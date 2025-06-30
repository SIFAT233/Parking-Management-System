<?php

date_default_timezone_set('Asia/Dhaka');
// Start the session at the very top
session_start();


function getManualGarageStatus($space) {
    // PRIORITY 1: Check for maintenance (highest priority)
    if (isset($space['current_status']) && $space['current_status'] === 'maintenance') {
        return 'maintenance';
    }
    
    // PRIORITY 2: Check for emergency closure
    if (isset($space['current_status']) && $space['current_status'] === 'emergency_closed') {
        return 'emergency_closed';
    }
    
    // PRIORITY 3: Check for MANUAL OVERRIDE (key fix!)
    if (isset($space['is_manual_override']) && $space['is_manual_override'] == 1 && 
        isset($space['current_status']) && $space['current_status'] === 'closed') {
        return 'closed'; // Owner manually closed it (even 24/7 garages)
    }
    
    // PRIORITY 4: For 24/7 garages - they are open by default
    if (isset($space['is_24_7']) && $space['is_24_7']) {
        return 'open'; // 24/7 garages are open unless manually overridden above
    }
    
    // PRIORITY 5: Regular schedule logic for non-24/7 garages
    $currentDay = strtolower(date('l'));
    $currentTime = date('H:i:s');
    
    $operatingDays = isset($space['operating_days']) ? $space['operating_days'] : '';
    
    if (!empty($operatingDays)) {
        $operatingDaysArray = explode(',', strtolower($operatingDays));
        $operatingDaysArray = array_map('trim', $operatingDaysArray);
        
        if (!in_array($currentDay, $operatingDaysArray)) {
            return 'closed';
        }
        
        $openingTime = isset($space['opening_time']) ? $space['opening_time'] : '06:00:00';
        $closingTime = isset($space['closing_time']) ? $space['closing_time'] : '22:00:00';
        
        if ($currentTime >= $openingTime && $currentTime <= $closingTime) {
            return isset($space['current_status']) ? $space['current_status'] : 'open';
        } else {
            return 'closed';
        }
    }
    
    return isset($space['current_status']) ? $space['current_status'] : 'open';
}

// Add this at the top of your business_desh.php file after session_start()
if (isset($_GET['debug_notifications'])) {
    echo "<pre>";
    echo "SESSION last_check: " . (isset($_SESSION['last_notification_check']) ? $_SESSION['last_notification_check'] : "Not set") . "\n";
    
    $username = $_SESSION['username'];
    $query = "SELECT last_check_time FROM user_notification_checks WHERE username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo "DB last_check: " . $row['last_check_time'] . "\n";
    } else {
        echo "DB last_check: Not found in database\n";
    }
    
    echo "</pre>";
}
// For connecting to database
require_once("connection.php");

// Check if user is logged in, redirect to login page if not
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Get user information from database
$username = $_SESSION['username'];
$fullName = $username; // Default to username
$email = ""; // Default empty email

// Try to get user's personal information
$query = "SELECT * FROM personal_information WHERE email LIKE '%$username%' OR firstName LIKE '%$username%' OR lastName LIKE '%$username%'";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $fullName = $row['firstName'] . ' ' . $row['lastName'];
    $email = $row['email'];
    
    // Store in session for future use
    $_SESSION['fullName'] = $fullName;
    $_SESSION['email'] = $email;
} else {
    // Set defaults in session
    $_SESSION['fullName'] = $username;
    $_SESSION['email'] = "";
}

// Get first letter for avatar
$firstLetter = strtoupper(substr($fullName, 0, 1));



// Add this to the beginning of business_desh.php, after session_start()

// Handle AJAX requests for notifications
if (isset($_POST['action'])) {
    $response = ['success' => false];
    
    switch ($_POST['action']) {
        case 'get_notification_items':
    $username = $_SESSION['username'];
    $userNotifications = getUserNotifications($conn, $username);
    
    $response = [
        'success' => true,
        'bookings' => $userNotifications['bookings'],
        'verifications' => $userNotifications['verifications'],
        'garage_verifications' => $userNotifications['garage_verifications'],
        'payments' => $userNotifications['payments']
    ];
    break;
            
        case 'get_notification_counts':
    $username = $_SESSION['username'];
    $userNotifications = getUserNotifications($conn, $username);


    // Get notifications
$userNotifications = getUserNotifications($conn, $username);


    

    $totalNotifications = count($userNotifications['bookings']) + 
                      count($userNotifications['verifications']) +
                      count($userNotifications['garage_verifications']) +
                      count($userNotifications['payments']);
                      
    $response = [
        'success' => true,
        'counts' => [
            'bookings' => count($userNotifications['bookings']),
            'verifications' => count($userNotifications['verifications']),
            'garage_verifications' => count($userNotifications['garage_verifications']),
            'payments' => count($userNotifications['payments']),
            'total' => $totalNotifications
        ]
    ];
    break;
            
        case 'mark_all_read':
    $currentTime = date('Y-m-d H:i:s');
    $username = $_SESSION['username']; // Make sure username is explicitly set
    
    // Start a transaction to ensure database consistency
    $conn->begin_transaction();
    try {
        // Update session timestamp
        $_SESSION['last_notification_check'] = $currentTime;
        
        // Update database timestamp
        $query = "INSERT INTO user_notification_checks (username, last_check_time)
                  VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE last_check_time = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sss", $username, $currentTime, $currentTime);
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        $response = ['success' => true];
    } catch (Exception $e) {
        // Roll back on error
        $conn->rollback();
        error_log("Failed to update notification timestamp: " . $e->getMessage());
        $response = ['success' => false, 'message' => 'Database error'];
    }
    break;

    
    case 'get_parking_details':
    if (isset($_POST['garage_id'])) {
        $garageId = $_POST['garage_id'];
        $username = $_SESSION['username'];
        
        $query = "SELECT g.*, l.Latitude, l.Longitude, go.is_verified as owner_verified,
                 grs.average_rating, grs.total_ratings, grs.five_star, grs.four_star, 
                 grs.three_star, grs.two_star, grs.one_star,
                 (SELECT COUNT(*) FROM bookings b WHERE b.garage_id = g.garage_id AND b.status = 'active') as active_bookings,
                 (SELECT COUNT(*) FROM bookings b WHERE b.garage_id = g.garage_id) as total_bookings
                 FROM garage_information g
                 LEFT JOIN garagelocation l ON g.garage_id = l.garage_id
                 LEFT JOIN garage_owners go ON g.username = go.username
                 LEFT JOIN garage_ratings_summary grs ON g.garage_id = grs.garage_id
                 WHERE g.garage_id = ? AND g.username = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $garageId, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $response = [
                'success' => true,
                'data' => $row
            ];
        } else {
            $response['message'] = 'Parking space not found';
        }
    }
    break;
            
        case 'update_parking_space':
    if (isset($_POST['garage_id']) && isset($_POST['parking_name']) &&
        isset($_POST['parking_capacity']) && isset($_POST['price_per_hour'])) {
       
        $garageId = $_POST['garage_id'];
        $parkingName = trim($_POST['parking_name']);
        $parkingCapacity = intval($_POST['parking_capacity']);
        $pricePerHour = floatval($_POST['price_per_hour']);
        $username = $_SESSION['username'];
       
        // Validation
        if (empty($parkingName)) {
            $response['message'] = 'Parking name is required';
            break;
        }
        if ($parkingCapacity <= 0) {
            $response['message'] = 'Parking capacity must be greater than 0';
            break;
        }
        if ($pricePerHour <= 0) {
            $response['message'] = 'Price per hour must be greater than 0';
            break;
        }
       
        // Count occupied bookings
        $occupiedBookingsQuery = "SELECT COUNT(*) as occupied_count 
                                 FROM bookings 
                                 WHERE garage_id = ? 
                                 AND status IN ('upcoming', 'active')";
        $stmt = $conn->prepare($occupiedBookingsQuery);
        $stmt->bind_param("s", $garageId);
        $stmt->execute();
        $occupiedResult = $stmt->get_result();
        $occupiedBookings = 0;
       
        if ($occupiedResult && $occupiedResult->num_rows > 0) {
            $row = $occupiedResult->fetch_assoc();
            $occupiedBookings = intval($row['occupied_count']);
        }
       
        // Calculate availability properly
        $newAvailability = max(0, $parkingCapacity - $occupiedBookings);
       
        // Debug logging
        error_log("Updating garage: ID=$garageId, Capacity=$parkingCapacity, OccupiedBookings=$occupiedBookings, NewAvailability=$newAvailability");
       
        // Update database - DIFFERENT QUERIES BASED ON WHETHER IMAGE WAS UPLOADED
        if ($uploadedImagePath) {
            // Update WITH new image
            $query = "UPDATE garage_information
                     SET Parking_Space_Name = ?, Parking_Capacity = ?, PriceperHour = ?, 
                         Availability = ?, garage_image = ?, updated_at = NOW()
                     WHERE garage_id = ? AND username = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sidssss", $parkingName, $parkingCapacity, $pricePerHour, 
                             $newAvailability, $uploadedImagePath, $garageId, $username);
            
            error_log("Updating WITH image: " . $uploadedImagePath);
        } else {
            // Update WITHOUT changing image
            $query = "UPDATE garage_information
                     SET Parking_Space_Name = ?, Parking_Capacity = ?, PriceperHour = ?, 
                         Availability = ?, updated_at = NOW()
                     WHERE garage_id = ? AND username = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sidiss", $parkingName, $parkingCapacity, $pricePerHour, 
                             $newAvailability, $garageId, $username);
            
            error_log("Updating WITHOUT changing image");
        }
       
        if ($stmt->execute()) {
            $rowsAffected = $stmt->affected_rows;
            error_log("Rows affected: $rowsAffected");
           
            if ($rowsAffected > 0) {
                $message = "Parking space updated successfully. Available spaces: $newAvailability";
                if ($uploadedImagePath) {
                    $message .= " Image uploaded to: " . $uploadedImagePath;
                }
                $response = [
                    'success' => true,
                    'message' => $message,
                    'uploaded_image' => $uploadedImagePath,
                    'new_availability' => $newAvailability
                ];
                error_log("SUCCESS: " . $message);
            } else {
                $response['message'] = 'No rows were updated - garage not found or no changes made';
                error_log("WARNING: No database rows affected");
            }
        } else {
            error_log("SQL Error: " . $stmt->error);
            $response['message'] = 'Failed to update parking space: ' . $stmt->error;
        }
    } else {
        $response['message'] = 'Missing required fields';
        error_log("Missing fields: " . print_r($_POST, true));
        error_log("Files received: " . print_r($_FILES, true));
    }
    break;
            
        case 'get_parking_list':
            $username = $_SESSION['username'];
            $parkingSpaces = getParkingSpacesSummary($conn, $username);
            $garageVerificationStatus = getGarageVerificationStatus($conn, $username, $parkingSpaces);
            
            $response = [
                'success' => true,
                'data' => $parkingSpaces,
                'verification_status' => $garageVerificationStatus
            ];
            break;

            
case 'get_garage_reviews':
    if (isset($_POST['garage_id'])) {
        $garageId = $_POST['garage_id'];
        $username = $_SESSION['username'];
        
        // Get detailed reviews with customer info
        $reviewsQuery = "SELECT 
                            r.id,
                            r.rating,
                            r.review_text,
                            r.created_at,
                            r.updated_at,
                            COALESCE(CONCAT(p.firstName, ' ', p.lastName), r.rater_username) as customer_name,
                            r.rater_username,
                            b.booking_date,
                            b.booking_time,
                            b.duration,
                            DATEDIFF(NOW(), r.created_at) as days_ago
                        FROM ratings r
                        JOIN bookings b ON r.booking_id = b.id
                        JOIN garage_information g ON r.garage_id = g.garage_id
                        LEFT JOIN personal_information p ON r.rater_username = p.username
                        WHERE r.garage_id = ? AND g.username = ?
                        ORDER BY r.created_at DESC";
        
        $stmt = $conn->prepare($reviewsQuery);
        $stmt->bind_param("ss", $garageId, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $reviews = [];
        while ($row = $result->fetch_assoc()) {
            $reviews[] = [
                'id' => $row['id'],
                'rating' => floatval($row['rating']),
                'review_text' => $row['review_text'] ?: 'No comment provided',
                'customer_name' => $row['customer_name'],
                'booking_date' => date('M d, Y', strtotime($row['booking_date'])),
                'booking_time' => date('h:i A', strtotime($row['booking_time'])),
                'duration' => $row['duration'],
                'created_at' => date('M d, Y h:i A', strtotime($row['created_at'])),
                'days_ago' => max(0, intval($row['days_ago'])),
                'is_recent' => intval($row['days_ago']) <= 7
            ];
        }
        
        $response = [
            'success' => true,
            'reviews' => $reviews,
            'total_reviews' => count($reviews)
        ];
    } else {
        $response = ['success' => false, 'message' => 'Garage ID required'];
    }
    break;

    // New AJAX action for getting garage timing data
case 'get_garage_timing':
    if (isset($_POST['garage_id'])) {
        $garageId = $_POST['garage_id'];
        $username = $_SESSION['username'];
        
        $query = "SELECT 
                    gi.garage_id, 
                    gi.Parking_Space_Name, 
                    gi.Parking_Capacity, 
                    gi.PriceperHour,
                    gos.opening_time,
                    gos.closing_time,
                    gos.operating_days,
                    gos.is_24_7,
                    grts.current_status,
                    grts.is_manual_override,
                    grts.active_bookings_count,
                    grts.can_close_after,
                    grts.override_until,
                    grts.override_reason,
                    grts.force_closed
                  FROM garage_information gi
                  LEFT JOIN garage_operating_schedule gos ON gi.garage_id = gos.garage_id
                  LEFT JOIN garage_real_time_status grts ON gi.garage_id = grts.garage_id
                  WHERE gi.garage_id = ? AND gi.username = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $garageId, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            
            // Convert 24-hour to 12-hour format
            $openingTime12 = $row['opening_time'] ? date('h:i A', strtotime($row['opening_time'])) : '06:00 AM';
            $closingTime12 = $row['closing_time'] ? date('h:i A', strtotime($row['closing_time'])) : '10:00 PM';
            
            // Parse operating days
            $operatingDays = $row['operating_days'] ? explode(',', $row['operating_days']) : [];
            
            $response = [
                'success' => true,
                'data' => [
                    'garage_id' => $row['garage_id'],
                    'name' => $row['Parking_Space_Name'],
                    'capacity' => $row['Parking_Capacity'],
                    'price' => $row['PriceperHour'],
                    'opening_time' => $row['opening_time'],
                    'closing_time' => $row['closing_time'],
                    'opening_time_12' => $openingTime12,
                    'closing_time_12' => $closingTime12,
                    'operating_days' => $operatingDays,
                    'is_24_7' => $row['is_24_7'],
                    'current_status' => $row['current_status'] ?: 'open',
                    'is_manual_override' => $row['is_manual_override'] ?: false,
                    'active_bookings_count' => $row['active_bookings_count'] ?: 0,
                    'can_close_after' => $row['can_close_after'],
                    'override_until' => $row['override_until'],
                    'override_reason' => $row['override_reason'],
                    'force_closed' => $row['force_closed'] ?: false
                ]
            ];
        } else {
            $response = ['success' => false, 'message' => 'Garage not found'];
        }
    }
    break;
    // Add this case to your switch statement in business_desh.php (around line where other cases are)

case 'get_garage_status':
    if (isset($_POST['garage_id'])) {
        $garageId = $_POST['garage_id'];
        $username = $_SESSION['username'];
        
        // Get real-time status and basic garage info
        $query = "SELECT 
                    grts.current_status,
                    grts.active_bookings_count,
                    grts.is_manual_override,
                    grts.override_until,
                    grts.override_reason,
                    grts.force_closed,
                    grts.can_close_after,
                    grts.last_changed_at,
                    grts.changed_by,
                    gi.Parking_Space_Name,
                    gi.Parking_Capacity,
                    gi.PriceperHour
                  FROM garage_real_time_status grts
                  JOIN garage_information gi ON grts.garage_id = gi.garage_id
                  WHERE grts.garage_id = ? AND gi.username = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $garageId, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $data = $result->fetch_assoc();
            $response = [
                'success' => true,
                'garage_data' => [
                    'current_status' => $data['current_status'] ?: 'open',
                    'active_bookings_count' => intval($data['active_bookings_count'] ?: 0),
                    'is_manual_override' => boolval($data['is_manual_override']),
                    'override_until' => $data['override_until'],
                    'override_reason' => $data['override_reason'],
                    'force_closed' => boolval($data['force_closed']),
                    'can_close_after' => $data['can_close_after'],
                    'last_changed_at' => $data['last_changed_at'],
                    'changed_by' => $data['changed_by'],
                    'garage_name' => $data['Parking_Space_Name'],
                    'capacity' => intval($data['Parking_Capacity']),
                    'price_per_hour' => floatval($data['PriceperHour'])
                ]
            ];
        } else {
            // If garage not found in real-time status, check if it exists and create entry
            $checkGarage = "SELECT garage_id FROM garage_information WHERE garage_id = ? AND username = ?";
            $checkStmt = $conn->prepare($checkGarage);
            $checkStmt->bind_param("ss", $garageId, $username);
            $checkStmt->execute();
            $garageExists = $checkStmt->get_result()->num_rows > 0;
            
            if ($garageExists) {
                // Create missing real-time status entry
                $insertStatus = "INSERT INTO garage_real_time_status 
                                (garage_id, current_status, active_bookings_count, changed_by) 
                                VALUES (?, 'open', 0, ?)";
                $insertStmt = $conn->prepare($insertStatus);
                $insertStmt->bind_param("ss", $garageId, $username);
                $insertStmt->execute();
                
                // Return default values
                $response = [
                    'success' => true,
                    'garage_data' => [
                        'current_status' => 'open',
                        'active_bookings_count' => 0,
                        'is_manual_override' => false,
                        'override_until' => null,
                        'override_reason' => null,
                        'force_closed' => false,
                        'can_close_after' => null,
                        'last_changed_at' => date('Y-m-d H:i:s'),
                        'changed_by' => $username
                    ]
                ];
            } else {
                $response = ['success' => false, 'message' => 'Garage not found'];
            }
        }
    } else {
        $response = ['success' => false, 'message' => 'Missing garage_id parameter'];
    }
    break;
case 'get_operating_schedule':
    if (isset($_POST['garage_id'])) {
        $garageId = $_POST['garage_id'];
        $username = $_SESSION['username'];
        
        $query = "SELECT 
                    gos.opening_time,
                    gos.closing_time,
                    gos.operating_days,
                    gos.is_24_7,
                    gi.Parking_Space_Name,
                    gi.Parking_Capacity,
                    gi.PriceperHour
                  FROM garage_operating_schedule gos
                  JOIN garage_information gi ON gos.garage_id = gi.garage_id
                  WHERE gos.garage_id = ? AND gi.username = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $garageId, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $response = [
                'success' => true,
                'data' => $row
            ];
        } else {
            $response = ['success' => false, 'message' => 'Operating schedule not found'];
        }
    }
    break;
    
// New AJAX action for updating garage timing
case 'update_garage_timing':
    if (isset($_POST['garage_id'])) {
        $garageId = $_POST['garage_id'];
        $username = $_SESSION['username'];
        
        // Get form data
        $parkingName = trim($_POST['parking_name']);
        $parkingCapacity = intval($_POST['parking_capacity']);
        $pricePerHour = floatval($_POST['price_per_hour']);
        
        // Timing data
        $is24_7 = isset($_POST['is_24_7']) ? 1 : 0;
        
        // FIX: Handle 24/7 logic properly
        if ($is24_7) {
            // 24/7 MODE: Set to all day, all week
            $openingTime = '00:00:00';
            $closingTime = '23:59:00';
            $operatingDays = 'monday,tuesday,wednesday,thursday,friday,saturday,sunday';
        } else {
            // REGULAR MODE: Use user-selected values
            $openingTime = $_POST['opening_time'] ?: '06:00:00';
            $closingTime = $_POST['closing_time'] ?: '22:00:00';
            $operatingDays = isset($_POST['operating_days']) ? implode(',', $_POST['operating_days']) : '';
        }
        
        // Real-time control
$changeReason = $_POST['change_reason'] ?? 'Schedule update';

// Get current status from database if not provided
if (isset($_POST['current_status']) && !empty($_POST['current_status'])) {
    $currentStatus = $_POST['current_status'];
} else {
    // Get existing status from database
    $getCurrentStatus = "SELECT current_status FROM garage_real_time_status WHERE garage_id = ?";
    $stmt = $conn->prepare($getCurrentStatus);
    $stmt->bind_param("s", $garageId);
    $stmt->execute();
    $result = $stmt->get_result();
    $currentStatus = $result->fetch_assoc()['current_status'] ?? 'open';
}
        
        $conn->begin_transaction();
        
        try {
            // Update garage information
            $query1 = "UPDATE garage_information 
                       SET Parking_Space_Name = ?, Parking_Capacity = ?, PriceperHour = ?
                       WHERE garage_id = ? AND username = ?";
            $stmt1 = $conn->prepare($query1);
            $stmt1->bind_param("sidss", $parkingName, $parkingCapacity, $pricePerHour, $garageId, $username);
            $stmt1->execute();
            
            // Update or insert operating schedule
            $query2 = "INSERT INTO garage_operating_schedule 
                       (garage_id, opening_time, closing_time, operating_days, is_24_7)
                       VALUES (?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE
                       opening_time = VALUES(opening_time),
                       closing_time = VALUES(closing_time),
                       operating_days = VALUES(operating_days),
                       is_24_7 = VALUES(is_24_7)";
            $stmt2 = $conn->prepare($query2);
            $stmt2->bind_param("ssssi", $garageId, $openingTime, $closingTime, $operatingDays, $is24_7);
            $stmt2->execute();
            
            // Update real-time status
            $query3 = "UPDATE garage_real_time_status 
                       SET current_status = ?, changed_by = ?, override_reason = ?
                       WHERE garage_id = ?";
            $stmt3 = $conn->prepare($query3);
            $stmt3->bind_param("ssss", $currentStatus, $username, $changeReason, $garageId);
            $stmt3->execute();
            
            $conn->commit();
            $response = ['success' => true, 'message' => 'Garage timing updated successfully!'];
            
        } catch (Exception $e) {
            $conn->rollback();
            $response = ['success' => false, 'message' => 'Failed to update: ' . $e->getMessage()];
        }
    }
    break;

// New AJAX action for real-time garage control
case 'control_garage_status':
    if (isset($_POST['garage_id']) && isset($_POST['task_to_perform'])) {
        $garageId = $_POST['garage_id'];
        $action = $_POST['task_to_perform'];
        $username = $_SESSION['username'];
        $forceClose = isset($_POST['force_close']) ? intval($_POST['force_close']) : 0;
        $reason = $_POST['reason'] ?? 'Manual control';
        
        error_log("Processing control action: $action for garage: $garageId");

        try {
            switch ($action) {
                case 'close':
                    $query = "UPDATE garage_real_time_status 
                             SET current_status = 'closed', 
                                 is_manual_override = TRUE, 
                                 force_closed = ?, 
                                 override_reason = ?, 
                                 changed_by = ?,
                                 last_changed_at = NOW()
                             WHERE garage_id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("isss", $forceClose, $reason, $username, $garageId);
                    break;
                    
                case 'open':
                    $query = "UPDATE garage_real_time_status 
                             SET current_status = 'open', 
                                 is_manual_override = FALSE, 
                                 force_closed = FALSE, 
                                 override_until = NULL, 
                                 override_reason = NULL, 
                                 changed_by = ?,
                                 last_changed_at = NOW()
                             WHERE garage_id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("ss", $username, $garageId);
                    break;
                    
                case 'maintenance':
                    $duration = intval($_POST['duration'] ?? 1);
                    $overrideUntil = date('Y-m-d H:i:s', strtotime("+{$duration} hours"));
                    $query = "UPDATE garage_real_time_status 
                             SET current_status = 'maintenance', 
                                 is_manual_override = TRUE, 
                                 override_until = ?, 
                                 override_reason = ?, 
                                 changed_by = ?,
                                 last_changed_at = NOW()
                             WHERE garage_id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("ssss", $overrideUntil, $reason, $username, $garageId);
                    break;
                    
                default:
                    throw new Exception('Invalid control task specified: ' . htmlspecialchars($action));
            }

            if (isset($stmt) && $stmt) {
                $executeResult = $stmt->execute();
                error_log("Query execution result: " . ($executeResult ? 'success' : 'failed'));
                error_log("Affected rows: " . $stmt->affected_rows);
                
                if ($executeResult && $stmt->affected_rows > 0) {
                    $response = ['success' => true, 'message' => 'Status updated successfully!'];
                    error_log("Success: Status updated for garage $garageId to $action");
                } else {
                    // Check if garage exists
                    $checkQuery = "SELECT COUNT(*) as count FROM garage_real_time_status WHERE garage_id = ?";
                    $checkStmt = $conn->prepare($checkQuery);
                    $checkStmt->bind_param("s", $garageId);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result()->fetch_assoc();
                    
                    if ($checkResult['count'] == 0) {
                        $response = ['success' => false, 'message' => 'Garage not found in real-time status table.'];
                        error_log("Error: Garage $garageId not found in real_time_status table");
                    } else {
                        $response = ['success' => true, 'message' => 'No changes made - status may already be set to requested value.'];
                        error_log("Info: No changes made for garage $garageId - possibly already in requested state");
                    }
                }
            } else {
                throw new Exception('Failed to prepare database statement');
            }

        } catch (Exception $e) {
            error_log("Exception in control_garage_status: " . $e->getMessage());
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
    } else {
        $missingParams = [];
        if (!isset($_POST['garage_id'])) $missingParams[] = 'garage_id';
        if (!isset($_POST['task_to_perform'])) $missingParams[] = 'task_to_perform';
        
        $response = ['success' => false, 'message' => 'Missing required parameters: ' . implode(', ', $missingParams)];
        error_log("Missing required parameters: " . implode(', ', $missingParams));
    }
    break;
}
    // Return JSON response for AJAX requests
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}




