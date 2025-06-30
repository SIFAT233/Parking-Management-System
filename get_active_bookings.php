<?php
// Start the session
session_start();

// For connecting to database
require_once("connection.php");

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    // Return error if not logged in
    echo json_encode([
        'success' => false,
        'message' => 'User not logged in'
    ]);
    exit();
}

// Get username from session
$username = $_SESSION['username'];

// Get current date and time
$currentDate = date('Y-m-d');
$currentTime = date('H:i:s');

// Query to get all active and upcoming bookings for the user
$query = "SELECT b.*, g.Parking_Space_Name AS garage_name 
          FROM booking b
          JOIN garage_information g ON b.garage_id = g.garage_id 
          WHERE b.username = ? 
            AND (
                /* Active bookings - today and time is between start and end */
                (b.booking_date = ? AND ? BETWEEN b.booking_time AND DATE_ADD(b.booking_time, INTERVAL b.duration HOUR))
                OR
                /* Active bookings - multi-day that started earlier */
                (b.booking_date < ? AND DATE_ADD(b.booking_date, INTERVAL b.duration HOUR) >= ?)
                OR 
                /* Upcoming bookings for today */
                (b.booking_date = ? AND b.booking_time > ?)
                OR
                /* Future bookings */
                (b.booking_date > ?)
            )
          ORDER BY b.booking_date, b.booking_time";

// Prepare and execute the query
$stmt = $conn->prepare($query);
$stmt->bind_param("ssssssss", $username, $currentDate, $currentTime, $currentDate, $currentDate, $currentDate, $currentTime, $currentDate);
$stmt->execute();
$result = $stmt->get_result();

// Initialize bookings array
$bookings = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Determine if booking is active or upcoming
        $bookingStart = strtotime($row['booking_date'] . ' ' . $row['booking_time']);
        $bookingEnd = $bookingStart + ($row['duration'] * 3600); // duration in hours to seconds
        $currentTimestamp = time();
        
        if ($currentTimestamp < $bookingStart) {
            $status = 'upcoming';
        } elseif ($currentTimestamp < $bookingEnd) {
            $status = 'active';
        } else {
            // Skip completed bookings
            continue;
        }
        
        // Add booking to array with status
        $bookings[] = [
            'id' => $row['id'],
            'username' => $row['username'],
            'garage_id' => $row['garage_id'],
            'booking_date' => $row['booking_date'],
            'booking_time' => $row['booking_time'],
            'duration' => $row['duration'],
            'licenseplate' => $row['licenseplate'],
            'status' => $status,
            'garage_name' => $row['garage_name']
        ];
    }
}

// Return the bookings as JSON
echo json_encode([
    'success' => true,
    'bookings' => $bookings
]);