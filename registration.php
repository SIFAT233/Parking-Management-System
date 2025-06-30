<?php
// Database connection
require_once("connection.php");

// Start a transaction to ensure data consistency
$conn->begin_transaction();

// Process form submission
if(isset($_POST['submit'])){
    $errorOccurred = false;
    
    try {
        // Determine registration type
        $isGarageOwner = isset($_POST['isGarageOwner']) && $_POST['isGarageOwner'] == 1;
        
        // Personal Information
        $firstName = mysqli_real_escape_string($conn, $_POST['firstName']);
        $lastName = mysqli_real_escape_string($conn, $_POST['lastName']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);
        $address = mysqli_real_escape_string($conn, $_POST['address']);
        
        // Account Information
        $username = mysqli_real_escape_string($conn, $_POST['username']);
        $password = mysqli_real_escape_string($conn, $_POST['password']);
        $confirmPassword = mysqli_real_escape_string($conn, $_POST['confirmPassword']);
        
        // Validate passwords match
        if($password != $confirmPassword){
            throw new Exception("Passwords do not match!");
        }
        
        // Check if username already exists
        $checkUsername = "SELECT * FROM account_information WHERE username = '$username'";
        $usernameResult = $conn->query($checkUsername);
        
        if($usernameResult && $usernameResult->num_rows > 0) {
            throw new Exception("Username '$username' already exists. Please choose a different username.");
        }
        
        // Check if email already exists in personal_information
        $checkEmail = "SELECT * FROM personal_information WHERE email = '$email'";
        $emailResult = $conn->query($checkEmail);
        
        if($emailResult && $emailResult->num_rows > 0) {
            throw new Exception("Email '$email' is already registered. Please use a different email address.");
        }
        
        // Insert into account_information table first
        $accountQuery = "INSERT INTO account_information (username, password) 
                        VALUES ('$username', '$password')";
        if(!$conn->query($accountQuery)) {
            throw new Exception("Error creating account: " . $conn->error);
        }
        
        // Then insert into personal_information table
        $personalQuery = "INSERT INTO personal_information (firstName, lastName, email, phone, address, username) 
        VALUES ('$firstName', '$lastName', '$email', '$phone', '$address', '$username')";
        if(!$conn->query($personalQuery)) {
            throw new Exception("Error with personal information: " . $conn->error);
        }
        
        // Process user type specific information
        if($isGarageOwner) {
            // Create garage owner ID with the format G_owner_username
            $owner_id = "G_owner_" . $username;
            
            // Check if garage_owners table exists, create if not
            $checkTableQuery = "SHOW TABLES LIKE 'garage_owners'";
            $tableResult = $conn->query($checkTableQuery);
            
            if($tableResult->num_rows == 0) {
                // Create the garage_owners table
                $createTableQuery = "CREATE TABLE `garage_owners` (
                  `owner_id` varchar(100) NOT NULL,
                  `username` varchar(50) NOT NULL,
                  `is_verified` tinyint(1) DEFAULT 0,
                  `registration_date` timestamp NOT NULL DEFAULT current_timestamp(),
                  `last_login` timestamp NULL DEFAULT NULL,
                  `account_status` enum('active','suspended','inactive') NOT NULL DEFAULT 'active',
                  PRIMARY KEY (`owner_id`),
                  UNIQUE KEY `username` (`username`),
                  CONSTRAINT `fk_garage_owners_username` FOREIGN KEY (`username`) REFERENCES `account_information` (`username`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                $conn->query($createTableQuery);
            }
            
            // Insert into garage_owners table
            $ownerQuery = "INSERT INTO garage_owners (owner_id, username, is_verified) 
                         VALUES ('$owner_id', '$username', 0)";
            if(!$conn->query($ownerQuery)) {
                throw new Exception("Error creating garage owner account: " . $conn->error);
            }
            
            // Garage Owner Information from form
            $garageName = mysqli_real_escape_string($conn, $_POST['garageName']);
            $parkingLotAddress = mysqli_real_escape_string($conn, $_POST['parkingLotAddress']);
            $parkingType = mysqli_real_escape_string($conn, $_POST['parkingType']);
            $parkingDimensions = mysqli_real_escape_string($conn, $_POST['parkingDimensions']);
            $garageSlots = intval($_POST['garageSlots']);
            $pricePerHour = floatval($_POST['pricePerHour']);
            
            // Set Availability equal to Number of Parking Slots (garageSlots)
            $availability = $garageSlots;
            
            // Generate a unique garage_id
            $garageIdPrefix = $username . "_G_";
            $garageId = $garageIdPrefix . sprintf("%03d", 1); // First garage
            
            // Process location data if provided
            if(isset($_POST['latitude']) && isset($_POST['longitude']) && 
               !empty($_POST['latitude']) && !empty($_POST['longitude'])) {
                $latitude = floatval($_POST['latitude']);
                $longitude = floatval($_POST['longitude']);
                
                // Insert into garagelocation table first (because garage_information references it)
                $locationQuery = "INSERT INTO garagelocation (
                    garage_id,
                    Latitude,
                    Longitude,
                    username
                ) VALUES (
                    '$garageId',
                    $latitude,
                    $longitude,
                    '$username'
                )";
                
                if(!$conn->query($locationQuery)) {
                    throw new Exception("Error with garage location: " . $conn->error);
                }
                
                // Insert into garage_information table
                $garageQuery = "INSERT INTO garage_information (
                    username,
                    garage_id,
                    Parking_Space_Name, 
                    Parking_Lot_Address, 
                    Parking_Type, 
                    Parking_Space_Dimensions, 
                    Parking_Capacity, 
                    Availability, 
                    PriceperHour
                ) VALUES (
                    '$username',
                    '$garageId',
                    '$garageName',
                    '$parkingLotAddress', 
                    '$parkingType', 
                    '$parkingDimensions', 
                    $garageSlots, 
                    $availability, 
                    $pricePerHour
                )";
                
                if(!$conn->query($garageQuery)) {
                    throw new Exception("Error with garage information: " . $conn->error);
                }
            } else {
                throw new Exception("Latitude and longitude are required for garage owners. Please click on the map to select your garage location.");
            }
            
            $successMessage = "Garage owner registration successful! You can now <a href='login.php' class='text-amber-400 hover:text-amber-500'>login</a> with username <strong>$username</strong>.";
            
        } else {
            // Regular User Information (Vehicle + Parking Plan)
            $licensePlate = mysqli_real_escape_string($conn, $_POST['licensePlate']);
            $vehicleType = mysqli_real_escape_string($conn, $_POST['vehicleType']);
            $make = mysqli_real_escape_string($conn, $_POST['make']);
            $model = mysqli_real_escape_string($conn, $_POST['model']);
            $color = mysqli_real_escape_string($conn, $_POST['color']);
            
            // Insert into vehicle_information table
            $vehicleQuery = "INSERT INTO vehicle_information (licensePlate, vehicleType, make, model, color, username)
                           VALUES ('$licensePlate', '$vehicleType', '$make', '$model', '$color', '$username')";
            
            if(!$conn->query($vehicleQuery)) {
                throw new Exception("Error with vehicle information: " . $conn->error);
            }
            
            $successMessage = "Registration successful! You can now <a href='login.php' class='text-amber-400 hover:text-amber-500'>login</a> with username <strong>$username</strong>.";
        }
        
        // If we got here, everything worked, so commit the transaction
        $conn->commit();
        
    } catch (Exception $e) {
        // If an error occurred, roll back the transaction
        $conn->rollback();
        $errorMessage = $e->getMessage();
        $errorOccurred = true;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>পার্কিং লাগবে - Registration</title>
    <style>
        :root {
            --primary: #f39c12;
            --primary-dark: #e67e22;
            --dark-800: #1a1a1a;
            --dark-850: #141414;
            --dark-900: #0f0f0f;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            background-color: var(--dark-900);
            color: white;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Particles.js container */
        #particles-js {
            position: fixed;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: -1;
            background-color: var(--dark-900);
        }
        
        /* Enhanced Background Animation */
        .bg-animation {
            position: fixed;
            inset: 0;
            z-index: -1;
            overflow: hidden;
            pointer-events: none;
        }
        
        .bg-circle {
            position: absolute;
            border-radius: 50%;
            opacity: 0.1;
            filter: blur(80px);
            animation: float 15s infinite ease-in-out alternate;
        }
        
        .bg-circle:nth-child(1) {
            width: 300px;
            height: 300px;
            background: var(--primary);
            top: 20%;
            left: 25%;
        }
        
        .bg-circle:nth-child(2) {
            width: 400px;
            height: 400px;
            background: var(--primary-dark);
            bottom: 20%;
            right: 25%;
            animation-delay: 3s;
        }
        
        .bg-circle:nth-child(3) {
            width: 200px;
            height: 200px;
            background: var(--primary);
            top: 60%;
            left: 35%;
            animation-delay: 6s;
        }
        
        /* Additional animated elements */
        .animated-circle {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            animation: spin-slow 20s linear infinite;
        }
        
        .animated-circle:nth-child(1) {
            width: 300px;
            height: 300px;
            background: rgba(243, 156, 18, 0.1);
            top: 30%;
            right: 20%;
        }
        
        .animated-circle:nth-child(2) {
            width: 200px;
            height: 200px;
            background: rgba(230, 126, 34, 0.1);
            bottom: 30%;
            left: 10%;
            animation-direction: reverse;
            animation-delay: 5s;
        }
        
        @keyframes float {
            0% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-25px) scale(1.05); }
            100% { transform: translateY(-50px) scale(1.1); }
        }
        
        @keyframes spin-slow {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Main container */
        .container {
            width: 100%;
            max-width: 1200px;
            background: rgba(20, 20, 20, 0.8);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            flex-direction: column;
            animation: fadeIn 0.8s ease-out;
            position: relative;
            z-index: 1;
        }
        
        @media (min-width: 768px) {
            .container {
                flex-direction: row;
                min-height: 85vh;
                max-height: 900px;
            }
        }
        
        /* Left panel */
        .left-panel {
            flex: 0 0 100%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            padding: 40px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }
        
        @media (min-width: 768px) {
            .left-panel {
                flex: 0 0 40%;
            }
        }
        
        /* Pattern overlay enhancement */
        .pattern-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0.1;
            background-image: radial-gradient(circle, rgba(255,255,255,0.15) 2px, transparent 2px);
            background-size: 20px 20px;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }
        
        .logo {
            width: 50px;
            height: 50px;
            background-color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 15px rgba(255, 255, 255, 0.5);
            animation: glow 2s ease-in-out infinite alternate;
        }
        
        @keyframes glow {
            0% { box-shadow: 0 0 5px rgba(255, 255, 255, 0.5); }
            100% { box-shadow: 0 0 20px rgba(255, 255, 255, 0.8); }
        }
        
        .logo-text {
            font-size: 24px;
            font-weight: bold;
        }
        
        .welcome-text {
            position: relative;
            z-index: 1;
        }
        
        .welcome-text h2 {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 15px;
            animation: slideRight 1s ease-out;
        }
        
        .welcome-text p {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 30px;
            max-width: 400px;
        }
        
        /* Enhanced Car Animation */
        .car-illustration {
            text-align: center;
            margin: 30px 0;
            animation: float 6s infinite ease-in-out;
        }
        
        .car-illustration svg {
            width: 180px;
            height: auto;
            filter: drop-shadow(0 0 15px rgba(255, 255, 255, 0.3));
        }
        
        .features {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            position: relative;
            z-index: 1;
        }
        
        .feature {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            transition: all 0.3s ease;
            padding: 10px;
            border-radius: 8px;
            background-color: rgba(255, 255, 255, 0.03);
            border: 1px solid transparent;
        }
        
        .feature:hover {
            transform: translateY(-5px);
            background-color: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
            box-shadow: 0 10px 20px -5px rgba(0, 0, 0, 0.3);
        }
        
        .feature-icon {
            width: 36px;
            height: 36px;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.3s ease;
        }
        
        .feature:hover .feature-icon {
            transform: scale(1.1);
            background-color: rgba(255, 255, 255, 0.3);
            box-shadow: 0 0 15px rgba(255, 255, 255, 0.3);
        }
        
        .feature-icon svg {
            width: 18px;
            height: 18px;
        }
        
        .feature-text h4 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .feature-text p {
            font-size: 12px;
            opacity: 0.8;
        }
        
        /* Right panel (form) */
        .right-panel {
            flex: 0 0 100%;
            padding: 40px;
            overflow-y: auto;
            max-height: 50vh;
        }
        
        @media (min-width: 768px) {
            .right-panel {
                flex: 0 0 60%;
                max-height: 85vh;
            }
        }
        
        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .form-header .icon-circle {
            width: 60px;
            height: 60px;
            background-color: rgba(243, 156, 18, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(243, 156, 18, 0.4); }
            70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(243, 156, 18, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(243, 156, 18, 0); }
        }
        
        .form-header .icon-circle svg {
            width: 30px;
            height: 30px;
            color: var(--primary);
        }
        
        .form-header h3 {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .form-header p {
            color: rgba(255, 255, 255, 0.7);
        }
        
        /* User type selection */
        .user-type-selection {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .user-type-btn {
            flex: 1;
            padding: 15px;
            border-radius: 12px;
            border: 2px solid rgba(255, 255, 255, 0.1);
            background-color: transparent;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .user-type-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: all 0.6s ease;
        }
        
        .user-type-btn:hover::before {
            left: 100%;
        }
        
        .user-type-btn.active {
            background-color: rgba(243, 156, 18, 0.1);
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: 0 10px 20px -10px rgba(243, 156, 18, 0.3);
        }
        
        .user-type-btn:hover:not(.active) {
            background-color: rgba(255, 255, 255, 0.05);
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, 0.2);
            box-shadow: 0 5px 15px -5px rgba(0, 0, 0, 0.3);
        }
        
        .user-type-btn span {
            display: block;
        }
        
        .user-type-btn .btn-title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .user-type-btn .btn-desc {
            font-size: 12px;
            opacity: 0.7;
        }
        
        /* Form sections */
        .form-section {
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .form-section:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            border-color: rgba(243, 156, 18, 0.3);
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        /* Form inputs */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
        }
        
        @media (min-width: 576px) {
            .form-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        .full-width {
            grid-column: 1 / -1;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            background-color: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 8px;
            color: white;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
        }
        
        .form-control:hover {
            border-color: rgba(243, 156, 18, 0.3);
            background-color: rgba(255, 255, 255, 0.09);
        }
        
        .form-control:focus {
            outline: none;
            border-color: rgba(243, 156, 18, 0.5);
            box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.2);
            background-color: rgba(255, 255, 255, 0.1);
            transform: scale(1.01);
        }
        
        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }
        
        .form-select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 15px center;
    background-size: 16px;
    /* Fix the z-index and positioning */
    position: relative;
    z-index: 100;
}
.form-select option {
    background-color: var(--dark-800) !important;
    color: white !important;
    padding: 10px 15px !important;
    border: none !important;
    font-size: 14px !important;
}