// Function to get notifications for the current user
function getUserNotifications($conn, $username) {
    $notifications = [
        'bookings' => [],
        'verifications' => [],
        'garage_verifications' => [],
        'payments' => []
    ];
   
    // Set a default check time (24 hours ago) if not set in session
    if (!isset($_SESSION['last_notification_check'])) {
        $_SESSION['last_notification_check'] = date('Y-m-d H:i:s', strtotime('-24 hours'));
    }
    $lastCheck = $_SESSION['last_notification_check'];
   
    // সেশন থেকে last_check কে ডাটাবেস থেকে পাওয়া মানে আপডেট করুন
    $checkQuery = "SELECT last_check_time FROM user_notification_checks WHERE username = ?";
    $stmt = $conn->prepare($checkQuery);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // ADD THIS IMPROVED CODE HERE
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastCheck = $row['last_check_time'];
        // Debug log to see what timestamp is being used
        error_log("Using DB timestamp for {$username}: {$lastCheck}");
        // Update session with database value
        $_SESSION['last_notification_check'] = $lastCheck;
    } else {
        // No record found in database
        if (!isset($_SESSION['last_notification_check'])) {
            $_SESSION['last_notification_check'] = date('Y-m-d H:i:s', strtotime('-24 hours'));
            error_log("No DB record, setting default timestamp for {$username}");
        }
        $lastCheck = $_SESSION['last_notification_check'];
    }
    // END OF ADDED CODE
    // Get new bookings
    $bookingsQuery = "SELECT b.id, b.booking_date, b.booking_time, g.Parking_Space_Name as parking_name,
                     a.username as customer_username, CONCAT(p.firstName, ' ', p.lastName) as customer_name
                     FROM bookings b
                     JOIN garage_information g ON b.garage_id = g.garage_id
                     JOIN account_information a ON b.username = a.username
                     LEFT JOIN personal_information p ON a.username = p.username
                     WHERE g.username = ?
                     AND b.created_at > ?
                     ORDER BY b.created_at DESC
                     LIMIT 10";
    $stmt = $conn->prepare($bookingsQuery);
    $stmt->bind_param("ss", $username, $lastCheck);
    $stmt->execute();
    $bookingsResult = $stmt->get_result();
   
    while ($row = $bookingsResult->fetch_assoc()) {
        $row['booking_date_formatted'] = date('M d, Y', strtotime($row['booking_date']));
        $row['booking_time_formatted'] = date('h:i A', strtotime($row['booking_time']));
        $notifications['bookings'][] = $row;
    }
   
    // Get owner verification updates
    $verificationQuery = "SELECT owner_id, username, is_verified, registration_date
                         FROM garage_owners
                         WHERE username = ? AND registration_date > ?
                         ORDER BY registration_date DESC";
    $stmt = $conn->prepare($verificationQuery);
    $stmt->bind_param("ss", $username, $lastCheck);
    $stmt->execute();
    $verificationResult = $stmt->get_result();
   
    while ($row = $verificationResult->fetch_assoc()) {
        $notifications['verifications'][] = $row;
    }
    
    // Get garage verification updates
    $garageVerificationQuery = "SELECT garage_id, Parking_Space_Name, is_verified, updated_at
                               FROM garage_information
                               WHERE username = ? AND updated_at > ?
                               ORDER BY updated_at DESC";
    $stmt = $conn->prepare($garageVerificationQuery);
    $stmt->bind_param("ss", $username, $lastCheck);
    $stmt->execute();
    $garageVerificationResult = $stmt->get_result();
    
    while ($row = $garageVerificationResult->fetch_assoc()) {
        $row['updated_at_formatted'] = date('M d, Y h:i A', strtotime($row['updated_at']));
        $notifications['garage_verifications'][] = $row;
        error_log("Found update for: " . $row['Parking_Space_Name'] . ", updated at: " . $row['updated_at']);
    }
   
    // Get payment updates
    $paymentsQuery = "SELECT p.payment_id, p.booking_id, p.amount, p.payment_status,
                     p.payment_date, g.Parking_Space_Name as parking_name
                     FROM payments p
                     JOIN bookings b ON p.booking_id = b.id
                     JOIN garage_information g ON b.garage_id = g.garage_id
                     WHERE g.username = ? AND p.payment_date > ?
                     ORDER BY p.payment_date DESC
                     LIMIT 10";
    $stmt = $conn->prepare($paymentsQuery);
    $stmt->bind_param("ss", $username, $lastCheck);
    $stmt->execute();
    $paymentsResult = $stmt->get_result();
   
    while ($row = $paymentsResult->fetch_assoc()) {
        $row['payment_date_formatted'] = date('M d, Y h:i A', strtotime($row['payment_date']));
        $notifications['payments'][] = $row;
    }
   
    return $notifications;
}

// Get notifications
$userNotifications = getUserNotifications($conn, $username);


// ADD THIS FUNCTION HERE
// Filter notifications based on timestamp
function filterNotificationsByTime($notifications, $timestamp) {
    $filtered = [
        'bookings' => [],
        'verifications' => [],
        'garage_verifications' => [],
        'payments' => []
    ];
    
    // Filter bookings
    if (isset($notifications['bookings'])) {
        foreach ($notifications['bookings'] as $item) {
            $itemTime = isset($item['created_at']) ? strtotime($item['created_at']) : null;
            if ($itemTime && $itemTime > strtotime($timestamp)) {
                $filtered['bookings'][] = $item;
            }
        }
    }
    
    // Filter verifications
    if (isset($notifications['verifications'])) {
        foreach ($notifications['verifications'] as $item) {
            $itemTime = isset($item['registration_date']) ? strtotime($item['registration_date']) : null;
            if ($itemTime && $itemTime > strtotime($timestamp)) {
                $filtered['verifications'][] = $item;
            }
        }
    }
    
    // Filter garage verifications
    if (isset($notifications['garage_verifications'])) {
        foreach ($notifications['garage_verifications'] as $item) {
            $itemTime = isset($item['updated_at']) ? strtotime($item['updated_at']) : null;
            if ($itemTime && $itemTime > strtotime($timestamp)) {
                $filtered['garage_verifications'][] = $item;
            }
        }
    }
    
    // Filter payments
    if (isset($notifications['payments'])) {
        foreach ($notifications['payments'] as $item) {
            $itemTime = isset($item['payment_date']) ? strtotime($item['payment_date']) : null;
            if ($itemTime && $itemTime > strtotime($timestamp)) {
                $filtered['payments'][] = $item;
            }
        }
    }
    
    return $filtered;
}

// Apply the filter
if (isset($_SESSION['last_notification_check'])) {
    $userNotifications = filterNotificationsByTime($userNotifications, $_SESSION['last_notification_check']);
}
// END OF ADDED CODE
// Debug info (remove in production)
if (isset($_GET['debug'])) {
    echo "<pre style='color:white; background:#333; padding:10px; position:fixed; top:80px; right:10px; z-index:9999;'>";
    echo "Last Check Time: " . $_SESSION['last_notification_check'] . "\n";
    echo "Total Notifications After Filtering: " . 
        count($userNotifications['bookings']) + 
        count($userNotifications['verifications']) + 
        count($userNotifications['garage_verifications']) + 
        count($userNotifications['payments']) . "\n";
    echo "</pre>";
}
// Calculate total notifications - include garage_verifications
$totalNotifications = count($userNotifications['bookings']) +
                      count($userNotifications['verifications']) +
                      count($userNotifications['garage_verifications']) +
                      count($userNotifications['payments']);

// Update last check time if viewing notifications
if (isset($_GET['view_notifications'])) {
    $_SESSION['last_notification_check'] = date('Y-m-d H:i:s');
    // Redirect to remove the parameter from URL
    header("Location: business_desh.php");
    exit();
}

// Function to get total parking spaces for the owner
function getTotalParkingSpaces($conn, $username) {
    // Add debug logging
    error_log("getTotalParkingSpaces called for username: " . $username);
    
    $query = "SELECT SUM(Parking_Capacity) AS total_capacity FROM garage_information WHERE username = ?";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        error_log("Failed to prepare statement: " . $conn->error);
        return 0;
    }
    
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $total = $row['total_capacity'] ? intval($row['total_capacity']) : 0;
        error_log("Total parking spaces for user $username: $total");
        return $total;
    } else {
        error_log("No parking spaces found for user: $username");
        return 0;
    }
}




// Function to get total active bookings
function getActiveBookings($conn, $username) {
    $query = "SELECT COUNT(*) AS total FROM bookings b 
              JOIN garage_information g ON b.garage_id = g.garage_id 
              WHERE g.username = ? AND b.status = 'active'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['total'];
    } else {
        return 0;
    }
}

// Function to calculate occupancy rate
function getOccupancyRate($conn, $username) {
    // Get total capacity from garage_information
    $query = "SELECT SUM(Parking_Capacity) as total_capacity FROM garage_information WHERE username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $totalCapacity = $row['total_capacity'];
        
        if ($totalCapacity > 0) {
            // Get active bookings count
            $activeBookings = getActiveBookings($conn, $username);
            $occupancyRate = ($activeBookings / $totalCapacity) * 100;
            return round($occupancyRate);
        }
    }
    
    return 0;
}

// Function to calculate monthly income
function getMonthlyIncome($conn, $username) {
    $currentMonth = date('m');
    $currentYear = date('Y');
    
    // Get owner_id first
    $ownerIdQuery = "SELECT owner_id FROM garage_owners WHERE username = ?
                     UNION 
                     SELECT owner_id FROM dual_user WHERE username = ?";
    $stmt = $conn->prepare($ownerIdQuery);
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $ownerId = $row['owner_id'];
        
        // Get actual owner profit (after commission)
        $query = "SELECT SUM(owner_profit) AS total_income 
                  FROM profit_tracking 
                  WHERE owner_id = ? 
                  AND MONTH(created_at) = ? 
                  AND YEAR(created_at) = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sii", $ownerId, $currentMonth, $currentYear);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['total_income'] ? number_format($row['total_income'], 2) : '0.00';
        }
    }
    
    return '0.00';
}

// Function to get monthly income data for chart
function getMonthlyIncomeData($conn, $username) {
    $currentYear = date('Y');
    $data = [];
    
    // Get owner_id first
    $ownerIdQuery = "SELECT owner_id FROM garage_owners WHERE username = ?
                     UNION 
                     SELECT owner_id FROM dual_user WHERE username = ?";
    $stmt = $conn->prepare($ownerIdQuery);
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $ownerId = null;
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $ownerId = $row['owner_id'];
    }
    
    for ($month = 1; $month <= 12; $month++) {
        if ($ownerId) {
            // CORRECTED: Use profit_tracking table to get actual owner earnings
            $query = "SELECT COALESCE(SUM(owner_profit), 0) AS monthly_income 
                      FROM profit_tracking 
                      WHERE owner_id = ? 
                      AND MONTH(created_at) = ? 
                      AND YEAR(created_at) = ?";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sii", $ownerId, $month, $currentYear);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $monthlyIncome = $row['monthly_income'] ? $row['monthly_income'] : 0;
                $data[] = floatval($monthlyIncome); // Actual owner profit
            } else {
                $data[] = 0;
            }
        } else {
            $data[] = 0; // No owner found
        }
    }
    
    return json_encode($data);
}
// ALTERNATIVE: If you want to include owner profit after commission
function getMonthlyOwnerProfit($conn, $username) {
    $currentMonth = date('m');
    $currentYear = date('Y');
    
    // Get the owner_id for this username
    $ownerIdQuery = "SELECT owner_id FROM garage_owners WHERE username = ?
                     UNION 
                     SELECT owner_id FROM dual_user WHERE username = ?";
    $stmt = $conn->prepare($ownerIdQuery);
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $ownerId = $row['owner_id'];
        
        // Get actual owner profit from profit_tracking table
        $profitQuery = "SELECT SUM(owner_profit) AS total_profit 
                        FROM profit_tracking 
                        WHERE owner_id = ? 
                        AND MONTH(created_at) = ? 
                        AND YEAR(created_at) = ?";
        
        $stmt = $conn->prepare($profitQuery);
        $stmt->bind_param("sii", $ownerId, $currentMonth, $currentYear);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['total_profit'] ? number_format($row['total_profit'], 0) : 0;
        }
    }
    
    return 0;
}

// Function to get daily occupancy data for chart
function getDailyOccupancyData($conn, $username) {
    $currentMonth = date('m');
    $currentYear = date('Y');
    $daysInMonth = date('t');
    $data = [];
    
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $date = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $day);
        
        // Get total capacity
        $capacityQuery = "SELECT SUM(Parking_Capacity) as total_capacity 
                         FROM garage_information 
                         WHERE username = ?";
        $stmt = $conn->prepare($capacityQuery);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $capacityResult = $stmt->get_result();
        $totalCapacity = 0;
        
        if ($capacityResult && $capacityResult->num_rows > 0) {
            $row = $capacityResult->fetch_assoc();
            $totalCapacity = $row['total_capacity'] ? $row['total_capacity'] : 0;
        }
        
        // Get bookings for this day
        $bookingsQuery = "SELECT COUNT(*) as booking_count 
                         FROM bookings b 
                         JOIN garage_information g ON b.garage_id = g.garage_id 
                         WHERE g.username = ? 
                         AND b.booking_date = ?";
        $stmt = $conn->prepare($bookingsQuery);
        $stmt->bind_param("ss", $username, $date);
        $stmt->execute();
        $bookingsResult = $stmt->get_result();
        $bookingCount = 0;
        
        if ($bookingsResult && $bookingsResult->num_rows > 0) {
            $row = $bookingsResult->fetch_assoc();
            $bookingCount = $row['booking_count'] ? $row['booking_count'] : 0;
        }
        
        // Calculate occupancy rate
        if ($totalCapacity > 0) {
            $occupancyRate = ($bookingCount / $totalCapacity) * 100;
            $data[] = round($occupancyRate);
        } else {
            // If no capacity data, use a base value with some variation
            $baseOccupancy = 65;
            if ($day % 7 == 5 || $day % 7 == 6) { // Weekend pattern
                $data[] = min(95, $baseOccupancy + 20 + rand(-5, 5));
            } else {
                $data[] = min(95, $baseOccupancy + rand(-5, 15));
            }
        }
    }
    
    return json_encode($data);
}

// Function to get recent bookings
// UPDATED: Function to get recent bookings with clean amount and payment status
function getRecentBookings($conn, $username) {
    // Updated query to include payment status
    $query = "SELECT b.id, b.booking_date, b.booking_time, b.duration, b.status, b.payment_status,
              g.Parking_Space_Name, g.PriceperHour,
              p.firstName, p.lastName, a.username as customer_username,
              pt.owner_profit, pt.total_amount,
              pay.amount as actual_payment, pay.payment_status as actual_payment_status
              FROM bookings b
              JOIN garage_information g ON b.garage_id = g.garage_id
              JOIN account_information a ON b.username = a.username
              LEFT JOIN personal_information p ON a.username = p.username
              LEFT JOIN payments pay ON b.id = pay.booking_id AND pay.payment_status = 'paid'
              LEFT JOIN profit_tracking pt ON pay.payment_id = pt.payment_id
              WHERE g.username = ?
              ORDER BY b.booking_date DESC, b.booking_time DESC
              LIMIT 10";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bookings = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $bookingId = 'BK-' . str_pad($row['id'], 5, '0', STR_PAD_LEFT);
            
            // Get customer name
            if (!empty($row['firstName']) && !empty($row['lastName'])) {
                $customerName = $row['firstName'] . ' ' . $row['lastName'];
            } else {
                $customerName = $row['customer_username'];
            }
            
            $parkingName = $row['Parking_Space_Name'];
            $bookingDate = date('M d, Y', strtotime($row['booking_date']));
            
            // Calculate start and end times
            $startTime = date('h:i A', strtotime($row['booking_time']));
            $endDateTime = new DateTime($row['booking_date'] . ' ' . $row['booking_time']);
            $endDateTime->add(new DateInterval('PT' . $row['duration'] . 'H'));
            $endTime = $endDateTime->format('h:i A');
            $timeRange = $startTime . ' - ' . $endTime;
            
            $duration = $row['duration'] . ' ' . ($row['duration'] > 1 ? 'hours' : 'hour');
            
            // Calculate owner profit - CLEAN VERSION (no labels)
            if (!empty($row['owner_profit'])) {
    $amount = '৳' . number_format($row['owner_profit'], 1);
} elseif (!empty($row['actual_payment'])) {
    $commission = $row['actual_payment'] * 0.30; // 30% commission
    $ownerProfit = $row['actual_payment'] - $commission;
    $amount = '৳' . number_format($ownerProfit, 1); // Shows 1 decimal place
} else {
    $theoreticalAmount = $row['PriceperHour'] * $row['duration'];
    $commission = $theoreticalAmount * 0.30; // 30% commission  
    $ownerProfit = $theoreticalAmount - $commission;
    $amount = '৳' . number_format($ownerProfit, 1); // Shows 1 decimal place
}
            
            // Determine payment status
            $paymentStatus = 'Pending'; // Default
            if (!empty($row['actual_payment_status'])) {
                switch($row['actual_payment_status']) {
                    case 'paid':
                        $paymentStatus = 'Paid';
                        break;
                    case 'refunded':
                        $paymentStatus = 'Refunded';
                        break;
                    case 'pending':
                        $paymentStatus = 'Pending';
                        break;
                    default:
                        $paymentStatus = 'Pending';
                }
            } elseif (!empty($row['payment_status'])) {
                // Fallback to booking payment status
                switch($row['payment_status']) {
                    case 'paid':
                        $paymentStatus = 'Paid';
                        break;
                    case 'refunded':
                        $paymentStatus = 'Refunded';
                        break;
                    case 'pending':
                        $paymentStatus = 'Pending';
                        break;
                    default:
                        $paymentStatus = 'Pending';
                }
            }
            
            // Calculate real-time booking status
            $currentDateTime = new DateTime();
            $bookingStartDateTime = new DateTime($row['booking_date'] . ' ' . $row['booking_time']);
            $bookingEndDateTime = clone $bookingStartDateTime;
            $bookingEndDateTime->add(new DateInterval('PT' . $row['duration'] . 'H'));
            
            // Determine booking status based on current time
            if ($row['status'] === 'cancelled') {
                $actualStatus = 'cancelled';
            } elseif ($currentDateTime < $bookingStartDateTime) {
                $actualStatus = 'upcoming';
            } elseif ($currentDateTime >= $bookingStartDateTime && $currentDateTime <= $bookingEndDateTime) {
                $actualStatus = 'active';
            } else {
                $actualStatus = 'completed';
            }
            
            $statusDisplay = ucfirst($actualStatus);
            
            $bookings[] = [
                'id' => $bookingId,
                'customer' => $customerName,
                'parking' => $parkingName,
                'date' => $bookingDate,
                'time_range' => $timeRange,
                'duration' => $duration,
                'amount' => $amount, // Clean amount without labels
                'payment_status' => $paymentStatus, // NEW: Payment status
                'status' => $statusDisplay, // Booking status
                'raw_status' => $actualStatus
            ];
        }
    }
    
    return $bookings;
}

// Function to get parking locations for the map
function getParkingLocations($conn, $username) {
    $query = "SELECT g.garage_id, g.Parking_Space_Name, g.Parking_Capacity, g.PriceperHour, 
              l.Latitude, l.Longitude,
              (SELECT COUNT(*) FROM bookings b WHERE b.garage_id = g.garage_id AND b.status = 'active') AS active_bookings
              FROM garage_information g
              JOIN garagelocation l ON g.garage_id = l.garage_id
              WHERE g.username = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $parkingLocations = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $totalSpaces = intval($row['Parking_Capacity']);
            $activeBookings = intval($row['active_bookings']);
            $availableSpaces = $totalSpaces - $activeBookings;
            
            // Make sure it doesn't go negative
            $availableSpaces = max(0, $availableSpaces);
            $bookedSpaces = $totalSpaces - $availableSpaces;
            
            $parkingLocations[] = [
                'name' => $row['Parking_Space_Name'],
                'lat' => $row['Latitude'],
                'lng' => $row['Longitude'],
                'spaces' => $totalSpaces,
                'available' => $availableSpaces,
                'booked' => $bookedSpaces,
                'pricePerHour' => $row['PriceperHour']
            ];
        }
    }
    
    // If no locations found, return sample data
    if (empty($parkingLocations)) {
        $parkingLocations = [
            [
                'name' => 'Gulshan Parking Zone',
                'lat' => 23.7937,
                'lng' => 90.4137,
                'spaces' => 45,
                'available' => 12,
                'booked' => 33,
                'pricePerHour' => 10
            ],
            [
                'name' => 'Banani Parking Area',
                'lat' => 23.7937,
                'lng' => 90.4037,
                'spaces' => 30,
                'available' => 5,
                'booked' => 25,
                'pricePerHour' => 8
            ],
            [
                'name' => 'Dhanmondi Parking',
                'lat' => 23.7537,
                'lng' => 90.3737,
                'spaces' => 25,
                'available' => 8,
                'booked' => 17,
                'pricePerHour' => 9
            ]
        ];
    }
    
    return $parkingLocations;
}

// Function to get parking spaces summary - SIMPLIFIED
function getParkingSpacesSummary($conn, $username) {
    $query = "SELECT DISTINCT g.garage_id, g.Parking_Space_Name, g.Parking_Lot_Address, g.Parking_Capacity, g.PriceperHour, g.is_verified,
          (SELECT COUNT(*) FROM bookings b WHERE b.garage_id = g.garage_id AND b.status IN ('upcoming', 'active')) AS active_bookings 
          FROM garage_information g 
          WHERE g.username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $parkingSpaces = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Get actual availability based on active bookings
            $totalSpaces = intval($row['Parking_Capacity']);
            $activeBookings = intval($row['active_bookings']);
            $availableSpaces = max(0, $totalSpaces - $activeBookings);
            
            $parkingSpaces[] = [
                'garage_id' => $row['garage_id'],
                'name' => $row['Parking_Space_Name'],
                'address' => $row['Parking_Lot_Address'],
                'totalSpaces' => $totalSpaces,
                'availableSpaces' => $availableSpaces,
                'Availability' => $availableSpaces, // ADD THIS LINE - for backward compatibility
                'hourlyRate' => $row['PriceperHour'],
                'is_verified' => $row['is_verified']
            ];
        }
    }
    
    // If no spaces found, return sample data
    if (empty($parkingSpaces)) {
        $parkingSpaces = [
            [
                'garage_id' => 1,
                'name' => 'Gulshan Parking Zone',
                'address' => 'Gulshan Avenue, Dhaka',
                'totalSpaces' => 45,
                'availableSpaces' => 12,
                'Availability' => 12, // ADD THIS LINE
                'hourlyRate' => '10',
                'is_verified' => 1
            ],
            [
                'garage_id' => 2,
                'name' => 'Banani Parking Area',
                'address' => 'Banani Road 11, Dhaka',
                'totalSpaces' => 30,
                'availableSpaces' => 5,
                'Availability' => 5, // ADD THIS LINE
                'hourlyRate' => '8',
                'is_verified' => 0
            ],
            [
                'garage_id' => 3,
                'name' => 'Dhanmondi Parking',
                'address' => 'Dhanmondi 27, Dhaka',
                'totalSpaces' => 25,
                'availableSpaces' => 8,
                'Availability' => 8, // ADD THIS LINE
                'hourlyRate' => '9',
                'is_verified' => 1
            ]
        ];
    }
    
    return $parkingSpaces;
}

// Function to get verification status for each garage
function getGarageVerificationStatus($conn, $username, $parkingSpacesSummary) {
    $garageVerificationStatus = [];
    
    foreach ($parkingSpacesSummary as $space) {
        $garageName = $space['name'];
        $garageQuery = "SELECT garage_id, is_verified
                        FROM garage_information 
                        WHERE username = ? AND Parking_Space_Name = ?";
        $stmt = $conn->prepare($garageQuery);
        $stmt->bind_param("ss", $username, $garageName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $garageVerificationStatus[$garageName] = ($row['is_verified'] == 1);
        } else {
            $garageVerificationStatus[$garageName] = false;
        }
    }
    
    return $garageVerificationStatus;
}
// FIXED: getPaymentStatusColor function (add this to your PHP functions)
function getPaymentStatusColor($paymentStatus) {
    $colorMap = [
        'paid' => 'linear-gradient(135deg, #10b981 0%, #059669 100%)',
        'pending' => 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)',
        'refunded' => 'linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%)'
    ];
    return $colorMap[$paymentStatus] ?? 'linear-gradient(135deg, #6b7280 0%, #4b5563 100%)';
}


// Add this function to get parking spaces with ratings
function getParkingSpacesSummaryWithRatings($conn, $username) {
    $query = "SELECT DISTINCT g.garage_id, g.Parking_Space_Name, g.Parking_Lot_Address, 
          g.Parking_Capacity, g.PriceperHour, g.is_verified,
          grs.average_rating, grs.total_ratings,
          gos.opening_time, gos.closing_time, gos.operating_days, gos.is_24_7,
          grts.current_status, grts.is_manual_override, grts.override_reason,  -- ADD THESE FIELDS!
          (SELECT COUNT(*) FROM bookings b WHERE b.garage_id = g.garage_id AND b.status IN ('upcoming', 'active')) AS active_bookings 
          FROM garage_information g 
          LEFT JOIN garage_ratings_summary grs ON g.garage_id = grs.garage_id
          LEFT JOIN garage_operating_schedule gos ON g.garage_id = gos.garage_id  
          LEFT JOIN garage_real_time_status grts ON g.garage_id = grts.garage_id  -- IMPORTANT!
          WHERE g.username = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $parkingSpaces = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Get actual availability based on active bookings
            $totalSpaces = intval($row['Parking_Capacity']);
            $activeBookings = intval($row['active_bookings']);
            $availableSpaces = max(0, $totalSpaces - $activeBookings);
            
            $parkingSpaces[] = [
                'garage_id' => $row['garage_id'],
                'name' => $row['Parking_Space_Name'],
                'address' => $row['Parking_Lot_Address'],
                'totalSpaces' => $totalSpaces,
                'availableSpaces' => $availableSpaces,
                'Availability' => $availableSpaces,
                'hourlyRate' => $row['PriceperHour'],
                'is_verified' => $row['is_verified'],
                
                'average_rating' => $row['average_rating'] ? round($row['average_rating'], 1) : 0,
                'total_ratings' => $row['total_ratings'] ?: 0,
                'opening_time' => $row['opening_time'],
                'closing_time' => $row['closing_time'],
                'operating_days' => $row['operating_days'],
                'is_24_7' => $row['is_24_7'],
                'current_status' => $row['current_status'] ?? 'open',
                'is_manual_override' => $row['is_manual_override'] ?? 0,  // ADD THIS!
                'override_reason' => $row['override_reason'] ?? null       // ADD THIS!
            ];
        }
    }
    
    return $parkingSpaces;
}

// Update the main code to use the new function
$parkingSpacesSummary = getParkingSpacesSummaryWithRatings($conn, $username);

