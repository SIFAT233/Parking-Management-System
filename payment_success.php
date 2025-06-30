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

// Try to get user's personal information
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

// Get first letter for avatar
$firstLetter = strtoupper(substr($fullName, 0, 1));

// Get booking information from session if available
$bookingId = isset($_SESSION['booking_id']) ? $_SESSION['booking_id'] : (isset($_GET['booking_id']) ? $_GET['booking_id'] : '');
$numericId = str_replace("BK", "", $bookingId);

// Get other details from session if available
$locationName = isset($_SESSION['location_name']) ? $_SESSION['location_name'] : "Unknown Location";
$locationAddress = isset($_SESSION['location_address']) ? $_SESSION['location_address'] : "";
$bookingDate = isset($_SESSION['booking_date']) ? $_SESSION['booking_date'] : date('d M Y');
$bookingTime = isset($_SESSION['booking_time']) ? $_SESSION['booking_time'] : "Unknown";
$duration = isset($_SESSION['duration']) ? $_SESSION['duration'] : "";
$vehiclePlate = isset($_SESSION['vehicle_plate']) ? $_SESSION['vehicle_plate'] : "";
$parkingFee = isset($_SESSION['parking_fee']) ? $_SESSION['parking_fee'] : 55;
$serviceFee = isset($_SESSION['service_fee']) ? $_SESSION['service_fee'] : 10;
$totalAmount = isset($_SESSION['payment_amount']) ? $_SESSION['payment_amount'] : ($parkingFee + $serviceFee);
$paymentMethod = isset($_SESSION['payment_method']) ? $_SESSION['payment_method'] : 'bKash';
$transactionId = isset($_SESSION['transaction_id']) ? $_SESSION['transaction_id'] : "TRX" . date('YmdHis') . rand(1000, 9999);

