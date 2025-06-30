<?php
// Start the session
session_start();

// For connecting to database
require_once("connection.php");

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

$username = $_SESSION['username'];

try {
    // Get all transactions for the user with detailed information
    $query = "SELECT 
                pt.id,
                pt.transaction_type,
                pt.points_amount,
                pt.description,
                pt.created_at,
                pt.booking_id,
                b.garage_id,
                b.booking_date,
                b.booking_time,
                b.duration,
                gi.Parking_Space_Name as garage_name,
                gi.Parking_Lot_Address as garage_address
              FROM points_transactions pt
              LEFT JOIN bookings b ON pt.booking_id = b.id
              LEFT JOIN garage_information gi ON b.garage_id = gi.garage_id
              WHERE pt.username = ?
              ORDER BY pt.created_at DESC";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $transactions = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Format the transaction data
            $transaction = [
                'id' => $row['id'],
                'transaction_type' => $row['transaction_type'],
                'points_amount' => (int)$row['points_amount'],
                'description' => $row['description'],
                'created_at' => $row['created_at'],
                'booking_id' => $row['booking_id'],
                'garage_id' => $row['garage_id'],
                'garage_name' => $row['garage_name'],
                'garage_address' => $row['garage_address'],
                'booking_date' => $row['booking_date'],
                'booking_time' => $row['booking_time'],
                'duration' => $row['duration']
            ];
            
            $transactions[] = $transaction;
        }
    }
    
    // Calculate summary statistics
    $summary = [
        'total_earned' => 0,
        'total_spent' => 0,
        'total_bonus' => 0,
        'transaction_count' => count($transactions),
        'earned_count' => 0,
        'spent_count' => 0,
        'bonus_count' => 0
    ];
    
    // Calculate totals and counts
    foreach ($transactions as $transaction) {
        switch ($transaction['transaction_type']) {
            case 'earned':
                $summary['total_earned'] += $transaction['points_amount'];
                $summary['earned_count']++;
                break;
            case 'spent':
                $summary['total_spent'] += $transaction['points_amount'];
                $summary['spent_count']++;
                break;
            case 'bonus':
                $summary['total_bonus'] += $transaction['points_amount'];
                $summary['bonus_count']++;
                break;
        }
    }
    
    // Get current user points for additional context
    $currentPointsQuery = "SELECT points FROM account_information WHERE username = ?";
    $currentPointsStmt = $conn->prepare($currentPointsQuery);
    $currentPointsStmt->bind_param("s", $username);
    $currentPointsStmt->execute();
    $currentPointsResult = $currentPointsStmt->get_result();
    
    $currentPoints = 0;
    if ($currentPointsResult && $currentPointsResult->num_rows > 0) {
        $currentPointsRow = $currentPointsResult->fetch_assoc();
        $currentPoints = (int)$currentPointsRow['points'];
    }
    
    // Add current points to summary
    $summary['current_points'] = $currentPoints;
    $summary['net_points'] = $summary['total_earned'] + $summary['total_bonus'] - $summary['total_spent'];
    
    // Return successful response
    echo json_encode([
        'success' => true,
        'transactions' => $transactions,
        'summary' => $summary,
        'username' => $username,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    // Log error for debugging (you can remove this in production)
    error_log("Points transactions error for user $username: " . $e->getMessage());
    
    // Return error response
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to load transactions: ' . $e->getMessage(),
        'error_code' => 'DB_ERROR'
    ]);
    
} finally {
    // Close database connection
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($currentPointsStmt)) {
        $currentPointsStmt->close();
    }
    $conn->close();
}
?>