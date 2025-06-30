<?php
// Start the session
session_start();

// For connecting to database
require_once("connection.php");

// Check if user is logged in as admin
// For simplicity, we'll assume admin has username 'admin'
// In a real application, you would have a proper admin role system
if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'admin') {
    // Redirect to login page with error message
    header("Location: login.php?error=admin_access_required");
    exit();
}

// This function gets counts of entities needing verification
function getNotificationCounts($conn) {
    $counts = [];
   
    // Count unverified users
    $userQuery = "SELECT COUNT(*) as total FROM account_information WHERE status != 'verified' OR status IS NULL";
    $result = $conn->query($userQuery);
    $counts['unverified_users'] = $result->fetch_assoc()['total'];
   
    // Count unverified garage owners
    $ownerQuery = "SELECT COUNT(*) as total FROM garage_owners WHERE is_verified = 0";
    $result = $conn->query($ownerQuery);
    $counts['unverified_owners'] = $result->fetch_assoc()['total'];
   
    // Count unauthorized garage owners (users who have garages but aren't registered owners)
    // FIXED: Check for users that don't exist in garage_owners OR dual_user tables
    $unauthorizedQuery = "SELECT COUNT(DISTINCT gi.username) as total
                         FROM garage_information gi
                         LEFT JOIN garage_owners go ON gi.username = go.username
                         LEFT JOIN dual_user du ON gi.username = du.username
                         WHERE go.username IS NULL AND du.username IS NULL";
    $result = $conn->query($unauthorizedQuery);
    $counts['unauthorized_owners'] = $result->fetch_assoc()['total'];
   
    // Count unverified garages
    $garageQuery = "SELECT COUNT(*) as total FROM garage_information WHERE is_verified = 0";
    $result = $conn->query($garageQuery);
    $counts['unverified_garages'] = $result->fetch_assoc()['total'];
   
    // Total count for notification badge
    $counts['total'] = $counts['unverified_users'] + $counts['unverified_owners'] +
                       $counts['unauthorized_owners'] + $counts['unverified_garages'];
   
    return $counts;
}

function getSimpleGarageStatus($conn, $garage_id) {
    $query = "SELECT current_status FROM garage_real_time_status WHERE garage_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $garage_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return strtoupper($row['current_status']);
    }
    
    return 'UNKNOWN';
}
// Add this function to get garage status for display
function getGarageCurrentStatus($conn, $garage_id) {
    $query = "SELECT get_garage_current_status(?) as status";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $garage_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['status'];
    }
    
    return 'UNKNOWN';
}
function calculateMissingProfits($conn) {
    $calculated = 0;
    
    try {
        error_log("Starting profit calculation...");
        
        // Get all paid payments that don't have profit tracking records
        $query = "SELECT p.payment_id, p.booking_id, p.amount, b.garage_id
                  FROM payments p
                  INNER JOIN bookings b ON p.booking_id = b.id
                  LEFT JOIN profit_tracking pt ON p.payment_id = pt.payment_id
                  WHERE p.payment_status = 'paid' 
                  AND pt.payment_id IS NULL";
        
        $result = $conn->query($query);
        
        if (!$result) {
            throw new Exception("Query failed: " . $conn->error);
        }
        
        error_log("Found " . $result->num_rows . " payments without profit tracking");
        
        if ($result->num_rows > 0) {
            $conn->begin_transaction();
            
            while ($row = $result->fetch_assoc()) {
                $paymentId = $row['payment_id'];
                $bookingId = $row['booking_id'];
                $amount = floatval($row['amount']);
                $garageId = $row['garage_id'];
                
                // Get garage owner information
                $ownerInfo = getGarageOwnerInfo($conn, $garageId);
                
                // Calculate profits (default 30% platform, 70% owner)
                $platformCommission = 30.0; // 30%
                $platformProfit = ($amount * $platformCommission) / 100;
                $ownerProfit = $amount - $platformProfit;
                
                // Insert profit tracking record
                $insertQuery = "INSERT INTO profit_tracking 
                               (payment_id, booking_id, owner_id, garage_id, garage_name, 
                                total_amount, commission_rate, owner_profit, platform_profit, created_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                $stmt = $conn->prepare($insertQuery);
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                
                $stmt->bind_param("iisssddd", 
                    $paymentId,
                    $bookingId,
                    $ownerInfo['owner_id'],
                    $garageId,
                    $ownerInfo['garage_name'],
                    $amount,
                    $platformCommission,
                    $ownerProfit,
                    $platformProfit
                );
                
                if ($stmt->execute()) {
                    $calculated++;
                    error_log("Calculated profit for payment ID: " . $paymentId);
                } else {
                    error_log("Failed to insert profit for payment ID: " . $paymentId . " - " . $stmt->error);
                }
            }
            
            $conn->commit();
            error_log("Successfully calculated profits for " . $calculated . " payments");
        }
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollback();
        }
        error_log("Error in calculateMissingProfits: " . $e->getMessage());
        throw $e;
    }
    
    return $calculated;
}
function getGarageOwnerInfo($conn, $garageId) {
    // Get garage information and owner details
    $query = "SELECT gi.garage_name, gi.username as garage_owner_username,
                     COALESCE(go.owner_id, du.owner_id) as owner_id
              FROM garage_information gi
              LEFT JOIN garage_owners go ON gi.username = go.username
              LEFT JOIN dual_user du ON gi.username = du.username
              WHERE gi.garage_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $garageId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    // Return default values if not found
    return [
        'garage_name' => 'Unknown Garage',
        'garage_owner_username' => 'unknown',
        'owner_id' => null
    ];
}

function getTopContributingOwners($conn, $limit = 10) {
    error_log("Getting top contributing owners...");
    
    // First, let's see what data exists in profit_tracking
    $checkQuery = "SELECT COUNT(*) as count FROM profit_tracking";
    $result = $conn->query($checkQuery);
    $count = $result->fetch_assoc()['count'] ?? 0;
    error_log("Total profit tracking records: " . $count);
    
    if ($count == 0) {
        error_log("No profit tracking data found");
        return [];
    }
    
    // Simplified query that works with your database structure
    $query = "SELECT 
                pt.owner_id,
                pt.garage_name,
                COUNT(pt.payment_id) as transaction_count,
                SUM(pt.total_amount) as total_revenue,
                SUM(pt.owner_profit) as total_profit,
                AVG(pt.total_amount) as avg_transaction,
                pt.owner_id as username
              FROM profit_tracking pt
              WHERE pt.owner_profit > 0
              GROUP BY pt.owner_id
              ORDER BY total_profit DESC
              LIMIT ?";
    
    error_log("Query: " . $query);
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Query prepare failed: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $owners = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Extract username from owner_id (format: U_owner_username)
            $username = $row['owner_id'];
            if (strpos($username, 'U_owner_') === 0) {
                $username = substr($username, 8); // Remove 'U_owner_' prefix
            }
            
            $owners[] = [
                'username' => $username,
                'full_name' => $row['garage_name'] ?: $username,
                'transaction_count' => intval($row['transaction_count']),
                'total_revenue' => floatval($row['total_revenue']),
                'total_profit' => floatval($row['total_profit']),
                'avg_transaction' => floatval($row['avg_transaction'])
            ];
            
            error_log("Found owner: " . $username . " with profit: " . $row['total_profit']);
        }
    } else {
        error_log("No owners found in result");
    }
    
    error_log("Total owners found: " . count($owners));
    return $owners;
}

//AJAX handlers

if (isset($_POST['action']) && $_POST['action'] === 'update_owner_status') {
    $response = ['success' => false, 'message' => 'Failed to update owner status'];
    
    if (isset($_POST['owner_id']) && isset($_POST['status'])) {
        $ownerId = $_POST['owner_id'];
        $status = $_POST['status'];
        
        // Map frontend status values to database values
        $statusMapping = [
            'Active' => 'active',
            'Suspended' => 'suspended', 
            'Deactivated' => 'inactive',
            // Also allow direct database values
            'active' => 'active',
            'suspended' => 'suspended',
            'inactive' => 'inactive'
        ];
        
        // Validate and convert status
        if (!array_key_exists($status, $statusMapping)) {
            $response = ['success' => false, 'message' => 'Invalid status value. Expected: Active, Suspended, or Deactivated'];
        } else {
            $dbStatus = $statusMapping[$status];
            
            try {
                // Determine which table to update based on owner_id prefix
                if (strpos($ownerId, 'U_owner_') === 0) {
                    // This is a dual user
                    $updateQuery = "UPDATE dual_user SET account_status = ? WHERE owner_id = ?";
                } else {
                    // This is a garage owner  
                    $updateQuery = "UPDATE garage_owners SET account_status = ? WHERE owner_id = ?";
                }
                
                $stmt = $conn->prepare($updateQuery);
                if (!$stmt) {
                    $response = ['success' => false, 'message' => 'Database prepare error: ' . $conn->error];
                } else {
                    $stmt->bind_param("ss", $dbStatus, $ownerId);
                    
                    if ($stmt->execute()) {
                        if ($stmt->affected_rows > 0) {
                            $response = [
                                'success' => true, 
                                'message' => "Owner status updated to '{$status}' successfully",
                                'owner_id' => $ownerId,
                                'new_status' => $status,
                                'db_status' => $dbStatus
                            ];
                        } else {
                            $response = ['success' => false, 'message' => 'Owner not found or status already set'];
                        }
                    } else {
                        $response = ['success' => false, 'message' => 'Database error: ' . $stmt->error];
                    }
                    $stmt->close();
                }
            } catch (Exception $e) {
                $response = [
                    'success' => false, 
                    'message' => 'Error updating owner status: ' . $e->getMessage()
                ];
            }
        }
    } else {
        $response = ['success' => false, 'message' => 'Owner ID and status are required'];
    }
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}
if (isset($_POST['action']) && $_POST['action'] === 'set_default_commission_for_all') {
    // Clean any previous output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    $response = ['success' => false, 'message' => 'Failed to update commission rates'];
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Default commission rate
        $defaultRate = 30.00;
        
        // Get all owner IDs from both garage_owners and dual_user tables
        $allOwnersQuery = "SELECT owner_id FROM garage_owners 
                          UNION 
                          SELECT owner_id FROM dual_user";
        
        $result = $conn->query($allOwnersQuery);
        
        if (!$result) {
            throw new Exception('Failed to fetch owners: ' . $conn->error);
        }
        
        $updateCount = 0;
        $errorCount = 0;
        
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $ownerId = $row['owner_id'];
                
                // Determine owner type based on ID prefix
                $ownerType = (strpos($ownerId, 'U_owner_') === 0) ? 'dual' : 'garage';
                
                try {
                    // Check if commission record exists
                    $checkQuery = "SELECT id FROM owner_commissions WHERE owner_id = ?";
                    $stmt = $conn->prepare($checkQuery);
                    $stmt->bind_param("s", $ownerId);
                    $stmt->execute();
                    $checkResult = $stmt->get_result();
                    
                    if ($checkResult && $checkResult->num_rows > 0) {
                        // Update existing record
                        $updateQuery = "UPDATE owner_commissions 
                                       SET rate = ?, updated_at = NOW() 
                                       WHERE owner_id = ?";
                        $stmt = $conn->prepare($updateQuery);
                        $stmt->bind_param("ds", $defaultRate, $ownerId);
                    } else {
                        // Insert new record
                        $insertQuery = "INSERT INTO owner_commissions 
                                       (owner_id, owner_type, rate, created_at, updated_at) 
                                       VALUES (?, ?, ?, NOW(), NOW())";
                        $stmt = $conn->prepare($insertQuery);
                        $stmt->bind_param("ssd", $ownerId, $ownerType, $defaultRate);
                    }
                    
                    if ($stmt->execute()) {
                        $updateCount++;
                    } else {
                        $errorCount++;
                        error_log("Failed to update commission for owner {$ownerId}: " . $stmt->error);
                    }
                    
                } catch (Exception $e) {
                    $errorCount++;
                    error_log("Error processing owner {$ownerId}: " . $e->getMessage());
                }
            }
            
            // Commit transaction
            $conn->commit();
            
            $response = [
                'success' => true, 
                'message' => "Successfully set 30% commission rate for {$updateCount} owners." . 
                           ($errorCount > 0 ? " ({$errorCount} errors occurred)" : ""),
                'updated_count' => $updateCount,
                'error_count' => $errorCount
            ];
            
        } else {
            $response = [
                'success' => false, 
                'message' => 'No owners found to update.'
            ];
        }
        
    } catch (Exception $e) {
        // Rollback on error
        if ($conn->ping()) {
            $conn->rollback();
        }
        
        $response = [
            'success' => false, 
            'message' => 'Database error: ' . $e->getMessage()
        ];
        
        error_log("Commission update error: " . $e->getMessage());
    }
    
    // Return JSON response and exit
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Clean any existing output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers for JSON response
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    // Get action
    $action = $_POST['action'];
    $response = ['success' => false, 'message' => 'Unknown action'];
    
    try {
        switch ($action) {
            
            // ==============================================================================
            // REFUND PAYMENT ACTION
            // ==============================================================================
            case 'refund_payment':
                if (!isset($_POST['payment_id'])) {
                    $response = ['success' => false, 'message' => 'Payment ID is required'];
                    break;
                }
                
                $paymentId = (int)$_POST['payment_id'];
                
                // Get payment details with proper error handling
                $getPaymentQuery = "SELECT p.booking_id, p.amount, p.payment_status,
                                           b.garage_id, b.username as customer_username
                                    FROM payments p 
                                    JOIN bookings b ON p.booking_id = b.id 
                                    WHERE p.payment_id = ?";
                
                $stmt = $conn->prepare($getPaymentQuery);
                if (!$stmt) {
                    $response = ['success' => false, 'message' => 'Database prepare error: ' . $conn->error];
                    break;
                }
                
                $stmt->bind_param("i", $paymentId);
                if (!$stmt->execute()) {
                    $response = ['success' => false, 'message' => 'Database execute error: ' . $stmt->error];
                    break;
                }
                
                $result = $stmt->get_result();
                if (!$result || $result->num_rows === 0) {
                    $response = ['success' => false, 'message' => 'Payment not found'];
                    break;
                }
                
                $paymentData = $result->fetch_assoc();
                
                if ($paymentData['payment_status'] === 'refunded') {
                    $response = ['success' => false, 'message' => 'Payment is already refunded'];
                    break;
                }
                
                // Process refund with transaction
                $conn->begin_transaction();
                
                try {
                    // Update payment status
                    $updatePaymentQuery = "UPDATE payments SET payment_status = 'refunded' WHERE payment_id = ?";
                    $stmt = $conn->prepare($updatePaymentQuery);
                    $stmt->bind_param("i", $paymentId);
                    $stmt->execute();
                    
                    // Update booking payment status
                    $updateBookingQuery = "UPDATE bookings SET payment_status = 'refunded' WHERE id = ?";
                    $stmt = $conn->prepare($updateBookingQuery);
                    $stmt->bind_param("i", $paymentData['booking_id']);
                    $stmt->execute();
                    
                    $conn->commit();
                    $response = ['success' => true, 'message' => 'Payment refunded successfully'];
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $response = ['success' => false, 'message' => 'Error processing refund: ' . $e->getMessage()];
                }
                break;
            case 'get_payment':  // or case 'get_payment_details':
    if (!isset($_POST['payment_id'])) {
        $response = ['success' => false, 'message' => 'Payment ID is required'];
        break;
    }
    
    $paymentId = (int)$_POST['payment_id'];
    
    // FIXED: Use correct column names from your database schema
    $paymentQuery = "SELECT p.*, 
                            b.username as customer, 
                            b.garage_id,
                            b.licenseplate,  -- ✅ Fixed: was b.vehicle_license_plate
                            b.booking_date, 
                            b.booking_time,
                            b.duration,
                            b.status as booking_status,
                            gi.Parking_Space_Name as garage_name,
                            gi.Parking_Lot_Address as garage_address,
                            pi.firstName,
                            pi.lastName,
                            pi.email,
                            pi.phone,
                            v.make,
                            v.model,
                            v.color,
                            v.vehicleType
                     FROM payments p
                     LEFT JOIN bookings b ON p.booking_id = b.id
                     LEFT JOIN garage_information gi ON b.garage_id = gi.garage_id
                     LEFT JOIN personal_information pi ON b.username = pi.username
                     LEFT JOIN vehicle_information v ON b.licenseplate = v.licensePlate
                     WHERE p.payment_id = ?";
    
    $stmt = $conn->prepare($paymentQuery);
    if (!$stmt) {
        $response = ['success' => false, 'message' => 'Database error: ' . $conn->error];
        break;
    }
    
    $stmt->bind_param("i", $paymentId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result || $result->num_rows === 0) {
        $response = ['success' => false, 'message' => 'Payment not found'];
        break;
    }
    
    $payment = $result->fetch_assoc();
    $response = [
        'success' => true,
        'message' => 'Payment details retrieved successfully',
        'data' => $payment
    ];
    break;
            // ==============================================================================
            // POINTS HISTORY ACTION
            // ==============================================================================
            case 'get_user_points_history':
                if (!isset($_POST['username']) || empty(trim($_POST['username']))) {
                    $response = ['success' => false, 'message' => 'Username is required'];
                    break;
                }
                
                $username = trim($_POST['username']);
                
                // Get current points
                $pointsQuery = "SELECT points FROM account_information WHERE username = ?";
                $stmt = $conn->prepare($pointsQuery);
                if (!$stmt) {
                    $response = ['success' => false, 'message' => 'Database error: ' . $conn->error];
                    break;
                }
                
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $pointsResult = $stmt->get_result();
                
                $currentPoints = 0;
                if ($pointsResult && $pointsResult->num_rows > 0) {
                    $currentPoints = (int)$pointsResult->fetch_assoc()['points'];
                }
                
                // Get transaction history
                $historyQuery = "SELECT id, transaction_type, points_amount, description, booking_id, created_at
                                FROM points_transactions 
                                WHERE username = ?
                                ORDER BY created_at DESC 
                                LIMIT 20";
                
                $stmt = $conn->prepare($historyQuery);
                if (!$stmt) {
                    $response = ['success' => false, 'message' => 'Database error: ' . $conn->error];
                    break;
                }
                
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $historyResult = $stmt->get_result();
                
                $transactions = [];
                if ($historyResult) {
                    while ($row = $historyResult->fetch_assoc()) {
                        $transactions[] = $row;
                    }
                }
                
                $response = [
                    'success' => true, 
                    'message' => 'Points history retrieved successfully',
                    'data' => [
                        'username' => $username,
                        'current_points' => $currentPoints,
                        'transactions' => $transactions
                    ]
                ];
                break;
                // ==============================================================================
// GET USER ACTION - Add this to your switch statement
// ==============================================================================
case 'get_user':
    if (!isset($_POST['username'])) {
        $response = ['success' => false, 'message' => 'Username is required'];
        break;
    }
    
    $username = trim($_POST['username']);
    
    if (empty($username)) {
        $response = ['success' => false, 'message' => 'Username cannot be empty'];
        break;
    }
    
    try {
        // FIXED: Get user account information with correct column names
        $userQuery = "SELECT username, password, points, status, registration_date, last_login,
                             user_level, total_earned_points, default_dashboard
                      FROM account_information 
                      WHERE username = ?";
        
        $stmt = $conn->prepare($userQuery);
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result || $result->num_rows === 0) {
            $response = ['success' => false, 'message' => "User '{$username}' not found"];
            break;
        }
        
        $user = $result->fetch_assoc();
        
        // Get personal information if available
        $personalQuery = "SELECT firstName, lastName, email, phone, address 
                         FROM personal_information 
                         WHERE username = ?";
        
        $stmt = $conn->prepare($personalQuery);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $personalResult = $stmt->get_result();
            
            if ($personalResult && $personalResult->num_rows > 0) {
                $personalInfo = $personalResult->fetch_assoc();
                // Merge personal info with user data
                $user = array_merge($user, $personalInfo);
            } else {
                // Set default values if no personal info found
                $user['firstName'] = '';
                $user['lastName'] = '';
                $user['email'] = '';
                $user['phone'] = '';
                $user['address'] = '';
            }
        }
        
        // Get user statistics
        $statsQuery = "SELECT 
                        (SELECT COUNT(*) FROM bookings WHERE username = ?) as total_bookings,
                        (SELECT COUNT(*) FROM bookings WHERE username = ? AND status = 'completed') as completed_bookings,
                        (SELECT COUNT(*) FROM garage_information WHERE username = ?) as owned_garages,
                        (SELECT COALESCE(SUM(points_amount), 0) FROM points_transactions WHERE username = ? AND transaction_type = 'earned') as total_points_earned";
        
        $stmt = $conn->prepare($statsQuery);
        if ($stmt) {
            $stmt->bind_param("ssss", $username, $username, $username, $username);
            $stmt->execute();
            $statsResult = $stmt->get_result();
            
            if ($statsResult && $statsResult->num_rows > 0) {
                $stats = $statsResult->fetch_assoc();
                $user['statistics'] = [
                    'total_bookings' => (int)($stats['total_bookings'] ?? 0),
                    'completed_bookings' => (int)($stats['completed_bookings'] ?? 0),
                    'owned_garages' => (int)($stats['owned_garages'] ?? 0),
                    'total_points_earned' => (int)($stats['total_points_earned'] ?? 0)
                ];
            }
        }
        
        // Check if user is a garage owner (check both tables)
        $ownerQuery = "SELECT owner_id, is_verified, account_status, 'official' as owner_type 
                       FROM garage_owners 
                       WHERE username = ?
                       UNION
                       SELECT owner_id, is_verified, account_status, 'dual' as owner_type 
                       FROM dual_user 
                       WHERE username = ?";
        
        $stmt = $conn->prepare($ownerQuery);
        if ($stmt) {
            $stmt->bind_param("ss", $username, $username);
            $stmt->execute();
            $ownerResult = $stmt->get_result();
            
            if ($ownerResult && $ownerResult->num_rows > 0) {
                $ownerInfo = $ownerResult->fetch_assoc();
                $user['is_garage_owner'] = true;
                $user['owner_id'] = $ownerInfo['owner_id'];
                $user['owner_verified'] = (bool)$ownerInfo['is_verified'];
                $user['is_official_owner'] = ($ownerInfo['owner_type'] === 'official');
                $user['owner_status'] = $ownerInfo['account_status'];
            } else {
                $user['is_garage_owner'] = false;
                $user['owner_id'] = null;
                $user['owner_verified'] = false;
                $user['is_official_owner'] = false;
                $user['owner_status'] = null;
            }
        }
        
        // FIXED: Rename registration_date to created_at for frontend compatibility
        if (isset($user['registration_date'])) {
            $user['created_at'] = $user['registration_date'];
        }
        
        $response = [
            'success' => true,
            'message' => 'User details retrieved successfully',
            'data' => $user
        ];
        
    } catch (Exception $e) {
        $response = [
            'success' => false, 
            'message' => 'Database error occurred: ' . $e->getMessage()
        ];
        error_log("Get user error: " . $e->getMessage());
    }
    break;

// ==============================================================================
// ADD USER ACTION
// ==============================================================================
case 'add_user':
    if (!isset($_POST['username']) || !isset($_POST['password'])) {
        $response = ['success' => false, 'message' => 'Username and password are required'];
        break;
    }
    
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $firstName = trim($_POST['firstName'] ?? '');
    $lastName = trim($_POST['lastName'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Validate inputs
    if (empty($username) || empty($password)) {
        $response = ['success' => false, 'message' => 'Username and password cannot be empty'];
        break;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Check if username already exists
        $checkQuery = "SELECT username FROM account_information WHERE username = ?";
        $stmt = $conn->prepare($checkQuery);
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $response = ['success' => false, 'message' => "Username '{$username}' already exists"];
            break;
        }
        
        // Insert account info
        $accountQuery = "INSERT INTO account_information (username, password, status, points, registration_date) 
                         VALUES (?, ?, 'unverified', 0, NOW())";
        $stmt = $conn->prepare($accountQuery);
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("ss", $username, $password);
        if (!$stmt->execute()) {
            throw new Exception('Failed to insert account: ' . $stmt->error);
        }
        
        // Insert personal info if provided
        if (!empty($firstName) || !empty($lastName) || !empty($email) || !empty($phone) || !empty($address)) {
            $personalQuery = "INSERT INTO personal_information (firstName, lastName, email, phone, address, username) 
                              VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($personalQuery);
            if (!$stmt) {
                throw new Exception('Database prepare error for personal info: ' . $conn->error);
            }
            
            $stmt->bind_param("ssssss", $firstName, $lastName, $email, $phone, $address, $username);
            if (!$stmt->execute()) {
                throw new Exception('Failed to insert personal info: ' . $stmt->error);
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        $response = [
            'success' => true, 
            'message' => "User '{$username}' added successfully",
            'data' => ['username' => $username]
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        $response = [
            'success' => false, 
            'message' => 'Error adding user: ' . $e->getMessage()
        ];
        error_log("Add user error: " . $e->getMessage());
    }
    break;

case 'update_commission':
            $response = ['success' => false, 'message' => 'Missing required parameters'];
            
            if (isset($_POST['owner_id']) && isset($_POST['rate'])) {
                $ownerId = $_POST['owner_id'];
                $rate = (float) $_POST['rate'];
                
                if ($rate < 0 || $rate > 100) {
                    $response = ['success' => false, 'message' => 'Rate must be between 0 and 100'];
                } else {
                    try {
                        // Determine owner type based on ID prefix
                        $ownerType = (strpos($ownerId, 'U_owner_') === 0) ? 'dual' : 'garage';
                        
                        // Check if commission record exists
                        $checkQuery = "SELECT id FROM owner_commissions WHERE owner_id = ?";
                        $stmt = $conn->prepare($checkQuery);
                        $stmt->bind_param("s", $ownerId);
                        $stmt->execute();
                        $checkResult = $stmt->get_result();
                        
                        if ($checkResult && $checkResult->num_rows > 0) {
                            // Update existing record
                            $updateQuery = "UPDATE owner_commissions 
                                           SET rate = ?, updated_at = NOW() 
                                           WHERE owner_id = ?";
                            $stmt = $conn->prepare($updateQuery);
                            $stmt->bind_param("ds", $rate, $ownerId);
                        } else {
                            // Insert new record
                            $insertQuery = "INSERT INTO owner_commissions 
                                           (owner_id, owner_type, rate, created_at, updated_at) 
                                           VALUES (?, ?, ?, NOW(), NOW())";
                            $stmt = $conn->prepare($insertQuery);
                            $stmt->bind_param("ssd", $ownerId, $ownerType, $rate);
                        }
                        
                        if ($stmt->execute()) {
                            $response = [
                                'success' => true, 
                                'message' => "Commission rate updated to {$rate}% successfully",
                                'new_rate' => $rate,
                                'owner_id' => $ownerId
                            ];
                        } else {
                            $response = ['success' => false, 'message' => 'Database error: ' . $stmt->error];
                        }
                        
                    } catch (Exception $e) {
                        $response = [
                            'success' => false, 
                            'message' => 'Database error: ' . $e->getMessage()
                        ];
                    }
                }
            }
            
            // Return JSON response and exit
            header('Content-Type: application/json');
            echo json_encode($response);
            exit();
            break;
// ==============================================================================
// GET GARAGE STATUS ACTION - MISSING HANDLER
// ==============================================================================
case 'get_garage_status':
    try {
        if (!isset($_POST['garage_id'])) {
            $response = ['success' => false, 'message' => 'Garage ID is required'];
            break;
        }
        
        $garageId = $_POST['garage_id'];
        
        // Get comprehensive garage status using your exact schema
        $statusQuery = "SELECT 
                            rts.current_status,
                            rts.is_manual_override,
                            rts.override_until,
                            rts.override_reason,
                            rts.force_closed,
                            rts.active_bookings_count,
                            rts.can_close_after,
                            rts.last_changed_at,
                            rts.changed_by,
                            gi.Parking_Capacity as total_capacity,
                            gi.PriceperHour as price_per_hour,
                            gi.Availability as available_spots,
                            gi.Parking_Space_Name as garage_name
                        FROM garage_information gi
                        LEFT JOIN garage_real_time_status rts ON gi.garage_id = rts.garage_id
                        WHERE gi.garage_id = ?";
        
        $stmt = $conn->prepare($statusQuery);
        if (!$stmt) {
            $response = ['success' => false, 'message' => 'Database prepare error: ' . $conn->error];
            break;
        }
        
        $stmt->bind_param("s", $garageId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $statusData = $result->fetch_assoc();
            
            // Get operating schedule using your exact schema
            $scheduleQuery = "SELECT 
                                garage_name,
                                opening_time,
                                closing_time,
                                operating_days,
                                is_24_7,
                                created_at,
                                updated_at
                            FROM garage_operating_schedule 
                            WHERE garage_id = ?";
            $stmt = $conn->prepare($scheduleQuery);
            $stmt->bind_param("s", $garageId);
            $stmt->execute();
            $scheduleResult = $stmt->get_result();
            
            $schedule = null;
            if ($scheduleResult && $scheduleResult->num_rows > 0) {
                $schedule = $scheduleResult->fetch_assoc();
            }
            
            $response = [
                'success' => true,
                'status' => $statusData,
                'schedule' => $schedule
            ];
        } else {
            $response = ['success' => false, 'message' => 'Garage not found'];
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error fetching garage status: ' . $e->getMessage()];
        error_log("Error in get_garage_status: " . $e->getMessage());
    }
    break;


// ==============================================================================
// UPDATE GARAGE ACTION - MISSING HANDLER
// ==============================================================================
case 'update_garage':
    try {
        $requiredFields = ['garage_id'];
        foreach ($requiredFields as $field) {
            if (!isset($_POST[$field])) {
                $response = ['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required'];
                break 2;
            }
        }
        
        $garageId = $_POST['garage_id'];
        $updateFields = [];
        $values = [];
        $types = '';
        
        // Build dynamic update query based on provided fields matching your schema
        $allowedFields = [
            'price_per_hour' => 'PriceperHour',
            'garage_name' => 'Parking_Space_Name',
            'address' => 'Parking_Lot_Address',
            'capacity' => 'Parking_Capacity',
            'garage_type' => 'Parking_Type',
            'parking_dimensions' => 'Parking_Space_Dimensions',
            'availability' => 'Availability'
        ];
        
        foreach ($allowedFields as $postField => $dbField) {
            if (isset($_POST[$postField]) && $_POST[$postField] !== '') {
                $updateFields[] = "$dbField = ?";
                $values[] = $_POST[$postField];
                
                // Determine type based on your schema
                if (in_array($postField, ['price_per_hour'])) {
                    $types .= 'd'; // decimal
                } elseif (in_array($postField, ['capacity', 'availability'])) {
                    $types .= 'i'; // integer
                } else {
                    $types .= 's'; // string
                }
            }
        }
        
        if (empty($updateFields)) {
            $response = ['success' => false, 'message' => 'No valid fields to update'];
            break;
        }
        
        // Add updated_at timestamp
        $updateFields[] = "updated_at = NOW()";
        
        // Add garage_id to the end
        $values[] = $garageId;
        $types .= 's';
        
        $updateQuery = "UPDATE garage_information SET " . implode(', ', $updateFields) . " WHERE garage_id = ?";
        
        $stmt = $conn->prepare($updateQuery);
        if (!$stmt) {
            $response = ['success' => false, 'message' => 'Database prepare error: ' . $conn->error];
            break;
        }
        
        $stmt->bind_param($types, ...$values);
        
        if ($stmt->execute()) {
            // Log the update
            error_log("Garage updated: " . $garageId . " by admin");
            
            $response = ['success' => true, 'message' => 'Garage updated successfully'];
        } else {
            $response = ['success' => false, 'message' => 'Error updating garage: ' . $stmt->error];
            error_log("Error updating garage: " . $stmt->error);
        }
        
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error updating garage: ' . $e->getMessage()];
        error_log("Error in update_garage: " . $e->getMessage());
    }
    break;

case 'calculate_missing_profits':
    try {
        $calculated = calculateMissingProfits($conn);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => "Successfully calculated profits for {$calculated} payments",
            'calculated' => $calculated
        ]);
        exit();
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Error calculating profits: ' . $e->getMessage(),
            'calculated' => 0
        ]);
        exit();
    }
    break;

case 'get_top_contributing_owners':
    try {
        $topOwners = getTopContributingOwners($conn);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $topOwners
        ]);
        exit();
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching top owners: ' . $e->getMessage(),
            'data' => []
        ]);
        exit();
    }
    break;
// ==============================================================================
// UPDATE GARAGE STATUS ACTION - ENHANCED VERSION FOR YOUR SCHEMA
// ==============================================================================
case 'update_garage_status':
    try {
        if (!isset($_POST['garage_id']) || !isset($_POST['status'])) {
            $response = ['success' => false, 'message' => 'Garage ID and status are required'];
            break;
        }
        
        $garageId = $_POST['garage_id'];
        $newStatus = $_POST['status'];
        $reason = $_POST['reason'] ?? 'Admin update';
        $forceClose = isset($_POST['force_close']) && $_POST['force_close'] == '1';
        
        // Validate status according to your schema
        $validStatuses = ['open', 'closed', 'maintenance', 'emergency_closed'];
        if (!in_array($newStatus, $validStatuses)) {
            $response = ['success' => false, 'message' => 'Invalid status value'];
            break;
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Update or insert garage real-time status using your exact schema
            $statusQuery = "INSERT INTO garage_real_time_status 
                           (garage_id, current_status, is_manual_override, override_reason, 
                            force_closed, last_changed_at, changed_by)
                           VALUES (?, ?, 1, ?, ?, NOW(), 'admin')
                           ON DUPLICATE KEY UPDATE 
                           current_status = VALUES(current_status),
                           is_manual_override = VALUES(is_manual_override),
                           override_reason = VALUES(override_reason),
                           force_closed = VALUES(force_closed),
                           last_changed_at = VALUES(last_changed_at),
                           changed_by = VALUES(changed_by)";
            
            $stmt = $conn->prepare($statusQuery);
            $forceCloseFlag = ($forceClose) ? 1 : 0;
            $stmt->bind_param("sssi", $garageId, $newStatus, $reason, $forceCloseFlag);
            $stmt->execute();
            
            // If force closing and there are active bookings, handle them
            if ($forceClose && in_array($newStatus, ['closed', 'maintenance', 'emergency_closed'])) {
                // Get active bookings
                $activeBookingsQuery = "SELECT id FROM bookings 
                                       WHERE garage_id = ? AND status IN ('confirmed', 'active', 'upcoming')";
                $stmt = $conn->prepare($activeBookingsQuery);
                $stmt->bind_param("s", $garageId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $cancelledBookings = 0;
                while ($booking = $result->fetch_assoc()) {
                    // Update booking status
                    $cancelQuery = "UPDATE bookings SET status = 'cancelled_by_admin', 
                                   updated_at = NOW() WHERE id = ?";
                    $stmt2 = $conn->prepare($cancelQuery);
                    $stmt2->bind_param("i", $booking['id']);
                    $stmt2->execute();
                    $cancelledBookings++;
                }
                
                if ($cancelledBookings > 0) {
                    $reason .= " (Force closed - {$cancelledBookings} bookings cancelled)";
                    
                    // Update the reason in the status record
                    $updateReasonQuery = "UPDATE garage_real_time_status 
                                         SET override_reason = ? 
                                         WHERE garage_id = ?";
                    $stmt = $conn->prepare($updateReasonQuery);
                    $stmt->bind_param("ss", $reason, $garageId);
                    $stmt->execute();
                }
            }
            
            $conn->commit();
            
            $message = "Garage status updated to " . strtoupper($newStatus);
            if ($forceClose && isset($cancelledBookings) && $cancelledBookings > 0) {
                $message .= " and {$cancelledBookings} active bookings were cancelled";
            }
            
            $response = ['success' => true, 'message' => $message];
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error updating garage status: ' . $e->getMessage()];
        error_log("Error in update_garage_status: " . $e->getMessage());
    }
    break;
case 'get_vehicle':
    if (!isset($_POST['license_plate']) || empty(trim($_POST['license_plate']))) {
        $response = ['success' => false, 'message' => 'License plate is required'];
        break;
    }
    
    $licensePlate = trim($_POST['license_plate']);
    
    try {
        // Get vehicle details with owner information
        $vehicleQuery = "SELECT v.licensePlate, v.vehicleType, v.make, v.model, v.color, v.username,
                               p.firstName, p.lastName, p.email, p.phone, p.address
                        FROM vehicle_information v
                        LEFT JOIN personal_information p ON v.username = p.username
                        WHERE v.licensePlate = ?";
        
        $stmt = $conn->prepare($vehicleQuery);
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("s", $licensePlate);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result || $result->num_rows === 0) {
            $response = ['success' => false, 'message' => 'Vehicle not found'];
            break;
        }
        
        $vehicle = $result->fetch_assoc();
        
        // Get booking history for this vehicle
        $bookingQuery = "SELECT b.id, b.booking_date, b.booking_time, b.duration, b.status, b.payment_status,
                               gi.Parking_Space_Name as garage_name, gi.Parking_Lot_Address as garage_address,
                               p.amount as payment_amount, p.payment_method, p.transaction_id
                        FROM bookings b
                        LEFT JOIN garage_information gi ON b.garage_id = gi.garage_id
                        LEFT JOIN payments p ON b.id = p.booking_id
                        WHERE b.licenseplate = ?
                        ORDER BY b.booking_date DESC, b.booking_time DESC
                        LIMIT 10";
        
        $stmt = $conn->prepare($bookingQuery);
        if (!$stmt) {
            throw new Exception('Database prepare error for booking history: ' . $conn->error);
        }
        
        $stmt->bind_param("s", $licensePlate);
        $stmt->execute();
        $bookingResult = $stmt->get_result();
        
        $bookingHistory = [];
        if ($bookingResult && $bookingResult->num_rows > 0) {
            while ($row = $bookingResult->fetch_assoc()) {
                $bookingHistory[] = $row;
            }
        }
        
        $vehicle['booking_history'] = $bookingHistory;
        
        $response = [
            'success' => true,
            'message' => 'Vehicle details retrieved successfully',
            'data' => $vehicle
        ];
        
    } catch (Exception $e) {
        $response = [
            'success' => false,
            'message' => 'Error retrieving vehicle details: ' . $e->getMessage()
        ];
        error_log("Get vehicle error: " . $e->getMessage());
    }
    break;
// Update your backend cases to use "this_month" as the default period:
case 'get_revenue_trends':
            // Clean any previous output
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');
            
            try {
                $period = $_POST['period'] ?? 'last_7_days';
                
                error_log("Revenue trends request for period: " . $period);
                
                // Call the revenue trends function
                $trends = getRevenueTrends($conn, $period);
                
                echo json_encode([
                    'success' => true, 
                    'data' => $trends,
                    'period' => $period,
                    'count' => count($trends),
                    'debug' => 'Revenue trends loaded successfully'
                ]);
                exit();
                
            } catch (Exception $e) {
                error_log("Revenue trends error: " . $e->getMessage());
                
                echo json_encode([
                    'success' => false, 
                    'message' => 'Error fetching revenue trends: ' . $e->getMessage(),
                    'data' => generateSampleTrendsData($_POST['period'] ?? 'last_7_days'),
                    'period' => $_POST['period'] ?? 'last_7_days'
                ]);
                exit();
            }
            break;
case 'get_revenue_stats':
    try {
        // Based on your database schema, payments table should have payment_date column
        // Let's check what date column actually exists
        $totalRevenueQuery = "SELECT 
                                SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END) as total_revenue,
                                SUM(CASE WHEN payment_status = 'pending' THEN amount ELSE 0 END) as pending_revenue,
                                COUNT(*) as total_transactions
                              FROM payments 
                              WHERE YEAR(payment_date) = YEAR(NOW())";
        
        $result = $conn->query($totalRevenueQuery);
        
        if (!$result) {
            // If payment_date doesn't exist, try with a different date column
            $totalRevenueQuery = "SELECT 
                                    SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END) as total_revenue,
                                    SUM(CASE WHEN payment_status = 'pending' THEN amount ELSE 0 END) as pending_revenue,
                                    COUNT(*) as total_transactions
                                  FROM payments";
            $result = $conn->query($totalRevenueQuery);
        }
        
        $revenueData = $result->fetch_assoc();
        
        // Calculate platform profit (assuming 30% commission)
        $platformCommissionRate = 0.30; // 30%
        $totalRevenue = floatval($revenueData['total_revenue'] ?? 0);
        $pendingRevenue = floatval($revenueData['pending_revenue'] ?? 0);
        $platformProfit = $totalRevenue * $platformCommissionRate;
        $ownerEarnings = $totalRevenue * (1 - $platformCommissionRate);
        
        // Get booking statistics 
        $bookingStatsQuery = "SELECT 
                                COUNT(*) as total_bookings,
                                AVG(p.amount) as avg_booking_value
                              FROM payments p
                              JOIN bookings b ON p.booking_id = b.id
                              WHERE p.payment_status = 'paid'";
        
        $bookingResult = $conn->query($bookingStatsQuery);
        $bookingStats = $bookingResult ? $bookingResult->fetch_assoc() : ['total_bookings' => 0, 'avg_booking_value' => 0];
        
        $response = [
            'success' => true,
            'data' => [
                'total_revenue' => $totalRevenue,
                'platform_profit' => $platformProfit,
                'owner_earnings' => $ownerEarnings,
                'pending_revenue' => $pendingRevenue,
                'total_bookings' => intval($bookingStats['total_bookings'] ?? 0),
                'avg_booking_value' => floatval($bookingStats['avg_booking_value'] ?? 0),
                'commission_rate' => $platformCommissionRate * 100,
                'query_used' => $totalRevenueQuery // Debug info
            ]
        ];
        
    } catch (Exception $e) {
        $response = [
            'success' => false,
            'message' => 'Error fetching revenue stats: ' . $e->getMessage()
        ];
        error_log("Revenue stats error: " . $e->getMessage());
    }
    break;

case 'today':
                // For today, show hourly breakdown OR just today's total if no hourly data
                $dateCondition = "DATE(COALESCE(p.payment_date, b.created_at)) = CURDATE()";
                $groupBy = "DATE(COALESCE(p.payment_date, b.created_at))"; // Changed from HOUR to DATE for simplicity
                $dateFormat = "DATE(COALESCE(p.payment_date, b.created_at))";
                $orderBy = "DATE(COALESCE(p.payment_date, b.created_at))";
                break;

case 'get_payment_methods_data':
    try {
        $paymentMethodsQuery = "SELECT 
                                    COALESCE(payment_method, 'Unknown') as payment_method,
                                    COUNT(*) as count,
                                    SUM(amount) as total_amount
                                FROM payments 
                                WHERE payment_status = 'paid'
                                GROUP BY payment_method
                                ORDER BY total_amount DESC";
        
        $result = $conn->query($paymentMethodsQuery);
        $paymentMethods = [];
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $paymentMethods[] = [
                    'method' => ucfirst($row['payment_method']),
                    'count' => intval($row['count']),
                    'amount' => floatval($row['total_amount'])
                ];
            }
        }
        
        echo json_encode([
            'success' => true,
            'data' => $paymentMethods
        ]);
        exit();
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
        exit();
    }
    break;


case 'get_top_garages':
    try {
        $topGaragesQuery = "SELECT 
                                gi.Parking_Space_Name as garage_name,
                                gi.garage_id,
                                b.garage_id as booking_garage_id,
                                COUNT(b.id) as total_bookings,
                                SUM(CASE WHEN p.payment_status = 'paid' THEN p.amount ELSE 0 END) as total_revenue,
                                SUM(CASE WHEN p.payment_status = 'paid' THEN p.amount * 0.30 ELSE 0 END) as platform_profit,
                                AVG(CASE WHEN p.payment_status = 'paid' THEN p.amount ELSE NULL END) as avg_per_booking
                            FROM bookings b
                            LEFT JOIN payments p ON b.id = p.booking_id
                            LEFT JOIN garage_information gi ON b.garage_id = gi.garage_id
                            GROUP BY b.garage_id, gi.Parking_Space_Name, gi.garage_id
                            HAVING total_revenue > 0
                            ORDER BY total_revenue DESC
                            LIMIT 10";
        
        $result = $conn->query($topGaragesQuery);
        $topGarages = [];
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $topGarages[] = [
                    'garage_name' => $row['garage_name'],
                    'garage_id' => $row['garage_id'] ?: $row['booking_garage_id'],
                    'owner' => 'Owner', // You may need to join with owners table
                    'total_bookings' => intval($row['total_bookings']),
                    'total_revenue' => floatval($row['total_revenue']),
                    'platform_profit' => floatval($row['platform_profit']),
                    'avg_per_booking' => floatval($row['avg_per_booking'])
                ];
            }
        }
        
        $response = [
            'success' => true,
            'data' => $topGarages
        ];
        
    } catch (Exception $e) {
        $response = [
            'success' => false,
            'message' => 'Error fetching top garages: ' . $e->getMessage()
        ];
        error_log("Top garages error: " . $e->getMessage());
    }
    break;
case 'get_top_revenue_garages':
            try {
                $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 10;
                $topGarages = getTopRevenueGarages($conn, $limit);
                
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'data' => $topGarages]);
                exit();
            } catch (Exception $e) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false, 
                    'message' => 'Error fetching top garages: ' . $e->getMessage()
                ]);
                exit();
            }
            break;
case 'export_revenue_report':
    try {
        $period = $_POST['period'] ?? 'last_30_days';
        
        // Get comprehensive revenue data
        $reportQuery = "SELECT 
                            p.payment_id,
                            p.booking_id,
                            p.amount,
                            p.payment_method,
                            p.payment_status,
                            p.transaction_id,
                            p.created_at,
                            b.username as customer,
                            b.booking_date,
                            b.booking_time,
                            gi.Parking_Space_Name as garage_name,
                            go.owner_id as garage_owner
                        FROM payments p
                        LEFT JOIN bookings b ON p.booking_id = b.id
                        LEFT JOIN garage_information gi ON b.garage_id = gi.garage_id
                        LEFT JOIN garage_owners go ON gi.garage_id = go.garage_id
                        WHERE p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        ORDER BY p.created_at DESC";
        
        $result = $conn->query($reportQuery);
        $reportData = [];
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $reportData[] = $row;
            }
        }
        
        $response = [
            'success' => true,
            'data' => $reportData,
            'summary' => [
                'total_records' => count($reportData),
                'period' => $period,
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ];
        
    } catch (Exception $e) {
        $response = [
            'success' => false,
            'message' => 'Error generating revenue report: ' . $e->getMessage()
        ];
        error_log("Revenue report error: " . $e->getMessage());
    }
    break;
// ==============================================================================
// GET GARAGE REVIEWS ACTION - USING YOUR RATINGS TABLE
// ==============================================================================
case 'get_garage_reviews':
    try {
        if (!isset($_POST['garage_id'])) {
            $response = ['success' => false, 'message' => 'Garage ID is required'];
            break;
        }
        
        $garageId = $_POST['garage_id'];
        
        // Fixed query - using correct column names from your database schema
        $reviewsQuery = "SELECT 
                            r.id as review_id,
                            r.rater_username as username,
                            r.garage_id,
                            r.garage_name,
                            r.garage_owner_username,
                            r.booking_id,
                            r.rating,
                            r.review_text,
                            r.created_at,
                            r.updated_at,
                            p.firstName,
                            p.lastName
                        FROM ratings r
                        LEFT JOIN personal_information p ON r.rater_username = p.username
                        WHERE r.garage_id = ?
                        ORDER BY r.created_at DESC
                        LIMIT 50";
        
        $stmt = $conn->prepare($reviewsQuery);
        if (!$stmt) {
            $response = ['success' => false, 'message' => 'Database prepare error: ' . $conn->error];
            break;
        }
        
        $stmt->bind_param("s", $garageId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $reviews = [];
        while ($row = $result->fetch_assoc()) {
            $reviews[] = $row;
        }
        
        // Get rating summary from your existing garage_ratings_summary table
        $summaryQuery = "SELECT 
                            garage_name,
                            total_ratings,
                            average_rating,
                            five_star,
                            four_star,
                            three_star,
                            two_star,
                            one_star,
                            last_updated
                        FROM garage_ratings_summary 
                        WHERE garage_id = ?";
        
        $stmt = $conn->prepare($summaryQuery);
        $stmt->bind_param("s", $garageId);
        $stmt->execute();
        $summaryResult = $stmt->get_result();
        
        $summary = null;
        if ($summaryResult && $summaryResult->num_rows > 0) {
            $summary = $summaryResult->fetch_assoc();
        }
        
        // Get garage info for debug
        $garageQuery = "SELECT Parking_Space_Name as garage_name FROM garage_information WHERE garage_id = ?";
        $stmt = $conn->prepare($garageQuery);
        $stmt->bind_param("s", $garageId);
        $stmt->execute();
        $garageResult = $stmt->get_result();
        $garageInfo = $garageResult->fetch_assoc();
        
        $response = [
            'success' => true,
            'reviews' => $reviews,
            'summary' => $summary,
            'garage_info' => $garageInfo,
            'debug' => [
                'garage_id' => $garageId,
                'garage_name' => $garageInfo['garage_name'] ?? 'Unknown',
                'reviews_count' => count($reviews),
                'has_summary' => !is_null($summary)
            ]
        ];
        
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error fetching reviews: ' . $e->getMessage()];
        error_log("Error in get_garage_reviews: " . $e->getMessage());
    }
    break;
case 'verify_garage':
    if (!isset($_POST['garage_id'])) {
        $response = ['success' => false, 'message' => 'Garage ID is required'];
        break;
    }
    
    $garageId = $_POST['garage_id'];
    
    try {
        // Debug logging
        error_log("Admin is verifying garage: " . $garageId);
        
        // Update garage verification status
        $query = "UPDATE garage_information SET is_verified = 1 WHERE garage_id = ?";
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            $response = ['success' => false, 'message' => 'Database prepare error: ' . $conn->error];
            break;
        }
        
        $stmt->bind_param("s", $garageId);
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                error_log("Garage verification successful for: " . $garageId);
                $response = ['success' => true, 'message' => 'Garage verified successfully'];
            } else {
                error_log("No garage found with ID: " . $garageId);
                $response = ['success' => false, 'message' => 'Garage not found'];
            }
        } else {
            error_log("Garage verification failed for: " . $garageId . ". Error: " . $stmt->error);
            $response = ['success' => false, 'message' => 'Error verifying garage: ' . $stmt->error];
        }
        
    } catch (Exception $e) {
        error_log("Exception in verify_garage: " . $e->getMessage());
        $response = ['success' => false, 'message' => 'System error: ' . $e->getMessage()];
    }
    break;

// ALSO ADD these related cases if they're missing:

case 'verify_owner':
    if (!isset($_POST['owner_id'])) {
        $response = ['success' => false, 'message' => 'Owner ID is required'];
        break;
    }
    
    $ownerId = $_POST['owner_id'];
    
    try {
        // Check if we need to register the owner first
        if (isset($_POST['register_first']) && $_POST['register_first'] === 'true' && isset($_POST['username'])) {
            $username = $_POST['username'];
            $newOwnerId = "G_owner_" . $username;
            
            // First check if the owner already exists
            $checkQuery = "SELECT * FROM garage_owners WHERE username = ?";
            $stmt = $conn->prepare($checkQuery);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 0) {
                // Insert new garage owner
                $insertQuery = "INSERT INTO garage_owners (owner_id, username, is_verified, registration_date, account_status, original_type) 
                                VALUES (?, ?, 1, NOW(), 'active', 'user')";
                $stmt = $conn->prepare($insertQuery);
                $stmt->bind_param("ss", $newOwnerId, $username);
                
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Garage owner registered and verified successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Error registering garage owner: ' . $stmt->error];
                }
            } else {
                // Owner exists, just update verification
                $updateQuery = "UPDATE garage_owners SET is_verified = 1 WHERE username = ?";
                $stmt = $conn->prepare($updateQuery);
                $stmt->bind_param("s", $username);
                
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Garage owner verified successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Error verifying garage owner: ' . $stmt->error];
                }
            }
        } else {
            // Original verification code for existing owners
            $query = "UPDATE garage_owners SET is_verified = 1 WHERE owner_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $ownerId);
            
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Garage owner verified successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Error verifying garage owner: ' . $stmt->error];
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'System error: ' . $e->getMessage()];
    }
    break;

case 'verify_user':
    if (!isset($_POST['username'])) {
        $response = ['success' => false, 'message' => 'Username is required'];
        break;
    }
    
    $username = trim($_POST['username']);
    
    if (empty($username)) {
        $response = ['success' => false, 'message' => 'Username cannot be empty'];
        break;
    }
    
    try {
        // Update user status to verified
        $verifyQuery = "UPDATE account_information SET status = 'verified' WHERE username = ?";
        $stmt = $conn->prepare($verifyQuery);
        
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("s", $username);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to verify user: ' . $stmt->error);
        }
        
        if ($stmt->affected_rows === 0) {
            $response = ['success' => false, 'message' => "User '{$username}' not found"];
            break;
        }
        
        $response = ['success' => true, 'message' => "User '{$username}' verified successfully"];
        
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error verifying user: ' . $e->getMessage()];
        error_log("Verify user error: " . $e->getMessage());
    }
    break;

case 'get_verification_items':
    $response = ['success' => true, 'users' => [], 'owners' => [], 'unauthorized' => [], 'garages' => []];
   
    try {
        // Get unverified users
        $userQuery = "SELECT a.username, p.firstName, p.lastName, p.email
                     FROM account_information a
                     LEFT JOIN personal_information p ON a.username = p.username
                     WHERE a.status = 'unverified'
                     ORDER BY a.username
                     LIMIT 10";
        $result = $conn->query($userQuery);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $response['users'][] = $row;
            }
        }
       
        // Get unverified garage owners
        $ownerQuery = "SELECT go.owner_id, go.username, p.firstName, p.lastName, p.email
                      FROM garage_owners go
                      LEFT JOIN personal_information p ON go.username = p.username
                      WHERE go.is_verified = 0
                      ORDER BY go.registration_date DESC
                      LIMIT 10";
        $result = $conn->query($ownerQuery);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $response['owners'][] = $row;
            }
        }
       
        // Get unauthorized garage owners
        $unauthorizedQuery = "SELECT DISTINCT gi.username, p.firstName, p.lastName, p.email
                             FROM garage_information gi
                             LEFT JOIN garage_owners go ON gi.username = go.username
                             LEFT JOIN dual_user du ON gi.username = du.username
                             LEFT JOIN personal_information p ON gi.username = p.username
                             WHERE go.username IS NULL AND du.username IS NULL
                             ORDER BY gi.created_at DESC
                             LIMIT 10";
        $result = $conn->query($unauthorizedQuery);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $response['unauthorized'][] = $row;
            }
        }
       
        // Get unverified garages - THIS IS THE KEY PART THAT WAS MISSING
        $garageQuery = "SELECT gi.garage_id, gi.Parking_Space_Name, gi.Parking_Lot_Address, 
                              gi.username, gi.created_at, p.firstName, p.lastName
                       FROM garage_information gi
                       LEFT JOIN personal_information p ON gi.username = p.username
                       WHERE gi.is_verified = 0
                       ORDER BY gi.created_at DESC
                       LIMIT 10";
        $result = $conn->query($garageQuery);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $response['garages'][] = $row;
            }
        }
        
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error fetching verification items: ' . $e->getMessage()];
    }
    break;
// ==============================================================================
// UPDATE GARAGE SCHEDULE ACTION - USING YOUR EXACT SCHEMA
// ==============================================================================
case 'update_garage_schedule':
    try {
        if (!isset($_POST['garage_id'])) {
            $response = ['success' => false, 'message' => 'Garage ID is required'];
            break;
        }
        
        $garageId = $_POST['garage_id'];
        $is24_7 = isset($_POST['is_24_7']) ? 1 : 0;
        $openingTime = $_POST['opening_time'] ?? '06:00:00';
        $closingTime = $_POST['closing_time'] ?? '22:00:00';
        $operatingDays = $_POST['operating_days'] ?? 'monday,tuesday,wednesday,thursday,friday,saturday,sunday';
        
        // Update schedule using your exact schema
        $scheduleQuery = "INSERT INTO garage_operating_schedule 
                         (garage_id, opening_time, closing_time, operating_days, is_24_7, updated_at)
                         VALUES (?, ?, ?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE 
                         opening_time = VALUES(opening_time),
                         closing_time = VALUES(closing_time),
                         operating_days = VALUES(operating_days),
                         is_24_7 = VALUES(is_24_7),
                         updated_at = VALUES(updated_at)";
        
        $stmt = $conn->prepare($scheduleQuery);
        if (!$stmt) {
            $response = ['success' => false, 'message' => 'Database prepare error: ' . $conn->error];
            break;
        }
        
        $stmt->bind_param("ssssi", $garageId, $openingTime, $closingTime, $operatingDays, $is24_7);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Garage schedule updated successfully'];
        } else {
            $response = ['success' => false, 'message' => 'Error updating schedule: ' . $stmt->error];
        }
        
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error updating garage schedule: ' . $e->getMessage()];
        error_log("Error in update_garage_schedule: " . $e->getMessage());
    }
    break;
// ==============================================================================
// UPDATE USER ACTION
// ==============================================================================
case 'update_user':
    if (!isset($_POST['username']) || !isset($_POST['password'])) {
        $response = ['success' => false, 'message' => 'Username and password are required'];
        break;
    }
    
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $firstName = trim($_POST['firstName'] ?? '');
    $lastName = trim($_POST['lastName'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Validate inputs
    if (empty($username) || empty($password)) {
        $response = ['success' => false, 'message' => 'Username and password cannot be empty'];
        break;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Check if user exists
        $checkQuery = "SELECT username FROM account_information WHERE username = ?";
        $stmt = $conn->prepare($checkQuery);
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result || $result->num_rows === 0) {
            $response = ['success' => false, 'message' => "User '{$username}' not found"];
            break;
        }
        
        // Update account info
        $accountQuery = "UPDATE account_information SET password = ? WHERE username = ?";
        $stmt = $conn->prepare($accountQuery);
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("ss", $password, $username);
        if (!$stmt->execute()) {
            throw new Exception('Failed to update account: ' . $stmt->error);
        }
        
        // Handle personal information
        $checkPersonalQuery = "SELECT username FROM personal_information WHERE username = ?";
        $stmt = $conn->prepare($checkPersonalQuery);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $personalResult = $stmt->get_result();
            
            if ($personalResult && $personalResult->num_rows > 0) {
                // Update existing personal info
                $personalQuery = "UPDATE personal_information 
                                 SET firstName = ?, lastName = ?, email = ?, phone = ?, address = ? 
                                 WHERE username = ?";
                $stmt = $conn->prepare($personalQuery);
                if ($stmt) {
                    $stmt->bind_param("ssssss", $firstName, $lastName, $email, $phone, $address, $username);
                    $stmt->execute();
                }
            } else {
                // Insert new personal info if any data provided
                if (!empty($firstName) || !empty($lastName) || !empty($email) || !empty($phone) || !empty($address)) {
                    $personalQuery = "INSERT INTO personal_information (firstName, lastName, email, phone, address, username) 
                                      VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($personalQuery);
                    if ($stmt) {
                        $stmt->bind_param("ssssss", $firstName, $lastName, $email, $phone, $address, $username);
                        $stmt->execute();
                    }
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        $response = [
            'success' => true, 
            'message' => "User '{$username}' updated successfully",
            'data' => ['username' => $username]
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        $response = [
            'success' => false, 
            'message' => 'Error updating user: ' . $e->getMessage()
        ];
        error_log("Update user error: " . $e->getMessage());
    }
    break;
// Add this case to your main switch statement:
case 'get_booking':
    if (!isset($_POST['booking_id'])) {
        $response = ['success' => false, 'message' => 'Booking ID is required'];
        break;
    }
    
    $bookingId = (int)$_POST['booking_id'];
    
    // FIXED: Use correct column names from your database schema
    $bookingQuery = "SELECT b.*, 
                            gi.Parking_Space_Name as garage_name, 
                            gi.Parking_Lot_Address as garage_location,
                            gi.PriceperHour as garage_price,
                            p.amount as payment_amount, 
                            p.payment_method, 
                            p.transaction_id, 
                            p.payment_status as payment_status_detail,
                            v.make, 
                            v.model, 
                            v.color,
                            v.vehicleType,
                            pi.firstName,
                            pi.lastName,
                            pi.email,
                            pi.phone
                     FROM bookings b
                     LEFT JOIN garage_information gi ON b.garage_id = gi.garage_id
                     LEFT JOIN payments p ON b.id = p.booking_id
                     LEFT JOIN vehicle_information v ON b.licenseplate = v.licensePlate
                     LEFT JOIN personal_information pi ON b.username = pi.username
                     WHERE b.id = ?";
    
    $stmt = $conn->prepare($bookingQuery);
    if (!$stmt) {
        $response = ['success' => false, 'message' => 'Database error: ' . $conn->error];
        break;
    }
    
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result || $result->num_rows === 0) {
        $response = ['success' => false, 'message' => 'Booking not found'];
        break;
    }
    
    $booking = $result->fetch_assoc();
    $response = [
        'success' => true,
        'message' => 'Booking details retrieved successfully',
        'data' => $booking
    ];
    break;



    case 'get_revenue_trends':
    $period = $_POST['period'] ?? 'last_7_days';
    
    try {
        $trends_data = getRevenueTrends($conn, $period);
        
        if ($trends_data && count($trends_data) > 0) {
            echo json_encode([
                'success' => true,
                'data' => $trends_data,
                'period' => $period,
                'message' => 'Data retrieved successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'data' => [],
                'period' => $period,
                'message' => 'No data available for this period'
            ]);
        }
    } catch (Exception $e) {
        error_log("Revenue trends error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'data' => [],
            'period' => $period,
            'error' => $e->getMessage()
        ]);
    }
    break;
// Alternative: If you have get_booking_details case, update it like this:

case 'get_booking_details':
    if (!isset($_POST['booking_id'])) {
        $response = ['success' => false, 'message' => 'Booking ID is required'];
        break;
    }
    
    $bookingId = (int)$_POST['booking_id'];
    
    // FIXED: Use correct column names from your database schema
    $bookingQuery = "SELECT b.*, 
                            gi.Parking_Space_Name as garage_name, 
                            gi.Parking_Lot_Address as garage_location,
                            gi.PriceperHour as garage_price,
                            p.amount as payment_amount, 
                            p.payment_method, 
                            p.transaction_id, 
                            p.payment_status as payment_status_detail,
                            v.make, 
                            v.model, 
                            v.color,
                            v.vehicleType,
                            pi.firstName,
                            pi.lastName,
                            pi.email,
                            pi.phone
                     FROM bookings b
                     LEFT JOIN garage_information gi ON b.garage_id = gi.garage_id
                     LEFT JOIN payments p ON b.id = p.booking_id
                     LEFT JOIN vehicle_information v ON b.licenseplate = v.licensePlate
                     LEFT JOIN personal_information pi ON b.username = pi.username
                     WHERE b.id = ?";
    
    $stmt = $conn->prepare($bookingQuery);
    if (!$stmt) {
        $response = ['success' => false, 'message' => 'Database error: ' . $conn->error];
        break;
    }
    
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result || $result->num_rows === 0) {
        $response = ['success' => false, 'message' => 'Booking not found'];
        break;
    }
    
    $booking = $result->fetch_assoc();
    $response = [
        'success' => true,
        'message' => 'Booking details retrieved successfully',
        'data' => $booking
    ];
    break;
// ==============================================================================
// DELETE USER ACTION
// ==============================================================================
case 'delete_user':
    if (!isset($_POST['username'])) {
        $response = ['success' => false, 'message' => 'Username is required'];
        break;
    }
    
    $username = trim($_POST['username']);
    
    if (empty($username)) {
        $response = ['success' => false, 'message' => 'Username cannot be empty'];
        break;
    }
    
    // Prevent deleting admin user
    if ($username === 'admin') {
        $response = ['success' => false, 'message' => 'Cannot delete admin user'];
        break;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Check if user exists
        $checkQuery = "SELECT username FROM account_information WHERE username = ?";
        $stmt = $conn->prepare($checkQuery);
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result || $result->num_rows === 0) {
            $response = ['success' => false, 'message' => "User '{$username}' not found"];
            break;
        }
        
        // Check for dependencies (bookings, garages, etc.)
        $bookingsQuery = "SELECT COUNT(*) as count FROM bookings WHERE username = ?";
        $stmt = $conn->prepare($bookingsQuery);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $bookingResult = $stmt->get_result();
            $bookingCount = $bookingResult->fetch_assoc()['count'];
            
            if ($bookingCount > 0) {
                $response = ['success' => false, 'message' => "Cannot delete user: has {$bookingCount} booking(s). Please handle bookings first."];
                break;
            }
        }
        
        $garagesQuery = "SELECT COUNT(*) as count FROM garage_information WHERE username = ?";
        $stmt = $conn->prepare($garagesQuery);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $garageResult = $stmt->get_result();
            $garageCount = $garageResult->fetch_assoc()['count'];
            
            if ($garageCount > 0) {
                $response = ['success' => false, 'message' => "Cannot delete user: owns {$garageCount} garage(s). Please transfer or delete garages first."];
                break;
            }
        }
        
        // Delete in proper order (foreign key constraints)
        
        // Delete personal information
        $deletePersonalQuery = "DELETE FROM personal_information WHERE username = ?";
        $stmt = $conn->prepare($deletePersonalQuery);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
        }
        
        // Delete points transactions
        $deletePointsQuery = "DELETE FROM points_transactions WHERE username = ?";
        $stmt = $conn->prepare($deletePointsQuery);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
        }
        
        // Delete verification documents
        $deleteDocsQuery = "DELETE FROM verification_documents WHERE username = ?";
        $stmt = $conn->prepare($deleteDocsQuery);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
        }
        
        // Delete verification requests
        $deleteRequestsQuery = "DELETE FROM verification_requests WHERE username = ?";
        $stmt = $conn->prepare($deleteRequestsQuery);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
        }
        
        // Delete from garage_owners or dual_user if exists
        $deleteOwnerQuery = "DELETE FROM garage_owners WHERE username = ?";
        $stmt = $conn->prepare($deleteOwnerQuery);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
        }
        
        $deleteDualQuery = "DELETE FROM dual_user WHERE username = ?";
        $stmt = $conn->prepare($deleteDualQuery);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
        }
        
        // Delete login history
        $deleteLoginQuery = "DELETE FROM user_login_history WHERE username = ?";
        $stmt = $conn->prepare($deleteLoginQuery);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
        }
        
        // Finally delete account
        $deleteAccountQuery = "DELETE FROM account_information WHERE username = ?";
        $stmt = $conn->prepare($deleteAccountQuery);
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("s", $username);
        if (!$stmt->execute()) {
            throw new Exception('Failed to delete account: ' . $stmt->error);
        }
        
        // Commit transaction
        $conn->commit();
        
        $response = [
            'success' => true, 
            'message' => "User '{$username}' deleted successfully"
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        $response = [
            'success' => false, 
            'message' => 'Error deleting user: ' . $e->getMessage()
        ];
        error_log("Delete user error: " . $e->getMessage());
    }
    break;

// ==============================================================================
// VERIFY USER ACTION
// ==============================================================================
case 'verify_user':
    if (!isset($_POST['username'])) {
        $response = ['success' => false, 'message' => 'Username is required'];
        break;
    }
    
    $username = trim($_POST['username']);
    
    if (empty($username)) {
        $response = ['success' => false, 'message' => 'Username cannot be empty'];
        break;
    }
    
    try {
        // Update user status to verified
        $verifyQuery = "UPDATE account_information SET status = 'verified' WHERE username = ?";
        $stmt = $conn->prepare($verifyQuery);
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("s", $username);
        if (!$stmt->execute()) {
            throw new Exception('Failed to verify user: ' . $stmt->error);
        }
        
        if ($stmt->affected_rows === 0) {
            $response = ['success' => false, 'message' => "User '{$username}' not found"];
            break;
        }
        
        $response = [
            'success' => true, 
            'message' => "User '{$username}' verified successfully"
        ];
        
    } catch (Exception $e) {
        $response = [
            'success' => false, 
            'message' => 'Error verifying user: ' . $e->getMessage()
        ];
        error_log("Verify user error: " . $e->getMessage());
    }
    break;
// ==============================================================================
// GET USER VERIFICATION DOCS ACTION (if you need this too)
// ==============================================================================
case 'get_user_verification_docs':
    if (!isset($_POST['username']) || empty(trim($_POST['username']))) {
        $response = ['success' => false, 'message' => 'Username is required'];
        break;
    }
    
    $username = trim($_POST['username']);
    
    try {
        // FIXED: Get verification documents first
        $docsQuery = "SELECT vd.*, 
                            CASE 
                                WHEN vd.document_type = 'nid' THEN 'National ID'
                                WHEN vd.document_type = 'driving_license' THEN 'Driving License'
                                WHEN vd.document_type = 'passport' THEN 'Passport'
                                WHEN vd.document_type = 'vehicle_registration' THEN 'Vehicle Registration'
                                WHEN vd.document_type = 'vehicle_insurance' THEN 'Vehicle Insurance'
                                ELSE UPPER(vd.document_type)
                            END as document_type_display
                     FROM verification_documents vd
                     WHERE vd.username = ?
                     ORDER BY vd.submitted_at DESC";
        
        $stmt = $conn->prepare($docsQuery);
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $docsResult = $stmt->get_result();
        
        $documents = [];
        if ($docsResult) {
            while ($row = $docsResult->fetch_assoc()) {
                $documents[] = $row;
            }
        }
        
        // Get verification request
        $requestQuery = "SELECT vr.*, ai.status as account_status,
                               CONCAT(COALESCE(pi.firstName, ''), ' ', COALESCE(pi.lastName, '')) as full_name,
                               pi.email, pi.phone
                        FROM verification_requests vr
                        LEFT JOIN account_information ai ON vr.username = ai.username
                        LEFT JOIN personal_information pi ON vr.username = pi.username
                        WHERE vr.username = ?
                        ORDER BY vr.requested_at DESC LIMIT 1";
        
        $stmt = $conn->prepare($requestQuery);
        $verificationRequest = null;
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $requestResult = $stmt->get_result();
            
            if ($requestResult && $requestResult->num_rows > 0) {
                $verificationRequest = $requestResult->fetch_assoc();
            }
        }
        
        // Get user basic info if no verification request exists
        if (!$verificationRequest) {
            $userQuery = "SELECT ai.status as account_status,
                                CONCAT(COALESCE(pi.firstName, ''), ' ', COALESCE(pi.lastName, '')) as full_name,
                                pi.email, pi.phone
                         FROM account_information ai
                         LEFT JOIN personal_information pi ON ai.username = pi.username
                         WHERE ai.username = ?";
            
            $stmt = $conn->prepare($userQuery);
            if ($stmt) {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $userResult = $stmt->get_result();
                
                if ($userResult && $userResult->num_rows > 0) {
                    $userInfo = $userResult->fetch_assoc();
                    $verificationRequest = [
                        'username' => $username,
                        'overall_status' => 'no_request',
                        'account_status' => $userInfo['account_status'],
                        'full_name' => $userInfo['full_name'],
                        'email' => $userInfo['email'],
                        'phone' => $userInfo['phone'],
                        'requested_at' => null,
                        'admin_notes' => null
                    ];
                }
            }
        }
        
        $response = [
            'success' => true,
            'message' => 'Verification documents retrieved successfully',
            'data' => [
                'username' => $username,
                'verification_request' => $verificationRequest,
                'documents' => $documents,
                'total_documents' => count($documents)
            ]
        ];
        
    } catch (Exception $e) {
        $response = [
            'success' => false, 
            'message' => 'Error retrieving verification documents: ' . $e->getMessage()
        ];
        error_log("Get verification docs error: " . $e->getMessage());
    }
    break;
case 'review_user_verification':
    $response = ['success' => false, 'message' => 'Missing required parameters'];
    
    if (isset($_POST['username']) && isset($_POST['decision']) && isset($_POST['admin_notes'])) {
        $username = trim($_POST['username']);
        $decision = $_POST['decision'];
        $adminNotes = trim($_POST['admin_notes']);
        $adminUsername = $_SESSION['username'] ?? 'admin';
        
        try {
            $conn->begin_transaction();
            
            if ($decision === 'approve') {
                // Update account status to verified
                $updateAccountQuery = "UPDATE account_information SET status = 'verified' WHERE username = ?";
                $stmt = $conn->prepare($updateAccountQuery);
                $stmt->bind_param("s", $username);
                $stmt->execute();
                
                // Update documents to approved
                $updateDocsQuery = "UPDATE verification_documents 
                                   SET status = 'approved', reviewed_at = NOW(), reviewed_by = ? 
                                   WHERE username = ? AND status = 'pending'";
                $stmt = $conn->prepare($updateDocsQuery);
                $stmt->bind_param("ss", $adminUsername, $username);
                $stmt->execute();
                
                // Update verification request
                $updateRequestQuery = "UPDATE verification_requests 
                                      SET overall_status = 'approved', completed_at = NOW(), admin_notes = ? 
                                      WHERE username = ? AND overall_status IN ('pending', 'under_review')";
                $stmt = $conn->prepare($updateRequestQuery);
                $stmt->bind_param("ss", $adminNotes, $username);
                $stmt->execute();
                
                $response = [
                    'success' => true,
                    'message' => "User {$username} has been verified successfully!",
                    'new_status' => 'verified'
                ];
                
            } elseif ($decision === 'reject') {
                // Update documents to rejected
                $updateDocsQuery = "UPDATE verification_documents 
                                   SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ?, rejection_reason = ? 
                                   WHERE username = ? AND status = 'pending'";
                $stmt = $conn->prepare($updateDocsQuery);
                $stmt->bind_param("sss", $adminUsername, $adminNotes, $username);
                $stmt->execute();
                
                // Update verification request
                $updateRequestQuery = "UPDATE verification_requests 
                                      SET overall_status = 'rejected', completed_at = NOW(), admin_notes = ? 
                                      WHERE username = ? AND overall_status IN ('pending', 'under_review')";
                $stmt = $conn->prepare($updateRequestQuery);
                $stmt->bind_param("ss", $adminNotes, $username);
                $stmt->execute();
                
                $response = [
                    'success' => true,
                    'message' => "Verification request for {$username} has been rejected.",
                    'new_status' => 'unverified'
                ];
            } else {
                $response = ['success' => false, 'message' => 'Invalid decision. Must be approve or reject.'];
            }
            
            $conn->commit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $response = ['success' => false, 'message' => 'Database error occurred: ' . $e->getMessage()];
            error_log("Review verification error: " . $e->getMessage());
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
    break;

            // ==============================================================================
// ADJUST USER POINTS ACTION - Add this to your switch statement
// ==============================================================================
case 'adjust_user_points':
    if (!isset($_POST['username']) || !isset($_POST['points_change']) || !isset($_POST['reason'])) {
        $response = ['success' => false, 'message' => 'Missing required fields'];
        break;
    }
    
    $username = trim($_POST['username']);
    $pointsChange = (int)$_POST['points_change'];
    $reason = trim($_POST['reason']);
    $adminUsername = $_SESSION['username'] ?? 'admin';
    
    // Validate inputs
    if (empty($username)) {
        $response = ['success' => false, 'message' => 'Username cannot be empty'];
        break;
    }
    
    if ($pointsChange == 0) {
        $response = ['success' => false, 'message' => 'Points change cannot be zero'];
        break;
    }
    
    if (empty($reason)) {
        $response = ['success' => false, 'message' => 'Reason is required'];
        break;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get current points
        $getCurrentQuery = "SELECT points FROM account_information WHERE username = ?";
        $stmt = $conn->prepare($getCurrentQuery);
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result || $result->num_rows === 0) {
            $response = ['success' => false, 'message' => "User '{$username}' not found"];
            break;
        }
        
        $currentPoints = (int)$result->fetch_assoc()['points'];
        $newPoints = $currentPoints + $pointsChange;
        
        // Prevent negative points
        if ($newPoints < 0) {
            $response = ['success' => false, 'message' => "Cannot reduce points below zero. Current: {$currentPoints}, Requested change: {$pointsChange}"];
            break;
        }
        
        // Update points
        $updateQuery = "UPDATE account_information SET points = ? WHERE username = ?";
        $stmt = $conn->prepare($updateQuery);
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("is", $newPoints, $username);
        if (!$stmt->execute()) {
            throw new Exception('Failed to update points: ' . $stmt->error);
        }
        
        // Log transaction
        $transactionType = $pointsChange > 0 ? 'bonus' : 'spent';
        $description = "Admin adjustment by {$adminUsername}: {$reason}";
        
        $logQuery = "INSERT INTO points_transactions (username, transaction_type, points_amount, description, created_at) VALUES (?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($logQuery);
        if (!$stmt) {
            throw new Exception('Database prepare error for transaction log: ' . $conn->error);
        }
        
        $stmt->bind_param("ssis", $username, $transactionType, abs($pointsChange), $description);
        if (!$stmt->execute()) {
            throw new Exception('Failed to log transaction: ' . $stmt->error);
        }
        
        // Commit transaction
        $conn->commit();
        
        $response = [
            'success' => true,
            'message' => "Successfully updated {$username}'s points from {$currentPoints} to {$newPoints}",
            'data' => [
                'username' => $username,
                'old_points' => $currentPoints,
                'new_points' => $newPoints,
                'change' => $pointsChange,
                'reason' => $reason
            ]
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        $response = [
            'success' => false, 
            'message' => 'Database error occurred: ' . $e->getMessage()
        ];
        error_log("Points adjustment error: " . $e->getMessage());
    }
    break;
            // ==============================================================================
            // OWNER DETAILS ACTION
            // ==============================================================================
case 'get_owner_details':
    try {
        if (!isset($_POST['owner_id'])) {
            $response = ['success' => false, 'message' => 'Owner ID is required'];
            break;
        }
        
        $ownerId = $_POST['owner_id'];
        
        $ownerQuery = "SELECT 
                        COALESCE(go.owner_id, du.owner_id) as owner_id,
                        COALESCE(go.username, du.username) as username,
                        COALESCE(go.is_verified, du.is_verified) as is_verified,
                        COALESCE(go.registration_date, du.registration_date) as registration_date,
                        COALESCE(go.last_login, du.last_login) as last_login,
                        COALESCE(go.account_status, du.account_status) as account_status,
                        p.firstName, p.lastName, p.email, p.phone, p.address,
                        ai.points
                       FROM (SELECT ? as search_id) s
                       LEFT JOIN garage_owners go ON s.search_id = go.owner_id
                       LEFT JOIN dual_user du ON s.search_id = du.owner_id
                       LEFT JOIN personal_information p ON COALESCE(go.username, du.username) = p.username
                       LEFT JOIN account_information ai ON COALESCE(go.username, du.username) = ai.username";
        
        $stmt = $conn->prepare($ownerQuery);
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("s", $ownerId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result || $result->num_rows === 0) {
            $response = ['success' => false, 'message' => 'Owner not found'];
            break;
        }
        
        $owner = $result->fetch_assoc();
        
        $garagesQuery = "SELECT 
                            garage_id,
                            Parking_Space_Name as name,
                            Parking_Lot_Address as address,
                            Parking_Type as garage_type,
                            is_verified,
                            Parking_Capacity as parking_capacity,
                            PriceperHour as price_per_hour,
                            Availability as available_spots
                         FROM garage_information 
                         WHERE username = ?";
        
        $stmt = $conn->prepare($garagesQuery);
        if ($stmt) {
            $stmt->bind_param("s", $owner['username']);
            $stmt->execute();
            $garagesResult = $stmt->get_result();
            
            $garages = [];
            if ($garagesResult) {
                while ($garage = $garagesResult->fetch_assoc()) {
                    $garages[] = $garage;
                }
            }
            $owner['garages'] = $garages;
        }
        
        // Rest of your existing code...
        
        $response = [
            'success' => true,
            'message' => 'Owner details retrieved successfully',
            'data' => $owner
        ];
        
    } catch (Exception $e) {
        $response = [
            'success' => false, 
            'message' => 'System error: ' . $e->getMessage()
        ];
        error_log("Get owner details error: " . $e->getMessage());
    }
    break;
            
            // ==============================================================================
            // BOOKING DETAILS ACTION
            // ==============================================================================
            case 'get_booking_details':
                if (!isset($_POST['booking_id'])) {
                    $response = ['success' => false, 'message' => 'Booking ID is required'];
                    break;
                }
                
                $bookingId = (int)$_POST['booking_id'];
                
                $bookingQuery = "SELECT b.*, gi.Parking_Space_Name as garage_name, 
                                        gi.Location as garage_location,
                                        p.amount as payment_amount, p.payment_method, 
                                        p.transaction_id, p.payment_status as payment_status_detail
                                 FROM bookings b
                                 LEFT JOIN garage_information gi ON b.garage_id = gi.garage_id
                                 LEFT JOIN payments p ON b.id = p.booking_id
                                 WHERE b.id = ?";
                
                $stmt = $conn->prepare($bookingQuery);
                if (!$stmt) {
                    $response = ['success' => false, 'message' => 'Database error: ' . $conn->error];
                    break;
                }
                
                $stmt->bind_param("i", $bookingId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if (!$result || $result->num_rows === 0) {
                    $response = ['success' => false, 'message' => 'Booking not found'];
                    break;
                }
                
                $booking = $result->fetch_assoc();
                
                $response = [
                    'success' => true,
                    'message' => 'Booking details retrieved successfully',
                    'data' => $booking
                ];
                break;
            
            case 'get_payment_details':
    if (!isset($_POST['payment_id'])) {
        $response = ['success' => false, 'message' => 'Payment ID is required'];
        break;
    }
    
    $paymentId = (int)$_POST['payment_id'];
    
    // FIXED: Use correct column names from your database schema
    $paymentQuery = "SELECT p.*, 
                            b.username as customer, 
                            b.garage_id,
                            b.licenseplate,  -- ✅ Fixed: was b.vehicle_license_plate
                            b.booking_date, 
                            b.booking_time,
                            b.duration,
                            b.status as booking_status,
                            gi.Parking_Space_Name as garage_name,
                            gi.Parking_Lot_Address as garage_address
                     FROM payments p
                     LEFT JOIN bookings b ON p.booking_id = b.id
                     LEFT JOIN garage_information gi ON b.garage_id = gi.garage_id
                     WHERE p.payment_id = ?";
    
    $stmt = $conn->prepare($paymentQuery);
    if (!$stmt) {
        $response = ['success' => false, 'message' => 'Database error: ' . $conn->error];
        break;
    }
    
    $stmt->bind_param("i", $paymentId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result || $result->num_rows === 0) {
        $response = ['success' => false, 'message' => 'Payment not found'];
        break;
    }
    
    $payment = $result->fetch_assoc();
    
    $response = [
        'success' => true,
        'message' => 'Payment details retrieved successfully',
        'data' => $payment
    ];
    break;
            case 'update_owner_status':
            $response = ['success' => false, 'message' => 'Failed to update owner status'];
            
            if (isset($_POST['owner_id']) && isset($_POST['status'])) {
                $ownerId = $_POST['owner_id'];
                $status = $_POST['status'];
                
                // Validate status values
                $allowedStatuses = ['Active', 'Suspended', 'Deactivated'];
                if (!in_array($status, $allowedStatuses)) {
                    $response = ['success' => false, 'message' => 'Invalid status value'];
                } else {
                    try {
                        // Determine which table to update based on owner_id prefix
                        if (strpos($ownerId, 'U_owner_') === 0) {
                            // This is a dual user
                            $updateQuery = "UPDATE dual_user SET status = ? WHERE owner_id = ?";
                        } else {
                            // This is a garage owner
                            $updateQuery = "UPDATE garage_owners SET status = ? WHERE owner_id = ?";
                        }
                        
                        $stmt = $conn->prepare($updateQuery);
                        if (!$stmt) {
                            $response = ['success' => false, 'message' => 'Database prepare error: ' . $conn->error];
                        } else {
                            $stmt->bind_param("ss", $status, $ownerId);
                            
                            if ($stmt->execute()) {
                                if ($stmt->affected_rows > 0) {
                                    $response = [
                                        'success' => true, 
                                        'message' => "Owner status updated to '{$status}' successfully",
                                        'owner_id' => $ownerId,
                                        'new_status' => $status
                                    ];
                                } else {
                                    $response = ['success' => false, 'message' => 'Owner not found or status already set'];
                                }
                            } else {
                                $response = ['success' => false, 'message' => 'Database error: ' . $stmt->error];
                            }
                            $stmt->close();
                        }
                    } catch (Exception $e) {
                        $response = [
                            'success' => false, 
                            'message' => 'Error updating owner status: ' . $e->getMessage()
                        ];
                    }
                }
            } else {
                $response = ['success' => false, 'message' => 'Owner ID and status are required'];
            }
            
            header('Content-Type: application/json');
            echo json_encode($response);
            exit();
            break;
            // ==============================================================================
            // TEST CONNECTION ACTION
            // ==============================================================================
            case 'test_connection':
                $testQuery = "SELECT COUNT(*) as count FROM garage_information";
                $result = $conn->query($testQuery);
                
                if (!$result) {
                    $response = ['success' => false, 'message' => 'Database connection failed: ' . $conn->error];
                    break;
                }
                
                $count = $result->fetch_assoc()['count'];
                
                $response = [
                    'success' => true,
                    'message' => 'Database connection successful',
                    'data' => [
                        'total_garages' => $count,
                        'connection_test' => 'passed',
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ];
                break;
            
            // ==============================================================================
            // DEFAULT CASE
            // ==============================================================================
            default:
                $response = ['success' => false, 'message' => 'Unknown action: ' . $action];
                break;
        }
        
    } catch (Exception $e) {
        $response = [
            'success' => false, 
            'message' => 'System error: ' . $e->getMessage(),
            'debug' => [
                'error_line' => $e->getLine(),
                'error_file' => basename($e->getFile())
            ]
        ];
    }
    
    // Output JSON response and exit
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}


// 1. NEW: Get user verification documents
if (isset($_POST['action']) && $_POST['action'] === 'get_user_verification_docs') {
    $response = ['success' => false, 'message' => 'Username is required'];
    
    if (isset($_POST['username']) && !empty(trim($_POST['username']))) {
        $username = trim($_POST['username']);
        
        try {
            // Get verification request
            $requestQuery = "SELECT vr.*, ai.status as account_status,
                                   CONCAT(pi.firstName, ' ', pi.lastName) as full_name,
                                   pi.email, pi.phone
                            FROM verification_requests vr
                            LEFT JOIN account_information ai ON vr.username = ai.username
                            LEFT JOIN personal_information pi ON vr.username = pi.username
                            WHERE vr.username = ?
                            ORDER BY vr.requested_at DESC LIMIT 1";
            
            $stmt = $conn->prepare($requestQuery);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $requestResult = $stmt->get_result();
            
            $verificationRequest = null;
            if ($requestResult && $requestResult->num_rows > 0) {
                $verificationRequest = $requestResult->fetch_assoc();
            }
            
            // Get verification documents
            $docsQuery = "SELECT vd.*, 
                                CASE 
                                    WHEN vd.document_type = 'nid' THEN 'National ID'
                                    WHEN vd.document_type = 'driving_license' THEN 'Driving License'
                                    WHEN vd.document_type = 'passport' THEN 'Passport'
                                    WHEN vd.document_type = 'vehicle_registration' THEN 'Vehicle Registration'
                                    WHEN vd.document_type = 'vehicle_insurance' THEN 'Vehicle Insurance'
                                    ELSE UPPER(vd.document_type)
                                END as document_type_display
                         FROM verification_documents vd
                         WHERE vd.username = ?
                         ORDER BY vd.submitted_at DESC";
            
            $stmt = $conn->prepare($docsQuery);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $docsResult = $stmt->get_result();
            
            $documents = [];
            if ($docsResult && $docsResult->num_rows > 0) {
                while ($row = $docsResult->fetch_assoc()) {
                    $documents[] = $row;
                }
            }
            
            $response = [
                'success' => true,
                'username' => $username,
                'verification_request' => $verificationRequest,
                'documents' => $documents,
                'total_documents' => count($documents)
            ];
            
        } catch (Exception $e) {
            $response = ['success' => false, 'message' => 'Error retrieving verification documents'];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

// 2. NEW: Review user verification
if (isset($_POST['action']) && $_POST['action'] === 'review_user_verification') {
    $response = ['success' => false, 'message' => 'Missing required parameters'];
    
    if (isset($_POST['username']) && isset($_POST['decision']) && isset($_POST['admin_notes'])) {
        $username = trim($_POST['username']);
        $decision = $_POST['decision'];
        $adminNotes = trim($_POST['admin_notes']);
        $adminUsername = $_SESSION['username'] ?? 'admin';
        
        try {
            $conn->begin_transaction();
            
            if ($decision === 'approve') {
                // Update account status to verified
                $updateAccountQuery = "UPDATE account_information SET status = 'verified' WHERE username = ?";
                $stmt = $conn->prepare($updateAccountQuery);
                $stmt->bind_param("s", $username);
                $stmt->execute();
                
                // Update documents to approved
                $updateDocsQuery = "UPDATE verification_documents 
                                   SET status = 'approved', reviewed_at = NOW(), reviewed_by = ? 
                                   WHERE username = ? AND status = 'pending'";
                $stmt = $conn->prepare($updateDocsQuery);
                $stmt->bind_param("ss", $adminUsername, $username);
                $stmt->execute();
                
                // Update verification request
                $updateRequestQuery = "UPDATE verification_requests 
                                      SET overall_status = 'approved', completed_at = NOW(), admin_notes = ? 
                                      WHERE username = ? AND overall_status IN ('pending', 'under_review')";
                $stmt = $conn->prepare($updateRequestQuery);
                $stmt->bind_param("ss", $adminNotes, $username);
                $stmt->execute();
                
                $response = [
                    'success' => true,
                    'message' => "User {$username} has been verified successfully!",
                    'new_status' => 'verified'
                ];
                
            } elseif ($decision === 'reject') {
                // Update documents to rejected
                $updateDocsQuery = "UPDATE verification_documents 
                                   SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ?, rejection_reason = ? 
                                   WHERE username = ? AND status = 'pending'";
                $stmt = $conn->prepare($updateDocsQuery);
                $stmt->bind_param("sss", $adminUsername, $adminNotes, $username);
                $stmt->execute();
                
                // Update verification request
                $updateRequestQuery = "UPDATE verification_requests 
                                      SET overall_status = 'rejected', completed_at = NOW(), admin_notes = ? 
                                      WHERE username = ? AND overall_status IN ('pending', 'under_review')";
                $stmt = $conn->prepare($updateRequestQuery);
                $stmt->bind_param("ss", $adminNotes, $username);
                $stmt->execute();
                
                $response = [
                    'success' => true,
                    'message' => "Verification request for {$username} has been rejected.",
                    'new_status' => 'unverified'
                ];
            }
            
            $conn->commit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $response = ['success' => false, 'message' => 'Database error occurred'];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}
// Handler for revenue statistics
if (isset($_POST['action']) && $_POST['action'] === 'get_revenue_stats') {
    $revenueStats = getRevenueStats($conn);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $revenueStats]);
    exit();
}

// Handler for payment method revenue breakdown
if (isset($_POST['action']) && $_POST['action'] === 'get_payment_method_revenue') {
    $paymentMethodData = getRevenueByPaymentMethod($conn);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $paymentMethodData]);
    exit();
}

// Handler for top revenue garages
if (isset($_POST['action']) && $_POST['action'] === 'get_top_revenue_garages') {
    $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 10;
    $topGarages = getTopRevenueGarages($conn, $limit);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $topGarages]);
    exit();
}

// Handler for revenue trends
if (isset($_POST['action']) && $_POST['action'] === 'get_revenue_trends') {
    $period = $_POST['period'] ?? 'last_30_days';
    $trends = getRevenueTrends($conn, $period);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $trends]);
    exit();
}
// Handle get user points history
if (isset($_POST['action']) && $_POST['action'] === 'get_user_points_history') {
    // Clean any previous output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    $response = ['success' => false, 'message' => 'Username is required'];
    
    try {
        if (isset($_POST['username']) && !empty(trim($_POST['username']))) {
            $username = trim($_POST['username']);
            
            // Get user's current points
            $pointsQuery = "SELECT points FROM account_information WHERE username = ?";
            $stmt = $conn->prepare($pointsQuery);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $pointsResult = $stmt->get_result();
            
            $currentPoints = 0;
            if ($pointsResult && $pointsResult->num_rows > 0) {
                $currentPoints = (int)$pointsResult->fetch_assoc()['points'];
            }
            
            // Get points transaction history (last 20 transactions)
            $historyQuery = "SELECT 
                                id,
                                transaction_type,
                                points_amount,
                                description,
                                booking_id,
                                created_at
                            FROM points_transactions 
                            WHERE username = ? 
                            ORDER BY created_at DESC 
                            LIMIT 20";
            
            $stmt = $conn->prepare($historyQuery);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $historyResult = $stmt->get_result();
            
            $history = [];
            if ($historyResult && $historyResult->num_rows > 0) {
                while ($row = $historyResult->fetch_assoc()) {
                    $history[] = [
                        'id' => $row['id'],
                        'transaction_type' => $row['transaction_type'],
                        'points_amount' => (int)$row['points_amount'],
                        'description' => $row['description'],
                        'booking_id' => $row['booking_id'],
                        'created_at' => $row['created_at']
                    ];
                }
            }
            
            $response = [
                'success' => true,
                'current_points' => $currentPoints,
                'history' => $history,
                'total_transactions' => count($history),
                'username' => $username
            ];
            
        } else {
            $response = ['success' => false, 'message' => 'Invalid or missing username'];
        }
        
    } catch (Exception $e) {
        error_log("Points history error: " . $e->getMessage());
        $response = [
            'success' => false, 
            'message' => 'Error retrieving points history'
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}
// Handle points adjustment
if (isset($_POST['action']) && $_POST['action'] === 'adjust_user_points') {
    // CRITICAL: Clean all output buffers and prevent any HTML output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Turn off error display to prevent contamination
    ini_set('display_errors', 0);
    
    // Set headers early
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    
    $response = ['success' => false, 'message' => 'Missing required parameters'];
    
    try {
        // Validate required fields
        if (!isset($_POST['username']) || !isset($_POST['points_change']) || !isset($_POST['reason'])) {
            $response = ['success' => false, 'message' => 'Missing required fields'];
        } else {
            $username = trim($_POST['username']);
            $pointsChange = (int)$_POST['points_change'];
            $reason = trim($_POST['reason']);
            $adminUsername = $_SESSION['username'] ?? 'admin';
            
            // Validate inputs
            if (empty($username)) {
                $response = ['success' => false, 'message' => 'Username cannot be empty'];
            } elseif ($pointsChange == 0) {
                $response = ['success' => false, 'message' => 'Points change cannot be zero'];
            } elseif (empty($reason)) {
                $response = ['success' => false, 'message' => 'Reason cannot be empty'];
            } else {
                // Database operations
                $conn->begin_transaction();
                
                // Get current points
                $stmt = $conn->prepare("SELECT points FROM account_information WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result && $result->num_rows > 0) {
                    $currentPoints = (int)$result->fetch_assoc()['points'];
                    $newPoints = $currentPoints + $pointsChange;
                    
                    // Prevent negative points
                    if ($newPoints < 0) {
                        $conn->rollback();
                        $response = [
                            'success' => false, 
                            'message' => "Cannot reduce points below zero. Current: {$currentPoints}, Attempted change: {$pointsChange}"
                        ];
                    } else {
                        // Update points
                        $stmt = $conn->prepare("UPDATE account_information SET points = ? WHERE username = ?");
                        $stmt->bind_param("is", $newPoints, $username);
                        
                        if ($stmt->execute()) {
                            // Log transaction
                            $transactionType = $pointsChange > 0 ? 'bonus' : 'spent';
                            $description = "Admin adjustment by {$adminUsername}: {$reason}";
                            
                            $stmt = $conn->prepare("INSERT INTO points_transactions (username, transaction_type, points_amount, description) VALUES (?, ?, ?, ?)");
                            $stmt->bind_param("ssis", $username, $transactionType, abs($pointsChange), $description);
                            
                            if ($stmt->execute()) {
                                $conn->commit();
                                $response = [
                                    'success' => true,
                                    'message' => "Successfully updated {$username}'s points from {$currentPoints} to {$newPoints}",
                                    'old_points' => $currentPoints,
                                    'new_points' => $newPoints,
                                    'change' => $pointsChange
                                ];
                            } else {
                                $conn->rollback();
                                $response = ['success' => false, 'message' => 'Failed to log transaction'];
                            }
                        } else {
                            $conn->rollback();
                            $response = ['success' => false, 'message' => 'Failed to update points'];
                        }
                    }
                } else {
                    $conn->rollback();
                    $response = ['success' => false, 'message' => "User '{$username}' not found"];
                }
            }
        }
    } catch (Exception $e) {
        if (isset($conn) && $conn->ping()) {
            $conn->rollback();
        }
        $response = [
            'success' => false, 
            'message' => 'Database error occurred'
        ];
        
        // Log the actual error (but don't send it to client)
        error_log("Points adjustment error: " . $e->getMessage());
    }
    
    // CRITICAL: Output ONLY the JSON, nothing else
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit; // Prevent any further output
}

// Handle get user points history
if (isset($_POST['action']) && $_POST['action'] === 'get_user_points_history') {
    $response = ['success' => false, 'message' => 'Username is required'];
    
    if (isset($_POST['username'])) {
        $username = $_POST['username'];
        
        // Get user's current points
        $pointsQuery = "SELECT points FROM account_information WHERE username = ?";
        $stmt = $conn->prepare($pointsQuery);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $pointsResult = $stmt->get_result();
        
        $currentPoints = 0;
        if ($pointsResult && $pointsResult->num_rows > 0) {
            $currentPoints = $pointsResult->fetch_assoc()['points'];
        }
        
        // Get points transaction history
        $historyQuery = "SELECT * FROM points_transactions WHERE username = ? ORDER BY created_at DESC LIMIT 20";
        $stmt = $conn->prepare($historyQuery);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $historyResult = $stmt->get_result();
        
        $history = [];
        if ($historyResult && $historyResult->num_rows > 0) {
            while ($row = $historyResult->fetch_assoc()) {
                $history[] = $row;
            }
        }
        
        $response = [
            'success' => true,
            'current_points' => $currentPoints,
            'history' => $history
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}



// SOLUTION 2: Also make sure this debug handler is temporarily added to see what's happening
if (isset($_POST['action']) && $_POST['action'] === 'debug_action') {
    $response = [
        'success' => true,
        'message' => 'Debug successful',
        'received_action' => $_POST['action'],
        'all_post_data' => $_POST
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}


// Get notification counts when the page loads
$notificationCounts = getNotificationCounts($conn);

// Add a new AJAX endpoint to get updated notification counts
if (isset($_POST['action']) && $_POST['action'] === 'get_notification_counts') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'counts' => getNotificationCounts($conn)]);
    exit();
}



// Add this to your AJAX handlers in admin.php

// Handle setting default commission for all garage owners
if (isset($_POST['action']) && $_POST['action'] === 'set_default_commission_for_all') {
    $response = ['success' => false, 'message' => 'Failed to update commission rates'];
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Default commission rate
        $defaultRate = 30.00;
        
        // Get all owner IDs from both garage_owners and dual_user tables
        $allOwnersQuery = "SELECT owner_id FROM garage_owners 
                          UNION 
                          SELECT owner_id FROM dual_user";
        
        $result = $conn->query($allOwnersQuery);
        $updateCount = 0;
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $ownerId = $row['owner_id'];
                $ownerType = (strpos($ownerId, 'U_owner_') === 0) ? 'dual' : 'garage';
                
                // Check if commission record exists
                $checkQuery = "SELECT id FROM owner_commissions WHERE owner_id = ?";
                $stmt = $conn->prepare($checkQuery);
                $stmt->bind_param("s", $ownerId);
                $stmt->execute();
                $checkResult = $stmt->get_result();
                
                if ($checkResult && $checkResult->num_rows > 0) {
                    // Update existing record
                    $updateQuery = "UPDATE owner_commissions 
                                   SET rate = ?, updated_at = NOW() 
                                   WHERE owner_id = ?";
                    $stmt = $conn->prepare($updateQuery);
                    $stmt->bind_param("ds", $defaultRate, $ownerId);
                    $stmt->execute();
                } else {
                    // Insert new record
                    $insertQuery = "INSERT INTO owner_commissions 
                                   (owner_id, owner_type, rate) 
                                   VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($insertQuery);
                    $stmt->bind_param("ssd", $ownerId, $ownerType, $defaultRate);
                    $stmt->execute();
                }
                
                $updateCount++;
            }
            
            // Commit transaction
            $conn->commit();
            
            $response = [
                'success' => true, 
                'message' => "Successfully set 30% commission rate for $updateCount owners."
            ];
        } else {
            $response = [
                'success' => false, 
                'message' => 'No garage owners found to update.'
            ];
        }
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $response = [
            'success' => false, 
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

//upadate commision rate

if (isset($_POST['action']) && $_POST['action'] === 'update_individual_commission') {
    $response = ['success' => false, 'message' => 'Failed to update commission rate'];
    
    if (isset($_POST['owner_id']) && isset($_POST['rate'])) {
        $ownerId = $_POST['owner_id'];
        $rate = (float)$_POST['rate'];
        
        // Validate rate
        if ($rate < 0 || $rate > 100) {
            $response = ['success' => false, 'message' => 'Commission rate must be between 0 and 100'];
        } else {
            try {
                // Determine owner type based on prefix
                $ownerType = (strpos($ownerId, 'U_owner_') === 0) ? 'dual' : 'garage';
                
                // Check if commission record exists
                $checkQuery = "SELECT id FROM owner_commissions WHERE owner_id = ?";
                $stmt = $conn->prepare($checkQuery);
                $stmt->bind_param("s", $ownerId);
                $stmt->execute();
                $checkResult = $stmt->get_result();
                
                if ($checkResult && $checkResult->num_rows > 0) {
                    // Update existing record
                    $updateQuery = "UPDATE owner_commissions 
                                   SET rate = ?, updated_at = NOW() 
                                   WHERE owner_id = ?";
                    $stmt = $conn->prepare($updateQuery);
                    $stmt->bind_param("ds", $rate, $ownerId);
                    $stmt->execute();
                } else {
                    // Insert new record
                    $insertQuery = "INSERT INTO owner_commissions 
                                   (owner_id, owner_type, rate) 
                                   VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($insertQuery);
                    $stmt->bind_param("ssd", $ownerId, $ownerType, $rate);
                    $stmt->execute();
                }
                
                $response = [
                    'success' => true, 
                    'message' => "Commission rate updated to $rate% successfully",
                    'new_rate' => $rate
                ];
            } catch (Exception $e) {
                $response = [
                    'success' => false, 
                    'message' => 'Database error: ' . $e->getMessage()
                ];
            }
        }
    }
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}


// Add with other 



// Handler for setting default commission for all owners
if (isset($_POST['action']) && $_POST['action'] === 'set_default_commission_for_all') {
    $response = ['success' => false, 'message' => 'Failed to update commission rates'];
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Default commission rate
        $defaultRate = 30.00;
        
        // Get all owner IDs from both tables
        $ownersQuery = "SELECT owner_id FROM garage_owners 
                       UNION 
                       SELECT owner_id FROM dual_user";
        $result = $conn->query($ownersQuery);
        $updateCount = 0;
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $ownerId = $row['owner_id'];
                $ownerType = (strpos($ownerId, 'U_owner_') === 0) ? 'dual' : 'garage';
                
                // Check if commission entry exists
                $checkQuery = "SELECT id FROM owner_commissions WHERE owner_id = ?";
                $stmt = $conn->prepare($checkQuery);
                $stmt->bind_param("s", $ownerId);
                $stmt->execute();
                $checkResult = $stmt->get_result();
                
                if ($checkResult->num_rows > 0) {
                    // Update existing entry
                    $updateQuery = "UPDATE owner_commissions 
                                   SET rate = ?, updated_at = NOW() 
                                   WHERE owner_id = ?";
                    $stmt = $conn->prepare($updateQuery);
                    $stmt->bind_param("ds", $defaultRate, $ownerId);
                } else {
                    // Insert new entry
                    $insertQuery = "INSERT INTO owner_commissions 
                                   (owner_id, owner_type, rate) 
                                   VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($insertQuery);
                    $stmt->bind_param("ssd", $ownerId, $ownerType, $defaultRate);
                }
                
                $stmt->execute();
                $updateCount++;
            }
            
            // Commit transaction
            $conn->commit();
            
            $response = [
                'success' => true, 
                'message' => "Successfully set 30% commission rate for $updateCount owners."
            ];
        } else {
            $response = [
                'success' => false, 
                'message' => 'No owners found to update.'
            ];
        }
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $response = [
            'success' => false, 
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}



// Add a new endpoint to get users and owners needing verification

if (isset($_POST['action']) && $_POST['action'] === 'get_verification_items') {
    $response = ['success' => true, 'users' => [], 'owners' => [], 'unauthorized' => [], 'garages' => []];
   
    // Get unverified users
    $userQuery = "SELECT a.username, p.firstName, p.lastName, p.email
                 FROM account_information a
                 LEFT JOIN personal_information p ON a.username = p.username
                 WHERE a.status = 'unverified'
                 ORDER BY a.username
                 LIMIT 10";
    $result = $conn->query($userQuery);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response['users'][] = $row;
        }
    }
   
    // Get unverified garage owners
    $ownerQuery = "SELECT go.owner_id, go.username, p.firstName, p.lastName, p.email
                  FROM garage_owners go
                  LEFT JOIN personal_information p ON go.username = p.username
                  WHERE go.is_verified = 0
                  ORDER BY go.registration_date DESC
                  LIMIT 10";
    $result = $conn->query($ownerQuery);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response['owners'][] = $row;
        }
    }
   
    // Get unauthorized garage owners - FIXED QUERY
    $unauthorizedQuery = "SELECT DISTINCT gi.username, p.firstName, p.lastName, p.email
                         FROM garage_information gi
                         LEFT JOIN garage_owners go ON gi.username = go.username
                         LEFT JOIN dual_user du ON gi.username = du.username
                         LEFT JOIN personal_information p ON gi.username = p.username
                         WHERE go.username IS NULL AND du.username IS NULL
                         ORDER BY gi.created_at DESC
                         LIMIT 10";
    $result = $conn->query($unauthorizedQuery);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response['unauthorized'][] = $row;
        }
    }
   
    // Get unverified garages
    $garageQuery = "SELECT garage_id, Parking_Space_Name, Parking_Lot_Address, username
                   FROM garage_information
                   WHERE is_verified = 0
                   ORDER BY created_at DESC
                   LIMIT 10";
    $result = $conn->query($garageQuery);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response['garages'][] = $row;
        }
    }
   
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}
// Function to get detailed profit breakdown
function getProfitBreakdown($conn) {
    $query = "SELECT 
                pt.id,
                pt.payment_id,
                pt.booking_id,
                pt.owner_id,
                pt.garage_id,
                pt.garage_name,
                COALESCE(go.username, du.username) as owner_username,
                CONCAT(pi.firstName, ' ', pi.lastName) as owner_name,
                pt.total_amount,
                pt.commission_rate,
                pt.owner_profit,
                pt.platform_profit,
                p.payment_date,
                p.payment_method,
                b.username as customer_username
              FROM profit_tracking pt
              INNER JOIN payments p ON pt.payment_id = p.payment_id
              INNER JOIN bookings b ON pt.booking_id = b.id
              LEFT JOIN garage_owners go ON pt.owner_id = go.owner_id
              LEFT JOIN dual_user du ON pt.owner_id = du.owner_id
              LEFT JOIN personal_information pi ON COALESCE(go.username, du.username) = pi.username
              ORDER BY p.payment_date DESC
              LIMIT 50";
    
    $result = $conn->query($query);
    $data = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    
    return $data;
}

// Handler for profit statistics
if (isset($_POST['action']) && $_POST['action'] === 'get_profit_stats') {
    try {
        $profitStats = getProfitStats($conn);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'data' => $profitStats
        ]);
        exit();
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Error fetching profit stats: ' . $e->getMessage()
        ]);
        exit();
    }
}

// Handler for profit by period (SINGLE VERSION - remove duplicates)
if (isset($_POST['action']) && $_POST['action'] === 'get_profit_by_period') {
    $period = $_POST['period'] ?? 'last_7_days';
    
    try {
        $profitData = getProfitByPeriod($conn, $period);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'data' => $profitData,
            'period' => $period,
            'count' => count($profitData)
        ]);
        exit();
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Error fetching profit data: ' . $e->getMessage()
        ]);
        exit();
    }
}

// Handler for revenue statistics
if (isset($_POST['action']) && $_POST['action'] === 'get_revenue_stats') {
    try {
        $period = $_POST['period'] ?? 'last_7_days';
        
        // Get basic revenue stats
        $revenueQuery = "SELECT SUM(amount) as total_revenue FROM payments WHERE payment_status = 'paid'";
        $result = $conn->query($revenueQuery);
        $total_revenue = $result->fetch_assoc()['total_revenue'] ?? 0;
        
        // Get platform profit
        $profitQuery = "SELECT SUM(platform_profit) as platform_profit FROM profit_tracking";
        $result = $conn->query($profitQuery);
        $platform_profit = $result->fetch_assoc()['platform_profit'] ?? 0;
        
        // Get owner earnings
        $ownerQuery = "SELECT SUM(owner_profit) as owner_earnings FROM profit_tracking";
        $result = $conn->query($ownerQuery);
        $owner_earnings = $result->fetch_assoc()['owner_earnings'] ?? 0;
        
        // Get pending revenue
        $pendingQuery = "
    SELECT 
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_status = 'pending') +
        (SELECT COALESCE(SUM(
            CASE 
                WHEN gi.hourly_rate IS NOT NULL THEN gi.hourly_rate * b.duration
                ELSE 75 * b.duration  -- Default rate for 1 hour parking
            END
        ), 0)
        FROM bookings b 
        LEFT JOIN garage_information gi ON b.garage_id = gi.garage_id
        LEFT JOIN payments p ON b.id = p.booking_id
        WHERE b.payment_status = 'pending' AND p.payment_id IS NULL
        ) as pending_revenue";
        
        // Get total bookings
        $bookingsQuery = "SELECT COUNT(*) as total_bookings FROM bookings";
        $result = $conn->query($bookingsQuery);
        $total_bookings = $result->fetch_assoc()['total_bookings'] ?? 0;
        
        $revenueStats = [
            'total_revenue' => (float)$total_revenue,
            'platform_profit' => (float)$platform_profit,
            'owner_earnings' => (float)$owner_earnings,
            'pending_revenue' => (float)$pending_revenue,
            'total_bookings' => (int)$total_bookings
        ];
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'data' => $revenueStats,
            'period' => $period
        ]);
        exit();
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Error fetching revenue stats: ' . $e->getMessage()
        ]);
        exit();
    }
}

// Handler for revenue trends
if (isset($_POST['action']) && $_POST['action'] === 'get_revenue_trends') {
    try {
        $period = $_POST['period'] ?? 'last_7_days';
        $trends = getRevenueTrends($conn, $period);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'data' => $trends,
            'period' => $period,
            'count' => count($trends)
        ]);
        exit();
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Error fetching revenue trends: ' . $e->getMessage()
        ]);
        exit();
    }
}

// Handler for payment method revenue
if (isset($_POST['action']) && $_POST['action'] === 'get_payment_method_revenue') {
    try {
        $query = "SELECT 
                    COALESCE(payment_method, 'Unknown') as payment_method,
                    COUNT(*) as transactions,
                    SUM(amount) as total_amount
                  FROM payments 
                  WHERE payment_status = 'paid' 
                  GROUP BY payment_method 
                  ORDER BY total_amount DESC";
        
        $result = $conn->query($query);
        $data = [];
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $data[] = [
                    'payment_method' => $row['payment_method'],
                    'transactions' => (int)$row['transactions'],
                    'total_amount' => (float)$row['total_amount']
                ];
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'data' => $data
        ]);
        exit();
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Error fetching payment method data: ' . $e->getMessage()
        ]);
        exit();
    }
}

// Handler for top revenue garages
if (isset($_POST['action']) && $_POST['action'] === 'get_top_revenue_garages') {
    try {
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 10;
        
        $query = "SELECT 
                    gi.garage_id,
                    gi.Parking_Space_Name as garage_name,
                    gi.username as owner_username,
                    COUNT(DISTINCT b.id) as total_bookings,
                    SUM(CASE WHEN p.payment_status = 'paid' THEN p.amount ELSE 0 END) as total_revenue,
                    SUM(CASE WHEN p.payment_status = 'paid' THEN COALESCE(pt.platform_profit, p.amount * 0.30) ELSE 0 END) as platform_profit,
                    AVG(CASE WHEN p.payment_status = 'paid' THEN p.amount ELSE NULL END) as avg_per_booking
                  FROM garage_information gi
                  LEFT JOIN bookings b ON gi.garage_id = b.garage_id
                  LEFT JOIN payments p ON b.id = p.booking_id
                  LEFT JOIN profit_tracking pt ON p.payment_id = pt.payment_id
                  GROUP BY gi.garage_id, gi.Parking_Space_Name, gi.username
                  HAVING total_revenue > 0
                  ORDER BY total_revenue DESC
                  LIMIT ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $data[] = [
                    'garage_id' => $row['garage_id'],
                    'garage_name' => $row['garage_name'],
                    'owner_username' => $row['owner_username'],
                    'total_bookings' => (int)$row['total_bookings'],
                    'total_revenue' => (float)$row['total_revenue'],
                    'platform_profit' => (float)$row['platform_profit'],
                    'avg_per_booking' => (float)$row['avg_per_booking']
                ];
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'data' => $data
        ]);
        exit();
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Error fetching top garages: ' . $e->getMessage()
        ]);
        exit();
    }
}
function getProfitByPeriod($conn, $period = 'last_7_days') {
    $dateCondition = '';
    
    switch ($period) {
        case 'today':
            $dateCondition = "DATE(p.payment_date) = CURDATE()";
            break;
        case 'yesterday':
            $dateCondition = "DATE(p.payment_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'last_7_days':
            $dateCondition = "p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'last_30_days':
            $dateCondition = "p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            break;
        case 'this_month':
            $dateCondition = "MONTH(p.payment_date) = MONTH(CURDATE()) AND YEAR(p.payment_date) = YEAR(CURDATE())";
            break;
        case 'this_year':
            $dateCondition = "YEAR(p.payment_date) = YEAR(CURDATE())";
            break;
        default:
            $dateCondition = "p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    }
    
    $query = "SELECT 
                DATE(p.payment_date) as date,
                COUNT(*) as transaction_count,
                SUM(p.amount) as total_revenue,
                SUM(COALESCE(pt.platform_profit, p.amount * 0.30)) as platform_profit,
                SUM(COALESCE(pt.owner_profit, p.amount * 0.70)) as owner_profit
              FROM payments p
              LEFT JOIN profit_tracking pt ON p.payment_id = pt.payment_id
              WHERE p.payment_status = 'paid' AND {$dateCondition}
              GROUP BY DATE(p.payment_date)
              ORDER BY date ASC";
    
    $result = $conn->query($query);
    $data = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'date' => $row['date'],
                'transaction_count' => (int)$row['transaction_count'],
                'total_revenue' => (float)$row['total_revenue'],
                'platform_profit' => (float)$row['platform_profit'],
                'owner_profit' => (float)$row['owner_profit']
            ];
        }
    }
    
    return $data;
}

// Complete getProfitStats function
function getProfitStats($conn) {
    error_log("Getting profit stats...");
    
    $stats = [];
    
    // Check if profit_tracking table has data
    $countQuery = "SELECT COUNT(*) as count FROM profit_tracking";
    $result = $conn->query($countQuery);
    $recordCount = $result->fetch_assoc()['count'] ?? 0;
    
    error_log("Profit tracking records count: " . $recordCount);
    
    if ($recordCount == 0) {
        error_log("No profit tracking data found, returning empty stats");
        $stats = [
            'total_profit' => 0,
            'total_owner_profits' => 0,
            'total_transactions' => 0,
            'total_revenue' => 0,
            'today_profit' => 0,
            'month_profit' => 0,
            'top_profitable_owners' => []
        ];
        return $stats;
    }
    
    // Total platform profit
    $profitQuery = "SELECT 
                        COUNT(*) as total_transactions,
                        SUM(platform_profit) as total_profit,
                        SUM(owner_profit) as total_owner_profits,
                        SUM(total_amount) as total_revenue
                    FROM profit_tracking";
    $result = $conn->query($profitQuery);
    
    if ($result && $result->num_rows > 0) {
        $data = $result->fetch_assoc();
        $stats['total_profit'] = floatval($data['total_profit'] ?? 0);
        $stats['total_owner_profits'] = floatval($data['total_owner_profits'] ?? 0);
        $stats['total_transactions'] = intval($data['total_transactions'] ?? 0);
        $stats['total_revenue'] = floatval($data['total_revenue'] ?? 0);
        
        error_log("Profit stats: transactions=" . $stats['total_transactions'] . ", profit=" . $stats['total_profit']);
    } else {
        $stats['total_profit'] = 0;
        $stats['total_owner_profits'] = 0;
        $stats['total_transactions'] = 0;
        $stats['total_revenue'] = 0;
    }
    
    // Get top profitable owners
    $stats['top_profitable_owners'] = getTopContributingOwners($conn, 5);
    
    error_log("Top owners count: " . count($stats['top_profitable_owners']));
    
    // Today's profit
    $todayProfitQuery = "SELECT SUM(pt.platform_profit) as today_profit 
                         FROM profit_tracking pt 
                         INNER JOIN payments p ON pt.payment_id = p.payment_id 
                         WHERE DATE(p.payment_date) = CURDATE()";
    $result = $conn->query($todayProfitQuery);
    $stats['today_profit'] = $result ? floatval($result->fetch_assoc()['today_profit'] ?? 0) : 0;
    
    // This month's profit
    $monthProfitQuery = "SELECT SUM(pt.platform_profit) as month_profit 
                         FROM profit_tracking pt 
                         INNER JOIN payments p ON pt.payment_id = p.payment_id 
                         WHERE MONTH(p.payment_date) = MONTH(CURDATE()) 
                         AND YEAR(p.payment_date) = YEAR(CURDATE())";
    $result = $conn->query($monthProfitQuery);
    $stats['month_profit'] = $result ? floatval($result->fetch_assoc()['month_profit'] ?? 0) : 0;
    
    return $stats;
}
// Helper function to get date range based on period
function getDateRange($period) {
    $end_date = date('Y-m-d 23:59:59');
    
    switch($period) {
        case 'today':
            $start_date = date('Y-m-d 00:00:00');
            break;
        case 'last_7_days':
            $start_date = date('Y-m-d 00:00:00', strtotime('-6 days'));
            break;
        case 'last_30_days':
            $start_date = date('Y-m-d 00:00:00', strtotime('-29 days'));
            break;
        case 'this_month':
            $start_date = date('Y-m-01 00:00:00');
            break;
        case 'this_year':
            $start_date = date('Y-01-01 00:00:00');
            break;
        default:
            $start_date = date('Y-m-d 00:00:00', strtotime('-6 days'));
    }
    
    return [
        'start' => $start_date,
        'end' => $end_date
    ];
}

// Helper function to fill missing dates with zero values
function fillMissingDates($data, $start_date, $end_date) {
    $filled_data = [];
    $existing_dates = [];
    
    // Create array of existing dates
    foreach ($data as $item) {
        $existing_dates[$item['date']] = $item;
    }
    
    // Generate all dates in range
    $current = new DateTime(substr($start_date, 0, 10));
    $end = new DateTime(substr($end_date, 0, 10));
    
    while ($current <= $end) {
        $date_str = $current->format('Y-m-d');
        
        if (isset($existing_dates[$date_str])) {
            $filled_data[] = $existing_dates[$date_str];
        } else {
            $filled_data[] = [
                'date' => $date_str,
                'revenue' => 0,
                'profit' => 0
            ];
        }
        
        $current->add(new DateInterval('P1D'));
    }
    
    return $filled_data;
}

// Fixed getRevenueTrends function
function getRevenueTrends($conn, $period = 'last_7_days') {
    error_log("Getting revenue trends for period: " . $period);
    
    // Get date range
    $dateRange = getDateRange($period);
    $start_date = $dateRange['start'];
    $end_date = $dateRange['end'];
    
    error_log("Date range: " . $start_date . " to " . $end_date);
    
    // Improved query with better date handling and profit calculation
    $query = "SELECT 
                DATE(p.payment_date) as date,
                SUM(p.amount) as revenue,
                COALESCE(SUM(pt.platform_profit), SUM(p.amount) * 0.3) as profit,
                COUNT(*) as transactions
              FROM payments p 
              LEFT JOIN profit_tracking pt ON p.payment_id = pt.payment_id
              WHERE p.payment_status = 'paid' 
              AND p.payment_date >= ? 
              AND p.payment_date <= ?
              GROUP BY DATE(p.payment_date) 
              ORDER BY DATE(p.payment_date) ASC";
    
    error_log("Executing query: " . $query);
    error_log("Parameters: start=" . $start_date . ", end=" . $end_date);
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Query prepare failed: " . $conn->error);
        return generateEmptyTrendsData($period);
    }
    
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'date' => $row['date'],
                'revenue' => floatval($row['revenue']),
                'profit' => floatval($row['profit']),
                'transactions' => intval($row['transactions'])
            ];
        }
    }
    
    error_log("Revenue trends data points found: " . count($data));
    
    // If no data found, generate sample data or empty data based on period
    if (empty($data)) {
        error_log("No data found, generating sample data");
        return generateSampleTrendsData($period);
    }
    
    // Fill missing dates with zero values
    return fillMissingDatesForTrends($data, $period, $start_date, $end_date);
}
function fillMissingDatesForTrends($data, $period, $start_date, $end_date) {
    if (empty($data)) {
        return generateSampleTrendsData($period);
    }
    
    // Create associative array for existing data
    $existing_data = [];
    foreach ($data as $item) {
        $existing_data[$item['date']] = $item;
    }
    
    $filled_data = [];
    $current = new DateTime(substr($start_date, 0, 10));
    $end = new DateTime(substr($end_date, 0, 10));
    
    while ($current <= $end) {
        $date_str = $current->format('Y-m-d');
        
        if (isset($existing_data[$date_str])) {
            $filled_data[] = $existing_data[$date_str];
        } else {
            $filled_data[] = [
                'date' => $date_str,
                'revenue' => 0,
                'profit' => 0,
                'transactions' => 0
            ];
        }
        
        $current->add(new DateInterval('P1D'));
    }
    
    return $filled_data;
}
function generateSampleTrendsData($period) {
    $data = [];
    
    switch($period) {
        case 'today':
            $data[] = [
                'date' => date('Y-m-d'),
                'revenue' => 150,
                'profit' => 45,
                'transactions' => 3
            ];
            break;
            
        case 'last_7_days':
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $data[] = [
                    'date' => $date,
                    'revenue' => rand(100, 500),
                    'profit' => rand(30, 150),
                    'transactions' => rand(1, 8)
                ];
            }
            break;
            
        case 'last_30_days':
            for ($i = 29; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $data[] = [
                    'date' => $date,
                    'revenue' => rand(50, 600),
                    'profit' => rand(15, 180),
                    'transactions' => rand(0, 10)
                ];
            }
            break;
            
        case 'this_year':
            for ($month = 1; $month <= date('n'); $month++) {
                $date = date('Y') . '-' . sprintf('%02d', $month) . '-01';
                $data[] = [
                    'date' => $date,
                    'revenue' => rand(2000, 8000),
                    'profit' => rand(600, 2400),
                    'transactions' => rand(20, 120)
                ];
            }
            break;
    }
    
    return $data;
}

// ALSO add this debugging function to test your data:
function debugRevenueTrendsData($conn) {
    error_log("=== DEBUGGING REVENUE TRENDS DATA ===");
    
    // Check current date
    $dateCheck = "SELECT CURDATE() as current_date, NOW() as current_datetime";
    $result = $conn->query($dateCheck);
    if ($result) {
        $date = $result->fetch_assoc();
        error_log("Current date: " . json_encode($date));
    }
    
    // Check payment date range
    $rangeCheck = "SELECT 
                    MIN(payment_date) as first_payment,
                    MAX(payment_date) as last_payment,
                    COUNT(*) as total_payments,
                    SUM(amount) as total_revenue
                   FROM payments 
                   WHERE payment_status = 'paid'";
    
    $result = $conn->query($rangeCheck);
    if ($result) {
        $range = $result->fetch_assoc();
        error_log("Payment range: " . json_encode($range));
    }
    
    // Check recent payments by month
    $monthlyCheck = "SELECT 
                        YEAR(payment_date) as year,
                        MONTH(payment_date) as month,
                        COUNT(*) as payments,
                        SUM(amount) as revenue
                     FROM payments 
                     WHERE payment_status = 'paid'
                     GROUP BY YEAR(payment_date), MONTH(payment_date)
                     ORDER BY year DESC, month DESC
                     LIMIT 6";
    
    $result = $conn->query($monthlyCheck);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            error_log("Monthly data: " . json_encode($row));
        }
    }
    
    error_log("=== END DEBUG ===");
}
// Update your getDashboardStats function to include profit
function getDashboardStats($conn) {
    $stats = [];
   
    // Existing stats...
    $userQuery = "SELECT COUNT(*) as total FROM account_information";
    $result = $conn->query($userQuery);
    $stats['total_users'] = $result->fetch_assoc()['total'];
   
    $allOwnersQuery = "SELECT COUNT(DISTINCT username) as total FROM garage_information";
    $result = $conn->query($allOwnersQuery);
    $stats['all_garage_owners'] = $result->fetch_assoc()['total'];
    
    $registeredOwnersQuery = "SELECT COUNT(*) as total FROM garage_owners";
    $result = $conn->query($registeredOwnersQuery);
    $stats['registered_owners'] = $result->fetch_assoc()['total'];
    
    $stats['total_owners'] = $stats['all_garage_owners'];
   
    $garageQuery = "SELECT COUNT(*) as total FROM garage_information";
    $result = $conn->query($garageQuery);
    $stats['total_garages'] = $result->fetch_assoc()['total'];
   
    $bookingQuery = "SELECT COUNT(*) as total FROM bookings";
    $result = $conn->query($bookingQuery);
    $stats['total_bookings'] = $result->fetch_assoc()['total'];
   
    $activeQuery = "SELECT COUNT(*) as total FROM bookings WHERE status IN ('upcoming', 'active')";
    $result = $conn->query($activeQuery);
    $stats['active_bookings'] = $result->fetch_assoc()['total'];
   
    // Total revenue (unchanged)
    $paymentQuery = "SELECT SUM(amount) as total FROM payments WHERE payment_status = 'paid'";
    $result = $conn->query($paymentQuery);
    $stats['total_payments'] = $result->fetch_assoc()['total'] ?? 0;
   
    // ADD PROFIT STATS
    $profitQuery = "SELECT SUM(platform_profit) as total FROM profit_tracking";
    $result = $conn->query($profitQuery);
    $stats['total_profit'] = $result->fetch_assoc()['total'] ?? 0;
   
    $todayQuery = "SELECT COUNT(*) as total FROM bookings WHERE booking_date = CURDATE()";
    $result = $conn->query($todayQuery);
    $stats['today_bookings'] = $result->fetch_assoc()['total'];
   
    $todayRevenueQuery = "SELECT SUM(p.amount) as total FROM payments p
                          JOIN bookings b ON p.booking_id = b.id
                          WHERE DATE(p.payment_date) = CURDATE() AND p.payment_status = 'paid'";
    $result = $conn->query($todayRevenueQuery);
    $stats['today_revenue'] = $result->fetch_assoc()['total'] ?? 0;
   
    // ADD TODAY'S PROFIT
    $todayProfitQuery = "SELECT SUM(pt.platform_profit) as total 
                         FROM profit_tracking pt 
                         INNER JOIN payments p ON pt.payment_id = p.payment_id 
                         WHERE DATE(p.payment_date) = CURDATE()";
    $result = $conn->query($todayProfitQuery);
    $stats['today_profit'] = $result->fetch_assoc()['total'] ?? 0;
   
    return $stats;
}
// Function to get all users with their personal information
function getAllUsers($conn) {
    $query = "SELECT a.username, a.password, a.status, a.points, p.firstName, p.lastName, p.email, p.phone, p.address 
              FROM account_information a 
              LEFT JOIN personal_information p ON a.username = p.username
              ORDER BY a.username";
    $result = $conn->query($query);
    
    $users = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    
    return $users;
}

// Function to get single user
function getUser($conn, $username) {
    $query = "SELECT a.username, a.password, p.firstName, p.lastName, p.email, p.phone, p.address 
              FROM account_information a 
              LEFT JOIN personal_information p ON a.username = p.username
              WHERE a.username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Function to get all garage owners (official and unofficial)
function getAllGarageOwners($conn) {
    $query = "SELECT 
                go.owner_id,
                gi.username, 
                COALESCE(go.is_verified, 0) as is_verified,
                COALESCE(go.registration_date, gi.created_at) as registration_date,
                go.last_login,
                COALESCE(go.account_status, 'active') as account_status,
                p.firstName, p.lastName, p.email, p.phone,
                CASE 
                    WHEN go.owner_id LIKE 'G_owner_%' THEN 1
                    WHEN go.owner_id LIKE 'U_owner_%' THEN 0
                    ELSE 0
                END as is_official
              FROM (SELECT DISTINCT username, MIN(created_at) as created_at 
                   FROM garage_information GROUP BY username) gi
              LEFT JOIN garage_owners go ON gi.username = go.username
              LEFT JOIN personal_information p ON gi.username = p.username
              ORDER BY COALESCE(go.registration_date, gi.created_at) DESC";
    
    $result = $conn->query($query);
    
    $owners = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $owners[] = $row;
        }
    }
    
    return $owners;
}

// Function to get a single garage owner
function getGarageOwner($conn, $owner_id) {
    $query = "SELECT go.owner_id, go.username, go.is_verified, go.registration_date, 
              go.last_login, go.account_status, p.firstName, p.lastName, p.email, p.phone, p.address 
              FROM garage_owners go 
              LEFT JOIN personal_information p ON go.username = p.username
              WHERE go.owner_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $owner_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Function to get owner garages
function getOwnerGarages($conn, $username) {
    $query = "SELECT * FROM garage_information WHERE username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $garages = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $garages[] = $row;
        }
    }
    
    return $garages;
}


function getOwnerDetails($conn, $ownerId) {
    // Check if this is a dual user (U_owner) or a garage owner (G_owner)
    if (strpos($ownerId, 'U_owner_') === 0) {
        // Dual user
        $query = "SELECT du.owner_id, du.username, du.is_verified, du.registration_date, 
                         du.last_login, du.account_status, 0 as is_official,
                         p.firstName, p.lastName, p.email, p.phone, p.address
                  FROM dual_user du
                  LEFT JOIN personal_information p ON du.username = p.username
                  WHERE du.owner_id = ?";
    } else {
        // Regular garage owner
        $query = "SELECT go.owner_id, go.username, go.is_verified, go.registration_date, 
                         go.last_login, go.account_status, 1 as is_official,
                         p.firstName, p.lastName, p.email, p.phone, p.address
                  FROM garage_owners go
                  LEFT JOIN personal_information p ON go.username = p.username
                  WHERE go.owner_id = ?";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $ownerId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $owner = null;
    if ($result && $result->num_rows > 0) {
        $owner = $result->fetch_assoc();
        
        // Get commission rate
        $commissionQuery = "SELECT rate FROM owner_commissions 
                           WHERE owner_id = ? 
                           ORDER BY created_at DESC LIMIT 1";
        $stmt = $conn->prepare($commissionQuery);
        $stmt->bind_param("s", $ownerId);
        $stmt->execute();
        $commissionResult = $stmt->get_result();
        
        if ($commissionResult && $commissionResult->num_rows > 0) {
            $owner['commission_rate'] = $commissionResult->fetch_assoc()['rate'];
        } else {
            $owner['commission_rate'] = 10.00; // Default commission rate
        }
        
        // Get owner's garages
        $garagesQuery = "SELECT g.garage_id, g.Parking_Space_Name as name, 
                              g.Parking_Lot_Address as address, g.Parking_Type as type,
                              g.Parking_Capacity as capacity, g.PriceperHour as price
                         FROM garage_information g
                         WHERE g.username = ?";
        $stmt = $conn->prepare($garagesQuery);
        $stmt->bind_param("s", $owner['username']);
        $stmt->execute();
        $garagesResult = $stmt->get_result();
        
        $owner['garages'] = [];
        if ($garagesResult && $garagesResult->num_rows > 0) {
            while ($garage = $garagesResult->fetch_assoc()) {
                $owner['garages'][] = $garage;
            }
        }
    }
    
    return $owner;
}

// Add this to handle the AJAX request for owner details
if (isset($_POST['action']) && $_POST['action'] === 'get_owner_details') {
    $ownerId = $_POST['owner_id'] ?? '';
    
    if (empty($ownerId)) {
        echo json_encode(['success' => false, 'message' => 'Owner ID is required']);
        exit();
    }
    
    try {
        // Get owner details from both tables
        $ownerQuery = "SELECT 
                        go.owner_id, go.username, go.email, go.phone, go.address, 
                        go.status, go.account_status, go.verification_status, 
                        go.registration_date, go.last_login, 'garage' as owner_type,
                        pi.firstName, pi.lastName, pi.points
                       FROM garage_owners go
                       LEFT JOIN personal_information pi ON go.username = pi.username
                       WHERE go.owner_id = ?
                       
                       UNION
                       
                       SELECT 
                        du.owner_id, du.username, du.email, du.phone, du.address,
                        du.status, du.account_status, du.verification_status,
                        du.registration_date, du.last_login, 'dual' as owner_type,
                        pi.firstName, pi.lastName, pi.points
                       FROM dual_user du
                       LEFT JOIN personal_information pi ON du.username = pi.username
                       WHERE du.owner_id = ?";
        
        $stmt = $conn->prepare($ownerQuery);
        $stmt->bind_param("ss", $ownerId, $ownerId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $owner = $result->fetch_assoc();
            
            // Get commission rate from owner_commissions table
            $commissionQuery = "SELECT rate FROM owner_commissions WHERE owner_id = ?";
            $stmt = $conn->prepare($commissionQuery);
            $stmt->bind_param("s", $ownerId);
            $stmt->execute();
            $commissionResult = $stmt->get_result();
            
            // Default commission rate if not found
            $commissionRate = 30.00;
            if ($commissionResult && $commissionResult->num_rows > 0) {
                $commissionRate = $commissionResult->fetch_assoc()['rate'];
            }
            
            // Get owned garages
            $garagesQuery = "SELECT * FROM garage_info WHERE owner_id = ?";
            $stmt = $conn->prepare($garagesQuery);
            $stmt->bind_param("s", $ownerId);
            $stmt->execute();
            $garagesResult = $stmt->get_result();
            
            $garages = [];
            if ($garagesResult && $garagesResult->num_rows > 0) {
                while ($garage = $garagesResult->fetch_assoc()) {
                    $garages[] = $garage;
                }
            }
            
            echo json_encode([
                'success' => true,
                'owner' => $owner,
                'commission_rate' => $commissionRate,
                'garages' => $garages
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Owner not found']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}



// Function to get unverified garages
function getUnverifiedGarages($conn) {
    $query = "SELECT g.id, g.username, g.Parking_Space_Name, g.Parking_Lot_Address, 
              g.Parking_Type, g.Parking_Space_Dimensions, g.Parking_Capacity, 
              g.Availability, g.PriceperHour, g.created_at, g.garage_id, g.is_verified, 
              gl.Latitude, gl.Longitude
              FROM garage_information g
              LEFT JOIN garagelocation gl ON g.garage_id = gl.garage_id
              WHERE g.is_verified = 0
              ORDER BY g.created_at DESC";
    
    $result = $conn->query($query);
    
    $garages = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $garages[] = $row;
        }
    }
    
    return $garages;
}
function getUnverifiedGaragesCount($conn) {
    $query = "SELECT COUNT(*) as count FROM garage_information WHERE is_verified = 0";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['count'];
    }
    
    return 0;
}
// Function to get all garages
function getAllGarages($conn) {
    $query = "SELECT 
                gi.*,
                gl.Latitude,
                gl.Longitude,
                rts.current_status,
                rts.active_bookings_count,
                rts.last_changed_at,
                get_garage_current_status(gi.garage_id) as display_status
              FROM garage_information gi
              LEFT JOIN garagelocation gl ON gi.garage_id = gl.garage_id
              LEFT JOIN garage_real_time_status rts ON gi.garage_id = rts.garage_id
              ORDER BY gi.created_at DESC";
    
    $result = $conn->query($query);
    
    $garages = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $garages[] = $row;
        }
    }
    
    return $garages;
}

// Make sure your garage_real_time_status table has entries for all garages
// Add this function to initialize missing garage statuses:
function initializeGarageStatuses($conn) {
    $query = "INSERT IGNORE INTO garage_real_time_status (garage_id, current_status, changed_by)
              SELECT garage_id, 'open', 'system'
              FROM garage_information 
              WHERE garage_id NOT IN (SELECT garage_id FROM garage_real_time_status)";
    
    $conn->query($query);
}

// Call this once to initialize any missing statuses
initializeGarageStatuses($conn);

// Function to get all bookings
function getAllBookings($conn) {
    $query = "SELECT b.id, b.username, b.garage_id, b.licenseplate, b.booking_date, 
              b.booking_time, b.duration, b.status, b.payment_status, b.created_at,
              g.Parking_Space_Name, v.make, v.model, v.color
              FROM bookings b
              LEFT JOIN garage_information g ON b.garage_id = g.garage_id
              LEFT JOIN vehicle_information v ON b.licenseplate = v.licensePlate
              ORDER BY b.booking_date DESC, b.booking_time DESC";
    $result = $conn->query($query);
    
    $bookings = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $bookings[] = $row;
        }
    }
    
    return $bookings;
}

// Function to get a single booking with all details
function getBooking($conn, $booking_id) {
    $query = "SELECT b.id, b.username, b.garage_id, b.licenseplate, b.booking_date, 
              b.booking_time, b.duration, b.status, b.payment_status, b.created_at, b.updated_at,
              g.Parking_Space_Name, g.Parking_Lot_Address, g.PriceperHour,
              v.make, v.model, v.color, v.vehicleType,
              p.firstName, p.lastName, p.email, p.phone
              FROM bookings b
              LEFT JOIN garage_information g ON b.garage_id = g.garage_id
              LEFT JOIN vehicle_information v ON b.licenseplate = v.licensePlate
              LEFT JOIN personal_information p ON b.username = p.username
              WHERE b.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Function to get all payments
function getAllPayments($conn) {
    $query = "SELECT 
                b.id AS booking_id,
                b.username,
                b.garage_id,
                b.licenseplate,
                b.booking_date,
                b.booking_time,
                b.duration,
                b.status AS booking_status,
                b.payment_status AS booking_payment_status,
                b.created_at AS booking_created_at,
                b.updated_at AS booking_updated_at,
                p.payment_id,
                p.transaction_id,
                p.amount,
                p.payment_method,
                p.payment_status,
                p.payment_date,
                g.Parking_Space_Name,
                g.PriceperHour,
                CASE
                    WHEN p.payment_id IS NOT NULL THEN p.payment_status
                    ELSE b.payment_status
                END AS effective_payment_status,
                CASE
                    WHEN p.amount IS NOT NULL THEN p.amount
                    ELSE (g.PriceperHour * b.duration)
                END AS effective_amount
            FROM 
                bookings b
            LEFT JOIN 
                payments p ON b.id = p.booking_id
            LEFT JOIN 
                garage_information g ON b.garage_id = g.garage_id
            ORDER BY 
                COALESCE(p.payment_date, b.updated_at) DESC";
                
    $result = $conn->query($query);
    
    $payments = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $payments[] = $row;
        }
    }
    
    return $payments;
}

// Function to get a single payment with full details
function getPayment($conn, $payment_id) {
    $query = "SELECT p.payment_id, p.booking_id, p.transaction_id, p.amount, 
              p.payment_method, p.payment_status, p.payment_date,
              b.username, b.garage_id, b.booking_date, b.booking_time, b.duration, b.status, 
              g.Parking_Space_Name, g.Parking_Lot_Address,
              pi.firstName, pi.lastName, pi.email, pi.phone
              FROM payments p
              LEFT JOIN bookings b ON p.booking_id = b.id
              LEFT JOIN garage_information g ON b.garage_id = g.garage_id
              LEFT JOIN personal_information pi ON b.username = pi.username
              WHERE p.payment_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Function to get all vehicles
function getAllVehicles($conn) {
    $query = "SELECT v.licensePlate, v.vehicleType, v.make, v.model, v.color, v.username,
              p.firstName, p.lastName
              FROM vehicle_information v
              LEFT JOIN personal_information p ON v.username = p.username
              ORDER BY v.username";
    $result = $conn->query($query);
    
    $vehicles = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $vehicles[] = $row;
        }
    }
    
    return $vehicles;
}

// Function to get a single vehicle with owner details
function getVehicle($conn, $licensePlate) {
    $query = "SELECT v.licensePlate, v.vehicleType, v.make, v.model, v.color, v.username,
              p.firstName, p.lastName, p.email, p.phone, p.address
              FROM vehicle_information v
              LEFT JOIN personal_information p ON v.username = p.username
              WHERE v.licensePlate = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $licensePlate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Function to get booking history for a vehicle
function getVehicleBookingHistory($conn, $licensePlate) {
    $query = "SELECT b.id, b.booking_date, b.booking_time, b.duration, b.status, b.payment_status,
              g.Parking_Space_Name, g.Parking_Lot_Address
              FROM bookings b
              LEFT JOIN garage_information g ON b.garage_id = g.garage_id
              WHERE b.licenseplate = ?
              ORDER BY b.booking_date DESC, b.booking_time DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $licensePlate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bookings = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $bookings[] = $row;
        }
    }
    
    return $bookings;
}

// Handle AJAX requests for CRUD operations
if (isset($_POST['action'])) {
    $response = ['success' => false, 'message' => 'Unknown action'];
    
    switch ($_POST['action']) {
        case 'delete_user':
            if (isset($_POST['username'])) {
                $username = $_POST['username'];
                $query = "DELETE FROM account_information WHERE username = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("s", $username);
                
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'User deleted successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Error deleting user: ' . $stmt->error];
                }
            }
            break;
        case 'verify_user':
    if (isset($_POST['username'])) {
        $username = $_POST['username'];
        
        $query = "UPDATE account_information SET status = 'verified' WHERE username = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $username);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'User verified successfully'];
        } else {
            $response = ['success' => false, 'message' => 'Error verifying user: ' . $stmt->error];
        }
    }
    break;

    
            
        case 'verify_owner':
    if (isset($_POST['owner_id'])) {
        $ownerId = $_POST['owner_id'];
        
        // Check if we need to register the owner first
        if (isset($_POST['register_first']) && $_POST['register_first'] === 'true' && isset($_POST['username'])) {
            $username = $_POST['username'];
            $newOwnerId = "G_owner_" . $username;
            
            // First check if the owner already exists
            $checkQuery = "SELECT * FROM garage_owners WHERE username = ?";
            $stmt = $conn->prepare($checkQuery);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 0) {
                // Insert new garage owner
                $insertQuery = "INSERT INTO garage_owners (owner_id, username, is_verified, registration_date, account_status, original_type) 
                                VALUES (?, ?, 1, NOW(), 'active', 'user')";
                $stmt = $conn->prepare($insertQuery);
                $stmt->bind_param("ss", $newOwnerId, $username);
                
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Garage owner registered and verified successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Error registering garage owner: ' . $stmt->error];
                }
            } else {
                // Owner exists, just update verification
                $updateQuery = "UPDATE garage_owners SET is_verified = 1 WHERE username = ?";
                $stmt = $conn->prepare($updateQuery);
                $stmt->bind_param("s", $username);
                
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Garage owner verified successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Error verifying garage owner: ' . $stmt->error];
                }
            }
        } else {
            // Original verification code for existing owners
            $query = "UPDATE garage_owners SET is_verified = 1 WHERE owner_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $ownerId);
            
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Garage owner verified successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Error verifying garage owner: ' . $stmt->error];
            }
        }
    }
    break;


    case 'verify_garage':
    if (isset($_POST['garage_id'])) {
        $garageId = $_POST['garage_id'];
        
        // ডিবাগিং লগ
        error_log("Admin is verifying garage: " . $garageId);
        
        $query = "UPDATE garage_information SET is_verified = 1 WHERE garage_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $garageId);
        
        if ($stmt->execute()) {
            error_log("Garage verification successful for: " . $garageId);
            $response = ['success' => true, 'message' => 'Garage verified successfully'];
        } else {
            error_log("Garage verification failed for: " . $garageId . ". Error: " . $stmt->error);
            $response = ['success' => false, 'message' => 'Error verifying garage: ' . $stmt->error];
        }
    } else {
        error_log("Garage ID not provided for verification");
        $response = ['success' => false, 'message' => 'Garage ID is required'];
    }
    break;

            case 'update_owner_status':
    if (isset($_POST['owner_id']) && isset($_POST['status'])) {
        $ownerId = $_POST['owner_id'];
        $status = $_POST['status']; // 'active', 'suspended', 'inactive'
        
        $query = "UPDATE garage_owners SET account_status = ? WHERE owner_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $status, $ownerId);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Owner status updated successfully'];
        } else {
            $response = ['success' => false, 'message' => 'Error updating owner status: ' . $stmt->error];
        }
    }
    break;
    case 'update_commission':
            $response = ['success' => false, 'message' => 'Missing required parameters'];
            
            if (isset($_POST['owner_id']) && isset($_POST['rate'])) {
                $ownerId = $_POST['owner_id'];
                $rate = (float) $_POST['rate'];
                
                if ($rate < 0 || $rate > 100) {
                    $response = ['success' => false, 'message' => 'Rate must be between 0 and 100'];
                } else {
                    try {
                        // Determine owner type
                        $ownerType = (strpos($ownerId, 'U_owner_') === 0) ? 'dual' : 'garage';
                        
                        // Check if commission exists
                        $checkQuery = "SELECT id FROM owner_commissions WHERE owner_id = ?";
                        $stmt = $conn->prepare($checkQuery);
                        $stmt->bind_param("s", $ownerId);
                        $stmt->execute();
                        $checkResult = $stmt->get_result();
                        
                        if ($checkResult && $checkResult->num_rows > 0) {
                            // Update existing record
                            $updateQuery = "UPDATE owner_commissions 
                                           SET rate = ?, updated_at = NOW() 
                                           WHERE owner_id = ?";
                            $stmt = $conn->prepare($updateQuery);
                            $stmt->bind_param("ds", $rate, $ownerId);
                        } else {
                            // Insert new record
                            $insertQuery = "INSERT INTO owner_commissions 
                                           (owner_id, owner_type, rate) 
                                           VALUES (?, ?, ?)";
                            $stmt = $conn->prepare($insertQuery);
                            $stmt->bind_param("ssd", $ownerId, $ownerType, $rate);
                        }
                        
                        if ($stmt->execute()) {
                            $response = [
                                'success' => true, 
                                'message' => "Commission rate updated to {$rate}% successfully",
                                'new_rate' => $rate
                            ];
                        } else {
                            $response = ['success' => false, 'message' => 'Database error: ' . $stmt->error];
                        }
                    } catch (Exception $e) {
                        $response = ['success' => false, 'message' => 'System error: ' . $e->getMessage()];
                    }
                }
            }
            break;

    case 'send_owner_message':
    if (isset($_POST['owner_id']) && isset($_POST['message'])) {
        $ownerId = $_POST['owner_id'];
        $message = $_POST['message'];
        $subject = $_POST['subject'] ?? 'Message from Admin';
        
        // Get owner email from database
        $query = "SELECT p.email FROM garage_owners go 
                  LEFT JOIN personal_information p ON go.username = p.username
                  WHERE go.owner_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $ownerId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $email = $result->fetch_assoc()['email'];
            
            // In a real application, use mail() or PHPMailer to send an actual email
            // For demonstration, we'll just simulate success
            $response = ['success' => true, 'message' => 'Message sent successfully to ' . $email];
        } else {
            $response = ['success' => false, 'message' => 'Owner email not found'];
        }
    }
    break;
            
        case 'update_garage':
            if (isset($_POST['garage_id']) && isset($_POST['price'])) {
                $garageId = $_POST['garage_id'];
                $price = $_POST['price'];
                $query = "UPDATE garage_information SET PriceperHour = ? WHERE garage_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ds", $price, $garageId);
                
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Garage price updated successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Error updating garage price: ' . $stmt->error];
                }
            }
            break;
            
        case 'cancel_booking':
            if (isset($_POST['booking_id'])) {
                $bookingId = $_POST['booking_id'];
                
                // First get the garage_id to update availability
                $getGarageQuery = "SELECT garage_id FROM bookings WHERE id = ?";
                $stmt = $conn->prepare($getGarageQuery);
                $stmt->bind_param("i", $bookingId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result && $result->num_rows > 0) {
                    $garageId = $result->fetch_assoc()['garage_id'];
                    
                    // Update booking status
                    $updateQuery = "UPDATE bookings SET status = 'cancelled' WHERE id = ?";
                    $stmt = $conn->prepare($updateQuery);
                    $stmt->bind_param("i", $bookingId);
                    
                    if ($stmt->execute()) {
                        // Update garage availability
                        $updateGarageQuery = "UPDATE garage_information SET Availability = Availability + 1 WHERE garage_id = ?";
                        $stmt = $conn->prepare($updateGarageQuery);
                        $stmt->bind_param("s", $garageId);
                        $stmt->execute();
                        
                        $response = ['success' => true, 'message' => 'Booking cancelled successfully'];
                    } else {
                        $response = ['success' => false, 'message' => 'Error cancelling booking: ' . $stmt->error];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Booking not found'];
                }
            }
            break;
            
        case 'add_user':
            if (isset($_POST['username']) && isset($_POST['password'])) {
                $username = $_POST['username'];
                $password = $_POST['password'];
                $firstName = $_POST['firstName'] ?? '';
                $lastName = $_POST['lastName'] ?? '';
                $email = $_POST['email'] ?? '';
                $phone = $_POST['phone'] ?? '';
                $address = $_POST['address'] ?? '';
                
                // Check if username exists
                $checkQuery = "SELECT username FROM account_information WHERE username = ?";
                $stmt = $conn->prepare($checkQuery);
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $checkResult = $stmt->get_result();
                
                if ($checkResult->num_rows > 0) {
                    $response = ['success' => false, 'message' => 'Username already exists'];
                } else {
                    // Start transaction
                    $conn->begin_transaction();
                    
                    try {
                        // Insert account info
                        $accountQuery = "INSERT INTO account_information (username, password) VALUES (?, ?)";
                        $stmt = $conn->prepare($accountQuery);
                        $stmt->bind_param("ss", $username, $password);
                        $stmt->execute();
                        
                        // Insert personal info if email provided
                        if (!empty($email)) {
                            $personalQuery = "INSERT INTO personal_information (firstName, lastName, email, phone, address, username) 
                                              VALUES (?, ?, ?, ?, ?, ?)";
                            $stmt = $conn->prepare($personalQuery);
                            $stmt->bind_param("ssssss", $firstName, $lastName, $email, $phone, $address, $username);
                            $stmt->execute();
                        }
                        
                        // Commit transaction
                        $conn->commit();
                        
                        $response = ['success' => true, 'message' => 'User added successfully'];
                    } catch (Exception $e) {
                        // Rollback on error
                        $conn->rollback();
                        $response = ['success' => false, 'message' => 'Error adding user: ' . $e->getMessage()];
                    }
                }
            }
            break;
            
        case 'update_user':
            if (isset($_POST['username']) && isset($_POST['password'])) {
                $username = $_POST['username'];
                $password = $_POST['password'];
                $firstName = $_POST['firstName'] ?? '';
                $lastName = $_POST['lastName'] ?? '';
                $email = $_POST['email'] ?? '';
                $phone = $_POST['phone'] ?? '';
                $address = $_POST['address'] ?? '';
                
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    // Update account info
                    $accountQuery = "UPDATE account_information SET password = ? WHERE username = ?";
                    $stmt = $conn->prepare($accountQuery);
                    $stmt->bind_param("ss", $password, $username);
                    $stmt->execute();
                    
                    // Check if personal info exists
                    $checkQuery = "SELECT email FROM personal_information WHERE username = ?";
                    $stmt = $conn->prepare($checkQuery);
                    $stmt->bind_param("s", $username);
                    $stmt->execute();
                    $checkResult = $stmt->get_result();
                    
                    if ($checkResult->num_rows > 0) {
                        // Update personal info
                        $personalQuery = "UPDATE personal_information SET firstName = ?, lastName = ?, 
                                          phone = ?, address = ? WHERE username = ?";
                        $stmt = $conn->prepare($personalQuery);
                        $stmt->bind_param("sssss", $firstName, $lastName, $phone, $address, $username);
                        $stmt->execute();
                    } else if (!empty($email)) {
                        // Insert personal info
                        $personalQuery = "INSERT INTO personal_information (firstName, lastName, email, phone, address, username) 
                                          VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($personalQuery);
                        $stmt->bind_param("ssssss", $firstName, $lastName, $email, $phone, $address, $username);
                        $stmt->execute();
                    }
                    
                    // Commit transaction
                    $conn->commit();
                    
                    $response = ['success' => true, 'message' => 'User updated successfully'];
                } catch (Exception $e) {
                    // Rollback on error
                    $conn->rollback();
                    $response = ['success' => false, 'message' => 'Error updating user: ' . $e->getMessage()];
                }
            }
            break;
            
        case 'refund_payment':
            if (isset($_POST['payment_id'])) {
                $paymentId = $_POST['payment_id'];
                
                // First get the payment and booking details
                $getPaymentQuery = "SELECT p.booking_id, b.garage_id 
                                    FROM payments p 
                                    JOIN bookings b ON p.booking_id = b.id 
                                    WHERE p.payment_id = ?";
                $stmt = $conn->prepare($getPaymentQuery);
                $stmt->bind_param("i", $paymentId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result && $result->num_rows > 0) {
                    $paymentData = $result->fetch_assoc();
                    $bookingId = $paymentData['booking_id'];
                    $garageId = $paymentData['garage_id'];
                    
                    // Start transaction
                    $conn->begin_transaction();
                    
                    try {
                        // Update payment status
                        $updatePaymentQuery = "UPDATE payments SET payment_status = 'refunded' WHERE payment_id = ?";
                        $stmt = $conn->prepare($updatePaymentQuery);
                        $stmt->bind_param("i", $paymentId);
                        $stmt->execute();
                        
                        // Update booking payment status
                        $updateBookingQuery = "UPDATE bookings SET payment_status = 'refunded' WHERE id = ?";
                        $stmt = $conn->prepare($updateBookingQuery);
                        $stmt->bind_param("i", $bookingId);
                        $stmt->execute();
                        
                        // Commit transaction
                        $conn->commit();
                        
                        $response = ['success' => true, 'message' => 'Payment refunded successfully'];
                    } catch (Exception $e) {
                        // Rollback on error
                        $conn->rollback();
                        $response = ['success' => false, 'message' => 'Error refunding payment: ' . $e->getMessage()];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Payment not found'];
                }
            }
            break;
            
        case 'get_user':
            if (isset($_POST['username'])) {
                $username = $_POST['username'];
                $user = getUser($conn, $username);
                
                if ($user) {
                    $response = ['success' => true, 'data' => $user];
                } else {
                    $response = ['success' => false, 'message' => 'User not found'];
                }
            }
            break;
            
        case 'get_owner':
            if (isset($_POST['owner_id'])) {
                $ownerId = $_POST['owner_id'];
                $owner = getGarageOwner($conn, $ownerId);
                
                if ($owner) {
                    // Get owner's garages
                    $owner['garages'] = getOwnerGarages($conn, $owner['username']);
                    $response = ['success' => true, 'data' => $owner];
                } else {
                    $response = ['success' => false, 'message' => 'Garage owner not found'];
                }
            }
            break;
            
        case 'get_booking':
            if (isset($_POST['booking_id'])) {
                $bookingId = $_POST['booking_id'];
                $booking = getBooking($conn, $bookingId);
                
                if ($booking) {
                    // Get payment details if any
                    $paymentQuery = "SELECT * FROM payments WHERE booking_id = ?";
                    $stmt = $conn->prepare($paymentQuery);
                    $stmt->bind_param("i", $bookingId);
                    $stmt->execute();
                    $paymentResult = $stmt->get_result();
                    
                    if ($paymentResult && $paymentResult->num_rows > 0) {
                        $booking['payment'] = $paymentResult->fetch_assoc();
                    }
                    
                    $response = ['success' => true, 'data' => $booking];
                } else {
                    $response = ['success' => false, 'message' => 'Booking not found'];
                }
            }
            break;

        case 'get_commission_rate':
    if (isset($_POST['owner_id'])) {
        $ownerId = $_POST['owner_id'];
        
        // Check if commission record exists
        $query = "SELECT rate FROM owner_commissions WHERE owner_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $ownerId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $response = ['success' => true, 'rate' => $row['rate']];
        } else {
            $response = ['success' => false, 'message' => 'No commission rate found for this owner'];
        }
    } else {
        $response = ['success' => false, 'message' => 'Owner ID is required'];
    }
    break;
            
        case 'get_payment':
            if (isset($_POST['payment_id'])) {
                $paymentId = $_POST['payment_id'];
                $payment = getPayment($conn, $paymentId);
                
                if ($payment) {
                    $response = ['success' => true, 'data' => $payment];
                } else {
                    $response = ['success' => false, 'message' => 'Payment not found'];
                }
            }
            break;
            
        case 'get_vehicle':
            if (isset($_POST['license_plate'])) {
                $licensePlate = $_POST['license_plate'];
                $vehicle = getVehicle($conn, $licensePlate);
                
                if ($vehicle) {
                    // Get booking history
                    $vehicle['booking_history'] = getVehicleBookingHistory($conn, $licensePlate);
                    $response = ['success' => true, 'data' => $vehicle];
                } else {
                    $response = ['success' => false, 'message' => 'Vehicle not found'];
                }
            }
            break;
        case 'set_default_commission_for_all':
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // First, get all garage owners
        $ownersQuery = "SELECT owner_id FROM garage_owners";
        $ownersResult = $conn->query($ownersQuery);
        
        $updated = 0;
        $added = 0;
        
        if ($ownersResult) {
            while ($owner = $ownersResult->fetch_assoc()) {
                $ownerId = $owner['owner_id'];
                
                // Check if this owner already has a commission entry
                $checkQuery = "SELECT id FROM owner_commissions WHERE owner_id = ?";
                $stmt = $conn->prepare($checkQuery);
                $stmt->bind_param("s", $ownerId);
                $stmt->execute();
                $checkResult = $stmt->get_result();
                
                if ($checkResult->num_rows > 0) {
                    // Update existing commission
                    $updateQuery = "UPDATE owner_commissions SET rate = 30.00, updated_at = NOW() WHERE owner_id = ?";
                    $stmt = $conn->prepare($updateQuery);
                    $stmt->bind_param("s", $ownerId);
                    $stmt->execute();
                    $updated++;
                } else {
                    // Insert new commission
                    $insertQuery = "INSERT INTO owner_commissions (owner_id, rate) VALUES (?, 30.00)";
                    $stmt = $conn->prepare($insertQuery);
                    $stmt->bind_param("s", $ownerId);
                    $stmt->execute();
                    $added++;
                }
            }
            
            // Commit transaction
            $conn->commit();
            
            $response = [
                'success' => true, 
                'message' => "Commission rates updated successfully! Updated: $updated, Added: $added"
            ];
        } else {
            throw new Exception("Error fetching garage owners: " . $conn->error);
        }
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $response = ['success' => false, 'message' => $e->getMessage()];
    }
    break;
            
        
    }
    
    // Return JSON response for AJAX requests
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Get data for dashboard
$stats = getDashboardStats($conn);
$users = getAllUsers($conn);
$owners = getAllGarageOwners($conn);
$garages = getAllGarages($conn);
$bookings = getAllBookings($conn);
$payments = getAllPayments($conn);
$vehicles = getAllVehicles($conn);

// Get active tab from query parameter or default to dashboard
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';

// Function to get garage reviews
function getGarageReviews($conn, $garage_id) {
    $query = "SELECT 
                r.id,
                r.rating,
                r.review_text,
                r.rater_username,
                r.created_at,
                p.firstName,
                p.lastName,
                b.booking_date,
                b.booking_time
              FROM ratings r
              LEFT JOIN personal_information p ON r.rater_username = p.username
              LEFT JOIN bookings b ON r.booking_id = b.id
              WHERE r.garage_id = ?
              ORDER BY r.created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $garage_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $reviews = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $reviews[] = $row;
        }
    }
    
    return $reviews;
}

// Function to get garage rating summary
function getGarageRatingSummary($conn, $garage_id) {
    $query = "SELECT 
                garage_name,
                total_ratings,
                average_rating,
                five_star,
                four_star,
                three_star,
                two_star,
                one_star
              FROM garage_ratings_summary 
              WHERE garage_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $garage_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}
// Handle get profit by period - THIS WAS MISSING!
if (isset($_POST['action']) && $_POST['action'] === 'get_profit_by_period') {
    $period = $_POST['period'] ?? 'last_7_days';
    
    try {
        $profitData = getProfitByPeriod($conn, $period);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'data' => $profitData,
            'period' => $period,
            'count' => count($profitData)
        ]);
        exit();
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Error fetching profit data: ' . $e->getMessage()
        ]);
        exit();
    }
}
function getRevenueStats($conn) {
    $stats = [];
    
    // Total Revenue (all paid payments)
    $revenueQuery = "SELECT SUM(amount) as total_revenue FROM payments WHERE payment_status = 'paid'";
    $result = $conn->query($revenueQuery);
    $stats['total_revenue'] = $result->fetch_assoc()['total_revenue'] ?? 0;
    
    // Platform Profit (from profit_tracking table)
    $profitQuery = "SELECT SUM(platform_profit) as platform_profit FROM profit_tracking";
    $result = $conn->query($profitQuery);
    $stats['platform_profit'] = $result->fetch_assoc()['platform_profit'] ?? 0;
    
    // Owner Earnings (total owner profits)
    $ownerQuery = "SELECT SUM(owner_profit) as owner_earnings FROM profit_tracking";
    $result = $conn->query($ownerQuery);
    $stats['owner_earnings'] = $result->fetch_assoc()['owner_earnings'] ?? 0;
    
    // FIXED: Pending Revenue calculation to include both:
    // 1. Payments with pending status in payments table
    // 2. Bookings with pending payment status that don't have payment records yet
    $pendingQuery = "
        SELECT 
            (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_status = 'pending') +
            (SELECT COALESCE(SUM(
                CASE 
                    WHEN gi.hourly_rate IS NOT NULL THEN gi.hourly_rate * b.duration
                    ELSE 75 * b.duration  -- Default rate for 1 hour parking
                END
            ), 0)
            FROM bookings b 
            LEFT JOIN garage_information gi ON b.garage_id = gi.garage_id
            LEFT JOIN payments p ON b.id = p.booking_id
            WHERE b.payment_status = 'pending' AND p.payment_id IS NULL
            ) as pending_revenue";
    
    $result = $conn->query($pendingQuery);
    $stats['pending_revenue'] = $result->fetch_assoc()['pending_revenue'] ?? 0;
    
    // Alternative simpler approach if garage pricing is complex:
    // Just count unpaid bookings with estimated amounts
    $simplePendingQuery = "
        SELECT 
            (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_status = 'pending') +
            (SELECT COUNT(*) * 50 FROM bookings b 
             LEFT JOIN payments p ON b.id = p.booking_id 
             WHERE b.payment_status = 'pending' AND p.payment_id IS NULL) as pending_revenue";
    
    // Use the simpler approach if the complex one fails
    if ($stats['pending_revenue'] == 0) {
        $result = $conn->query($simplePendingQuery);
        $stats['pending_revenue'] = $result->fetch_assoc()['pending_revenue'] ?? 0;
    }
    
    // Total bookings count
    $bookingsQuery = "SELECT COUNT(*) as total_bookings FROM bookings";
    $result = $conn->query($bookingsQuery);
    $stats['total_bookings'] = $result->fetch_assoc()['total_bookings'] ?? 0;
    
    // Today's Revenue
    $todayQuery = "SELECT SUM(amount) as today_revenue 
                   FROM payments 
                   WHERE payment_status = 'paid' 
                   AND DATE(payment_date) = CURDATE()";
    $result = $conn->query($todayQuery);
    $stats['today_revenue'] = $result->fetch_assoc()['today_revenue'] ?? 0;
    
    // This Month's Revenue
    $monthQuery = "SELECT SUM(amount) as month_revenue 
                   FROM payments 
                   WHERE payment_status = 'paid' 
                   AND MONTH(payment_date) = MONTH(CURDATE()) 
                   AND YEAR(payment_date) = YEAR(CURDATE())";
    $result = $conn->query($monthQuery);
    $stats['month_revenue'] = $result->fetch_assoc()['month_revenue'] ?? 0;
    
    return $stats;
}


// Function to get revenue by payment method
function getRevenueByPaymentMethod($conn) {
    $query = "SELECT 
                COALESCE(payment_method, 'Unknown') as payment_method,
                COUNT(*) as transactions,
                SUM(amount) as total_amount,
                AVG(amount) as avg_amount
              FROM payments 
              WHERE payment_status = 'paid' 
              GROUP BY payment_method 
              ORDER BY total_amount DESC";
    
    $result = $conn->query($query);
    $data = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'payment_method' => $row['payment_method'],
                'transactions' => (int)$row['transactions'],
                'total_amount' => (float)$row['total_amount'],
                'avg_amount' => (float)$row['avg_amount']
            ];
        }
    }
    
    return $data;
}

// Fixed function to get top revenue generating garages
function getTopRevenueGarages($conn, $limit = 10) {
    $query = "SELECT 
                gi.garage_id,
                gi.Parking_Space_Name as garage_name,
                gi.username as owner_username,
                COUNT(DISTINCT b.id) as total_bookings,
                SUM(CASE WHEN p.payment_status = 'paid' THEN p.amount ELSE 0 END) as total_revenue,
                SUM(CASE WHEN p.payment_status = 'paid' THEN COALESCE(pt.platform_profit, p.amount * 0.30) ELSE 0 END) as platform_profit,
                SUM(CASE WHEN p.payment_status = 'paid' THEN COALESCE(pt.owner_profit, p.amount * 0.70) ELSE 0 END) as owner_profit,
                AVG(CASE WHEN p.payment_status = 'paid' THEN p.amount ELSE NULL END) as avg_per_booking
              FROM garage_information gi
              LEFT JOIN bookings b ON gi.garage_id = b.garage_id
              LEFT JOIN payments p ON b.id = p.booking_id
              LEFT JOIN profit_tracking pt ON p.payment_id = pt.payment_id
              GROUP BY gi.garage_id, gi.Parking_Space_Name, gi.username
              HAVING total_revenue > 0
              ORDER BY total_revenue DESC
              LIMIT ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'garage_id' => $row['garage_id'],
                'garage_name' => $row['garage_name'],
                'owner_username' => $row['owner_username'],
                'total_bookings' => (int)$row['total_bookings'],
                'total_revenue' => (float)$row['total_revenue'],
                'platform_profit' => (float)$row['platform_profit'],
                'owner_profit' => (float)$row['owner_profit'],
                'avg_per_booking' => (float)$row['avg_per_booking']
            ];
        }
    }
    
    return $data;
}
?>

<!DOCTYPE html>
<html class="bg-gray-900" lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Car Parking System</title>
    <!-- Tailwind CSS and daisyUI -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.7.3/dist/full.min.css" rel="stylesheet" type="text/css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        .status-active {
    background-color: rgba(16, 185, 129, 0.2);
    color: #10b981;
}
.status-suspended {
    background-color: rgba(245, 158, 11, 0.2);
    color: #f59e0b;
}
.status-inactive {
    background-color: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}
    </style>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#f39c12',
                        'primary-dark': '#e67e22',
                    }
                }
            }
        }
    </script>
    <!-- Chart.js for dashboard charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .data-table {
            width: 100%;
            overflow-x: auto;
        }
        .data-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th {
            background-color: rgba(255, 255, 255, 0.1);
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }
        .data-table td {
            padding: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .data-table tr:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        /* অ্যালগুলো মার্জ করে একটি .status-badge বানান */
.status-badge {
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 0.875rem;
    font-weight: 600;
    display: inline-block;
}

/* স্ট্যাটাস ক্লাসগুলো ঠিক আছে */
.status-active { 
    background-color: rgba(16, 185, 129, 0.2);
    color: #10b981;
}
/* অন্যান্য স্ট্যাটাস ক্লাস... */

/* ভেরিফিকেশন স্ট্যাটাস - HTML এ টেক্সট রাখবেন না */
.status-verified {
    background-color: rgba(16, 185, 129, 0.2);
    color: #10b981;
}
.status-verified::before {
    content: "";
}

.status-unverified {
    background-color: rgba(245, 158, 11, 0.2);
    color: #f59e0b;
    cursor: pointer;
    transition: all 0.3s ease;
}
.status-unverified::before {
    content: "";
}

.status-unverified:hover::before {
    content: "";
}
        .modal {
            transition: opacity 0.2s ease;
        }
        .modal-box {
            max-width: 90%;
            max-height: 90%;
        }
        .detail-section {
            margin-bottom: 20px;
        }
        .detail-section h4 {
            font-weight: 600;
            margin-bottom: 10px;
            color: #f39c12;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
        }
        .detail-item {
            margin-bottom: 10px;
        }
        .detail-label {
            font-size: 0.875rem;
            color: rgba(255, 255, 255, 0.6);
            margin-bottom: 4px;
        }
        .detail-value {
            font-weight: 500;
        }

         html, body {
        height: 100%;
        margin: 0;
    }
    body {
        display: flex;
        flex-direction: column;
    }
    .main-content {
        flex: 1 0 auto;
    }
        footer {
        flex-shrink: 0;
        margin-top: auto;
    }

    /* Status indicator styles */
.status-indicator {
    display: flex;
    align-items: center;
    gap: 6px;
}

.status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background-color: #10b981; /* Green color for verified status */
    display: inline-block;
}

.status-text {
    font-size: 0.875rem;
    color: #10b981;
    opacity: 0; /* Initially hidden */
    transition: opacity 0.3s ease;
}

tr:hover .status-text {
    opacity: 1; /* Show on row hover */
}

.status-verified .status-text {
    display: inline;
}

/* Animation when status changes */
@keyframes statusChange {
    0% { transform: scale(1); }
    50% { transform: scale(1.5); }
    100% { transform: scale(1); }
}

.status-changed {
    animation: statusChange 0.5s ease;
}

    </style>
</head>
<body class="min-h-screen bg-gray-900">
    <!-- Header -->
    <!-- Replace your existing header code with this: -->
<header class="bg-gray-800 shadow-md">
    <div class="container mx-auto px-4 py-4 flex justify-between items-center">
        <div class="flex items-center gap-4">
            <div class="w-10 h-10 bg-primary rounded-full flex justify-center items-center overflow-hidden">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
            </div>
            <h1 class="text-xl font-semibold text-white">Admin Dashboard</h1>
        </div>
        
        <div class="flex items-center gap-4">
            <span class="text-white/80">Welcome, Admin</span>
            
            <!-- Notification Icon - Now positioned between Welcome and Logout -->
            <div class="relative">
                <button id="notification-button" class="btn btn-sm btn-ghost text-white relative">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <span id="notification-count" class="absolute -top-1 -right-1 bg-primary text-white text-xs font-bold rounded-full h-5 w-5 flex items-center justify-center"><?php echo $notificationCounts['total']; ?></span>
                </button>
                
                <div id="notification-dropdown" class="absolute right-0 mt-2 w-80 bg-gray-800 shadow-lg rounded-lg z-50 hidden">
                    <div class="p-3 border-b border-gray-700">
                        <h3 class="font-bold text-white">Notifications</h3>
                        <p class="text-xs text-white/70">Items needing verification</p>
                    </div>
                    
                    <div id="notification-content" class="max-h-96 overflow-y-auto">
                        <div class="p-6 text-center text-white/70">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-white/40 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                            <p>Loading notifications...</p>
                        </div>
                    </div>
                    
                    <div class="p-3 border-t border-gray-700 text-center">
                        <button id="refresh-notifications" class="btn btn-sm btn-ghost text-white">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"></path>
                            </svg>
                            Refresh
                        </button>
                    </div>
                </div>
            </div>
            
            <a href="logout.php" class="btn btn-sm btn-outline text-white border-white/30 hover:bg-white/10 hover:border-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
                Logout
            </a>
        </div>
    </div>
</header>

    <!-- Main Content -->
    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-col md:flex-row gap-6">
            <!-- Sidebar Navigation -->
            <div class="w-full md:w-64 bg-gray-800 rounded-lg p-4">
                <nav>
                    <ul class="space-y-2">
                        <li>
                            <a href="?tab=dashboard" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'dashboard' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="14" width="7" height="7"></rect>
                                    <rect x="3" y="14" width="7" height="7"></rect>
                                </svg>
                                Dashboard
                            </a>
                        </li>
                        <li>
                            <a href="?tab=users" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'users' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg>
                                Users
                            </a>
                        </li>
                        <li>
                            <a href="?tab=owners" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'owners' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="9" cy="7" r="4"></circle>
                                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                </svg>
                                Garage Owners
                            </a>
                        </li>
                        <li>
                            <a href="?tab=garages" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'garages' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                                </svg>
                                Garages
                            </a>
                        </li>
                        <li>
    <a href="?tab=unverified_garages" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'unverified_garages' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
            <polyline points="9 22 9 12 15 12 15 22"></polyline>
        </svg>
        Unverified Garages
    </a>
</li>
                        <li>
                            <a href="?tab=bookings" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'bookings' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                                Bookings
                            </a>
                        </li>
                        <li>
                            <a href="?tab=payments" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'payments' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                                    <line x1="1" y1="10" x2="23" y2="10"></line>
                                </svg>
                                Payments
                            </a>
                        </li>
                        <li>
                            <a href="?tab=vehicles" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'vehicles' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="1" y="3" width="15" height="13"></rect>
                                    <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                                    <circle cx="5.5" cy="18.5" r="2.5"></circle>
                                    <circle cx="18.5" cy="18.5" r="2.5"></circle>
                                </svg>
                                Vehicles
                            </a>
                        </li>
                        <li>
            <a href="?tab=revenue" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'revenue' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="1" x2="12" y2="23"></line>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                </svg>
                Revenue & Profit
            </a>
        </li>
                    </ul>
                </nav>
            </div>
            
            <!-- Main Content Area -->
            <div class="flex-1">
                <!-- Dashboard Tab -->
                <!-- Replace your entire dashboard tab content with this dynamic version -->
<div id="dashboard-tab" class="tab-content <?php echo $activeTab === 'dashboard' ? 'active' : ''; ?>">
    <h2 class="text-2xl font-bold text-white mb-6">Dashboard Overview</h2>
    
    <!-- Main Stats Cards - Responsive Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6 mb-6">
        <!-- Total Users -->
        <div class="bg-gray-800 rounded-lg p-4 lg:p-6 shadow-lg hover:shadow-xl transition-shadow">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-white/60 text-xs lg:text-sm mb-1">Total Users</p>
                    <h3 class="text-xl lg:text-2xl font-bold text-white"><?php echo number_format($stats['total_users']); ?></h3>
                    <p class="text-xs text-blue-400 mt-1">Registered accounts</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-blue-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                </div>
            </div>
        </div>
        
        <!-- Total Garages -->
        <div class="bg-gray-800 rounded-lg p-4 lg:p-6 shadow-lg hover:shadow-xl transition-shadow">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-white/60 text-xs lg:text-sm mb-1">Total Garages</p>
                    <h3 class="text-xl lg:text-2xl font-bold text-white"><?php echo number_format($stats['total_garages']); ?></h3>
                    <p class="text-xs text-green-400 mt-1">Active locations</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-green-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                </div>
            </div>
        </div>
        
        <!-- Total Revenue -->
        <div class="bg-gray-800 rounded-lg p-4 lg:p-6 shadow-lg hover:shadow-xl transition-shadow">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-white/60 text-xs lg:text-sm mb-1">Total Revenue</p>
                    <h3 class="text-xl lg:text-2xl font-bold text-white">৳<?php echo number_format($stats['total_payments'], 2); ?></h3>
                    <p class="text-xs text-yellow-400 mt-1">All payments</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-yellow-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-yellow-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                        <line x1="1" y1="10" x2="23" y2="10"></line>
                    </svg>
                </div>
            </div>
        </div>
        
        <!-- Platform Profit -->
        <div class="bg-gray-800 rounded-lg p-4 lg:p-6 shadow-lg hover:shadow-xl transition-shadow border border-emerald-500/20">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-white/60 text-xs lg:text-sm mb-1">Platform Profit</p>
                    <h3 class="text-xl lg:text-2xl font-bold text-emerald-400">৳<?php echo number_format($stats['total_profit'], 2); ?></h3>
                    <p class="text-xs text-emerald-300 mt-1">
                        <?php 
                        $profit_margin = $stats['total_payments'] > 0 ? ($stats['total_profit'] / $stats['total_payments']) * 100 : 0;
                        echo number_format($profit_margin, 1) . '% margin';
                        ?>
                    </p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-emerald-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="1" x2="12" y2="23"></line>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Secondary Stats - Responsive Grid -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 lg:gap-4 mb-6">
        <!-- Today's Bookings -->
        <div class="bg-gray-800 rounded-lg p-3 lg:p-4 shadow-lg">
            <div class="text-center">
                <div class="w-8 h-8 bg-blue-500/20 rounded-full flex items-center justify-center mx-auto mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                </div>
                <p class="text-xs text-white/60 mb-1">Today's Bookings</p>
                <h4 class="text-lg lg:text-xl font-bold text-white"><?php echo number_format($stats['today_bookings']); ?></h4>
            </div>
        </div>

        <!-- Today's Revenue -->
        <div class="bg-gray-800 rounded-lg p-3 lg:p-4 shadow-lg">
            <div class="text-center">
                <div class="w-8 h-8 bg-green-500/20 rounded-full flex items-center justify-center mx-auto mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="1" x2="12" y2="23"></line>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                    </svg>
                </div>
                <p class="text-xs text-white/60 mb-1">Today's Revenue</p>
                <h4 class="text-lg lg:text-xl font-bold text-white">৳<?php echo number_format($stats['today_revenue'], 2); ?></h4>
            </div>
        </div>

        <!-- Today's Profit -->
        <div class="bg-gray-800 rounded-lg p-3 lg:p-4 shadow-lg border border-emerald-500/20">
            <div class="text-center">
                <div class="w-8 h-8 bg-emerald-500/20 rounded-full flex items-center justify-center mx-auto mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                    </svg>
                </div>
                <p class="text-xs text-white/60 mb-1">Today's Profit</p>
                <h4 class="text-lg lg:text-xl font-bold text-emerald-400">৳<?php echo number_format($stats['today_profit'], 2); ?></h4>
            </div>
        </div>

        <!-- Active Bookings -->
        <div class="bg-gray-800 rounded-lg p-3 lg:p-4 shadow-lg">
            <div class="text-center">
                <div class="w-8 h-8 bg-orange-500/20 rounded-full flex items-center justify-center mx-auto mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-orange-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12,6 12,12 16,14"></polyline>
                    </svg>
                </div>
                <p class="text-xs text-white/60 mb-1">Active Bookings</p>
                <h4 class="text-lg lg:text-xl font-bold text-white"><?php echo number_format($stats['active_bookings']); ?></h4>
            </div>
        </div>

        <!-- Garage Owners -->
        <div class="bg-gray-800 rounded-lg p-3 lg:p-4 shadow-lg">
            <div class="text-center">
                <div class="w-8 h-8 bg-purple-500/20 rounded-full flex items-center justify-center mx-auto mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-purple-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                </div>
                <p class="text-xs text-white/60 mb-1">Garage Owners</p>
                <h4 class="text-lg lg:text-xl font-bold text-white"><?php echo number_format($stats['total_owners']); ?></h4>
            </div>
        </div>

        <!-- Total Bookings -->
        <div class="bg-gray-800 rounded-lg p-3 lg:p-4 shadow-lg">
            <div class="text-center">
                <div class="w-8 h-8 bg-indigo-500/20 rounded-full flex items-center justify-center mx-auto mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14,2 14,8 20,8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10,9 9,9 8,9"></polyline>
                    </svg>
                </div>
                <p class="text-xs text-white/60 mb-1">Total Bookings</p>
                <h4 class="text-lg lg:text-xl font-bold text-white"><?php echo number_format($stats['total_bookings']); ?></h4>
            </div>
        </div>
    </div>

    <!-- Charts and Analytics -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- Revenue vs Profit Chart -->
        <div class="lg:col-span-2 bg-gray-800 rounded-lg p-6 shadow-lg">
            <div class="flex justify-between items-center mb-4">
                <h4 class="text-lg font-semibold text-white">Revenue vs Profit Trend</h4>
                <button id="refresh-profit-chart" class="btn btn-sm btn-ghost text-white/60 hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"></path>
                    </svg>
                </button>
            </div>
            <div class="h-64">
                <canvas id="revenueProfitChart"></canvas>
            </div>
        </div>
        
        <!-- Top Contributing Owners -->
        <div class="bg-gray-800 rounded-lg p-6 shadow-lg">
            <div class="flex justify-between items-center mb-4">
                <h4 class="text-lg font-semibold text-white">Top Contributing Owners</h4>
                <span class="text-xs text-white/60">by profit generated</span>
            </div>
            <div class="space-y-3 max-h-80 overflow-y-auto">
                <?php 
                $profitStats = getProfitStats($conn);
                if (!empty($profitStats['top_profitable_owners'])): 
                    foreach ($profitStats['top_profitable_owners'] as $index => $owner): 
                ?>
                <div class="flex items-center justify-between p-3 bg-gray-700/50 rounded-lg hover:bg-gray-700/70 transition-colors">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-primary/20 rounded-full flex items-center justify-center text-primary font-bold text-sm">
                            <?php echo $index + 1; ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-white font-medium truncate"><?php echo htmlspecialchars($owner['username']); ?></p>
                            <p class="text-white/60 text-xs"><?php echo $owner['transaction_count']; ?> transactions</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-emerald-400 font-bold text-sm">৳<?php echo number_format($owner['total_profit'], 0); ?></p>
                        <p class="text-white/60 text-xs">profit</p>
                    </div>
                </div>
                <?php 
                    endforeach; 
                else: 
                ?>
                <div class="text-center py-8">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-white/20 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                        <line x1="9" y1="9" x2="9.01" y2="9"></line>
                        <line x1="15" y1="9" x2="15.01" y2="9"></line>
                    </svg>
                    <p class="text-white/60 text-sm">No profit data available yet</p>
                    <button class="btn btn-sm btn-primary mt-2" onclick="calculateMissingProfits()">Calculate Profits</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Booking Status Chart -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <div class="bg-gray-800 rounded-lg p-6 shadow-lg">
            <h4 class="text-lg font-semibold text-white mb-4">Booking Status Distribution</h4>
            <div class="h-64">
                <canvas id="bookingStatusChart"></canvas>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="bg-gray-800 rounded-lg p-6 shadow-lg">
            <h4 class="text-lg font-semibold text-white mb-4">Quick Actions</h4>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <button class="btn btn-primary btn-sm" onclick="calculateMissingProfits()">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                    </svg>
                    Calculate Profits
                </button>
                
                <button class="btn btn-secondary btn-sm" onclick="refreshDashboard()">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"></path>
                    </svg>
                    Refresh Data
                </button>
                
                <a href="?tab=users" class="btn btn-outline btn-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    Manage Users
                </a>
                
                <a href="?tab=owners" class="btn btn-outline btn-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    Manage Owners
                </a>
            </div>
            
            <!-- System Status -->
            <div class="mt-6 p-4 bg-gray-700/30 rounded-lg">
                <h5 class="text-sm font-semibold text-white mb-3">System Status</h5>
                <div class="space-y-2">
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-white/60">Database</span>
                        <span class="text-green-400 flex items-center gap-1">
                            <div class="w-2 h-2 bg-green-400 rounded-full"></div>
                            Online
                        </span>
                    </div>
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-white/60">Payment System</span>
                        <span class="text-green-400 flex items-center gap-1">
                            <div class="w-2 h-2 bg-green-400 rounded-full"></div>
                            Active
                        </span>
                    </div>
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-white/60">Profit Tracking</span>
                        <span class="text-<?php echo !empty($profitStats['top_profitable_owners']) ? 'green' : 'yellow'; ?>-400 flex items-center gap-1">
                            <div class="w-2 h-2 bg-<?php echo !empty($profitStats['top_profitable_owners']) ? 'green' : 'yellow'; ?>-400 rounded-full"></div>
                            <?php echo !empty($profitStats['top_profitable_owners']) ? 'Active' : 'Pending'; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Bookings -->
    <div class="bg-gray-800 rounded-lg p-6 shadow-lg">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <h4 class="text-lg font-semibold text-white">Recent Bookings</h4>
            <div class="flex gap-2">
                <a href="?tab=bookings" class="text-primary hover:text-primary-dark text-sm flex items-center gap-1">
                    View All
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </a>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="text-left border-b border-gray-700">
                        <th class="text-white/70 pb-3 text-sm">ID</th>
                        <th class="text-white/70 pb-3 text-sm">User</th>
                        <th class="text-white/70 pb-3 text-sm">Garage</th>
                        <th class="text-white/70 pb-3 text-sm">Date & Time</th>
                        <th class="text-white/70 pb-3 text-sm">Status</th>
                        <th class="text-white/70 pb-3 text-sm">Payment</th>
                        <th class="text-white/70 pb-3 text-sm">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Display only the 5 most recent bookings
                    $recentBookings = array_slice($bookings, 0, 5);
                    if (!empty($recentBookings)):
                        foreach ($recentBookings as $booking): 
                            $bookingDateTime = date('d M Y', strtotime($booking['booking_date'])) . ' at ' . date('h:i A', strtotime($booking['booking_time']));
                            
                            // Determine status class
                            $statusClass = '';
                            switch ($booking['status']) {
                                case 'upcoming': $statusClass = 'bg-blue-500/20 text-blue-400'; break;
                                case 'active': $statusClass = 'bg-green-500/20 text-green-400'; break;
                                case 'completed': $statusClass = 'bg-gray-500/20 text-gray-400'; break;
                                case 'cancelled': $statusClass = 'bg-red-500/20 text-red-400'; break;
                            }
                            
                            // Determine payment status class
                            $paymentClass = '';
                            switch ($booking['payment_status']) {
                                case 'paid': $paymentClass = 'bg-green-500/20 text-green-400'; break;
                                case 'pending': $paymentClass = 'bg-yellow-500/20 text-yellow-400'; break;
                                case 'refunded': $paymentClass = 'bg-purple-500/20 text-purple-400'; break;
                            }
                    ?>
                    <tr class="border-b border-gray-700/50 hover:bg-gray-700/20 transition-colors">
                        <td class="py-3 text-sm text-white">#<?php echo $booking['id']; ?></td>
                        <td class="py-3 text-sm text-white"><?php echo htmlspecialchars($booking['username']); ?></td>
                        <td class="py-3 text-sm text-white truncate max-w-32"><?php echo htmlspecialchars($booking['Parking_Space_Name']); ?></td>
                        <td class="py-3 text-sm text-white/80"><?php echo $bookingDateTime; ?></td>
                        <td class="py-3">
                            <span class="px-2 py-1 text-xs rounded-full <?php echo $statusClass; ?>">
                                <?php echo ucfirst($booking['status']); ?>
                            </span>
                        </td>
                        <td class="py-3">
                            <span class="px-2 py-1 text-xs rounded-full <?php echo $paymentClass; ?>">
                                <?php echo ucfirst($booking['payment_status']); ?>
                            </span>
                        </td>
                        <td class="py-3">
                            <button class="btn btn-xs btn-ghost text-white/60 hover:text-white" onclick="viewBookingDetails(<?php echo $booking['id']; ?>)">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                            </button>
                        </td>
                    </tr>
                    <?php 
                        endforeach; 
                    else:
                    ?>
                    <tr>
                        <td colspan="7" class="py-8 text-center">
                            <div class="text-white/60">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto mb-3 text-white/20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                                <p class="text-sm">No recent bookings found</p>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
                
                <!-- Users Tab -->
                <div id="users-tab" class="tab-content <?php echo $activeTab === 'users' ? 'active' : ''; ?>">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-white">User Management</h2>
        <div class="flex gap-4">
            <div class="relative">
                <input type="text" id="user-search" placeholder="Search users..." class="input input-bordered bg-gray-700 text-white w-64">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 absolute right-3 top-3 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
            </div>
            <button class="btn btn-primary" onclick="openAddUserModal()">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add User
            </button>
        </div>
    </div>
    
    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
        <div class="data-table">
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Points</th>
                        <th>Address</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <?php if ($user['status'] == 'verified'): ?>
                                <span class="status-badge status-verified">Verified</span>
                            <?php else: ?>
                                <span class="status-badge status-unverified" onclick="verifyUser('<?php echo $user['username']; ?>')">Unverified</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?></td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                        <td><?php echo htmlspecialchars($user['phone']); ?></td>
                        <td>
                            <div class="flex items-center gap-2">
                                <span class="text-primary font-bold"><?php echo number_format($user['points']); ?></span>
                                <button class="btn btn-xs btn-outline btn-warning" onclick="openPointsModal('<?php echo $user['username']; ?>', <?php echo $user['points']; ?>)">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                </button>
                                <button class="btn btn-xs btn-outline btn-info" onclick="viewPointsHistory('<?php echo $user['username']; ?>')" title="View Points History">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12,6 12,12 16,14"></polyline>
            </svg>
        </button>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($user['address']); ?></td>
                        <td>
                            <div class="flex gap-2">
                                <button class="btn btn-sm btn-outline btn-info" onclick="editUser('<?php echo $user['username']; ?>')">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                </button>
                                <button class="btn btn-sm btn-outline btn-error" onclick="deleteUser('<?php echo $user['username']; ?>')">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"></polyline>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
                
                <!-- Garage Owners Tab -->
<div id="owners-tab" class="tab-content <?php echo $activeTab === 'owners' ? 'active' : ''; ?>">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-white">Garage Owner Management</h2>
        <div class="flex gap-4">
            <button class="btn btn-primary" onclick="setDefaultCommissionForAll()">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14M5 12h14"></path>
                </svg>
                Set 30% Commission for All
            </button>
            <div class="relative">
                <input type="text" id="owner-search" placeholder="Search owners..." class="input input-bordered bg-gray-700 text-white w-64">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 absolute right-3 top-3 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
            </div>
        </div>
    </div>
    
    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
        <div class="data-table">
            <table>
                <thead>
                    <tr>
                        <th>Owner ID</th>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Registration Date</th>
                        <th>Verification</th> <!-- Changed from Status -->
                        <th>Account Status</th> <!-- New column -->
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Get all owners including dual users
                    $ownersQuery = "SELECT 
                        go.owner_id, 
                        go.username, 
                        go.is_verified, 
                        go.registration_date, 
                        go.last_login, 
                        go.account_status,
                        p.firstName, 
                        p.lastName, 
                        p.email, 
                        p.phone, 
                        1 as is_official
                      FROM garage_owners go
                      LEFT JOIN personal_information p ON go.username = p.username
                      
                      UNION
                      
                      SELECT 
                        du.owner_id, 
                        du.username, 
                        du.is_verified, 
                        du.registration_date, 
                        du.last_login, 
                        du.account_status,
                        p.firstName, 
                        p.lastName, 
                        p.email, 
                        p.phone, 
                        0 as is_official
                      FROM dual_user du
                      LEFT JOIN personal_information p ON du.username = p.username
                      
                      ORDER BY registration_date DESC";
                    
                    $ownersResult = $conn->query($ownersQuery);
                    $allOwners = [];
                    
                    if ($ownersResult && $ownersResult->num_rows > 0) {
                        while ($row = $ownersResult->fetch_assoc()) {
                            $allOwners[] = $row;
                        }
                    }
                    
                    foreach ($allOwners as $owner): 
                        $verifiedClass = $owner['is_verified'] ? 'status-verified' : 'status-unverified';
                        $verifiedText = $owner['is_verified'] ? 'Verified' : 'Unverified';
                        $registrationDate = date('d M Y', strtotime($owner['registration_date']));
                    ?>
                    <tr>
                        <td>
                            <?php echo $owner['owner_id']; ?>
                            <?php if ($owner['is_official'] == 0): ?>
                                <!-- Yellow for regular users -->
                                <svg xmlns="http://www.w3.org/2000/svg" class="inline h-5 w-5 text-yellow-500 ml-1" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            <?php elseif ($owner['is_official'] == 2): ?>
                                <!-- Green for converted users -->
                                <svg xmlns="http://www.w3.org/2000/svg" class="inline h-5 w-5 text-green-500 ml-1" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            <?php else: ?>
                                <!-- Blue for original professional owners -->
                                <svg xmlns="http://www.w3.org/2000/svg" class="inline h-5 w-5 text-blue-500 ml-1" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $owner['username']; ?></td>
                        <td><?php echo $owner['firstName'] . ' ' . $owner['lastName']; ?></td>
                        <td><?php echo $owner['email']; ?></td>
                        <td><?php echo $owner['phone']; ?></td>
                        <td><?php echo $registrationDate; ?></td>
                        
                        <td>
                            <span class="status-badge <?php echo $verifiedClass; ?>"><?php echo $verifiedText; ?></span>
                        </td>
                        <td>
                            <?php if ($owner['account_status'] == 'active'): ?>
                                <span class="status-badge status-active">Active</span>
                            <?php elseif ($owner['account_status'] == 'suspended'): ?>
                                <span class="status-badge status-suspended">Suspended</span>
                            <?php elseif ($owner['account_status'] == 'inactive'): ?>
                                <span class="status-badge status-inactive">Deactivated</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="flex gap-2">
                                <?php if (!$owner['is_verified']): ?>
                                <button class="btn btn-sm btn-outline btn-success" onclick="verifyOwner('<?php echo $owner['owner_id']; ?>')">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                    </svg>
                                    Verify
                                </button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline btn-info" onclick="viewOwnerDetails('<?php echo $owner['owner_id']; ?>')">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="8"></circle>
                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                    </svg>
                                </button>
                                <div class="dropdown dropdown-end">
                                    <button tabindex="0" class="btn btn-sm btn-outline">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="1"></circle>
                                            <circle cx="12" cy="5" r="1"></circle>
                                            <circle cx="12" cy="19" r="1"></circle>
                                        </svg>
                                    </button>
                                    <ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow bg-gray-700 rounded-box w-52">
                                        <li><a onclick="updateOwnerStatus('<?php echo $owner['owner_id']; ?>', 'active')">Activate Account</a></li>
                                        <li><a onclick="updateOwnerStatus('<?php echo $owner['owner_id']; ?>', 'suspended')">Suspend Account</a></li>
                                        <li><a onclick="updateOwnerStatus('<?php echo $owner['owner_id']; ?>', 'inactive')">Deactivate Account</a></li>
                                    </ul>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Garages Tab -->
<div id="garages-tab" class="tab-content <?php echo $activeTab === 'garages' ? 'active' : ''; ?>">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-white">Garage Management</h2>
        <div class="flex gap-4">
            <div class="relative">
                <input type="text" id="garage-search" placeholder="Search garages..." class="input input-bordered bg-gray-700 text-white w-64">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 absolute right-3 top-3 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
            </div>
        </div>
    </div>
    
    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
        <div class="data-table">
            <table>
                <thead>
                    <tr>
                        <th style="width: 120px;">ID</th>
                        <th style="width: 200px;">Name</th>
                        <th style="width: 250px;">Address</th>
                        <th style="width: 100px;">Owner</th>
                        <th style="width: 80px;">Type</th>
                        <th style="width: 80px;">Capacity</th>
                        <th style="width: 80px;">Available</th>
                        <th style="width: 100px;">Price/Hour</th>
                        <th style="width: 140px;">Status</th>
                        <th style="width: 220px;">Actions</th> <!-- Increased width for actions -->
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($garages as $garage): 
                        // Safe handling of coordinates
                        $latitudeJS = isset($garage['Latitude']) && $garage['Latitude'] !== null ? (float)$garage['Latitude'] : 'null';
                        $longitudeJS = isset($garage['Longitude']) && $garage['Longitude'] !== null ? (float)$garage['Longitude'] : 'null';
                        $nameJS = addslashes($garage['Parking_Space_Name'] ?? '');
                        
                        // Get garage status with error handling
                        try {
                            $currentStatus = getSimpleGarageStatus($conn, $garage['garage_id']);
                        } catch (Exception $e) {
                            $currentStatus = 'UNKNOWN';
                            error_log("Error getting status for garage {$garage['garage_id']}: " . $e->getMessage());
                        }
                        
                        $statusClass = '';
                        $statusText = $currentStatus;
                        
                        switch(strtolower($currentStatus)) {
                            case 'open':
                                $statusClass = 'badge-success';
                                $statusText = 'OPEN';
                                break;
                            case 'closed':
                                $statusClass = 'badge-error';
                                $statusText = 'CLOSED';
                                break;
                            case 'maintenance':
                                $statusClass = 'badge-warning';
                                $statusText = 'MAINTENANCE';
                                break;
                            case 'emergency_closed':
                                $statusClass = 'badge-error';
                                $statusText = 'EMERGENCY';
                                break;
                            default:
                                $statusClass = 'badge-neutral';
                                $statusText = 'UNKNOWN';
                        }
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($garage['garage_id'] ?? ''); ?></td>
                        <td title="<?php echo htmlspecialchars($garage['Parking_Space_Name'] ?? ''); ?>">
                            <?php echo htmlspecialchars($garage['Parking_Space_Name'] ?? ''); ?>
                        </td>
                        <td title="<?php echo htmlspecialchars($garage['Parking_Lot_Address'] ?? ''); ?>">
                            <?php echo htmlspecialchars($garage['Parking_Lot_Address'] ?? ''); ?>
                        </td>
                        <td><?php echo htmlspecialchars($garage['username'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($garage['Parking_Type'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($garage['Parking_Capacity'] ?? '0'); ?></td>
                        <td><?php echo htmlspecialchars($garage['Availability'] ?? '0'); ?></td>
                        <td>৳<?php echo htmlspecialchars($garage['PriceperHour'] ?? '0'); ?></td>
                        <!-- Status display -->
                        <td>
                            <div class="badge <?php echo $statusClass; ?> badge-sm w-full text-xs" 
                                 title="<?php echo $statusText; ?>">
                                <?php echo $statusText; ?>
                            </div>
                        </td>
                        <td>
                            <div class="flex gap-1 flex-wrap">
                                <!-- Edit Button -->
                                <button class="btn btn-xs btn-outline btn-info" 
                                        onclick="editGarage('<?php echo $garage['garage_id']; ?>', <?php echo $garage['PriceperHour'] ?? 0; ?>)"
                                        title="Edit Garage">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                </button>
                                
                                <!-- Location Button -->
                                <?php if ($latitudeJS !== 'null' && $longitudeJS !== 'null'): ?>
                                <button class="btn btn-xs btn-outline btn-success" 
                                        onclick="viewGarageLocation(<?php echo $latitudeJS; ?>, <?php echo $longitudeJS; ?>, '<?php echo $nameJS; ?>')"
                                        title="View Location">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                        <circle cx="12" cy="10" r="3"></circle>
                                    </svg>
                                </button>
                                <?php else: ?>
                                <button class="btn btn-xs btn-outline btn-gray" 
                                        disabled
                                        title="Location not available">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                        <circle cx="12" cy="10" r="3"></circle>
                                    </svg>
                                </button>
                                <?php endif; ?>
                                
                                <!-- Reviews Button -->
                                <button class="btn btn-xs btn-outline btn-warning" 
                                        onclick="viewGarageReviews('<?php echo $garage['garage_id']; ?>', '<?php echo addslashes($garage['Parking_Space_Name'] ?? 'Unknown Garage'); ?>')" 
                                        title="View Reviews">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26 12,2"></polygon>
                                    </svg>
                                </button>
                                
                                <!-- Control Button -->
                                <button class="btn btn-xs btn-outline btn-secondary" 
                                        onclick="openGarageControl('<?php echo $garage['garage_id']; ?>')"
                                        title="Control Panel">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="3"></circle>
                                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1 1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
                
<!-- Unverified Garages Tab -->
    <div id="unverified_garages-tab" class="tab-content <?php echo $activeTab === 'unverified_garages' ? 'active' : ''; ?>">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-white">Unverified Garages</h2>
        </div>
        
        <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
            <div class="data-table">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Address</th>
                            <th>Owner</th>
                            <th>Owner Status</th>
                            <th>Garage Status</th>
                            <th>Type</th>
                            <th>Capacity</th>
                            <th>Price/Hour</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $unverifiedGarages = getUnverifiedGarages($conn);
                        foreach ($unverifiedGarages as $garage): 
                            // Get owner verification status
                            $ownerVerified = false;
                            $ownerQuery = "SELECT is_verified FROM garage_owners WHERE username = ?";
                            $stmt = $conn->prepare($ownerQuery);
                            $stmt->bind_param("s", $garage['username']);
                            $stmt->execute();
                            $ownerResult = $stmt->get_result();
                            if ($ownerResult && $ownerResult->num_rows > 0) {
                                $ownerRow = $ownerResult->fetch_assoc();
                                $ownerVerified = $ownerRow['is_verified'] == 1;
                            }
                        ?>
                        <tr>
    <td><?php echo $garage['garage_id']; ?></td>
    <td><?php echo $garage['Parking_Space_Name']; ?></td>
    <td><?php echo $garage['Parking_Lot_Address']; ?></td>
    <td><?php echo $garage['username']; ?></td>
    <td>
        <?php if ($ownerVerified): ?>
            <span class="status-badge status-verified">Verified</span>
        <?php else: ?>
            <span class="status-badge status-unverified">Unverified</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if ($garage['is_verified'] == 1): ?>
            <span class="status-badge status-verified">Verified</span>
        <?php else: ?>
            <span class="status-badge status-unverified">Unverified</span>
        <?php endif; ?>
    </td>
    <td><?php echo $garage['Parking_Type']; ?></td>
    <td><?php echo $garage['Parking_Capacity']; ?></td>
    <td>৳<?php echo $garage['PriceperHour']; ?></td>
    <td>
        <div class="flex gap-2">
            <button class="btn btn-sm btn-outline btn-success" onclick="verifyGarage('<?php echo $garage['garage_id']; ?>')">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                Verify
            </button>
            <button class="btn btn-sm btn-outline btn-info" onclick="viewGarageDetails('<?php echo $garage['garage_id']; ?>')">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
            </button>
        </div>
    </td>
</tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>


                <!-- Bookings Tab -->
                <div id="bookings-tab" class="tab-content <?php echo $activeTab === 'bookings' ? 'active' : ''; ?>">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-white">Booking Management</h2>
                        <div class="flex gap-4">
                            <div class="relative">
                                <input type="text" id="booking-search" placeholder="Search bookings..." class="input input-bordered bg-gray-700 text-white w-64">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 absolute right-3 top-3 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                            </div>
                            <select id="booking-status-filter" class="select select-bordered bg-gray-700 text-white">
                                <option value="all">All Statuses</option>
                                <option value="upcoming">Upcoming</option>
                                <option value="active">Active</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                        <div class="data-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>User</th>
                                        <th>Garage</th>
                                        <th>Vehicle</th>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Duration</th>
                                        <th>Status</th>
                                        <th>Payment</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bookings as $booking): 
                                        $bookingDate = date('d M Y', strtotime($booking['booking_date']));
                                        $bookingTime = date('h:i A', strtotime($booking['booking_time']));
                                        $vehicleInfo = $booking['make'] . ' ' . $booking['model'] . ' (' . $booking['color'] . ')';
                                        
                                        // Determine status class
                                        $statusClass = '';
                                        switch ($booking['status']) {
                                            case 'upcoming':
                                                $statusClass = 'status-upcoming';
                                                break;
                                            case 'active':
                                                $statusClass = 'status-active';
                                                break;
                                            case 'completed':
                                                $statusClass = 'status-completed';
                                                break;
                                            case 'cancelled':
                                                $statusClass = 'status-cancelled';
                                                break;
                                        }
                                        
                                        // Determine payment status class
                                        $paymentClass = '';
                                        switch ($booking['payment_status']) {
                                            case 'paid':
                                                $paymentClass = 'status-paid';
                                                break;
                                            case 'pending':
                                                $paymentClass = 'status-pending';
                                                break;
                                            case 'refunded':
                                                $paymentClass = 'status-refunded';
                                                break;
                                        }
                                    ?>
                                    <tr data-status="<?php echo $booking['status']; ?>">
                                        <td>#<?php echo $booking['id']; ?></td>
                                        <td><?php echo $booking['username']; ?></td>
                                        <td><?php echo $booking['Parking_Space_Name']; ?></td>
                                        <td><?php echo $vehicleInfo; ?></td>
                                        <td><?php echo $bookingDate; ?></td>
                                        <td><?php echo $bookingTime; ?></td>
                                        <td><?php echo $booking['duration']; ?> hours</td>
                                        <td>
                                            <span class="status-badge <?php echo $statusClass; ?>"><?php echo ucfirst($booking['status']); ?></span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $paymentClass; ?>"><?php echo ucfirst($booking['payment_status']); ?></span>
                                        </td>
                                        <td>
                                            <div class="flex gap-2">
                                                <button class="btn btn-sm btn-outline btn-info" onclick="viewBookingDetails(<?php echo $booking['id']; ?>)">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="11" cy="11" r="8"></circle>
                                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                                    </svg>
                                                </button>
                                                <?php if ($booking['status'] === 'upcoming' || $booking['status'] === 'active'): ?>
                                                <button class="btn btn-sm btn-outline btn-error" onclick="cancelBooking(<?php echo $booking['id']; ?>)">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="12" cy="12" r="10"></circle>
                                                        <line x1="15" y1="9" x2="9" y2="15"></line>
                                                        <line x1="9" y1="9" x2="15" y2="15"></line>
                                                    </svg>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Payments Tab -->
                <div id="payments-tab" class="tab-content <?php echo $activeTab === 'payments' ? 'active' : ''; ?>">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-white">Payment Management</h2>
                        <div class="flex gap-4">
                            <div class="relative">
                                <input type="text" id="payment-search" placeholder="Search payments..." class="input input-bordered bg-gray-700 text-white w-64">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 absolute right-3 top-3 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                            </div>
                            <select id="payment-status-filter" class="select select-bordered bg-gray-700 text-white">
                                <option value="all">All Statuses</option>
                                <option value="paid">Paid</option>
                                <option value="pending">Pending</option>
                                <option value="refunded">Refunded</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                        <div class="data-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Transaction ID</th>
                                        <th>Booking ID</th>
                                        <th>User</th>
                                        <th>Garage</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    foreach ($payments as $payment):
                                        // Only process payments that have a payment record
                                        if (!empty($payment['payment_id'])):
                                            $paymentDate = !empty($payment['payment_date']) ? date('d M Y h:i A', strtotime($payment['payment_date'])) : 'N/A';
                                            
                                            // Determine payment status class
                                            $paymentClass = '';
                                            switch ($payment['payment_status']) {
                                                case 'paid':
                                                    $paymentClass = 'status-paid';
                                                    break;
                                                case 'pending':
                                                    $paymentClass = 'status-pending';
                                                    break;
                                                case 'refunded':
                                                    $paymentClass = 'status-refunded';
                                                    break;
                                            }
                                    ?>
                                    <tr data-status="<?php echo $payment['payment_status']; ?>">
                                        <td>#<?php echo $payment['payment_id']; ?></td>
                                        <td><?php echo $payment['transaction_id']; ?></td>
                                        <td>#<?php echo $payment['booking_id']; ?></td>
                                        <td><?php echo $payment['username']; ?></td>
                                        <td><?php echo $payment['Parking_Space_Name']; ?></td>
                                        <td>৳<?php echo number_format($payment['amount'], 2); ?></td>
                                        <td><?php echo ucfirst($payment['payment_method']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $paymentClass; ?>"><?php echo ucfirst($payment['payment_status']); ?></span>
                                        </td>
                                        <td><?php echo $paymentDate; ?></td>
                                        <td>
                                            <div class="flex gap-2">
                                                <button class="btn btn-sm btn-outline btn-info" onclick="viewPaymentDetails(<?php echo $payment['payment_id']; ?>)">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="11" cy="11" r="8"></circle>
                                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                                    </svg>
                                                </button>
                                                <?php if ($payment['payment_status'] === 'paid'): ?>
                                                <button class="btn btn-sm btn-outline btn-warning hover:btn-warning" onclick="refundPayment(<?php echo $payment['payment_id']; ?>)" title="Refund Payment">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 2v6h6"></path>
        <path d="M21 12A9 9 0 0 0 6 5.3L3 8"></path>
        <path d="M21 22v-6h-6"></path>
        <path d="M3 12a9 9 0 0 0 15 6.7l3-2.7"></path>
    </svg>
</button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                        endif;
                                    endforeach;
                                    
                                    // Now show pending payments from bookings that don't have payment records
                                    foreach ($payments as $payment):
                                        if (empty($payment['payment_id']) && $payment['booking_payment_status'] === 'pending'):
                                            $paymentDate = !empty($payment['booking_updated_at']) ? date('d M Y h:i A', strtotime($payment['booking_updated_at'])) : 'N/A';
                                    ?>
                                    <tr data-status="pending">
                                        <td>N/A</td>
                                        <td>N/A</td>
                                        <td>#<?php echo $payment['booking_id']; ?></td>
                                        <td><?php echo $payment['username']; ?></td>
                                        <td><?php echo $payment['Parking_Space_Name']; ?></td>
                                        <td>৳<?php echo number_format($payment['effective_amount'], 2); ?></td>
                                        <td>Not paid</td>
                                        <td>
                                            <span class="status-badge status-pending">Pending</span>
                                        </td>
                                        <td><?php echo $paymentDate; ?></td>
                                        <td>
                                            <div class="flex gap-2">
                                                <button class="btn btn-sm btn-outline btn-info" onclick="viewBookingDetails(<?php echo $payment['booking_id']; ?>)">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="11" cy="11" r="8"></circle>
                                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                        endif;
                                    endforeach;
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Vehicles Tab -->
                <div id="vehicles-tab" class="tab-content <?php echo $activeTab === 'vehicles' ? 'active' : ''; ?>">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-white">Vehicle Management</h2>
                        <div class="flex gap-4">
                            <div class="relative">
                                <input type="text" id="vehicle-search" placeholder="Search vehicles..." class="input input-bordered bg-gray-700 text-white w-64">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 absolute right-3 top-3 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                        <div class="data-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>License Plate</th>
                                        <th>Owner</th>
                                        <th>Owner Name</th>
                                        <th>Type</th>
                                        <th>Make</th>
                                        <th>Model</th>
                                        <th>Color</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                    <tr>
                                        <td><?php echo $vehicle['licensePlate']; ?></td>
                                        <td><?php echo $vehicle['username']; ?></td>
                                        <td><?php echo $vehicle['firstName'] . ' ' . $vehicle['lastName']; ?></td>
                                        <td><?php echo ucfirst($vehicle['vehicleType']); ?></td>
                                        <td><?php echo $vehicle['make']; ?></td>
                                        <td><?php echo $vehicle['model']; ?></td>
                                        <td><?php echo ucfirst($vehicle['color']); ?></td>
                                        <td>
                                            <div class="flex gap-2">
                                                <button class="btn btn-sm btn-outline btn-info" onclick="viewVehicleDetails('<?php echo $vehicle['licensePlate']; ?>')">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="11" cy="11" r="8"></circle>
                                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <!-- Revenue & Profit Tab -->
<div id="revenue-tab" class="tab-content <?php echo $activeTab === 'revenue' ? 'active' : ''; ?>">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-white">Revenue & Profit Analytics</h2>
        <div class="flex gap-4">
            <!-- Time Period Selector -->
            <select id="revenue-period-filter" class="select select-bordered bg-gray-700 text-white">
                <option value="today">Today</option>
                <option value="last_7_days" selected>Last 7 Days</option>
                <option value="last_30_days">Last 30 Days</option>
                <option value="this_month">This Month</option>
                <option value="this_year">This Year</option>
            </select>
            <button class="btn btn-primary" onclick="exportRevenueReport()">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>
                </svg>
                Export Report
            </button>
        </div>
    </div>
    
    <!-- Revenue Metrics Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6 mb-6">
        <!-- Total Revenue Card -->
        <div class="bg-gradient-to-br from-blue-500/20 to-blue-600/20 border border-blue-500/30 rounded-lg p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-blue-300 text-xs lg:text-sm mb-1">Total Revenue</p>
                    <h3 id="total-revenue" class="text-xl lg:text-2xl font-bold text-white">৳0</h3>
                    <p id="revenue-change" class="text-xs text-blue-400 mt-1">Loading...</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-blue-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                    </svg>
                </div>
            </div>
        </div>
        
        <!-- Platform Profit Card -->
        <div class="bg-gradient-to-br from-emerald-500/20 to-emerald-600/20 border border-emerald-500/30 rounded-lg p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-emerald-300 text-xs lg:text-sm mb-1">Platform Profit</p>
                    <h3 id="platform-profit" class="text-xl lg:text-2xl font-bold text-white">৳0</h3>
                    <p id="profit-margin" class="text-xs text-emerald-400 mt-1">Loading...</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-emerald-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 7a4 4 0 1 1 8 0 4 4 0 0 1-8 0M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
            </div>
        </div>
        
        <!-- Owner Earnings Card -->
        <div class="bg-gradient-to-br from-purple-500/20 to-purple-600/20 border border-purple-500/30 rounded-lg p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-purple-300 text-xs lg:text-sm mb-1">Owner Earnings</p>
                    <h3 id="owner-earnings" class="text-xl lg:text-2xl font-bold text-white">৳0</h3>
                    <p id="owner-percentage" class="text-xs text-purple-400 mt-1">Loading...</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-purple-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-purple-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 20h5v-2a3 3 0 0 0-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 0 1 5.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 0 1 9.288 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0zm6 3a2 2 0 1 1-4 0 2 2 0 0 1 4 0zM7 10a2 2 0 1 1-4 0 2 2 0 0 1 4 0z"/>
                    </svg>
                </div>
            </div>
        </div>
        
        <!-- Pending Revenue Card -->
        <div class="bg-gradient-to-br from-amber-500/20 to-amber-600/20 border border-amber-500/30 rounded-lg p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-amber-300 text-xs lg:text-sm mb-1">Pending Revenue</p>
                    <h3 id="pending-revenue" class="text-xl lg:text-2xl font-bold text-white">৳0</h3>
                    <p id="pending-count" class="text-xs text-amber-400 mt-1">Loading...</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-amber-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12,6 12,12 16,14"/>
                    </svg>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Charts Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Revenue Trends Chart -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h4 class="text-lg font-semibold text-white mb-4">Revenue Trends</h4>
            <div class="h-64">
                <canvas id="revenueTrendsChart"></canvas>
            </div>
        </div>
        
        <!-- Payment Methods Chart -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h4 class="text-lg font-semibold text-white mb-4">Revenue by Payment Method</h4>
            <div class="h-64">
                <canvas id="paymentMethodsChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Top Revenue Garages Table -->
    <div class="bg-gray-800 rounded-lg p-6">
        <h4 class="text-lg font-semibold text-white mb-4">Top Revenue Generating Garages</h4>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-700">
                        <th class="text-left py-3 text-gray-300">Garage</th>
                        <th class="text-left py-3 text-gray-300">Owner</th>
                        <th class="text-right py-3 text-gray-300">Revenue</th>
                        <th class="text-right py-3 text-gray-300">Profit</th>
                        <th class="text-right py-3 text-gray-300">Bookings</th>
                        <th class="text-right py-3 text-gray-300">Avg/Booking</th>
                    </tr>
                </thead>
                <tbody id="top-garages-table">
                    <tr>
                        <td colspan="6" class="text-center py-8 text-white/60">Loading...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <!-- Edit Garage Modal -->
    <div id="editGarageModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 class="text-xl font-bold text-white mb-4">Edit Garage Price</h3>
            <form id="editGarageForm">
                <input type="hidden" id="edit_garage_id" name="garage_id">
                <input type="hidden" name="action" value="update_garage">
                
                <div class="form-control mb-4">
                    <label class="label">
                        <span class="label-text text-white">Price per Hour (৳)</span>
                    </label>
                    <input type="number" id="edit_price" name="price" class="input input-bordered bg-gray-700 text-white" min="0" step="0.01" required>
                </div>
                
                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" class="btn btn-outline border-white/20 text-white" onclick="closeEditGarageModal()">Cancel</button>
                    <button type="submit" class="btn bg-primary hover:bg-primary-dark text-white border-none">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <div id="viewLocationModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-2xl">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-white" id="location_title">Garage Location</h3>
            <button onclick="closeLocationModal()" class="text-white/70 hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div id="mapContainer" style="height: 400px; width: 100%; border-radius: 0.5rem;"></div>
        <div class="flex justify-end mt-4">
            <button class="btn btn-outline border-white/20 text-white" onclick="closeLocationModal()">Close</button>
        </div>
    </div>
</div>
    
    <!-- Add/Edit User Modal -->
    <div id="userModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg p-6 w-full max-w-2xl">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white" id="userModalTitle">Add New User</h3>
                <button onclick="closeUserModal()" class="text-white/70 hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form id="userForm">
                <input type="hidden" id="user_action" name="action" value="add_user">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-white">Username</span>
                        </label>
                        <input type="text" id="username" name="username" class="input input-bordered bg-gray-700 text-white" required>
                    </div>
                    
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-white">Password</span>
                        </label>
                        <input type="password" id="password" name="password" class="input input-bordered bg-gray-700 text-white" required>
                    </div>
                    
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-white">First Name</span>
                        </label>
                        <input type="text" id="firstName" name="firstName" class="input input-bordered bg-gray-700 text-white">
                    </div>
                    
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-white">Last Name</span>
                        </label>
                        <input type="text" id="lastName" name="lastName" class="input input-bordered bg-gray-700 text-white">
                    </div>
                    
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-white">Email</span>
                        </label>
                        <input type="email" id="email" name="email" class="input input-bordered bg-gray-700 text-white">
                    </div>
                    
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-white">Phone</span>
                        </label>
                        <input type="text" id="phone" name="phone" class="input input-bordered bg-gray-700 text-white">
                    </div>
                    
                    <div class="form-control md:col-span-2">
                        <label class="label">
                            <span class="label-text text-white">Address</span>
                        </label>
                        <input type="text" id="address" name="address" class="input input-bordered bg-gray-700 text-white">
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" class="btn btn-outline border-white/20 text-white" onclick="closeUserModal()">Cancel</button>
                    <button type="submit" class="btn bg-primary hover:bg-primary-dark text-white border-none">Save</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Garage Owner Details Modal -->
    <div id="ownerDetailsModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg p-6 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Garage Owner Details</h3>
                <button onclick="closeOwnerDetailsModal()" class="text-white/70 hover:text-white">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
    </svg>
</button>
            </div>
            
            <div id="ownerDetailsContent" class="text-white">
                <!-- Content will be loaded dynamically -->
                <div class="flex justify-center items-center h-40">
                    <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
                </div>
            </div>
            
            <div class="flex justify-end mt-6">
                <button class="btn btn-outline border-white/20 text-white" onclick="closeOwnerDetailsModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- View Booking Details Modal -->
    <div id="bookingDetailsModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg p-6 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Booking Details</h3>
                <button onclick="closeBookingDetailsModal()" class="text-white/70 hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            
            <div id="bookingDetailsContent" class="text-white">
                <!-- Content will be loaded dynamically -->
                <div class="flex justify-center items-center h-40">
                    <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
                </div>
            </div>
            
            <div class="flex justify-end mt-6 gap-3">
                <div id="bookingActionButtons" class="hidden">
                    <!-- Action buttons will be added dynamically -->
                </div>
                <button class="btn btn-outline border-white/20 text-white" onclick="closeBookingDetailsModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- View Payment Details Modal -->
    <div id="paymentDetailsModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg p-6 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Payment Details</h3>
                <button onclick="closePaymentDetailsModal()" class="text-white/70 hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            
            <div id="paymentDetailsContent" class="text-white">
                <!-- Content will be loaded dynamically -->
                <div class="flex justify-center items-center h-40">
                    <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
                </div>
            </div>
            
            <div class="flex justify-end mt-6 gap-3">
                <div id="paymentActionButtons" class="hidden">
                    <!-- Action buttons will be added dynamically -->
                </div>
                <button class="btn btn-outline border-white/20 text-white" onclick="closePaymentDetailsModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- View Vehicle Details Modal -->
    <div id="vehicleDetailsModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg p-6 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Vehicle Details</h3>
                <button onclick="closeVehicleDetailsModal()" class="text-white/70 hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            
            <div id="vehicleDetailsContent" class="text-white">
                <!-- Content will be loaded dynamically -->
                <div class="flex justify-center items-center h-40">
                    <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
                </div>
            </div>
            
            <div class="flex justify-end mt-6">
                <button class="btn btn-outline border-white/20 text-white" onclick="closeVehicleDetailsModal()">Close</button>
            </div>
        </div>
    </div>
     <!-- Garage Reviews Modal -->
<div id="garageReviewsModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-white" id="reviewsModalTitle">Garage Reviews</h3>
            <button onclick="closeGarageReviewsModal()" class="text-white/70 hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        
        <div id="garageReviewsContent" class="text-white">
            <!-- Content will be loaded dynamically -->
            <div class="flex justify-center items-center h-40">
                <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
            </div>
        </div>
        
        <div class="flex justify-end mt-6">
            <button class="btn btn-outline border-white/20 text-white" onclick="closeGarageReviewsModal()">Close</button>
        </div>
    </div>
</div>                                   
    <!-- Footer -->
    <footer class="bg-gray-800 py-6">
        <div class="container mx-auto px-4 text-center">
            <p class="text-white/70">&copy; <?php echo date('Y'); ?> Car Parking System. All rights reserved.</p>
        </div>
    </footer>
    
    <script>
    // Global variables for map
let leafletMap = null;
let leafletMarker = null;

// Function to view garage location on map
function viewGarageLocation(lat, lng, name) {
    console.log("Button clicked with:", lat, lng, name);
    
    // Show the modal first
    document.getElementById('viewLocationModal').classList.remove('hidden');
    
    // Convert coordinates to numbers
    lat = parseFloat(lat);
    lng = parseFloat(lng);
    
    if (isNaN(lat) || isNaN(lng)) {
        console.error("Invalid coordinates:", lat, lng);
        return;
    }
    
    // Initialize map after a short delay to ensure modal is visible
    setTimeout(function() {
        try {
            // Create map if not exists or reset
            if (window.leafletMap) {
                window.leafletMap.remove();
            }
            
            // Initialize map
            window.leafletMap = L.map('mapContainer').setView([lat, lng], 15);
            
            // Add tile layer
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(window.leafletMap);
            
            // Add marker
            L.marker([lat, lng]).addTo(window.leafletMap)
                .bindPopup("<b>" + name + "</b><br>Location: " + lat.toFixed(6) + ", " + lng.toFixed(6))
                .openPopup();
            
            console.log("Map initialized successfully");
        } catch (error) {
            console.error("Error initializing map:", error);
        }
    }, 300);
}

// Close location modal
function closeLocationModal() {
    document.getElementById('viewLocationModal').classList.add('hidden');
}
        
        // Function to show tab content based on URL parameter
        function showTabContent() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab') || 'dashboard';
            
            // Hide all tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tab + '-tab').classList.add('active');
        }
        
        // Call showTabContent on page load
        document.addEventListener('DOMContentLoaded', function() {
            showTabContent();
            
            // Initialize charts for dashboard
            if (document.getElementById('bookingStatusChart')) {
                initBookingStatusChart();
            }
            
            if (document.getElementById('revenueChart')) {
                initRevenueChart();
            }
            if (document.getElementById('revenueProfitChart')) {
        initRevenueProfitChart();
    }
            // Add event listeners for search inputs
            document.querySelectorAll('input[id$="-search"]').forEach(input => {
                input.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const tableId = this.id.split('-')[0];
                    
                    document.querySelectorAll(`#${tableId}-tab table tbody tr`).forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(searchTerm) ? '' : 'none';
                    });
                });
            });
            
            // Add event listeners for status filters
            document.querySelectorAll('select[id$="-status-filter"]').forEach(select => {
                select.addEventListener('change', function() {
                    const status = this.value;
                    const tableId = this.id.split('-')[0];
                    
                    document.querySelectorAll(`#${tableId}-tab table tbody tr`).forEach(row => {
                        if (status === 'all') {
                            row.style.display = '';
                        } else {
                            const rowStatus = row.getAttribute('data-status');
                            row.style.display = rowStatus === status ? '' : 'none';
                        }
                    });
                });
            });
            
            // Add event listener for edit garage form
            document.getElementById('editGarageForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    // Fix the field name mapping
    if (formData.has('price')) {
        formData.set('price_per_hour', formData.get('price'));
        formData.delete('price');
    }
    
    fetch('admin.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            closeEditGarageModal();
            location.reload(); // Refresh to show updated price
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error updating garage price');
    });
});
            // Add event listener for user form
            document.getElementById('userForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        closeUserModal();
                        window.location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            });
        });
        
        // Function to initialize booking status chart
        function initBookingStatusChart() {
            const ctx = document.getElementById('bookingStatusChart').getContext('2d');
            
            // Sample data - in a real application, you would get this from the server
            const data = {
                labels: ['Upcoming', 'Active', 'Completed', 'Cancelled'],
                datasets: [{
                    data: [
                        <?php 
                        $upcomingCount = 0;
                        $activeCount = 0;
                        $completedCount = 0;
                        $cancelledCount = 0;
                        
                        foreach ($bookings as $booking) {
                            switch ($booking['status']) {
                                case 'upcoming':
                                    $upcomingCount++;
                                    break;
                                case 'active':
                                    $activeCount++;
                                    break;
                                case 'completed':
                                    $completedCount++;
                                    break;
                                case 'cancelled':
                                    $cancelledCount++;
                                    break;
                            }
                        }
                        
                        echo $upcomingCount . ', ' . $activeCount . ', ' . $completedCount . ', ' . $cancelledCount;
                        ?>
                    ],
                    backgroundColor: [
                        'rgba(59, 130, 246, 0.7)',
                        'rgba(16, 185, 129, 0.7)',
                        'rgba(243, 156, 18, 0.7)',
                        'rgba(239, 68, 68, 0.7)'
                    ],
                    borderColor: [
                        'rgba(59, 130, 246, 1)',
                        'rgba(16, 185, 129, 1)',
                        'rgba(243, 156, 18, 1)',
                        'rgba(239, 68, 68, 1)'
                    ],
                    borderWidth: 1
                }]
            };
            
            new Chart(ctx, {
                type: 'doughnut',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                color: 'white'
                            }
                        }
                    }
                }
            });
        }
        
        // Function to initialize revenue chart
        function initRevenueChart() {
            const ctx = document.getElementById('revenueChart').getContext('2d');
            
            // Sample data - in a real application, you would get this from the server
            const data = {
                labels: ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6', 'Today'],
                datasets: [{
                    label: 'Revenue',
                    data: [150, 200, 175, 300, 225, 250, <?php echo $stats['today_revenue']; ?>],
                    backgroundColor: 'rgba(243, 156, 18, 0.2)',
                    borderColor: 'rgba(243, 156, 18, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                }]
            };
            
            new Chart(ctx, {
                type: 'line',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)'
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: 'white'
                            }
                        }
                    }
                }
            });
        }
        
        // Function to edit garage price
        function editGarage(garageId, price) {
            document.getElementById('edit_garage_id').value = garageId;
            document.getElementById('edit_price').value = price;
            document.getElementById('editGarageModal').classList.remove('hidden');
        }
        
        // Function to close edit garage modal
        function closeEditGarageModal() {
            document.getElementById('editGarageModal').classList.add('hidden');
        }
        
        
        
        // Function to delete user
        function deleteUser(username) {
            if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
                const formData = new FormData();
                formData.append('action', 'delete_user');
                formData.append('username', username);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        window.location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
        
        // Function to verify garage owner
        function verifyOwner(ownerId) {
    if (confirm('Are you sure you want to verify this garage owner?')) {
        const formData = new FormData();
        formData.append('action', 'verify_owner');
        formData.append('owner_id', ownerId);
        
        // Check if it's a user-owner that needs registration
        if (ownerId.startsWith('U_owner_')) {
            // Extract username from the user-owner ID
            const username = ownerId.replace('U_owner_', '');
            formData.append('username', username);
            formData.append('register_first', 'true');
        }
        
        fetch('admin.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.reload();
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }
}


function verifyGarage(garageId) {
    console.log("Verifying garage:", garageId); // ডিবাগিং লগ
    
    if (confirm('Are you sure you want to verify this garage?')) {
        const formData = new FormData();
        formData.append('action', 'verify_garage');
        formData.append('garage_id', garageId);
        
        // আরেকটি ডিবাগিং লগ
        console.log("Sending AJAX request with:", formData.get('action'), formData.get('garage_id'));
        
        fetch('admin.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log("Raw response:", response); // ডিবাগিং লগ
            return response.json();
        })
        .then(data => {
            console.log("Response data:", data); // ডিবাগিং লগ
            if (data.success) {
                alert(data.message);
                window.location.reload(); // পেজ রিলোড
            } else {
                alert('Error: ' + (data.message || 'Unknown error occurred'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please check console for details.');
        });
    }
}
        
        // Function to register user as an official garage owner
function registerAsOwner(username) {
    if (confirm('আপনি কি নিশ্চিত যে আপনি এই ইউজারকে অফিসিয়াল গ্যারেজ ওনার হিসেবে রেজিস্টার করতে চান?')) {
        const formData = new FormData();
        formData.append('action', 'register_as_owner');
        formData.append('username', username);
        
        fetch('admin.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status);
            // Return the text first to see what's wrong
            return response.text();
        })
        .then(text => {
            console.log('Raw response from server:', text);
            // Now try to parse it as JSON
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error:', e);
                alert('Server response is not valid JSON. Check console for details.');
                throw e;
            }
        })
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.reload();
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Error details:', error);
            alert('একটি ত্রুটি ঘটেছে। অনুগ্রহ করে আবার চেষ্টা করুন। Details: ' + error.message);
        });
    }
}
        
        // Function to cancel booking
        function cancelBooking(bookingId) {
            if (confirm('Are you sure you want to cancel this booking?')) {
                const formData = new FormData();
                formData.append('action', 'cancel_booking');
                formData.append('booking_id', bookingId);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        window.location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
        
        // Function to refund payment
        function refundPayment(paymentId) {
            if (confirm('Are you sure you want to refund this payment?')) {
                const formData = new FormData();
                formData.append('action', 'refund_payment');
                formData.append('payment_id', paymentId);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        window.location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
        
        // Functions for user management
        function openAddUserModal() {
            // Reset the form
            document.getElementById('userForm').reset();
            document.getElementById('user_action').value = 'add_user';
            document.getElementById('userModalTitle').textContent = 'Add New User';
            
            // Enable username field for new users
            document.getElementById('username').removeAttribute('readonly');
            
            // Show the modal
            document.getElementById('userModal').classList.remove('hidden');
        }
        
        function closeUserModal() {
            document.getElementById('userModal').classList.add('hidden');
        }
        
// Function to view user details (NEW - this was missing)
function viewUserDetails(username) {
    console.log('Opening user details for:', username);
    
    // Create modal if it doesn't exist
    if (!document.getElementById('userDetailsModal')) {
        createUserDetailsModal();
    }
    
    // Show loading state
    document.getElementById('userDetailsContent').innerHTML = `
        <div class="flex justify-center items-center h-40">
            <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-blue-500"></div>
            <p class="ml-4 text-white">Loading user details...</p>
        </div>
    `;
    
    // Show the modal
    document.getElementById('userDetailsModal').classList.remove('hidden');
    
    // Fetch user details
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `action=get_user&username=${encodeURIComponent(username)}`
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        console.log('User details raw response:', text);
        
        // Clean response
        let cleanText = text.trim();
        const jsonStart = cleanText.indexOf('{');
        const jsonEnd = cleanText.lastIndexOf('}') + 1;
        
        if (jsonStart !== -1 && jsonEnd > jsonStart) {
            cleanText = cleanText.substring(jsonStart, jsonEnd);
        }
        
        let data;
        try {
            data = JSON.parse(cleanText);
        } catch (e) {
            console.error('JSON parsing error:', e);
            throw new Error('Invalid JSON response from server');
        }
        
        if (data.success) {
            displayUserDetails(data.data);
        } else {
            document.getElementById('userDetailsContent').innerHTML = `
                <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                    <p><strong>Error:</strong> ${data.message}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error fetching user details:', error);
        document.getElementById('userDetailsContent').innerHTML = `
            <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                <p><strong>Error:</strong> ${error.message}</p>
                <button onclick="viewUserDetails('${username}')" class="mt-3 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                    Retry
                </button>
            </div>
        `;
    });
}

// Function to display user details (NEW - this was missing)
function displayUserDetails(user) {
    const verifiedBadge = user.status === 'verified' ? 
        '<span class="px-2 py-1 bg-green-600 text-white text-xs rounded">Verified</span>' : 
        '<span class="px-2 py-1 bg-red-600 text-white text-xs rounded">Unverified</span>';
    
    const ownerBadge = user.is_garage_owner ? 
        '<span class="px-2 py-1 bg-blue-600 text-white text-xs rounded">Garage Owner</span>' : 
        '<span class="px-2 py-1 bg-gray-600 text-white text-xs rounded">Regular User</span>';
    
    let detailsHTML = `
        <div class="space-y-6">
            <div class="flex justify-between items-start">
                <div>
                    <h4 class="text-xl font-bold text-white">${user.firstName || ''} ${user.lastName || ''}</h4>
                    <p class="text-gray-400">@${user.username}</p>
                </div>
                <div class="text-right space-y-1">
                    ${verifiedBadge}
                    ${ownerBadge}
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-4">
                    <h5 class="text-lg font-semibold text-blue-400">Contact Information</h5>
                    
                    <div>
                        <p class="text-gray-400 text-sm">Email:</p>
                        <p class="text-white">${user.email || 'Not provided'}</p>
                    </div>
                    
                    <div>
                        <p class="text-gray-400 text-sm">Phone:</p>
                        <p class="text-white">${user.phone || 'Not provided'}</p>
                    </div>
                    
                    <div>
                        <p class="text-gray-400 text-sm">Address:</p>
                        <p class="text-white">${user.address || 'Not provided'}</p>
                    </div>
                    
                    <div>
                        <p class="text-gray-400 text-sm">Current Points:</p>
                        <div class="flex items-center gap-2">
                            <p class="text-green-400 text-xl font-bold">${user.points || 0}</p>
                            <button onclick="openPointsModal('${user.username}', ${user.points || 0})" class="btn btn-xs btn-outline btn-warning">
                                Edit Points
                            </button>
                            <button onclick="viewPointsHistory('${user.username}')" class="btn btn-xs btn-outline btn-info">
                                View History
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="space-y-4">
                    <h5 class="text-lg font-semibold text-blue-400">Account Status</h5>
                    
                    <div>
                        <p class="text-gray-400 text-sm">Account Created:</p>
                        <p class="text-white">${user.created_at ? new Date(user.created_at).toLocaleDateString() : 'Unknown'}</p>
                    </div>
                    
                    <div>
                        <p class="text-gray-400 text-sm">Last Login:</p>
                        <p class="text-white">${user.last_login ? new Date(user.last_login).toLocaleString() : 'Never logged in'}</p>
                    </div>
                    
                    <div>
                        <p class="text-gray-400 text-sm">Account Status:</p>
                        <p class="text-white">${user.status || 'Active'}</p>
                    </div>
                    
                    ${user.is_garage_owner ? `
                    <div>
                        <p class="text-gray-400 text-sm">Owner ID:</p>
                        <p class="text-white">${user.owner_id}</p>
                    </div>
                    
                    <div>
                        <p class="text-gray-400 text-sm">Owner Verification:</p>
                        <p class="text-white">${user.owner_verified ? 'Verified Owner' : 'Unverified Owner'}</p>
                    </div>
                    
                    <div>
                        <p class="text-gray-400 text-sm">Owner Type:</p>
                        <p class="text-white">${user.is_official_owner ? 'Official Owner' : 'Dual User'}</p>
                    </div>
                    ` : ''}
                </div>
            </div>
    `;
    
    // Add statistics if available
    if (user.statistics) {
        detailsHTML += `
            <div class="mt-6">
                <h5 class="text-lg font-semibold text-blue-400 mb-3">User Statistics</h5>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-gray-700/50 rounded-lg p-3 text-center">
                        <p class="text-gray-400 text-sm">Total Bookings</p>
                        <p class="text-white text-xl font-bold">${user.statistics.total_bookings}</p>
                    </div>
                    <div class="bg-gray-700/50 rounded-lg p-3 text-center">
                        <p class="text-gray-400 text-sm">Completed</p>
                        <p class="text-green-400 text-xl font-bold">${user.statistics.completed_bookings}</p>
                    </div>
                    <div class="bg-gray-700/50 rounded-lg p-3 text-center">
                        <p class="text-gray-400 text-sm">Owned Garages</p>
                        <p class="text-blue-400 text-xl font-bold">${user.statistics.owned_garages}</p>
                    </div>
                    <div class="bg-gray-700/50 rounded-lg p-3 text-center">
                        <p class="text-gray-400 text-sm">Points Earned</p>
                        <p class="text-yellow-400 text-xl font-bold">${user.statistics.total_points_earned || 0}</p>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Add action buttons
    detailsHTML += `
        <div class="mt-6 flex justify-end gap-3">
            <button onclick="editUser('${user.username}')" class="btn btn-outline btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                </svg>
                Edit User
            </button>
            ${user.status !== 'verified' ? `
                <button onclick="verifyUser('${user.username}')" class="btn btn-outline btn-success">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20,6 9,17 4,12"></polyline>
                    </svg>
                    Verify User
                </button>
            ` : ''}
            <button onclick="deleteUser('${user.username}')" class="btn btn-outline btn-error">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3,6 5,6 21,6"></polyline>
                    <path d="M19,6v14a2,2 0 0,1-2,2H7a2,2 0 0,1-2-2V6m3,0V4a2,2 0 0,1,2-2h4a2,2 0 0,1,2,2v2"></path>
                </svg>
                Delete User
            </button>
        </div>
    `;
    
    detailsHTML += '</div>';
    
    document.getElementById('userDetailsContent').innerHTML = detailsHTML;
}

// Function to create user details modal (NEW - this was missing)
function createUserDetailsModal() {
    const modalHTML = `
        <div id="userDetailsModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
            <div class="bg-gray-800 rounded-lg p-6 w-full max-w-5xl max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-bold text-white">User Details</h3>
                    <button onclick="closeUserDetailsModal()" class="text-white/70 hover:text-white">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                
                <div id="userDetailsContent" class="text-white">
                    <!-- Content will be loaded dynamically -->
                </div>
                
                <div class="flex justify-end mt-6">
                    <button class="btn btn-outline border-white/20 text-white" onclick="closeUserDetailsModal()">Close</button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    console.log('User details modal created successfully');
}

// Function to close user details modal (NEW - this was missing)
function closeUserDetailsModal() {
    const modal = document.getElementById('userDetailsModal');
    if (modal) {
        modal.classList.add('hidden');
    }
}

// UPDATED: Enhanced editUser function (improve your existing one)
function editUser(username) {
    console.log('Editing user:', username);
    
    // Set action to update
    document.getElementById('user_action').value = 'update_user';
    document.getElementById('userModalTitle').textContent = 'Edit User';
    
    // Disable username field for existing users
    document.getElementById('username').setAttribute('readonly', 'readonly');
    
    // Close user details modal if open
    closeUserDetailsModal();
    
    // Show loading in form
    document.getElementById('userForm').reset();
    document.getElementById('username').value = username;
    
    // Get user data
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `action=get_user&username=${encodeURIComponent(username)}`
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        console.log('Edit user response:', text);
        
        // Clean response
        let cleanText = text.trim();
        const jsonStart = cleanText.indexOf('{');
        const jsonEnd = cleanText.lastIndexOf('}') + 1;
        
        if (jsonStart !== -1 && jsonEnd > jsonStart) {
            cleanText = cleanText.substring(jsonStart, jsonEnd);
        }
        
        let data;
        try {
            data = JSON.parse(cleanText);
        } catch (e) {
            throw new Error('Invalid JSON response');
        }
        
        if (data.success) {
            // Fill the form with user data
            const user = data.data;
            document.getElementById('username').value = user.username;
            document.getElementById('password').value = user.password || '';
            document.getElementById('firstName').value = user.firstName || '';
            document.getElementById('lastName').value = user.lastName || '';
            document.getElementById('email').value = user.email || '';
            document.getElementById('phone').value = user.phone || '';
            document.getElementById('address').value = user.address || '';
            
            // Show the modal
            document.getElementById('userModal').classList.remove('hidden');
        } else {
            alert('Error loading user data: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while loading user data: ' + error.message);
    });
}

// Test function for user details
function testUserDetails() {
    console.log('Testing user details...');
    
    // Test with an existing user
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_user&username=saba'
    })
    .then(response => response.text())
    .then(text => {
        console.log('Test response:', text);
        try {
            const data = JSON.parse(text);
            console.log('Test parsed data:', data);
            if (data.success) {
                alert('Test successful! User details loaded correctly.');
                viewUserDetails('saba'); // Open the modal to test
            } else {
                alert('Test failed: ' + data.message);
            }
        } catch (e) {
            console.error('Test parse error:', e);
            alert('Test failed: Invalid JSON response');
        }
    })
    .catch(error => {
        console.error('Test error:', error);
        alert('Test failed: ' + error.message);
    });
}

// Initialize when DOM loads
document.addEventListener('DOMContentLoaded', function() {
    console.log('User management functions initialized');
    
    // Test if all required elements exist
    const requiredElements = ['userModal', 'userForm', 'username', 'password'];
    const missingElements = requiredElements.filter(id => !document.getElementById(id));
    
    if (missingElements.length > 0) {
        console.warn('Missing user management elements:', missingElements);
    } else {
        console.log('All user management elements found ✓');
    }
});
// Helper functions for owner details tabs
function generateOwnerProfileHTML(owner) {
    const verifiedBadge = owner.is_verified ? 
        `<span class="status-badge status-verified"></span>` : 
        `<span class="status-badge status-unverified"></span>`;
        
    const registrationDate = new Date(owner.registration_date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
    
    const lastLogin = owner.last_login ? new Date(owner.last_login).toLocaleString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    }) : 'Never';
    
    return `
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="detail-section">
                <h4 class="text-lg font-semibold text-primary mb-3">Owner Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="detail-item">
                        <p class="detail-label">Owner ID</p>
                        <p class="detail-value">${owner.owner_id}</p>
                    </div>
                    <div class="detail-item">
                        <p class="detail-label">Username</p>
                        <p class="detail-value">${owner.username}</p>
                    </div>
                    <div class="detail-item">
                        <p class="detail-label">Status</p>
                        <p class="detail-value">${verifiedBadge}</p>
                    </div>
                    <div class="detail-item">
                        <p class="detail-label">Account Status</p>
                        <p class="detail-value capitalize">${owner.account_status}</p>
                    </div>
                    <div class="detail-item">
                        <p class="detail-label">Registration Date</p>
                        <p class="detail-value">${registrationDate}</p>
                    </div>
                    <div class="detail-item">
                        <p class="detail-label">Last Login</p>
                        <p class="detail-value">${lastLogin}</p>
                    </div>
                </div>
                
                <!-- Add actions buttons -->
                <div class="mt-4 flex flex-wrap gap-2">
                    <button class="btn btn-sm btn-primary" onclick="resetOwnerPassword('${owner.owner_id}')">
                        Reset Password
                    </button>
                    <button class="btn btn-sm btn-info" onclick="openMessageModal('${owner.owner_id}')">
                        Send Message
                    </button>
                    <button class="btn btn-sm btn-warning" onclick="openCommissionModal('${owner.owner_id}', 10)">
                        Set Commission
                    </button>
                    <div class="form-control mt-2">
                        <label class="label cursor-pointer flex justify-start gap-2">
                            <input type="checkbox" class="toggle toggle-success" onchange="updateFeaturedStatus('${owner.owner_id}', this.checked)" ${owner.is_featured ? 'checked' : ''}>
                            <span class="label-text text-white">Featured Owner</span>
                        </label>
                    </div>
                    <button class="btn btn-sm btn-error mt-2" onclick="openDeleteOwnerModal('${owner.owner_id}')">
                        Delete Account
                    </button>
                </div>
            </div>
            
            <div class="detail-section">
                <h4 class="text-lg font-semibold text-primary mb-3">Personal Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="detail-item">
                        <p class="detail-label">Name</p>
                        <p class="detail-value">${owner.firstName} ${owner.lastName}</p>
                    </div>
                    <div class="detail-item">
                        <p class="detail-label">Email</p>
                        <p class="detail-value">${owner.email || 'Not provided'}</p>
                    </div>
                    <div class="detail-item">
                        <p class="detail-label">Phone</p>
                        <p class="detail-value">${owner.phone || 'Not provided'}</p>
                    </div>
                    <div class="detail-item md:col-span-2">
                        <p class="detail-label">Address</p>
                        <p class="detail-value">${owner.address || 'Not provided'}</p>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function generateOwnerGaragesHTML(owner) {
    if (!owner.garages || owner.garages.length === 0) {
        return `
            <div class="detail-section">
                <h4 class="text-lg font-semibold text-primary mb-3">Owned Garages</h4>
                <p class="text-white/70">No garages registered yet.</p>
            </div>
        `;
    }
    
    let garageListing = `
        <div class="detail-section">
            <h4 class="text-lg font-semibold text-primary mb-3">Owned Garages</h4>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr>
                            <th class="text-left px-4 py-2 bg-gray-700">Name</th>
                            <th class="text-left px-4 py-2 bg-gray-700">Address</th>
                            <th class="text-left px-4 py-2 bg-gray-700">Type</th>
                            <th class="text-left px-4 py-2 bg-gray-700">Capacity</th>
                            <th class="text-left px-4 py-2 bg-gray-700">Price/Hour</th>
                        </tr>
                    </thead>
                    <tbody>
    `;
    
    owner.garages.forEach(garage => {
        garageListing += `
            <tr>
                <td class="px-4 py-2 border-t border-gray-700">${garage.Parking_Space_Name}</td>
                <td class="px-4 py-2 border-t border-gray-700">${garage.Parking_Lot_Address}</td>
                <td class="px-4 py-2 border-t border-gray-700">${garage.Parking_Type}</td>
                <td class="px-4 py-2 border-t border-gray-700">${garage.Parking_Capacity}</td>
                <td class="px-4 py-2 border-t border-gray-700">৳${garage.PriceperHour}</td>
            </tr>
        `;
    });
    
    garageListing += `
                    </tbody>
                </table>
            </div>
        </div>
    `;
    
    return garageListing;
}

function showOwnerTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.owner-tab-content').forEach(tab => {
        tab.classList.add('hidden');
    });
    
    // Deactivate all tab buttons
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('tab-active');
    });
    
    // Show selected tab
    document.getElementById(`${tabName}-tab`).classList.remove('hidden');
    
    // Activate selected tab button
    document.querySelector(`.tab[onclick="showOwnerTab('${tabName}')"]`).classList.add('tab-active');
}
        
        // Functions for booking details
        function viewBookingDetails(bookingId) {
    const formData = new FormData();
    formData.append('action', 'get_booking_details');  // ✅ Fixed: Changed from 'get_booking' to 'get_booking_details'
    formData.append('booking_id', bookingId);
    
    // Show loading state
    document.getElementById('bookingDetailsContent').innerHTML = `
        <div class="flex justify-center items-center h-40">
            <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
        </div>
    `;
    
    // Hide action buttons initially
    document.getElementById('bookingActionButtons').classList.add('hidden');
    
    // Show the modal
    document.getElementById('bookingDetailsModal').classList.remove('hidden');
    
    fetch('admin.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const booking = data.data;
            
            // Determine status classes
            let statusClass = '';
            switch (booking.status) {
                case 'upcoming':
                    statusClass = 'bg-blue-500/20 text-blue-400 border border-blue-500/30';
                    break;
                case 'active':
                    statusClass = 'bg-green-500/20 text-green-400 border border-green-500/30';
                    break;
                case 'completed':
                    statusClass = 'bg-gray-500/20 text-gray-400 border border-gray-500/30';
                    break;
                case 'cancelled':
                    statusClass = 'bg-red-500/20 text-red-400 border border-red-500/30';
                    break;
            }
            
            let paymentClass = '';
            switch (booking.payment_status) {
                case 'paid':
                    paymentClass = 'bg-green-500/20 text-green-400 border border-green-500/30';
                    break;
                case 'pending':
                    paymentClass = 'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30';
                    break;
                case 'refunded':
                    paymentClass = 'bg-purple-500/20 text-purple-400 border border-purple-500/30';
                    break;
            }
            
            // Format dates
            const bookingDate = new Date(booking.booking_date).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            const bookingTime = new Date(`${booking.booking_date}T${booking.booking_time}`).toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit'
            });
            
            const createdAt = new Date(booking.created_at).toLocaleString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            const updatedAt = new Date(booking.updated_at).toLocaleString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            // Build payment info
            let paymentInfo = '';
            if (booking.payment_amount) {
                paymentInfo = `
                    <div class="bg-gray-700/50 p-4 rounded-lg">
                        <h4 class="text-lg font-semibold text-white mb-3">Payment Information</h4>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-gray-400 text-sm">Amount</p>
                                <p class="text-white font-medium">৳${booking.payment_amount}</p>
                            </div>
                            <div>
                                <p class="text-gray-400 text-sm">Method</p>
                                <p class="text-white font-medium capitalize">${booking.payment_method || 'N/A'}</p>
                            </div>
                            <div>
                                <p class="text-gray-400 text-sm">Transaction ID</p>
                                <p class="text-white font-medium">${booking.transaction_id || 'N/A'}</p>
                            </div>
                            <div>
                                <p class="text-gray-400 text-sm">Status</p>
                                <span class="px-2 py-1 text-xs rounded-full ${paymentClass}">${booking.payment_status_detail || booking.payment_status}</span>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            // Build the complete booking details HTML
            const bookingDetailsHTML = `
                <div class="space-y-6">
                    <!-- Header -->
                    <div class="border-b border-gray-600 pb-4">
                        <h3 class="text-xl font-bold text-white">Booking #${booking.id}</h3>
                        <div class="flex items-center gap-3 mt-2">
                            <span class="px-3 py-1 text-sm rounded-full ${statusClass}">${booking.status.charAt(0).toUpperCase() + booking.status.slice(1)}</span>
                            <span class="px-3 py-1 text-sm rounded-full ${paymentClass}">${booking.payment_status.charAt(0).toUpperCase() + booking.payment_status.slice(1)}</span>
                        </div>
                    </div>
                    
                    <!-- Booking Information -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-gray-700/50 p-4 rounded-lg">
                            <h4 class="text-lg font-semibold text-white mb-3">Booking Details</h4>
                            <div class="space-y-3">
                                <div>
                                    <p class="text-gray-400 text-sm">Customer</p>
                                    <p class="text-white font-medium">${booking.username}</p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-sm">Garage</p>
                                    <p class="text-white font-medium">${booking.garage_name || 'N/A'}</p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-sm">Date & Time</p>
                                    <p class="text-white font-medium">${bookingDate} at ${bookingTime}</p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-sm">Duration</p>
                                    <p class="text-white font-medium">${booking.duration} hour(s)</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-gray-700/50 p-4 rounded-lg">
                            <h4 class="text-lg font-semibold text-white mb-3">Vehicle Information</h4>
                            <div class="space-y-3">
                                <div>
                                    <p class="text-gray-400 text-sm">License Plate</p>
                                    <p class="text-white font-medium">${booking.licenseplate || 'N/A'}</p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-sm">Vehicle</p>
                                    <p class="text-white font-medium">${(booking.make || '') + ' ' + (booking.model || '') || 'N/A'}</p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-sm">Color</p>
                                    <p class="text-white font-medium capitalize">${booking.color || 'N/A'}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    ${paymentInfo}
                    
                    <!-- Timestamps -->
                    <div class="bg-gray-700/50 p-4 rounded-lg">
                        <h4 class="text-lg font-semibold text-white mb-3">Timestamps</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-gray-400 text-sm">Created</p>
                                <p class="text-white font-medium">${createdAt}</p>
                            </div>
                            <div>
                                <p class="text-gray-400 text-sm">Last Updated</p>
                                <p class="text-white font-medium">${updatedAt}</p>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('bookingDetailsContent').innerHTML = bookingDetailsHTML;
            
            // Show action buttons if booking is upcoming or active
            if (booking.status === 'upcoming' || booking.status === 'active') {
                document.getElementById('bookingActionButtons').innerHTML = `
                    <button class="btn btn-error" onclick="cancelBookingFromDetails(${booking.id})">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="15" y1="9" x2="9" y2="15"></line>
                            <line x1="9" y1="9" x2="15" y2="15"></line>
                        </svg>
                        Cancel Booking
                    </button>
                `;
                document.getElementById('bookingActionButtons').classList.remove('hidden');
            }
        } else {
            document.getElementById('bookingDetailsContent').innerHTML = `
                <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                    <p>Error: ${data.message}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        document.getElementById('bookingDetailsContent').innerHTML = `
            <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                <p>An error occurred while fetching booking details. Please try again.</p>
            </div>
        `;
    });
}
        
        function closeBookingDetailsModal() {
            document.getElementById('bookingDetailsModal').classList.add('hidden');
        }
        
        function cancelBookingFromDetails(bookingId) {
            if (confirm('Are you sure you want to cancel this booking?')) {
                const formData = new FormData();
                formData.append('action', 'cancel_booking');
                formData.append('booking_id', bookingId);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        closeBookingDetailsModal();
                        window.location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
        
        // Functions for payment details
        function viewPaymentDetails(paymentId) {
    const formData = new FormData();
    formData.append('action', 'get_payment_details');  // ✅ Fixed: Changed from 'get_payment' to 'get_payment_details'
    formData.append('payment_id', paymentId);
    
    // Rest of the function stays the same...
    
    // Show loading state
    document.getElementById('paymentDetailsContent').innerHTML = `
        <div class="flex justify-center items-center h-40">
            <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
        </div>
    `;
    
    // Hide action buttons initially
    document.getElementById('paymentActionButtons').classList.add('hidden');
    
    // Show the modal
    document.getElementById('paymentDetailsModal').classList.remove('hidden');
    
    fetch('admin.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const payment = data.data;
            
            // Determine payment status class
            let paymentClass = '';
            switch (payment.payment_status) {
                case 'paid':
                    paymentClass = 'bg-green-500/20 text-green-400 border border-green-500/30';
                    break;
                case 'pending':
                    paymentClass = 'bg-yellow-500/20 text-yellow-400 border border-yellow-500/30';
                    break;
                case 'refunded':
                    paymentClass = 'bg-purple-500/20 text-purple-400 border border-purple-500/30';
                    break;
            }
            
            // Format dates
            const paymentDate = new Date(payment.payment_date).toLocaleString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            // Build the payment details HTML
            const paymentDetailsHTML = `
                <div class="space-y-6">
                    <!-- Header -->
                    <div class="border-b border-gray-600 pb-4">
                        <h3 class="text-xl font-bold text-white">Payment #${payment.payment_id}</h3>
                        <div class="flex items-center gap-3 mt-2">
                            <span class="px-3 py-1 text-sm rounded-full ${paymentClass}">${payment.payment_status.charAt(0).toUpperCase() + payment.payment_status.slice(1)}</span>
                        </div>
                    </div>
                    
                    <!-- Payment Information -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-gray-700/50 p-4 rounded-lg">
                            <h4 class="text-lg font-semibold text-white mb-3">Payment Details</h4>
                            <div class="space-y-3">
                                <div>
                                    <p class="text-gray-400 text-sm">Amount</p>
                                    <p class="text-white font-medium text-lg">৳${payment.amount}</p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-sm">Payment Method</p>
                                    <p class="text-white font-medium capitalize">${payment.payment_method}</p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-sm">Transaction ID</p>
                                    <p class="text-white font-medium">${payment.transaction_id || 'N/A'}</p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-sm">Payment Date</p>
                                    <p class="text-white font-medium">${paymentDate}</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-gray-700/50 p-4 rounded-lg">
                            <h4 class="text-lg font-semibold text-white mb-3">Booking Information</h4>
                            <div class="space-y-3">
                                <div>
                                    <p class="text-gray-400 text-sm">Booking ID</p>
                                    <p class="text-white font-medium">#${payment.booking_id}</p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-sm">Customer</p>
                                    <p class="text-white font-medium">${payment.customer || payment.username}</p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-sm">Garage</p>
                                    <p class="text-white font-medium">${payment.garage_name || 'N/A'}</p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-sm">Garage ID</p>
                                    <p class="text-white font-medium">${payment.garage_id || 'N/A'}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('paymentDetailsContent').innerHTML = paymentDetailsHTML;
            
            // Show refund button if payment is paid
            if (payment.payment_status === 'paid') {
                document.getElementById('paymentActionButtons').innerHTML = `
                    <button class="btn btn-warning" onclick="refundPaymentFromDetails(${payment.payment_id})">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m15 18-6-6 6-6"/>
                        </svg>
                        Refund Payment
                    </button>
                `;
                document.getElementById('paymentActionButtons').classList.remove('hidden');
            }
        } else {
            document.getElementById('paymentDetailsContent').innerHTML = `
                <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                    <p>Error: ${data.message}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        document.getElementById('paymentDetailsContent').innerHTML = `
            <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                <p>An error occurred while fetching payment details. Please try again.</p>
            </div>
        `;
    });
}
        
        function closePaymentDetailsModal() {
            document.getElementById('paymentDetailsModal').classList.add('hidden');
        }
        
        function refundPaymentFromDetails(paymentId) {
            if (confirm('Are you sure you want to refund this payment?')) {
                const formData = new FormData();
                formData.append('action', 'refund_payment');
                formData.append('payment_id', paymentId);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        closePaymentDetailsModal();
                        window.location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
        
        // Functions for vehicle details
        function viewVehicleDetails(licensePlate) {
            const formData = new FormData();
            formData.append('action', 'get_vehicle');
            formData.append('license_plate', licensePlate);
            
            // Show loading state
            document.getElementById('vehicleDetailsContent').innerHTML = `
                <div class="flex justify-center items-center h-40">
                    <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
                </div>
            `;
            
            // Show the modal
            document.getElementById('vehicleDetailsModal').classList.remove('hidden');
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const vehicle = data.data;
                    
                    // Booking history table
                    let bookingHistoryHTML = '';
                    if (vehicle.booking_history && vehicle.booking_history.length > 0) {
                        bookingHistoryHTML = `
                            <div class="detail-section mt-6">
                                <h4 class="text-lg font-semibold text-primary mb-3">Booking History</h4>
                                <div class="overflow-x-auto">
                                    <table class="w-full">
                                        <thead>
                                            <tr>
                                                <th class="text-left px-4 py-2 bg-gray-700">Booking ID</th>
                                                <th class="text-left px-4 py-2 bg-gray-700">Date</th>
                                                <th class="text-left px-4 py-2 bg-gray-700">Time</th>
                                                <th class="text-left px-4 py-2 bg-gray-700">Duration</th>
                                                <th class="text-left px-4 py-2 bg-gray-700">Garage</th>
                                                <th class="text-left px-4 py-2 bg-gray-700">Status</th>
                                                <th class="text-left px-4 py-2 bg-gray-700">Payment</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                        `;
                        
                        vehicle.booking_history.forEach(booking => {
                            // Determine status class
                            let statusClass = '';
                            switch (booking.status) {
                                case 'upcoming':
                                    statusClass = 'status-upcoming';
                                    break;
                                case 'active':
                                    statusClass = 'status-active';
                                    break;
                                case 'completed':
                                    statusClass = 'status-completed';
                                    break;
                                case 'cancelled':
                                    statusClass = 'status-cancelled';
                                    break;
                            }
                            
                            // Determine payment status class
                            let paymentClass = '';
                            switch (booking.payment_status) {
                                case 'paid':
                                    paymentClass = 'status-paid';
                                    break;
                                case 'pending':
                                    paymentClass = 'status-pending';
                                    break;
                                case 'refunded':
                                    paymentClass = 'status-refunded';
                                    break;
                            }
                            
                            const bookingDate = new Date(booking.booking_date).toLocaleDateString('en-US', {
                                year: 'numeric',
                                month: 'short',
                                day: 'numeric'
                            });
                            
                            const bookingTime = new Date(`${booking.booking_date}T${booking.booking_time}`).toLocaleTimeString('en-US', {
                                hour: '2-digit',
                                minute: '2-digit'
                            });
                            
                            bookingHistoryHTML += `
                                <tr>
                                    <td class="px-4 py-2 border-t border-gray-700">#${booking.id}</td>
                                    <td class="px-4 py-2 border-t border-gray-700">${bookingDate}</td>
                                    <td class="px-4 py-2 border-t border-gray-700">${bookingTime}</td>
                                    <td class="px-4 py-2 border-t border-gray-700">${booking.duration} hours</td>
                                    <td class="px-4 py-2 border-t border-gray-700">${booking.Parking_Space_Name}</td>
                                    <td class="px-4 py-2 border-t border-gray-700">
                                        <span class="status-badge ${statusClass}">${booking.status.charAt(0).toUpperCase() + booking.status.slice(1)}</span>
                                    </td>
                                    <td class="px-4 py-2 border-t border-gray-700">
                                        <span class="status-badge ${paymentClass}">${booking.payment_status.charAt(0).toUpperCase() + booking.payment_status.slice(1)}</span>
                                    </td>
                                </tr>
                            `;
                        });
                        
                        bookingHistoryHTML += `
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        `;
                    } else {
                        bookingHistoryHTML = `
                            <div class="detail-section mt-6">
                                <h4 class="text-lg font-semibold text-primary mb-3">Booking History</h4>
                                <p class="text-white/70">No booking history found for this vehicle.</p>
                            </div>
                        `;
                    }
                    
                    // Create HTML for vehicle details
                    const vehicleDetailsHTML = `
                        <div class="grid grid-cols-1 gap-6">
                            <div class="flex flex-wrap gap-4 justify-between items-center">
                                <div>
                                    <h3 class="text-xl font-bold">${vehicle.make} ${vehicle.model}</h3>
                                    <p class="text-white/70 text-sm">License Plate: ${vehicle.licensePlate}</p>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="detail-section">
                                    <h4 class="text-lg font-semibold text-primary mb-3">Vehicle Information</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="detail-item">
                                            <p class="detail-label">Type</p>
                                            <p class="detail-value capitalize">${vehicle.vehicleType}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Make</p>
                                            <p class="detail-value">${vehicle.make}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Model</p>
                                            <p class="detail-value">${vehicle.model}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Color</p>
                                            <p class="detail-value capitalize">${vehicle.color}</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="detail-section">
                                    <h4 class="text-lg font-semibold text-primary mb-3">Owner Information</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="detail-item">
                                            <p class="detail-label">Username</p>
                                            <p class="detail-value">${vehicle.username}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Name</p>
                                            <p class="detail-value">${vehicle.firstName} ${vehicle.lastName}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Email</p>
                                            <p class="detail-value">${vehicle.email || 'Not provided'}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Phone</p>
                                            <p class="detail-value">${vehicle.phone || 'Not provided'}</p>
                                        </div>
                                        <div class="detail-item md:col-span-2">
                                            <p class="detail-label">Address</p>
                                            <p class="detail-value">${vehicle.address || 'Not provided'}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            ${bookingHistoryHTML}
                        </div>
                    `;
                    
                    document.getElementById('vehicleDetailsContent').innerHTML = vehicleDetailsHTML;
                } else {
                    document.getElementById('vehicleDetailsContent').innerHTML = `
                        <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                            <p>${data.message}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('vehicleDetailsContent').innerHTML = `
                    <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                        <p>An error occurred while fetching vehicle details. Please try again.</p>
                    </div>
                `;
            });
        }
        
        function closeVehicleDetailsModal() {
            document.getElementById('vehicleDetailsModal').classList.add('hidden');
        }
    </script>








<!-- Message Owner Modal -->
<div id="messageOwnerModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
        <h3 class="text-xl font-bold text-white mb-4">Send Message to Owner</h3>
        <form id="messageOwnerForm">
            <input type="hidden" id="message_owner_id" name="owner_id">
            <input type="hidden" name="action" value="send_owner_message">
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white">Subject</span>
                </label>
                <input type="text" id="message_subject" name="subject" class="input input-bordered bg-gray-700 text-white" required>
            </div>
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white">Message</span>
                </label>
                <textarea id="message_content" name="message" class="textarea textarea-bordered bg-gray-700 text-white h-32" required></textarea>
            </div>
            
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" class="btn btn-outline border-white/20 text-white" onclick="closeMessageModal()">Cancel</button>
                <button type="submit" class="btn bg-primary hover:bg-primary-dark text-white border-none">Send Message</button>
            </div>
        </form>
    </div>
</div>

<script>

    // Message modal functions
function openMessageModal(ownerId) {
    document.getElementById('message_owner_id').value = ownerId;
    document.getElementById('message_subject').value = '';
    document.getElementById('message_content').value = '';
    document.getElementById('messageOwnerModal').classList.remove('hidden');
}

function closeMessageModal() {
    document.getElementById('messageOwnerModal').classList.add('hidden');
}

// Make sure this code is added to your document ready function or at the end of the script
document.addEventListener('DOMContentLoaded', function() {
    // Add message form submission
    if (document.getElementById('messageOwnerForm')) {
        document.getElementById('messageOwnerForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeMessageModal();
                } else {
                    alert(data.message || 'An error occurred while sending the message.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        });
    }
});
</script>


<!-- Commission Modal -->
<div id="commissionModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
        <h3 class="text-xl font-bold text-white mb-4">Set Commission Rate</h3>
        <form id="commissionForm">
            <input type="hidden" id="commission_owner_id" name="owner_id">
            <input type="hidden" name="action" value="update_commission_rate">
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white">Commission Rate (%)</span>
                </label>
                <input type="number" id="commission_rate" name="commission_rate" class="input input-bordered bg-gray-700 text-white" min="0" max="100" step="0.1" required>
            </div>
            
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" class="btn btn-outline border-white/20 text-white" onclick="closeCommissionModal()">Cancel</button>
                <button type="submit" class="btn bg-primary hover:bg-primary-dark text-white border-none">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Commission modal functions
function openCommissionModal(ownerId, currentRate) {
    document.getElementById('commission_owner_id').value = ownerId;
    document.getElementById('commission_rate').value = currentRate || 10;
    document.getElementById('commissionModal').classList.remove('hidden');
}

function closeCommissionModal() {
    document.getElementById('commissionModal').classList.add('hidden');
}

// Add commission form submission
document.getElementById('commissionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('admin.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            closeCommissionModal();
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
});
</script>

<script>
    // Function to update garage owner status
function updateOwnerStatus(ownerId, status) {
    if (confirm(`Are you sure you want to change this owner's status to "${status}"?`)) {
        const formData = new FormData();
        formData.append('action', 'update_owner_status');
        formData.append('owner_id', ownerId);
        formData.append('status', status);
        
        fetch('admin.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.reload(); // Reload to see the updated status
            } else {
                alert(data.message || 'An error occurred while updating owner status.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }
}
</script>

<script>
    function closeOwnerDetailsModal() {
    document.getElementById('ownerDetailsModal').classList.add('hidden');
}
</script>

<script>

// Function to set default 30% commission for all garage owners
function setDefaultCommissionForAll() {
    if (confirm('Are you sure you want to set 30% commission rate for all garage owners? This will update existing rates as well.')) {
        fetch('admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=set_default_commission_for_all'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.reload(); // Reload to see changes
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }
}

</script>



<script>
  // Test map functionality
  console.log("Map functionality test...");
  console.log("Leaflet loaded:", typeof L !== 'undefined' ? "Yes" : "No");
  
  // Override viewGarageLocation with a debug version
  window.originalViewGarageLocation = window.viewGarageLocation;
  window.viewGarageLocation = function(lat, lng, name) {
    console.log("viewGarageLocation called with:", lat, lng, name);
    
    // Log the map container element
    const mapContainer = document.getElementById('mapContainer');
    console.log("Map container found:", mapContainer ? "Yes" : "No");
    
    // Call the original function if it exists
    if (window.originalViewGarageLocation) {
      try {
        window.originalViewGarageLocation(lat, lng, name);
      } catch (error) {
        console.error("Error in original function:", error);
      }
    } else {
      console.error("Original viewGarageLocation function not found");
    }
  };
  
  // Test if modal can be shown
  const viewLocationModal = document.getElementById('viewLocationModal');
  console.log("Location modal found:", viewLocationModal ? "Yes" : "No");
</script>


<!-- Add this JavaScript code to the end of your admin.php file, just before the closing </body> tag -->
<script>
    // Notification system
    document.addEventListener('DOMContentLoaded', function() {
        const notificationButton = document.getElementById('notification-button');
        const notificationDropdown = document.getElementById('notification-dropdown');
        const notificationContent = document.getElementById('notification-content');
        const refreshButton = document.getElementById('refresh-notifications');
        
        // Toggle notification dropdown
        notificationButton.addEventListener('click', function() {
            notificationDropdown.classList.toggle('hidden');
            
            // If showing dropdown, fetch notification items
            if (!notificationDropdown.classList.contains('hidden')) {
                fetchNotificationItems();
            }
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            if (!notificationButton.contains(event.target) && !notificationDropdown.contains(event.target)) {
                notificationDropdown.classList.add('hidden');
            }
        });
        
        // Refresh notifications
        refreshButton.addEventListener('click', function() {
            fetchNotificationItems();
            fetchNotificationCounts();
        });
        
        // Fetch notification counts every 5 minutes
        setInterval(fetchNotificationCounts, 5 * 60 * 1000);
        
        // Function to fetch notification counts
        // Add these two functions to your JavaScript code
function fetchNotificationCounts() {
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_notification_counts'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('notification-count').textContent = data.counts.total;
        }
    })
    .catch(error => console.error('Error fetching notification counts:', error));
}

function fetchNotificationItems() {
    // Show loading state
    document.getElementById('notification-content').innerHTML = `
        <div class="p-6 text-center text-white/70">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-white/40 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
            <p>Loading notifications...</p>
        </div>
    `;

    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_verification_items'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            renderNotificationItems(data);
        } else {
            document.getElementById('notification-content').innerHTML = `
                <div class="p-6 text-center text-white/70">
                    <p>Error loading notifications.</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error fetching notifications:', error);
        document.getElementById('notification-content').innerHTML = `
            <div class="p-6 text-center text-white/70">
                <p>Failed to load notifications. Please try again.</p>
            </div>
        `;
    });

    
}

function renderNotificationItems(data) {
    const { users, owners, unauthorized, garages } = data; // 'garages' যোগ করুন
    let content = '';
    
    // Add user notifications
    if (users.length > 0) {
        content += `
            <div class="p-3 bg-gray-700">
                <h4 class="font-semibold text-white">Unverified Users (${users.length})</h4>
            </div>
        `;
        
        users.forEach(user => {
            content += `
                <div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium text-white">${user.username}</p>
                            <p class="text-sm text-white/70">${user.firstName || ''} ${user.lastName || ''}</p>
                        </div>
                        <button class="btn btn-xs btn-primary" onclick="verifyUser('${user.username}')">Verify</button>
                    </div>
                </div>
            `;
        });
    }
    
    // Add garage owner notifications
    if (owners.length > 0) {
        content += `
            <div class="p-3 bg-gray-700">
                <h4 class="font-semibold text-white">Unverified Garage Owners (${owners.length})</h4>
            </div>
        `;
        
        owners.forEach(owner => {
            content += `
                <div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium text-white">${owner.username}</p>
                            <p class="text-sm text-white/70">${owner.firstName || ''} ${owner.lastName || ''}</p>
                        </div>
                        <button class="btn btn-xs btn-primary" onclick="verifyOwner('${owner.owner_id}')">Verify</button>
                    </div>
                </div>
            `;
        });
    }
    
    // Add unauthorized garage owners notifications
    if (unauthorized.length > 0) {
        content += `
            <div class="p-3 bg-gray-700">
                <h4 class="font-semibold text-white">Users with Garages (${unauthorized.length})</h4>
            </div>
        `;
        
        unauthorized.forEach(user => {
            // Create a temporary owner ID for users with garages but not registered as owners
            const tempOwnerId = `U_owner_${user.username}`;
            content += `
                <div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium text-white">${user.username}</p>
                            <p class="text-sm text-white/70">${user.firstName || ''} ${user.lastName || ''}</p>
                        </div>
                        <button class="btn btn-xs btn-primary" onclick="verifyOwner('${tempOwnerId}')">Register & Verify</button>
                    </div>
                </div>
            `;
        });
    }
    
    // Add unverified garages notifications - নতুন যোগ করা সেকশন
    if (garages && garages.length > 0) {
        content += `
            <div class="p-3 bg-gray-700">
                <h4 class="font-semibold text-white">Unverified Garages (${garages.length})</h4>
            </div>
        `;
        
        garages.forEach(garage => {
            content += `
                <div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium text-white">${garage.Parking_Space_Name}</p>
                            <p class="text-sm text-white/70">${garage.Parking_Lot_Address}</p>
                        </div>
                        <button class="btn btn-xs btn-primary" onclick="verifyGarage('${garage.garage_id}')">Verify</button>
                    </div>
                </div>
            `;
        });
    }
    
    // Show message if no notifications - গ্যারেজ যোগ করুন চেকে
    if (users.length === 0 && owners.length === 0 && unauthorized.length === 0 && (!garages || garages.length === 0)) {
        content = `
            <div class="p-6 text-center text-white/70">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-white/40 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                    <line x1="9" y1="9" x2="9.01" y2="9"></line>
                    <line x1="15" y1="9" x2="15.01" y2="9"></line>
                </svg>
                <p>No pending verifications!</p>
            </div>
        `;
    }
    
    // Update notification content
    notificationContent.innerHTML = content;
}
    });
</script>


<script>
function viewOwnerDetails(ownerId) {
    console.log('Opening owner details for:', ownerId);
    
    // Check if modal exists
    if (!document.getElementById('ownerDetailsModal')) {
        console.error('Owner details modal not found!');
        alert('Owner details modal not found. Please refresh the page.');
        return;
    }
    
    // Show loading state
    document.getElementById('ownerDetailsContent').innerHTML = `
        <div class="flex justify-center items-center h-40">
            <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-blue-500"></div>
            <p class="ml-4 text-white">Loading owner details...</p>
        </div>
    `;
    
    // Show the modal
    document.getElementById('ownerDetailsModal').classList.remove('hidden');
    
    // Fetch owner details with improved error handling
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `action=get_owner_details&owner_id=${encodeURIComponent(ownerId)}`
    })
    .then(response => {
        console.log('Owner details response status:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        console.log('Owner details raw response:', text);
        
        // Clean the response to extract JSON
        let cleanText = text.trim();
        const jsonStart = cleanText.indexOf('{');
        const jsonEnd = cleanText.lastIndexOf('}') + 1;
        
        if (jsonStart !== -1 && jsonEnd > jsonStart) {
            cleanText = cleanText.substring(jsonStart, jsonEnd);
        }
        
        let data;
        try {
            data = JSON.parse(cleanText);
            console.log('Parsed owner details:', data);
        } catch (parseError) {
            console.error('JSON parsing error:', parseError);
            throw new Error('Invalid JSON response from server');
        }
        
        if (data.success) {
            const owner = data.data;
            console.log('Owner data:', owner);
            
            // FIXED: Handle missing fields properly
            const ownerType = owner.owner_id?.includes('G_owner_') ? 'Professional Owner' : 'Dual User';
            const lastLogin = owner.last_login ? new Date(owner.last_login).toLocaleString() : 'Never';
            const fullName = `${owner.firstName || ''} ${owner.lastName || ''}`.trim() || owner.name || 'Not provided';
            const commissionRate = owner.commission_rate || '30.00';
            
            // Create garage list HTML
            let garagesList = '';
            if (owner.garages && owner.garages.length > 0) {
                garagesList = `
                    <div class="mt-6">
                        <h4 class="text-lg font-semibold text-blue-400 mb-3">Owned Garages (${owner.garages.length})</h4>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr>
                                        <th class="text-left px-4 py-2 bg-gray-700 text-white">Name</th>
                                        <th class="text-left px-4 py-2 bg-gray-700 text-white">Address</th>
                                        <th class="text-left px-4 py-2 bg-gray-700 text-white">Type</th>
                                        <th class="text-left px-4 py-2 bg-gray-700 text-white">Capacity</th>
                                        <th class="text-left px-4 py-2 bg-gray-700 text-white">Price/Hour</th>
                                        <th class="text-left px-4 py-2 bg-gray-700 text-white">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                
                owner.garages.forEach(garage => {
                    const verifiedBadge = garage.is_verified == 1 ? 
                        '<span class="px-1 py-0.5 bg-green-600 text-white text-xs rounded">Verified</span>' : 
                        '<span class="px-1 py-0.5 bg-red-600 text-white text-xs rounded">Unverified</span>';
                    
                    garagesList += `
                        <tr class="hover:bg-gray-700/30">
                            <td class="px-4 py-2 border-t border-gray-700 text-white">${garage.name || 'Unnamed'}</td>
                            <td class="px-4 py-2 border-t border-gray-700 text-white">${garage.address || 'No address'}</td>
                            <td class="px-4 py-2 border-t border-gray-700 text-white">${garage.garage_type || 'Standard'}</td>
                            <td class="px-4 py-2 border-t border-gray-700 text-white">${garage.parking_capacity || 'N/A'}</td>
                            <td class="px-4 py-2 border-t border-gray-700 text-white">৳${garage.price_per_hour || 'N/A'}</td>
                            <td class="px-4 py-2 border-t border-gray-700">${verifiedBadge}</td>
                        </tr>
                    `;
                });
                
                garagesList += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            } else {
                garagesList = `
                    <div class="mt-6">
                        <h4 class="text-lg font-semibold text-blue-400 mb-3">Owned Garages</h4>
                        <div class="text-center py-8">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-white/20 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 6a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V6Z"/>
                                <path d="M8 12h8"/>
                                <path d="M12 8v8"/>
                            </svg>
                            <p class="text-white/70">No garages registered yet.</p>
                        </div>
                    </div>
                `;
            }
            
            // Add statistics section
            let statisticsSection = '';
            if (owner.statistics) {
                statisticsSection = `
                    <div class="mt-6">
                        <h4 class="text-lg font-semibold text-blue-400 mb-3">Statistics</h4>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="bg-gray-700/50 rounded-lg p-3 text-center">
                                <p class="text-gray-400 text-sm">Total Garages</p>
                                <p class="text-white text-xl font-bold">${owner.statistics.total_garages || 0}</p>
                            </div>
                            <div class="bg-gray-700/50 rounded-lg p-3 text-center">
                                <p class="text-gray-400 text-sm">Total Bookings</p>
                                <p class="text-blue-400 text-xl font-bold">${owner.statistics.total_bookings || 0}</p>
                            </div>
                            <div class="bg-gray-700/50 rounded-lg p-3 text-center">
                                <p class="text-gray-400 text-sm">Completed</p>
                                <p class="text-green-400 text-xl font-bold">${owner.statistics.completed_bookings || 0}</p>
                            </div>
                            <div class="bg-gray-700/50 rounded-lg p-3 text-center">
                                <p class="text-gray-400 text-sm">Total Earnings</p>
                                <p class="text-yellow-400 text-xl font-bold">৳${(owner.statistics.total_earnings || 0).toFixed(2)}</p>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            // Generate owner details HTML
            const ownerDetailsHTML = `
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="detail-section">
                        <h4 class="text-lg font-semibold text-blue-400 mb-3">Owner Information</h4>
                        <div class="space-y-3">
                            <div class="detail-item">
                                <p class="text-gray-400 text-sm">Owner ID</p>
                                <p class="text-white">${owner.owner_id || 'N/A'}</p>
                            </div>
                            <div class="detail-item">
                                <p class="text-gray-400 text-sm">Username</p>
                                <p class="text-white">@${owner.username || 'N/A'}</p>
                            </div>
                            <div class="detail-item">
                                <p class="text-gray-400 text-sm">Owner Type</p>
                                <p class="text-white">${ownerType}</p>
                            </div>
                            <div class="detail-item">
                                <p class="text-gray-400 text-sm">Account Status</p>
                                <p class="text-white capitalize">${owner.account_status || 'Active'}</p>
                            </div>
                            <div class="detail-item">
                                <p class="text-gray-400 text-sm">Verification Status</p>
                                <span class="px-2 py-1 rounded text-xs ${owner.is_verified == 1 ? 'bg-green-600' : 'bg-red-600'} text-white">
                                    ${owner.is_verified == 1 ? 'Verified' : 'Unverified'}
                                </span>
                            </div>
                            <div class="detail-item">
                                <p class="text-gray-400 text-sm">Registration Date</p>
                                <p class="text-white">${owner.registration_date ? new Date(owner.registration_date).toLocaleDateString() : 'N/A'}</p>
                            </div>
                            <div class="detail-item">
                                <p class="text-gray-400 text-sm">Last Login</p>
                                <p class="text-white">${lastLogin}</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h4 class="text-lg font-semibold text-blue-400 mb-3">Personal Information</h4>
                        <div class="space-y-3">
                            <div class="detail-item">
                                <p class="text-gray-400 text-sm">Full Name</p>
                                <p class="text-white">${fullName}</p>
                            </div>
                            <div class="detail-item">
                                <p class="text-gray-400 text-sm">Email</p>
                                <p class="text-white">${owner.email || 'Not provided'}</p>
                            </div>
                            <div class="detail-item">
                                <p class="text-gray-400 text-sm">Phone</p>
                                <p class="text-white">${owner.phone || 'Not provided'}</p>
                            </div>
                            <div class="detail-item">
                                <p class="text-gray-400 text-sm">Address</p>
                                <p class="text-white">${owner.address || 'Not provided'}</p>
                            </div>
                            <div class="detail-item">
                                <p class="text-gray-400 text-sm">Account Points</p>
                                <p class="text-green-400 text-xl font-bold">${owner.points || 0}</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                ${statisticsSection}
                
                <div class="mt-6">
    <h4 class="text-lg font-semibold text-blue-400 mb-3">Commission Settings</h4>
    <div class="bg-gray-700/30 p-4 rounded-lg">
        <div class="flex justify-between items-center">
            <div>
                <p class="text-white/60 text-sm">Current Commission Rate</p>
                <p id="commission-rate-display" class="text-white text-2xl font-bold">${commissionRate}%</p>
                <p id="commission-description" class="text-white/60 text-xs mt-2">Commission rate determines the percentage of booking revenue retained by the platform. The remaining amount (${100 - commissionRate}%) goes to the owner.</p>
            </div>
            <button id="commission-update-btn" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700" onclick="updateCommissionRate('${owner.owner_id}', '${commissionRate}')">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2-2v-7"></path>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                </svg>
                Update Commission Rate
            </button>
        </div>
    </div>
</div>
                
                ${garagesList}
            `;
            
            document.getElementById('ownerDetailsContent').innerHTML = ownerDetailsHTML;
        } else {
            console.error('API error:', data.message);
            document.getElementById('ownerDetailsContent').innerHTML = `
                <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                    <h4 class="font-semibold mb-2">Error Loading Owner Details</h4>
                    <p>${data.message}</p>
                    <button onclick="viewOwnerDetails('${ownerId}')" class="mt-3 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                        Retry
                    </button>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error fetching owner details:', error);
        document.getElementById('ownerDetailsContent').innerHTML = `
            <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                <h4 class="font-semibold mb-2">Network Error</h4>
                <p>An error occurred while fetching owner details: ${error.message}</p>
                <button onclick="viewOwnerDetails('${ownerId}')" class="mt-3 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                    Retry
                </button>
            </div>
        `;
    });
}

// Function to close the owner details modal
function closeOwnerDetailsModal() {
    const modal = document.getElementById('ownerDetailsModal');
    if (modal) {
        modal.classList.add('hidden');
    }
}

function updateCommissionRate(ownerId, currentRate) {
    const newRate = prompt(`Enter new commission rate for ${ownerId}:`, currentRate);
    
    if (newRate === null) return;
    
    const rate = parseFloat(newRate);
    if (isNaN(rate) || rate < 0 || rate > 100) {
        alert('Please enter a valid commission rate between 0 and 100');
        return;
    }
    
    // Use FormData instead of URL-encoded string
    const formData = new FormData();
    formData.append('action', 'update_commission');
    formData.append('owner_id', ownerId);
    formData.append('rate', rate);
    
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.text();
    })
    .then(text => {
        console.log('Raw response:', text);
        try {
            const data = JSON.parse(text.trim());
            if (data.success) {
                alert('Commission rate updated successfully: ' + data.message);
                
                // IMPORTANT: Refresh the owner details modal to show updated commission
                console.log('Refreshing owner details for:', ownerId);
                viewOwnerDetails(ownerId);
                
            } else {
                alert('Failed to update: ' + data.message);
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            console.error('Response text:', text);
            alert('Server error: Invalid response format');
        }
    })
    .catch(error => {
        console.error('Network error:', error);
        alert('Network error occurred. Please check your connection and try again.');
    });
}
function updateCommissionRateDisplay(ownerId, newRate) {
    // Update the commission rate display in the modal
    const commissionElement = document.querySelector('.text-2xl.font-bold');
    if (commissionElement && commissionElement.textContent.includes('%')) {
        commissionElement.textContent = `${newRate}%`;
    }
    
    // Update any other elements that might show the commission rate
    const commissionDescription = document.querySelector('p:contains("Commission rate determines")');
    if (commissionDescription) {
        const ownerPercentage = (100 - newRate).toFixed(2);
        commissionDescription.innerHTML = `Commission rate determines the percentage of booking revenue retained by the platform. The remaining amount (${ownerPercentage}%) goes to the owner.`;
    }
}
// ADDED: Test function
function testOwnerDetails() {
    console.log('Testing owner details...');
    viewOwnerDetails('G_owner_sami'); // Test with existing owner ID
}

// ADDED: Search functionality for garage owners
document.addEventListener('DOMContentLoaded', function() {
    // Add search functionality
    const searchInputs = document.querySelectorAll('input[placeholder*="Search owners"], input[placeholder*="search owners"]');
    
    searchInputs.forEach(searchInput => {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            console.log('Searching garage owners for:', searchTerm);
            
            // Find all table rows in garage owner tables
            const tableRows = document.querySelectorAll('.data-table tbody tr, table tbody tr');
            let visibleCount = 0;
            
            tableRows.forEach(row => {
                // Skip if this row is not in a garage owner context
                const parentTable = row.closest('table');
                if (!parentTable || !parentTable.querySelector('th')) return;
                
                const headers = Array.from(parentTable.querySelectorAll('th')).map(th => th.textContent.toLowerCase());
                const isOwnerTable = headers.some(header => 
                    header.includes('owner') || 
                    header.includes('username') || 
                    header.includes('verification')
                );
                
                if (!isOwnerTable) return;
                
                const text = row.textContent.toLowerCase();
                const shouldShow = text.includes(searchTerm);
                row.style.display = shouldShow ? '' : 'none';
                
                if (shouldShow) {
                    visibleCount++;
                    // Highlight matching text
                    const cells = row.querySelectorAll('td');
                    cells.forEach(cell => {
                        if (cell.textContent.toLowerCase().includes(searchTerm) && searchTerm.length > 0) {
                            cell.style.backgroundColor = 'rgba(59, 130, 246, 0.1)';
                        } else {
                            cell.style.backgroundColor = '';
                        }
                    });
                } else {
                    // Remove highlighting from hidden rows
                    const cells = row.querySelectorAll('td');
                    cells.forEach(cell => {
                        cell.style.backgroundColor = '';
                    });
                }
            });
            
            console.log(`Found ${visibleCount} matching garage owners`);
        });
    });
    
    console.log('Garage owner search functionality initialized');
});
</script>

<script>
// Add these functions to your existing JavaScript in admin.php

// Function to calculate missing profits
function calculateMissingProfits() {
    if (confirm('Calculate profit for all payments that are missing profit data?')) {
        // Show loading state
        const button = event.target;
        const originalText = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<svg class="animate-spin h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Calculating...';
        
        fetch('admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=calculate_missing_profits'
        })
        .then(response => response.json())
        .then(data => {
            alert(data.message);
            if (data.calculated > 0) {
                window.location.reload();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while calculating profits.');
        })
        .finally(() => {
            // Restore button state
            button.disabled = false;
            button.innerHTML = originalText;
        });
    }
}

// Function to refresh dashboard
function refreshDashboard() {
    window.location.reload();
}

// Function to refresh profit chart
function refreshProfitChart() {
    if (window.revenueProfitChartInstance) {
        window.revenueProfitChartInstance.destroy();
    }
    initRevenueProfitChart();
}

// Updated Revenue vs Profit Chart function
// REPLACE your existing initRevenueProfitChart function with this fixed version

function initRevenueProfitChart() {
    const ctx = document.getElementById('revenueProfitChart');
    if (!ctx) {
        console.log('Revenue profit chart canvas not found');
        return;
    }
    
    console.log('Initializing revenue profit chart...');
    
    // Destroy existing chart if it exists
    if (window.revenueProfitChartInstance) {
        window.revenueProfitChartInstance.destroy();
    }
    
    // Fetch profit data
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_profit_by_period&period=last_7_days'
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.text();
    })
    .then(text => {
        console.log('Raw response:', text);
        try {
            const data = JSON.parse(text);
            console.log('Parsed data:', data);
            
            if (data.success && data.data && data.data.length > 0) {
                createRevenueProfitChart(ctx, data.data);
            } else {
                console.log('No data available, creating sample chart');
                createSampleProfitChart(ctx);
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            console.log('Raw response that failed to parse:', text);
            createSampleProfitChart(ctx);
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        createSampleProfitChart(ctx);
    });
}

function createRevenueProfitChart(ctx, profitData) {
    const dates = profitData.map(item => {
        const date = new Date(item.date);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    });
    
    const revenueData = profitData.map(item => parseFloat(item.total_revenue || 0));
    const profitData_values = profitData.map(item => parseFloat(item.platform_profit || 0));
    const ownerCommissionData = profitData.map(item => parseFloat(item.owner_commission || 0));
    
    console.log('Chart data:', { dates, revenueData, profitData_values, ownerCommissionData });
    
    window.revenueProfitChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: dates,
            datasets: [
                {
                    label: 'Total Revenue',
                    data: revenueData,
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderColor: 'rgba(59, 130, 246, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: false
                },
                {
                    label: 'Platform Profit',
                    data: profitData_values,
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: false
                },
                {
                    label: 'Owner Commission',
                    data: ownerCommissionData,
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    borderColor: 'rgba(245, 158, 11, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)',
                        callback: function(value) {
                            return '৳' + value.toFixed(0);
                        }
                    }
                },
                x: {
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)'
                    }
                }
            },
            plugins: {
                legend: {
                    labels: {
                        color: 'white'
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: 'white',
                    bodyColor: 'white',
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ৳' + context.parsed.y.toFixed(2);
                        }
                    }
                }
            }
        }
    });
}

function createSampleProfitChart(ctx) {
    window.revenueProfitChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['6 days ago', '5 days ago', '4 days ago', '3 days ago', '2 days ago', 'Yesterday', 'Today'],
            datasets: [
                {
                    label: 'Total Revenue',
                    data: [150, 200, 175, 300, 225, 250, 166],
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderColor: 'rgba(59, 130, 246, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: false
                },
                {
                    label: 'Platform Profit',
                    data: [45, 60, 52, 90, 67, 75, 50],
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)',
                        callback: function(value) {
                            return '৳' + value;
                        }
                    }
                },
                x: {
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)'
                    }
                }
            },
            plugins: {
                legend: {
                    labels: {
                        color: 'white'
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: 'white',
                    bodyColor: 'white'
                }
            }
        }
    });
}

// Test function to check database connection
function testProfitData() {
    console.log('Testing profit data connection...');
    
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=test_profit_data'
    })
    .then(response => response.json())
    .then(data => {
        console.log('Database test results:', data);
        alert('Database Test Results:\n' + 
              'Connected: ' + data.database_connected + '\n' +
              'Profit Records: ' + (data.profit_tracking_total || 0) + '\n' +
              'Joined Records: ' + (data.joined_total || 0) + '\n' +
              'Sample Data: ' + (data.samples ? data.samples.length : 0) + ' records');
    })
    .catch(error => {
        console.error('Test error:', error);
        alert('Database test failed: ' + error.message);
    });
}
</script>

<script>
// Function to view garage reviews
function viewGarageReviews(garageId, garageName) {
    console.log('=== VIEWING GARAGE REVIEWS ===');
    console.log('Garage ID:', garageId);
    console.log('Garage Name:', garageName);
    
    // Set modal title
    document.getElementById('reviewsModalTitle').textContent = `Reviews for ${garageName}`;
    
    // Show loading state
    document.getElementById('garageReviewsContent').innerHTML = `
        <div class="flex justify-center items-center h-40">
            <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
            <p class="ml-4 text-white">Loading reviews...</p>
        </div>
    `;
    
    // Show the modal
    document.getElementById('garageReviewsModal').classList.remove('hidden');
    
    // Create form data
    const formData = new FormData();
    formData.append('action', 'get_garage_reviews');
    formData.append('garage_id', garageId);
    
    console.log('Sending request with data:', {
        action: 'get_garage_reviews',
        garage_id: garageId
    });
    
    // Fetch reviews
    fetch('admin.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return response.text().then(text => {
            console.log('Raw response:', text);
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response text:', text);
                throw new Error('Invalid JSON response: ' + text.substring(0, 100));
            }
        });
    })
    .then(data => {
        console.log('Parsed data:', data);
        
        if (data.success) {
            displayGarageReviews(data);
        } else {
            document.getElementById('garageReviewsContent').innerHTML = `
                <div class="text-center py-8">
                    <p class="text-red-400">Error: ${data.message}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        document.getElementById('garageReviewsContent').innerHTML = `
            <div class="text-center py-8">
                <p class="text-red-400">Error loading reviews: ${error.message}</p>
                <button onclick="viewGarageReviews('${garageId}', '${garageName}')" class="mt-2 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Retry</button>
            </div>
        `;
    });
}

function displayGarageReviews(data) {
    const { reviews, summary, garage_info, debug } = data;
    let content = '';
    
    console.log('Displaying reviews:', {
        reviewsCount: reviews?.length || 0,
        hasSummary: !!summary,
        garageInfo: garage_info,
        debug: debug
    });
    
    // Debug info (you can remove this later)
    if (debug) {
        content += `
            <div class="bg-blue-900/20 text-blue-300 rounded-lg p-3 mb-4 text-xs">
                <strong>Debug Info:</strong> Found ${debug.reviews_count} reviews for ${debug.garage_name} (ID: ${debug.garage_id})
            </div>
        `;
    }
    
    // Rating Summary Section
    if (summary && summary.total_ratings > 0) {
        const avgRating = parseFloat(summary.average_rating);
        const totalRatings = parseInt(summary.total_ratings);
        
        content += `
            <div class="bg-gray-700/50 rounded-lg p-6 mb-6">
                <h4 class="text-lg font-semibold text-yellow-400 mb-4">Rating Summary</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="text-center">
                        <div class="text-4xl font-bold text-yellow-400 mb-2">${avgRating.toFixed(1)}</div>
                        <div class="flex justify-center mb-2">
                            ${generateStarRating(avgRating)}
                        </div>
                        <p class="text-white/70">Based on ${totalRatings} review${totalRatings !== 1 ? 's' : ''}</p>
                    </div>
                    <div class="space-y-2">
                        ${generateRatingBreakdown(summary)}
                    </div>
                </div>
            </div>
        `;
    } else {
        content += `
            <div class="bg-gray-700/50 rounded-lg p-6 mb-6 text-center">
                <p class="text-white/70">No rating summary available</p>
            </div>
        `;
    }
    
    // Individual Reviews Section
    if (reviews && reviews.length > 0) {
        content += `
            <div>
                <h4 class="text-lg font-semibold text-white mb-4">Customer Reviews (${reviews.length})</h4>
                <div class="space-y-4 max-h-96 overflow-y-auto">
        `;
        
        reviews.forEach(review => {
            // Handle reviewer name safely - using corrected field names
            let reviewerName = 'Anonymous';
            if (review.firstName && review.lastName) {
                reviewerName = `${review.firstName} ${review.lastName}`;
            } else if (review.firstName) {
                reviewerName = review.firstName;
            } else if (review.username) {
                reviewerName = review.username;
            }
            
            const reviewDate = new Date(review.created_at).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            // Get booking date from booking_id if needed (optional)
            const bookingInfo = review.booking_id ? `Booking #${review.booking_id}` : '';
            
            content += `
                <div class="bg-gray-700/30 rounded-lg p-4 border border-gray-600">
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <h5 class="font-semibold text-white">${escapeHtml(reviewerName)}</h5>
                            <p class="text-sm text-white/60">Reviewed on ${reviewDate}</p>
                            ${bookingInfo ? `<p class="text-xs text-white/50">${bookingInfo}</p>` : ''}
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="flex">
                                ${generateStarRating(parseFloat(review.rating))}
                            </div>
                            <span class="text-yellow-400 font-semibold">${review.rating}</span>
                        </div>
                    </div>
                    ${review.review_text ? `
                        <div class="text-white/80 text-sm leading-relaxed">
                            ${escapeHtml(review.review_text)}
                        </div>
                    ` : `
                        <div class="text-white/50 text-sm italic">
                            No written review provided
                        </div>
                    `}
                </div>
            `;
        });
        
        content += `
                </div>
            </div>
        `;
    } else {
        content += `
            <div class="text-center py-8">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mx-auto text-white/30 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
                <h4 class="text-lg font-semibold text-white/70 mb-2">No Reviews Yet</h4>
                <p class="text-white/50">This garage hasn't received any reviews yet.</p>
            </div>
        `;
    }
    
    document.getElementById('garageReviewsContent').innerHTML = content;
}

// Helper function to generate star rating display
function generateStarRating(rating) {
    const fullStars = Math.floor(rating);
    const hasHalfStar = rating % 1 >= 0.5;
    const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);
    
    let html = '';
    
    // Full stars
    for (let i = 0; i < fullStars; i++) {
        html += '<svg class="w-4 h-4 text-yellow-400 fill-current" viewBox="0 0 20 20"><path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/></svg>';
    }
    
    // Half star
    if (hasHalfStar) {
        html += '<svg class="w-4 h-4 text-yellow-400" viewBox="0 0 20 20"><defs><linearGradient id="half"><stop offset="50%" stop-color="currentColor"/><stop offset="50%" stop-color="transparent"/></linearGradient></defs><path fill="url(#half)" d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/></svg>';
    }
    
    // Empty stars
    for (let i = 0; i < emptyStars; i++) {
        html += '<svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path></svg>';
    }
    
    return html;
}

// Helper function to generate rating breakdown
function generateRatingBreakdown(summary) {
    const total = parseInt(summary.total_ratings);
    const ratings = [
        { star: 5, count: parseInt(summary.five_star || 0) },
        { star: 4, count: parseInt(summary.four_star || 0) },
        { star: 3, count: parseInt(summary.three_star || 0) },
        { star: 2, count: parseInt(summary.two_star || 0) },
        { star: 1, count: parseInt(summary.one_star || 0) }
    ];
    
    return ratings.map(rating => {
        const percentage = total > 0 ? Math.round((rating.count / total) * 100) : 0;
        return `
            <div class="flex items-center gap-2">
                <span class="text-sm text-white/70 w-6">${rating.star}★</span>
                <div class="flex-1 bg-gray-600 rounded-full h-2">
                    <div class="bg-yellow-400 h-2 rounded-full transition-all duration-300" style="width: ${percentage}%"></div>
                </div>
                <span class="text-sm text-white/70 w-16 text-right">${rating.count} (${percentage}%)</span>
            </div>
        `;
    }).join('');
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Helper function to copy text to clipboard
function copyToClipboard(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).catch(err => {
            console.error('Failed to copy text: ', err);
            fallbackCopyTextToClipboard(text);
        });
    } else {
        fallbackCopyTextToClipboard(text);
    }
}

function fallbackCopyTextToClipboard(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.top = '-1000px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
        document.execCommand('copy');
    } catch (err) {
        console.error('Fallback copy failed:', err);
    }
    
    document.body.removeChild(textArea);
}

// Function to close garage reviews modal
function closeGarageReviewsModal() {
    document.getElementById('garageReviewsModal').classList.add('hidden');
}
// Helper function to generate star rating HTML
function generateStarRating(rating) {
    const fullStars = Math.floor(rating);
    const hasHalfStar = rating % 1 >= 0.5;
    const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);
    
    let starsHtml = '';
    
    // Full stars
    for (let i = 0; i < fullStars; i++) {
        starsHtml += `<span class="text-yellow-400 text-lg">★</span>`;
    }
    
    // Half star
    if (hasHalfStar) {
        starsHtml += `<span class="text-yellow-400 text-lg">☆</span>`;
    }
    
    // Empty stars
    for (let i = 0; i < emptyStars; i++) {
        starsHtml += `<span class="text-gray-400 text-lg">☆</span>`;
    }
    
    return starsHtml;
}

// Helper function to generate rating breakdown
function generateRatingBreakdown(summary) {
    const total = parseInt(summary.total_ratings) || 0;
    if (total === 0) return '<p class="text-white/50 text-sm">No ratings available</p>';
    
    const ratings = [
        { star: 5, count: parseInt(summary.five_star) || 0 },
        { star: 4, count: parseInt(summary.four_star) || 0 },
        { star: 3, count: parseInt(summary.three_star) || 0 },
        { star: 2, count: parseInt(summary.two_star) || 0 },
        { star: 1, count: parseInt(summary.one_star) || 0 }
    ];
    
    return ratings.map(rating => {
        const percentage = total > 0 ? Math.round((rating.count / total) * 100) : 0;
        return `
            <div class="flex items-center gap-2">
                <span class="text-sm text-white/70 w-6">${rating.star}★</span>
                <div class="flex-1 bg-gray-600 rounded-full h-2">
                    <div class="bg-yellow-400 h-2 rounded-full transition-all duration-300" style="width: ${percentage}%"></div>
                </div>
                <span class="text-sm text-white/70 w-16 text-right">${rating.count} (${percentage}%)</span>
            </div>
        `;
    }).join('');
}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Helper function to copy text to clipboard
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).catch(err => {
        console.error('Failed to copy text: ', err);
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
    });
}

// Function to close garage reviews modal
function closeGarageReviewsModal() {
    document.getElementById('garageReviewsModal').classList.add('hidden');
}

// Debug function for testing specific garage
function debugGarageReviews(garageId) {
    console.log('=== DEBUGGING GARAGE REVIEWS ===');
    console.log('Garage ID:', garageId);
    
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            'action': 'get_garage_reviews',
            'garage_id': garageId
        })
    })
    .then(response => {
        console.log('Debug Response Status:', response.status);
        console.log('Debug Response Headers:', response.headers);
        return response.text();
    })
    .then(text => {
        console.log('Debug Raw Response:', text);
        console.log('Debug Response Length:', text.length);
        console.log('Debug First 200 chars:', text.substring(0, 200));
        
        try {
            const data = JSON.parse(text);
            console.log('Debug Parsed Data:', data);
            alert('Debug completed! Check console for full details.');
        } catch (e) {
            console.error('Debug JSON Parse Error:', e);
            alert('Parse error in debug. Check console for details.');
        }
    })
    .catch(error => {
        console.error('Debug Fetch Error:', error);
        alert('Network error in debug. Check console for details.');
    });
}

// Add this function to test the modal itself
function testReviewsModal() {
    document.getElementById('reviewsModalTitle').textContent = 'Test Modal';
    document.getElementById('garageReviewsContent').innerHTML = `
        <div class="p-4 bg-green-900/20 text-green-500 rounded-lg">
            <h4 class="font-semibold mb-2">Modal Test Successful!</h4>
            <p>The modal is working correctly. The issue is with the AJAX request.</p>
        </div>
    `;
    document.getElementById('garageReviewsModal').classList.remove('hidden');
}

// Auto-test function to run when page loads
document.addEventListener('DOMContentLoaded', function() {
    console.log('Reviews script loaded successfully');
    
    // Test if required elements exist
    const modal = document.getElementById('garageReviewsModal');
    const title = document.getElementById('reviewsModalTitle');
    const content = document.getElementById('garageReviewsContent');
    
    if (!modal) console.error('Missing garageReviewsModal element');
    if (!title) console.error('Missing reviewsModalTitle element');
    if (!content) console.error('Missing garageReviewsContent element');
    
    if (modal && title && content) {
        console.log('All required modal elements found ✓');
    }
});
</script>

<!-- Points Management Modal -->
<div id="pointsModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
        <h3 class="text-xl font-bold text-white mb-4">Manage User Points</h3>
        <form id="pointsForm">
            <input type="hidden" id="points_username" name="username">
            <input type="hidden" name="action" value="adjust_user_points">
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white">User</span>
                </label>
                <input type="text" id="points_user_display" class="input input-bordered bg-gray-700 text-white" readonly>
            </div>
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white">Current Points</span>
                </label>
                <input type="text" id="current_points_display" class="input input-bordered bg-gray-700 text-white" readonly>
            </div>
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white">Points Change</span>
                </label>
                <div class="flex gap-2">
                    
                    <input type="number" id="points_change" name="points_change" class="input input-bordered bg-gray-700 text-white flex-1" placeholder="Enter amount (+/-)" required>
                    
                </div>
                <label class="label">
                    <span class="label-text-alt text-white/60">Use positive numbers to add points, negative to subtract</span>
                </label>
            </div>
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white">Reason</span>
                </label>
                <select id="reason_select" class="select select-bordered bg-gray-700 text-white mb-2" onchange="updateReasonField()">
                    <option value="">Select a reason</option>
                    <option value="Admin bonus">Admin bonus</option>
                    <option value="Compensation">Compensation</option>
                    <option value="Promotion reward">Promotion reward</option>
                    <option value="System adjustment">System adjustment</option>
                    <option value="Custom">Custom reason</option>
                </select>
                <input type="text" id="reason" name="reason" class="input input-bordered bg-gray-700 text-white" placeholder="Enter reason for adjustment" required>
            </div>
            
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" class="btn btn-outline border-white/20 text-white" onclick="closePointsModal()">Cancel</button>
                <button type="submit" class="btn bg-primary hover:bg-primary-dark text-white border-none">Update Points</button>
            </div>
        </form>
    </div>
</div>

<!-- Points History Modal -->
<div id="pointsHistoryModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-white">Points History</h3>
            <button onclick="closePointsHistoryModal()" class="text-white/70 hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        
        <div id="pointsHistoryContent" class="text-white">
            <!-- Content will be loaded dynamically -->
            <div class="flex justify-center items-center h-40">
                <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
            </div>
        </div>
        
        <div class="flex justify-end mt-6">
            <button class="btn btn-outline border-white/20 text-white" onclick="closePointsHistoryModal()">Close</button>
        </div>
    </div>
</div>
<script>
// Complete fix for admin dashboard - Replace your JavaScript section with this

// Global variables to track chart instances
let bookingStatusChartInstance = null;
let revenueChartInstance = null;
let revenueProfitChartInstance = null;

// Function to safely destroy and recreate charts
function destroyChart(chartInstance) {
    if (chartInstance) {
        try {
            chartInstance.destroy();
        } catch (e) {
            console.warn('Error destroying chart:', e);
        }
    }
    return null;
}

// Fixed chart initialization functions
function initBookingStatusChart() {
    const ctx = document.getElementById('bookingStatusChart');
    if (!ctx) return;
    
    // Destroy existing chart if it exists
    bookingStatusChartInstance = destroyChart(bookingStatusChartInstance);
    
    const data = {
        labels: ['Upcoming', 'Active', 'Completed', 'Cancelled'],
        datasets: [{
            data: [
                <?php 
                $upcomingCount = 0;
                $activeCount = 0;
                $completedCount = 0;
                $cancelledCount = 0;
                
                foreach ($bookings as $booking) {
                    switch ($booking['status']) {
                        case 'upcoming': $upcomingCount++; break;
                        case 'active': $activeCount++; break;
                        case 'completed': $completedCount++; break;
                        case 'cancelled': $cancelledCount++; break;
                    }
                }
                echo $upcomingCount . ', ' . $activeCount . ', ' . $completedCount . ', ' . $cancelledCount;
                ?>
            ],
            backgroundColor: [
                'rgba(59, 130, 246, 0.7)',
                'rgba(16, 185, 129, 0.7)',
                'rgba(243, 156, 18, 0.7)',
                'rgba(239, 68, 68, 0.7)'
            ],
            borderColor: [
                'rgba(59, 130, 246, 1)',
                'rgba(16, 185, 129, 1)',
                'rgba(243, 156, 18, 1)',
                'rgba(239, 68, 68, 1)'
            ],
            borderWidth: 1
        }]
    };
    
    bookingStatusChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        color: 'white'
                    }
                }
            }
        }
    });
}

// Fixed points modal functions
function openPointsModal(username, currentPoints) {
    console.log('Opening points modal for:', username, 'Current points:', currentPoints);
    
    // Set form values
    document.getElementById('points_username').value = username;
    document.getElementById('points_user_display').value = username;
    document.getElementById('current_points_display').value = currentPoints + ' points';
    document.getElementById('points_change').value = '';
    document.getElementById('reason').value = '';
    
    // Reset reason select
    const reasonSelect = document.getElementById('reason_select');
    if (reasonSelect) {
        reasonSelect.selectedIndex = 0;
    }
    
    // Show modal
    document.getElementById('pointsModal').classList.remove('hidden');
}

// Function to close points modal
function closePointsModal() {
    document.getElementById('pointsModal').classList.add('hidden');
}

// Function to update reason field based on select
function updateReasonField() {
    const select = document.getElementById('reason_select');
    const input = document.getElementById('reason');
    
    if (select && input) {
        if (select.value && select.value !== 'Custom') {
            input.value = select.value;
            input.readOnly = true;
        } else {
            input.value = '';
            input.readOnly = false;
            input.focus();
        }
    }
}

// Fixed points form submission
function submitPointsForm(e) {
    e.preventDefault();
    e.stopPropagation();
    
    console.log('Points form submitted');
    
    const form = e.target;
    const formData = new FormData(form);
    const pointsChange = parseInt(formData.get('points_change'));
    const username = formData.get('username');
    const reason = formData.get('reason');
    
    // Validate inputs
    if (!pointsChange || isNaN(pointsChange)) {
        alert('Please enter a valid points change amount');
        return false;
    }
    
    if (!reason || reason.trim() === '') {
        alert('Please provide a reason for the points adjustment');
        return false;
    }
    
    // Confirmation
    const actionText = pointsChange > 0 ? 
        `add ${pointsChange} points to` : 
        `remove ${Math.abs(pointsChange)} points from`;
    
    if (!confirm(`Are you sure you want to ${actionText} ${username}?\n\nReason: ${reason}`)) {
        return false;
    }
    
    // Disable submit button
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Updating...';
    }
    
    // Send AJAX request
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'action': 'adjust_user_points',
            'username': username,
            'points_change': pointsChange,
            'reason': reason
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        console.log('Points adjustment raw response:', text);
        
        // Clean response
        let cleanText = text.trim();
        const jsonStart = cleanText.indexOf('{');
        const jsonEnd = cleanText.lastIndexOf('}') + 1;
        
        if (jsonStart !== -1 && jsonEnd > jsonStart) {
            cleanText = cleanText.substring(jsonStart, jsonEnd);
        }
        
        let data;
        try {
            data = JSON.parse(cleanText);
        } catch (e) {
            console.error('JSON parsing error:', e);
            throw new Error('Invalid JSON response from server');
        }
        
        if (data.success) {
            alert(data.message);
            closePointsModal();
            // Reload page to show updated points
            window.location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error adjusting points:', error);
        alert('Error adjusting points: ' + error.message);
    })
    .finally(() => {
        // Re-enable submit button
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Update Points';
        }
    });
    
    return false;
}

// Initialize form submission when DOM loads
document.addEventListener('DOMContentLoaded', function() {
    const pointsForm = document.getElementById('pointsForm');
    if (pointsForm) {
        pointsForm.addEventListener('submit', submitPointsForm);
        console.log('Points form event listener attached');
    } else {
        console.warn('Points form not found');
    }
    
    // Test if modal exists
    const pointsModal = document.getElementById('pointsModal');
    if (!pointsModal) {
        console.warn('Points modal not found in DOM');
    } else {
        console.log('Points modal found successfully');
    }
});

// Test function for points adjustment
function testPointsAdjustment() {
    console.log('Testing points adjustment...');
    
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=adjust_user_points&username=saba&points_change=5&reason=Test adjustment'
    })
    .then(response => response.text())
    .then(text => {
        console.log('Test response:', text);
        try {
            const data = JSON.parse(text);
            console.log('Test parsed data:', data);
            alert('Test result: ' + data.message);
        } catch (e) {
            console.error('Test parse error:', e);
            alert('Test failed: Invalid JSON response');
        }
    })
    .catch(error => {
        console.error('Test error:', error);
        alert('Test failed: ' + error.message);
    });
}

// Show tab content function
function showTabContent() {
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab') || 'dashboard';
    
    // Hide all tab content
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Show selected tab content
    const targetTab = document.getElementById(tab + '-tab');
    if (targetTab) {
        targetTab.classList.add('active');
    }
}
</script>

<script>
    // Complete Points History JavaScript - Add this to your admin.php

// Points History Modal Function
function viewPointsHistory(username) {
    console.log('Opening points history for:', username);
    
    // Show loading state
    document.getElementById('pointsHistoryContent').innerHTML = `
        <div class="flex justify-center items-center h-40">
            <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
            <p class="ml-4 text-white">Loading points history...</p>
        </div>
    `;
    
    // Show the modal
    document.getElementById('pointsHistoryModal').classList.remove('hidden');
    
    // Fetch points history
    const formData = new FormData();
    formData.append('action', 'get_user_points_history');
    formData.append('username', username);
    
    fetch('admin.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        console.log('Points history response status:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        console.log('Points history raw response:', text);
        try {
            const data = JSON.parse(text);
            console.log('Points history parsed data:', data);
            
            if (data.success) {
                displayPointsHistory(data, username);
            } else {
                document.getElementById('pointsHistoryContent').innerHTML = `
                    <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                        <p><strong>Error loading points history:</strong> ${data.message}</p>
                    </div>
                `;
            }
        } catch (jsonError) {
            console.error('JSON parsing error:', jsonError);
            document.getElementById('pointsHistoryContent').innerHTML = `
                <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                    <p><strong>Error:</strong> Invalid response from server</p>
                    <p class="text-sm mt-2">Check browser console for details</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error fetching points history:', error);
        document.getElementById('pointsHistoryContent').innerHTML = `
            <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                <p><strong>Network Error:</strong> ${error.message}</p>
                <p class="text-sm mt-2">Please check your connection and try again</p>
            </div>
        `;
    });
}

// Display Points History Function
function displayPointsHistory(data, username) {
    console.log('Raw data received:', data);
    
    // FIX: Handle both possible data structures
    let pointsData;
    if (data.data) {
        // Data is nested under 'data' key
        pointsData = data.data;
    } else {
        // Data is direct
        pointsData = data;
    }
    
    console.log('Points data:', pointsData);
    
    // FIX: Handle different field names for transactions
    const currentPoints = pointsData.current_points || 0;
    const history = pointsData.history || pointsData.transactions || [];
    
    console.log('Current points:', currentPoints);
    console.log('History length:', history.length);
    
    let content = `
        <div class="bg-gray-700/50 rounded-lg p-4 mb-6">
            <h4 class="text-lg font-semibold text-blue-400 mb-2">Current Status</h4>
            <div class="flex items-center gap-6">
                <div>
                    <p class="text-white/70 text-sm">User</p>
                    <p class="text-white font-medium text-lg">${username}</p>
                </div>
                <div>
                    <p class="text-white/70 text-sm">Current Points</p>
                    <p class="text-blue-400 text-3xl font-bold">${currentPoints.toLocaleString()}</p>
                </div>
            </div>
        </div>
    `;
    
    if (history && history.length > 0) {
        content += `
            <div>
                <h4 class="text-lg font-semibold text-blue-400 mb-4">Transaction History (Last ${history.length})</h4>
                <div class="overflow-x-auto max-h-96 overflow-y-auto">
                    <table class="w-full">
                        <thead class="sticky top-0 bg-gray-700">
                            <tr>
                                <th class="text-left px-4 py-3 text-white font-semibold">Date & Time</th>
                                <th class="text-left px-4 py-3 text-white font-semibold">Type</th>
                                <th class="text-left px-4 py-3 text-white font-semibold">Points</th>
                                <th class="text-left px-4 py-3 text-white font-semibold">Description</th>
                                <th class="text-left px-4 py-3 text-white font-semibold">Booking</th>
                            </tr>
                        </thead>
                        <tbody>
        `;
        
        history.forEach((transaction, index) => {
            const date = new Date(transaction.created_at).toLocaleString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });
            
            let typeClass = '';
            let typeIcon = '';
            let typeBadge = '';
            
            switch (transaction.transaction_type) {
                case 'earned':
                    typeClass = 'text-green-400';
                    typeIcon = '+';
                    typeBadge = 'bg-green-500/20 text-green-400';
                    break;
                case 'spent':
                    typeClass = 'text-red-400';
                    typeIcon = '-';
                    typeBadge = 'bg-red-500/20 text-red-400';
                    break;
                case 'bonus':
                    typeClass = 'text-blue-400';
                    typeIcon = '+';
                    typeBadge = 'bg-blue-500/20 text-blue-400';
                    break;
                default:
                    typeClass = 'text-gray-400';
                    typeIcon = '';
                    typeBadge = 'bg-gray-500/20 text-gray-400';
            }
            
            const rowClass = index % 2 === 0 ? 'bg-gray-800/30' : 'bg-gray-800/10';
            
            content += `
                <tr class="${rowClass} hover:bg-gray-700/30 transition-colors">
                    <td class="px-4 py-3 text-white/80 text-sm">${date}</td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 rounded-full text-xs font-medium ${typeBadge}">
                            ${transaction.transaction_type.charAt(0).toUpperCase() + transaction.transaction_type.slice(1)}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="${typeClass} font-bold text-lg">${typeIcon}${transaction.points_amount}</span>
                    </td>
                    <td class="px-4 py-3 text-white/80 text-sm">${transaction.description || 'No description'}</td>
                    <td class="px-4 py-3 text-center">
                        ${transaction.booking_id ? 
                            `<span class="bg-gray-600 text-white px-2 py-1 rounded text-xs">#${transaction.booking_id}</span>` : 
                            '<span class="text-white/40">-</span>'
                        }
                    </td>
                </tr>
            `;
        });
        
        content += `
                        </tbody>
                    </table>
                </div>
            </div>
        `;
        
        // Add summary stats
        const totalEarned = history.filter(t => t.transaction_type === 'earned').reduce((sum, t) => sum + parseInt(t.points_amount), 0);
        const totalSpent = history.filter(t => t.transaction_type === 'spent').reduce((sum, t) => sum + parseInt(t.points_amount), 0);
        const totalBonus = history.filter(t => t.transaction_type === 'bonus').reduce((sum, t) => sum + parseInt(t.points_amount), 0);
        
        content += `
            <div class="mt-6 grid grid-cols-3 gap-4">
                <div class="bg-green-500/10 border border-green-500/20 rounded-lg p-3 text-center">
                    <p class="text-green-400 text-sm">Total Earned</p>
                    <p class="text-green-400 text-xl font-bold">+${totalEarned}</p>
                </div>
                <div class="bg-red-500/10 border border-red-500/20 rounded-lg p-3 text-center">
                    <p class="text-red-400 text-sm">Total Spent</p>
                    <p class="text-red-400 text-xl font-bold">-${totalSpent}</p>
                </div>
                <div class="bg-blue-500/10 border border-blue-500/20 rounded-lg p-3 text-center">
                    <p class="text-blue-400 text-sm">Admin Bonus</p>
                    <p class="text-blue-400 text-xl font-bold">+${totalBonus}</p>
                </div>
            </div>
        `;
    } else {
        content += `
            <div class="text-center py-12">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mx-auto text-white/20 mb-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <h4 class="text-lg font-semibold text-white/70 mb-2">No Transaction History</h4>
                <p class="text-white/50">This user hasn't earned or spent any points yet.</p>
                <p class="text-white/40 text-sm mt-2">Points will appear here when they complete bookings or receive admin bonuses.</p>
            </div>
        `;
    }
    
    document.getElementById('pointsHistoryContent').innerHTML = content;
}

// Close Modal Function
function closePointsHistoryModal() {
    document.getElementById('pointsHistoryModal').classList.add('hidden');
}

// Test function to verify it's working
function testPointsHistory() {
    console.log('Testing points history...');
    viewPointsHistory('saba'); // Test with saba who has points
}

// Make sure the modal HTML exists - Add this function to check
function ensurePointsHistoryModal() {
    if (!document.getElementById('pointsHistoryModal')) {
        console.warn('Points History Modal not found! Adding it...');
        
        const modalHTML = `
            <div id="pointsHistoryModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
                <div class="bg-gray-800 rounded-lg p-6 w-full max-w-6xl max-h-[90vh] overflow-y-auto">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold text-white">Points History</h3>
                        <button onclick="closePointsHistoryModal()" class="text-white/70 hover:text-white">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    
                    <div id="pointsHistoryContent" class="text-white">
                        <!-- Content will be loaded dynamically -->
                    </div>
                    
                    <div class="flex justify-end mt-6">
                        <button class="btn btn-outline border-white/20 text-white" onclick="closePointsHistoryModal()">Close</button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        console.log('Points History Modal added successfully!');
    }
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Ensure modal exists
    ensurePointsHistoryModal();
    
    // Add click event listeners to all blue buttons
    document.querySelectorAll('button[onclick*="viewPointsHistory"]').forEach(button => {
        console.log('Found points history button:', button);
    });
    
    console.log('Points history functionality initialized');
});
</script>

<script>
// ===================================================================
// COMPLETE ADMIN DASHBOARD SCRIPT - FIXED VERSION
// ===================================================================

// Global chart instances
window.revenueTrendsChartInstance = null;
window.paymentMethodsChartInstance = null;
window.bookingStatusChartInstance = null;

// ===================================================================
// SINGLE DOM CONTENT LOADED EVENT LISTENER
// ===================================================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing admin dashboard...');
    
    // Initialize booking status chart for main dashboard
    try {
        if (document.getElementById('bookingStatusChart')) {
            initBookingStatusChart();
        }
    } catch (e) {
        console.error('Error initializing booking status chart:', e);
    }
    
    // Check active tab and load appropriate content
    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab') || 'dashboard';
    console.log('Active tab:', activeTab);
    
    // Load revenue analytics if revenue tab is active
    if (activeTab === 'revenue') {
        console.log('Revenue tab is active, loading analytics...');
        setTimeout(() => {
            loadRevenueAnalytics();
            setupPeriodFilterListener();
        }, 500);
    }
    
    // Add tab click listeners
    document.querySelectorAll('a[href*="tab=revenue"]').forEach(link => {
        link.addEventListener('click', function() {
            console.log('Revenue tab clicked, loading analytics...');
            setTimeout(() => {
                loadRevenueAnalytics();
                setupPeriodFilterListener();
            }, 200);
        });
    });
    
    // Setup period filter if it exists now
    setupPeriodFilterListener();
});

// ===================================================================
// PERIOD FILTER SETUP - SINGLE FUNCTION
// ===================================================================
function setupPeriodFilterListener() {
    const periodFilter = document.getElementById('revenue-period-filter');
    if (periodFilter && !periodFilter.hasEventListener) {
        console.log('Setting up period filter listener');
        
        periodFilter.addEventListener('change', function() {
            console.log('Period changed to:', this.value);
            
            // Reload all analytics with new period
            setTimeout(() => {
                loadRevenueAnalytics();
            }, 100);
        });
        
        // Mark that we've added the listener
        periodFilter.hasEventListener = true;
    }
}

// ===================================================================
// REVENUE ANALYTICS FUNCTIONS
// ===================================================================
function loadRevenueAnalytics() {
    console.log('Loading revenue analytics...');
    
    loadRevenueStats();
    loadRevenueTrends();
    loadPaymentMethodsData();
    loadTopGarages();
}

function loadRevenueStats() {
    console.log('🔄 Loading revenue stats for analytics page...');
    
    const periodFilter = document.getElementById('revenue-period-filter');
    const period = periodFilter ? periodFilter.value : 'last_7_days';
    
    fetch('admin.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `action=get_revenue_stats&period=${period}`
    })
    .then(response => {
        console.log('📡 Revenue stats response status:', response.status);
        return response.text();
    })
    .then(text => {
        console.log('📄 Revenue stats raw response:', text);
        
        // Clean the response (remove any PHP warnings or extra content)
        let cleanText = text.trim();
        const jsonStart = cleanText.indexOf('{');
        const jsonEnd = cleanText.lastIndexOf('}') + 1;
        
        if (jsonStart !== -1 && jsonEnd > jsonStart) {
            cleanText = cleanText.substring(jsonStart, jsonEnd);
        }
        
        try {
            const data = JSON.parse(cleanText);
            console.log('✅ Revenue stats parsed data:', data);
            
            if (data.success && data.data) {
                updateRevenueCards(data.data);
            } else {
                console.error('❌ Revenue stats failed:', data.message);
                showRevenueStatsError();
            }
        } catch (e) {
            console.error('❌ JSON parse error:', e);
            console.log('❌ Failed to parse text:', cleanText);
            showRevenueStatsError();
        }
    })
    .catch(error => {
        console.error('❌ Revenue stats fetch error:', error);
        showRevenueStatsError();
    });
}
function showRevenueStatsError() {
    console.log('❌ Showing revenue stats error state');
    
    // Set default values for all elements
    const elements = {
        totalRevenue: document.getElementById('total-revenue'),
        platformProfit: document.getElementById('platform-profit'),
        ownerEarnings: document.getElementById('owner-earnings'),
        pendingRevenue: document.getElementById('pending-revenue'),
        revenueChange: document.getElementById('revenue-change'),
        profitMargin: document.getElementById('profit-margin'),
        ownerPercentage: document.getElementById('owner-percentage')
    };
    
    Object.keys(elements).forEach(key => {
        const element = elements[key];
        if (element) {
            if (key.includes('Revenue') || key.includes('Profit') || key.includes('Earnings')) {
                element.textContent = '৳0';
            } else if (key === 'revenueChange') {
                element.textContent = '0 bookings';
            } else {
                element.textContent = 'Error';
            }
        }
    });
}
function updateRevenueCards(stats) {
    console.log('📊 Updating revenue cards with stats:', stats);
    
    // Get all the card elements
    const elements = {
        totalRevenue: document.getElementById('total-revenue'),
        platformProfit: document.getElementById('platform-profit'),
        ownerEarnings: document.getElementById('owner-earnings'),
        pendingRevenue: document.getElementById('pending-revenue'),
        revenueChange: document.getElementById('revenue-change'),
        profitMargin: document.getElementById('profit-margin'),
        ownerPercentage: document.getElementById('owner-percentage'),
        pendingCount: document.getElementById('pending-count')
    };
    
    console.log('📋 Found elements:', Object.keys(elements).filter(key => elements[key]));
    
    // Update Total Revenue
    if (elements.totalRevenue) {
        const totalRevenue = Number(stats.total_revenue || 0);
        elements.totalRevenue.textContent = '৳' + totalRevenue.toLocaleString();
        console.log('✅ Updated total revenue:', totalRevenue);
    }
    
    // Update Platform Profit
    if (elements.platformProfit) {
        const platformProfit = Number(stats.platform_profit || 0);
        elements.platformProfit.textContent = '৳' + platformProfit.toLocaleString();
        console.log('✅ Updated platform profit:', platformProfit);
    }
    
    // Update Owner Earnings
    if (elements.ownerEarnings) {
        const ownerEarnings = Number(stats.owner_earnings || 0);
        elements.ownerEarnings.textContent = '৳' + ownerEarnings.toLocaleString();
        console.log('✅ Updated owner earnings:', ownerEarnings);
    }
    
    // Update Pending Revenue - THIS IS THE KEY FIX!
    if (elements.pendingRevenue) {
        const pendingRevenue = Number(stats.pending_revenue || 0);
        elements.pendingRevenue.textContent = '৳' + pendingRevenue.toLocaleString();
        console.log('✅ Updated pending revenue:', pendingRevenue);
        
        // Also remove any "Loading..." text that might be in a parent container
        const pendingCard = elements.pendingRevenue.closest('.bg-gradient-to-br');
        if (pendingCard) {
            const loadingElements = pendingCard.querySelectorAll('*');
            loadingElements.forEach(el => {
                if (el.textContent === 'Loading...') {
                    el.textContent = pendingRevenue > 0 ? 'Payments pending' : 'No pending payments';
                }
            });
        }
    }
    
    // Update pending count if element exists
    if (elements.pendingCount) {
        const pendingRevenue = Number(stats.pending_revenue || 0);
        elements.pendingCount.textContent = pendingRevenue > 0 ? 'Payments pending' : 'No pending payments';
    }
    
    // Update booking count
    if (elements.revenueChange) {
        const totalBookings = Number(stats.total_bookings || 0);
        elements.revenueChange.textContent = `${totalBookings} bookings`;
        console.log('✅ Updated bookings count:', totalBookings);
    }
    
    // Update profit margin
    if (elements.profitMargin && stats.total_revenue > 0) {
        const margin = ((stats.platform_profit / stats.total_revenue) * 100).toFixed(1);
        elements.profitMargin.textContent = `${margin}% margin`;
        console.log('✅ Updated profit margin:', margin + '%');
    }
    
    // Update owner percentage
    if (elements.ownerPercentage && stats.total_revenue > 0) {
        const ownerPct = ((stats.owner_earnings / stats.total_revenue) * 100).toFixed(1);
        elements.ownerPercentage.textContent = `${ownerPct}% to owners`;
        console.log('✅ Updated owner percentage:', ownerPct + '%');
    }
    
    console.log('🎉 All revenue cards updated successfully!');
}

function loadRevenueTrends(period = 'last_7_days') {
    console.log('🔄 Loading revenue trends for:', period);
    
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `action=get_revenue_trends&period=${encodeURIComponent(period)}`
    })
    .then(response => response.text())
    .then(text => {
        console.log('📡 Raw response received');
        
        try {
            const data = JSON.parse(text);
            console.log('✅ Parsed data:', data);
            
            if (data.success && data.data && data.data.length > 0) {
                console.log(`📊 Creating chart with ${data.data.length} data points for ${data.period}`);
                updateRevenueTrendsChart(data.data, data.period);
            } else {
                console.log('📊 No data available for this period, showing empty chart');
                createEmptyTrendsChart(period);
            }
        } catch (e) {
            console.error('❌ JSON parse error:', e);
            createEmptyTrendsChart(period);
        }
    })
    .catch(error => {
        console.error('❌ Network error:', error);
        createEmptyTrendsChart(period);
    });
}
// Enhanced chart creation function
function updateRevenueTrendsChart(data, period) {
    console.log('🎨 Creating revenue trends chart with data:', data);
    
    const ctx = document.getElementById('revenueTrendsChart');
    if (!ctx) {
        console.error('❌ Chart canvas not found');
        return;
    }
    
    // Destroy existing chart
    if (window.revenueTrendsChartInstance) {
        window.revenueTrendsChartInstance.destroy();
        window.revenueTrendsChartInstance = null;
    }
    
    let labels = [];
    let revenueData = [];
    let profitData = [];
    
    // Process data
    data.forEach(item => {
        const date = new Date(item.date);
        let label;
        
        switch (period) {
            case 'today':
                label = 'Today';
                break;
            case 'this_year':
                label = date.toLocaleDateString('en-US', { month: 'short' });
                break;
            default:
                label = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }
        
        labels.push(label);
        revenueData.push(parseFloat(item.revenue || 0));
        profitData.push(parseFloat(item.profit || 0));
    });
    
    console.log('📈 Chart data prepared:', {
        labels: labels,
        revenue: revenueData,
        profit: profitData
    });
    
    // Create the chart
    window.revenueTrendsChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Revenue',
                data: revenueData,
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderColor: 'rgba(59, 130, 246, 1)',
                borderWidth: 3,
                tension: 0.4,
                fill: false,
                pointBackgroundColor: 'rgba(59, 130, 246, 1)',
                pointRadius: 6,
                pointHoverRadius: 8
            }, {
                label: 'Profit',
                data: profitData,
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                borderColor: 'rgba(16, 185, 129, 1)',
                borderWidth: 3,
                tension: 0.4,
                fill: false,
                pointBackgroundColor: 'rgba(16, 185, 129, 1)',
                pointRadius: 6,
                pointHoverRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { 
                        color: 'white',
                        font: { size: 14 }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: 'white',
                    bodyColor: 'white',
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ৳' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(255, 255, 255, 0.1)' },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)',
                        callback: function(value) { 
                            return '৳' + value.toLocaleString(); 
                        }
                    }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.1)' },
                    ticks: { color: 'rgba(255, 255, 255, 0.7)' }
                }
            }
        }
    });
    
    console.log('✅ Revenue trends chart created successfully');
}


function createEmptyTrendsChart(period) {
    const ctx = document.getElementById('revenueTrendsChart');
    if (!ctx) return;
    
    // Destroy existing chart
    if (window.revenueTrendsChartInstance) {
        window.revenueTrendsChartInstance.destroy();
        window.revenueTrendsChartInstance = null;
    }
    
    let labels = [];
    let revenueData = [];
    let profitData = [];
    
    switch (period) {
        case 'today':
            labels = ['Today'];
            revenueData = [0];
            profitData = [0];
            break;
        case 'last_7_days':
            for (let i = 6; i >= 0; i--) {
                const date = new Date();
                date.setDate(date.getDate() - i);
                labels.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
                revenueData.push(0);
                profitData.push(0);
            }
            break;
        case 'this_year':
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
            labels = months.slice(0, new Date().getMonth() + 1);
            revenueData = new Array(labels.length).fill(0);
            profitData = new Array(labels.length).fill(0);
            break;
        default:
            labels = ['No Data'];
            revenueData = [0];
            profitData = [0];
    }
    
    // Create empty chart with same structure
    window.revenueTrendsChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Revenue',
                data: revenueData,
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderColor: 'rgba(59, 130, 246, 1)',
                borderWidth: 2,
                tension: 0.4,
                fill: false,
                pointBackgroundColor: 'rgba(59, 130, 246, 1)',
                pointRadius: 4
            }, {
                label: 'Profit',
                data: profitData,
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                borderColor: 'rgba(16, 185, 129, 1)',
                borderWidth: 2,
                tension: 0.4,
                fill: false,
                pointBackgroundColor: 'rgba(16, 185, 129, 1)',
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: 'white' } }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(255, 255, 255, 0.1)' },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)',
                        callback: function(value) { return '৳' + value; }
                    }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.1)' },
                    ticks: { color: 'rgba(255, 255, 255, 0.7)' }
                }
            }
        }
    });
}

function loadPaymentMethodsData() {
    fetch('admin.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'action=get_payment_methods_data'
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                updatePaymentMethodsChart(data.data);
            } else {
                updatePaymentMethodsChart([]);
            }
        } catch (e) {
            console.error('Payment methods parse error:', e);
            updatePaymentMethodsChart([]);
        }
    })
    .catch(error => {
        console.error('Payment methods fetch error:', error);
        updatePaymentMethodsChart([]);
    });
}

function updatePaymentMethodsChart(data) {
    const ctx = document.getElementById('paymentMethodsChart');
    if (!ctx) return;
    
    if (window.paymentMethodsChartInstance) {
        window.paymentMethodsChartInstance.destroy();
    }
    
    let labels, amounts, colors;
    
    if (data && data.length > 0) {
        labels = data.map(item => {
            const method = item.method || item.payment_method;
            return method ? method.charAt(0).toUpperCase() + method.slice(1) : 'Unknown';
        });
        amounts = data.map(item => parseFloat(item.amount || item.total_amount || 0));
        colors = [
            'rgba(236, 72, 153, 0.8)', // bKash
            'rgba(59, 130, 246, 0.8)',  // Nagad
            'rgba(245, 158, 11, 0.8)',  // Points
            'rgba(16, 185, 129, 0.8)',  // Cash
            'rgba(139, 92, 246, 0.8)'   // Others
        ];
    } else {
        labels = ['Bkash', 'Nagad', 'Points'];
        amounts = [60, 30, 10];
        colors = [
            'rgba(236, 72, 153, 0.8)',
            'rgba(59, 130, 246, 0.8)',
            'rgba(245, 158, 11, 0.8)'
        ];
    }
    
    window.paymentMethodsChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: amounts,
                backgroundColor: colors,
                borderWidth: 2,
                borderColor: '#1f2937'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: 'white', padding: 20 }
                }
            }
        }
    });
}

function loadTopGarages() {
    fetch('admin.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'action=get_top_garages'
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                updateTopGaragesTable(data.data);
            } else {
                updateTopGaragesTable([]);
            }
        } catch (e) {
            console.error('Top garages parse error:', e);
            updateTopGaragesTable([]);
        }
    })
    .catch(error => {
        console.error('Top garages fetch error:', error);
        updateTopGaragesTable([]);
    });
}

function updateTopGaragesTable(data) {
    const tableBody = document.getElementById('top-garages-table');
    if (!tableBody) return;
    
    if (data && data.length > 0) {
        let html = '';
        data.forEach(garage => {
            html += `
                <tr class="border-b border-gray-700/50 hover:bg-white/5">
                    <td class="py-3 px-4">
                        <div>
                            <p class="font-medium text-white">${garage.garage_name || 'Unknown'}</p>
                            <p class="text-sm text-gray-400">${garage.garage_id || ''}</p>
                        </div>
                    </td>
                    <td class="py-3 px-4 text-white">${garage.owner || garage.owner_username || 'Unknown'}</td>
                    <td class="text-right py-3 px-4 text-blue-400 font-medium">৳${Number(garage.total_revenue || 0).toLocaleString()}</td>
                    <td class="text-right py-3 px-4 text-emerald-400 font-medium">৳${Number(garage.platform_profit || garage.total_profit || 0).toLocaleString()}</td>
                    <td class="text-right py-3 px-4 text-gray-300">${garage.total_bookings || 0}</td>
                    <td class="text-right py-3 px-4 text-purple-400 font-medium">৳${Number(garage.avg_per_booking || garage.avg_booking_value || 0).toFixed(0)}</td>
                </tr>
            `;
        });
        tableBody.innerHTML = html;
    } else {
        tableBody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-8 text-white/60">
                    <div class="text-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-white/20 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                        <p>No revenue data available yet</p>
                        <p class="text-sm mt-1">Complete some bookings to see analytics</p>
                    </div>
                </td>
            </tr>
        `;
    }
}

// ===================================================================
// BOOKING STATUS CHART FOR MAIN DASHBOARD
// ===================================================================
function initBookingStatusChart() {
    const ctx = document.getElementById('bookingStatusChart');
    if (!ctx) return;
    
    if (window.bookingStatusChartInstance) {
        window.bookingStatusChartInstance.destroy();
    }
    
    window.bookingStatusChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Upcoming', 'Active', 'Completed', 'Cancelled'],
            datasets: [{
                data: [12, 5, 28, 3],
                backgroundColor: [
                    'rgba(59, 130, 246, 0.7)',
                    'rgba(16, 185, 129, 0.7)',
                    'rgba(243, 156, 18, 0.7)',
                    'rgba(239, 68, 68, 0.7)'
                ],
                borderColor: [
                    'rgba(59, 130, 246, 1)',
                    'rgba(16, 185, 129, 1)',
                    'rgba(243, 156, 18, 1)',
                    'rgba(239, 68, 68, 1)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { color: 'white' }
                }
            }
        }
    });
}

// ===================================================================
// UTILITY FUNCTIONS
// ===================================================================
function exportRevenueReport() {
    console.log('Export feature - coming soon!');
    showNotification('Export feature coming soon!', 'info');
}

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 z-50 px-6 py-3 rounded-lg shadow-lg ${
        type === 'success' ? 'bg-green-600' : 
        type === 'error' ? 'bg-red-600' : 
        'bg-blue-600'
    } text-white`;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 3000);
}

// ===================================================================
// DEBUG FUNCTIONS
// ===================================================================
function debugRevenue() {
    console.log('Manual debug - loading revenue analytics...');
    loadRevenueAnalytics();
}

function testRevenueAjax() {
    console.log('Testing revenue AJAX...');
    
    fetch('admin.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'action=get_revenue_stats'
    })
    .then(response => response.text())
    .then(text => {
        console.log('Test response:', text);
        try {
            const data = JSON.parse(text);
            console.log('Test parsed data:', data);
        } catch (e) {
            console.error('Test JSON parse error:', e);
        }
    })
    .catch(error => console.error('Test fetch error:', error));
}

// Make functions globally available
window.loadRevenueAnalytics = loadRevenueAnalytics;
window.exportRevenueReport = exportRevenueReport;
window.debugRevenue = debugRevenue;
window.testRevenueAjax = testRevenueAjax;

console.log('💡 Debug functions available: debugRevenue(), testRevenueAjax()');
</script>

<!-- Add this modal HTML to your admin.php file, before the closing </body> tag -->

<!-- Document Verification Modal -->
<div id="verificationModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-6xl max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold text-white">Document Verification Review</h3>
            <button onclick="closeVerificationModal()" class="text-white/70 hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        
        <div id="verificationContent" class="text-white">
            <!-- Content will be loaded dynamically -->
            <div class="flex justify-center items-center h-40">
                <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
                <p class="ml-4 text-white">Loading verification documents...</p>
            </div>
        </div>
        
        <!-- Action buttons -->
        <div id="verificationActions" class="flex justify-between items-center mt-6 pt-6 border-t border-gray-700 hidden">
            <div class="flex-1">
                <label class="block text-white text-sm font-medium mb-2">Admin Notes:</label>
                <textarea id="adminNotes" class="w-full bg-gray-700 text-white border border-gray-600 rounded px-3 py-2 h-20 resize-none" placeholder="Add notes about your decision..."></textarea>
            </div>
            <div class="flex gap-3 ml-6">
                <button id="rejectBtn" class="btn btn-error" onclick="reviewVerification('reject')">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="15" y1="9" x2="9" y2="15"></line>
                        <line x1="9" y1="9" x2="15" y2="15"></line>
                    </svg>
                    Reject
                </button>
                <button id="approveBtn" class="btn btn-success" onclick="reviewVerification('approve')">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    Approve
                </button>
            </div>
        </div>
        
        <div class="flex justify-end mt-4">
            <button class="btn btn-outline border-white/20 text-white" onclick="closeVerificationModal()">Close</button>
        </div>
    </div>
</div>

<script>
// Global variable to store current verification data
let currentVerificationData = null;

// Function to open verification modal (call this when clicking unverified status)
function openVerificationModal(username) {
    console.log('Opening verification modal for:', username);
    
    // Create modal if it doesn't exist
    if (!document.getElementById('verificationModal')) {
        createVerificationModal();
    }
    
    // Show modal
    document.getElementById('verificationModal').classList.remove('hidden');
    
    // Reset content
    document.getElementById('verificationContent').innerHTML = `
        <div class="flex justify-center items-center h-40">
            <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-blue-500"></div>
            <p class="ml-4 text-white">Loading verification documents...</p>
        </div>
    `;
    
    // Hide action buttons initially
    const actionButtons = document.getElementById('verificationActions');
    if (actionButtons) {
        actionButtons.classList.add('hidden');
    }
    
    // Fetch verification documents
    fetchVerificationDocuments(username);
}

// Function to fetch verification documents
function fetchVerificationDocuments(username) {
    const formData = new FormData();
    formData.append('action', 'get_user_verification_docs');
    formData.append('username', username);
    
    fetch('admin.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        console.log('Raw response:', text);
        
        // Clean the response to extract JSON
        let cleanText = text.trim();
        const jsonStart = cleanText.indexOf('{');
        const jsonEnd = cleanText.lastIndexOf('}') + 1;
        
        if (jsonStart !== -1 && jsonEnd > jsonStart) {
            cleanText = cleanText.substring(jsonStart, jsonEnd);
        }
        
        try {
            const data = JSON.parse(cleanText);
            console.log('Parsed verification data:', data);
            
            if (data.success) {
                currentVerificationData = data;
                displayVerificationDocuments(data);
            } else {
                document.getElementById('verificationContent').innerHTML = `
                    <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                        <p><strong>Error:</strong> ${data.message}</p>
                    </div>
                `;
            }
        } catch (jsonError) {
            console.error('JSON parsing error:', jsonError);
            document.getElementById('verificationContent').innerHTML = `
                <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                    <p><strong>Error:</strong> Invalid response from server</p>
                    <p class="text-sm mt-2">Check browser console for details</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error fetching verification documents:', error);
        document.getElementById('verificationContent').innerHTML = `
            <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                <p><strong>Network Error:</strong> ${error.message}</p>
                <p class="text-sm mt-2">Please check your connection and try again</p>
            </div>
        `;
    });
}

// FIXED: Function to display verification documents with correct data structure
function displayVerificationDocuments(data) {
    // FIXED: Handle both possible data structures
    const responseData = data.data || data;
    const { username, verification_request, documents } = responseData;
    
    console.log('Displaying verification for:', username);
    console.log('Documents found:', documents?.length || 0);
    console.log('Verification request:', verification_request);
    
    let content = `
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- User Information -->
            <div class="bg-gray-700/30 rounded-lg p-4">
                <h4 class="text-lg font-semibold text-blue-400 mb-3">User Information</h4>
                <div class="space-y-2">
                    <div>
                        <span class="text-white/60 text-sm">Username:</span>
                        <p class="text-white font-medium">${username}</p>
                    </div>`;
    
    if (verification_request) {
        // FIXED: Handle null/empty full_name properly
        const fullName = verification_request.full_name?.trim() || 'Not provided';
        const email = verification_request.email || 'Not provided';
        const phone = verification_request.phone || 'Not provided';
        const accountStatus = verification_request.account_status || 'unverified';
        const requestType = verification_request.request_type || 'Not specified';
        
        content += `
                    <div>
                        <span class="text-white/60 text-sm">Full Name:</span>
                        <p class="text-white">${fullName}</p>
                    </div>
                    <div>
                        <span class="text-white/60 text-sm">Email:</span>
                        <p class="text-white">${email}</p>
                    </div>
                    <div>
                        <span class="text-white/60 text-sm">Phone:</span>
                        <p class="text-white">${phone}</p>
                    </div>
                    <div>
                        <span class="text-white/60 text-sm">Current Status:</span>
                        <span class="px-2 py-1 rounded text-xs ${accountStatus === 'verified' ? 'bg-green-500/20 text-green-400' : 'bg-yellow-500/20 text-yellow-400'}">${accountStatus}</span>
                    </div>
                    <div>
                        <span class="text-white/60 text-sm">Request Type:</span>
                        <p class="text-white capitalize">${requestType}</p>
                    </div>`;
        
        if (verification_request.requested_at) {
            content += `
                    <div>
                        <span class="text-white/60 text-sm">Requested At:</span>
                        <p class="text-white text-sm">${new Date(verification_request.requested_at).toLocaleString()}</p>
                    </div>`;
        }
    }
    
    content += `
                </div>
            </div>
            
            <!-- Documents Section -->
            <div class="lg:col-span-2">
                <h4 class="text-lg font-semibold text-blue-400 mb-4">Submitted Documents (${documents?.length || 0})</h4>`;
    
    if (documents && documents.length > 0) {
        content += `<div class="grid grid-cols-1 md:grid-cols-2 gap-4">`;
        
        documents.forEach(doc => {
            const statusClass = doc.status === 'approved' ? 'bg-green-500/20 text-green-400' : 
                               doc.status === 'rejected' ? 'bg-red-500/20 text-red-400' : 
                               'bg-yellow-500/20 text-yellow-400';
            
            const submittedDate = new Date(doc.submitted_at).toLocaleDateString();
            const fileSize = (doc.file_size / 1024 / 1024).toFixed(2); // Convert to MB
            const documentType = doc.document_type_display || doc.document_type || 'Document';
            
            content += `
                <div class="bg-gray-700/50 border border-gray-600 rounded-lg p-4 hover:bg-gray-700/70 transition-colors">
                    <div class="flex justify-between items-start mb-3">
                        <h5 class="font-medium text-white">${documentType}</h5>
                        <span class="px-2 py-1 rounded text-xs ${statusClass}">${doc.status}</span>
                    </div>
                    
                    <div class="space-y-2 text-sm mb-4">
                        <div>
                            <span class="text-white/60">Document Number:</span>
                            <span class="text-white ml-2">${doc.document_number || 'Not provided'}</span>
                        </div>
                        <div>
                            <span class="text-white/60">File Name:</span>
                            <span class="text-white ml-2">${doc.original_filename}</span>
                        </div>
                        <div>
                            <span class="text-white/60">File Size:</span>
                            <span class="text-white ml-2">${fileSize} MB</span>
                        </div>
                        <div>
                            <span class="text-white/60">Submitted:</span>
                            <span class="text-white ml-2">${submittedDate}</span>
                        </div>
                        ${doc.reviewed_at ? `
                        <div>
                            <span class="text-white/60">Reviewed:</span>
                            <span class="text-white ml-2">${new Date(doc.reviewed_at).toLocaleDateString()}</span>
                        </div>` : ''}
                        ${doc.reviewed_by ? `
                        <div>
                            <span class="text-white/60">Reviewed by:</span>
                            <span class="text-white ml-2">${doc.reviewed_by}</span>
                        </div>` : ''}
                    </div>
                    
                    <div class="flex gap-2">
                        <button class="btn btn-sm btn-primary flex-1" onclick="viewDocument('${doc.file_path}', '${doc.original_filename}')">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            View Document
                        </button>
                        <button class="btn btn-sm btn-outline" onclick="downloadDocument('${doc.file_path}', '${doc.original_filename}')">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>
                            </svg>
                        </button>
                    </div>
                    
                    ${doc.rejection_reason ? `
                    <div class="mt-3 p-2 bg-red-900/20 rounded">
                        <p class="text-red-400 text-sm"><strong>Rejection Reason:</strong> ${doc.rejection_reason}</p>
                    </div>` : ''}
                </div>`;
        });
        
        content += `</div>`;
        
        // Add admin notes section if there are documents
        content += `
            <div class="mt-6">
                <h5 class="text-lg font-semibold text-blue-400 mb-3">Admin Review</h5>
                <textarea id="adminNotes" placeholder="Add your review notes here..." 
                          class="w-full p-3 bg-gray-600 text-white rounded-lg border border-gray-500 focus:border-blue-400 focus:outline-none"
                          rows="3">${verification_request?.admin_notes || ''}</textarea>
            </div>`;
        
    } else {
        content += `
            <div class="text-center py-12">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mx-auto text-white/20 mb-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14,2 14,8 20,8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <polyline points="10,9 9,9 8,9"></polyline>
                </svg>
                <h4 class="text-lg font-semibold text-white/70 mb-2">No Documents Submitted</h4>
                <p class="text-white/50">This user hasn't submitted any verification documents yet.</p>
                <button onclick="simpleVerifyUser('${username}')" class="mt-4 px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                    Verify Without Documents
                </button>
            </div>`;
    }
    
    content += `
            </div>
        </div>`;
    
    document.getElementById('verificationContent').innerHTML = content;
    
    // Show action buttons only if there are pending documents
    if (documents && documents.length > 0) {
        const hasPendingDocs = documents.some(doc => doc.status === 'pending');
        if (hasPendingDocs) {
            const actionButtons = document.getElementById('verificationActions');
            if (actionButtons) {
                actionButtons.classList.remove('hidden');
            }
        }
    }
}

// Function to view document
function viewDocument(filePath, fileName) {
    console.log('Viewing document:', filePath);
    // Create a new window to display the document
    window.open(filePath, '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
}

// Function to download document
function downloadDocument(filePath, fileName) {
    console.log('Downloading document:', filePath);
    // Create a temporary link to download the file
    const link = document.createElement('a');
    link.href = filePath;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// FIXED: Function to review verification (approve/reject)
function reviewVerification(decision) {
    if (!currentVerificationData) {
        alert('No verification data available');
        return;
    }
    
    const adminNotes = document.getElementById('adminNotes')?.value?.trim();
    if (!adminNotes) {
        alert('Please add admin notes before making a decision');
        return;
    }
    
    // FIXED: Get username from correct data structure
    const username = currentVerificationData.data?.username || currentVerificationData.username;
    const actionText = decision === 'approve' ? 'approve' : 'reject';
    
    if (!confirm(`Are you sure you want to ${actionText} this verification request for ${username}?`)) {
        return;
    }
    
    // Disable buttons if they exist
    const approveBtn = document.getElementById('approveBtn');
    const rejectBtn = document.getElementById('rejectBtn');
    if (approveBtn) approveBtn.disabled = true;
    if (rejectBtn) rejectBtn.disabled = true;
    
    const formData = new FormData();
    formData.append('action', 'review_user_verification');
    formData.append('username', username);
    formData.append('decision', decision);
    formData.append('admin_notes', adminNotes);
    
    fetch('admin.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        console.log('Review response:', text);
        
        // Clean response
        let cleanText = text.trim();
        const jsonStart = cleanText.indexOf('{');
        const jsonEnd = cleanText.lastIndexOf('}') + 1;
        
        if (jsonStart !== -1 && jsonEnd > jsonStart) {
            cleanText = cleanText.substring(jsonStart, jsonEnd);
        }
        
        try {
            const data = JSON.parse(cleanText);
            
            if (data.success) {
                alert(data.message);
                closeVerificationModal();
                // Refresh the page to update the user status
                window.location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            alert('Error processing response');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error occurred: ' + error.message);
    })
    .finally(() => {
        // Re-enable buttons
        if (approveBtn) approveBtn.disabled = false;
        if (rejectBtn) rejectBtn.disabled = false;
    });
}

// Function to close verification modal
function closeVerificationModal() {
    const modal = document.getElementById('verificationModal');
    if (modal) {
        modal.classList.add('hidden');
    }
    currentVerificationData = null;
    
    // Clear admin notes
    const adminNotes = document.getElementById('adminNotes');
    if (adminNotes) {
        adminNotes.value = '';
    }
}

// FIXED: Update the existing verifyUser function to use the new modal
function verifyUser(username) {
    console.log('Verifying user:', username);
    
    // Check if user has submitted verification documents
    const formData = new FormData();
    formData.append('action', 'get_user_verification_docs');
    formData.append('username', username);
    
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        console.log('Verify user response:', text);
        
        // Clean response
        let cleanText = text.trim();
        const jsonStart = cleanText.indexOf('{');
        const jsonEnd = cleanText.lastIndexOf('}') + 1;
        
        if (jsonStart !== -1 && jsonEnd > jsonStart) {
            cleanText = cleanText.substring(jsonStart, jsonEnd);
        }
        
        let data;
        try {
            data = JSON.parse(cleanText);
        } catch (e) {
            throw new Error('Invalid JSON response');
        }
        
        console.log('Verification check data:', data);
        
        if (data.success) {
            // FIXED: Check the correct data structure for documents
            const documents = data.data?.documents || data.documents || [];
            
            if (documents.length > 0) {
                // User has submitted documents, open verification modal
                console.log(`User ${username} has ${documents.length} document(s), opening verification modal`);
                openVerificationModal(username);
            } else {
                // No documents submitted, use simple verification
                console.log(`User ${username} has no documents, asking for simple verification`);
                if (confirm('This user has not submitted any verification documents. Do you want to verify them anyway?')) {
                    simpleVerifyUser(username);
                }
            }
        } else {
            console.error('Error fetching verification docs:', data.message);
            // Fallback to simple verification
            if (confirm('Error checking verification documents. Do you want to verify this user anyway?')) {
                simpleVerifyUser(username);
            }
        }
    })
    .catch(error => {
        console.error('Error checking verification documents:', error);
        // Fallback to simple verification
        if (confirm('Network error occurred. Do you want to verify this user anyway?')) {
            simpleVerifyUser(username);
        }
    });
}

// ADDED: Simple verification function for users without documents
function simpleVerifyUser(username) {
    if (!confirm(`Are you sure you want to verify user "${username}" without reviewing documents?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'verify_user');
    formData.append('username', username);
    
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        console.log('Simple verify response:', text);
        
        let data;
        try {
            data = JSON.parse(text.trim());
        } catch (e) {
            throw new Error('Invalid JSON response');
        }
        
        if (data.success) {
            alert(data.message);
            window.location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error verifying user:', error);
        alert('Error verifying user: ' + error.message);
    });
}

// ADDED: Function to create verification modal if it doesn't exist
function createVerificationModal() {
    const modalHTML = `
        <div id="verificationModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
            <div class="bg-gray-800 rounded-lg p-6 w-full max-w-6xl max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-bold text-white">User Verification</h3>
                    <button onclick="closeVerificationModal()" class="text-white/70 hover:text-white">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                
                <div id="verificationContent" class="text-white">
                    <!-- Content will be loaded dynamically -->
                </div>
                
                <div id="verificationActions" class="hidden flex justify-center gap-4 mt-6">
                    <button id="rejectBtn" onclick="reviewVerification('reject')" class="px-6 py-2 bg-red-600 text-white rounded hover:bg-red-700">
                        Reject
                    </button>
                    <button id="approveBtn" onclick="reviewVerification('approve')" class="px-6 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                        Approve
                    </button>
                </div>
                
                <div class="flex justify-end mt-6">
                    <button class="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700" onclick="closeVerificationModal()">Close</button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    console.log('Verification modal created successfully');
}

// ADDED: Test function
function testVerification() {
    console.log('Testing verification for noman...');
    verifyUser('noman');
}
</script>
<!-- Garage Control Modal -->
<div id="garageControlModal" class="modal">
    <div class="modal-box w-11/12 max-w-5xl bg-gray-800">
        <h3 class="font-bold text-lg text-white mb-4">Garage Control Panel</h3>
        
        <!-- Garage Info Section -->
        <div id="garageInfo" class="bg-gray-700 p-4 rounded-lg mb-4">
            <h4 class="font-semibold text-white mb-2">Garage Information</h4>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <span class="text-gray-400">Status:</span>
                    <div id="currentStatus" class="badge badge-sm mt-1">LOADING...</div>
                </div>
                <div>
                    <span class="text-gray-400">Active Bookings:</span>
                    <div id="activeBookings" class="text-white">-</div>
                </div>
                <div>
                    <span class="text-gray-400">Capacity:</span>
                    <div id="garageCapacity" class="text-white">-</div>
                </div>
                <div>
                    <span class="text-gray-400">Price/Hour:</span>
                    <div id="garagePrice" class="text-white">-</div>
                </div>
            </div>
        </div>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Status Control Section -->
            <div class="bg-gray-700 p-4 rounded-lg">
                <h4 class="font-semibold text-white mb-3">Status Control</h4>
                
                <div class="form-control mb-3">
                    <label class="label">
                        <span class="label-text text-gray-300">Change Status</span>
                    </label>
                    <select id="statusSelect" class="select select-bordered bg-gray-600 text-white">
                        <option value="open">Open</option>
                        <option value="closed">Closed</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="emergency_closed">Emergency Closed</option>
                    </select>
                </div>
                
                <div class="form-control mb-3">
                    <label class="label">
                        <span class="label-text text-gray-300">Reason</span>
                    </label>
                    <textarea id="statusReason" class="textarea textarea-bordered bg-gray-600 text-white" placeholder="Enter reason for status change..."></textarea>
                </div>
                
                <div class="form-control mb-4">
                    <label class="cursor-pointer label">
                        <span class="label-text text-gray-300">Force Close (Ignore active bookings)</span>
                        <input id="forceClose" type="checkbox" class="checkbox checkbox-warning" />
                    </label>
                </div>
                
                <button id="updateStatusBtn" class="btn btn-primary w-full">Update Status</button>
            </div>
            
            <!-- Schedule Control Section -->
            <div class="bg-gray-700 p-4 rounded-lg">
                <h4 class="font-semibold text-white mb-3">Operating Schedule</h4>
                
                <div class="form-control mb-3">
                    <label class="cursor-pointer label">
                        <span class="label-text text-gray-300">24/7 Operation</span>
                        <input id="is24_7" type="checkbox" class="checkbox checkbox-primary" onchange="toggle24Hours()" />
                    </label>
                </div>
                
                <div id="timeControls" class="mb-3">
                    <div class="grid grid-cols-2 gap-2">
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text text-gray-300 text-sm">Open Time</span>
                            </label>
                            <input id="openTime" type="time" class="input input-bordered bg-gray-600 text-white input-sm" value="09:00">
                        </div>
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text text-gray-300 text-sm">Close Time</span>
                            </label>
                            <input id="closeTime" type="time" class="input input-bordered bg-gray-600 text-white input-sm" value="22:00">
                        </div>
                    </div>
                </div>
                
                <div class="form-control mb-4">
                    <label class="label">
                        <span class="label-text text-gray-300">Operating Days</span>
                    </label>
                    <div class="grid grid-cols-4 gap-1 text-xs">
                        <label class="cursor-pointer label py-1">
                            <span class="label-text text-gray-300">Mon</span>
                            <input id="monday" type="checkbox" class="checkbox checkbox-xs" checked />
                        </label>
                        <label class="cursor-pointer label py-1">
                            <span class="label-text text-gray-300">Tue</span>
                            <input id="tuesday" type="checkbox" class="checkbox checkbox-xs" checked />
                        </label>
                        <label class="cursor-pointer label py-1">
                            <span class="label-text text-gray-300">Wed</span>
                            <input id="wednesday" type="checkbox" class="checkbox checkbox-xs" checked />
                        </label>
                        <label class="cursor-pointer label py-1">
                            <span class="label-text text-gray-300">Thu</span>
                            <input id="thursday" type="checkbox" class="checkbox checkbox-xs" checked />
                        </label>
                        <label class="cursor-pointer label py-1">
                            <span class="label-text text-gray-300">Fri</span>
                            <input id="friday" type="checkbox" class="checkbox checkbox-xs" checked />
                        </label>
                        <label class="cursor-pointer label py-1">
                            <span class="label-text text-gray-300">Sat</span>
                            <input id="saturday" type="checkbox" class="checkbox checkbox-xs" checked />
                        </label>
                        <label class="cursor-pointer label py-1">
                            <span class="label-text text-gray-300">Sun</span>
                            <input id="sunday" type="checkbox" class="checkbox checkbox-xs" />
                        </label>
                    </div>
                </div>
                
                <button id="updateScheduleBtn" class="btn btn-secondary w-full">Update Schedule</button>
            </div>
        </div>
        
        <!-- Quick Actions Section -->
        <div class="bg-gray-700 p-4 rounded-lg mt-4">
            <h4 class="font-semibold text-white mb-3">Quick Actions</h4>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                <button onclick="quickStatus('open')" class="btn btn-sm btn-success">Open Now</button>
                <button onclick="quickStatus('closed')" class="btn btn-sm btn-error">Close Now</button>
                <button onclick="quickStatus('maintenance')" class="btn btn-sm btn-warning">Maintenance</button>
                <button onclick="quickStatus('emergency_closed')" class="btn btn-sm btn-error">Emergency Close</button>
            </div>
        </div>
        
        <!-- Temporary Override Section -->
        <div class="bg-gray-700 p-4 rounded-lg mt-4">
            <h4 class="font-semibold text-white mb-3">Temporary Override</h4>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="form-control">
                    <label class="label">
                        <span class="label-text text-gray-300 text-sm">Override Until</span>
                    </label>
                    <input id="overrideUntil" type="datetime-local" class="input input-bordered bg-gray-600 text-white input-sm">
                </div>
                <div class="form-control">
                    <label class="label">
                        <span class="label-text text-gray-300 text-sm">Action</span>
                    </label>
                    <select id="overrideAction" class="select select-bordered bg-gray-600 text-white select-sm">
                        <option value="open">Force Open</option>
                        <option value="closed">Force Closed</option>
                    </select>
                </div>
                <div class="form-control">
                    <label class="label">
                        <span class="label-text text-gray-300 text-sm">&nbsp;</span>
                    </label>
                    <button id="applyOverrideBtn" class="btn btn-warning btn-sm">Apply Override</button>
                </div>
            </div>
        </div>
        
        <div class="modal-action">
            <button class="btn" onclick="closeGarageControl()">Close</button>
        </div>
    </div>
</div>
<script>
let currentGarageId = null;

function openGarageControl(garageId) {
    currentGarageId = garageId;
    document.getElementById('garageControlModal').classList.add('modal-open');
    loadGarageData(garageId);
    
    // Set default override time to 1 hour from now
    const now = new Date();
    now.setHours(now.getHours() + 1);
    document.getElementById('overrideUntil').value = now.toISOString().slice(0, 16);
}

function closeGarageControl() {
    document.getElementById('garageControlModal').classList.remove('modal-open');
    currentGarageId = null;
}

function loadGarageData(garageId) {
    console.log('Loading garage data for:', garageId);
    
    // Show loading state in all display elements - USING CORRECT IDs FROM YOUR HTML
    const statusElement = document.getElementById('currentStatus');        // Changed from 'garageInfoStatus'
    const bookingsElement = document.getElementById('activeBookings');     // Changed from 'garageInfoBookings'
    const capacityElement = document.getElementById('garageCapacity');     // Changed from 'garageInfoCapacity'
    const priceElement = document.getElementById('garagePrice');           // Changed from 'garageInfoPrice'
    
    if (statusElement) statusElement.textContent = 'LOADING...';
    if (bookingsElement) bookingsElement.textContent = '-';
    if (capacityElement) capacityElement.textContent = '-';
    if (priceElement) priceElement.textContent = '-';
    
    // Create form data
    const formData = new FormData();
    formData.append('action', 'get_garage_status');
    formData.append('garage_id', garageId);
    
    fetch('admin.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return response.text().then(text => {
            console.log('Raw garage status response:', text.substring(0, 500));
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response text:', text);
                throw new Error('Invalid JSON response: ' + text.substring(0, 100));
            }
        });
    })
    .then(data => {
        console.log('Garage status data:', data);
        
        if (data.success) {
            // Fix the data structure access - use data.status instead of data.data.status
            const status = data.status;
            const schedule = data.schedule;
            
            console.log('Status object:', status);
            console.log('Schedule object:', schedule);
            
            // Update garage info display with null checks - USING CORRECT IDs
            if (status) {
                if (statusElement) {
                    const currentStatus = status.current_status || 'UNKNOWN';
                    statusElement.textContent = currentStatus.toUpperCase();
                    
                    // Add status styling using badge classes
                    statusElement.className = 'badge badge-sm ' + getStatusBadgeClass(currentStatus);
                }
                
                if (bookingsElement) {
                    bookingsElement.textContent = status.active_bookings_count || 0;
                }
                
                if (capacityElement) {
                    capacityElement.textContent = status.total_capacity || status.Parking_Capacity || '-';
                }
                
                if (priceElement) {
                    const price = status.price_per_hour || status.PriceperHour;
                    priceElement.textContent = price ? `৳${price}` : '-';
                }
                
                // Update status control dropdown if it exists
                const statusSelect = document.getElementById('statusSelect');
                if (statusSelect) {
                    statusSelect.value = status.current_status || 'open';
                }
            }
            
            // Update schedule if available
            if (schedule) {
                console.log('Updating schedule with:', schedule);
                
                const is24_7 = document.getElementById('is24_7');
                const openTime = document.getElementById('openTime');
                const closeTime = document.getElementById('closeTime');
                
                if (is24_7) is24_7.checked = schedule.is_24_7 == 1;
                if (openTime) openTime.value = schedule.opening_time || '09:00';
                if (closeTime) closeTime.value = schedule.closing_time || '22:00';
                
                // Update operating days checkboxes
                const operatingDays = schedule.operating_days ? schedule.operating_days.split(',') : [];
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].forEach(day => {
                    const checkbox = document.getElementById(day);
                    if (checkbox) {
                        checkbox.checked = operatingDays.includes(day);
                    }
                });
                
                // Toggle time controls based on 24/7 setting
                toggle24Hours();
            }
        } else {
            console.error('Error loading garage data:', data.message);
            
            // Show error in status
            if (statusElement) {
                statusElement.textContent = 'ERROR';
                statusElement.className = 'badge badge-sm badge-error';
            }
            
            // Don't show alert for debugging - just log it
            console.error('Backend error:', data.message);
        }
    })
    .catch(error => {
        console.error('Error loading garage data:', error);
        
        // Show error in status
        if (statusElement) {
            statusElement.textContent = 'ERROR';
            statusElement.className = 'badge badge-sm badge-error';
        }
        
        // Don't show alert for debugging - just log it
        console.error('Network/Parse error:', error.message);
    });
}

// Helper function to get status badge class (matches your existing CSS)
function getStatusBadgeClass(status) {
    switch (status.toLowerCase()) {
        case 'open':
            return 'badge-success';
        case 'closed':
            return 'badge-error';
        case 'maintenance':
            return 'badge-warning';
        case 'emergency_closed':
            return 'badge-error';
        default:
            return 'badge-neutral';
    }
}

// Debug function to test garage status loading
function debugGarageStatus(garageId) {
    console.log('=== DEBUGGING GARAGE STATUS ===');
    console.log('Garage ID:', garageId);
    
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            'action': 'get_garage_status',
            'garage_id': garageId
        })
    })
    .then(response => {
        console.log('Debug Response Status:', response.status);
        return response.text();
    })
    .then(text => {
        console.log('Debug Raw Response:', text);
        console.log('Debug Response Length:', text.length);
        
        try {
            const data = JSON.parse(text);
            console.log('Debug Parsed Data:', data);
            alert('Debug completed! Check console for full details.');
        } catch (e) {
            console.error('Debug JSON Parse Error:', e);
            alert('Parse error in debug. Check console for details.');
        }
    })
    .catch(error => {
        console.error('Debug Fetch Error:', error);
        alert('Network error in debug. Check console for details.');
    });
}

function toggle24Hours() {
    const is24_7 = document.getElementById('is24_7').checked;
    document.getElementById('timeControls').style.display = is24_7 ? 'none' : 'block';
}

// Quick status change function
function quickStatus(status) {
    const statusSelect = document.getElementById('statusSelect');
    const statusReason = document.getElementById('statusReason');
    
    if (statusSelect) statusSelect.value = status;
    if (statusReason) statusReason.value = `Quick ${status} action by admin`;
    
    updateGarageStatus();
}

// Event listeners for control buttons
document.getElementById('updateStatusBtn').addEventListener('click', updateGarageStatus);
document.getElementById('updateScheduleBtn').addEventListener('click', updateGarageSchedule);
document.getElementById('applyOverrideBtn').addEventListener('click', applyTemporaryOverride);

// Update garage status function
function updateGarageStatus() {
    const statusSelect = document.getElementById('statusSelect');
    const statusReason = document.getElementById('statusReason');
    const forceClose = document.getElementById('forceClose');
    
    if (!statusSelect || !statusReason) {
        alert('Status control elements not found');
        return;
    }
    
    const status = statusSelect.value;
    const reason = statusReason.value;
    const forceCloseChecked = forceClose ? forceClose.checked : false;
    
    if (!reason && status !== 'open') {
        alert('Please provide a reason for status change');
        return;
    }
    
    if (!confirm(`Change garage status to "${status.toUpperCase()}"?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update_garage_status');
    formData.append('garage_id', currentGarageId);
    formData.append('status', status);
    formData.append('reason', reason);
    if (forceCloseChecked) formData.append('force_close', '1');
    
    fetch('admin.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            loadGarageData(currentGarageId); // Reload data
            // Don't reload the entire page - just refresh the status
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error updating status');
    });
}

function updateGarageSchedule() {
    const is24_7 = document.getElementById('is24_7');
    const openTime = document.getElementById('openTime');
    const closeTime = document.getElementById('closeTime');
    
    if (!is24_7 || !openTime || !closeTime) {
        alert('Schedule control elements not found');
        return;
    }
    
    const is24_7_checked = is24_7.checked;
    const openTimeValue = openTime.value;
    const closeTimeValue = closeTime.value;
    
    const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    const operatingDays = days.filter(day => {
        const checkbox = document.getElementById(day);
        return checkbox && checkbox.checked;
    });
    
    if (!is24_7_checked && (!openTimeValue || !closeTimeValue)) {
        alert('Please set opening and closing times');
        return;
    }
    
    if (operatingDays.length === 0) {
        alert('Please select at least one operating day');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update_garage_schedule');
    formData.append('garage_id', currentGarageId);
    if (is24_7_checked) formData.append('is_24_7', '1');
    formData.append('opening_time', is24_7_checked ? '' : openTimeValue);
    formData.append('closing_time', is24_7_checked ? '' : closeTimeValue);
    formData.append('operating_days', operatingDays.join(','));
    
    fetch('admin.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            loadGarageData(currentGarageId);
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error updating schedule');
    });
}

// Apply temporary override function
function applyTemporaryOverride() {
    const overrideUntil = document.getElementById('overrideUntil');
    const overrideAction = document.getElementById('overrideAction');
    
    if (!overrideUntil || !overrideAction) {
        alert('Override control elements not found');
        return;
    }
    
    const overrideUntilValue = overrideUntil.value;
    const overrideActionValue = overrideAction.value;
    
    if (!overrideUntilValue) {
        alert('Please select an end time for the override');
        return;
    }
    
    const endTime = new Date(overrideUntilValue);
    if (endTime <= new Date()) {
        alert('Override end time must be in the future');
        return;
    }
    
    if (!confirm(`Apply temporary override to "${overrideActionValue}" until ${endTime.toLocaleString()}?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'apply_temporary_override');
    formData.append('garage_id', currentGarageId);
    formData.append('override_until', overrideUntilValue);
    formData.append('override_action', overrideActionValue);
    formData.append('reason', 'Temporary admin override');
    
    fetch('admin.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            loadGarageData(currentGarageId);
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error applying override');
    });
}

// Set up event listeners when the page loads
document.addEventListener('DOMContentLoaded', function() {
    // Add event listeners for buttons if they exist
    const updateStatusBtn = document.getElementById('updateStatusBtn');
    const updateScheduleBtn = document.getElementById('updateScheduleBtn');
    const applyOverrideBtn = document.getElementById('applyOverrideBtn');
    
    if (updateStatusBtn) {
        updateStatusBtn.addEventListener('click', updateGarageStatus);
    }
    
    if (updateScheduleBtn) {
        updateScheduleBtn.addEventListener('click', updateGarageSchedule);
    }
    
    if (applyOverrideBtn) {
        applyOverrideBtn.addEventListener('click', applyTemporaryOverride);
    }
    
    // Set default override time to 1 hour from now
    const overrideUntil = document.getElementById('overrideUntil');
    if (overrideUntil) {
        const now = new Date();
        now.setHours(now.getHours() + 1);
        overrideUntil.value = now.toISOString().slice(0, 16);
    }
});

// Close modal when clicking outside
document.getElementById('garageControlModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeGarageControl();
    }
});

// Add some additional utility functions
function refreshGarageStatus() {
    if (currentGarageId) {
        loadGarageData(currentGarageId);
    }
}

function viewGarageBookings() {
    // This would open a view showing current and upcoming bookings
    alert('Viewing garage bookings... (Feature to be implemented)');
}

function exportGarageReport() {
    // This would generate and download a report for the garage
    alert('Exporting garage report... (Feature to be implemented)');
}

// Auto-refresh garage data every 30 seconds when modal is open
setInterval(() => {
    if (currentGarageId && document.getElementById('garageControlModal').classList.contains('modal-open')) {
        refreshGarageStatus();
    }
}, 30000);
</script>

<!-- Additional CSS for better styling -->
<style>
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.modal-open {
    display: flex;
}

.badge-success {
    background-color: #22c55e;
    color: white;
}

.badge-error {
    background-color: #ef4444;
    color: white;
}

.badge-warning {
    background-color: #f59e0b;
    color: white;
}

.badge-neutral {
    background-color: #6b7280;
    color: white;
}

/* Make buttons more visible in dark theme */
.btn-outline {
    border-width: 1px;
}

.btn-outline:hover {
    background-color: currentColor;
    color: white;
}

/* Improve form controls in dark theme */
.select, .input, .textarea {
    border-color: #4b5563;
}

.select:focus, .input:focus, .textarea:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Status indicator animations */
.badge {
    transition: all 0.3s ease;
}

.badge.badge-success {
    animation: pulse-green 2s infinite;
}

@keyframes pulse-green {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .modal-box {
        width: 95%;
        margin: 1rem;
    }
    
    .grid.grid-cols-2.md\\:grid-cols-4 {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .grid.grid-cols-1.md\\:grid-cols-3 {
        grid-template-columns: 1fr;
    }
}
</style>

</body>
</html>