// Get booking details from database if booking ID is valid and session data is not available
if (is_numeric($numericId) && (!isset($_SESSION['location_name']) || !isset($_SESSION['payment_amount']))) {
    // First try to get data from the payments table
    $paymentQuery = "SELECT p.*, b.booking_date, b.booking_time, b.duration, g.Parking_Space_Name, g.Parking_Lot_Address, g.PriceperHour,
                    v.vehicleType, v.make, v.model, v.color, v.licensePlate
                    FROM payments p
                    JOIN bookings b ON p.booking_id = b.id
                    JOIN garage_information g ON b.garage_id = g.garage_id
                    LEFT JOIN vehicle_information v ON b.licenseplate = v.licensePlate
                    WHERE p.booking_id = ?
                    ORDER BY p.payment_id DESC LIMIT 1";
                    
    $paymentStmt = $conn->prepare($paymentQuery);
    
    if ($paymentStmt) {
        $paymentStmt->bind_param("i", $numericId);
        $paymentStmt->execute();
        $paymentResult = $paymentStmt->get_result();
        
        if ($paymentResult && $paymentResult->num_rows > 0) {
            $payment = $paymentResult->fetch_assoc();
            $transactionId = $payment['transaction_id'];
            $totalAmount = $payment['amount'];
            $paymentMethod = $payment['payment_method'];
            
            // Booking details from the joined tables
            $locationName = $payment['Parking_Space_Name'];
            $locationAddress = $payment['Parking_Lot_Address'];
            $bookingDate = date('d M Y', strtotime($payment['booking_date']));
            $startTime = date('h:i A', strtotime($payment['booking_time']));
            $duration = $payment['duration'] . " hours";
            $endTime = date('h:i A', strtotime($payment['booking_time'] . " + {$payment['duration']} hours"));
            $bookingTime = $startTime . " - " . $endTime;
            $parkingFee = $payment['PriceperHour'] * $payment['duration'];
            $serviceFee = $totalAmount - $parkingFee;
            
            // Format vehicle info
            if (!empty($payment['make']) && !empty($payment['model'])) {
                $vehiclePlate = $payment['make'] . ' ' . $payment['model'];
                if (!empty($payment['color'])) {
                    $vehiclePlate .= ' (' . $payment['color'] . ')';
                }
            } else if (!empty($payment['licensePlate'])) {
                $vehiclePlate = 'License Plate: ' . $payment['licensePlate'];
            } else {
                $vehiclePlate = "Not provided";
            }
        }
    }
    
    // If payment record not found, fall back to booking details
    if (!isset($payment)) {
        $bookingQuery = "SELECT b.*, g.Parking_Space_Name, g.Parking_Lot_Address, g.PriceperHour,
                        v.vehicleType, v.make, v.model, v.color, v.licensePlate
                        FROM bookings b 
                        LEFT JOIN garage_information g ON b.garage_id = g.garage_id 
                        LEFT JOIN vehicle_information v ON b.licenseplate = v.licensePlate
                        WHERE b.id = ?";
        
        $stmt = $conn->prepare($bookingQuery);
        $stmt->bind_param("i", $numericId);
        $stmt->execute();
        $bookingResult = $stmt->get_result();
        
        if ($bookingResult && $bookingResult->num_rows > 0) {
            $booking = $bookingResult->fetch_assoc();
            $locationName = $booking['Parking_Space_Name'];
            $locationAddress = $booking['Parking_Lot_Address'];
            $bookingDate = date('d M Y', strtotime($booking['booking_date']));
            $startTime = date('h:i A', strtotime($booking['booking_time']));
            $duration = $booking['duration'] . " hours";
            $endTime = date('h:i A', strtotime($booking['booking_time'] . " + {$booking['duration']} hours"));
            $bookingTime = $startTime . " - " . $endTime;
            
            // Format vehicle info
            if (!empty($booking['make']) && !empty($booking['model'])) {
                $vehiclePlate = $booking['make'] . ' ' . $booking['model'];
                if (!empty($booking['color'])) {
                    $vehiclePlate .= ' (' . $booking['color'] . ')';
                }
            } else if (!empty($booking['licensePlate'])) {
                $vehiclePlate = 'License Plate: ' . $booking['licensePlate'];
            } else {
                $vehiclePlate = "Not provided";
            }
            
            // Calculate the parking fee using PriceperHour from the database
            if (isset($booking['PriceperHour'])) {
                $parkingFee = $booking['PriceperHour'] * $booking['duration'];
            } else {
                $parkingFee = 55; // Default if PriceperHour is not set
            }
            
            $totalAmount = $parkingFee + $serviceFee;
        }
    }
}

// Get current date and time for payment timestamp
$paymentDate = date('d M Y');
$paymentTime = date('h:i A');

// Clear payment session data after using it
// We'll do this at the end of the script to ensure data is available throughout the page

// Check if booking is completed and get garage info for rating
$canRate = false;
$garageId = '';
$garageName = '';
$hasRated = false;
$existingRating = null;
$existingReview = null;

