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
$currentPassword = $_POST['current_password'];
$newPassword = $_POST['new_password'];
$confirmPassword = $_POST['confirm_password'];
$response = ['success' => false, 'message' => ''];

// Basic validation
if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
    $response['message'] = "All fields are required.";
    echo json_encode($response);
    exit();
}

if ($newPassword !== $confirmPassword) {
    $response['message'] = "New passwords do not match.";
    echo json_encode($response);
    exit();
}

if (strlen($newPassword) < 5) {
    $response['message'] = "Password must be at least 5 characters.";
    echo json_encode($response);
    exit();
}

// Verify current password
$query = "SELECT password FROM account_information WHERE username = '$username'";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $storedPassword = $row['password'];
    
    // Verify current password (note: in production, use password_hash and password_verify)
    if ($currentPassword !== $storedPassword) {
        $response['message'] = "Current password is incorrect.";
        echo json_encode($response);
        exit();
    }
    
    // Update password
    $updateQuery = "UPDATE account_information SET password = '$newPassword' WHERE username = '$username'";
    
    if ($conn->query($updateQuery) === TRUE) {
        $response['success'] = true;
        $response['message'] = "Password updated successfully!";
    } else {
        $response['message'] = "Error updating password: " . $conn->error;
    }
} else {
    $response['message'] = "User account not found.";
}

// Return JSON response
echo json_encode($response);
?>