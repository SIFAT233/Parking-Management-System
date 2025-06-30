<?php
// Start session
session_start();
require_once("connection.php");

// Set timezone to Bangladesh
date_default_timezone_set('Asia/Dhaka');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log function for better debugging
function debug_log($message) {
    error_log("[Next Availability Debug] " . $message);
}

debug_log("Script started");
debug_log("Current server time: " . date('Y-m-d H:i:s'));

// Ensure request has garage_id
if (!isset($_GET['garage_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Garage ID is required']);
    exit();
}

$garageId = $_GET['garage_id'];
$currentUser = isset($_SESSION['username']) ? $_SESSION['username'] : '';

debug_log("Checking for garage_id: $garageId, Current user: $currentUser");

// Check if the current user already has a booking for this garage
$hasOwnBooking = false;
if (!empty($currentUser)) {
    $checkOwn = "SELECT COUNT(*) as own_booking 
                FROM bookings 
                WHERE garage_id = ? 
                AND username = ? 
                AND status IN ('upcoming', 'active')";
    $stmt = $conn->prepare($checkOwn);
    $stmt->bind_param("ss", $garageId, $currentUser);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $hasOwnBooking = ($row['own_booking'] > 0);
    $stmt->close();
    
    debug_log("User has own booking: " . ($hasOwnBooking ? "Yes" : "No"));
}

// Get ALL upcoming and active bookings for this garage EXCEPT the current user's
$query = "SELECT booking_date, booking_time, duration, username, status
          FROM bookings 
          WHERE garage_id = ? 
          AND status IN ('upcoming', 'active')
          ORDER BY booking_date ASC, booking_time ASC";

debug_log("Executing query: " . $query);
debug_log("Parameters: garage_id = $garageId");

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $garageId);
$stmt->execute();
$result = $stmt->get_result();

debug_log("Found " . $result->num_rows . " bookings");

$nextAvailableTime = null;
$bookingDetails = [];

if ($result->num_rows > 0) {
    // Find the latest end time of all bookings
    while ($booking = $result->fetch_assoc()) {
        $bookingDate = $booking['booking_date'];
        $bookingTime = $booking['booking_time'];
        $duration = intval($booking['duration']);
        $bookingUser = $booking['username'];
        $bookingStatus = $booking['status'];
        
        debug_log("Processing booking: Date=$bookingDate, Time=$bookingTime, Duration=$duration, User=$bookingUser, Status=$bookingStatus");
        
        // Calculate end time
        $startDateTime = new DateTime("$bookingDate $bookingTime");
        $endDateTime = clone $startDateTime;
        $endDateTime->add(new DateInterval("PT{$duration}H"));
        
        debug_log("Booking start: " . $startDateTime->format('Y-m-d H:i:s') . ", end: " . $endDateTime->format('Y-m-d H:i:s'));
        
        $bookingDetails[] = [
            'user' => $bookingUser,
            'start' => $startDateTime->format('Y-m-d H:i:s'),
            'end' => $endDateTime->format('Y-m-d H:i:s'),
            'status' => $bookingStatus
        ];
        
        // Update latest end time
        if ($nextAvailableTime === null || $endDateTime > $nextAvailableTime) {
            $nextAvailableTime = $endDateTime;
            debug_log("Updated next available time to: " . $nextAvailableTime->format('Y-m-d H:i:s'));
        }
    }
    
    if ($nextAvailableTime) {
        // Get current time
        $now = new DateTime();
        debug_log("Current time: " . $now->format('Y-m-d H:i:s'));
        
        // Make sure the next available time is in the future
        if ($nextAvailableTime < $now) {
            debug_log("Next available time is in the past, setting to now");
            $nextAvailableTime = $now;
        }
        
        debug_log("Final next available time: " . $nextAvailableTime->format('Y-m-d H:i:s'));
        debug_log("Timestamp: " . $nextAvailableTime->getTimestamp());
        debug_log("Current timestamp: " . time());
        debug_log("Difference in seconds: " . ($nextAvailableTime->getTimestamp() - time()));
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'has_own_booking' => $hasOwnBooking,
            'next_available' => [
                'date' => $nextAvailableTime->format('Y-m-d'),
                'time' => $nextAvailableTime->format('H:i:s'),
                'formatted' => $nextAvailableTime->format('M j, Y g:i A'),
                'timestamp' => $nextAvailableTime->getTimestamp()
            ],
            'debug_info' => [
                'bookings' => $bookingDetails,
                'current_time' => $now->format('Y-m-d H:i:s'),
                'current_timestamp' => time(),
                'time_difference_seconds' => ($nextAvailableTime->getTimestamp() - time())
            ]
        ]);
    } else {
        // No other bookings
        debug_log("No valid next available time found");
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'has_own_booking' => $hasOwnBooking,
            'message' => 'No bookings found'
        ]);
    }
} else {
    // No bookings
    debug_log("No bookings found");
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'has_own_booking' => $hasOwnBooking,
        'message' => 'No bookings found'
    ]);
}
?>