if (is_numeric($numericId)) {
    // Get booking status and garage info
    $bookingStatusQuery = "SELECT b.status, b.garage_id, g.Parking_Space_Name 
                          FROM bookings b 
                          JOIN garage_information g ON b.garage_id = g.garage_id 
                          WHERE b.id = ? AND b.username = ?";
    $statusStmt = $conn->prepare($bookingStatusQuery);
    $statusStmt->bind_param("is", $numericId, $username);
    $statusStmt->execute();
    $statusResult = $statusStmt->get_result();
    
    if ($statusResult && $statusResult->num_rows > 0) {
        $bookingData = $statusResult->fetch_assoc();
        $canRate = ($bookingData['status'] == 'completed');
        $garageId = $bookingData['garage_id'];
        $garageName = $bookingData['Parking_Space_Name'];
        
        // Check if already rated
        if ($canRate) {
            $ratingQuery = "SELECT rating, review_text FROM ratings WHERE booking_id = ?";
            $ratingStmt = $conn->prepare($ratingQuery);
            $ratingStmt->bind_param("i", $numericId);
            $ratingStmt->execute();
            $ratingResult = $ratingStmt->get_result();
            
            if ($ratingResult && $ratingResult->num_rows > 0) {
                $hasRated = true;
                $ratingData = $ratingResult->fetch_assoc();
                $existingRating = $ratingData['rating'];
                $existingReview = $ratingData['review_text'];
            }
            $ratingStmt->close();
        }
    }
    $statusStmt->close();
}
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
    <title>Payment Success - Car Parking System</title>
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
                    }
                },
                animation: {
                    fadeIn: 'fadeIn 1.2s ease-out'
                }
            }
        }
    }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
        .receipt-container {
            font-family: 'Inter', sans-serif;
            width: 100%;
            max-width: 800px;
            background-color: #0f1122;
            border-radius: 16px;
            padding: 40px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }
        
        .receipt-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 20px;
        }
        
        .logo-title {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .receipt-logo {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 50px;
            height: 50px;
            background-color: #f39c12;
            border-radius: 50%;
        }
        
        .date-time {
            text-align: right;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.7);
        }
        
        .receipt-title {
            font-size: 24px;
            font-weight: 700;
        }
        
        .success-badge {
            display: flex;
            align-items: center;
            background-color: rgba(22, 163, 74, 0.2);
            color: #16a34a;
            padding: 8px 16px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 30px;
            width: fit-content;
        }
        
        .details-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .details-box {
            background-color: rgba(0, 0, 0, 0.3);
            border-radius: 12px;
            padding: 20px;
        }
        
        .details-title {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        
        .detail-item:last-child {
            margin-bottom: 0;
        }
        
        .detail-label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
        }
        
        .detail-value {
            font-weight: 500;
        }
        
        .paid-status {
            color: #16a34a;
            font-weight: 600;
        }
        
        .receipt-totals {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 20px;
            margin-top: 20px;
            margin-bottom: 30px;
        }
        
        .total-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        
        .final-total {
            display: flex;
            justify-content: space-between;
            padding-top: 15px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: 15px;
        }
        
        .final-total .total-label {
            font-weight: 600;
            font-size: 16px;
        }
        
        .final-total .total-value {
            color: #f39c12;
            font-weight: 700;
            font-size: 20px;
        }
        
        .receipt-buttons {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-top: 20px;
        }
        
        .receipt-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        
        .btn-primary {
            background-color: #f39c12;
            color: white;
            border: none;
        }
        
        .btn-primary:hover {
            background-color: #e67e22;
        }
        
        .btn-outline {
            background-color: transparent;
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.5);
        }
        
        .btn-outline:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .receipt-footer {
            text-align: center;
            margin-top: 40px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.5);
        }
        
        /* Print styles */
        @media print {
            body {
                background-color: white;
                color: black;
                padding: 0;
            }
            
            .receipt-container {
                background-color: white;
                border: 1px solid #ddd;
                box-shadow: none;
                max-width: 100%;
                border-radius: 0;
            }
            
            .details-box {
                background-color: #f5f5f5;
            }
            
            .detail-label {
                color: #555;
            }
            
            .receipt-btn {
                display: none;
            }
            
            .paid-status {
                color: #16a34a;
            }
            
            .final-total .total-value {
                color: #f39c12;
            }
            
            .receipt-header, .receipt-totals {
                border-color: #ddd;
            }
            
            .final-total {
                border-color: #ddd;
            }
            
            .success-badge {
                background-color: rgba(22, 163, 74, 0.1);
                color: #16a34a;
                border: 1px solid #16a34a;
            }
            
            header, nav, footer, .btn {
                display: none !important;
            }
            
            .receipt-container {
                margin: 0;
                padding: 20px;
            }
        }
    </style>

        <!-- Add this CSS to the existing <style> section in view_receipt.php -->
<style>
/* Add these styles to your existing CSS */
.rating-section {
    margin-top: 30px;
    padding-top: 30px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
}

.rating-container {
    background-color: rgba(0, 0, 0, 0.3);
    border-radius: 12px;
    padding: 25px;
    text-align: center;
}

