<?php
// Start the session
session_start();

// For connecting to database
require_once("connection.php");

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to get vehicle information.']);
    exit();
}

$username = $_SESSION['username'];

// Get user's vehicles
$query = "SELECT licenseplate, vehicleType, make, model, color FROM vehicle_information 
          WHERE username = '$username'";

$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    $vehicles = [];
    
    while ($row = $result->fetch_assoc()) {
        $vehicles[] = $row;
    }
    
    echo json_encode(['success' => true, 'vehicles' => $vehicles]);
} else {
    echo json_encode(['success' => false, 'message' => 'No vehicles found for this user.']);
}
?>