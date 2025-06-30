<?php
// Start the session at the very top
session_start();

// For connecting to database
require_once("connection.php");

// Check if user is logged in and request is POST
if (!isset($_SESSION['username']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get user information
$username = $_SESSION['username'];
$passwordConfirm = $_POST['password_confirm'];
$response = ['success' => false, 'message' => ''];

// Verify password
$query = "SELECT password FROM account_information WHERE username = '$username'";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $storedPassword = $row['password'];
    
    // Verify password
    if ($passwordConfirm !== $storedPassword) {
        $response['message'] = "Incorrect password. Account deletion cancelled.";
        echo json_encode($response);
        exit();
    }
    
    // Start transaction to ensure all operations succeed or fail together
    $conn->begin_transaction();
    
    try {
        // Delete user's vehicles
        $conn->query("DELETE FROM vehicle_information WHERE username = '$username'");
        
        // Delete user's bookings
        $conn->query("DELETE FROM bookings WHERE username = '$username'");
        
        // Delete user's parking spaces
        $conn->query("DELETE FROM garage_information WHERE username = '$username'");
        
        // Delete location data
        $conn->query("DELETE FROM garagelocation WHERE username = '$username'");
        
        // Delete user's personal information
        $conn->query("DELETE FROM personal_information WHERE username = '$username'");
        
        // Delete from garage owners if applicable
        $conn->query("DELETE FROM garage_owners WHERE username = '$username'");
        
        // Finally, delete the account itself
        $conn->query("DELETE FROM account_information WHERE username = '$username'");
        
        // If all operations succeeded, commit the transaction
        $conn->commit();
        
        $response['success'] = true;
        $response['message'] = "Account deleted successfully.";
    } catch (Exception $e) {
        // If any operation failed, roll back the transaction
        $conn->rollback();
        $response['message'] = "Error deleting account: " . $e->getMessage();
    }
} else {
    $response['message'] = "User account not found.";
}

// Return JSON response
echo json_encode($response);
?>