.rating-title {
    font-size: 18px;
    font-weight: 600;
    color: white;
    margin-bottom: 15px;
}

.rating-subtitle {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.7);
    margin-bottom: 20px;
}

.rating-stars {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-bottom: 20px;
}

.star {
    width: 32px;
    height: 32px;
    cursor: pointer;
    transition: all 0.2s ease;
    fill: rgba(255, 255, 255, 0.2);
    stroke: rgba(255, 255, 255, 0.4);
}

.star:hover,
.star.active {
    fill: #f39c12;
    stroke: #f39c12;
    transform: scale(1.1);
}

.rating-text {
    font-size: 14px;
    color: #f39c12;
    font-weight: 500;
    margin-bottom: 15px;
    height: 20px;
}

.review-textarea {
    width: 100%;
    padding: 12px;
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    background-color: rgba(0, 0, 0, 0.3);
    color: white;
    font-size: 14px;
    resize: vertical;
    min-height: 80px;
    margin-bottom: 20px;
}

.review-textarea::placeholder {
    color: rgba(255, 255, 255, 0.5);
}

.review-textarea:focus {
    outline: none;
    border-color: #f39c12;
}

.rating-buttons {
    display: flex;
    justify-content: center;
    gap: 12px;
}

.existing-rating {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    background-color: rgba(34, 197, 94, 0.2);
    color: #22c55e;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 15px;
}

.existing-rating svg {
    fill: #f39c12;
}

/* Modal Styles */
.rating-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.rating-modal.active {
    opacity: 1;
    visibility: visible;
}

.rating-modal-content {
    background-color: #0f1122;
    border-radius: 16px;
    width: 90%;
    max-width: 500px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    transform: translateY(20px);
    transition: all 0.3s ease;
}

.rating-modal.active .rating-modal-content {
    transform: translateY(0);
}

.notification {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 8px;
    color: white;
    font-weight: 500;
    z-index: 10000;
    opacity: 0;
    transform: translateX(100%);
    transition: all 0.3s ease;
}

.notification.show {
    opacity: 1;
    transform: translateX(0);
}

.notification.success {
    background-color: #22c55e;
}

