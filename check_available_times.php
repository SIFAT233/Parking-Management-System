<?php
// Start session
session_start();
require_once("connection.php");

// Ensure user is logged in
if (!isset($_SESSION['username'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

// Validate input
if (!isset($_GET['garage_id']) || !isset($_GET['booking_date'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required parameters']);
    exit();
}

$garageId = $_GET['garage_id'];
$bookingDate = $_GET['booking_date'];

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid date format']);
    exit();
}

// Get the garage's operating hours (assuming 6 AM to 10 PM)
$startTime = '06:00:00';
$endTime = '22:00:00';

// Generate all possible time slots
$timeSlots = [];
$slotStart = strtotime($startTime);
$slotEnd = strtotime($endTime);
$interval = 60 * 60; // 1 hour slots

for ($time = $slotStart; $time < $slotEnd; $time += $interval) {
    $formattedTime = date('H:i:s', $time);
    $displayTime = date('h:i A', $time);
    $timeSlots[] = [
        'value' => $formattedTime,
        'display' => $displayTime,
        'is_available' => true
    ];
}

// Get garage capacity
$garageQuery = "SELECT Parking_Capacity FROM garage_information WHERE garage_id = ?";
$stmt = $conn->prepare($garageQuery);
$stmt->bind_param("s", $garageId);
$stmt->execute();
$garageResult = $stmt->get_result();

if ($garageResult->num_rows === 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Garage not found']);
    exit();
}

$garageData = $garageResult->fetch_assoc();
$parkingCapacity = $garageData['Parking_Capacity'];
$stmt->close();

// Get all bookings for this garage on this date
$bookingsQuery = "SELECT booking_time, duration, COUNT(*) as booking_count 
                 FROM bookings 
                 WHERE garage_id = ? 
                 AND booking_date = ? 
                 AND status IN ('upcoming', 'active')
                 GROUP BY booking_time, duration";
$stmt = $conn->prepare($bookingsQuery);
$stmt->bind_param("ss", $garageId, $bookingDate);
$stmt->execute();
$bookingsResult = $stmt->get_result();
$stmt->close();

// Mark unavailable time slots
if ($bookingsResult && $bookingsResult->num_rows > 0) {
    while ($booking = $bookingsResult->fetch_assoc()) {
        $bookingStartTime = strtotime($booking['booking_time']);
        $bookingEndTime = $bookingStartTime + ($booking['duration'] * 3600);
        $bookingsCount = $booking['booking_count'];
        
        // If bookings count equals or exceeds capacity, mark slots as unavailable
        if ($bookingsCount >= $parkingCapacity) {
            // Mark all slots that overlap with this booking as unavailable
            foreach ($timeSlots as &$slot) {
                $slotTime = strtotime($bookingDate . ' ' . $slot['value']);
                $slotEndTime = $slotTime + $interval;
                
                // Check for overlap
                if (($slotTime >= $bookingStartTime && $slotTime < $bookingEndTime) ||
                    ($slotEndTime > $bookingStartTime && $slotEndTime <= $bookingEndTime) ||
                    ($slotTime <= $bookingStartTime && $slotEndTime >= $bookingEndTime)) {
                    $slot['is_available'] = false;
                }
            }
        }
    }
}

// Filter to only available time slots
$availableTimeSlots = array_filter($timeSlots, function($slot) {
    return $slot['is_available'];
});

// Reset array keys
$availableTimeSlots = array_values($availableTimeSlots);

// Return the available time slots
header('Content-Type: application/json');
echo json_encode(['available_times' => $availableTimeSlots]);
?>