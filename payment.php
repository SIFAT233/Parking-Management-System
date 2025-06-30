<?php
// Start the session at the very top
session_start();

// For connecting to database
require_once("connection.php");

// Check if user is logged in, redirect to login page if not
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Get user information from database
$username = $_SESSION['username'];
$fullName = $username; // Default to username
$email = ""; // Default empty email
$userPoints = 0; // Default points

// Try to get user's personal information and points
$query = "SELECT * FROM personal_information WHERE email LIKE '%$username%' OR firstName LIKE '%$username%' OR lastName LIKE '%$username%'";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $fullName = $row['firstName'] . ' ' . $row['lastName'];
    $email = $row['email'];
    
    // Store in session for future use
    $_SESSION['fullName'] = $fullName;
    $_SESSION['email'] = $email;
} else {
    // Set defaults in session
    $_SESSION['fullName'] = $username;
    $_SESSION['email'] = "";
}

// Get user's current points
$pointsQuery = "SELECT points FROM account_information WHERE username = ?";
$pointsStmt = $conn->prepare($pointsQuery);
$pointsStmt->bind_param("s", $username);
$pointsStmt->execute();
$pointsResult = $pointsStmt->get_result();
if ($pointsResult && $pointsResult->num_rows > 0) {
    $pointsRow = $pointsResult->fetch_assoc();
    $userPoints = $pointsRow['points'];
}

// Get first letter for avatar
$firstLetter = strtoupper(substr($fullName, 0, 1));

// Get booking information from database
$bookingId = isset($_GET['booking_id']) ? $_GET['booking_id'] : ''; // Don't default to any specific ID
$bookingData = null;
$numericId = str_replace("BK", "", $bookingId);

// Get booking details from database - using the correct table structure
if (is_numeric($numericId)) {
    $bookingQuery = "SELECT b.*, g.Parking_Space_Name, g.Parking_Lot_Address, g.PriceperHour, v.licensePlate, v.vehicleType, v.make, v.model, v.color 
                    FROM bookings b 
                    LEFT JOIN garage_information g ON b.garage_id = g.garage_id 
                    LEFT JOIN vehicle_information v ON b.licenseplate = v.licensePlate 
                    WHERE b.id = ?";
    
    $stmt = $conn->prepare($bookingQuery);
    $stmt->bind_param("i", $numericId);
    $stmt->execute();
    $bookingResult = $stmt->get_result();
    
    if ($bookingResult && $bookingResult->num_rows > 0) {
        $bookingData = $bookingResult->fetch_assoc();
    }
}

// CORRECTED FUNCTION: Get owner commission rate for information purposes only
function getOwnerCommissionRate($conn, $garageOwnerUsername) {
    $commissionRate = 30.00; // Default commission rate
    
    // First check if owner is in garage_owners table
    $ownerQuery = "SELECT owner_id FROM garage_owners WHERE username = ?";
    $ownerStmt = $conn->prepare($ownerQuery);
    $ownerStmt->bind_param("s", $garageOwnerUsername);
    $ownerStmt->execute();
    $ownerResult = $ownerStmt->get_result();
    
    if ($ownerResult && $ownerResult->num_rows > 0) {
        $ownerRow = $ownerResult->fetch_assoc();
        $ownerId = $ownerRow['owner_id'];
        
        // Get commission rate for garage owner
        $commissionQuery = "SELECT rate FROM owner_commissions WHERE owner_id = ? AND owner_type = 'garage'";
        $commissionStmt = $conn->prepare($commissionQuery);
        $commissionStmt->bind_param("s", $ownerId);
        $commissionStmt->execute();
        $commissionResult = $commissionStmt->get_result();
        
        if ($commissionResult && $commissionResult->num_rows > 0) {
            $commissionRow = $commissionResult->fetch_assoc();
            $commissionRate = $commissionRow['rate'];
        }
    } else {
        // Check if owner is in dual_user table
        $dualUserQuery = "SELECT owner_id FROM dual_user WHERE username = ?";
        $dualUserStmt = $conn->prepare($dualUserQuery);
        $dualUserStmt->bind_param("s", $garageOwnerUsername);
        $dualUserStmt->execute();
        $dualUserResult = $dualUserStmt->get_result();
        
        if ($dualUserResult && $dualUserResult->num_rows > 0) {
            $dualUserRow = $dualUserResult->fetch_assoc();
            $ownerId = $dualUserRow['owner_id'];
            
            // Get commission rate for dual user
            $commissionQuery = "SELECT rate FROM owner_commissions WHERE owner_id = ? AND owner_type = 'dual'";
            $commissionStmt = $conn->prepare($commissionQuery);
            $commissionStmt->bind_param("s", $ownerId);
            $commissionStmt->execute();
            $commissionResult = $commissionStmt->get_result();
            
            if ($commissionResult && $commissionResult->num_rows > 0) {
                $commissionRow = $commissionResult->fetch_assoc();
                $commissionRate = $commissionRow['rate'];
            }
        }
    }
    
    return $commissionRate;
}