.notification.error {
    background-color: #ef4444;
}
</style>

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
    </div>
                                        <div class="text-xs text-base-content/60 leading-tight break-words max-w-full overflow-wrap-anywhere"><?php echo htmlspecialchars($email); ?></div>
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
        <!-- Success Animation -->
        <div class="flex justify-center mb-8">
            <div class="w-24 h-24 rounded-full bg-green-500/20 flex items-center justify-center animate-fadeIn">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            </div>
        </div>
        
        <div class="text-center mb-12 animate-fadeIn">
            <h2 class="text-3xl md:text-4xl font-bold text-white mb-4">Payment Successful!</h2>
            <p class="text-white/80 text-lg max-w-2xl mx-auto">Your payment has been processed successfully. Your booking is now confirmed.</p>
        </div>
         <!-- ADD POINTS SUCCESS MESSAGE HERE (RIGHT AFTER MAIN SUCCESS MESSAGE) -->
        <?php if (isset($_SESSION['payment_method']) && $_SESSION['payment_method'] === 'points'): ?>
            <?php
            $pointsUsed = $_SESSION['points_used'];
            $hoursCovered = $_SESSION['points_covered_hours'];
            $moneySaved = $_SESSION['points_payment_amount'];
            ?>
            <div class="bg-green-900/30 border border-green-500/30 rounded-lg p-6 mb-6 text-center">
                <div class="flex items-center justify-center gap-2 mb-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1" />
                    </svg>
                    <h3 class="text-lg font-semibold text-green-300">Points Payment Successful!</h3>
                </div>
                <div class="bg-green-800/30 rounded-lg p-4 text-left">
                    <p class="text-green-400 text-sm">
                        <strong>Points Used:</strong> <?php echo $pointsUsed; ?> points<br>
                        <strong>Parking Duration:</strong> <?php echo $hoursCovered; ?> hour<?php echo $hoursCovered > 1 ? 's' : ''; ?><br>
                        <strong>Money Saved:</strong> ৳<?php echo $moneySaved; ?><br>
                        <strong>Conversion Rate:</strong> 150 points = 1 hour parking
                    </p>
                </div>
            </div>
        <?php endif; ?>
        <!-- Payment Receipt -->
        <div class="max-w-3xl mx-auto">
            <div class="receipt-container animate-fadeIn">
                <div class="receipt-header">
                    <div class="logo-title">
                        <div class="receipt-logo">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                <path d="M9 18V6h4.5a2.5 2.5 0 0 1 0 5H9"></path>
                            </svg>
                        </div>
                        <div class="receipt-title">Payment Receipt</div>
                    </div>
                    <div class="date-time">
                        <div>Date: <?php echo $paymentDate; ?></div>
                        <div>Time: <?php echo $paymentTime; ?></div>
                    </div>
                </div>
                
                <div class="success-badge">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px;">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    Payment Successful
                </div>
                
                <div class="details-row">
                    <div class="details-box">
                        <div class="details-title">Transaction Details</div>
                        <div class="detail-item">
                            <div class="detail-label">Transaction ID:</div>
                            <div class="detail-value"><?php echo $transactionId; ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Booking ID:</div>
                            <div class="detail-value"><?php echo $bookingId; ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Payment Method:</div>
                            <div class="detail-value"><?php echo ucfirst($paymentMethod); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Status:</div>
                            <div class="detail-value paid-status">Paid</div>
                        </div>
                    </div>
                    
                    <div class="details-box">
                        <div class="details-title">Booking Details</div>
                        <div class="detail-item">
                            <div class="detail-label">Location:</div>
                            <div class="detail-value"><?php echo $locationName; ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Date:</div>
                            <div class="detail-value"><?php echo $bookingDate; ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Time:</div>
                            <div class="detail-value"><?php echo $bookingTime; ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Vehicle:</div>
                            <div class="detail-value"><?php echo $vehiclePlate; ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="receipt-totals">
                    <div class="total-item">
                        <div class="total-label">Sub Total:</div>
                        <div class="total-value">৳<?php echo number_format($parkingFee, 2); ?></div>
                    </div>
                    <div class="total-item">
                        <div class="total-label">Service Fee:</div>
                        <div class="total-value">৳<?php echo number_format($serviceFee, 2); ?></div>
                    </div>
                    <div class="final-total">
                        <div class="total-label">Total:</div>
                        <div class="total-value">৳<?php echo number_format($totalAmount, 2); ?></div>
                    </div>
                </div>
                

                <!-- Add this HTML after the receipt-totals div and before receipt-buttons div in view_receipt.php -->
