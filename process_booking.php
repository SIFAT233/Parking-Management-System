<?php
// Start the session
session_start();
// For connecting to database
require_once("connection.php");
// Check if user is logged in
if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to book a parking space.']);
    exit();
}
// Check if it's a POST request
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get the form data
    $username = $_SESSION['username'];
    $garage_id = $_POST['garage_id'];
    $booking_date = $_POST['booking_date'];
    $booking_time = $_POST['booking_time'];
    $duration = (int)$_POST['duration'];
    $licenseplate = $_POST['licenseplate']; 
   
    // Validate inputs
    if (empty($garage_id) || empty($booking_date) || empty($booking_time) || empty($duration) || empty($licenseplate)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit();
    }
   
    // Check if garage exists and get its capacity
    $garageQuery = "SELECT Parking_Capacity, Availability, Parking_Space_Name FROM garage_information WHERE garage_id = ?";
    $stmt = $conn->prepare($garageQuery);
    $stmt->bind_param("s", $garage_id);
    $stmt->execute();
    $garageResult = $stmt->get_result();
    $stmt->close();
   
    if ($garageResult && $garageResult->num_rows > 0) {
        $garageData = $garageResult->fetch_assoc();
        $parking_capacity = $garageData['Parking_Capacity'];
        $current_availability = $garageData['Availability'];
        $parking_name = $garageData['Parking_Space_Name'];
        
        // Check if garage is full
        if ($current_availability <= 0) {
            echo json_encode(['success' => false, 'message' => 'This parking space is currently full. Please try another garage or different time.']);
            exit();
        }
        
        // Calculate booking end time
        $booking_end_time_sql = "SELECT ADDTIME(?, ?) as end_time";
        $stmt = $conn->prepare($booking_end_time_sql);
        $duration_formatted = $duration . ":00:00"; // Convert duration to HH:MM:SS format
        $stmt->bind_param("ss", $booking_time, $duration_formatted);
        $stmt->execute();
        $end_time_result = $stmt->get_result();
        $end_time_data = $end_time_result->fetch_assoc();
        $booking_end_time = $end_time_data['end_time'];
        $stmt->close();
        
        // Check for overlapping bookings
        // A booking overlaps if:
        // 1. The existing booking's start time is within our new booking's time range
        // 2. The existing booking's end time is within our new booking's time range
        // 3. The existing booking completely encapsulates our new booking's time range
        $overlapQuery = "SELECT COUNT(*) as overlap_count FROM bookings
                         WHERE garage_id = ? 
                         AND booking_date = ?
                         AND status IN ('upcoming', 'active')
                         AND (
                             (booking_time >= ? AND booking_time < ?) 
                             OR 
                             (ADDTIME(booking_time, SEC_TO_TIME(duration * 3600)) > ? AND ADDTIME(booking_time, SEC_TO_TIME(duration * 3600)) <= ?)
                             OR
                             (booking_time <= ? AND ADDTIME(booking_time, SEC_TO_TIME(duration * 3600)) >= ?)
                         )";
                         
        $stmt = $conn->prepare($overlapQuery);
        $stmt->bind_param("ssssssss", 
            $garage_id, 
            $booking_date, 
            $booking_time, 
            $booking_end_time,  
            $booking_time, 
            $booking_end_time,
            $booking_time,
            $booking_end_time
        );
        $stmt->execute();
        $overlapResult = $stmt->get_result();
        $overlapData = $overlapResult->fetch_assoc();
        $overlapCount = $overlapData['overlap_count'];
        $stmt->close();
        
        // Check if we have capacity for this booking
        if ($overlapCount >= $parking_capacity) {
            echo json_encode(['success' => false, 'message' => 'This time slot is already fully booked. Please choose another time or garage.']);
            exit();
        }
        
        // All checks passed, insert the booking
        $status = "upcoming";
        $payment_status = "pending";
        
        $insertQuery = "INSERT INTO bookings (username, garage_id, licenseplate, booking_date, booking_time, duration, status, payment_status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($insertQuery);
        $stmt->bind_param("sssssiss", $username, $garage_id, $licenseplate, $booking_date, $booking_time, $duration, $status, $payment_status);
        
        if ($stmt->execute()) {
            // Update all garage availabilities
            updateAllGarageAvailability($conn);
            
            // Get the updated availability after booking
            $updatedAvailQuery = "SELECT Availability, Parking_Capacity FROM garage_information WHERE garage_id = ?";
            $updatedStmt = $conn->prepare($updatedAvailQuery);
            $updatedStmt->bind_param("s", $garage_id);
            $updatedStmt->execute();
            $updatedResult = $updatedStmt->get_result();
            $updatedData = $updatedResult->fetch_assoc();
            $updatedStmt->close();
            
            // Return success with updated garage info
            echo json_encode([
                'success' => true, 
                'message' => 'Your booking has been confirmed! You can view your booking details in "My Bookings".',
                'updated_garage' => [
                    'id' => $garage_id,
                    'name' => $parking_name,
                    'available' => $updatedData['Availability'],
                    'capacity' => $updatedData['Parking_Capacity']
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error making booking: ' . $stmt->error]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Garage not found.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}

// Function to update all garage availability
function updateAllGarageAvailability($conn) {
    // Get all garages
    $getAllGaragesQuery = "SELECT garage_id, Parking_Capacity FROM garage_information";
    $garagesResult = $conn->query($getAllGaragesQuery);
   
    if ($garagesResult && $garagesResult->num_rows > 0) {
        while ($garage = $garagesResult->fetch_assoc()) {
            $garageId = $garage['garage_id'];
            $totalCapacity = $garage['Parking_Capacity'];
           
            // Count active and upcoming bookings for this garage
            $countBookingsQuery = "SELECT COUNT(*) as booking_count
                                  FROM bookings
                                  WHERE garage_id = ?
                                  AND status IN ('upcoming', 'active')";
            $stmt = $conn->prepare($countBookingsQuery);
            $stmt->bind_param("s", $garageId);
            $stmt->execute();
            $countResult = $stmt->get_result();
            $countRow = $countResult->fetch_assoc();
            $bookingsCount = $countRow['booking_count'];
            $stmt->close();
           
            // Calculate availability
            $availability = $totalCapacity - $bookingsCount;
            $availability = max(0, $availability); // Make sure it's not negative
           
            // Update garage availability
            $updateQuery = "UPDATE garage_information
                          SET Availability = ?
                          WHERE garage_id = ?";
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("is", $availability, $garageId);
            $stmt->execute();
            $stmt->close();
        }
    }
}
?>