// Helper function to generate star rating HTML
function generateStarRating($rating, $totalRatings = 0) {
    $fullStars = floor($rating);
    $hasHalfStar = ($rating - $fullStars) >= 0.5;
    $emptyStars = 5 - $fullStars - ($hasHalfStar ? 1 : 0);
    
    $stars = '';
    
    // Full stars
    for ($i = 0; $i < $fullStars; $i++) {
        $stars .= '<svg class="w-4 h-4 text-yellow-400 fill-current inline mr-1" viewBox="0 0 20 20">
                    <path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/>
                   </svg>';
    }
    
    // Half star
    if ($hasHalfStar) {
        $stars .= '<svg class="w-4 h-4 text-yellow-400 inline mr-1" viewBox="0 0 20 20">
                    <defs>
                        <linearGradient id="half-fill-' . uniqid() . '">
                            <stop offset="50%" stop-color="currentColor"/>
                            <stop offset="50%" stop-color="transparent"/>
                        </linearGradient>
                    </defs>
                    <path fill="url(#half-fill-' . uniqid() . ')" stroke="currentColor" stroke-width="1" d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/>
                   </svg>';
    }
    
    // Empty stars
    for ($i = 0; $i < $emptyStars; $i++) {
        $stars .= '<svg class="w-4 h-4 text-gray-400 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 20 20">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/>
                   </svg>';
    }
    
    return $stars;
}


function getAvailableTimeSlots($conn, $username, $date, $garageId = null) {
    // Get garage information
    $garageQuery = "SELECT garage_id, Parking_Space_Name, Parking_Capacity 
                    FROM garage_information 
                    WHERE username = ?";
    
    if ($garageId) {
        $garageQuery .= " AND garage_id = ?";
        $stmt = $conn->prepare($garageQuery);
        $stmt->bind_param("ss", $username, $garageId);
    } else {
        $stmt = $conn->prepare($garageQuery);
        $stmt->bind_param("s", $username);
    }
    
    $stmt->execute();
    $garageResult = $stmt->get_result();
    
    $garages = [];
    while ($garage = $garageResult->fetch_assoc()) {
        $garages[$garage['garage_id']] = [
            'name' => $garage['Parking_Space_Name'],
            'capacity' => (int)$garage['Parking_Capacity']
        ];
    }
    
    // Get bookings for the date
    $bookingQuery = "SELECT 
                        b.booking_time,
                        b.duration,
                        b.garage_id,
                        b.status
                     FROM bookings b
                     JOIN garage_information g ON b.garage_id = g.garage_id
                     WHERE g.username = ?
                     AND b.booking_date = ?
                     AND b.status IN ('upcoming', 'active', 'completed')";
    
    $stmt = $conn->prepare($bookingQuery);
    $stmt->bind_param("ss", $username, $date);
    $stmt->execute();
    $bookingResult = $stmt->get_result();
    
    $bookedSlots = [];
    while ($booking = $bookingResult->fetch_assoc()) {
        $startHour = (int)date('H', strtotime($booking['booking_time']));
        $duration = (int)$booking['duration'];
        $garageId = $booking['garage_id'];
        
        for ($hour = $startHour; $hour < $startHour + $duration; $hour++) {
            if (!isset($bookedSlots[$hour])) {
                $bookedSlots[$hour] = [];
            }
            if (!isset($bookedSlots[$hour][$garageId])) {
                $bookedSlots[$hour][$garageId] = 0;
            }
            $bookedSlots[$hour][$garageId]++;
        }
    }
    
    // Generate 24-hour data
    $timeSlots = [];
    for ($hour = 0; $hour < 24; $hour++) {
        $hourData = [
            'hour' => sprintf('%02d:00', $hour),
            'garages' => []
        ];
        
        foreach ($garages as $gId => $garage) {
            $bookedCount = isset($bookedSlots[$hour][$gId]) ? $bookedSlots[$hour][$gId] : 0;
            $availableCount = $garage['capacity'] - $bookedCount;
            
            $hourData['garages'][$gId] = [
                'name' => $garage['name'],
                'total' => $garage['capacity'],
                'available' => max(0, $availableCount),
                'booked' => $bookedCount,
                'status' => $availableCount > 0 ? 'available' : 'full'
            ];
        }
        
        $timeSlots[] = $hourData;
    }
    
    return $timeSlots;
}


// Get data for the dashboard
$totalSpaces = getTotalParkingSpaces($conn, $username);
$activeBookings = getActiveBookings($conn, $username);
$occupancyRate = getOccupancyRate($conn, $username);
$monthlyIncome = getMonthlyIncome($conn, $username);
$monthlyIncomeData = getMonthlyIncomeData($conn, $username);
$dailyOccupancyData = getDailyOccupancyData($conn, $username);
$recentBookings = getRecentBookings($conn, $username);
$parkingLocations = getParkingLocations($conn, $username);
$parkingSpacesSummary = getParkingSpacesSummaryWithRatings($conn, $username);

// Get unverified garages
$unverifiedGarages = [];
$garageQuery = "SELECT g.Parking_Space_Name 
                FROM garage_information g 
                LEFT JOIN garage_owners go ON g.username = go.username 
                WHERE g.username = ? AND (go.is_verified = 0 OR go.is_verified IS NULL)";
$stmt = $conn->prepare($garageQuery);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $unverifiedGarages[] = $row['Parking_Space_Name'];
}

// Get verification status for all garages
$garageVerificationStatus = getGarageVerificationStatus($conn, $username, $parkingSpacesSummary);
?>

<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>পার্কিং লাগবে - Business Dashboard</title>
    <!-- Tailwind CSS and daisyUI -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.7.3/dist/full.min.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Leaflet CSS for maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <!-- Chart.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    primary: '#f39c12',
                    'primary-dark': '#e67e22',
                },
                keyframes: {
                    kenBurns: {
                        '0%': { transform: 'scale(1) translate(0, 0)' },
                        '100%': { transform: 'scale(1.1) translate(-2%, -1%)' }
                    },
                    fadeIn: {
                        'from': { opacity: '0', transform: 'translateY(20px)' },
                        'to': { opacity: '1', transform: 'translateY(0)' }
                    }
                },
                animation: {
                    kenBurns: 'kenBurns 30s ease-in-out infinite alternate',
                    fadeIn: 'fadeIn 1.2s ease-out'
                }
            }
        }
    }
    </script>
    <style>
/* Additional CSS for better star ratings */
.rating-stars {
    display: flex;
    align-items: center;
    gap: 2px;
}

.star-rating svg {
    width: 16px;
    height: 16px;
    transition: all 0.2s ease;
}

.rating-badge {
    background: linear-gradient(135deg, rgba(255, 215, 0, 0.2) 0%, rgba(255, 193, 7, 0.2) 100%);
    border: 1px solid rgba(255, 215, 0, 0.3);
}

/* Hover effect for rating section */
.rating-section:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 215, 0, 0.3);
}

/* Animate stars on hover */
.rating-stars:hover svg {
    transform: scale(1.1);
}
</style>
    <style>
    /* Force inputs to be editable */
    #editModal input[type="text"],
    #editModal input[type="number"] {
        display: block !important;
        width: 100% !important;
        padding: 8px 12px !important;
        background-color: #374151 !important;
        color: #ffffff !important;
        border: 1px solid #4b5563 !important;
        border-radius: 6px !important;
        font-size: 16px !important;
        line-height: 1.5 !important;
        cursor: text !important;
        pointer-events: auto !important;
        -webkit-user-select: text !important;
        -moz-user-select: text !important;
        user-select: text !important;
        opacity: 1 !important;
        position: relative !important;
        z-index: 10 !important;
    }
    
    /* Remove any overlay that might be blocking */
    #editModal .modal-content,
    #editModal form,
    #editModal div {
        position: static !important;
        pointer-events: auto !important;
    }
    
    #editModal input[type="text"]:focus,
    #editModal input[type="number"]:focus {
        outline: 2px solid #f39c12 !important;
        background-color: #1f2937 !important;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Remove any event listeners that might be preventing input
    setTimeout(function() {
        const editInputs = document.querySelectorAll('#editModal input[type="text"], #editModal input[type="number"]');
        
        editInputs.forEach(input => {
            // Clone the input to remove all event listeners
            const newInput = input.cloneNode(true);
            input.parentNode.replaceChild(newInput, input);
            
            // Add click event to ensure focus
            newInput.addEventListener('click', function() {
                this.focus();
            });
            
            // Test if input is working
            newInput.addEventListener('input', function() {
                console.log('Input working:', this.id, this.value);
            });
        });
        
        console.log('Input event listeners reset');
    }, 1000);
});
// 🎯 FINAL FIX - Add Save buttons to Basic Info and Operating Hours tabs only
setTimeout(function() {
    console.log('🧹 CLEANING UP DUPLICATE FUNCTIONS');
    
    // Force clear any existing modal content first
    const modal = document.getElementById('editModal');
    if (modal) {
        modal.classList.add('hidden');
        const form = modal.querySelector('form');
        if (form) {
            form.innerHTML = '';
        }
    }
    
    // FINAL SINGLE VERSION - This will be the ONLY version that runs
    window.openEditModal = function(garageId) {
        console.log('🎯 SINGLE VERSION: Opening modal for garage:', garageId);
        
        // Close any existing modals
        const modal = document.getElementById('editModal');
        if (!modal) {
            alert('Modal not found!');
            return;
        }
        
        // Clear and rebuild completely
        modal.classList.remove('hidden');
        
        const formContainer = modal.querySelector('form') || modal.querySelector('#editTimingForm');
        if (!formContainer) {
            console.error('Form container not found');
            return;
        }
        
        // SINGLE CONSISTENT STRUCTURE WITH TAB-SPECIFIC SAVE BUTTONS
        formContainer.innerHTML = `
            <input type="hidden" id="editGarageId" name="garage_id">
            
            <!-- Tab Navigation -->
            <div style="margin-bottom: 24px; padding: 24px;">
                <div style="display: flex; gap: 16px; border-bottom: 1px solid #4b5563; padding-bottom: 8px; margin-bottom: 24px;">
                    <button type="button" onclick="window.switchTab('basic')" id="basicTabBtn" 
                            style="padding: 12px 20px; color: white; border: none; border-bottom: 2px solid #f39c12; background: none; cursor: pointer; font-weight: 500;">
                        📝 Basic Info
                    </button>
                    <button type="button" onclick="window.switchTab('timing')" id="timingTabBtn"
                            style="padding: 12px 20px; color: #9ca3af; border: none; border-bottom: 2px solid transparent; background: none; cursor: pointer;">
                        ⏰ Operating Hours
                    </button>
                    <button type="button" onclick="window.switchTab('control')" id="controlTabBtn"
                            style="padding: 12px 20px; color: #9ca3af; border: none; border-bottom: 2px solid transparent; background: none; cursor: pointer;">
                        🎛️ Real-time Control
                    </button>
                </div>
                
                <!-- BASIC INFO TAB -->
                <div id="basicTab" style="display: block;">
                    <div style="background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 8px; padding: 16px; margin-bottom: 24px;">
                        <p style="color: #22c55e; margin: 0; font-weight: 500;">✅ Editing: <span id="currentGarageName">Loading...</span></p>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                        <div>
                            <label style="color: white; font-weight: 500; margin-bottom: 8px; display: block;">🏠 Parking Space Name</label>
                            <input type="text" id="editParkingName" name="parking_name" required
                                   style="width: 100%; padding: 12px; background-color: #374151; color: white; border: 1px solid #6b7280; border-radius: 8px; box-sizing: border-box;">
                        </div>
                        
                        <div>
                            <label style="color: white; font-weight: 500; margin-bottom: 8px; display: block;">🚗 Parking Capacity</label>
                            <input type="number" id="editParkingCapacity" name="parking_capacity" min="1" required
                                   style="width: 100%; padding: 12px; background-color: #374151; color: white; border: 1px solid #6b7280; border-radius: 8px; box-sizing: border-box;">
                        </div>
                        
                        <div>
                            <label style="color: white; font-weight: 500; margin-bottom: 8px; display: block;">💰 Price per Hour (৳)</label>
                            <input type="number" id="editPricePerHour" name="price_per_hour" min="0" step="0.01" required
                                   style="width: 100%; padding: 12px; background-color: #374151; color: white; border: 1px solid #6b7280; border-radius: 8px; box-sizing: border-box;">
                        </div>
                        
                    </div>
                    
                    <!-- SAVE BUTTONS FOR BASIC INFO -->
                    <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 32px; padding-top: 24px; border-top: 1px solid #4b5563;">
                        <button type="button" onclick="closeEditModal()" 
                                style="padding: 12px 24px; background-color: #6b7280; color: white; border: none; border-radius: 8px; cursor: pointer;">
                            Cancel
                        </button>
                        <button type="submit" 
                                style="padding: 12px 24px; background-color: #f39c12; color: white; border: none; border-radius: 8px; cursor: pointer;">
                            💾 Save Changes
                        </button>
                    </div>
                </div>

                <!-- TIMING TAB -->
                <div id="timingTab" style="display: none;">
                    <div style="background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 8px; padding: 16px; margin-bottom: 24px;">
                        <p style="color: #22c55e; margin: 0; font-weight: 500;">⏰ Operating Hours Configuration</p>
                    </div>
                    
                    <div style="margin-bottom: 24px;">
                        <label style="display: flex; align-items: center; color: white; font-weight: 500; cursor: pointer;">
                            <input type="checkbox" id="is24_7" name="is_24_7" onchange="toggleOperatingHours()" style="margin-right: 12px; width: 16px; height: 16px;">
                            <span>🕐 Open 24/7</span>
                        </label>
                    </div>
                    
                    <div id="operatingHours" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                        <div>
                            <label style="color: white; font-weight: 500; margin-bottom: 8px; display: block;">⏰ Opening Time</label>
                            <input type="time" id="openingTime" name="opening_time" value="06:00"
                                   style="width: 100%; padding: 12px; background-color: #374151; color: white; border: 1px solid #6b7280; border-radius: 8px;">
                        </div>
                        <div>
                            <label style="color: white; font-weight: 500; margin-bottom: 8px; display: block;">🌙 Closing Time</label>
                            <input type="time" id="closingTime" name="closing_time" value="22:00"
                                   style="width: 100%; padding: 12px; background-color: #374151; color: white; border: 1px solid #6b7280; border-radius: 8px;">
                        </div>
                    </div>
                    
                    <!-- OPERATING DAYS SECTION -->
                    <div style="margin-bottom: 24px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                            <label style="color: white; font-weight: 500;">📅 Operating Days</label>
                            <div style="display: flex; gap: 8px;">
                                <button type="button" onclick="selectAllDays()" 
                                        style="padding: 4px 8px; background: #10b981; color: white; border: none; border-radius: 4px; font-size: 12px; cursor: pointer;">
                                    All
                                </button>
                                <button type="button" onclick="clearAllDays()" 
                                        style="padding: 4px 8px; background: #ef4444; color: white; border: none; border-radius: 4px; font-size: 12px; cursor: pointer;">
                                    None
                                </button>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px;">
                            <label style="display: flex; align-items: center; gap: 8px; background: rgba(55, 65, 81, 0.5); padding: 12px; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; border: 1px solid #4b5563;"
                                   onmouseover="this.style.background='rgba(55, 65, 81, 0.8)'" 
                                   onmouseout="this.style.background='rgba(55, 65, 81, 0.5)'">
                                <input type="checkbox" name="operating_days[]" value="monday" 
                                       style="width: 16px; height: 16px; accent-color: #f39c12;">
                                <span style="color: white; font-size: 14px;">Monday</span>
                            </label>
                            
                            <label style="display: flex; align-items: center; gap: 8px; background: rgba(55, 65, 81, 0.5); padding: 12px; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; border: 1px solid #4b5563;"
                                   onmouseover="this.style.background='rgba(55, 65, 81, 0.8)'" 
                                   onmouseout="this.style.background='rgba(55, 65, 81, 0.5)'">
                                <input type="checkbox" name="operating_days[]" value="tuesday" 
                                       style="width: 16px; height: 16px; accent-color: #f39c12;">
                                <span style="color: white; font-size: 14px;">Tuesday</span>
                            </label>
                            
                            <label style="display: flex; align-items: center; gap: 8px; background: rgba(55, 65, 81, 0.5); padding: 12px; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; border: 1px solid #4b5563;"
                                   onmouseover="this.style.background='rgba(55, 65, 81, 0.8)'" 
                                   onmouseout="this.style.background='rgba(55, 65, 81, 0.5)'">
                                <input type="checkbox" name="operating_days[]" value="wednesday" 
                                       style="width: 16px; height: 16px; accent-color: #f39c12;">
                                <span style="color: white; font-size: 14px;">Wednesday</span>
                            </label>
                            
                            <label style="display: flex; align-items: center; gap: 8px; background: rgba(55, 65, 81, 0.5); padding: 12px; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; border: 1px solid #4b5563;"
                                   onmouseover="this.style.background='rgba(55, 65, 81, 0.8)'" 
                                   onmouseout="this.style.background='rgba(55, 65, 81, 0.5)'">
                                <input type="checkbox" name="operating_days[]" value="thursday" 
                                       style="width: 16px; height: 16px; accent-color: #f39c12;">
                                <span style="color: white; font-size: 14px;">Thursday</span>
                            </label>
                            
                            <label style="display: flex; align-items: center; gap: 8px; background: rgba(55, 65, 81, 0.5); padding: 12px; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; border: 1px solid #4b5563;"
                                   onmouseover="this.style.background='rgba(55, 65, 81, 0.8)'" 
                                   onmouseout="this.style.background='rgba(55, 65, 81, 0.5)'">
                                <input type="checkbox" name="operating_days[]" value="friday" 
                                       style="width: 16px; height: 16px; accent-color: #f39c12;">
                                <span style="color: white; font-size: 14px;">Friday</span>
                            </label>
                            
                            <label style="display: flex; align-items: center; gap: 8px; background: rgba(55, 65, 81, 0.5); padding: 12px; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; border: 1px solid #4b5563;"
                                   onmouseover="this.style.background='rgba(55, 65, 81, 0.8)'" 
                                   onmouseout="this.style.background='rgba(55, 65, 81, 0.5)'">
                                <input type="checkbox" name="operating_days[]" value="saturday" 
                                       style="width: 16px; height: 16px; accent-color: #f39c12;">
                                <span style="color: white; font-size: 14px;">Saturday</span>
                            </label>
                            
                            <label style="display: flex; align-items: center; gap: 8px; background: rgba(55, 65, 81, 0.5); padding: 12px; border-radius: 8px; cursor: pointer; transition: all 0.3s ease; border: 1px solid #4b5563;"
                                   onmouseover="this.style.background='rgba(55, 65, 81, 0.8)'" 
                                   onmouseout="this.style.background='rgba(55, 65, 81, 0.5)'">
                                <input type="checkbox" name="operating_days[]" value="sunday" 
                                       style="width: 16px; height: 16px; accent-color: #f39c12;">
                                <span style="color: white; font-size: 14px;">Sunday</span>
                            </label>
                        </div>
                    </div>
                    
                    <!-- SAVE BUTTONS FOR TIMING TAB -->
                    <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 32px; padding-top: 24px; border-top: 1px solid #4b5563;">
                        <button type="button" onclick="closeEditModal()" 
                                style="padding: 12px 24px; background-color: #6b7280; color: white; border: none; border-radius: 8px; cursor: pointer;">
                            Cancel
                        </button>
                        <button type="submit" 
                                style="padding: 12px 24px; background-color: #f39c12; color: white; border: none; border-radius: 8px; cursor: pointer;">
                            💾 Save Changes
                        </button>
                    </div>
                </div>
                
                <!-- CONTROL TAB -->
                <div id="controlTab" style="display: none;">
                    <div style="background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 8px; padding: 16px; margin-bottom: 24px;">
                        <p style="color: #22c55e; margin: 0; font-weight: 500;">🎛️ Real-time Control Panel</p>
                    </div>
                    
                    <!-- Current Status Display -->
                    <div id="statusInfo" style="background: rgba(75, 85, 99, 0.5); padding: 20px; border-radius: 8px; border-left: 4px solid #22c55e; margin-bottom: 24px;">
                        <h4 style="color: white; font-weight: bold; margin-bottom: 12px;">📊 Current Status</h4>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; font-size: 14px;">
                            <div><span style="color: #9ca3af;">Status:</span> <span id="currentStatusDisplay" style="color: #22c55e; font-weight: bold;">Loading...</span></div>
                            <div><span style="color: #9ca3af;">Active Bookings:</span> <span id="activeBookingsDisplay" style="color: white; font-weight: 500;">0</span></div>
                        </div>
                    </div>

                    <!-- Control Buttons (Not part of form submission) -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 24px;">
                        <button type="button" onclick="performGarageControl('open')" 
                                style="padding: 16px; background: #10b981; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; transition: all 0.3s ease;"
                                onmouseover="this.style.background='#059669'" 
                                onmouseout="this.style.background='#10b981'">
                            🟢 Open
                        </button>
                        <button type="button" onclick="performGarageControl('close')" 
                                style="padding: 16px; background: #ef4444; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; transition: all 0.3s ease;"
                                onmouseover="this.style.background='#dc2626'" 
                                onmouseout="this.style.background='#ef4444'">
                            🔴 Close
                        </button>
                        <button type="button" onclick="performGarageControl('maintenance')" 
                                style="padding: 16px; background: #f97316; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; transition: all 0.3s ease;"
                                onmouseover="this.style.background='#ea580c'" 
                                onmouseout="this.style.background='#f97316'">
                            🟡 Maintenance
                        </button>
                    </div>

                    <!-- Additional Control Options -->
                    <div style="margin-bottom: 24px;">
                        <label style="color: white; font-weight: 500; margin-bottom: 8px; display: block;">Reason for Status Change</label>
                        <input type="text" id="statusChangeReason" 
                               style="width: 100%; padding: 12px; background-color: #374151; color: white; border: 1px solid #6b7280; border-radius: 8px; box-sizing: border-box;"
                               placeholder="Enter reason (optional)">
                    </div>
                    
                    <!-- NO SAVE BUTTONS HERE - Control actions are immediate -->
                    <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 32px; padding-top: 24px; border-top: 1px solid #4b5563;">
                        <button type="button" onclick="closeEditModal()" 
                                style="padding: 12px 24px; background-color: #6b7280; color: white; border: none; border-radius: 8px; cursor: pointer;">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        console.log('✅ Single consistent modal created with tab-specific save buttons');
        
        // Fetch and populate data
        fetch('business_desh.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=get_parking_details&garage_id=${garageId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                const garageData = data.data;
                
                // Populate basic fields
                document.getElementById('editGarageId').value = garageData.garage_id;
                document.getElementById('editParkingName').value = garageData.Parking_Space_Name;
                document.getElementById('editParkingCapacity').value = garageData.Parking_Capacity;
                document.getElementById('editPricePerHour').value = garageData.PriceperHour;
                document.getElementById('currentGarageName').textContent = garageData.Parking_Space_Name;
                
                console.log('✅ Basic data populated');
                
                // NOW FETCH TIMING DATA
                fetchTimingDataForModal(garageData.garage_id);
            }
        })
        .catch(error => console.error('❌ Error:', error));
    };
    
    // Single switchTab function
    window.switchTab = function(tabName) {
        console.log('🔄 SINGLE: Switching to tab:', tabName);
        
        // Hide all tabs
        ['basicTab', 'timingTab', 'controlTab'].forEach(id => {
            const tab = document.getElementById(id);
            if (tab) tab.style.display = 'none';
        });
        
        // Reset all buttons
        ['basicTabBtn', 'timingTabBtn', 'controlTabBtn'].forEach(id => {
            const btn = document.getElementById(id);
            if (btn) {
                btn.style.color = '#9ca3af';
                btn.style.borderBottomColor = 'transparent';
            }
        });
        
        // Show selected
        const targetTab = document.getElementById(tabName + 'Tab');
        const targetBtn = document.getElementById(tabName + 'TabBtn');
        
        if (targetTab) targetTab.style.display = 'block';
        if (targetBtn) {
            targetBtn.style.color = 'white';
            targetBtn.style.borderBottomColor = '#f39c12';
        }
    };
    
    // Add helper functions for operating days
    window.selectAllDays = function() {
        const checkboxes = document.querySelectorAll('input[name="operating_days[]"]');
        checkboxes.forEach(checkbox => checkbox.checked = true);
    };
    
    window.clearAllDays = function() {
        const checkboxes = document.querySelectorAll('input[name="operating_days[]"]');
        checkboxes.forEach(checkbox => checkbox.checked = false);
    };
    
    window.toggleOperatingHours = function() {
        const is24_7 = document.getElementById('is24_7');
        const operatingHours = document.getElementById('operatingHours');
        
        if (is24_7 && operatingHours) {
            operatingHours.style.display = is24_7.checked ? 'none' : 'grid';
        }
    };
    
    console.log('🎯 CLEANUP COMPLETE - Tab-specific save buttons implemented!');
}, 500);


function loadGarageRealTimeStatus(garageId) {
    console.log('🔄 Loading real-time status for garage:', garageId);
    
    // Ensure the real-time control section exists
    let statusDisplay = document.getElementById('currentStatusDisplay');
    if (!statusDisplay) {
        createRealTimeControlSection();
        statusDisplay = document.getElementById('currentStatusDisplay');
    }
    
    // Show loading state
    if (statusDisplay) {
        statusDisplay.innerHTML = 'Loading...';
        statusDisplay.style.color = '#9ca3af';
    }
    
    // Create form data for the request
    const formData = new FormData();
    formData.append('action', 'get_garage_status');
    formData.append('garage_id', garageId);
    
    // Fetch real status from database
    fetch('business_desh.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        console.log('📡 Received garage status:', data);
        
        if (data.success && data.garage_data) {
            // Update status display with real data
            updateCurrentStatusDisplay(data.garage_data.current_status || 'open');
            
            // Update active bookings if available
            const activeBookingsDisplay = document.getElementById('activeBookingsDisplay');
            if (activeBookingsDisplay && data.garage_data.active_bookings_count !== undefined) {
                activeBookingsDisplay.textContent = data.garage_data.active_bookings_count;
            }
        } else {
            console.error('❌ Failed to load garage status:', data.message);
            // Default to open if can't load
            updateCurrentStatusDisplay('open');
        }
    })
    .catch(error => {
        console.error('🚨 Error loading garage status:', error);
        // Default to open if error
        updateCurrentStatusDisplay('open');
    });
}
// Enhanced garage control function
function performGarageControl(action) {
    console.log('🎛️ Garage control called with action:', action);
    
    const garageId = document.getElementById('editGarageId')?.value;
    if (!garageId) {
        alert('❌ Error: No garage selected');
        console.error('No garage ID found');
        return;
    }
    
    console.log('🏠 Controlling garage:', garageId);
    
    // Get reason and force close values
    const reasonElement = document.getElementById('statusChangeReason');
    const forceCloseElement = document.getElementById('forceCloseOption');
    
    const reason = reasonElement ? reasonElement.value || 'Manual control' : 'Manual control';
    const forceClose = forceCloseElement ? forceCloseElement.checked : false;
    
    console.log('📝 Reason:', reason);
    console.log('🔒 Force close:', forceClose);
    
    // Handle maintenance duration
    let duration = 1;
    if (action === 'maintenance') {
        const durationInput = prompt('How many hours for maintenance?', '2');
        if (!durationInput || durationInput <= 0) {
            console.log('❌ Maintenance cancelled - invalid duration');
            return;
        }
        duration = parseInt(durationInput);
        console.log('⏱️ Maintenance duration:', duration, 'hours');
    }
    
    // Disable clicked button
    const clickedButton = document.getElementById(action + 'Button');
    let originalText = '';
    if (clickedButton) {
        originalText = clickedButton.textContent;
        clickedButton.disabled = true;
        clickedButton.textContent = 'Processing...';
        clickedButton.style.opacity = '0.6';
    }
    
    // Prepare form data
    const formData = new FormData();
    formData.append('action', 'control_garage_status');
    formData.append('garage_id', garageId);
    formData.append('task_to_perform', action);
    formData.append('reason', reason);
    formData.append('force_close', forceClose ? '1' : '0');
    
    if (action === 'maintenance') {
        formData.append('duration', duration);
    }
    
    // Log what we're sending
    console.log('📤 Sending control request:');
    for (let [key, value] of formData.entries()) {
        console.log(`  ${key}: ${value}`);
    }
    
    // Send request
    fetch('business_desh.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('📥 Response status:', response.status);
        return response.json();
    })
    .then(data => {
        console.log('📋 Control response:', data);
        
        if (data.success) {
    alert('✅ ' + (data.message || 'Status updated successfully!'));
    console.log('✅ Success:', data.message);
    
    // Update the status display with correct status
    let newStatus;
    switch(action) {
        case 'close': newStatus = 'closed'; break;
        case 'open': newStatus = 'open'; break;
        case 'maintenance': newStatus = 'maintenance'; break;
    }
    updateStatusDisplayAfterControl(newStatus);
    
    // Clear the reason field
    if (reasonElement) reasonElement.value = '';
    if (forceCloseElement) forceCloseElement.checked = false;
} else {
            alert('❌ Error: ' + (data.message || 'Unknown error occurred'));
            console.error('❌ Control failed:', data.message);
        }
    })
    .catch(error => {
        console.error('🚨 Network error:', error);
        alert('🚨 Network error occurred. Please check your connection and try again.');
    })
    .finally(() => {
        // Re-enable button
        if (clickedButton) {
            clickedButton.disabled = false;
            clickedButton.textContent = originalText;
            clickedButton.style.opacity = '1';
        }
        console.log('🔓 Button re-enabled');
    });
}

function updateStatusDisplayAfterControl(action) {
    console.log('🚨 updateStatusDisplayAfterControl called with action:', action);
    console.log('🚨 Called from:', new Error().stack);
    const statusDisplay = document.getElementById('currentStatusDisplay');
    if (statusDisplay) {
        switch(action) {
            case 'open':
                statusDisplay.innerHTML = '🟢 OPEN';
                statusDisplay.style.color = '#22c55e';
                break;
            case 'closed':  // ← CHANGED: was 'close', now 'closed'
                statusDisplay.innerHTML = '🔴 CLOSED';
                statusDisplay.style.color = '#ef4444';
                break;
            case 'maintenance':
                statusDisplay.innerHTML = '🟡 MAINTENANCE';
                statusDisplay.style.color = '#f59e0b';
                break;
            default:
                // Handle unexpected values
                statusDisplay.innerHTML = '❓ ' + action.toUpperCase();
                statusDisplay.style.color = '#6b7280';
                console.warn('Unknown status:', action);
                break;
        }
    }
    
    console.log('📊 Status display updated to:', action.toUpperCase());
}
// Add these helper functions for operating days
function selectAllDays() {
    const checkboxes = document.querySelectorAll('input[name="operating_days[]"]');
    checkboxes.forEach(checkbox => checkbox.checked = true);
    console.log('✅ All days selected');
}

function clearAllDays() {
    const checkboxes = document.querySelectorAll('input[name="operating_days[]"]');
    checkboxes.forEach(checkbox => checkbox.checked = false);
    console.log('❌ All days cleared');
}

function toggleOperatingHours() {
    const is24_7 = document.getElementById('is24_7');
    const operatingHours = document.getElementById('operatingHours');
    const operatingDaysSection = document.getElementById('operatingDaysSection') || 
                                 document.querySelector('[style*="Operating Days"]')?.parentElement;
    
    if (is24_7 && operatingHours) {
        if (is24_7.checked) {
            // 24/7 MODE ENABLED
            console.log('🕐 24/7 mode enabled');
            
            // Hide time selection (not needed for 24/7)
            operatingHours.style.display = 'none';
            
            // AUTO-SELECT ALL 7 DAYS
            const dayCheckboxes = document.querySelectorAll('input[name="operating_days[]"]');
            dayCheckboxes.forEach(checkbox => {
                checkbox.checked = true;
                checkbox.disabled = true; // Disable editing since it's 24/7
            });
            
            // Set times to 24-hour operation (optional, for display)
            const openingTime = document.getElementById('openingTime');
            const closingTime = document.getElementById('closingTime');
            if (openingTime) openingTime.value = '00:00';
            if (closingTime) closingTime.value = '23:59';
            
            // Add visual indicator for operating days
            if (operatingDaysSection) {
                operatingDaysSection.style.opacity = '0.6';
                
                // Add 24/7 indicator
                let indicator = operatingDaysSection.querySelector('.day-24-7-indicator');
                if (!indicator) {
                    indicator = document.createElement('div');
                    indicator.className = 'day-24-7-indicator';
                    indicator.style.cssText = `
                        background: linear-gradient(45deg, #10b981, #059669);
                        color: white;
                        padding: 8px 16px;
                        border-radius: 6px;
                        text-align: center;
                        font-weight: bold;
                        margin-top: 8px;
                        font-size: 14px;
                    `;
                    indicator.textContent = '🕐 Open 24/7 - All Days Selected Automatically';
                    operatingDaysSection.appendChild(indicator);
                }
            }
            
        } else {
            // REGULAR HOURS MODE
            console.log('⏰ Regular hours mode enabled');
            
            // Show time selection
            operatingHours.style.display = 'grid';
            
            // Re-enable day selection
            const dayCheckboxes = document.querySelectorAll('input[name="operating_days[]"]');
            dayCheckboxes.forEach(checkbox => {
                checkbox.disabled = false;
            });
            
            // Restore operating days section appearance
            if (operatingDaysSection) {
                operatingDaysSection.style.opacity = '1';
                
                // Remove 24/7 indicator
                const indicator = operatingDaysSection.querySelector('.day-24-7-indicator');
                if (indicator) {
                    indicator.remove();
                }
            }
            
            // Reset to default times
            const openingTime = document.getElementById('openingTime');
            const closingTime = document.getElementById('closingTime');
            if (openingTime && openingTime.value === '00:00') openingTime.value = '06:00';
            if (closingTime && closingTime.value === '23:59') closingTime.value = '22:00';
        }
    }
}
// Add this function to fetch and populate timing data
function fetchTimingDataForModal(garageId) {
    console.log('⏰ Fetching timing data for:', garageId);
    
    fetch('business_desh.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=get_garage_timing&garage_id=${garageId}`
    })
    .then(response => response.json())
    .then(data => {
        console.log('📋 Timing data response:', data);
        
        if (data.success && data.data) {
            populateTimingFieldsInModal(data.data);
        } else {
            console.log('⚠️ No timing data found, trying to get from operating schedule...');
            // Fallback: get data from garage_operating_schedule table
            fetchOperatingSchedule(garageId);
        }
    })
    .catch(error => {
        console.error('❌ Error fetching timing data:', error);
        // Set defaults
        setDefaultTimingValues();
    });
}

function populateTimingFieldsInModal(timingData) {
    console.log('📝 Populating timing fields with:', timingData);
    
    // Set 24/7 toggle FIRST
    const is24_7Element = document.getElementById('is24_7');
    if (is24_7Element) {
        is24_7Element.checked = timingData.is_24_7 == 1;
        console.log('✅ Set 24/7:', timingData.is_24_7);
    }
    
    // Set times
    const openingTimeElement = document.getElementById('openingTime');
    if (openingTimeElement) {
        let openingTime = timingData.opening_time || '06:00:00';
        if (openingTime.length === 8) {
            openingTime = openingTime.substring(0, 5);
        }
        openingTimeElement.value = openingTime;
        console.log('✅ Set opening time:', openingTime);
    }
    
    const closingTimeElement = document.getElementById('closingTime');
    if (closingTimeElement) {
        let closingTime = timingData.closing_time || '22:00:00';
        if (closingTime.length === 8) {
            closingTime = closingTime.substring(0, 5);
        }
        closingTimeElement.value = closingTime;
        console.log('✅ Set closing time:', closingTime);
    }
    
    // Set operating days
    setTimeout(() => {
        // FIX: If 24/7 is enabled, override operating days to all days
        if (timingData.is_24_7 == 1) {
            console.log('🕐 24/7 mode detected - setting all days');
            const allDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            populateOperatingDaysInModal(allDays.join(','));
        } else {
            populateOperatingDaysInModal(timingData.operating_days);
        }
        
        // Apply the 24/7 toggle (this will handle the UI updates)
        toggleOperatingHours();
    }, 100);
}
function populateOperatingDaysInModal(operatingDaysData) {
    console.log('📅 Setting operating days:', operatingDaysData);
    
    const checkboxes = document.querySelectorAll('input[name="operating_days[]"]');
    console.log(`Found ${checkboxes.length} checkboxes`);
    
    if (checkboxes.length === 0) {
        console.error('❌ No operating day checkboxes found!');
        return;
    }
    
    // Clear all checkboxes first
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
        console.log(`Cleared: ${checkbox.value}`);
    });
    
    if (operatingDaysData) {
        let operatingDays = [];
        
        // Handle different data formats
        if (Array.isArray(operatingDaysData)) {
            operatingDays = operatingDaysData.map(day => day.toLowerCase().trim());
        } else if (typeof operatingDaysData === 'string') {
            operatingDays = operatingDaysData.split(',').map(day => day.toLowerCase().trim());
        }
        
        console.log('📅 Processed operating days:', operatingDays);
        
        // Check the appropriate checkboxes
        checkboxes.forEach(checkbox => {
            const dayValue = checkbox.value.toLowerCase();
            if (operatingDays.includes(dayValue)) {
                checkbox.checked = true;
                console.log(`✅ CHECKED: ${dayValue}`);
            } else {
                console.log(`❌ UNCHECKED: ${dayValue}`);
            }
        });
        
        console.log('🎉 Operating days populated successfully!');
    } else {
        console.log('⚠️ No operating days data, defaulting to all days');
        // Default to all days
        checkboxes.forEach(checkbox => {
            checkbox.checked = true;
        });
    }
}