<?php if ($canRate): ?>
<div class="rating-section">
    <div class="rating-container">
        <?php if ($hasRated): ?>
            <div class="existing-rating">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                </svg>
                <span>You rated this experience <?php echo number_format($existingRating, 1); ?>/5.0</span>
            </div>
            <button onclick="openRatingModal()" class="receipt-btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path>
                </svg>
                Edit Rating
            </button>
        <?php else: ?>
            <div class="rating-title">How was your parking experience?</div>
            <div class="rating-subtitle">Rate your experience at <?php echo htmlspecialchars($garageName); ?></div>
            
            <div class="rating-stars" id="ratingStars">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <svg class="star" data-rating="<?php echo $i; ?>" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                        <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                    </svg>
                <?php endfor; ?>
            </div>
            
            <div class="rating-text" id="ratingText"></div>
            
            <textarea class="review-textarea" id="reviewText" placeholder="Share your experience (optional)"></textarea>
            
            <div class="rating-buttons">
                <button onclick="submitRating()" class="receipt-btn btn-primary" id="submitBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                    </svg>
                    <span id="submitText">Submit Rating</span>
                    <span id="submitLoader" style="display: none;">Submitting...</span>
                </button>
                <button onclick="skipRating()" class="receipt-btn btn-outline">
                    Skip for now
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Rating Modal for editing existing ratings -->
<div id="ratingModal" class="rating-modal">
    <div class="rating-modal-content">
        <div style="padding: 30px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="color: white; font-size: 20px; font-weight: 600; margin: 0;">Edit Your Rating</h3>
                <button onclick="closeRatingModal()" style="background: none; border: none; color: rgba(255,255,255,0.7); cursor: pointer; font-size: 24px;">&times;</button>
            </div>
            
            <div style="text-align: center;">
                <div style="margin-bottom: 20px; color: rgba(255,255,255,0.8);">Rate your experience at <?php echo htmlspecialchars($garageName); ?></div>
                
                <div class="rating-stars" id="modalRatingStars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <svg class="star" data-rating="<?php echo $i; ?>" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                        </svg>
                    <?php endfor; ?>
                </div>
                
                <div class="rating-text" id="modalRatingText"></div>
                
                <textarea class="review-textarea" id="modalReviewText" placeholder="Share your experience (optional)"><?php echo htmlspecialchars($existingReview ?? ''); ?></textarea>
                
                <div class="rating-buttons">
                    <button onclick="updateRating()" class="receipt-btn btn-primary" id="updateBtn">
                        <span id="updateText">Update Rating</span>
                        <span id="updateLoader" style="display: none;">Updating...</span>
                    </button>
                    <button onclick="closeRatingModal()" class="receipt-btn btn-outline">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

                <div class="receipt-buttons">
                    <a href="booking.php" class="receipt-btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                            <polyline points="17 21 17 13 7 13 7 21"></polyline>
                            <polyline points="7 3 7 8 15 8"></polyline>
                        </svg>
                        View My Bookings
                    </a>
                    <button onclick="window.print()" class="receipt-btn btn-outline">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="7 10 12 15 17 10"></polyline>
                            <line x1="12" y1="15" x2="12" y2="3"></line>
                        </svg>
                        Download Receipt
                    </button>
                </div>
                
                <div class="receipt-footer">
                    &copy; <?php echo date('Y'); ?> Car Parking System. All rights reserved.
                </div>
            </div>
        </div>
    </main>
    
    <!-- Footer -->
    <footer class="bg-black/70 backdrop-blur-md border-t border-white/10 pt-10 pb-8 mt-16 print:hidden">
        <div class="container mx-auto px-4 mt-10 pt-6 border-t border-white/10 flex flex-col md:flex-row justify-between items-center gap-4">
            <p class="text-white/90 text-sm">&copy; <?php echo date('Y'); ?> Car Parking System. All rights reserved.</p>
            <div class="flex gap-6">
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Privacy Policy</a>
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Terms of Service</a>
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Cookie Policy</a>
            </div>
        </div>
    </footer>

    <script>
        // Add script to handle print functionality better
        document.addEventListener('DOMContentLoaded', function() {
            const printButton = document.querySelector('button[onclick="window.print()"]');
            
            if (printButton) {
                printButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    window.print();
                });
            }
        });
    </script>

    <script>
// Add this JavaScript to your existing script section or create a new one
let selectedRating = <?php echo $existingRating ?? 0; ?>;
let modalSelectedRating = <?php echo $existingRating ?? 0; ?>;
const bookingId = <?php echo $numericId; ?>;
const garageId = '<?php echo $garageId; ?>';

// Rating texts
const ratingTexts = {
    1: "Poor - Not satisfied",
    2: "Fair - Below expectations", 
    3: "Good - Met expectations",
    4: "Very Good - Above expectations",
    5: "Excellent - Outstanding experience"
};

// Initialize rating functionality
document.addEventListener('DOMContentLoaded', function() {
    initializeRatingStars('ratingStars', 'ratingText');
    initializeRatingStars('modalRatingStars', 'modalRatingText');
    
    // Pre-select existing rating in modal
    if (modalSelectedRating > 0) {
        updateStarDisplay('modalRatingStars', modalSelectedRating);
        document.getElementById('modalRatingText').textContent = ratingTexts[modalSelectedRating];
    }
});