// If no booking data found or booking ID is not numeric, try to find the user's most recent booking
if (!$bookingData) {
    // Try to get the user's most recent booking as a fallback
    $userBookingQuery = "SELECT b.*, g.Parking_Space_Name, g.Parking_Lot_Address, g.PriceperHour, v.licensePlate, v.vehicleType, v.make, v.model, v.color 
                        FROM bookings b 
                        LEFT JOIN garage_information g ON b.garage_id = g.garage_id 
                        LEFT JOIN vehicle_information v ON b.licenseplate = v.licensePlate 
                        WHERE b.username = ? 
                        ORDER BY b.created_at DESC LIMIT 1";
    
    $userStmt = $conn->prepare($userBookingQuery);
    $userStmt->bind_param("s", $username);
    $userStmt->execute();
    $userBookingResult = $userStmt->get_result();
    
    if ($userBookingResult && $userBookingResult->num_rows > 0) {
        $bookingData = $userBookingResult->fetch_assoc();
        // Keep the original booking ID if it was provided, otherwise use the found booking ID
        if (empty($bookingId)) {
            $bookingId = "BK" . $bookingData['id'];
        }
    } else {
        // Last resort fallback to default values - but keep the original booking ID if provided
        if (empty($bookingId)) {
            $bookingId = "BK0"; // Default booking ID if none provided
        }
        
        // Try to get any available garage information
        $garageQuery = "SELECT * FROM garage_information ORDER BY id ASC LIMIT 1";
        $garageResult = $conn->query($garageQuery);
        
        if ($garageResult && $garageResult->num_rows > 0) {
            $garageData = $garageResult->fetch_assoc();
            
            // Use garage data for display - CORRECTED: Customer pays only parking fee
            $locationName = $garageData['Parking_Space_Name'];
            $locationAddress = $garageData['Parking_Lot_Address'];
            $parkingFee = $garageData['PriceperHour'] * 1; // For 1 hour
            
            // CORRECTED: No additional service fee charged to customer
            $serviceFee = 0;
            $totalAmount = $parkingFee; // Customer pays only parking fee
            
            $duration = "1 hour";
            $vehiclePlate = "Not specified";
        } else {
            // Absolute last resort - generic values
            $locationName = "Unknown Location";
            $locationAddress = "Address not available";
            $parkingFee = 35;
            
            // CORRECTED: No additional service fee
            $serviceFee = 0;
            $totalAmount = $parkingFee; // Customer pays only parking fee
            
            $duration = "1 hour";
            $vehiclePlate = "Not specified";
        }
    }
}

// If we have booking data, extract the information
if ($bookingData) {
    // Extract booking information from database result
    if (empty($bookingId)) {
        $bookingId = "BK" . $bookingData['id'];
    }
    $locationName = $bookingData['Parking_Space_Name'];
    $locationAddress = $bookingData['Parking_Lot_Address'];
    $duration = $bookingData['duration'] . " hours";
    
    // Format vehicle info
    if (!empty($bookingData['make']) && !empty($bookingData['model'])) {
        $vehiclePlate = $bookingData['make'] . ' ' . $bookingData['model'];
        if (!empty($bookingData['color'])) {
            $vehiclePlate .= ' (' . $bookingData['color'] . ')';
        }
    } else if (!empty($bookingData['licensePlate'])) {
        $vehiclePlate = 'License Plate: ' . $bookingData['licensePlate'];
    } else {
        $vehiclePlate = "Not provided";
    }
    
    // CORRECTED: Customer pays only the parking fee
    $parkingFee = $bookingData['PriceperHour'] * $bookingData['duration'];
    
    // Get garage owner username for commission rate information
    $garageOwnerUsername = '';
    $garageInfoQuery = "SELECT username FROM garage_information WHERE garage_id = ?";
    $garageInfoStmt = $conn->prepare($garageInfoQuery);
    $garageInfoStmt->bind_param("s", $bookingData['garage_id']);
    $garageInfoStmt->execute();
    $garageInfoResult = $garageInfoStmt->get_result();
    
    if ($garageInfoResult && $garageInfoResult->num_rows > 0) {
        $garageInfoRow = $garageInfoResult->fetch_assoc();
        $garageOwnerUsername = $garageInfoRow['username'];
    }
    
    // Get commission rate for display purposes (optional)
    $commissionRate = getOwnerCommissionRate($conn, $garageOwnerUsername);
    
    // CORRECTED: Customer pays only parking fee - no additional service fee
    $serviceFee = 0; // No service fee charged to customer
    $totalAmount = $parkingFee; // Customer pays only the parking fee
    
    // For display purposes, calculate the breakdown (not charged to customer)
    $platformProfit = round(($parkingFee * $commissionRate) / 100, 2);
    $ownerProfit = round($parkingFee - $platformProfit, 2);
}

