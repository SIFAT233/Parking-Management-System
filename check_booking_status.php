<?php
// check_booking_status.php
// Start the session
session_start();
// For connecting to database
require_once("connection.php");
// Check if user is logged in
if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}
// Get username from session
$username = $_SESSION['username'];
// Check if garage_id is provided
if (!isset($_GET['garage_id'])) {
    echo json_encode(['success' => false, 'message' => 'No garage ID provided']);
    exit;
}
$garageId = $_GET['garage_id'];
// Query to check if user has active or upcoming booking for this garage
$query = "SELECT id, status, booking_date, booking_time, duration
          FROM bookings
          WHERE username = ? AND garage_id = ?
          AND (status = 'upcoming' OR status = 'active')
          ORDER BY booking_date DESC, booking_time DESC
          LIMIT 1";
$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $username, $garageId);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    // User has an active booking
    $booking = $result->fetch_assoc();
   
    // Format times for better JS parsing if needed
    $booking_date = $booking['booking_date'];
    $booking_time = $booking['booking_time'];
    
    // Calculate start and end timestamps for the timer
    $startTimestamp = strtotime($booking_date . ' ' . $booking_time);
    $endTimestamp = $startTimestamp + ((int)$booking['duration'] * 3600); // Convert hours to seconds
   
    echo json_encode([
        'success' => true,
        'has_booking' => true,
        'booking' => [
            'id' => $booking['id'],
            'status' => $booking['status'],
            'booking_date' => $booking_date,
            'booking_time' => $booking_time,
            'duration' => (int)$booking['duration'],
            'start_timestamp' => $startTimestamp,
            'end_timestamp' => $endTimestamp
        ]
    ]);
} else {
    // No active booking found
    echo json_encode([
        'success' => true,
        'has_booking' => false
    ]);
}
$stmt->close();
$conn->close();
?>