function initializeRatingStars(starsId, textId) {
    const stars = document.querySelectorAll(`#${starsId} .star`);
    const ratingText = document.getElementById(textId);
    
    stars.forEach(star => {
        star.addEventListener('click', function() {
            const rating = parseInt(this.getAttribute('data-rating'));
            
            if (starsId === 'ratingStars') {
                selectedRating = rating;
            } else {
                modalSelectedRating = rating;
            }
            
            updateStarDisplay(starsId, rating);
            ratingText.textContent = ratingTexts[rating];
        });
        
        star.addEventListener('mouseenter', function() {
            const rating = parseInt(this.getAttribute('data-rating'));
            updateStarDisplay(starsId, rating);
        });
    });
    
    // Reset on mouse leave
    document.getElementById(starsId).addEventListener('mouseleave', function() {
        const currentRating = starsId === 'ratingStars' ? selectedRating : modalSelectedRating;
        updateStarDisplay(starsId, currentRating);
        ratingText.textContent = currentRating > 0 ? ratingTexts[currentRating] : '';
    });
}

function updateStarDisplay(starsId, rating) {
    const stars = document.querySelectorAll(`#${starsId} .star`);
    stars.forEach((star, index) => {
        if (index < rating) {
            star.classList.add('active');
        } else {
            star.classList.remove('active');
        }
    });
}

function submitRating() {
    if (selectedRating === 0) {
        showNotification('Please select a rating', 'error');
        return;
    }
    
    const reviewText = document.getElementById('reviewText').value;
    const submitBtn = document.getElementById('submitBtn');
    const submitText = document.getElementById('submitText');
    const submitLoader = document.getElementById('submitLoader');
    
    // Show loading state
    submitBtn.disabled = true;
    submitText.style.display = 'none';
    submitLoader.style.display = 'inline';
    
    const formData = new FormData();
    formData.append('action', 'submit_rating');
    formData.append('booking_id', bookingId);
    formData.append('garage_id', garageId);
    formData.append('rating', selectedRating);
    formData.append('review_text', reviewText);
    
    fetch('rating_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Rating submitted successfully!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification(data.message || 'Error submitting rating', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error submitting rating. Please try again.', 'error');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitText.style.display = 'inline';
        submitLoader.style.display = 'none';
    });
}

function updateRating() {
    if (modalSelectedRating === 0) {
        showNotification('Please select a rating', 'error');
        return;
    }
    
    const reviewText = document.getElementById('modalReviewText').value;
    const updateBtn = document.getElementById('updateBtn');
    const updateText = document.getElementById('updateText');
    const updateLoader = document.getElementById('updateLoader');
    
    // Show loading state
    updateBtn.disabled = true;
    updateText.style.display = 'none';
    updateLoader.style.display = 'inline';
    
    const formData = new FormData();
    formData.append('action', 'submit_rating');
    formData.append('booking_id', bookingId);
    formData.append('garage_id', garageId);
    formData.append('rating', modalSelectedRating);
    formData.append('review_text', reviewText);
    
    fetch('rating_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Rating updated successfully!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification(data.message || 'Error updating rating', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error updating rating. Please try again.', 'error');
    })
    .finally(() => {
        updateBtn.disabled = false;
        updateText.style.display = 'inline';
        updateLoader.style.display = 'none';
    });
}

function skipRating() {
    const ratingSection = document.querySelector('.rating-section');
    ratingSection.style.transition = 'all 0.3s ease';
    ratingSection.style.opacity = '0';
    ratingSection.style.transform = 'translateY(-20px)';
    
    setTimeout(() => {
        ratingSection.remove();
    }, 300);
}

function openRatingModal() {
    document.getElementById('ratingModal').classList.add('active');
}

function closeRatingModal() {
    document.getElementById('ratingModal').classList.remove('active');
}

function showNotification(message, type) {
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    setTimeout(() => notification.classList.add('show'), 100);
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Close modal when clicking outside
document.getElementById('ratingModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeRatingModal();
    }
});
</script>
</body>
</html>