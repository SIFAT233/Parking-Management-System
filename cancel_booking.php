<?php
// Start the session
session_start();

// For connecting to database
require_once("connection.php");

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    // For AJAX requests
    if (isset($_POST['garage_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to cancel a booking.']);
    } else {
        // For direct URL access
        $_SESSION['cancelError'] = 'You must be logged in to cancel a booking.';
        header("Location: booking.php");
    }
    exit();
}

$username = $_SESSION['username'];
$response = ['success' => false, 'message' => 'No booking ID provided'];

// Handle both POST requests (from home.php) and GET requests (from booking.php)
if (isset($_POST['garage_id']) || isset($_GET['cancel'])) {
    
    // Determine if we're using garage_id or booking_id
    if (isset($_POST['garage_id'])) {
        $garage_id = $_POST['garage_id'];
        
        // Find the booking ID based on garage_id
        $checkQuery = "SELECT id, status FROM bookings 
                      WHERE username = ? AND garage_id = ? 
                      AND status IN ('upcoming', 'active')";
        
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("ss", $username, $garage_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $booking = $result->fetch_assoc();
            $booking_id = $booking['id'];
        } else {
            if (isset($_POST['garage_id'])) {
                echo json_encode(['success' => false, 'message' => 'No active booking found for this garage.']);
            } else {
                $_SESSION['cancelError'] = 'No active booking found for this garage.';
                header("Location: booking.php");
            }
            exit();
        }
        $stmt->close();
    } else {
        // Direct booking ID from URL
        $booking_id = (int)$_GET['cancel'];
        
        // Check if booking belongs to the user and get garage_id
        $checkQuery = "SELECT id, garage_id FROM bookings WHERE id = ? AND username = ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("is", $booking_id, $username);
        $stmt->execute();
        $checkResult = $stmt->get_result();
        
        if ($checkResult && $checkResult->num_rows > 0) {
            $bookingRow = $checkResult->fetch_assoc();
            $garage_id = $bookingRow['garage_id'];
        } else {
            $_SESSION['cancelError'] = 'Booking not found or does not belong to you.';
            header("Location: booking.php");
            exit();
        }
        $stmt->close();
    }
    
    // Update booking status to 'cancelled'
    $updateQuery = "UPDATE bookings SET status = 'cancelled' WHERE id = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("i", $booking_id);
    
    if ($stmt->execute()) {
        // Update garage availability after cancellation
        updateAllGarageAvailability($conn);
        
        // Get updated garage info
        $garageQuery = "SELECT g.Parking_Space_Name, g.Availability, g.Parking_Capacity 
                      FROM garage_information g
                      WHERE g.garage_id = ?";
        
        $stmt = $conn->prepare($garageQuery);
        $stmt->bind_param("s", $garage_id);
        $stmt->execute();
        $garageResult = $stmt->get_result();
        $garageData = $garageResult->fetch_assoc();
        
        $response = [
            'success' => true, 
            'message' => 'Your booking has been cancelled successfully.',
            'updated_garage' => [
                'id' => $garage_id,
                'name' => $garageData['Parking_Space_Name'],
                'available' => $garageData['Availability'],
                'capacity' => $garageData['Parking_Capacity']
            ]
        ];
    } else {
        $response = ['success' => false, 'message' => 'Error cancelling booking: ' . $stmt->error];
    }
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

// Return appropriate response based on request type
if (isset($_POST['garage_id'])) {
    // For AJAX requests from home.php
    header('Content-Type: application/json');
    echo json_encode($response);
} else {
    // For direct requests from booking.php
    if ($response['success']) {
        $_SESSION['cancelMessage'] = $response['message'];
    } else {
        $_SESSION['cancelError'] = $response['message'];
    }
    header("Location: booking.php");
}
?>