// Fallback function to get data from garage_operating_schedule
function fetchOperatingSchedule(garageId) {
    console.log('📋 Fetching from operating schedule for:', garageId);
    
    // You might need to add this action to your PHP
    fetch('business_desh.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=get_operating_schedule&garage_id=${garageId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data) {
            populateTimingFieldsInModal(data.data);
        } else {
            setDefaultTimingValues();
        }
    })
    .catch(error => {
        console.error('❌ Error fetching operating schedule:', error);
        setDefaultTimingValues();
    });
}

function setDefaultTimingValues() {
    console.log('⚠️ Setting default timing values');
    
    // Set default times
    const openingTimeElement = document.getElementById('openingTime');
    if (openingTimeElement) openingTimeElement.value = '06:00';
    
    const closingTimeElement = document.getElementById('closingTime');
    if (closingTimeElement) closingTimeElement.value = '22:00';
    
    // Set all days as default
    setTimeout(() => {
        const checkboxes = document.querySelectorAll('input[name="operating_days[]"]');
        checkboxes.forEach(checkbox => checkbox.checked = true);
    }, 100);
}
</script>

</head>
<body class="relative min-h-screen">
    <!-- Background Image with Overlay -->
    <div class="fixed inset-0 bg-cover bg-center bg-no-repeat z-[-2]" 
         style="background-image: url('https://images.unsplash.com/photo-1573348722427-f1d6819fdf98?q=80&w=1374&auto=format&fit=crop&ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D')">
    </div>
    <div class="fixed inset-0 bg-black/50 z-[-1]"></div>
    
    <!-- Header Based on home.php -->
    <header class="sticky top-0 z-50 bg-black/50 backdrop-blur-md border-b border-white/20">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <a href="#" class="flex items-center gap-4 text-white">
                <div class="w-10 h-10 bg-primary rounded-full flex justify-center items-center overflow-hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><path d="M9 18V6h4.5a2.5 2.5 0 0 1 0 5H9"></path></svg>
                </div>
                <h1 class="text-xl font-semibold drop-shadow-md">পার্কিং লাগবে - Business Dashboard</h1>
            </a>
            
            <nav class="hidden md:block">
                <ul class="flex gap-8">
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Dashboard</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Manage Spaces</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Bookings</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Reports</a></li>
                </ul>
            </nav>
            
            <div class="hidden md:flex items-center gap-4">
                <a href="home.php" class="btn btn-outline btn-sm text-white border-primary hover:bg-primary hover:border-primary">
                    Switch To User Mode
                </a>
                
                <!-- Add this notification icon to the header section, before the profile dropdown -->
<div class="relative">
    <button id="notification-button" class="btn btn-sm btn-ghost text-white relative">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
        </svg>
        <span id="notification-count" class="absolute -top-1 -right-1 bg-primary text-white text-xs font-bold rounded-full h-5 w-5 flex items-center justify-center <?php echo isset($totalNotifications) && $totalNotifications > 0 ? '' : 'hidden'; ?>">
    <?php echo isset($totalNotifications) && $totalNotifications > 0 ? $totalNotifications : ''; ?>
</span>
    </button>
    
    <div id="notification-dropdown" class="absolute right-0 mt-2 w-80 bg-gray-800 shadow-lg rounded-lg z-50 hidden">
        <div class="p-3 border-b border-gray-700">
            <h3 class="font-bold text-white">Notifications</h3>
        </div>
        
        <div id="notification-content" class="max-h-96 overflow-y-auto">
            <?php 
            try {
                if (!isset($userNotifications) || 
                    (empty($userNotifications['bookings']) && 
                     empty($userNotifications['verifications']) && 
                     empty($userNotifications['payments']))) {
                    // No notifications or error occurred
                    echo '<div class="p-6 text-center text-white/70">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-white/40 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                                <line x1="9" y1="9" x2="9.01" y2="9"></line>
                                <line x1="15" y1="9" x2="15.01" y2="9"></line>
                            </svg>
                            <p>No pending notifications!</p>
                          </div>';
                } else {
                    // Display bookings
                    if (!empty($userNotifications['bookings'])) {
                        echo '<div class="p-3 bg-gray-700">
                                <h4 class="font-semibold text-white">New Bookings (' . count($userNotifications['bookings']) . ')</h4>
                              </div>';
                        
                        foreach ($userNotifications['bookings'] as $booking) {
                            echo '<div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                                    <div>
                                        <p class="font-medium text-white">' . htmlspecialchars($booking['parking_name']) . '</p>
                                        <p class="text-sm text-white/70">
                                            Booked by ' . htmlspecialchars($booking['customer_name'] ?? 'Unknown') . ' for 
                                            ' . htmlspecialchars($booking['booking_date_formatted'] ?? $booking['booking_date']) . ' at 
                                            ' . htmlspecialchars($booking['booking_time_formatted'] ?? $booking['booking_time']) . '
                                        </p>
                                    </div>
                                    <div class="mt-2">
                                        <a href="booking_details.php?id=' . $booking['id'] . '" class="text-xs text-primary hover:underline">View Details</a>
                                    </div>
                                  </div>';
                        }
                    }
                    
                    // Display verifications
                    if (!empty($userNotifications['verifications'])) {
                        echo '<div class="p-3 bg-gray-700">
                                <h4 class="font-semibold text-white">Verification Updates (' . count($userNotifications['verifications']) . ')</h4>
                              </div>';
                        
                        foreach ($userNotifications['verifications'] as $verification) {
                            $statusClass = $verification['is_verified'] ? 'text-green-400' : 'text-yellow-400';
                            $statusText = $verification['is_verified'] ? 'verified' : 'pending verification';
                            
                            echo '<div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                                    <div>
                                        <p class="font-medium text-white">Garage Owner Status</p>
                                        <p class="text-sm text-white/70">
                                            Your account is now 
                                            <span class="' . $statusClass . '">' . $statusText . '</span>
                                        </p>
                                    </div>
                                  </div>';
                        }
                    }
                    

                   // Display garage verifications
if (!empty($userNotifications['garage_verifications'])) {
    echo '<div class="p-3 bg-gray-700">
            <h4 class="font-semibold text-white">Garage Verification Updates (' . count($userNotifications['garage_verifications']) . ')</h4>
          </div>';
    
    foreach ($userNotifications['garage_verifications'] as $verification) {
        $statusClass = $verification['is_verified'] ? 'text-green-400' : 'text-yellow-400';
        $statusText = $verification['is_verified'] ? 'verified' : 'pending verification';
        
        echo '<div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                <div>
                    <p class="font-medium text-white">' . htmlspecialchars($verification['Parking_Space_Name']) . '</p>
                    <p class="text-sm text-white/70">
                        Your garage is now 
                        <span class="' . $statusClass . '">' . $statusText . '</span>
                    </p>
                </div>
              </div>';
    }
} 


                    // Display payments
                    if (!empty($userNotifications['payments'])) {
                        echo '<div class="p-3 bg-gray-700">
                                <h4 class="font-semibold text-white">Payment Updates (' . count($userNotifications['payments']) . ')</h4>
                              </div>';
                        
                        foreach ($userNotifications['payments'] as $payment) {
                            $statusClass = $payment['payment_status'] === 'paid' ? 'text-green-400' : 'text-yellow-400';
                            
                            echo '<div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                                    <div>
                                        <p class="font-medium text-white">৳' . number_format((float)$payment['amount'], 2) . ' - ' . htmlspecialchars($payment['parking_name']) . '</p>
                                        <p class="text-sm text-white/70">
                                            Payment 
                                            <span class="' . $statusClass . '">' . $payment['payment_status'] . '</span> - 
                                            ' . ($payment['payment_date_formatted'] ?? date('M d, Y h:i A', strtotime($payment['payment_date']))) . '
                                        </p>
                                    </div>
                                    <div class="mt-2">
                                        <a href="payment_details.php?id=' . $payment['payment_id'] . '" class="text-xs text-primary hover:underline">View Details</a>
                                    </div>
                                  </div>';
                        }
                    }
                }
            } catch (Exception $e) {
                echo '<div class="p-6 text-center text-white/70 error-message">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-red-400 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                        <p>An error occurred. Please try again.</p>
                      </div>';
            }
            ?>
        </div>
        
        <div class="p-3 border-t border-gray-700 text-center">
            <a href="?view_notifications=1" class="btn btn-sm btn-ghost text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="9 11 12 14 22 4"></polyline>
                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                </svg>
                Mark All as Read
            </a>
        </div>
    </div>
</div>
                <!-- Profile Dropdown with Improved Styling -->
<div class="relative">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" class="btn btn-circle avatar">
            <div class="w-10 h-10 rounded-full bg-primary/20 border-2 border-primary overflow-hidden flex items-center justify-center cursor-pointer">
                <span class="text-xl font-bold text-primary"><?php echo $firstLetter; ?></span>
            </div>
        </div>
        <ul tabindex="0" class="dropdown-content z-20 p-0 shadow-xl bg-gray-800/90 backdrop-blur-sm rounded-xl w-60 mt-2 overflow-hidden">
            <!-- Header with user info -->
            <li class="p-4 border-b border-gray-700/50">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-primary/20 border-2 border-primary overflow-hidden flex items-center justify-center">
                        <span class="text-xl font-bold text-primary"><?php echo $firstLetter; ?></span>
                    </div>
                    <div>
                        <div class="font-semibold text-white"><?php echo htmlspecialchars($fullName); ?></div>
                        <div class="text-xs text-white/60"><?php echo htmlspecialchars($email); ?></div>
                    </div>
                </div>
            </li>
            
            <!-- Menu items - note we're NOT using the menu class -->
            <div class="py-1">
                <a href="my_profile.php" class="block px-4 py-2.5 text-white/90 hover:bg-gray-700/50 hover:text-primary transition-colors">My Profile</a>
                
                <a href="payment_history.php" class="block px-4 py-2.5 text-white/90 hover:bg-gray-700/50 hover:text-primary transition-colors">Payment History</a>
            </div>
            
            <!-- Logout with divider -->
            <div class="border-t border-gray-700/50 mt-1">
                <a href="logout.php" class="flex items-center px-4 py-3 text-white/90 hover:bg-gray-700/50 hover:text-primary transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    Logout
                </a>
            </div>
        </ul>
    </div>
</div>
            </div>
            
            <button class="md:hidden btn btn-ghost text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
        </div>
    </header>
    
    <?php
// Display notification if there are unverified garages
if (!empty($unverifiedGarages)):
?>

<?php endif; ?>


    <!-- Main Content -->
    <main class="container mx-auto px-4 py-10">
        <!-- Hero Section -->
        <section class="flex flex-col items-center text-center py-8">
            <h2 class="text-4xl md:text-5xl font-bold text-white mb-3 drop-shadow-md">Parking Owner Dashboard</h2>
            <p class="text-lg text-white/90 max-w-2xl mb-8">Manage your parking spaces and view statistics</p>
        </section>
        
        <!-- Dashboard Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Total Parking Spaces Card -->
            <div class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-white/80 text-sm font-medium mb-1">Total Parking Spaces</h3>
                        <p class="text-white text-3xl font-bold"><?php echo $totalSpaces; ?></p>
                    </div>
                    <div class="w-12 h-12 rounded-full bg-primary/20 border-2 border-primary flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                            <path d="M9 18V6h4.5a2.5 2.5 0 0 1 0 5H9"></path>
                        </svg>
                    </div>
                </div>
                <div class="mt-3 text-white/70 text-xs flex items-center">
                    
                    
                </div>
            </div>
            
            <!-- Active Bookings Card -->
            <div class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-white/80 text-sm font-medium mb-1">Active Bookings</h3>
                        <p class="text-white text-3xl font-bold"><?php echo $activeBookings; ?></p>
                    </div>
                    <div class="w-12 h-12 rounded-full bg-primary/20 border-2 border-primary flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                </div>
                <div class="mt-3 text-white/70 text-xs flex items-center">
                    
                    
                </div>
            </div>
            
            <!-- Occupancy Rate Card -->
            <div class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-white/80 text-sm font-medium mb-1">Occupancy Rate</h3>
                        <p class="text-white text-3xl font-bold"><?php echo $occupancyRate; ?>%</p>
                    </div>
                    <div class="w-12 h-12 rounded-full bg-primary/20 border-2 border-primary flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                </div>
                <div class="mt-3 text-white/70 text-xs flex items-center">
                    
                    
                </div>
            </div>
            
            <!-- Monthly Income Card -->
            <div class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-white/80 text-sm font-medium mb-1">Monthly Income</h3>
                        <p class="text-white text-3xl font-bold">৳<?php echo $monthlyIncome; ?></p>
                    </div>
                    <div class="w-12 h-12 rounded-full bg-primary/20 border-2 border-primary flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
                <div class="mt-3 text-white/70 text-xs flex items-center">
                    
                </div>
            </div>
        </div>
        
        <!-- Chart Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Monthly Income Chart -->
            <div class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl">
                <h3 class="text-white text-lg font-semibold mb-4">Monthly Income (2025)</h3>
                <div class="h-64">
                    <canvas id="incomeChart"></canvas>
                </div>
            </div>
            
            <!-- Daily Occupancy Chart -->
            <div class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl">
                <h3 class="text-white text-lg font-semibold mb-4">Daily Occupancy Rate (Current Month)</h3>
                <div class="h-64">
                    <canvas id="occupancyChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Recent Bookings Table -->
        <!-- Updated Recent Bookings Table with Payment Status Column -->