// Process payment (demo)
$paymentSuccess = false;
$paymentError = "";
$validationError = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_method'])) {
    // Store the current booking details to session BEFORE processing payment
    $_SESSION['booking_id'] = $bookingId;
    $_SESSION['location_name'] = $locationName;
    $_SESSION['location_address'] = $locationAddress;
    $_SESSION['duration'] = $duration;
    $_SESSION['vehicle_plate'] = $vehiclePlate;
    $_SESSION['parking_fee'] = $parkingFee;
    $_SESSION['service_fee'] = $serviceFee; // This will be 0
    $_SESSION['payment_amount'] = $totalAmount; // This equals parking fee
    
    // Get payment method
    $paymentMethod = $_POST['payment_method'];
    
    // Validate payment details based on method
    $isValid = true;
    
    if ($paymentMethod === 'points') {
    // Handle points payment
    $durationHours = 1; // Default
    if ($bookingData) {
        $durationHours = isset($bookingData['duration']) ? $bookingData['duration'] : 1;
    }
    
    $pointsPerHour = 150;
    $pointsToUse = $durationHours * $pointsPerHour;
    
    // Use the stored procedure for points payment
    $pointsPaymentQuery = "CALL use_points_for_payment(?, ?, ?, ?)";
    $pointsStmt = $conn->prepare($pointsPaymentQuery);
    $description = "Points payment for booking #{$numericId} - {$durationHours} hour" . ($durationHours > 1 ? 's' : '') . " parking";
    $pointsStmt->bind_param("siis", $username, $numericId, $pointsToUse, $description);
    
    if ($pointsStmt->execute()) {
        // Update user points in our local variable
        $userPoints -= $pointsToUse;
        
        // Store points info in session
        $_SESSION['points_used'] = $pointsToUse;
        $_SESSION['payment_method'] = 'points';
        $_SESSION['transaction_id'] = 'PTS_' . date('YmdHis') . rand(1000, 9999);
        $_SESSION['points_covered_hours'] = $durationHours;
        $_SESSION['points_payment_amount'] = $totalAmount; // The actual money saved
        
        $paymentSuccess = true;
    } else {
        $paymentError = "Error processing points payment";
        $paymentSuccess = false;
    }
} else if ($paymentMethod === 'bkash') {
        if (!isset($_POST['bkash_number']) || empty($_POST['bkash_number'])) {
            $validationError = "Please enter your bKash mobile number";
            $isValid = false;
        } else {
            $bkashNumber = $_POST['bkash_number'];
            // Basic validation for Bangladeshi phone number (11 digits starting with 01)
            if (!preg_match('/^01[0-9]{9}$/', $bkashNumber)) {
                $validationError = "Invalid bKash number. Please enter a valid 11-digit Bangladeshi number";
                $isValid = false;
            }
        }
    } else if ($paymentMethod === 'nagad') {
        if (!isset($_POST['nagad_number']) || empty($_POST['nagad_number'])) {
            $validationError = "Please enter your Nagad mobile number";
            $isValid = false;
        } else {
            $nagadNumber = $_POST['nagad_number'];
            // Basic validation for Bangladeshi phone number (11 digits starting with 01)
            if (!preg_match('/^01[0-9]{9}$/', $nagadNumber)) {
                $validationError = "Invalid Nagad number. Please enter a valid 11-digit Bangladeshi number";
                $isValid = false;
            }
        }
    } else if ($paymentMethod === 'card') {
        // Validate card details
        if (!isset($_POST['card_number']) || empty($_POST['card_number']) ||
            !isset($_POST['card_holder']) || empty($_POST['card_holder']) ||
            !isset($_POST['card_expiry']) || empty($_POST['card_expiry']) ||
            !isset($_POST['card_cvv']) || empty($_POST['card_cvv'])) {
            $validationError = "Please fill in all card details";
            $isValid = false;
        } else {
            $cardNumber = str_replace(' ', '', $_POST['card_number']);
            // Basic card number validation (should be 16 digits)
            if (!preg_match('/^[0-9]{16}$/', $cardNumber)) {
                $validationError = "Invalid card number. Please enter a valid 16-digit card number";
                $isValid = false;
            }
            
            // Basic CVV validation (should be 3 or 4 digits)
            $cardCVV = $_POST['card_cvv'];
            if (!preg_match('/^[0-9]{3,4}$/', $cardCVV)) {
                $validationError = "Invalid CVV. Please enter a valid 3 or 4 digit security code";
                $isValid = false;
            }
            
            // Basic expiry date validation (should be in format MM/YY)
            $cardExpiry = $_POST['card_expiry'];
            if (!preg_match('/^(0[1-9]|1[0-2])\/[0-9]{2}$/', $cardExpiry)) {
                $validationError = "Invalid expiry date. Please use format MM/YY";
                $isValid = false;
            } else {
                // Check if card is expired
                list($month, $year) = explode('/', $cardExpiry);
                $expiry = \DateTime::createFromFormat('my', $month . $year);
                $now = new \DateTime();
                if ($expiry < $now) {
                    $validationError = "This card has expired. Please use a different card";
                    $isValid = false;
                }
            }
        }
    }
    
    // If validation passes, process the payment
    if ($isValid) {
        // For demo purposes, always succeed
        $paymentSuccess = true;
        
        // If we have a real booking and booking ID is numeric, update its payment status  
        if (is_numeric(str_replace("BK", "", $bookingId))) {
            $numericId = str_replace("BK", "", $bookingId);
            
            if ($paymentMethod === 'points') {
                // Handle points payment
                $pointsToUse = intval($_POST['points_amount']);
                
                // Use the stored procedure for points payment
                $pointsPaymentQuery = "CALL use_points_for_payment(?, ?, ?, ?)";
                $pointsStmt = $conn->prepare($pointsPaymentQuery);
                $description = "Payment for booking #" . $numericId;
                $pointsStmt->bind_param("siis", $username, $numericId, $pointsToUse, $description);
                
                if ($pointsStmt->execute()) {
                    // Update user points in our local variable
                    $userPoints -= $pointsToUse;
                    
                    // Store points info in session
                    $_SESSION['points_used'] = $pointsToUse;
                    $_SESSION['payment_method'] = 'points';
                    $_SESSION['transaction_id'] = 'PTS_' . date('YmdHis') . rand(1000, 9999);
                } else {
                    $paymentError = "Error processing points payment";
                    $paymentSuccess = false;
                }
            } else {
                // Handle regular payment methods
                $transactionId = "TRX" . date('YmdHis') . rand(1000, 9999);
                
                // First check if the payments table exists, if not create it
                $checkTableQuery = "SHOW TABLES LIKE 'payments'";
                $tableResult = $conn->query($checkTableQuery);
                
                if ($tableResult->num_rows == 0) {
                    // Create the payments table
                    $createTableQuery = "CREATE TABLE `payments` (
                      `payment_id` int(11) NOT NULL AUTO_INCREMENT,
                      `booking_id` int(11) NOT NULL,
                      `transaction_id` varchar(50) NOT NULL,
                      `amount` decimal(10,2) NOT NULL,
                      `payment_method` varchar(20) NOT NULL,
                      `payment_status` enum('pending','paid','refunded') NOT NULL DEFAULT 'pending',
                      `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
                      PRIMARY KEY (`payment_id`),
                      KEY `booking_id` (`booking_id`)
                    )";
                    $conn->query($createTableQuery);
                    
                    // Add foreign key after table creation
                    $addForeignKeyQuery = "ALTER TABLE `payments` 
                                         ADD CONSTRAINT `payments_ibfk_1` 
                                         FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) 
                                         ON DELETE CASCADE ON UPDATE CASCADE";
                    $conn->query($addForeignKeyQuery);
                }
                
                // CORRECTED: Insert payment record with only parking fee amount
                $insertPaymentQuery = "INSERT INTO payments (booking_id, transaction_id, amount, payment_method, payment_status) 
                                     VALUES (?, ?, ?, ?, 'paid')";
                $paymentStmt = $conn->prepare($insertPaymentQuery);
                $paymentStmt->bind_param("isds", $numericId, $transactionId, $totalAmount, $paymentMethod);
                
                if ($paymentStmt->execute()) {
                    // Also update the booking's payment_status in the bookings table
                    $updateQuery = "UPDATE bookings SET payment_status = 'paid' WHERE id = ?";
                    $stmt = $conn->prepare($updateQuery);
                    $stmt->bind_param("i", $numericId);
                    
                    if ($stmt->execute()) {
                        // Now update garage availability to 0 (not available)
                        if (isset($bookingData['garage_id'])) {
                            $garageId = $bookingData['garage_id'];
                            
                            // First check if the booking time is current or upcoming
                            $bookingDate = $bookingData['booking_date'];
                            $bookingTime = $bookingData['booking_time'];
                            $duration = $bookingData['duration'];
                            $bookingEndDateTime = strtotime($bookingDate . ' ' . $bookingTime) + ($duration * 3600);
                            $currentDateTime = time();
                            
                            // Only mark as unavailable if the booking end time is in the future
                            if ($bookingEndDateTime > $currentDateTime) {
                                $updateGarageQuery = "UPDATE garage_information SET Availability = 0 WHERE garage_id = ?";
                                $garageStmt = $conn->prepare($updateGarageQuery);
                                $garageStmt->bind_param("s", $garageId);
                                $garageStmt->execute();
                            }
                        }
                    }
                }
                
                // Store transaction ID in session
                $_SESSION['transaction_id'] = $transactionId;
                $_SESSION['payment_method'] = $paymentMethod;
            }
            
            // Add booking date and time to session
            if (isset($bookingData['booking_date']) && isset($bookingData['booking_time'])) {
                $bookingDate = date('d M Y', strtotime($bookingData['booking_date']));
                $startTime = date('h:i A', strtotime($bookingData['booking_time']));
                $endTime = date('h:i A', strtotime($bookingData['booking_time'] . " + {$bookingData['duration']} hours"));
                $bookingTime = $startTime . " - " . $endTime;
                
                $_SESSION['booking_date'] = $bookingDate;
                $_SESSION['booking_time'] = $bookingTime;
            }
            
            // Redirect to success page after 2 seconds
            if ($paymentSuccess) {
                header("refresh:2;url=payment_success.php");
            }
        }
    }
}
// Calculate points needed for this booking
$durationHours = 1; // Default duration
if ($bookingData) {
    $durationHours = isset($bookingData['duration']) ? $bookingData['duration'] : 1;
}

$pointsPerHour = 150; // 150 points = 1 hour parking
$pointsNeeded = $durationHours * $pointsPerHour;
$canUsePoints = ($userPoints >= $pointsNeeded);
$pointsDisabledClass = $canUsePoints ? '' : 'opacity-50 cursor-not-allowed';
$pointsDisabledAttr = $canUsePoints ? '' : 'disabled';

// Simple function to get level icon based on total earned points
function getUserLevelIcon($username, $conn) {
    // Get total earned points for this user
    $query = "SELECT COALESCE(SUM(points_amount), 0) as total_earned 
              FROM points_transactions 
              WHERE username = ? AND transaction_type = 'earned'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $totalEarned = 0;
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $totalEarned = (int)$row['total_earned'];
    }
    
    // Determine level icon based on earned points
    if ($totalEarned >= 161) {
        return '💎'; // Diamond
    } elseif ($totalEarned >= 100) {
        return '🏆'; // Gold  
    } elseif ($totalEarned >= 15) {
        return '⭐'; // Bronze
    } else {
        return ''; // No icon for less than 15 earned
    }
}

// Get level icon for current user
$levelIcon = getUserLevelIcon($username, $conn);

// Function to get level name for tooltip/display
function getUserLevelName($username, $conn) {
    $query = "SELECT COALESCE(SUM(points_amount), 0) as total_earned 
              FROM points_transactions 
              WHERE username = ? AND transaction_type = 'earned'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $totalEarned = 0;
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $totalEarned = (int)$row['total_earned'];
    }
    
    if ($totalEarned >= 161) {
        return 'Diamond';
    } elseif ($totalEarned >= 100) {
        return 'Gold';
    } elseif ($totalEarned >= 15) {
        return 'Bronze';
    } else {
        return 'New User';
    }
}

$levelName = getUserLevelName($username, $conn);
?>

<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment - Car Parking System</title>
    <!-- Tailwind CSS and daisyUI -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.7.3/dist/full.min.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    primary: '#f39c12',
                    'primary-dark': '#e67e22',
                },
                keyframes: {
                    fadeIn: {
                        'from': { opacity: '0', transform: 'translateY(20px)' },
                        'to': { opacity: '1', transform: 'translateY(0)' }
                    },
                    pulse: {
                        '0%, 100%': { opacity: 1 },
                        '50%': { opacity: 0.5 }
                    }
                },
                animation: {
                    fadeIn: 'fadeIn 1.2s ease-out',
                    pulse: 'pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite'
                }
            }
        }
    }
    </script>
</head>
<body class="relative min-h-screen bg-[#0a0a14]">
    <!-- Header -->
    <header class="sticky top-0 z-50 bg-black/50 backdrop-blur-md border-b border-white/20">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <a href="#" class="flex items-center gap-4 text-white">
                <div class="w-10 h-10 bg-primary rounded-full flex justify-center items-center overflow-hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><path d="M9 18V6h4.5a2.5 2.5 0 0 1 0 5H9"></path></svg>
                </div>
                <h1 class="text-xl font-semibold drop-shadow-md">Car Parking System</h1>
            </a>
            
            <nav class="hidden md:block">
                <ul class="flex gap-8">
                    <li><a href="home.php" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Home</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Garage Location</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Pricing</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">How It Works</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Contact</a></li>
                </ul>
            </nav>
            
            <div class="hidden md:flex items-center gap-4">
                <a href="home.php" class="btn btn-outline btn-sm text-white border-primary hover:bg-primary hover:border-primary">
                    Back To Home
                </a>
                
                <!-- Profile Dropdown with Dynamic Data -->
                <div class="relative">
                    <div class="dropdown dropdown-end">
                        <div tabindex="0" role="button" class="btn btn-circle avatar">
                            <div class="w-10 h-10 rounded-full bg-primary/20 border-2 border-primary overflow-hidden flex items-center justify-center cursor-pointer">
                                <span class="text-xl font-bold text-primary"><?php echo $firstLetter; ?></span>
                            </div>
                        </div>
                        <ul tabindex="0" class="dropdown-content z-[1] menu p-0 shadow bg-base-100 rounded-box w-72 mt-2">
                            <!-- Profile Header -->
                            <li class="p-4 bg-gradient-to-r from-primary/10 to-primary/5 rounded-t-box border-b border-base-300">
                                <div class="flex items-start gap-3 hover:bg-transparent cursor-default w-full">
                                    <div class="w-12 h-12 rounded-full bg-primary/20 border-2 border-primary overflow-hidden flex items-center justify-center flex-shrink-0">
                                        <span class="text-lg font-bold text-primary"><?php echo $firstLetter; ?></span>
                                    </div>
                                    <div class="flex-1 min-w-0 w-full overflow-hidden">
<div class="font-semibold text-base-content text-sm leading-tight mb-1 truncate flex items-center gap-1">
        <?php echo htmlspecialchars($fullName); ?>
        <?php if ($levelIcon): ?>
            <span title="<?php echo $levelName; ?> Level"><?php echo $levelIcon; ?></span>
        <?php endif; ?>
    </div>                                        <div class="text-xs text-base-content/60 leading-tight break-words max-w-full overflow-wrap-anywhere"><?php echo htmlspecialchars($email); ?></div>
                                    </div>
                                </div>
                            </li>
                            
                            <!-- Menu Items -->
                            <div class="p-2">
                                <li><a href="my_profile.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-base-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                    My Profile
                                </a></li>
                                <li><a href="booking.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-base-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                    My Bookings
                                </a></li>
                                <li><a href="myvehicles.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-base-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                    </svg>
                                    My Vehicles
                                </a></li>
                                <li><a href="payment_history.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-base-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                                    </svg>
                                    Payment History
                                </a></li>
                                
                                <!-- Divider -->
                                <div class="divider my-2"></div>
                                
                                <li><a href="logout.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-error/10 hover:text-error">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                    </svg>
                                    Logout
                                </a></li>
                            </div>
                        </ul>
                    </div>
                </div>
            </div>
            
            <button class="md:hidden btn btn-ghost text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
        </div>
    </header>
    
    <!-- Main Content -->
    <main class="container mx-auto px-4 py-10">
        <!-- Page Header -->
        <section class="flex flex-col md:flex-row justify-between items-center mb-8">
            <div>
                <h2 class="text-3xl md:text-4xl font-bold text-white mb-2">Payment</h2>
                <p class="text-white/80">Complete your parking reservation payment</p>
            </div>
            
            <a href="#" class="btn btn-outline text-white border-white/30 hover:bg-white/10 hover:border-white mt-4 md:mt-0" onclick="history.back(); return false;">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>
                Back
            </a>
        </section>
        
        <?php if ($paymentSuccess): ?>
        <div class="alert alert-success mb-6 animate-pulse">
            <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <div>
                <h3 class="font-bold">Payment Successful!</h3>
                <div class="text-sm">Your payment has been processed. Redirecting to confirmation page...</div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($paymentError)): ?>
        <div class="alert alert-error mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <span><?php echo $paymentError; ?></span>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($validationError)): ?>
        <div class="alert alert-warning mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
            <span><?php echo $validationError; ?></span>
        </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Payment Options -->
            <div class="lg:col-span-2">
                <div class="bg-[#0f1122]/90 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl">
                    <h3 class="text-white text-xl font-semibold mb-6">Select Payment Option</h3>
                    
                    <form action="payment.php?booking_id=<?php echo $bookingId; ?>" method="POST" id="paymentForm">
                        <input type="hidden" name="booking_id" value="<?php echo $bookingId; ?>">
                        
                        <!-- Main Payment Options -->
                        <div class="grid grid-cols-4 gap-4 mb-8">
                            <!-- bKash -->
                            <label class="cursor-pointer">
                                <input type="radio" name="payment_method" value="bkash" class="hidden peer" checked>
                                <div class="bg-[#1a1f37] border-2 border-transparent rounded-lg p-4 text-center transition-all peer-checked:border-primary">
                                    <div class="w-20 h-20 mx-auto mb-3 flex items-center justify-center">
                                        <img src="https://www.bkash.com/sites/all/themes/bkash/logo.png" alt="bKash" class="max-w-full max-h-full">
                                    </div>
                                    <p class="text-white font-medium">bKash</p>
                                </div>
                            </label>
                            
                            <!-- Nagad -->
                            <label class="cursor-pointer">
                                <input type="radio" name="payment_method" value="nagad" class="hidden peer">
                                <div class="bg-[#1a1f37] border-2 border-transparent rounded-lg p-4 text-center transition-all peer-checked:border-primary">
                                    <div class="w-20 h-20 mx-auto mb-3 flex items-center justify-center">
                                        <img src="https://www.logo.wine/a/logo/Nagad/Nagad-Logo.wine.svg" alt="Nagad" class="max-w-full max-h-full">
                                    </div>
                                    <p class="text-white font-medium">Nagad</p>
                                </div>
                            </label>
                            
                            <!-- Bank Card -->
                            <label class="cursor-pointer">
                                <input type="radio" name="payment_method" value="card" class="hidden peer">
                                <div class="bg-[#1a1f37] border-2 border-transparent rounded-lg p-4 text-center transition-all peer-checked:border-primary">
                                    <div class="w-20 h-20 mx-auto mb-3 flex items-center justify-center">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/5/5e/Visa_Inc._logo.svg/2560px-Visa_Inc._logo.svg.png" alt="VISA Card" class="max-w-full max-h-full">
                                    </div>
                                    <p class="text-white font-medium">Bank Card</p>
                                </div>
                            </label>
                            <!-- Points Payment -->
<label class="cursor-pointer <?php echo $pointsDisabledClass; ?>">
    <input type="radio" name="payment_method" value="points" class="hidden peer" <?php echo $pointsDisabledAttr; ?>>
    <div class="bg-[#1a1f37] border-2 border-transparent rounded-lg p-4 text-center transition-all peer-checked:border-primary <?php echo $canUsePoints ? '' : 'bg-gray-700'; ?>">
        <div class="w-20 h-20 mx-auto mb-3 flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1" />
            </svg>
        </div>
        <p class="text-white font-medium">Points</p>
        <p class="text-xs text-primary mt-1"><?php echo $userPoints; ?> available</p>
        <?php if (!$canUsePoints): ?>
            <p class="text-xs text-red-400 mt-1">Need <?php echo $pointsNeeded; ?> pts</p>
        <?php else: ?>
            <p class="text-xs text-green-400 mt-1">Covers <?php echo $durationHours; ?>h parking</p>
        <?php endif; ?>
    </div>
</label>
                        </div>
                        
                        <!-- Payment method specific fields -->
                        <div id="bkashFields" class="mb-6">
                            <div class="form-control w-full">
                                <label class="label">
                                    <span class="label-text text-white">bKash Mobile Number <span class="text-red-500">*</span></span>
                                </label>
                                <input type="text" name="bkash_number" placeholder="01XXXXXXXXX" class="input input-bordered w-full" required />
                                <label class="label">
                                    <span class="label-text-alt text-white/60">Enter the mobile number registered with bKash</span>
                                </label>
                            </div>
                        </div>
                        
                        <div id="nagadFields" class="mb-6 hidden">
                            <div class="form-control w-full">
                                <label class="label">
                                    <span class="label-text text-white">Nagad Mobile Number <span class="text-red-500">*</span></span>
                                </label>
                                <input type="text" name="nagad_number" placeholder="01XXXXXXXXX" class="input input-bordered w-full" />
                                <label class="label">
                                    <span class="label-text-alt text-white/60">Enter the mobile number registered with Nagad</span>
                                </label>
                            </div>
                        </div>
                        
                        <div id="cardFields" class="mb-6 hidden">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div class="form-control w-full">
                                    <label class="label">
                                        <span class="label-text text-white">Card Number <span class="text-red-500">*</span></span>
                                    </label>
                                    <input type="text" name="card_number" placeholder="XXXX XXXX XXXX XXXX" class="input input-bordered w-full" />
                                </div>
                                <div class="form-control w-full">
                                    <label class="label">
                                        <span class="label-text text-white">Cardholder Name <span class="text-red-500">*</span></span>
                                    </label>
                                    <input type="text" name="card_holder" placeholder="Name on card" class="input input-bordered w-full" />
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="form-control w-full">
                                    <label class="label">
                                        <span class="label-text text-white">Expiry Date <span class="text-red-500">*</span></span>
                                    </label>
                                    <input type="text" name="card_expiry" placeholder="MM/YY" class="input input-bordered w-full" />
                                </div>
                                <div class="form-control w-full">
                                    <label class="label">
                                        <span class="label-text text-white">CVV <span class="text-red-500">*</span></span>
                                    </label>
                                    <input type="text" name="card_cvv" placeholder="XXX" class="input input-bordered w-full" />
                                </div>
                            </div>
                        </div>
                        <!-- Add this HTML section after the cardFields div and before the Payment Channels section -->
<!-- Updated Points Fields HTML -->
<!-- ADD THIS after the cardFields div (around line 370): -->
<div id="pointsFields" class="mb-6 hidden">
    <div class="bg-primary/10 border border-primary/30 rounded-lg p-4 mb-4">
        <div class="flex items-center gap-2 mb-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p class="text-white font-medium">Points Payment Information</p>
        </div>
        <p class="text-white/80 text-sm">
            • 150 points = 1 hour of parking time<br>
            • Required for this booking: <span class="text-primary font-semibold"><?php echo $pointsNeeded; ?> points</span> (<?php echo $durationHours; ?> hour<?php echo $durationHours > 1 ? 's' : ''; ?>)<br>
            • Available: <span class="text-primary font-semibold"><?php echo $userPoints; ?> points</span><br>
            • This will cover the <strong>full parking fee</strong> of ৳<?php echo $totalAmount; ?>
        </p>
    </div>
    
    <!-- Hidden input since points amount is fixed based on duration -->
    <input type="hidden" name="points_amount" value="<?php echo $pointsNeeded; ?>" />
    
    <div class="bg-green-900/30 border border-green-500/30 rounded-lg p-4">
        <div class="flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p class="text-green-300 font-medium">
                Using <?php echo $pointsNeeded; ?> points for <?php echo $durationHours; ?> hour<?php echo $durationHours > 1 ? 's' : ''; ?> parking
            </p>
        </div>
        <p class="text-green-400 text-sm mt-1">
            Remaining after payment: <?php echo ($userPoints - $pointsNeeded); ?> points
        </p>
    </div>
</div>
                        <!-- Payment Channels -->
                        <div class="mb-8">
                            <h4 class="text-white/80 text-sm font-medium mb-4 flex items-center">
                                <span>Payment Channels</span>
                                <span class="ml-auto text-xs text-white/50">Powered by <span class="text-white">SSL Commerz</span></span>
                            </h4>
                            
                            <!-- Credit/Debit Cards -->
                            <div class="mb-4">
                                <p class="text-white/60 text-xs mb-2">CREDIT/DEBIT CARDS</p>
                                <div class="grid grid-cols-6 gap-2">
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/5/5e/Visa_Inc._logo.svg/2560px-Visa_Inc._logo.svg.png" alt="Visa" class="h-6">
                                    </div>
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/2/2a/Mastercard-logo.svg/1280px-Mastercard-logo.svg.png" alt="Mastercard" class="h-6">
                                    </div>
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/f/fa/American_Express_logo_%282018%29.svg/1200px-American_Express_logo_%282018%29.svg.png" alt="American Express" class="h-6">
                                    </div>
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/4/40/JCB_logo.svg/2560px-JCB_logo.svg.png" alt="JCB" class="h-6">
                                    </div>
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/8/80/Maestro_2016.svg/1200px-Maestro_2016.svg.png" alt="Maestro" class="h-6">
                                    </div>
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/1/16/Former_Visa_logo.svg/2560px-Former_Visa_logo.svg.png" alt="Visa Electron" class="h-6">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Mobile Banking -->
                            <div class="mb-4">
                                <p class="text-white/60 text-xs mb-2">MOBILE BANKING</p>
                                <div class="grid grid-cols-6 gap-2">
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://www.bkash.com/sites/all/themes/bkash/logo.png" alt="bKash" class="h-6">
                                    </div>
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://www.logo.wine/a/logo/Nagad/Nagad-Logo.wine.svg" alt="Nagad" class="h-6">
                                    </div>
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://www.dutchbanglabank.com/img/rocket.png" alt="Rocket" class="h-6">
                                    </div>
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://www.upaybd.com/images/logo.png" alt="Upay" class="h-6">
                                    </div>
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://www.surecash.net/images/logo-surecash.png" alt="SureCash" class="h-6">
                                    </div>
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://www.mycash.com.bd/assets/images/mycash-logo.png" alt="MyCash" class="h-6">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Internet Banking -->
                            <div class="mb-4">
                                <p class="text-white/60 text-xs mb-2">INTERNET BANKING</p>
                                <div class="grid grid-cols-6 gap-2">
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://www.citybank.com/content/dam/citybank/images/logo.png" alt="City Bank" class="h-6">
                                    </div>
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://www.bracbank.com/images/logo.png" alt="BRAC Bank" class="h-6">
                                    </div>
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://www.dutchbanglabank.com/img/dbbl_logo.png" alt="DBBL" class="h-6">
                                    </div>
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://www.ebl.com.bd/assets/images/logo.png" alt="EBL" class="h-6">
                                    </div>
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://www.bankasia-bd.com/assets/img/logo.png" alt="Bank Asia" class="h-6">
                                    </div>
                                    <div class="bg-white rounded-md p-2 flex items-center justify-center">
                                        <img src="https://www.islamibankbd.com/images/logo.png" alt="Islami Bank" class="h-6">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Payment Button -->
                        <div class="mt-8">
                            <button type="submit" class="btn bg-primary hover:bg-primary-dark text-white border-none w-full py-3 text-lg">
                                Pay ৳<?php echo $totalAmount; ?>
                            </button>
                            <p class="text-white/60 text-xs text-center mt-2">By clicking "Pay", you agree to our <a href="#" class="text-primary hover:underline">Terms & Conditions</a></p>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Order Summary -->
            <div class="lg:col-span-1">
                <div class="bg-[#0f1122]/90 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl sticky top-24">
                    <h3 class="text-white text-xl font-semibold mb-6">Order Summary</h3>
                    
                    <div class="space-y-4">
                        <div class="flex justify-between items-center pb-3 border-b border-white/10">
                            <span class="text-white/70">Booking ID</span>
                            <span class="text-white font-medium"><?php echo $bookingId; ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center pb-3 border-b border-white/10">
                            <span class="text-white/70">Location</span>
                            <span class="text-white font-medium"><?php echo $locationName; ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center pb-3 border-b border-white/10">
                            <span class="text-white/70">Address</span>
                            <span class="text-white font-medium"><?php echo $locationAddress; ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center pb-3 border-b border-white/10">
                            <span class="text-white/70">Duration</span>
                            <span class="text-white font-medium"><?php echo $duration; ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center pb-3 border-b border-white/10">
                            <span class="text-white/70">Vehicle</span>
                            <span class="text-white font-medium"><?php echo $vehiclePlate; ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center pb-3 border-b border-white/10">
                            <span class="text-white/70">Parking Fee</span>
                            <span class="text-white font-medium">৳<?php echo $parkingFee; ?></span>
                        </div>
                        
                        
                        
                        <div class="flex justify-between items-center pt-2">
                            <span class="text-white font-semibold">Total</span>
                            <span class="text-primary text-xl font-bold">৳<?php echo $totalAmount; ?></span>
                        </div>
                    </div>
                    
                    <div class="mt-6 bg-black/30 rounded-lg p-4">
                        <div class="flex items-center gap-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                            <p class="text-white/80 text-sm">Your payment information is encrypted and secure.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Footer -->
    <footer class="bg-black/70 backdrop-blur-md border-t border-white/10 pt-16 pb-8 mt-16">
        <div class="container mx-auto px-4 mt-10 pt-6 border-t border-white/10 flex flex-col md:flex-row justify-between items-center gap-4">
            <p class="text-white/90 text-sm">&copy; <?php echo date('Y'); ?> Car Parking System. All rights reserved.</p>
            <div class="flex gap-6">
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Privacy Policy</a>
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Terms of Service</a>
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Cookie Policy</a>
            </div>
        </div>
    </footer>

    <!-- REPLACE the entire <script> section at the bottom with this: -->
<script>
    // Toggle payment method fields and update required attributes
    document.addEventListener('DOMContentLoaded', function() {
        const bkashRadio = document.querySelector('input[value="bkash"]');
        const nagadRadio = document.querySelector('input[value="nagad"]');
        const cardRadio = document.querySelector('input[value="card"]');
        const pointsRadio = document.querySelector('input[value="points"]');
        
        const bkashFields = document.getElementById('bkashFields');
        const nagadFields = document.getElementById('nagadFields');
        const cardFields = document.getElementById('cardFields');
        const pointsFields = document.getElementById('pointsFields');
        
        // Input fields
        const bkashNumber = document.querySelector('input[name="bkash_number"]');
        const nagadNumber = document.querySelector('input[name="nagad_number"]');
        const cardNumber = document.querySelector('input[name="card_number"]');
        const cardHolder = document.querySelector('input[name="card_holder"]');
        const cardExpiry = document.querySelector('input[name="card_expiry"]');
        const cardCVV = document.querySelector('input[name="card_cvv"]');
        
        function togglePaymentFields() {
            // Show/hide fields
            bkashFields.classList.toggle('hidden', !bkashRadio.checked);
            nagadFields.classList.toggle('hidden', !nagadRadio.checked);
            cardFields.classList.toggle('hidden', !cardRadio.checked);
            pointsFields.classList.toggle('hidden', !pointsRadio.checked);
            
            // Set required attributes
            bkashNumber.required = bkashRadio.checked;
            nagadNumber.required = nagadRadio.checked;
            // Points don't need required validation since amount is fixed
            
            if (cardRadio.checked) {
                cardNumber.required = true;
                cardHolder.required = true;
                cardExpiry.required = true;
                cardCVV.required = true;
            } else {
                cardNumber.required = false;
                cardHolder.required = false;
                cardExpiry.required = false;
                cardCVV.required = false;
            }
        }
        
        // Initial toggle
        togglePaymentFields();
        
        // Add event listeners
        bkashRadio.addEventListener('change', togglePaymentFields);
        nagadRadio.addEventListener('change', togglePaymentFields);
        cardRadio.addEventListener('change', togglePaymentFields);
        pointsRadio.addEventListener('change', togglePaymentFields);
        
        // Add form validation
        const form = document.getElementById('paymentForm');
        
        form.addEventListener('submit', function(event) {
            let isValid = true;
            
            if (bkashRadio.checked) {
                if (!/^01[0-9]{9}$/.test(bkashNumber.value)) {
                    alert('Please enter a valid 11-digit Bangladeshi mobile number for bKash');
                    isValid = false;
                }
            } else if (nagadRadio.checked) {
                if (!/^01[0-9]{9}$/.test(nagadNumber.value)) {
                    alert('Please enter a valid 11-digit Bangladeshi mobile number for Nagad');
                    isValid = false;
                }
            } else if (pointsRadio.checked) {
                // Points validation
                const userPoints = <?php echo $userPoints; ?>;
                const requiredPoints = <?php echo isset($pointsNeeded) ? $pointsNeeded : 150; ?>;
                const durationHours = <?php echo isset($durationHours) ? $durationHours : 1; ?>;
                
                if (userPoints < requiredPoints) {
                    alert('Insufficient points. Required: ' + requiredPoints + ' points for ' + durationHours + ' hour' + (durationHours > 1 ? 's' : '') + '. Available: ' + userPoints + ' points');
                    isValid = false;
                }
            } else if (cardRadio.checked) {
                const cleanCardNumber = cardNumber.value.replace(/\s/g, '');
                if (!/^[0-9]{16}$/.test(cleanCardNumber)) {
                    alert('Please enter a valid 16-digit card number');
                    isValid = false;
                }
                
                if (!/^(0[1-9]|1[0-2])\/[0-9]{2}$/.test(cardExpiry.value)) {
                    alert('Please enter a valid expiry date in MM/YY format');
                    isValid = false;
                }
                
                if (!/^[0-9]{3,4}$/.test(cardCVV.value)) {
                    alert('Please enter a valid 3 or 4 digit CVV code');
                    isValid = false;
                }
            }
            
            if (!isValid) {
                event.preventDefault();
            }
        });
    });
</script>
</body>
</html>