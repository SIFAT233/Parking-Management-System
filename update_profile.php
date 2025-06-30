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

// Get user information from database
$username = $_SESSION['username'];
$response = ['success' => false, 'message' => ''];

// Get form data
$firstName = trim($_POST['firstName']);
$lastName = trim($_POST['lastName']);
$email = trim($_POST['email']);
$phone = trim($_POST['phone']);
$address = trim($_POST['address']);

// Basic validation
if (empty($firstName) || empty($lastName) || empty($email)) {
    $response['message'] = "First name, last name, and email are required fields.";
    echo json_encode($response);
    exit();
}

// Check if email already exists for another user
$checkQuery = "SELECT * FROM personal_information WHERE email = '$email' AND username != '$username'";
$checkResult = $conn->query($checkQuery);

if ($checkResult && $checkResult->num_rows > 0) {
    $response['message'] = "This email is already in use by another account.";
    echo json_encode($response);
    exit();
}

// Check if user already has personal information
$checkExistingQuery = "SELECT * FROM personal_information WHERE username = '$username'";
$checkExistingResult = $conn->query($checkExistingQuery);

if ($checkExistingResult && $checkExistingResult->num_rows > 0) {
    // Update existing record
    $updateQuery = "UPDATE personal_information 
                    SET firstName = '$firstName', 
                        lastName = '$lastName', 
                        email = '$email', 
                        phone = '$phone', 
                        address = '$address' 
                    WHERE username = '$username'";
    
    if ($conn->query($updateQuery) === TRUE) {
        $response['success'] = true;
        $response['message'] = "Profile updated successfully!";
        
        // Update session data
        $_SESSION['fullName'] = $firstName . ' ' . $lastName;
        $_SESSION['email'] = $email;
    } else {
        $response['message'] = "Error updating profile: " . $conn->error;
    }
} else {
    // Insert new record
    $insertQuery = "INSERT INTO personal_information (firstName, lastName, email, phone, address, username) 
                   VALUES ('$firstName', '$lastName', '$email', '$phone', '$address', '$username')";
    
    if ($conn->query($insertQuery) === TRUE) {
        $response['success'] = true;
        $response['message'] = "Profile created successfully!";
        
        // Update session data
        $_SESSION['fullName'] = $firstName . ' ' . $lastName;
        $_SESSION['email'] = $email;
    } else {
        $response['message'] = "Error creating profile: " . $conn->error;
    }
}

// Return JSON response
echo json_encode($response);
?>