/* Fix for dropdown list container */
.form-select:focus {
    outline: none;
    border-color: rgba(243, 156, 18, 0.5);
    box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.2);
    background-color: rgba(255, 255, 255, 0.1);
    transform: scale(1.01);
    /* Ensure dropdown appears above other elements */
    z-index: 1000;
    position: relative;
}

        
        /* Map container */
        .map-container {
            height: 250px;
            border-radius: 8px;
            overflow: hidden;
            margin-top: 15px;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        
        .location-info {
            margin-top: 15px;
            padding: 12px;
            background-color: rgba(255, 255, 255, 0.07);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        
        .location-info p {
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        /* Submit button */
        .submit-btn {
            display: block;
            width: 100%;
            padding: 15px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .submit-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: all 0.5s ease;
        }
        
        .submit-btn:hover::before {
            left: 100%;
        }
        
        .submit-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2);
        }
        
        .submit-btn:active {
            transform: translateY(0);
            box-shadow: 0 5px 10px -3px rgba(0, 0, 0, 0.3);
        }
        
        .login-link {
            text-align: center;
            margin-top: 20px;
        }
        
        .login-link a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .login-link a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideRight {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        /* Alert messages */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            animation: fadeIn 0.5s ease-out;
        }
        
        .alert-success {
            background-color: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #10b981;
        }
        
        .alert-error {
            background-color: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }
        
        /* Hide elements based on user type */
        .garage-only {
            display: none;
        }
        
        .user-only {
            display: block;
        }
        
        .user-type-garage .garage-only {
            display: block;
        }
        
        .user-type-garage .user-only {
            display: none;
        }
    </style>
    <!-- Leaflet CSS for maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <!-- Particles.js for background animation -->
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
</head>
<body>
    <!-- Particles.js container -->
    <div id="particles-js"></div>
    
    <!-- Enhanced Background Animation -->
    <div class="bg-animation">
        <div class="bg-circle"></div>
        <div class="bg-circle"></div>
        <div class="bg-circle"></div>
        <div class="animated-circle"></div>
        <div class="animated-circle"></div>
    </div>
    
    <div class="container">
        <!-- Left Panel -->
        <div class="left-panel">
            <!-- Pattern Overlay -->
            <div class="pattern-overlay"></div>
            
            <div>
                <!-- Logo -->
                <div class="logo-container">
                    <div class="logo">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="#f39c12">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                            <path d="M9 18V6h4.5a2.5 2.5 0 0 1 0 5H9"></path>
                        </svg>
                    </div>
                    <h1 class="logo-text">পার্কিং লাগবে</h1>
                </div>
                
                <!-- Welcome Text -->
                <div class="welcome-text">
                    <h2>Create Account!</h2>
                    <p>Register to access our parking services and enjoy hassle-free parking with enhanced features and security.</p>
                </div>
            </div>
            
            <!-- Enhanced Car Illustration -->
            <div class="car-illustration">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512" fill="#ffffff">
                    <path d="M171.3 96H224v96H111.3l30.4-75.9C146.5 104 158.2 96 171.3 96zM272 192V96h81.2c9.7 0 18.9 4.4 25 12l67.2 84H272zm256.2 1L428.2 68c-18.2-22.8-45.8-36-75-36H171.3c-39.3 0-74.6 23.9-89.1 60.3L40.6 196.4C16.8 205.8 0 228.9 0 256v112c0 17.7 14.3 32 32 32h33.3c7.6 45.4 47.1 80 94.7 80s87.1-34.6 94.7-80h130.6c7.6 45.4 47.1 80 94.7 80s87.1-34.6 94.7-80H608c17.7 0 32-14.3 32-32V320c0-65.2-48.8-119-111.8-127zM160 432c-26.5 0-48-21.5-48-48s21.5-48 48-48 48 21.5 48 48-21.5 48-48 48zm272 0c-26.5 0-48-21.5-48-48s21.5-48 48-48 48 21.5 48 48-21.5 48-48 48zm48-160H160v-64h320v64z"/>
                </svg>
            </div>
            
            <!-- Features -->
            <div class="features">
                <div class="feature">
                    <div class="feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                    </div>
                    <div class="feature-text">
                        <h4>Easy Access</h4>
                        <p>To all parking facilities</p>
                    </div>
                </div>
                
                <div class="feature">
                    <div class="feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </div>
                    <div class="feature-text">
                        <h4>Mobile App</h4>
                        <p>For quick check-in/out</p>
                    </div>
                </div>
                
                <div class="feature">
                    <div class="feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                        </svg>
                    </div>
                    <div class="feature-text">
                        <h4>Discounted Rates</h4>
                        <p>For regular users</p>
                    </div>
                </div>
                
                <div class="feature">
                    <div class="feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </div>
                    <div class="feature-text">
                        <h4>24/7 Support</h4>
                        <p>Customer assistance</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Right Panel (Form) -->
        <div class="right-panel">
            <div class="form-header">
                <div class="icon-circle">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 4c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm0 14c-2.03 0-4.43-.82-6-2.33 0-1.95 4-3.67 6-3.67s6 1.72 6 3.67c-1.57 1.51-3.97 2.33-6 2.33z"/>
                    </svg>
                </div>
                <h3>Registration Form</h3>
                <p>Fill in your details to create an account</p>
            </div>
            
            <!-- Alert Messages -->
            <?php if(isset($successMessage)): ?>
                <div class="alert alert-success">
                    <?php echo $successMessage; ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($errorMessage)): ?>
                <div class="alert alert-error">
                    <?php echo $errorMessage; ?>
                </div>
            <?php endif; ?>
            
            <!-- User Type Selection -->
            <div class="user-type-selection">
                <button type="button" id="user-btn" class="user-type-btn active" data-type="user">
                    <span class="btn-title">A User Looking for Parking</span>
                    <span class="btn-desc">Find available parking spaces</span>
                </button>
                <button type="button" id="garage-btn" class="user-type-btn" data-type="garage">
                    <span class="btn-title">A Garage Owner</span>
                    <span class="btn-desc">List your parking space</span>
                </button>
            </div>
            
            <form id="registration-form" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST">
                <input type="hidden" id="isGarageOwner" name="isGarageOwner" value="0">
                
                <!-- Personal Information Section -->
                <div class="form-section">
                    <h4 class="section-title">Personal Information</h4>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="firstName" class="form-label">First Name*</label>
                            <input type="text" id="firstName" name="firstName" class="form-control" placeholder="Enter your first name" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="lastName" class="form-label">Last Name*</label>
                            <input type="text" id="lastName" name="lastName" class="form-control" placeholder="Enter your last name" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email" class="form-label">Email Address*</label>
                            <input type="email" id="email" name="email" class="form-control" placeholder="Enter your email address" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone" class="form-label">Phone Number*</label>
                            <input type="tel" id="phone" name="phone" class="form-control" placeholder="Enter your phone number" required>
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="address" class="form-label">Address</label>
                            <input type="text" id="address" name="address" class="form-control" placeholder="Enter your address">
                        </div>
                    </div>
                </div>
                
                <!-- Vehicle Information (Regular User) -->
                <div class="form-section user-only">
                    <h4 class="section-title">Vehicle Information</h4>
                    
                    <div class="form-group">
                        <label for="licensePlate" class="form-label">License Plate Number*</label>
                        <input type="text" id="licensePlate" name="licensePlate" class="form-control" placeholder="e.g., DHA-D-11-1234" required>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="vehicleType" class="form-label">Vehicle Type*</label>
                            <select id="vehicleType" name="vehicleType" class="form-control form-select" required>
                                <option value="" disabled selected>Select vehicle type</option>
                                <option value="sedan">Sedan</option>
                                <option value="suv">SUV</option>
                                <option value="hatchback">Hatchback</option>
                                <option value="truck">Truck</option>
                                <option value="motorcycle">Motorcycle</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="make" class="form-label">Make*</label>
                            <select id="make" name="make" class="form-control form-select" required>
                                <option value="" disabled selected>Select a brand</option>
                                <option value="Toyota">Toyota</option>
                                <option value="Honda">Honda</option>
                                <option value="Nissan">Nissan</option>
                                <option value="BMW">BMW</option>
                                <option value="Mercedes">Mercedes</option>
                                <option value="Ford">Ford</option>
                                <option value="Hyundai">Hyundai</option>
                                <option value="Mitsubishi">Mitsubishi</option>
                                <option value="Suzuki">Suzuki</option>
                                <option value="Kia">Kia</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="model" class="form-label">Model*</label>
                            <input type="text" id="model" name="model" class="form-control" placeholder="Enter model" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="color" class="form-label">Vehicle Color*</label>
                            <input type="text" id="color" name="color" class="form-control" placeholder="Enter vehicle color" required>
                        </div>
                    </div>
                </div>
                
                <!-- Garage Information (Garage Owner) -->
                <div class="form-section garage-only">
                    <h4 class="section-title">Garage Information</h4>
                    
                    <div class="form-group">
                        <label for="garageName" class="form-label">Garage Name*</label>
                        <input type="text" id="garageName" name="garageName" class="form-control" placeholder="Enter name of your garage/parking space">
                    </div>
                    
                    <div class="form-group">
                        <label for="parkingLotAddress" class="form-label">Parking Lot Address*</label>
                        <input type="text" id="parkingLotAddress" name="parkingLotAddress" class="form-control" placeholder="Enter exact address of your parking lot">
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="parkingType" class="form-label">Parking Type*</label>
                            <select id="parkingType" name="parkingType" class="form-control form-select">
                                <option value="" disabled selected>Select parking type</option>
                                <option value="Covered">Covered</option>
                                <option value="Open">Open</option>
                                <option value="Valet">Valet</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="parkingDimensions" class="form-label">Parking Dimensions*</label>
                            <select id="parkingDimensions" name="parkingDimensions" class="form-control form-select">
                                <option value="" disabled selected>Select parking dimensions</option>
                                <option value="Compact">Compact</option>
                                <option value="Standard">Standard</option>
                                <option value="Large">Large</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="garageSlots" class="form-label">Number of Parking Slots*</label>
                            <input type="number" id="garageSlots" name="garageSlots" min="1" class="form-control" placeholder="Enter total number of slots">
                        </div>
                        
                        <div class="form-group">
                            <label for="pricePerHour" class="form-label">Price per Hour (৳)*</label>
                            <input type="number" id="pricePerHour" name="pricePerHour" min="0" step="5" class="form-control" placeholder="Enter hourly rate in ৳">
                        </div>
                    </div>
                    
                    <!-- Map Location Picker -->
                    <div class="form-group">
                        <label class="form-label">Garage Location (Click on map to select)</label>
                        <div id="map" class="map-container"></div>
                        
                        <div id="selected-location" class="location-info" style="display: none;">
                            <p>Selected Location:</p>
                            <p id="selected-lat">Latitude: </p>
                            <p id="selected-lng">Longitude: </p>
                        </div>
                        
                        <input type="hidden" id="latitude" name="latitude" value="">
                        <input type="hidden" id="longitude" name="longitude" value="">
                    </div>
                </div>
                
                <!-- Account Information -->
                <div class="form-section">
                    <h4 class="section-title">Account Information</h4>
                    
                    <div class="form-group">
                        <label for="username" class="form-label">Username*</label>
                        <input type="text" id="username" name="username" class="form-control" placeholder="Choose a username" required>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="password" class="form-label">Password*</label>
                            <input type="password" id="password" name="password" class="form-control" placeholder="Create a password" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirmPassword" class="form-label">Confirm Password*</label>
                            <input type="password" id="confirmPassword" name="confirmPassword" class="form-control" placeholder="Confirm your password" required>
                        </div>
                    </div>
                </div>
                
                <button type="submit" name="submit" class="submit-btn">Create Account</button>
                
                <div class="login-link">
                    <p>Already have an account? <a href="login.php">Login here</a></p>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize particles.js with enhanced configuration and mouse interactions
           particlesJS('particles-js', {
        "particles": {
            "number": {
                "value": 100,
                "density": {
                    "enable": true,
                    "value_area": 800
                }
            },
            "color": {
                "value": ["#f39c12", "#e67e22", "#f1c40f"]  // Multiple colors for variety
            },
            "shape": {
                "type": "circle",
                "stroke": {
                    "width": 0,
                    "color": "#000000"
                },
                "polygon": {
                    "nb_sides": 5
                },
                "image": {
                    "src": "img/github.svg",
                    "width": 100,
                    "height": 100
                }
            },
            "opacity": {
                "value": 0.6,
                "random": true,
                "anim": {
                    "enable": true,
                    "speed": 1,
                    "opacity_min": 0.1,
                    "sync": false
                }
            },
            "size": {
                "value": 4,
                "random": true,
                "anim": {
                    "enable": true,
                    "speed": 2,
                    "size_min": 0.1,
                    "sync": false
                }
            },
            "line_linked": {
                "enable": true,
                "distance": 150,
                "color": "#f39c12",
                "opacity": 0.3,
                "width": 1
            },
            "move": {
                "enable": true,
                "speed": 3,
                "direction": "none",
                "random": true,
                "straight": false,
                "out_mode": "bounce",
                "bounce": true,
                "attract": {
                    "enable": true,
                    "rotateX": 600,
                    "rotateY": 1200
                }
            }
        },
        "interactivity": {
            "detect_on": "canvas",
            "events": {
                "onhover": {
                    "enable": false,  // Disable mouse hover interaction
                    "mode": "grab"
                },
                "onclick": {
                    "enable": true,
                    "mode": "push"
                },
                "resize": true
            },
            "modes": {
                "grab": {
                    "distance": 200,
                    "line_linked": {
                        "opacity": 1
                    }
                },
                "bubble": {
                    "distance": 200,
                    "size": 10,
                    "duration": 2,
                    "opacity": 0.8,
                    "speed": 3
                },
                "repulse": {
                    "distance": 200,
                    "duration": 0.4
                },
                "push": {
                    "particles_nb": 4
                },
                "remove": {
                    "particles_nb": 2
                }
            }
        },
        "retina_detect": true
    });

            // Add hover effects to form inputs
            const formGroups = document.querySelectorAll('.form-group');
            
            // Add hover effect to form groups
            formGroups.forEach(group => {
                group.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                    this.style.transition = 'all 0.3s ease';
                    this.style.borderColor = 'rgba(243, 156, 18, 0.3)';
                    this.style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.2)';
                });
                
                group.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.borderColor = '';
                    this.style.boxShadow = '';
                });
            });
            
            // Add focus effects to inputs
            const inputs = document.querySelectorAll('input[type="text"], input[type="password"], input[type="email"], input[type="tel"], input[type="number"], select');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    const parentGroup = this.closest('.form-group');
                    if (parentGroup) {
                        parentGroup.style.transform = 'scale(1.02)';
                        parentGroup.style.borderColor = 'rgba(243, 156, 18, 0.5)';
                        parentGroup.style.boxShadow = '0 0 0 3px rgba(243, 156, 18, 0.2)';
                    }
                });
                
                input.addEventListener('blur', function() {
                    const parentGroup = this.closest('.form-group');
                    if (parentGroup) {
                        parentGroup.style.transform = '';
                        parentGroup.style.borderColor = '';
                        parentGroup.style.boxShadow = '';
                    }
                });
            });
            
            // Toggle between user and garage owner forms
            const userBtn = document.getElementById('user-btn');
            const garageBtn = document.getElementById('garage-btn');
            const form = document.getElementById('registration-form');
            const isGarageOwnerInput = document.getElementById('isGarageOwner');
            
            // User type toggle
            userBtn.addEventListener('click', function() {
                userBtn.classList.add('active');
                garageBtn.classList.remove('active');
                isGarageOwnerInput.value = '0';
                form.classList.remove('user-type-garage');
                
                // Set required fields for user
                setRequiredFields('user');
            });
            
            garageBtn.addEventListener('click', function() {
                garageBtn.classList.add('active');
                userBtn.classList.remove('active');
                isGarageOwnerInput.value = '1';
                form.classList.add('user-type-garage');
                
                // Set required fields for garage
                setRequiredFields('garage');
                
                // Initialize map when garage owner is selected
                setTimeout(initializeMap, 100);
            });
            
            // Set required fields based on user type
            function setRequiredFields(userType) {
                const userFields = ['licensePlate', 'vehicleType', 'make', 'model', 'color'];
                const garageFields = ['garageName', 'parkingLotAddress', 'parkingType', 'parkingDimensions', 'garageSlots', 'pricePerHour'];
                
                if (userType === 'user') {
                    // Set user fields as required
                    userFields.forEach(field => {
                        document.getElementById(field).setAttribute('required', '');
                    });
                    
                    // Remove required from garage fields
                    garageFields.forEach(field => {
                        document.getElementById(field).removeAttribute('required');
                    });
                } else {
                    // Set garage fields as required
                    garageFields.forEach(field => {
                        document.getElementById(field).setAttribute('required', '');
                    });
                    
                    // Remove required from user fields
                    userFields.forEach(field => {
                        document.getElementById(field).removeAttribute('required');
                    });
                }
            }
            
            // License plate formatter
            const plateInput = document.getElementById('licensePlate');
            if (plateInput) {
                plateInput.addEventListener('input', function(e) {
                    // Get current value and convert to uppercase
                    let value = e.target.value.toUpperCase();
                    
                    // Remove any character that's not a letter, number or hyphen
                    value = value.replace(/[^A-Z0-9\-]/g, '');
                    
                    // Apply the format: XXX-X-NN-NNNN
                    // Where X is letter and N is number
                    
                    // Split by hyphens (if any)
                    let parts = value.split('-').filter(part => part.length > 0);
                    let formatted = '';
                    
                    // First part (area code): 2-3 letters (e.g., DHA, SHA)
                    if (parts.length > 0) {
                        // Extract only letters from the first part
                        let areaCode = parts[0].replace(/[^A-Z]/g, '');
                        // Limit to 3 letters
                        areaCode = areaCode.substring(0, 3);
                        if (areaCode.length > 0) {
                            formatted = areaCode;
                        }
                    }
                    
                    // Second part (series): 1 letter (e.g., D)
                    if (parts.length > 1) {
                        // Extract only the first letter from the second part
                        let series = parts[1].replace(/[^A-Z]/g, '');
                        series = series.substring(0, 1);
                        if (series.length > 0) {
                            formatted += '-' + series;
                        }
                    } else if (formatted.length >= 3) {
                        // If we have a complete area code but no series yet
                        // Extract from remaining characters in the first part
                        let remaining = parts[0].substring(3).replace(/[^A-Z]/g, '');
                        if (remaining.length > 0) {
                            formatted += '-' + remaining.substring(0, 1);
                        }
                    }
                    
                    // Third part (number group 1): 1-2 digits (e.g., 11)
                    if (parts.length > 2) {
                        // Extract only numbers from the third part
                        let numGroup1 = parts[2].replace(/[^0-9]/g, '');
                        // Limit to 2 digits
                        numGroup1 = numGroup1.substring(0, 2);
                        if (numGroup1.length > 0) {
                            formatted += '-' + numGroup1;
                        }
                    } else if (formatted.includes('-') && parts.length > 1) {
                        // If we have area code and series but no number group yet
                        // Extract from remaining characters in the second part
                        let remaining = parts[1].substring(1).replace(/[^0-9]/g, '');
                        if (remaining.length > 0) {
                            formatted += '-' + remaining.substring(0, 2);
                        }
                    }
                    
                    // Fourth part (number group 2): 3-4 digits (e.g., 1234)
                    if (parts.length > 3) {
                        // Extract only numbers from the fourth part
                        let numGroup2 = parts[3].replace(/[^0-9]/g, '');
                        // Limit to 4 digits
                        numGroup2 = numGroup2.substring(0, 4);
                        if (numGroup2.length > 0) {
                            formatted += '-' + numGroup2;
                        }
                    } else if (formatted.includes('-') && formatted.split('-').length > 2) {
                        // If we have area code, series, and first number group
                        // Extract from remaining characters in the third part
                        let remaining = parts[2] ? parts[2].substring(2).replace(/[^0-9]/g, '') : '';
                        if (remaining.length > 0) {
                            formatted += '-' + remaining.substring(0, 4);
                        }
                    }
                    
                    // Update the input value
                    e.target.value = formatted;
                });
            }
            
            // Form validation
            form.addEventListener('submit', function(e) {
                const isGarageOwner = isGarageOwnerInput.value === '1';
                
                if (isGarageOwner) {
                    // Check if location is selected for garage owners
                    const latitude = document.getElementById('latitude').value;
                    const longitude = document.getElementById('longitude').value;
                    
                    if (!latitude || !longitude) {
                        e.preventDefault();
                        alert('Please select your garage location on the map.');
                    }
                }
                
                // Check if passwords match
                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirmPassword').value;
                
                if (password !== confirmPassword) {
                    e.preventDefault();
                    alert('Passwords do not match!');
                }
            });
            
            // Map initialization function
            function initializeMap() {
                // Check if map container exists and map is needed
                const mapContainer = document.getElementById('map');
                const isGarageOwner = isGarageOwnerInput.value === '1';
                
                if (!mapContainer || !isGarageOwner) return;
                
                // Create map centered on Dhaka, Bangladesh
                const map = L.map('map').setView([23.8103, 90.4125], 13);
                
                // Add OpenStreetMap tiles
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);
                
                const selectedLocationDiv = document.getElementById('selected-location');
                const selectedLatElement = document.getElementById('selected-lat');
                const selectedLngElement = document.getElementById('selected-lng');
                const latitudeInput = document.getElementById('latitude');
                const longitudeInput = document.getElementById('longitude');
                
                // Variable for marker
                let marker;
                
                // Try to get user's location for better starting point
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        
                        // Center map on user's location
                        map.setView([lat, lng], 15);
                        
                        // Add marker for current location
                        L.marker([lat, lng]).addTo(map)
                            .bindPopup('Your current location')
                            .openPopup();
                    },
                    function(error) {
                        console.error('Error getting location:', error);
                        // Default view remains on Dhaka
                    }
                );
                
                // Handle clicks on map to set garage location
                map.on('click', function(e) {
                    const lat = e.latlng.lat;
                    const lng = e.latlng.lng;
                    
                    // Remove previous marker if exists
                    if (marker) {
                        map.removeLayer(marker);
                    }
                    
                    // Add new marker
                    marker = L.marker([lat, lng]).addTo(map);
                    marker.bindPopup('Selected garage location').openPopup();
                    
                    // Update UI
                    selectedLatElement.textContent = `Latitude: ${lat.toFixed(6)}`;
                    selectedLngElement.textContent = `Longitude: ${lng.toFixed(6)}`;
                    selectedLocationDiv.style.display = 'block';
                    
                    // Update hidden inputs
                    latitudeInput.value = lat.toFixed(6);
                    longitudeInput.value = lng.toFixed(6);
                });
            }
        });
    </script>
</body>
</html>