<div class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl mb-8">
    <div class="flex justify-between items-center mb-4">
        <h3 class="text-white text-lg font-semibold">Recent Bookings</h3>
        <a href="#" class="text-primary hover:text-primary-dark text-sm">View All</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-white/90">
            <thead class="text-left border-b border-white/20">
                <tr>
                    <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Booking ID</th>
                    <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Customer</th>
                    <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Parking Space</th>
                    <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Date</th>
                    <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Time</th>
                    <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Duration</th>
                    <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Amount</th>
                    <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Payment</th>
                    <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($recentBookings as $booking): ?>
                <tr class="hover:bg-white/5">
                    <td class="py-3 px-2 text-sm font-medium"><?php echo htmlspecialchars($booking['id']); ?></td>
                    <td class="py-3 px-2 text-sm"><?php echo htmlspecialchars($booking['customer']); ?></td>
                    <td class="py-3 px-2 text-sm"><?php echo htmlspecialchars($booking['parking']); ?></td>
                    <td class="py-3 px-2 text-sm"><?php echo htmlspecialchars($booking['date']); ?></td>
                    <td class="py-3 px-2 text-sm"><?php echo htmlspecialchars($booking['time_range']); ?></td>
                    <td class="py-3 px-2 text-sm"><?php echo htmlspecialchars($booking['duration']); ?></td>
                    <td class="py-3 px-2 text-sm"><?php echo htmlspecialchars($booking['amount']); ?></td>
                    <!-- NEW: Payment Status Column -->
                    <td class="py-3 px-2 text-sm">
                        <?php 
                        $paymentStatusClass = '';
                        switch($booking['payment_status']) {
                            case 'Paid':
                                $paymentStatusClass = 'bg-green-500/20 text-green-400';
                                break;
                            case 'Refunded':
                                $paymentStatusClass = 'bg-purple-500/20 text-purple-400';
                                break;
                            case 'Pending':
                                $paymentStatusClass = 'bg-yellow-500/20 text-yellow-400';
                                break;
                            default:
                                $paymentStatusClass = 'bg-gray-500/20 text-gray-400';
                        }
                        ?>
                        <span class="px-2 py-1 rounded-full text-xs <?php echo $paymentStatusClass; ?>">
                            <?php echo htmlspecialchars($booking['payment_status']); ?>
                        </span>
                    </td>
                    <!-- Booking Status Column -->
                    <td class="py-3 px-2 text-sm">
                        <?php 
                        $statusClass = '';
                        switch($booking['raw_status']) {
                            case 'upcoming':
                                $statusClass = 'bg-blue-500/20 text-blue-400';
                                break;
                            case 'active':
                                $statusClass = 'bg-green-500/20 text-green-400';
                                break;
                            case 'completed':
                                $statusClass = 'bg-gray-500/20 text-gray-400';
                                break;
                            case 'cancelled':
                                $statusClass = 'bg-red-500/20 text-red-400';
                                break;
                            default:
                                $statusClass = 'bg-blue-500/20 text-blue-400';
                        }
                        ?>
                        <span class="px-2 py-1 rounded-full text-xs <?php echo $statusClass; ?>">
                            <?php echo htmlspecialchars($booking['status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
        
        <!-- Map Section -->
        <div class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl mb-8">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-white text-lg font-semibold">Your Parking Locations</h3>
                <a href="reg_for_business.php" class="text-primary hover:text-primary-dark text-sm">Add New Location</a>
            </div>
            <div class="h-[400px] rounded-lg overflow-hidden border border-white/20">
                <div id="parkingMap" class="w-full h-full"></div>
            </div>
        </div>
        
        <!-- Parking Space Summary -->
<!-- Updated Parking Space Summary with Ratings -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
    <?php foreach ($parkingSpacesSummary as $index => $space): 
        $garageName = $space['name'];
        $isVerified = isset($space['is_verified']) ? $space['is_verified'] : ($garageVerificationStatus[$garageName] ?? false);
        $garageId = isset($space['garage_id']) ? $space['garage_id'] : ($index + 1);
        $rating = $space['average_rating'] ?? 0;
        $totalRatings = $space['total_ratings'] ?? 0;
    ?>
    <div class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 overflow-hidden shadow-xl animate-fadeIn">
        <?php 
// Check if garage has uploaded image
$garageImage = !empty($space['garage_image']) ? $space['garage_image'] : null;
$displayImage = $garageImage ? $garageImage : "https://source.unsplash.com/600x400/?parking,garage&sig=" . ($index + 1);
?>
<div class="h-32 bg-center bg-cover" style="background-image: url('<?php echo $displayImage; ?>')"></div>
        <div class="p-5">
            <div class="flex justify-between items-start mb-2">
    <h3 class="text-white text-lg font-semibold"><?php echo htmlspecialchars($space['name']); ?></h3>
    
    <!-- Status Badges Container -->
<div class="flex gap-1 items-center flex-wrap justify-end">
    <!-- Verified Status -->
    <?php if ($isVerified): ?>
        <span class="px-2 py-1 bg-green-500/20 text-green-400 text-xs rounded-full flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 mr-1" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
            </svg>
            Verified
        </span>
    <?php else: ?>
        <span class="px-2 py-1 bg-yellow-500/20 text-yellow-400 text-xs rounded-full flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 mr-1" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>
            Pending
        </span>
    <?php endif; ?>
    
    <!-- FIXED: Conditional Status and Hours Display -->
    <?php 
$manualStatus = getManualGarageStatus($space);
$is24_7 = isset($space['is_24_7']) && $space['is_24_7'];
?>

<?php if ($is24_7): ?>
    <!-- 24/7 Garage: Show status or 24/7 -->
    <?php if ($manualStatus === 'maintenance'): ?>
        <span class="px-2 py-1 bg-orange-500/20 text-orange-400 text-xs rounded-full flex items-center">
            <div class="w-2 h-2 bg-orange-400 rounded-full mr-1"></div>
            Maintenance
        </span>
    <?php elseif ($manualStatus === 'closed'): ?>
        <span class="px-2 py-1 bg-red-500/20 text-red-400 text-xs rounded-full flex items-center">
            <div class="w-2 h-2 bg-red-400 rounded-full mr-1"></div>
            Closed
        </span>
    <?php else: ?>
        <span class="px-2 py-1 bg-blue-500/20 text-blue-400 text-xs rounded-full flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            24/7
        </span>
    <?php endif; ?>
<?php else: ?>
        <!-- Regular Hours Garage: Show status badge AND hours -->
        
        <?php 
    $statusColors = [
        'open' => 'bg-green-500/20 text-green-400',
        'closed' => 'bg-red-500/20 text-red-400', 
        'maintenance' => 'bg-orange-500/20 text-orange-400'
    ];
    $statusColor = $statusColors[$manualStatus] ?? 'bg-gray-500/20 text-gray-400';
    ?>
    <span class="px-2 py-1 <?php echo $statusColor; ?> text-xs rounded-full flex items-center">
        <div class="w-2 h-2 <?php echo str_replace('text-', 'bg-', explode(' ', $statusColor)[1]); ?> rounded-full mr-1 <?php echo $manualStatus === 'open' ? 'animate-pulse' : ''; ?>"></div>
        <?php echo ucfirst($manualStatus); ?>
    </span>
    
    <!-- Operating Hours Badge -->
    <?php if (isset($space['opening_time']) && isset($space['closing_time'])): ?>
        <span class="px-2 py-1 bg-blue-500/20 text-blue-400 text-xs rounded-full flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <?php echo date('g:i A', strtotime($space['opening_time'])) . ' - ' . date('g:i A', strtotime($space['closing_time'])); ?>
        </span>
    <?php endif; ?>
<?php endif; ?>
</div>
</div>
            
            <p class="text-white/80 text-sm mb-3"><?php echo htmlspecialchars($space['address']); ?></p>
            
            <!-- Rating Section -->
            <?php if ($rating > 0): ?>
                <div class="mb-3 p-3 bg-white/5 rounded-lg border border-white/10">
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center space-x-2">
                            <?php echo generateStarRating($rating, $totalRatings); ?>
                            <span class="text-white font-semibold text-sm"><?php echo $rating; ?></span>
                        </div>
                        <span class="text-white/60 text-xs">
                            <?php echo $totalRatings; ?> review<?php echo $totalRatings != 1 ? 's' : ''; ?>
                        </span>
                    </div>
                    <div class="text-center">
                        <span class="text-white/70 text-xs">
                            <?php 
                            if ($rating >= 4.5) echo "⭐ Excellent";
                            elseif ($rating >= 4.0) echo "👍 Very Good";
                            elseif ($rating >= 3.5) echo "👌 Good";
                            elseif ($rating >= 3.0) echo "😐 Average";
                            else echo "👎 Needs Improvement";
                            ?>
                        </span>
                    </div>
                </div>
            <?php else: ?>
                <div class="mb-3 p-3 bg-white/5 rounded-lg border border-white/10 text-center">
                    <div class="flex items-center justify-center space-x-2 mb-1">
                        <?php echo generateStarRating(0); ?>
                    </div>
                    <span class="text-white/60 text-xs">No reviews yet</span>
                </div>
            <?php endif; ?>
            
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div class="text-center">
                    <p class="text-white/60 text-xs mb-1">Total Spaces</p>
                    <p class="text-white text-lg font-semibold"><?php echo htmlspecialchars($space['totalSpaces']); ?></p>
                </div>
                <div class="text-center">
                    <p class="text-white/60 text-xs mb-1">Available</p>
                    <p class="text-white text-lg font-semibold"><?php echo htmlspecialchars($space['availableSpaces']); ?></p>
                </div>
            </div>
            <div class="mb-2 text-center">
                <p class="text-white/60 text-xs mb-1">Hourly Rate</p>
                <p class="text-primary text-lg font-semibold">৳<?php echo htmlspecialchars($space['hourlyRate']); ?></p>
            </div>
            <div class="flex space-x-2">
                <button onclick="showParkingDetails('<?php echo $space['garage_id']; ?>')" 
                        class="flex-1 bg-primary hover:bg-primary-dark text-white text-center text-sm py-2 rounded transition duration-300">
                    Details
                </button>
                <button onclick="openEditModal('<?php echo $space['garage_id']; ?>')" 
                        class="flex-1 bg-white/10 hover:bg-white/20 text-white text-center text-sm py-2 rounded transition duration-300">
                    Edit
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
    </main>
    
    <!-- Footer -->
    <footer class="bg-black/70 backdrop-blur-md border-t border-white/10 pt-16 pb-8">
        <div class="container mx-auto px-4 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10">
            <!-- Company Info -->
            <div>
                <h3 class="text-white text-lg font-semibold mb-4 pb-2 border-b border-primary w-max">About Us</h3>
                <ul class="space-y-2">
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Our Story</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">How It Works</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Testimonials</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Press & Media</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Careers</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Partners</a></li>
                </ul>
            </div>
            
            <!-- Services -->
            <div>
                <h3 class="text-white text-lg font-semibold mb-4 pb-2 border-b border-primary w-max">Business Services</h3>
                <ul class="space-y-2">
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Listing Parking Spaces</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Business Analytics</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Pricing Management</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Event Parking Solutions</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Business Partnerships</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">API Integration</a></li>
                </ul>
            </div>
            
            <!-- Support -->
            <div>
                <h3 class="text-white text-lg font-semibold mb-4 pb-2 border-b border-primary w-max">Support</h3>
                <ul class="space-y-2">
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Help Center</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">FAQs</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Contact Us</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Refund Policy</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Terms of Service</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Privacy Policy</a></li>
                </ul>
            </div>
            
            <!-- Contact -->
            <div>
                <h3 class="text-white text-lg font-semibold mb-4 pb-2 border-b border-primary w-max">Contact Us</h3>
                <ul class="space-y-4">
                    <li class="flex items-start gap-3 text-white/90">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 flex-shrink-0 mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                        123 Parking Avenue, Gulshan, Dhaka 1212
                    </li>
                    <li class="flex items-start gap-3 text-white/90">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 flex-shrink-0 mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                        (+880) 1700-000000
                    </li>
                    <li class="flex items-start gap-3 text-white/90">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 flex-shrink-0 mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        business@parkinglagbe.com
                    </li>
                </ul>
                
                <div class="flex gap-4 mt-6">
                    <a href="#" class="w-10 h-10 bg-white/10 rounded-full flex justify-center items-center transition-all hover:bg-primary hover:-translate-y-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path></svg>
                    </a>
                    <a href="#" class="w-10 h-10 bg-white/10 rounded-full flex justify-center items-center transition-all hover:bg-primary hover:-translate-y-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"></path></svg>
                    </a>
                    <a href="#" class="w-10 h-10 bg-white/10 rounded-full flex justify-center items-center transition-all hover:bg-primary hover:-translate-y-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg>
                    </a>
                    <a href="#" class="w-10 h-10 bg-white/10 rounded-full flex justify-center items-center transition-all hover:bg-primary hover:-translate-y-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"></path><rect x="2" y="9" width="4" height="12"></rect><circle cx="4" cy="4" r="2"></circle></svg>
                    </a>
                </div>
            </div>
        </div>
        
        <div class="container mx-auto px-4 mt-10 pt-6 border-t border-white/10 flex flex-col md:flex-row justify-between items-center gap-4">
            <p class="text-white/90 text-sm">&copy; <?php echo date('Y'); ?> পার্কিং লাগবে. All rights reserved.</p>
            <div class="flex gap-6">
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Privacy Policy</a>
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Terms of Service</a>
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Cookie Policy</a>
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Sitemap</a>
            </div>
        </div>
    </footer>
    
    <script>
        // Chart initialization with error handling and improved configuration
document.addEventListener('DOMContentLoaded', function() {
    // Initialize map centered on Dhaka, Bangladesh
    if (document.getElementById('parkingMap')) {
        /* Map initialization code (unchanged) */
        const map = L.map('parkingMap').setView([23.8103, 90.4125], 13);
        
        // Add OpenStreetMap tiles
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);
        
        // Sample parking locations
        const parkingLocations = <?php echo json_encode($parkingLocations); ?>;
        
        // Add markers for parking locations
        parkingLocations.forEach(location => {
            const marker = L.marker([location.lat, location.lng]).addTo(map);
            marker.bindPopup(`
                <div class="w-52 p-2">
                    <h3 class="text-base font-semibold mb-1">${location.name}</h3>
                    <p class="text-sm mb-1">Total Spaces: ${location.spaces}</p>
                    <p class="text-sm mb-1">Available: ${location.available}</p>
                    <p class="text-sm mb-1">Booked: ${location.booked}</p>
                    <p class="text-sm mb-1">Price: ৳${location.pricePerHour || '10'}/hour</p>
                    <button class="w-full mt-2 py-1 px-3 bg-primary text-white rounded text-sm hover:bg-primary-dark">Details</button>
                </div>
            `);
        });
    }
    
    // Initialize income chart with error handling
    try {
        const incomeCtx = document.getElementById('incomeChart');
        if (incomeCtx) {
            // Parse the PHP data with a fallback to empty array if it fails
            let monthlyData;
            try {
                monthlyData = <?php echo $monthlyIncomeData; ?>;
                if (!Array.isArray(monthlyData)) {
                    console.warn("Income data is not an array, using fallback data");
                    monthlyData = [15000, 16350, 17820, 19425, 21160, 23065, 25140, 27405, 29870, 32560, 35490, 38685];
                }
            } catch (e) {
                console.error("Error parsing income data:", e);
                monthlyData = [15000, 16350, 17820, 19425, 21160, 23065, 25140, 27405, 29870, 32560, 35490, 38685];
            }
            
            // Calculate reasonable min/max for better visualization
            const minValue = Math.max(0, Math.min(...monthlyData) * 0.8);
            const maxValue = Math.max(...monthlyData) * 1.2;
            
            new Chart(incomeCtx, {
                type: 'bar',
                data: {
                    labels: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
                    datasets: [{
                        label: 'Monthly Income (BDT)',
                        data: monthlyData,
                        backgroundColor: 'rgba(243, 156, 18, 0.7)',
                        borderColor: 'rgba(243, 156, 18, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            min: minValue,
                            max: maxValue,
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)',
                                callback: function(value) {
                                    return '৳' + value.toLocaleString();
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
                                color: 'rgba(255, 255, 255, 0.7)'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return '৳' + context.raw.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
            console.log("Income chart initialized successfully");
        } else {
            console.warn("Income chart canvas not found");
        }
    } catch (e) {
        console.error("Error initializing income chart:", e);
        // Display error message on the page
        const errorMsg = document.createElement('div');
        errorMsg.className = 'text-red-500 p-4 text-center';
        errorMsg.innerText = 'Could not load income chart. Please refresh the page.';
        
        const incomeChartContainer = document.getElementById('incomeChart')?.parentElement;
        if (incomeChartContainer) {
            incomeChartContainer.appendChild(errorMsg);
        }
    }
    
    // Initialize occupancy chart with error handling
    try {
        const occupancyCtx = document.getElementById('occupancyChart');
        if (occupancyCtx) {
            const daysInMonth = new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).getDate();
            const days = Array.from({length: daysInMonth}, (_, i) => i + 1);
            
            // Parse the PHP data with a fallback to generated data if it fails
            let occupancyData;
            try {
                occupancyData = <?php echo $dailyOccupancyData; ?>;
                if (!Array.isArray(occupancyData)) {
                    console.warn("Occupancy data is not an array, using fallback data");
                    // Generate smoother occupancy data as fallback
                    occupancyData = Array.from({length: daysInMonth}, (_, i) => {
                        // Weekend pattern (higher on weekends)
                        const isWeekend = (i % 7 === 5 || i % 7 === 6);
                        const baseOccupancy = 65;
                        return isWeekend ? 
                            Math.min(95, baseOccupancy + 20 + (Math.random() * 10 - 5)) : 
                            Math.min(95, baseOccupancy + (Math.random() * 15 - 5));
                    });
                }
            } catch (e) {
                console.error("Error parsing occupancy data:", e);
                // Generate fallback data
                occupancyData = Array.from({length: daysInMonth}, (_, i) => {
                    const isWeekend = (i % 7 === 5 || i % 7 === 6);
                    const baseOccupancy = 65;
                    return isWeekend ? 
                        Math.min(95, baseOccupancy + 20 + (Math.random() * 10 - 5)) : 
                        Math.min(95, baseOccupancy + (Math.random() * 15 - 5));
                });
            }
            
            new Chart(occupancyCtx, {
                type: 'line',
                data: {
                    labels: days,
                    datasets: [{
                        label: 'Occupancy Rate (%)',
                        data: occupancyData,
                        borderColor: 'rgba(243, 156, 18, 1)',
                        backgroundColor: 'rgba(243, 156, 18, 0.2)',
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: 'rgba(243, 156, 18, 1)',
                        fill: true,
                        tension: 0.3  // More realistic curve tension
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            min: 0,
                            max: 100,
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)',
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)',
                                maxRotation: 0,
                                autoSkip: true,
                                maxTicksLimit: 10
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: 'rgba(255, 255, 255, 0.7)'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.raw.toFixed(1) + '%';
                                }
                            }
                        }
                    }
                }
            });
            console.log("Occupancy chart initialized successfully");
        } else {
            console.warn("Occupancy chart canvas not found");
        }
    } catch (e) {
        console.error("Error initializing occupancy chart:", e);
        // Display error message on the page
        const errorMsg = document.createElement('div');
        errorMsg.className = 'text-red-500 p-4 text-center';
        errorMsg.innerText = 'Could not load occupancy chart. Please refresh the page.';
        
        const occupancyChartContainer = document.getElementById('occupancyChart')?.parentElement;
        if (occupancyChartContainer) {
            occupancyChartContainer.appendChild(errorMsg);
        }
    }
});
    </script>



<script>
    // Notification system
    document.addEventListener('DOMContentLoaded', function() {
        const notificationButton = document.getElementById('notification-button');
        const notificationDropdown = document.getElementById('notification-dropdown');
        const notificationContent = document.getElementById('notification-content');
        
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
        
        // Mark all as read button
        document.querySelector('a[href="?view_notifications=1"]').addEventListener('click', function(e) {
            e.preventDefault();
            markAllAsRead();
        });
        
        // Fetch notification counts every 5 minutes
        fetchNotificationCounts();
        setInterval(fetchNotificationCounts, 5 * 60 * 1000);
        
        // Function to fetch notification counts
        function fetchNotificationCounts() {
            fetch('business_desh.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_notification_counts'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update notification counter
                    const countElement = document.getElementById('notification-count');
                    countElement.textContent = data.counts.total;
                    
                    // Hide counter if no notifications
                    if (data.counts.total === 0) {
                        countElement.classList.add('hidden');
                    } else {
                        countElement.classList.remove('hidden');
                    }
                }
            })
            .catch(error => {
                console.error('Error fetching notification counts:', error);
            });
        }
        
        // Function to fetch notification items
        function fetchNotificationItems() {
    // Show loading state
    notificationContent.innerHTML = `
        <div class="p-6 text-center text-white/70">
            <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary mx-auto mb-3"></div>
            <p>Loading notifications...</p>
        </div>
    `;
    
    fetch('business_desh.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Cache-Control': 'no-cache, no-store, must-revalidate', // ক্যাশিং প্রতিরোধ করুন
            'Pragma': 'no-cache',
            'Expires': '0'
        },
        body: 'action=get_notification_items'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            renderNotifications(data);
        } else {
            notificationContent.innerHTML = `
                <div class="p-6 text-center text-white/70">
                    <p>Failed to load notifications. Please try again.</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error fetching notification items:', error);
        notificationContent.innerHTML = `
            <div class="p-6 text-center text-white/70">
                <p>An error occurred. Please try again.</p>
            </div>
        `;
    });
}
        
        // Function to render notifications
function renderNotifications(data) {
    const { bookings, verifications, garage_verifications, payments } = data;
    let content = '';
    
    // Display bookings
    if (bookings && bookings.length > 0) {
        content += `
            <div class="p-3 bg-gray-700">
                <h4 class="font-semibold text-white">New Bookings (${bookings.length})</h4>
            </div>
        `;
        
        bookings.forEach(booking => {
            content += `
                <div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                    <div>
                        <p class="font-medium text-white">${booking.parking_name || 'Booking'}</p>
                        <p class="text-sm text-white/70">
                            Booked by ${booking.customer_name || booking.customer_username || 'Customer'} for 
                            ${booking.booking_date_formatted || booking.booking_date} at 
                            ${booking.booking_time_formatted || booking.booking_time}
                        </p>
                    </div>
                    <div class="mt-2">
                        <a href="booking_details.php?id=${booking.id}" class="text-xs text-primary hover:underline">View Details</a>
                    </div>
                </div>
            `;
        });
    }
    
    // Display verifications
    if (verifications && verifications.length > 0) {
        content += `
            <div class="p-3 bg-gray-700">
                <h4 class="font-semibold text-white">Verification Updates (${verifications.length})</h4>
            </div>
        `;
        
        verifications.forEach(verification => {
            const statusClass = verification.is_verified ? 'text-green-400' : 'text-yellow-400';
            const statusText = verification.is_verified ? 'verified' : 'pending verification';
            
            content += `
                <div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                    <div>
                        <p class="font-medium text-white">Garage Owner Status</p>
                        <p class="text-sm text-white/70">
                            Your account is now 
                            <span class="${statusClass}">${statusText}</span>
                        </p>
                    </div>
                </div>
            `;
        });
    }

    // Display garage verifications
    if (garage_verifications && garage_verifications.length > 0) {
        content += `
            <div class="p-3 bg-gray-700">
                <h4 class="font-semibold text-white">Garage Verification Updates (${garage_verifications.length})</h4>
            </div>
        `;
        
        garage_verifications.forEach(verification => {
            const statusClass = verification.is_verified == 1 ? 'text-green-400' : 'text-yellow-400';
            const statusText = verification.is_verified == 1 ? 'verified' : 'pending verification';
            
            content += `
                <div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                    <div>
                        <p class="font-medium text-white">${verification.Parking_Space_Name || 'Garage'}</p>
                        <p class="text-sm text-white/70">
                            Your garage is now 
                            <span class="${statusClass}">${statusText}</span> - 
                            ${verification.updated_at_formatted || verification.updated_at || 'Recent'}
                        </p>
                    </div>
                </div>
            `;
        });
    }
    
    // Display payments
    if (payments && payments.length > 0) {
        content += `
            <div class="p-3 bg-gray-700">
                <h4 class="font-semibold text-white">Payment Updates (${payments.length})</h4>
            </div>
        `;
        
        payments.forEach(payment => {
            const statusClass = payment.payment_status === 'paid' ? 'text-green-400' : 'text-yellow-400';
            
            content += `
                <div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                    <div>
                        <p class="font-medium text-white">৳${payment.amount || '0'} - ${payment.parking_name || 'Parking'}</p>
                        <p class="text-sm text-white/70">
                            Payment 
                            <span class="${statusClass}">${payment.payment_status || 'pending'}</span> - 
                            ${payment.payment_date_formatted || payment.payment_date || 'Recent'}
                        </p>
                    </div>
                    <div class="mt-2">
                        <a href="payment_details.php?id=${payment.payment_id}" class="text-xs text-primary hover:underline">View Details</a>
                    </div>
                </div>
            `;
        });
    }
    
    // Show message if no notifications
    if ((!bookings || bookings.length === 0) && 
        (!verifications || verifications.length === 0) && 
        (!garage_verifications || garage_verifications.length === 0) && 
        (!payments || payments.length === 0)) {
        content = `
            <div class="p-6 text-center text-white/70">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-white/40 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                    <line x1="9" y1="9" x2="9.01" y2="9"></line>
                    <line x1="15" y1="9" x2="15.01" y2="9"></line>
                </svg>
                <p>No pending notifications!</p>
            </div>
        `;
    }
    
    // Update notification content
    notificationContent.innerHTML = content;
}
        
        // Function to mark all notifications as read
        // Current implementation may have this issue
function markAllAsRead() {
    // Show loading indicator
    const countElement = document.getElementById('notification-count');
    countElement.innerHTML = '<span class="animate-spin">⌛</span>';
    
    fetch('business_desh.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Cache-Control': 'no-cache, no-store, must-revalidate'
        },
        body: 'action=mark_all_read'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update UI immediately
            countElement.textContent = '';
            countElement.classList.add('hidden');
            
            // Update notification content
            notificationContent.innerHTML = `
                <div class="p-6 text-center text-white/70">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-white/40 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                        <line x1="9" y1="9" x2="9.01" y2="9"></line>
                        <line x1="15" y1="9" x2="15.01" y2="9"></line>
                    </svg>
                    <p>No pending notifications!</p>
                </div>
            `;
            
            // Close dropdown after a moment
            setTimeout(() => {
                notificationDropdown.classList.add('hidden');
            }, 1500);
            
            // Force page reload only after the AJAX call is complete
            // setTimeout(() => {
            //    window.location.reload(true); // Force a fresh reload
            // }, 2000);
        } else {
            console.error('Failed to mark notifications as read');
            alert('Could not update notification status. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error marking notifications as read:', error);
        alert('Error occurred. Please try again.');
    });
}
    });
</script>

<script>
// Parking Management Functions
function showParkingDetails(garageId) {
    fetch('business_desh.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_parking_details&garage_id=${garageId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            renderParkingDetails(data.data);
            document.getElementById('detailsModal').classList.remove('hidden');
        } else {
            alert('Failed to load parking details: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while loading parking details');
    });
}

function renderParkingDetails(data) {
    const availableSpaces = data.Parking_Capacity - (data.active_bookings || 0);
    const occupancyRate = ((data.active_bookings || 0) / data.Parking_Capacity * 100).toFixed(1);
    
    // Rating data
    const rating = data.average_rating ? parseFloat(data.average_rating) : 0;
    const totalRatings = data.total_ratings || 0;
    
    // Generate star rating HTML
    function generateStarRatingJS(rating) {
        const fullStars = Math.floor(rating);
        const hasHalfStar = (rating - fullStars) >= 0.5;
        const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);
        
        let stars = '';
        
        // Full stars
        for (let i = 0; i < fullStars; i++) {
            stars += '<svg class="w-4 h-4 text-yellow-400 fill-current inline mr-1" viewBox="0 0 20 20"><path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/></svg>';
        }
        
        // Half star
        if (hasHalfStar) {
            stars += '<svg class="w-4 h-4 text-yellow-400 inline mr-1" viewBox="0 0 20 20"><defs><linearGradient id="half-fill-' + Math.random() + '"><stop offset="50%" stop-color="currentColor"/><stop offset="50%" stop-color="transparent"/></linearGradient></defs><path fill="url(#half-fill-' + Math.random() + ')" stroke="currentColor" stroke-width="1" d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/></svg>';
        }
        
        // Empty stars
        for (let i = 0; i < emptyStars; i++) {
            stars += '<svg class="w-4 h-4 text-gray-400 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/></svg>';
        }
        
        return stars;
    }
    
    // Rating breakdown bars
    function generateRatingBreakdown() {
        if (totalRatings === 0) return '<p class="text-gray-400 text-sm text-center py-4">No ratings yet</p>';
        
        const ratings = [
            { stars: 5, count: data.five_star || 0 },
            { stars: 4, count: data.four_star || 0 },
            { stars: 3, count: data.three_star || 0 },
            { stars: 2, count: data.two_star || 0 },
            { stars: 1, count: data.one_star || 0 }
        ];
        
        let breakdown = '';
        ratings.forEach(r => {
            const percentage = totalRatings > 0 ? (r.count / totalRatings * 100) : 0;
            breakdown += `
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-sm text-gray-300 w-6 font-medium">${r.stars}★</span>
                    <div class="flex-1 bg-gray-600 rounded-full h-2.5">
                        <div class="bg-yellow-400 h-2.5 rounded-full transition-all duration-300" style="width: ${percentage}%"></div>
                    </div>
                    <span class="text-sm text-gray-400 w-8 text-right">${r.count}</span>
                </div>
            `;
        });
        
        return breakdown;
    }
    
    // Get rating quality text
    function getRatingQuality(rating) {
        if (rating >= 4.5) return { text: "Excellent", color: "text-green-400", icon: "⭐" };
        if (rating >= 4.0) return { text: "Very Good", color: "text-blue-400", icon: "👍" };
        if (rating >= 3.5) return { text: "Good", color: "text-yellow-400", icon: "👌" };
        if (rating >= 3.0) return { text: "Average", color: "text-orange-400", icon: "😐" };
        if (rating >= 2.0) return { text: "Below Average", color: "text-red-400", icon: "👎" };
        return { text: "Poor", color: "text-red-500", icon: "❌" };
    }
    
    const ratingQuality = getRatingQuality(rating);
    
    const content = `
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- LEFT COLUMN -->
            <div class="space-y-4">
                <div>
                    <h4 class="text-lg font-semibold text-white mb-2">${data.Parking_Space_Name}</h4>
                    <p class="text-gray-300">${data.Parking_Lot_Address || 'Address not specified'}</p>
                </div>
                
                <!-- Enhanced Rating Section -->
                ${rating > 0 ? `
                    <div class="bg-gradient-to-r from-gray-700 to-gray-600 p-5 rounded-xl border border-yellow-400/20">
                        <h5 class="font-semibold text-white mb-4 flex items-center">
                            <svg class="w-5 h-5 text-yellow-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                            </svg>
                            Customer Ratings
                        </h5>
                        
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="text-4xl font-bold text-white">${rating}</div>
                                <div>
                                    <div class="flex items-center mb-1">
                                        ${generateStarRatingJS(rating)}
                                    </div>
                                    <div class="text-sm ${ratingQuality.color} font-medium">
                                        ${ratingQuality.icon} ${ratingQuality.text}
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-lg font-semibold text-white">${totalRatings}</div>
                                <div class="text-xs text-gray-400">Review${totalRatings !== 1 ? 's' : ''}</div>
                            </div>
                        </div>
                        
                        <div class="space-y-2">
                            <h6 class="text-sm font-medium text-gray-300 mb-3">Rating Distribution</h6>
                            ${generateRatingBreakdown()}
                        </div>
                        
                        <!-- VIEW REVIEWS BUTTON -->
                        ${totalRatings > 0 ? `
                            <button onclick="loadAndShowReviews('${data.garage_id}', '${data.Parking_Space_Name.replace(/'/g, "\\'")}')" 
                                    class="w-full mt-4 bg-gradient-to-r from-yellow-500 to-yellow-600 hover:from-yellow-600 hover:to-yellow-700 text-white font-semibold py-3 px-4 rounded-lg transition-all duration-300 transform hover:scale-105 shadow-lg">
                                <svg class="w-4 h-4 inline mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M2 5a2 2 0 012-2h7a2 2 0 012 2v4a2 2 0 01-2 2H9l-3 3v-3H4a2 2 0 01-2-2V5z"/>
                                </svg>
                                Read All ${totalRatings} Review${totalRatings !== 1 ? 's' : ''}
                            </button>
                        ` : ''}
                    </div>
                ` : `
                    <div class="bg-gray-700 p-5 rounded-xl text-center border border-gray-600">
                        <h5 class="font-semibold text-white mb-3 flex items-center justify-center">
                            <svg class="w-5 h-5 text-gray-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                            </svg>
                            Customer Ratings
                        </h5>
                        <div class="flex items-center justify-center gap-2 mb-3">
                            ${generateStarRatingJS(0)}
                        </div>
                        <p class="text-gray-400 text-sm mb-2">No reviews yet</p>
                        <p class="text-gray-500 text-xs">Ratings will appear after customers review your parking space</p>
                    </div>
                `}
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-gray-700 p-3 rounded-lg">
                        <div class="text-2xl font-bold text-primary">${data.Parking_Capacity}</div>
                        <div class="text-sm text-gray-400">Total Spaces</div>
                    </div>
                    <div class="bg-gray-700 p-3 rounded-lg">
                        <div class="text-2xl font-bold text-green-400">${Math.max(0, availableSpaces)}</div>
                        <div class="text-sm text-gray-400">Available</div>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-gray-700 p-3 rounded-lg">
                        <div class="text-2xl font-bold text-blue-400">${data.active_bookings || 0}</div>
                        <div class="text-sm text-gray-400">Active Bookings</div>
                    </div>
                    <div class="bg-gray-700 p-3 rounded-lg">
                        <div class="text-2xl font-bold text-yellow-400">${occupancyRate}%</div>
                        <div class="text-sm text-gray-400">Occupancy Rate</div>
                    </div>
                </div>
            </div>
            
            <!-- RIGHT COLUMN -->
            <div class="space-y-4">
                <div class="bg-gray-700 p-4 rounded-lg">
                    <h5 class="font-semibold text-white mb-2">Pricing</h5>
                    <div class="text-2xl font-bold text-primary">৳${data.PriceperHour}</div>
                    <div class="text-sm text-gray-400">per hour</div>
                </div>
                
                <div class="bg-gray-700 p-4 rounded-lg">
                    <h5 class="font-semibold text-white mb-2">Status</h5>
                    ${data.is_verified == 1 ? 
                        '<span class="px-3 py-1 bg-green-500/20 text-green-400 text-sm rounded-full flex items-center w-fit"><svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>Verified</span>' : 
                        '<span class="px-3 py-1 bg-yellow-500/20 text-yellow-400 text-sm rounded-full flex items-center w-fit"><svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>Pending Verification</span>'
                    }
                </div>
                
                <div class="bg-gray-700 p-4 rounded-lg">
                    <h5 class="font-semibold text-white mb-2">Performance Statistics</h5>
                    <div class="text-sm text-gray-400 space-y-2">
                        <div class="flex justify-between">
                            <span>Total Bookings:</span>
                            <span class="text-white font-medium">${data.total_bookings || 0}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Customer Satisfaction:</span>
                            <span class="text-white font-medium">${rating > 0 ? `${rating}/5 stars` : 'No reviews yet'}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Reviews Count:</span>
                            <span class="text-white font-medium">${totalRatings} review${totalRatings !== 1 ? 's' : ''}</span>
                        </div>
                        ${data.Latitude && data.Longitude ? `
                            <div class="flex justify-between">
                                <span>Location:</span>
                                <span class="text-white font-medium text-xs">${data.Latitude}, ${data.Longitude}</span>
                            </div>
                        ` : ''}
                    </div>
                </div>
                
                ${rating > 0 ? `
                    <div class="bg-gradient-to-r from-blue-500/10 to-purple-500/10 p-4 rounded-lg border border-blue-500/20">
                        <h5 class="font-semibold text-white mb-2 flex items-center">
                            <svg class="w-4 h-4 text-blue-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                            </svg>
                            Quick Stats
                        </h5>
                        <div class="text-sm text-gray-300">
                            ${rating >= 4.0 ? 
                                '<div class="text-green-400">🎉 Your parking space is highly rated by customers!</div>' : 
                                rating >= 3.0 ? 
                                '<div class="text-yellow-400">💡 Consider improving services to boost ratings</div>' : 
                                '<div class="text-red-400">⚠️ Focus on customer service improvements needed</div>'
                            }
                        </div>
                    </div>
                ` : ''}
            </div>
        </div>
    `;
    
    document.getElementById('detailsContent').innerHTML = content;
}
// NEW FUNCTION: Load and display reviews
async function loadAndShowReviews(garageId, garageName) {
    try {
        // Show loading state
        const loadingHtml = `
            <div class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center">
                <div class="bg-gray-800 rounded-xl p-6 max-w-md w-full mx-4">
                    <div class="text-center">
                        <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-yellow-400 mx-auto mb-4"></div>
                        <p class="text-white">Loading reviews...</p>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', loadingHtml);
        
        // Fetch reviews
        const response = await fetch('business_desh.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=get_garage_reviews&garage_id=${garageId}`
        });
        
        const data = await response.json();
        
        // Remove loading state
        document.querySelector('.fixed.inset-0.bg-black\\/70').remove();
        
        if (data.success) {
            showReviewsModal(garageName, data.reviews, data.total_reviews);
        } else {
            alert('Failed to load reviews: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        // Remove loading state
        const loadingElement = document.querySelector('.fixed.inset-0.bg-black\\/70');
        if (loadingElement) loadingElement.remove();
        
        console.error('Error loading reviews:', error);
        alert('Error loading reviews. Please try again.');
    }
}

// NEW FUNCTION: Display the reviews modal
function showReviewsModal(garageName, reviews, totalReviews) {
    const modalHtml = `
        <div id="reviewsModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-gray-800 rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden border border-gray-600">
                <!-- Header -->
                <div class="bg-gradient-to-r from-yellow-500 to-yellow-600 p-6">
                    <div class="flex justify-between items-center">
                        <div>
                            <h3 class="text-2xl font-bold text-white mb-2">Customer Reviews</h3>
                            <p class="text-yellow-100">${garageName} • ${totalReviews} review${totalReviews !== 1 ? 's' : ''}</p>
                        </div>
                        <button onclick="closeReviewsModal()" class="text-white/80 hover:text-white text-2xl font-bold w-10 h-10 flex items-center justify-center rounded-full hover:bg-white/20 transition-all">
                            ×
                        </button>
                    </div>
                </div>
                
                <!-- Reviews Content -->
                <div class="p-6 max-h-[70vh] overflow-y-auto">
                    ${reviews.length > 0 ? `
                        <div class="space-y-6">
                            ${reviews.map(review => `
                                <div class="bg-gray-700/50 rounded-xl p-6 border border-gray-600/50 hover:border-yellow-400/30 transition-all hover:transform hover:scale-[1.02]">
                                    <!-- Review Header -->
                                    <div class="flex items-start justify-between mb-4">
                                        <div class="flex items-center gap-4">
                                            <div class="w-12 h-12 bg-gradient-to-br from-yellow-400 to-yellow-600 rounded-full flex items-center justify-center text-white font-bold text-lg">
                                                ${review.customer_name.charAt(0).toUpperCase()}
                                            </div>
                                            <div>
                                                <h4 class="text-white font-semibold text-lg">${review.customer_name}</h4>
                                                <div class="flex items-center gap-2">
                                                    <div class="flex">
                                                        ${Array.from({length: 5}, (_, i) => 
                                                            `<svg class="w-4 h-4 ${i < Math.floor(review.rating) ? 'text-yellow-400 fill-current' : 'text-gray-400'}" viewBox="0 0 20 20">
                                                                <path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/>
                                                            </svg>`
                                                        ).join('')}
                                                    </div>
                                                    <span class="text-yellow-400 font-semibold">${review.rating}/5</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-gray-400 text-sm">${review.created_at}</div>
                                            ${review.is_recent ? '<span class="inline-block bg-green-500/20 text-green-400 text-xs px-2 py-1 rounded-full mt-1">Recent</span>' : ''}
                                        </div>
                                    </div>
                                    
                                    <!-- Review Text -->
                                    <div class="mb-4">
                                        <p class="text-gray-200 leading-relaxed text-base">"${review.review_text}"</p>
                                    </div>
                                    
                                    <!-- Booking Details -->
                                    <div class="border-t border-gray-600 pt-4">
                                        <div class="flex items-center gap-6 text-sm text-gray-400 flex-wrap">
                                            <div class="flex items-center gap-2">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/>
                                                </svg>
                                                <span>Booked: ${review.booking_date}</span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                                                </svg>
                                                <span>${review.booking_time} • ${review.duration}h</span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                    <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3z"/>
                                                </svg>
                                                <span>${review.days_ago === 0 ? 'Today' : `${review.days_ago} day${review.days_ago !== 1 ? 's' : ''} ago`}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    ` : `
                        <div class="text-center py-12">
                            <div class="text-6xl mb-4">📝</div>
                            <h3 class="text-xl font-semibold text-white mb-2">No Reviews Yet</h3>
                            <p class="text-gray-400">Customer reviews will appear here after they rate your parking space.</p>
                        </div>
                    `}
                </div>
                
                <!-- Footer -->
                <div class="bg-gray-700/30 px-6 py-4 border-t border-gray-600">
                    <div class="flex justify-between items-center">
                        <div class="text-gray-400 text-sm">
                            ${reviews.length > 0 ? `Showing all ${reviews.length} review${reviews.length !== 1 ? 's' : ''} for this parking space` : 'No reviews available'}
                        </div>
                        <button onclick="closeReviewsModal()" class="bg-gray-600 hover:bg-gray-500 text-white px-6 py-2 rounded-lg transition-all">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Close modal when clicking outside
    document.getElementById('reviewsModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeReviewsModal();
        }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeReviewsModal();
        }
    });
}

// NEW FUNCTION: Close the reviews modal
function closeReviewsModal() {
    const modal = document.getElementById('reviewsModal');
    if (modal) {
        modal.remove();
    }
}
// REPLACE your openEditModal function with this DEBUG VERSION

// NEW: Clear form function
function clearEditForm() {
    // Clear all input fields
    const inputs = document.querySelectorAll('#editModal input[type="text"], #editModal input[type="number"], #editModal input[type="time"]');
    inputs.forEach(input => {
        input.value = '';
    });
    
    // Clear checkboxes
    const checkboxes = document.querySelectorAll('#editModal input[type="checkbox"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    
    // Clear hidden garage ID
    const garageIdInput = document.getElementById('editGarageId');
    if (garageIdInput) {
        garageIdInput.value = '';
    }
}

// NEW: Show loading state
function showLoadingState() {
    // Show loading in Basic Info tab
    const basicTab = document.getElementById('basicTab');
    if (basicTab) {
        basicTab.innerHTML = `
            <div class="text-center py-8">
                <div class="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-primary mx-auto mb-3"></div>
                <p class="text-white/70">Loading garage information...</p>
            </div>
        `;
    }
    
    // Show loading in Real-time Control tab
    const statusInfo = document.getElementById('statusInfo');
    if (statusInfo) {
        statusInfo.innerHTML = `
            <div class="text-center py-8">
                <div class="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-primary mx-auto mb-3"></div>
                <p class="text-white/70">Loading garage status...</p>
            </div>
        `;
    }
}

// FIXED: Separate function to fetch garage data
function fetchGarageData(garageId) {
    // First try to get comprehensive garage timing data
    fetch('business_desh.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_garage_timing&garage_id=${garageId}`
    })
    .then(response => response.json())
    .then(data => {
        console.log('=== DEBUG: Received garage timing data:', data);
        if (data.success && data.data) {
            // Success - populate with comprehensive data
            populateEditFormWithTiming(data.data);
            
            // IMPORTANT: Update the current status display immediately
            updateCurrentStatusDisplay(data.data.current_status || 'open');
        } else {
            console.log('Garage timing not found, falling back to basic details...');
            // Fallback to basic garage details
            return fetchBasicGarageData(garageId);
        }
    })
    .catch(error => {
        console.error('Error loading garage timing:', error);
        // Fallback to basic garage details
        fetchBasicGarageData(garageId);
    });
}

// Add this new function to update the status display
function updateCurrentStatusDisplay(currentStatus) {
    console.log('🔄 Updating status display to:', currentStatus);
    
    let statusDisplay = document.getElementById('currentStatusDisplay');
    
    // If status display doesn't exist, create it
    if (!statusDisplay) {
        createRealTimeControlSection();
        statusDisplay = document.getElementById('currentStatusDisplay');
    }
    
    if (statusDisplay) {
        const status = currentStatus.toLowerCase();
        let displayText, color, icon;
        
        switch(status) {
            case 'open':
                displayText = 'OPEN';
                color = '#22c55e';
                icon = '🟢';
                break;
            case 'closed':
                displayText = 'CLOSED';
                color = '#ef4444';
                icon = '🔴';
                break;
            case 'maintenance':
                displayText = 'MAINTENANCE';
                color = '#f59e0b';
                icon = '🟡';
                break;
            case 'emergency_closed':
                displayText = 'EMERGENCY CLOSED';
                color = '#dc2626';
                icon = '🚨';
                break;
            default:
                displayText = currentStatus.toUpperCase();
                color = '#6b7280';
                icon = '❓';
        }
        
        statusDisplay.innerHTML = `${icon} ${displayText}`;
        statusDisplay.style.color = color;
        
        console.log('✅ Status display updated successfully to:', displayText);
    } else {
        console.error('❌ Could not create or find status display element');
    }
}

// FIXED: Separate function for basic garage data
function fetchBasicGarageData(garageId) {
    fetch('business_desh.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_parking_details&garage_id=${garageId}`
    })
    .then(response => response.json())
    .then(data => {
        console.log('=== DEBUG: Received basic parking data:', data);
        if (data.success && data.data) {
            populateEditFormBasic(data.data);
        } else {
            showErrorState('Failed to load garage information');
        }
    })
    .catch(error => {
        console.error('Error loading basic garage data:', error);
        showErrorState('Network error occurred');
    });
}

// FIXED: Enhanced form population with timing data
function populateEditFormWithTiming(data) {
    console.log('=== DEBUG: Populating form with timing data:', data);
    
    // First recreate the basic form structure
    recreateBasicForm();
    
    // Wait for DOM to update
    setTimeout(() => {
        // Populate basic info
        populateBasicFields(data);
        
        // Populate timing info if available
        populateTimingFields(data);
        
        // Populate real-time control
        populateRealTimeControl(data);
        
        // Store data globally
        window.currentGarageData = data;
        
        console.log('=== SUCCESS: Form populated with timing data');
    }, 50);
}

// FIXED: Basic form population
function populateEditFormBasic(data) {
    console.log('=== DEBUG: Populating form with basic data:', data);
    
    // Recreate basic form structure
    recreateBasicForm();
    
    // Wait for DOM to update
    setTimeout(() => {
        // Map the basic data fields correctly
        const mappedData = {
            garage_id: data.garage_id,
            name: data.Parking_Space_Name || data.name,
            capacity: data.Parking_Capacity || data.capacity,
            price: data.PriceperHour || data.price
        };
        
        populateBasicFields(mappedData);
        
        // Show basic status info
        const statusInfo = document.getElementById('statusInfo');
        if (statusInfo) {
            statusInfo.innerHTML = `
                <div class="space-y-2 text-center py-4">
                    <p class="text-white/70">Basic garage information loaded.</p>
                    <p class="text-white/60 text-sm">Complete timing setup in the Operating Hours tab.</p>
                </div>
            `;
        }
        
        console.log('=== SUCCESS: Form populated with basic data');
    }, 50);
}

// NEW: Recreate basic form structure
function recreateBasicForm() {
    const basicTab = document.getElementById('basicTab');
    if (basicTab) {
        basicTab.innerHTML = `
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-white mb-2">Parking Space Name</label>
                    <input type="text" id="editParkingName" name="parking_name" 
                           class="w-full p-3 bg-gray-700 text-white border border-gray-600 rounded-lg"
                           required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-white mb-2">Parking Capacity</label>
                    <input type="number" id="editParkingCapacity" name="parking_capacity" min="1" 
                           class="w-full p-3 bg-gray-700 text-white border border-gray-600 rounded-lg"
                           required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-white mb-2">Price per Hour (৳)</label>
                    <input type="number" id="editPricePerHour" name="price_per_hour" min="0" step="0.01" 
                           class="w-full p-3 bg-gray-700 text-white border border-gray-600 rounded-lg"
                           required>
                </div>
            </div>
        `;
    }
}

// NEW: Populate basic fields
function populateBasicFields(data) {
    // Garage ID (hidden field)
    const garageIdInput = document.getElementById('editGarageId');
    if (garageIdInput && data.garage_id) {
        garageIdInput.value = data.garage_id;
        console.log('Set garage ID:', data.garage_id);
    }
    
    // Parking Name
    const nameInput = document.getElementById('editParkingName');
    if (nameInput && data.name) {
        nameInput.value = data.name;
        console.log('Set parking name:', data.name);
    }
    
    // Parking Capacity
    const capacityInput = document.getElementById('editParkingCapacity');
    if (capacityInput && data.capacity) {
        capacityInput.value = data.capacity;
        console.log('Set capacity:', data.capacity);
    }
    
    // Price per Hour
    const priceInput = document.getElementById('editPricePerHour');
    if (priceInput && data.price) {
        priceInput.value = data.price;
        console.log('Set price:', data.price);
    }
    
}

function populateTimingFields(data) {
    console.log('=== DEBUG: Populating timing fields with data:', data);
    
    // 24/7 toggle
    const is24_7Element = document.getElementById('is24_7');
    if (is24_7Element) {
        is24_7Element.checked = data.is_24_7 == 1;
        console.log('Set 24/7 status:', data.is_24_7);
    }
    
    // Opening time
    const openingTimeElement = document.getElementById('openingTime');
    if (openingTimeElement && data.opening_time) {
        openingTimeElement.value = data.opening_time;
        console.log('Set opening time:', data.opening_time);
    }
    
    // Closing time
    const closingTimeElement = document.getElementById('closingTime');
    if (closingTimeElement && data.closing_time) {
        closingTimeElement.value = data.closing_time;
        console.log('Set closing time:', data.closing_time);
    }
    
    // IMPROVED: Operating days population with delay
    if (data.operating_days) {
        console.log('Raw operating_days from data:', data.operating_days);
        
        // Add a delay to ensure DOM is ready
        setTimeout(() => {
            let operatingDays = [];
            
            if (Array.isArray(data.operating_days)) {
                operatingDays = data.operating_days.map(day => day.toLowerCase());
            } else if (typeof data.operating_days === 'string') {
                operatingDays = data.operating_days.split(',').map(day => day.trim().toLowerCase());
            }
            
            console.log('Processed operating days:', operatingDays);
            
            // Get all checkboxes
            const dayCheckboxes = document.querySelectorAll('#editModal input[name="operating_days[]"]');
            console.log(`Found ${dayCheckboxes.length} day checkboxes`);
            
            if (dayCheckboxes.length === 0) {
                console.error('No operating day checkboxes found - ensuring they exist');
                ensureOperatingDaysExist();
                
                // Try again after ensuring they exist
                setTimeout(() => {
                    const retryCheckboxes = document.querySelectorAll('#editModal input[name="operating_days[]"]');
                    setOperatingDayCheckboxes(retryCheckboxes, operatingDays);
                }, 200);
            } else {
                setOperatingDayCheckboxes(dayCheckboxes, operatingDays);
            }
        }, 100);
    } else {
        console.log('No operating_days data found - defaulting to all days');
        setTimeout(() => {
            const dayCheckboxes = document.querySelectorAll('#editModal input[name="operating_days[]"]');
            dayCheckboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
        }, 100);
    }
    
    // Toggle operating hours visibility
    setTimeout(() => {
        toggleOperatingHours();
    }, 150);
}
// Add this function to fetch timing data
function fetchAndPopulateTimingData(garageId) {
    fetch('business_desh.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=get_garage_timing&garage_id=${garageId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data) {
            const timingData = data.data;
            
            // Set 24/7 toggle
            const is24_7Element = document.getElementById('is24_7');
            if (is24_7Element) {
                is24_7Element.checked = timingData.is_24_7 == 1;
            }
            
            // Set opening/closing times
            const openingTimeElement = document.getElementById('openingTime');
            if (openingTimeElement && timingData.opening_time) {
                openingTimeElement.value = timingData.opening_time;
            }
            
            const closingTimeElement = document.getElementById('closingTime');
            if (closingTimeElement && timingData.closing_time) {
                closingTimeElement.value = timingData.closing_time;
            }
            
            // Set operating days
            setTimeout(() => {
                populateOperatingDaysFromDB(timingData.operating_days);
                toggleOperatingHours(); // Apply 24/7 toggle
            }, 100);
            
            console.log('⏰ Timing data populated successfully!');
        } else {
            console.log('⚠️ No timing data found, using defaults');
            // Set all days as default
            setTimeout(() => {
                const checkboxes = document.querySelectorAll('input[name="operating_days[]"]');
                checkboxes.forEach(checkbox => checkbox.checked = true);
            }, 100);
        }
    })
    .catch(error => {
        console.error('❌ Error fetching timing data:', error);
    });
}

function populateOperatingDaysFromDB(operatingDaysData) {
    console.log('📅 Populating operating days from DB:', operatingDaysData);
    
    const checkboxes = document.querySelectorAll('input[name="operating_days[]"]');
    
    // Clear all first
    checkboxes.forEach(checkbox => checkbox.checked = false);
    
    if (operatingDaysData) {
        let operatingDays = [];
        
        if (Array.isArray(operatingDaysData)) {
            operatingDays = operatingDaysData.map(day => day.toLowerCase());
        } else if (typeof operatingDaysData === 'string') {
            operatingDays = operatingDaysData.split(',').map(day => day.trim().toLowerCase());
        }
        
        console.log('📅 Processed operating days:', operatingDays);
        
        // Check appropriate boxes
        checkboxes.forEach(checkbox => {
            const dayValue = checkbox.value.toLowerCase();
            if (operatingDays.includes(dayValue)) {
                checkbox.checked = true;
                console.log(`✅ Checked ${dayValue}`);
            }
        });
    } else {
        // Default to all days if no data
        checkboxes.forEach(checkbox => checkbox.checked = true);
    }
}
// Helper function to set operating day checkboxes
function setOperatingDayCheckboxes(checkboxes, operatingDays) {
    // Clear all first
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    
    // Check the appropriate ones
    checkboxes.forEach(checkbox => {
        const dayValue = checkbox.value.toLowerCase();
        if (operatingDays.includes(dayValue)) {
            checkbox.checked = true;
            console.log(`✅ Checked ${dayValue}`);
        } else {
            console.log(`❌ Unchecked ${dayValue}`);
        }
    });
    
    console.log('Successfully set operating days:', operatingDays);
}
function ensureOperatingDaysInTiming() {
    const timingTab = document.getElementById('timingTab');
    if (!timingTab) return;
    
    // Check if operating days already exist
    if (timingTab.querySelector('input[name="operating_days[]"]')) {
        console.log('Operating days already exist in timing tab');
        return;
    }
    
    console.log('Adding operating days to timing tab...');
    
    // Find where to insert (after operating hours)
    const operatingHours = document.getElementById('operatingHours');
    if (operatingHours) {
        const operatingDaysHTML = `
            <div id="operatingDaysSection" class="mt-6">
                <label class="block text-sm font-medium text-white mb-3">📅 Operating Days</label>
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
                    ${['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].map(day => `
                        <label class="flex items-center space-x-2 cursor-pointer bg-gray-700/50 p-3 rounded-lg hover:bg-gray-600">
                            <input type="checkbox" name="operating_days[]" value="${day}" class="w-4 h-4" checked>
                            <span class="text-white text-sm capitalize">${day}</span>
                        </label>
                    `).join('')}
                </div>
            </div>
        `;
        
        operatingHours.insertAdjacentHTML('afterend', operatingDaysHTML);
        console.log('✅ Operating days added to timing tab');
    }
}
function debugOperatingDays() {
    console.log('=== DEBUGGING OPERATING DAYS ===');
    
    const checkboxes = document.querySelectorAll('#editModal input[name="operating_days[]"]');
    console.log(`Found ${checkboxes.length} operating day checkboxes:`);
    
    checkboxes.forEach(checkbox => {
        console.log(`- ${checkbox.value}: ${checkbox.checked ? 'CHECKED' : 'unchecked'}`);
    });
    
    if (window.currentGarageData && window.currentGarageData.operating_days) {
        console.log('Current garage operating days:', window.currentGarageData.operating_days);
    }
    
    console.log('=== END DEBUG ===');
}

// Add to window for testing
window.debugOperatingDays = debugOperatingDays;
function loadParkingSpaceForEdit(garageId) {
    console.log('Loading parking space for edit:', garageId);
    
    // Show loading state
    showEditModal();
    
    // Fetch parking space data including operating schedule
    fetch('business_desh.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_parking_details&garage_id=${encodeURIComponent(garageId)}`
    })
    .then(response => response.json())
    .then(data => {
        console.log('=== DEBUG: Received parking space data:', data);
        
        if (data.success) {
            // Populate all tabs with the data
            populateBasicFields(data.data);
            populateTimingFields(data.data); // This should now work properly
            populateRealTimeControl(data.data);
            
            // Store data globally
            window.currentGarageData = data.data;
        } else {
            console.error('Failed to load parking space data:', data.message);
            alert('Failed to load parking space data: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error loading parking space data:', error);
        alert('Error loading parking space data. Please try again.');
    });
}
// NEW: Show error state
function showErrorState(message) {
    const basicTab = document.getElementById('basicTab');
    if (basicTab) {
        basicTab.innerHTML = `
            <div class="text-center py-8">
                <div class="text-red-500 text-6xl mb-4">⚠️</div>
                <h3 class="text-xl font-semibold text-white mb-2">Error Loading Data</h3>
                <p class="text-gray-400">${message}</p>
                <button onclick="closeEditModal()" 
                        class="mt-4 bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                    Close
                </button>
            </div>
        `;
    }
}

// Function to populate real-time control status
function populateRealTimeControl(data) {
    console.log('🎛️ Populating real-time control with:', data);
    console.log('🔍 Current status value:', data.current_status);
    console.log('🔍 Data keys:', Object.keys(data));
    
    // First check if elements exist, if not, set up the status display manually
    let statusDisplay = document.getElementById('currentStatusDisplay');
    let activeBookingsDisplay = document.getElementById('activeBookingsDisplay');
    
    // If the status display doesn't exist, create the real-time control section
    if (!statusDisplay) {
        console.warn('⚠️ Status display not found, creating real-time control section...');
        createRealTimeControlSection();
        statusDisplay = document.getElementById('currentStatusDisplay');
        activeBookingsDisplay = document.getElementById('activeBookingsDisplay');
    }
    
    // Update status display
    if (statusDisplay) {
        const currentStatus = data.current_status || 'open';
        const status = currentStatus.toUpperCase();
        let statusColor = '#22c55e'; // green for open
        let statusIcon = '🟢';
        
        switch(currentStatus.toLowerCase()) {
            case 'closed':
                statusColor = '#ef4444';
                statusIcon = '🔴';
                break;
            case 'maintenance':
                statusColor = '#f59e0b';
                statusIcon = '🟡';
                break;
            case 'emergency_closed':
                statusColor = '#dc2626';
                statusIcon = '🚨';
                break;
            case 'open':
            default:
                statusColor = '#22c55e';
                statusIcon = '🟢';
                break;
        }
        
        statusDisplay.innerHTML = `${statusIcon} ${status}`;
        statusDisplay.style.color = statusColor;
        
        console.log(`✅ Status display updated to: ${statusIcon} ${status}`);
    } else {
        console.error('❌ Could not find or create currentStatusDisplay element!');
    }
    
    // Update active bookings display
    if (activeBookingsDisplay) {
        const bookingCount = data.active_bookings_count || 0;
        activeBookingsDisplay.textContent = bookingCount;
        console.log(`✅ Active bookings updated to: ${bookingCount}`);
    }
    
    console.log('✅ Real-time control populated successfully');
}
// FIXED VERSION: Replace the existing populateRealTimeControl function in business_desh.php

function populateRealTimeControl(data) {
    console.log('🎛️ Populating real-time control with:', data);
    console.log('🔍 Current status value:', data.current_status);
    console.log('🔍 Data keys:', Object.keys(data));
    
    // First check if elements exist, if not, set up the status display manually
    let statusDisplay = document.getElementById('currentStatusDisplay');
    let activeBookingsDisplay = document.getElementById('activeBookingsDisplay');
    
    // If the status display doesn't exist, create the real-time control section
    if (!statusDisplay) {
        console.warn('⚠️ Status display not found, creating real-time control section...');
        createRealTimeControlSection();
        statusDisplay = document.getElementById('currentStatusDisplay');
        activeBookingsDisplay = document.getElementById('activeBookingsDisplay');
    }
    
    // Update status display
    if (statusDisplay) {
        const currentStatus = data.current_status || 'open';
        const status = currentStatus.toUpperCase();
        let statusColor = '#22c55e'; // green for open
        let statusIcon = '🟢';
        
        switch(currentStatus.toLowerCase()) {
            case 'closed':
                statusColor = '#ef4444';
                statusIcon = '🔴';
                break;
            case 'maintenance':
                statusColor = '#f59e0b';
                statusIcon = '🟡';
                break;
            case 'emergency_closed':
                statusColor = '#dc2626';
                statusIcon = '🚨';
                break;
            case 'open':
            default:
                statusColor = '#22c55e';
                statusIcon = '🟢';
                break;
        }
        
        statusDisplay.innerHTML = `${statusIcon} ${status}`;
        statusDisplay.style.color = statusColor;
        
        console.log(`✅ Status display updated to: ${statusIcon} ${status}`);
    } else {
        console.error('❌ Could not find or create currentStatusDisplay element!');
    }
    
    // Update active bookings display
    if (activeBookingsDisplay) {
        const bookingCount = data.active_bookings_count || 0;
        activeBookingsDisplay.textContent = bookingCount;
        console.log(`✅ Active bookings updated to: ${bookingCount}`);
    }
    
    console.log('✅ Real-time control populated successfully');
}

// Helper function to create the real-time control section if it doesn't exist
function createRealTimeControlSection() {
    console.log('🔧 Creating real-time control section...');
    
    const realTimeTab = document.getElementById('realTimeTab');
    if (realTimeTab && !document.getElementById('currentStatusDisplay')) {
        realTimeTab.innerHTML = `
            <div class="space-y-6">
                <!-- Real-time Control Panel Header -->
                <div class="bg-gradient-to-r from-primary/20 to-blue-600/20 rounded-lg p-4 border border-primary/30">
                    <h3 class="text-lg font-semibold text-white mb-2">🎛️ Real-time Control Panel</h3>
                    <p class="text-gray-300 text-sm">Monitor and control your parking space status in real-time</p>
                </div>
                
                <!-- Current Status Display -->
                <div id="statusInfo" class="bg-gray-700/50 rounded-lg p-4 border-l-4 border-primary">
                    <div class="flex justify-between items-center">
                        <div>
                            <h4 class="text-white font-medium mb-1">📊 Current Status</h4>
                            <p class="text-lg font-semibold">
                                Status: <span id="currentStatusDisplay" class="text-gray-400">Loading...</span>
                            </p>
                        </div>
                        <div class="text-right">
                            <h4 class="text-white font-medium mb-1">📅 Active Bookings</h4>
                            <p class="text-2xl font-bold text-primary">
                                <span id="activeBookingsDisplay">0</span>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Control Buttons -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <button type="button" onclick="performGarageControl('open')" 
                            class="control-btn bg-green-600 hover:bg-green-700 text-white px-6 py-4 rounded-lg font-medium transition-all">
                        🟢 Open
                    </button>
                    <button type="button" onclick="performGarageControl('close')" 
                            class="control-btn bg-red-600 hover:bg-red-700 text-white px-6 py-4 rounded-lg font-medium transition-all">
                        🔴 Close
                    </button>
                    <button type="button" onclick="performGarageControl('maintenance')" 
                            class="control-btn bg-orange-600 hover:bg-orange-700 text-white px-6 py-4 rounded-lg font-medium transition-all">
                        🟡 Maintenance
                    </button>
                </div>
                
                <!-- Status Change Reason -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">Reason for Status Change</label>
                    <input type="text" id="statusChangeReason" 
                           placeholder="Enter reason (optional)" 
                           class="w-full p-3 bg-gray-700 text-white border border-gray-600 rounded-lg">
                </div>
            </div>
        `;
        console.log('✅ Real-time control section created');
    }
}
function loadParkingSpaceForEdit(garageId) {
    console.log('Loading parking space for edit:', garageId);
    
    // Show loading state
    showEditModal();
    
    // Fetch parking space data including operating schedule
    fetch('business_desh.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_parking_details&garage_id=${encodeURIComponent(garageId)}`
    })
    .then(response => response.json())
    .then(data => {
        console.log('=== DEBUG: Received parking space data:', data);
        
        if (data.success) {
            // Populate all tabs with the data
            populateBasicFields(data.data);
            populateTimingFields(data.data);
            
            // IMPORTANT: Always load real-time status separately to ensure it's fresh
            loadGarageRealTimeStatus(garageId);
            
            // Store data globally
            window.currentGarageData = data.data;
        } else {
            console.error('Failed to load parking space data:', data.message);
            alert('Failed to load parking space data: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error loading parking space data:', error);
        alert('Error loading parking space data. Please try again.');
    });
}

function getStatusColorClass(status) {
    switch(status) {
        case 'open': return 'bg-green-600/80';
        case 'closed': return 'bg-red-600/80';
        case 'maintenance': return 'bg-yellow-600/80';
        case 'emergency_closed': return 'bg-red-800/80';
        default: return 'bg-gray-600/80';
    }
}

// NEW FUNCTION: Update control buttons based on status
function updateControlButtons(data) {
    const openButton = document.querySelector('button[onclick="controlGarage(\'open\')"]');
    const closeButton = document.querySelector('button[onclick="controlGarage(\'close\')"]');
    const maintenanceButton = document.querySelector('button[onclick="controlGarage(\'maintenance\')"]');
    
    if (!openButton || !closeButton || !maintenanceButton) {
        console.log('Control buttons not found - they may not be in the DOM yet');
        return;
    }
    
    const currentStatus = data.current_status || 'open';
    
    // Reset all buttons
    [openButton, closeButton, maintenanceButton].forEach(btn => {
        btn.classList.remove('opacity-50', 'cursor-not-allowed', 'ring-2', 'ring-white');
        btn.disabled = false;
    });
    
    // Highlight and disable current status button
    switch(currentStatus) {
        case 'open':
            openButton.classList.add('opacity-75', 'cursor-not-allowed', 'ring-2', 'ring-white');
            openButton.disabled = true;
            break;
        case 'closed':
            closeButton.classList.add('opacity-75', 'cursor-not-allowed', 'ring-2', 'ring-white');
            closeButton.disabled = true;
            break;
        case 'maintenance':
            maintenanceButton.classList.add('opacity-75', 'cursor-not-allowed', 'ring-2', 'ring-white');
            maintenanceButton.disabled = true;
            break;
    }
    
    console.log('=== DEBUG: Updated control buttons for status:', currentStatus);
}

// Status display update function
function updateStatusDisplay(data) {
    const statusInfo = document.getElementById('statusInfo');
    if (statusInfo) {
        statusInfo.innerHTML = `
            <div class="space-y-2">
                <div>Status: <span class="font-semibold">${data.current_status ? data.current_status.toUpperCase() : 'OPEN'}</span></div>
                <div>Active Bookings: <span class="font-semibold">${data.active_bookings_count || 0}</span></div>
                <div>Manual Override: <span class="font-semibold">${data.is_manual_override ? 'Yes' : 'No'}</span></div>
                ${data.can_close_after ? `<div>Can close after: <span class="font-semibold">${new Date(data.can_close_after).toLocaleString()}</span></div>` : ''}
            </div>
        `;
    }
}

// Tab switching function
function switchTab(tabName) {
    console.log('=== DEBUG: Switching to tab:', tabName);
    
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.add('hidden');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active', 'border-primary', 'text-white');
        btn.classList.add('border-transparent', 'text-gray-400');
    });
    
    // Show selected tab with error handling
    const targetTab = document.getElementById(tabName + 'Tab');
    if (targetTab) {
        targetTab.classList.remove('hidden');
        console.log('=== DEBUG: Successfully showing tab:', tabName + 'Tab');
    } else {
        console.error('=== ERROR: Tab not found:', tabName + 'Tab');
        console.log('Available tabs:', document.querySelectorAll('[id$="Tab"]'));
    }
    
    // Update active tab button
    const activeButton = event ? event.target : document.querySelector(`button[onclick="switchTab('${tabName}')"]`);
    if (activeButton) {
        activeButton.classList.add('active', 'border-primary', 'text-white');
        activeButton.classList.remove('border-transparent', 'text-gray-400');
    }
    
    // If switching to control tab and we have data, refresh it
    if (tabName === 'control' && window.currentGarageData) {
        console.log('=== DEBUG: Refreshing Real-time Control tab with stored data');
        populateRealTimeControlTab(window.currentGarageData);
    }
}

// Operating hours toggle
function toggleOperatingHours() {
    const is24_7Element = document.getElementById('is24_7');
    const operatingHours = document.getElementById('operatingHours');
    
    if (is24_7Element && operatingHours) {
        const is24_7 = is24_7Element.checked;
        operatingHours.style.display = is24_7 ? 'none' : 'grid';
    }
}

// Garage control function
function controlGarage(action) {
    console.log('🎛️ Control garage called with action:', action);
    
    const garageId = document.getElementById('editGarageId')?.value;
    if (!garageId) {
        alert('❌ Error: No garage selected');
        console.error('No garage ID found');
        return;
    }
    
    console.log('🏠 Garage ID:', garageId);
    
    // Get reason and force close values
    const reasonElement = document.getElementById('changeReason');
    const forceCloseElement = document.getElementById('forceClose');
    
    const reason = reasonElement ? reasonElement.value || 'Manual control' : 'Manual control';
    const forceClose = forceCloseElement ? forceCloseElement.checked : false;
    
    console.log('📝 Reason:', reason);
    console.log('🔒 Force close:', forceClose);
    
    // Handle maintenance duration
    let duration = 1;
    if (action === 'maintenance') {
        const durationInput = prompt('How many hours for maintenance?', '2');
        if (!durationInput || durationInput <= 0) {
            console.log('❌ Maintenance cancelled - invalid duration');
            return;
        }
        duration = parseInt(durationInput);
    }
    
    // Disable the clicked button to prevent double clicks
    const clickedButton = event ? event.target : null;
    const originalText = clickedButton ? clickedButton.textContent : '';
    
    if (clickedButton) {
        clickedButton.disabled = true;
        clickedButton.textContent = 'Processing...';
        console.log('🔒 Button disabled for processing');
    }
    
    // Prepare form data
    const formData = new FormData();
    formData.append('action', 'control_garage_status');
    formData.append('garage_id', garageId);
    formData.append('task_to_perform', action); // IMPORTANT: Use correct parameter name
    formData.append('reason', reason);
    formData.append('force_close', forceClose ? '1' : '0');
    
    if (action === 'maintenance') {
        formData.append('duration', duration);
    }
    
    // Log what we're sending
    console.log('📤 Sending request:', {
        action: 'control_garage_status',
        garage_id: garageId,
        task_to_perform: action,
        reason: reason,
        force_close: forceClose ? '1' : '0',
        duration: action === 'maintenance' ? duration : 'N/A'
    });
    
    // Send AJAX request
    fetch('business_desh.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('📥 Response received:', response.status);
        return response.json();
    })
    .then(data => {
        console.log('📋 Response data:', data);
        
        if (data.success) {
            alert('✅ ' + (data.message || 'Status updated successfully!'));
            console.log('✅ Success:', data.message);
            
            // Refresh the garage data to show updated status
            if (typeof fetchGarageData === 'function') {
                fetchGarageData(garageId);
            } else if (typeof openEditModal === 'function') {
                openEditModal(garageId);
            } else {
                // Fallback: reload the page
                console.log('🔄 Reloading page to reflect changes');
                location.reload();
            }
        } else {
            alert('❌ Error: ' + (data.message || 'Unknown error occurred'));
            console.error('❌ Error:', data.message);
        }
    })
    .catch(error => {
        console.error('🚨 Network error:', error);
        alert('🚨 Network error occurred. Please check your connection and try again.');
    })
    .finally(() => {
        // Re-enable the button
        if (clickedButton) {
            clickedButton.disabled = false;
            clickedButton.textContent = originalText;
            console.log('🔓 Button re-enabled');
        }
    });
}

// Alternative function name for compatibility
function setGarageStatus(status) {
    console.log('🔄 setGarageStatus called, redirecting to controlGarage');
    controlGarage(status);
}
function closeDetailsModal() {
    document.getElementById('detailsModal').classList.add('hidden');
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
    const editForm = document.getElementById('editForm') || document.getElementById('editTimingForm');
    if (editForm) {
        editForm.reset();
    }
}
// Enhanced DOMContentLoaded event handler
document.addEventListener('DOMContentLoaded', function() {
    // Handle both old and new form IDs
    const editForm = document.getElementById('editForm') || document.getElementById('editTimingForm');
    
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            // Determine which action to use based on form content
            const hasTimingFields = document.getElementById('openingTime') || document.getElementById('is24_7');
            const action = hasTimingFields ? 'update_garage_timing' : 'update_parking_space';
            
            formData.append('action', action);
            
            const submitButton = this.querySelector('button[type="submit"]');
            const originalText = submitButton.textContent;
            submitButton.textContent = 'Saving...';
            submitButton.disabled = true;
            
            fetch('business_desh.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Parking space updated successfully!');
                    closeEditModal();
                    // Refresh the parking spaces display
                    location.reload();
                } else {
                    alert('Failed to update parking space: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating parking space');
            })
            .finally(() => {
                submitButton.textContent = originalText;
                submitButton.disabled = false;
            });
        });
    }
    
    // Add event listener for 24/7 toggle (if it exists)
    const is24_7Element = document.getElementById('is24_7');
    if (is24_7Element) {
        is24_7Element.addEventListener('change', toggleOperatingHours);
    }
    
    // Close modals when clicking outside
    const detailsModal = document.getElementById('detailsModal');
    if (detailsModal) {
        detailsModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeDetailsModal();
            }
        });
    }

    const editModal = document.getElementById('editModal');
    if (editModal) {
        editModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
    }

    // Enhanced CSS with timing control styles
    const style = document.createElement('style');
    style.textContent = `
        /* Tab Styles */
        .tab-btn.active {
            border-bottom-color: #f39c12 !important;
            color: white !important;
        }
        
        .tab-content.hidden {
            display: none !important;
        }
        
        .tab-btn {
            transition: all 0.3s ease;
        }
        
        .tab-btn:hover {
            color: white !important;
            border-bottom-color: rgba(243, 156, 18, 0.5) !important;
        }

        /* Reviews Modal Animations */
        #reviewsModal {
            animation: fadeIn 0.3s ease-out;
        }

        #reviewsModal > div {
            animation: slideInUp 0.3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Custom scrollbar for reviews */
        #reviewsModal .overflow-y-auto::-webkit-scrollbar {
            width: 8px;
        }

        #reviewsModal .overflow-y-auto::-webkit-scrollbar-track {
            background: rgba(75, 85, 99, 0.3);
            border-radius: 4px;
        }

        #reviewsModal .overflow-y-auto::-webkit-scrollbar-thumb {
            background: rgba(156, 163, 175, 0.5);
            border-radius: 4px;
        }

        #reviewsModal .overflow-y-auto::-webkit-scrollbar-thumb:hover {
            background: rgba(156, 163, 175, 0.7);
        }

        /* Review card hover effects */
        .bg-gray-700\\/50:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        /* Rating stars glow effect */
        .text-yellow-400.fill-current {
            filter: drop-shadow(0 0 2px rgba(251, 191, 36, 0.5));
        }

        /* Original rating animations */
        .rating-bar {
            transition: width 0.5s ease-in-out;
        }
        
        .rating-star {
            transition: transform 0.2s ease;
        }
        
        .rating-star:hover {
            transform: scale(1.1);
        }
        
        .rating-container {
            animation: fadeInUp 0.6s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .rating-glow {
            box-shadow: 0 0 20px rgba(255, 215, 0, 0.3);
        }

        /* Loading spinner animation */
        .animate-spin {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Enhanced hover effects for review cards */
        .bg-gray-700\\/50 {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .bg-gray-700\\/50:hover {
            background: rgba(55, 65, 81, 0.7);
            border-color: rgba(251, 191, 36, 0.4);
            transform: translateY(-3px) scale(1.01);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
        }

        /* Button hover effects */
        .bg-gradient-to-r.from-yellow-500.to-yellow-600:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(251, 191, 36, 0.4);
        }

        /* Smooth transitions for modal elements */
        .rounded-xl {
            transition: all 0.3s ease;
        }

        /* Focus states for accessibility */
        button:focus-visible {
            outline: 2px solid #f59e0b;
            outline-offset: 2px;
        }

        /* Control button styles */
        .control-btn {
            transition: all 0.3s ease;
        }
        
        .control-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }

        /* Status display styles */
        #currentStatusDisplay {
            border-left: 4px solid #f39c12;
        }

        /* Form input enhancements */
        .form-input {
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            transform: scale(1.02);
            box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.3);
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            #reviewsModal .max-w-4xl {
                max-width: 95vw;
            }
            
            #reviewsModal .p-6 {
                padding: 1rem;
            }
            
            .flex.items-center.gap-6 {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }
            
            .grid.grid-cols-1.md\\:grid-cols-3 {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .tab-btn {
                font-size: 0.875rem;
                padding: 0.5rem 1rem;
            }
        }
    `;
    document.head.appendChild(style);
});

// Alternative function for handling edit form (enhanced version)
function saveEditForm() {
    const form = document.getElementById('editForm') || document.getElementById('editTimingForm');
    const formData = new FormData(form);
    
    // Determine action based on form content
    const hasTimingFields = document.getElementById('openingTime') || document.getElementById('is24_7');
    const action = hasTimingFields ? 'update_garage_timing' : 'update_parking_space';
    
    formData.append('action', action);
    
    const submitButton = document.querySelector('#editModal button[onclick="saveEditForm()"]') || 
                        form.querySelector('button[type="submit"]');
    
    if (submitButton) {
        const originalText = submitButton.textContent;
        submitButton.textContent = 'Saving...';
        submitButton.disabled = true;
        
        fetch('business_desh.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Parking space updated successfully!');
                closeEditModal();
                location.reload();
            } else {
                alert('Failed to update: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred');
        })
        .finally(() => {
            submitButton.textContent = originalText;
            submitButton.disabled = false;
        });
    }
}

// ENHANCED: Debug function to check form state
function debugFormState() {
    console.log('=== FORM DEBUG ===');
    
    // Check if modal exists
    const modal = document.getElementById('editModal');
    console.log('Modal exists:', !!modal);
    console.log('Modal visible:', modal && !modal.classList.contains('hidden'));
    
    // Check basic form fields
    const fields = ['editGarageId', 'editParkingName', 'editParkingCapacity', 'editPricePerHour'];
    fields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        console.log(`${fieldId}:`, {
            exists: !!field,
            value: field ? field.value : 'N/A',
            disabled: field ? field.disabled : 'N/A'
        });
    });
    
    // Check tabs
    const tabs = ['basicTab', 'timingTab', 'controlTab'];
    tabs.forEach(tabId => {
        const tab = document.getElementById(tabId);
        console.log(`${tabId}:`, {
            exists: !!tab,
            visible: tab ? !tab.classList.contains('hidden') : 'N/A'
        });
    });
    
    console.log('=== END DEBUG ===');
}

// ADD this to test the modal population
window.debugFormState = debugFormState;


// Comprehensive function to populate all fields
function populateAllFields(data) {
    console.log('📝 Populating all fields with data:', data);
    
    // Basic fields
    if (document.getElementById('editGarageId')) {
        document.getElementById('editGarageId').value = data.garage_id || '';
    }
    if (document.getElementById('editParkingName')) {
        document.getElementById('editParkingName').value = data.Parking_Space_Name || data.name || '';
    }
    if (document.getElementById('editParkingCapacity')) {
        document.getElementById('editParkingCapacity').value = data.Parking_Capacity || data.capacity || '';
    }
    if (document.getElementById('editPricePerHour')) {
        document.getElementById('editPricePerHour').value = data.PriceperHour || data.price || '';
    }
    
    // Timing fields
    if (document.getElementById('is24_7')) {
        document.getElementById('is24_7').checked = data.is_24_7 == 1;
    }
    if (document.getElementById('openingTime')) {
        document.getElementById('openingTime').value = data.opening_time || '06:00';
    }
    if (document.getElementById('closingTime')) {
        document.getElementById('closingTime').value = data.closing_time || '22:00';
    }
    
    // OPERATING DAYS - This is the key fix!
    if (data.operating_days) {
        console.log('📅 Setting operating days:', data.operating_days);
        
        let operatingDays = [];
        if (Array.isArray(data.operating_days)) {
            operatingDays = data.operating_days;
        } else if (typeof data.operating_days === 'string') {
            operatingDays = data.operating_days.split(',').map(day => day.trim().toLowerCase());
        }
        
        console.log('📅 Processed operating days:', operatingDays);
        
        // Wait a moment for the checkboxes to be created
        setTimeout(() => {
            const dayCheckboxes = document.querySelectorAll('input[name="operating_days[]"]');
            console.log(`📅 Found ${dayCheckboxes.length} day checkboxes`);
            
            // Clear all first
            dayCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            
            // Check the appropriate ones
            dayCheckboxes.forEach(checkbox => {
                const dayValue = checkbox.value.toLowerCase();
                if (operatingDays.includes(dayValue)) {
                    checkbox.checked = true;
                    console.log(`✅ Checked ${dayValue}`);
                }
            });
        }, 100);
    } else {
        // Default to all days if no data
        console.log('⚠️ No operating days data, defaulting to all days');
        setTimeout(() => {
            const dayCheckboxes = document.querySelectorAll('input[name="operating_days[]"]');
            dayCheckboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
        }, 100);
    }
    
    console.log('✅ All fields populated');
}

// Quick test function to verify operating days are working
function testOperatingDays() {
    ensureOperatingDaysExist();
    
    setTimeout(() => {
        const checkboxes = document.querySelectorAll('input[name="operating_days[]"]');
        console.log(`Found ${checkboxes.length} operating day checkboxes:`);
        
        checkboxes.forEach(checkbox => {
            console.log(`- ${checkbox.value}: ${checkbox.checked ? 'checked' : 'unchecked'}`);
        });
    }, 100);
}

// Add this to your browser console to test: testOperatingDays()
window.testOperatingDays = testOperatingDays;

// Initialize the tab system
function initializeTabs() {
    console.log('🔧 Initializing tab system...');
    
    // Ensure tab structure exists
    const tabContainer = document.querySelector('#editModal .tab-container');
    if (!tabContainer) {
        console.log('⚠️ Tab container not found, creating tab structure...');
        createTabStructure();
    }
}

// Create complete tab structure if missing
function createTabStructure() {
    const formContainer = document.querySelector('#editTimingForm') || document.querySelector('#editForm');
    if (!formContainer) {
        console.error('❌ Form container not found');
        return;
    }
    
    // Create tab navigation
    const tabNavHtml = `
        <div class="tab-container mb-6">
            <div class="flex border-b border-gray-700">
                <button id="basicTabBtn" type="button" onclick="switchTab('basic')" 
                        class="tab-btn px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-400 hover:text-white hover:border-gray-300 transition-all">
                    📋 Basic Info
                </button>
                <button id="timingTabBtn" type="button" onclick="switchTab('timing')" 
                        class="tab-btn px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-400 hover:text-white hover:border-gray-300 transition-all">
                    ⏰ Operating Hours
                </button>
                <button id="controlTabBtn" type="button" onclick="switchTab('control')" 
                        class="tab-btn px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-400 hover:text-white hover:border-gray-300 transition-all">
                    🎛️ Real-time Control
                </button>
            </div>
        </div>
    `;
    
    // Insert tab navigation at the beginning of form
    const firstChild = formContainer.firstElementChild;
    firstChild.insertAdjacentHTML('afterend', tabNavHtml);
    
    // Create tab content areas
    createTabContent();
}

// Create all tab content areas
function createTabContent() {
    const formContainer = document.querySelector('#editTimingForm') || document.querySelector('#editForm');
    
    // Basic Info Tab
    const basicTabHtml = `
        <div id="basicTab" class="tab-content hidden">
            <div class="space-y-6">
                <div class="bg-blue-900/20 border border-blue-500/30 rounded-lg p-4 mb-6">
                    <p class="text-blue-400 font-medium">📋 Basic Parking Space Information</p>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-white mb-2">
                            🏷️ Parking Space Name
                        </label>
                        <input type="text" id="editParkingName" name="parking_name" 
                               class="w-full p-3 bg-gray-700 text-white border border-gray-600 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                               placeholder="Enter parking space name" required>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-white mb-2">
                            🚗 Parking Capacity
                        </label>
                        <input type="number" id="editParkingCapacity" name="parking_capacity" min="1" 
                               class="w-full p-3 bg-gray-700 text-white border border-gray-600 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                               placeholder="Number of parking spots" required>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-white mb-2">
                            💰 Price per Hour (৳)
                        </label>
                        <input type="number" id="editPricePerHour" name="price_per_hour" min="0" step="0.01" 
                               class="w-full p-3 bg-gray-700 text-white border border-gray-600 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                               placeholder="0.00" required>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Operating Hours Tab
    const timingTabHtml = `
    <div id="timingTab" class="tab-content hidden">
        <div class="space-y-6">
            <div class="bg-green-900/20 border border-green-500/30 rounded-lg p-4 mb-6">
                <p class="text-green-400 font-medium">⏰ Operating Hours Configuration</p>
            </div>
            
            <!-- 24/7 Toggle -->
            <div class="flex items-center space-x-3">
                <input type="checkbox" id="is24_7" name="is_24_7" 
                       onchange="toggleOperatingHours()"
                       class="w-4 h-4 text-primary bg-gray-600 border-gray-500 rounded focus:ring-primary">
                <label for="is24_7" class="text-white font-medium">🕐 Open 24/7</label>
            </div>
            
            <!-- Operating Hours (shown when not 24/7) -->
            <div id="operatingHours" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-white mb-2">🌅 Opening Time</label>
                    <input type="time" id="openingTime" name="opening_time" 
                           class="w-full p-3 bg-gray-700 text-white border border-gray-600 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-white mb-2">🌇 Closing Time</label>
                    <input type="time" id="closingTime" name="closing_time" 
                           class="w-full p-3 bg-gray-700 text-white border border-gray-600 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                </div>
            </div>
            
            <!-- Operating Days Section - Enhanced with better visibility -->
            <div id="operatingDaysSection" class="block">
                <div class="flex items-center justify-between mb-3">
                    <label class="block text-sm font-medium text-white">📅 Operating Days</label>
                    <!-- Quick Action Buttons -->
                    <div class="flex space-x-2">
                        <button type="button" onclick="selectAllDays()" 
                                class="px-2 py-1 bg-green-600 text-white text-xs rounded hover:bg-green-700 transition-colors">
                            All
                        </button>
                        <button type="button" onclick="clearAllDays()" 
                                class="px-2 py-1 bg-red-600 text-white text-xs rounded hover:bg-red-700 transition-colors">
                            None
                        </button>
                        <button type="button" onclick="selectWeekdays()" 
                                class="px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700 transition-colors">
                            Weekdays
                        </button>
                    </div>
                </div>
                
                <!-- Days Grid - Enhanced for better visibility -->
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
                    <label class="flex items-center space-x-2 cursor-pointer bg-gray-700/50 p-3 rounded-lg hover:bg-gray-600 transition-all border border-gray-600 hover:border-primary">
                        <input type="checkbox" name="operating_days[]" value="monday" 
                               class="w-4 h-4 text-primary bg-gray-600 border-gray-500 rounded focus:ring-primary accent-orange-500">
                        <span class="text-white text-sm font-medium">Monday</span>
                    </label>
                    <label class="flex items-center space-x-2 cursor-pointer bg-gray-700/50 p-3 rounded-lg hover:bg-gray-600 transition-all border border-gray-600 hover:border-primary">
                        <input type="checkbox" name="operating_days[]" value="tuesday" 
                               class="w-4 h-4 text-primary bg-gray-600 border-gray-500 rounded focus:ring-primary accent-orange-500">
                        <span class="text-white text-sm font-medium">Tuesday</span>
                    </label>
                    <label class="flex items-center space-x-2 cursor-pointer bg-gray-700/50 p-3 rounded-lg hover:bg-gray-600 transition-all border border-gray-600 hover:border-primary">
                        <input type="checkbox" name="operating_days[]" value="wednesday" 
                               class="w-4 h-4 text-primary bg-gray-600 border-gray-500 rounded focus:ring-primary accent-orange-500">
                        <span class="text-white text-sm font-medium">Wednesday</span>
                    </label>
                    <label class="flex items-center space-x-2 cursor-pointer bg-gray-700/50 p-3 rounded-lg hover:bg-gray-600 transition-all border border-gray-600 hover:border-primary">
                        <input type="checkbox" name="operating_days[]" value="thursday" 
                               class="w-4 h-4 text-primary bg-gray-600 border-gray-500 rounded focus:ring-primary accent-orange-500">
                        <span class="text-white text-sm font-medium">Thursday</span>
                    </label>
                    <label class="flex items-center space-x-2 cursor-pointer bg-gray-700/50 p-3 rounded-lg hover:bg-gray-600 transition-all border border-gray-600 hover:border-primary">
                        <input type="checkbox" name="operating_days[]" value="friday" 
                               class="w-4 h-4 text-primary bg-gray-600 border-gray-500 rounded focus:ring-primary accent-orange-500">
                        <span class="text-white text-sm font-medium">Friday</span>
                    </label>
                    <label class="flex items-center space-x-2 cursor-pointer bg-gray-700/50 p-3 rounded-lg hover:bg-gray-600 transition-all border border-gray-600 hover:border-primary">
                        <input type="checkbox" name="operating_days[]" value="saturday" 
                               class="w-4 h-4 text-primary bg-gray-600 border-gray-500 rounded focus:ring-primary accent-orange-500">
                        <span class="text-white text-sm font-medium">Saturday</span>
                    </label>
                    <label class="flex items-center space-x-2 cursor-pointer bg-gray-700/50 p-3 rounded-lg hover:bg-gray-600 transition-all border border-gray-600 hover:border-primary">
                        <input type="checkbox" name="operating_days[]" value="sunday" 
                               class="w-4 h-4 text-primary bg-gray-600 border-gray-500 rounded focus:ring-primary accent-orange-500">
                        <span class="text-white text-sm font-medium">Sunday</span>
                    </label>
                </div>
                
                <!-- Selection Summary -->
                <div id="daysSummary" class="mt-3 p-2 bg-gray-800/50 rounded text-sm text-gray-300">
                    <span id="selectedDaysCount">0</span> days selected
                </div>
            </div>
        </div>
    </div>
`;
    
    // Real-time Control Tab
    const controlTabHtml = `
        <div id="controlTab" class="tab-content hidden">
            <div class="space-y-6">
                <div class="bg-purple-900/20 border border-purple-500/30 rounded-lg p-4 mb-6">
                    <p class="text-purple-400 font-medium">🎛️ Real-time Control Panel</p>
                </div>
                
                <!-- Current Status Display -->
                <div id="statusInfo" class="bg-gray-700/50 rounded-lg p-4 border-l-4 border-primary">
                    <div class="text-center py-8">
                        <div class="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-primary mx-auto mb-3"></div>
                        <p class="text-white/70">Loading garage status...</p>
                    </div>
                </div>
                
                <!-- Control Buttons -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <button type="button" onclick="setGarageStatus('open')" 
                            class="control-btn bg-green-600 hover:bg-green-700 text-white px-6 py-4 rounded-lg font-medium transition-all">
                        🟢 Open
                    </button>
                    <button type="button" onclick="setGarageStatus('close')" 
                            class="control-btn bg-red-600 hover:bg-red-700 text-white px-6 py-4 rounded-lg font-medium transition-all">
                        🔴 Close
                    </button>
                    <button type="button" onclick="setGarageStatus('maintenance')" 
                            class="control-btn bg-orange-600 hover:bg-orange-700 text-white px-6 py-4 rounded-lg font-medium transition-all">
                        🟡 Maintenance
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // Insert all tab content
    const hiddenInput = document.getElementById('editGarageId');
    if (hiddenInput) {
        hiddenInput.insertAdjacentHTML('afterend', basicTabHtml + timingTabHtml + controlTabHtml);
        console.log('✅ Created all tab content');
    }
}

// Enhanced tab switching function
window.switchTab = function(tabName) {
    console.log('🔄 Switching to tab:', tabName);
    
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.add('hidden');
    });
    
    // Reset all tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active', 'border-primary', 'text-white');
        btn.classList.add('border-transparent', 'text-gray-400');
    });
    
    // Show selected tab
    const targetTab = document.getElementById(tabName + 'Tab');
    const targetBtn = document.getElementById(tabName + 'TabBtn');
    
    if (targetTab) {
        targetTab.classList.remove('hidden');
        console.log('✅ Showed tab:', tabName + 'Tab');
    } else {
        console.error('❌ Tab not found:', tabName + 'Tab');
    }
    
    if (targetBtn) {
        targetBtn.classList.add('active', 'border-primary', 'text-white');
        targetBtn.classList.remove('border-transparent', 'text-gray-400');
        console.log('✅ Activated tab button:', tabName + 'TabBtn');
    }
    
    // If switching to timing tab and data exists, ensure operating days are populated
    if (tabName === 'timing' && window.currentGarageData) {
        setTimeout(() => {
            populateTimingFields(window.currentGarageData);
        }, 100);
    }
};

// Clear all form data
function clearAllFormData() {
    console.log('🧹 Clearing all form data...');
    
    // Clear text inputs
    const textInputs = document.querySelectorAll('#editModal input[type="text"], #editModal input[type="number"], #editModal input[type="time"]');
    textInputs.forEach(input => {
        input.value = '';
    });
    
    // Clear checkboxes
    const checkboxes = document.querySelectorAll('#editModal input[type="checkbox"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    
    // Clear hidden garage ID
    const garageIdInput = document.getElementById('editGarageId');
    if (garageIdInput) {
        garageIdInput.value = '';
    }
}

// Fetch and populate garage data
function fetchAndPopulateGarageData(garageId) {
    console.log('📡 Fetching garage data for:', garageId);
    
    fetch('business_desh.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_parking_details&garage_id=${encodeURIComponent(garageId)}`
    })
    .then(response => response.json())
    .then(data => {
        console.log('📋 Data received:', data);
        
        if (data.success && data.data) {
            const garageData = data.data;
            
            // Store data globally for tab switching
            window.currentGarageData = garageData;
            
            // Wait for DOM to be ready
            setTimeout(() => {
                populateBasicFields(garageData);
                populateTimingFields(garageData);
                populateRealTimeControl(garageData);
                
                console.log('🎉 ALL DATA POPULATED SUCCESSFULLY!');
            }, 100);
        } else {
            console.error('❌ Failed to load data:', data.message);
            alert('Failed to load parking space data: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('❌ Network error:', error);
        alert('Error loading parking space data. Please try again.');
    });
}

// Populate basic fields
function populateBasicFields(data) {
    console.log('📝 Populating basic fields...');
    
    const fields = [
        { id: 'editGarageId', value: data.garage_id },
        { id: 'editParkingName', value: data.Parking_Space_Name },
        { id: 'editParkingCapacity', value: data.Parking_Capacity },
        { id: 'editPricePerHour', value: data.PriceperHour }
    ];
    
    fields.forEach(field => {
        const element = document.getElementById(field.id);
        if (element && field.value !== undefined) {
            element.value = field.value;
            console.log(`✅ Set ${field.id}:`, field.value);
        } else {
            console.warn(`⚠️ Element ${field.id} not found or value undefined`);
        }
    });
}




// Set garage status (for real-time control buttons)
function setGarageStatus(status) {
    const garageId = document.getElementById('editGarageId')?.value;
    if (!garageId) {
        alert('No garage selected');
        return;
    }
    
    console.log(`🎛️ Setting garage ${garageId} status to: ${status}`);
    
    fetch('business_desh.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=set_garage_status&garage_id=${garageId}&status=${status}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`Garage status updated to: ${status.toUpperCase()}`);
            // Refresh the status display
            fetchAndPopulateGarageData(garageId);
        } else {
            alert('Failed to update status: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error updating status:', error);
        alert('Error updating garage status');
    });
}

</script>


<!-- Details Modal -->
<div id="detailsModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-gray-800 rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center p-6 border-b border-gray-700">
            <h3 class="text-xl font-semibold text-white">Parking Space Details</h3>
            <button onclick="closeDetailsModal()" class="text-gray-400 hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        
        <div id="detailsContent" class="p-6">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<!-- Edit Modal -->

<!-- Complete Edit Modal -->
<div id="editModal" class="fixed inset-0 z-50 hidden" style="background-color: rgba(0,0,0,0.5);">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-gray-800 rounded-lg shadow-xl w-full max-w-4xl max-h-[90vh] overflow-y-auto">
            <!-- Header -->
            <div class="flex justify-between items-center p-6 border-b border-gray-700">
                <h3 class="text-xl font-semibold text-white">Edit Parking Space & Timing</h3>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            
            <!-- Form Content -->
            <form id="editTimingForm" class="p-6" enctype="multipart/form-data" onsubmit="handleFormSubmit(event)">
                <!-- Hidden Garage ID -->
                <input type="hidden" id="editGarageId" name="garage_id">
                
                <!-- Tab Navigation -->
                <div class="mb-6">
                    <div class="flex space-x-4 border-b border-gray-700">
                        <button type="button" onclick="switchTab('basic')" 
                                id="basicTabBtn" 
                                class="tab-btn active px-4 py-2 text-white border-b-2 border-primary">
                            📝 Basic Info
                        </button>
                        <button type="button" onclick="switchTab('timing')" 
                                id="timingTabBtn"
                                class="tab-btn px-4 py-2 text-gray-400 border-b-2 border-transparent hover:text-white">
                            ⏰ Operating Hours
                        </button>
                        <button type="button" onclick="switchTab('control')" 
                                id="controlTabBtn"
                                class="tab-btn px-4 py-2 text-gray-400 border-b-2 border-transparent hover:text-white">
                            🎛️ Real-time Control
                        </button>
                    </div>
                </div>
                
                <!-- Tab Contents -->
                
                <!-- Basic Info Tab -->
                <div id="basicTab" class="tab-content">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-white mb-2">Parking Space Name</label>
                            <input type="text" id="editParkingName" name="parking_name" 
                                   class="w-full p-3 bg-gray-700 text-white border border-gray-600 rounded-lg"
                                   required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-white mb-2">Parking Capacity</label>
                            <input type="number" id="editParkingCapacity" name="parking_capacity" min="1" 
                                   class="w-full p-3 bg-gray-700 text-white border border-gray-600 rounded-lg"
                                   required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-white mb-2">Price per Hour (৳)</label>
                            <input type="number" id="editPricePerHour" name="price_per_hour" min="0" step="0.01" 
                                   class="w-full p-3 bg-gray-700 text-white border border-gray-600 rounded-lg"
                                   required>
                        </div>
                    </div>
                </div>
                
                <!-- Operating Hours Tab -->
                <div id="timingTab" class="tab-content hidden">
                    <div class="space-y-6">
                        <!-- 24/7 Toggle -->
                        <div class="flex items-center space-x-3">
                            <input type="checkbox" id="is24_7" name="is_24_7" 
                                   onchange="toggleOperatingHours()"
                                   class="w-4 h-4 text-primary bg-gray-600 border-gray-500 rounded">
                            <label for="is24_7" class="text-white font-medium">🕐 Open 24/7</label>
                        </div>
                        
                        <!-- Operating Hours -->
                        <div id="operatingHours" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-white mb-2">⏰ Opening Time</label>
                                <input type="time" id="openingTime" name="opening_time" value="06:00"
                                       class="w-full p-3 bg-gray-700 text-white border border-gray-600 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-white mb-2">🌙 Closing Time</label>
                                <input type="time" id="closingTime" name="closing_time" value="22:00"
                                       class="w-full p-3 bg-gray-700 text-white border border-gray-600 rounded-lg">
                            </div>
                        </div>
                        
                        <!-- Operating Days -->
                        <div id="operatingDaysSection" class="mt-6">
                            <label class="block text-sm font-medium text-white mb-3">📅 Operating Days</label>
                            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
                                <label class="flex items-center space-x-2 cursor-pointer bg-gray-700/50 p-3 rounded-lg hover:bg-gray-600">
                                    <input type="checkbox" name="operating_days[]" value="monday" class="w-4 h-4" checked>
                                    <span class="text-white text-sm">Monday</span>
                                </label>
                                <label class="flex items-center space-x-2 cursor-pointer bg-gray-700/50 p-3 rounded-lg hover:bg-gray-600">
                                    <input type="checkbox" name="operating_days[]" value="tuesday" class="w-4 h-4" checked>
                                    <span class="text-white text-sm">Tuesday</span>
                                </label>
                                <label class="flex items-center space-x-2 cursor-pointer bg-gray-700/50 p-3 rounded-lg hover:bg-gray-600">
                                    <input type="checkbox" name="operating_days[]" value="wednesday" class="w-4 h-4" checked>
                                    <span class="text-white text-sm">Wednesday</span>
                                </label>
                                <label class="flex items-center space-x-2 cursor-pointer bg-gray-700/50 p-3 rounded-lg hover:bg-gray-600">
                                    <input type="checkbox" name="operating_days[]" value="thursday" class="w-4 h-4" checked>
                                    <span class="text-white text-sm">Thursday</span>
                                </label>
                                <label class="flex items-center space-x-2 cursor-pointer bg-gray-700/50 p-3 rounded-lg hover:bg-gray-600">
                                    <input type="checkbox" name="operating_days[]" value="friday" class="w-4 h-4" checked>
                                    <span class="text-white text-sm">Friday</span>
                                </label>
                                <label class="flex items-center space-x-2 cursor-pointer bg-gray-700/50 p-3 rounded-lg hover:bg-gray-600">
                                    <input type="checkbox" name="operating_days[]" value="saturday" class="w-4 h-4" checked>
                                    <span class="text-white text-sm">Saturday</span>
                                </label>
                                <label class="flex items-center space-x-2 cursor-pointer bg-gray-700/50 p-3 rounded-lg hover:bg-gray-600">
                                    <input type="checkbox" name="operating_days[]" value="sunday" class="w-4 h-4" checked>
                                    <span class="text-white text-sm">Sunday</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Real-time Control Tab -->
                <div id="controlTab" class="tab-content hidden">
                    <div class="space-y-6">
                        <!-- Current Status Display -->
                        <div id="statusInfo" class="bg-gray-700/50 rounded-lg p-4 border-l-4 border-primary">
                            <h4 class="text-white font-semibold mb-2">📊 Current Status</h4>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <span class="text-gray-400">Status:</span>
                                    <span id="currentStatusText" class="text-white font-semibold ml-2">OPEN</span>
                                </div>
                                <div>
                                    <span class="text-gray-400">Active Bookings:</span>
                                    <span id="activeBookingsCount" class="text-white font-semibold ml-2">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Control Buttons (Not part of form submission) -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <button type="button" onclick="updateGarageStatus('open')" 
                                    class="control-btn bg-green-600 hover:bg-green-700 text-white px-6 py-4 rounded-lg font-medium">
                                🟢 Open
                            </button>
                            <button type="button" onclick="updateGarageStatus('closed')" 
                                    class="control-btn bg-red-600 hover:bg-red-700 text-white px-6 py-4 rounded-lg font-medium">
                                🔴 Close
                            </button>
                            <button type="button" onclick="updateGarageStatus('maintenance')" 
                                    class="control-btn bg-orange-600 hover:bg-orange-700 text-white px-6 py-4 rounded-lg font-medium">
                                🟡 Maintenance
                            </button>
                        </div>
                        
                        <!-- Additional Control Options -->
                        <div class="mt-4">
                            <label class="block text-sm font-medium text-white mb-2">Reason for Status Change</label>
                            <input type="text" id="statusChangeReason" 
                                   class="w-full p-3 bg-gray-700 text-white border border-gray-600 rounded-lg"
                                   placeholder="Enter reason (optional)">
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="flex justify-end space-x-3 mt-8 pt-6 border-t border-gray-700">
                    <button type="button" onclick="closeEditModal()" 
                            class="px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-6 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark">
                        💾 Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function saveEditForm() {
    const form = document.getElementById('editForm');
    const formData = new FormData(form);
    formData.append('action', 'update_parking_space');
    
    fetch('business_desh.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Parking space updated successfully!');
            closeEditModal();
            location.reload();
        } else {
            alert('Failed to update: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
    });
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Debug: Check if inputs are accessible
    const inputs = document.querySelectorAll('#editForm input');
    console.log('Edit form inputs found:', inputs.length);
    
    inputs.forEach(input => {
        console.log(`Input ${input.id} - disabled: ${input.disabled}, readonly: ${input.readOnly}`);
    });
});
</script>
<script>
// Updated JavaScript for handling form submission
function handleFormSubmit(event) {
    event.preventDefault();
    
    const form = document.getElementById('editTimingForm');
    const formData = new FormData(form);
    
    // Determine action based on active tab
    const activeTab = document.querySelector('.tab-btn.active').id;
    let action = 'update_parking_space'; // Default action
    
    if (activeTab === 'hoursTabBtn') {
        action = 'update_garage_timing';
    } else if (activeTab === 'controlTabBtn') {
        action = 'control_garage_status';
    }
    
    formData.append('action', action);
    
    const submitButton = form.querySelector('button[type="submit"]');
    const originalText = submitButton.textContent;
    
    submitButton.textContent = 'Saving...';
    submitButton.disabled = true;
    
    fetch('business_desh.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message || 'Updated successfully!');
            closeEditModal();
            location.reload();
        } else {
            alert('Failed to update: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while saving');
    })
    .finally(() => {
        submitButton.textContent = originalText;
        submitButton.disabled = false;
    });
}

// Updated function to show current image when editing
function populateBasicFields(data) {
    console.log('📝 Populating basic fields...');
    
    const fields = [
        { id: 'editGarageId', value: data.garage_id },
        { id: 'editParkingName', value: data.Parking_Space_Name },
        { id: 'editParkingCapacity', value: data.Parking_Capacity },
        { id: 'editPricePerHour', value: data.PriceperHour }
    ];
    
    fields.forEach(field => {
        const element = document.getElementById(field.id);
        if (element && field.value !== undefined) {
            element.value = field.value;
            console.log(`✅ Set ${field.id}:`, field.value);
        } else {
            console.warn(`⚠️ Element ${field.id} not found or value undefined`);
        }
    });
    
    // Show current image if exists
    if (data.garage_image) {
        const currentImagePreview = document.getElementById('currentImagePreview');
        const currentImage = document.getElementById('currentImage');
        
        if (currentImagePreview && currentImage) {
            currentImage.src = data.garage_image;
            currentImagePreview.classList.remove('hidden');
        }
    }
}

// Preview selected image
document.addEventListener('DOMContentLoaded', function() {
    const imageInput = document.getElementById('editGarageImage');
    if (imageInput) {
        imageInput.addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const currentImage = document.getElementById('currentImage');
                    const currentImagePreview = document.getElementById('currentImagePreview');
                    
                    if (currentImage && currentImagePreview) {
                        currentImage.src = e.target.result;
                        currentImagePreview.classList.remove('hidden');
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    }
});
</script>
</body>
</html>