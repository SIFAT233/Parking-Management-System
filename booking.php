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

// Try to get user's personal information - Using prepared statement for security
$stmt = $conn->prepare("SELECT * FROM personal_information WHERE email LIKE ? OR firstName LIKE ? OR lastName LIKE ?");
$searchPattern = "%$username%";
$stmt->bind_param("sss", $searchPattern, $searchPattern, $searchPattern);
$stmt->execute();
$result = $stmt->get_result();

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
// Get user's parking spaces (if they are an owner)
$parkingSpaces = [];
$parkingQuery = "SELECT * FROM garage_information WHERE username = ?";
$stmt = $conn->prepare($parkingQuery);
$stmt->bind_param("s", $username);
$stmt->execute();
$parkingResult = $stmt->get_result();

if ($parkingResult && $parkingResult->num_rows > 0) {
    while ($row = $parkingResult->fetch_assoc()) {
        $parkingSpaces[] = $row;
    }
}
$stmt->close();


// Get first letter for avatar
$firstLetter = strtoupper(substr($fullName, 0, 1));

// Get user's current points
$userPoints = 0;
$pointsQuery = "SELECT points FROM account_information WHERE username = ?";
$pointsStmt = $conn->prepare($pointsQuery);
$pointsStmt->bind_param("s", $username);
$pointsStmt->execute();
$pointsResult = $pointsStmt->get_result();

if ($pointsResult && $pointsResult->num_rows > 0) {
    $pointsRow = $pointsResult->fetch_assoc();
    $userPoints = (int)$pointsRow['points'];
}

// Function to get points statistics
function getDetailedPointsStats($username, $conn) {
    $stats = [
        'current_points' => 0,
        'total_earned' => 0,
        'total_spent' => 0,
        'free_hours_available' => 0,
        'points_from_bookings' => 0
    ];
    
    // Get current points
    $pointsQuery = "SELECT points FROM account_information WHERE username = ?";
    $stmt = $conn->prepare($pointsQuery);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $stats['current_points'] = (int)$row['points'];
    }
    
    // Get total points from completed bookings (15 points per hour)
    $bookingPointsQuery = "SELECT COALESCE(SUM(points_amount), 0) as total_points 
                      FROM points_transactions 
                      WHERE username = ? 
                      AND transaction_type = 'earned'";
$stmt2 = $conn->prepare($bookingPointsQuery);
$stmt2->bind_param("s", $username);
$stmt2->execute();
$result2 = $stmt2->get_result();

if ($result2 && $result2->num_rows > 0) {
    $row2 = $result2->fetch_assoc();
    $stats['points_from_bookings'] = (int)$row2['total_points'];
    $stats['total_earned'] = $stats['points_from_bookings'];
}
    
    // Get points spent
    $spentQuery = "SELECT COALESCE(SUM(points_used), 0) as total_spent 
                   FROM payments 
                   WHERE payment_method = 'points' 
                   AND booking_id IN (SELECT id FROM bookings WHERE username = ?)";
    $stmt3 = $conn->prepare($spentQuery);
    $stmt3->bind_param("s", $username);
    $stmt3->execute();
    $result3 = $stmt3->get_result();
    
    if ($result3 && $result3->num_rows > 0) {
        $row3 = $result3->fetch_assoc();
        $stats['total_spent'] = (int)$row3['total_spent'];
    }
    
    // Calculate how many free hours user can get (150 points = 1 hour)
    $stats['free_hours_available'] = floor($stats['current_points'] / 150);
    
    return $stats;
}

$pointsStats = getDetailedPointsStats($username, $conn);

// Update booking statuses automatically based on current time
// Use server timezone settings
date_default_timezone_set('Asia/Dhaka'); // Set to Bangladesh timezone for proper time comparison
$currentDate = date('Y-m-d');
$currentTime = date('H:i:s');

// Debug comment to see what time the server is using
echo "<!-- Current server date and time: $currentDate $currentTime -->";

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

// Save the bookings that will be updated to 'completed' to update garage availability later
$completedBookingsQuery = "SELECT garage_id FROM bookings 
                          WHERE (booking_date < ? 
                          OR (booking_date = ? AND 
                              TIME_TO_SEC(booking_time) + (duration * 3600) < TIME_TO_SEC(?)))
                          AND status IN ('upcoming', 'active')";
$stmt = $conn->prepare($completedBookingsQuery);
$stmt->bind_param("sss", $currentDate, $currentDate, $currentTime);
$stmt->execute();
$completedBookingsResult = $stmt->get_result();
$completedGarageIds = [];

if ($completedBookingsResult && $completedBookingsResult->num_rows > 0) {
    while ($row = $completedBookingsResult->fetch_assoc()) {
        $completedGarageIds[] = $row['garage_id'];
    }
}
$stmt->close();

// This query updates all bookings for all users with more explicit time calculations
$updateStatusQuery = "UPDATE bookings SET 
    status = CASE
        WHEN booking_date < ? THEN 'completed'
        WHEN booking_date = ? AND 
             TIME_TO_SEC(booking_time) + (duration * 3600) < TIME_TO_SEC(?)
        THEN 'completed'
        WHEN booking_date = ? AND 
             TIME_TO_SEC(booking_time) <= TIME_TO_SEC(?) AND 
             TIME_TO_SEC(booking_time) + (duration * 3600) >= TIME_TO_SEC(?)
        THEN 'active'
        ELSE status
    END
    WHERE status IN ('upcoming', 'active')";

$stmt = $conn->prepare($updateStatusQuery);
$stmt->bind_param("ssssss", $currentDate, $currentDate, $currentTime, $currentDate, $currentTime, $currentTime);
$updateResult = $stmt->execute();
echo "<!-- Status update query affected rows: " . ($updateResult ? $stmt->affected_rows : 'Error: ' . $conn->error) . " -->";
$stmt->close();

// Call the function to update all garage availability
updateAllGarageAvailability($conn);

// Get booking information with vehicle details
$bookingsQuery = "SELECT b.*, g.Parking_Space_Name, g.Parking_Lot_Address, g.PriceperHour,
                v.vehicleType, v.make, v.model, v.color
                FROM bookings b 
                JOIN garage_information g ON b.garage_id = g.garage_id 
                LEFT JOIN vehicle_information v ON b.licenseplate = v.licensePlate
                WHERE b.username = ? 
                ORDER BY b.created_at DESC, b.id DESC";
$stmt = $conn->prepare($bookingsQuery);
$stmt->bind_param("s", $username);
$stmt->execute();
$bookingsResult = $stmt->get_result();
$stmt->close();

// Check for cancel messages from cancel_booking.php redirect
if (isset($_SESSION['cancelMessage'])) {
    $cancelMessage = $_SESSION['cancelMessage'];
    unset($_SESSION['cancelMessage']);
}
if (isset($_SESSION['cancelError'])) {
    $cancelError = $_SESSION['cancelError'];
    unset($_SESSION['cancelError']);
}

// Process booking cancellation if requested
if (isset($_GET['cancel']) && is_numeric($_GET['cancel'])) {
    $bookingId = (int)$_GET['cancel'];
    
    // Check if booking belongs to the user and get garage_id
    $checkQuery = "SELECT id, garage_id, status FROM bookings WHERE id = ? AND username = ?";
    $stmt = $conn->prepare($checkQuery);
    $stmt->bind_param("is", $bookingId, $username);
    $stmt->execute();
    $checkResult = $stmt->get_result();
    
    if ($checkResult && $checkResult->num_rows > 0) {
        $bookingRow = $checkResult->fetch_assoc();
        $garageId = $bookingRow['garage_id'];
        
        // Check if the booking is still eligible for cancellation (only upcoming or active)
        if (in_array($bookingRow['status'], ['upcoming', 'active'])) {
            // Update status to 'cancelled' instead of deleting
            $updateQuery = "UPDATE bookings SET status = 'cancelled' WHERE id = ?";
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bind_param("i", $bookingId);
            
            if ($updateStmt->execute()) {
                // Update garage availability by calling our function
                updateAllGarageAvailability($conn);
                $cancelMessage = "Booking successfully cancelled.";
            } else {
                $cancelError = "Problem cancelling booking. Please try again.";
            }
            $updateStmt->close();
        } else {
            $cancelError = "Only upcoming or active bookings can be cancelled.";
        }
        
        // Redirect to remove the cancel parameter from URL
        header("Location: booking.php");
        exit();
    } else {
        $cancelError = "Booking not found or does not belong to you.";
        $stmt->close();
        header("Location: booking.php");
        exit();
    }
}

// Process booking updates if submitted
if (isset($_POST['update_booking']) && isset($_POST['booking_id']) && is_numeric($_POST['booking_id'])) {
    $bookingId = (int)$_POST['booking_id'];
    $bookingDate = $_POST['booking_date'];
    $bookingTime = $_POST['booking_time'];
    $duration = (int)$_POST['duration'];
    
    // Check if booking belongs to the user
    $checkQuery = "SELECT id, garage_id FROM bookings WHERE id = ? AND username = ?";
    $stmt = $conn->prepare($checkQuery);
    $stmt->bind_param("is", $bookingId, $username);
    $stmt->execute();
    $checkResult = $stmt->get_result();
    
    if ($checkResult && $checkResult->num_rows > 0) {
        $bookingRow = $checkResult->fetch_assoc();
        $garageId = $bookingRow['garage_id'];
        
        // Calculate booking end time
        $booking_end_time_sql = "SELECT ADDTIME(?, ?) as end_time";
        $stmt = $conn->prepare($booking_end_time_sql);
        $duration_formatted = $duration . ":00:00"; // Convert duration to HH:MM:SS format
        $stmt->bind_param("ss", $bookingTime, $duration_formatted);
        $stmt->execute();
        $end_time_result = $stmt->get_result();
        $end_time_data = $end_time_result->fetch_assoc();
        $booking_end_time = $end_time_data['end_time'];
        $stmt->close();
        
        // Check for overlapping bookings (excluding this booking)
        $overlapQuery = "SELECT COUNT(*) as overlap_count FROM bookings
                       WHERE garage_id = ? 
                       AND booking_date = ?
                       AND id != ?
                       AND status IN ('upcoming', 'active')
                       AND (
                           (booking_time >= ? AND booking_time < ?) 
                           OR 
                           (ADDTIME(booking_time, SEC_TO_TIME(duration * 3600)) > ? AND ADDTIME(booking_time, SEC_TO_TIME(duration * 3600)) <= ?)
                           OR
                           (booking_time <= ? AND ADDTIME(booking_time, SEC_TO_TIME(duration * 3600)) >= ?)
                       )";
        
        $stmt = $conn->prepare($overlapQuery);
        $stmt->bind_param("ssissssss", 
            $garageId, 
            $bookingDate,
            $bookingId, 
            $bookingTime, 
            $booking_end_time,  
            $bookingTime, 
            $booking_end_time,
            $bookingTime,
            $booking_end_time
        );
        $stmt->execute();
        $overlapResult = $stmt->get_result();
        $overlapData = $overlapResult->fetch_assoc();
        $overlapCount = $overlapData['overlap_count'];
        $stmt->close();
        
        // Get garage capacity
        $garageQuery = "SELECT Parking_Capacity FROM garage_information WHERE garage_id = ?";
        $stmt = $conn->prepare($garageQuery);
        $stmt->bind_param("s", $garageId);
        $stmt->execute();
        $garageResult = $stmt->get_result();
        $garageData = $garageResult->fetch_assoc();
        $parkingCapacity = $garageData['Parking_Capacity'];
        $stmt->close();
        
        if ($overlapCount >= $parkingCapacity) {
            $updateError = "Cannot update booking: The selected time slot is already fully booked. Please choose another time.";
        } else {
            // Update the booking
            $updateQuery = "UPDATE bookings SET 
                          booking_date = ?, 
                          booking_time = ?,
                          duration = ?
                          WHERE id = ?";
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bind_param("ssii", $bookingDate, $bookingTime, $duration, $bookingId);
                          
            if ($updateStmt->execute()) {
                // Update statuses and availability
                updateAllGarageAvailability($conn);
                $updateMessage = "Booking successfully updated.";
            } else {
                $updateError = "Problem updating booking. Please try again.";
            }
            $updateStmt->close();
            
            // Redirect to refresh the page
            header("Location: booking.php");
            exit();
        }
    }
    $stmt->close();
}

// Process booking status update
if (isset($_POST['update_status']) && isset($_POST['booking_id']) && is_numeric($_POST['booking_id'])) {
    $bookingId = (int)$_POST['booking_id'];
    $newStatus = $_POST['status'];
    
    // Check if booking belongs to the user and get garage_id
    $checkQuery = "SELECT id, garage_id FROM bookings WHERE id = ? AND username = ?";
    $stmt = $conn->prepare($checkQuery);
    $stmt->bind_param("is", $bookingId, $username);
    $stmt->execute();
    $checkResult = $stmt->get_result();
    
    if ($checkResult && $checkResult->num_rows > 0) {
        $bookingRow = $checkResult->fetch_assoc();
        $garageId = $bookingRow['garage_id'];
        
        // Update the booking status
        $updateQuery = "UPDATE bookings SET status = ? WHERE id = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("si", $newStatus, $bookingId);
        
        if ($updateStmt->execute()) {
            // Update garage availability
            updateAllGarageAvailability($conn);
            $updateMessage = "Booking status successfully updated.";
        } else {
            $updateError = "Problem updating status. Please try again.";
        }
        $updateStmt->close();
        
        // Redirect to refresh the page
        header("Location: booking.php");
        exit();
    }
    $stmt->close();
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
    <title>My Bookings - Car Parking System</title>
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
                    }
                }
            }
        }
    </script>
    <style>
        .booking-card {
            transition: all 0.3s ease;
        }
        .booking-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }
        .status-badge {
            transition: all 0.3s ease;
        }
        .status-badge:hover {
            transform: scale(1.05);
        }
        .action-btn {
            transition: all 0.2s ease;
        }
        .action-btn:hover {
            transform: scale(1.05);
        }
        .payment-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            z-index: 10;
            transform: rotate(30deg) translateY(-5px);
            transition: all 0.3s ease;
        }
        .payment-badge:hover {
            transform: rotate(30deg) translateY(-8px);
        }
        /* Modal Styles */
        .edit-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        .edit-modal.active {
            opacity: 1;
            visibility: visible;
        }
        .edit-modal-content {
            background-color: rgba(30, 30, 30, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 0.5rem;
            width: 90%;
            max-width: 500px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transform: translateY(20px);
            transition: all 0.3s ease;
        }
        .edit-modal.active .edit-modal-content {
            transform: translateY(0);
        }
        /* Timer styles */
        .timer-container {
            margin-top: 8px;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .timer-upcoming {
            background-color: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }
        .timer-active {
            background-color: rgba(16, 185, 129, 0.2);
            color: #6ee7b7;
        }
        .timer-pulse {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% {
                opacity: 1;
            }
            50% {
                opacity: 0.6;
            }
            100% {
                opacity: 1;
            }
        }
    </style>
</head>
<body class="relative min-h-screen">
    <!-- Background Image with Overlay -->
    <div class="fixed inset-0 bg-cover bg-center bg-no-repeat z-[-2]" 
         style="background-image: url('https://images.unsplash.com/photo-1573348722427-f1d6819fdf98?q=80&w=1374&auto=format&fit=crop&ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D')">
    </div>
    <div class="fixed inset-0 bg-black/50 z-[-1]"></div>
    
    <!-- Header -->
    <header class="sticky top-0 z-50 bg-black/50 backdrop-blur-md border-b border-white/20">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <a href="booking.php" class="flex items-center gap-4 text-white">
                <div class="w-10 h-10 bg-primary rounded-full flex justify-center items-center overflow-hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><path d="M9 18V6h4.5a2.5 2.5 0 0 1 0 5H9"></path></svg>
                </div>
                <h1 class="text-xl font-semibold drop-shadow-md">Car Parking System</h1>
            </a>
            
            <nav class="hidden md:block">
                <ul class="flex gap-8">
                    <li><a href="home.php" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Home</a></li>
                    
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">How It Works</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Contact</a></li>
                </ul>
            </nav>
            
            <div class="hidden md:flex items-center gap-4">
            <?php if (!empty($parkingSpaces)): ?>
                <a href="business_desh.php" class="btn btn-outline btn-sm text-white border-primary hover:bg-primary hover:border-primary">
                    Switch To Business Mode
                </a>
                <?php else: ?>
                <a href="reg_for_business.php" class="btn btn-outline btn-sm text-white border-primary hover:bg-primary hover:border-primary">
                    Register as Business
                </a>
                <?php endif; ?>
                <!-- Points Display Badge - NEW -->
            <div class="flex items-center gap-2 bg-primary/20 backdrop-blur-sm px-3 py-2 rounded-full border border-primary/30 hover:bg-primary/30 transition-all cursor-pointer" onclick="showPointsModal()">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-primary" fill="currentColor" viewBox="0 0 24 24">
                    <polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26"></polygon>
                </svg>
                <span class="text-primary font-semibold text-sm" id="user-points-display"><?php echo $userPoints; ?> PTS</span>
            </div>
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
                                        <!-- Points display in dropdown -->
                                    <div class="flex items-center gap-1 mt-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-primary" fill="currentColor" viewBox="0 0 24 24">
                                            <polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26"></polygon>
                                        </svg>
                                        <span class="text-xs text-primary font-medium"><?php echo $userPoints; ?> Points</span>
                                    </div>
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
        <section class="mb-12">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-3xl font-bold text-white drop-shadow-md">My Bookings</h2>
                <a href="home.php" class="btn bg-primary hover:bg-primary-dark text-white border-none">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                    New Booking
                </a>
            </div>
            
            <?php if (isset($cancelMessage)): ?>
                <div class="alert alert-success mb-6">
                    <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span><?php echo $cancelMessage; ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($cancelError)): ?>
                <div class="alert alert-error mb-6">
                    <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span><?php echo $cancelError; ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($updateMessage)): ?>
                <div class="alert alert-success mb-6">
                    <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span><?php echo $updateMessage; ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($updateError)): ?>
                <div class="alert alert-error mb-6">
                    <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span><?php echo $updateError; ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Filter and Search Options -->
            <div class="bg-black/30 backdrop-blur-sm rounded-xl p-6 mb-8 border border-white/10">
                <div class="flex flex-col md:flex-row gap-4">
                    <div class="form-control flex-1">
                        <label class="label">
                            <span class="label-text text-white">Booking Status</span>
                        </label>
                        <select id="statusFilter" class="select select-bordered bg-white/15 text-white border-white/20 focus:border-primary w-full">
                            <option value="all">All Statuses</option>
                            <option value="upcoming">Upcoming</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="form-control flex-1">
                        <label class="label">
                            <span class="label-text text-white">By Date</span>
                        </label>
                        <select id="dateFilter" class="select select-bordered bg-white/15 text-white border-white/20 focus:border-primary w-full">
                            <option value="all">All Dates</option>
                            <option value="today">Today</option>
                            <option value="tomorrow">Tomorrow</option>
                            <option value="this_week">This Week</option>
                            <option value="this_month">This Month</option>
                            <option value="past">Past Bookings</option>
                        </select>
                    </div>
                    
                    <div class="form-control flex-1">
                        <label class="label">
                            <span class="label-text text-white">Search</span>
                        </label>
                        <input type="text" id="searchBooking" placeholder="Location, booking ID, etc..." class="input input-bordered bg-white/15 text-white border-white/20 focus:border-primary w-full" />
                    </div>
                </div>
            </div>
            
            <!-- Bookings List -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php 
    if ($bookingsResult && $bookingsResult->num_rows > 0) {
        while ($booking = $bookingsResult->fetch_assoc()) {
            $bookingId = $booking['id'];
            $garageName = $booking['Parking_Space_Name'];
            $garageAddress = $booking['Parking_Lot_Address'];
            $bookingDate = date('d M Y', strtotime($booking['booking_date']));
            $formattedBookingDate = date('Y-m-d', strtotime($booking['booking_date'])); // For form input
            $startTime = date('h:i A', strtotime($booking['booking_time']));
            $formattedBookingTime = date('H:i', strtotime($booking['booking_time'])); // For form input
            $duration = $booking['duration'];
            $endTime = date('h:i A', strtotime($booking['booking_time'] . " + {$duration} hours"));
            $paymentStatus = $booking['payment_status'];
            $garageId = $booking['garage_id'];
            
            // Force status update for this specific booking based on current time
            $status = $booking['status'];
            $bookingDateTime = strtotime($booking['booking_date'] . ' ' . $booking['booking_time']);
            $endDateTime = $bookingDateTime + ($duration * 3600);
            $currentDateTime = strtotime($currentDate . ' ' . $currentTime);
            
            if ($status != 'cancelled' && $status != 'completed') {
                if ($bookingDateTime > $currentDateTime) {
                    $status = 'upcoming';
                } else if ($currentDateTime >= $bookingDateTime && $currentDateTime <= $endDateTime) {
                    $status = 'active';
                } else if ($currentDateTime > $endDateTime) {
                    $status = 'completed';
                }
                
                // Update the database with the correct status if it differs
                if ($status != $booking['status']) {
                    $updateSingleQuery = "UPDATE bookings SET status = ? WHERE id = ?";
                    $updateStmt = $conn->prepare($updateSingleQuery);
                    $updateStmt->bind_param("si", $status, $booking['id']);
                    $updateStmt->execute();
                    $updateStmt->close();
                    
                    // If status changed to completed, update garage availability
                    if ($status == 'completed' && $booking['status'] != 'completed') {
                        updateAllGarageAvailability($conn);
                    }
                }
            }
            
            $price = $booking['PriceperHour'] * $duration;
            
            // Format vehicle info from joined vehicle_information table
            $vehicleInfo = 'Not provided';
            if (!empty($booking['make']) && !empty($booking['model'])) {
                $vehicleInfo = $booking['make'] . ' ' . $booking['model'];
                if (!empty($booking['color'])) {
                    $vehicleInfo .= ' (' . $booking['color'] . ')';
                }
                if (!empty($booking['vehicleType'])) {
                    $vehicleInfo .= ' - ' . ucfirst($booking['vehicleType']);
                }
            } else if (!empty($booking['licenseplate'])) {
                $vehicleInfo = 'License Plate: ' . $booking['licenseplate'];
            }
            
            // Determine status color
            $statusColor = "bg-info";
            $statusText = "Upcoming";
            
            if ($status == 'active') {
                $statusColor = "bg-success";
                $statusText = "Active";
            } else if ($status == 'completed') {
                $statusColor = "bg-primary";
                $statusText = "Completed";
            } else if ($status == 'cancelled') {
                $statusColor = "bg-error";
                $statusText = "Cancelled";
            }
            
            // Determine payment badge - Only show for active/completed bookings with payment_status = 'paid'
            $paymentBadge = '';
            if ($paymentStatus == 'paid') {
                $paymentBadge = '<div class="payment-badge">
                    <div class="badge badge-success bg-green-500 text-white shadow-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        PAID
                    </div>
                </div>';
            }
            
            // Create timer HTML for upcoming and active bookings
            $timerHTML = '';
            if ($status == 'upcoming') {
                // Calculate time until booking starts
                $timeUntilStart = $bookingDateTime - $currentDateTime;
                $timerHTML = '<div class="timer-container timer-upcoming" data-timestamp="' . $bookingDateTime . '" data-type="upcoming">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span class="countdown-text">Starts in: <span class="font-semibold countdown-value">calculating...</span></span>
                </div>';
            } else if ($status == 'active') {
                // Calculate time until booking ends
                $timeUntilEnd = $endDateTime - $currentDateTime;
                $timerHTML = '<div class="timer-container timer-active timer-pulse" data-timestamp="' . $endDateTime . '" data-type="active">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span class="countdown-text">Ends in: <span class="font-semibold countdown-value">calculating...</span></span>
                </div>';
            }
            // Check if user has already rated this booking
$hasRated = false;
$existingRating = null;
$existingReview = null;

if ($status == 'completed') {
    $ratingQuery = "SELECT rating, review_text FROM ratings WHERE booking_id = ?";
    $ratingStmt = $conn->prepare($ratingQuery);
    $ratingStmt->bind_param("i", $bookingId);
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
            echo '
            <div class="booking-card bg-black/40 backdrop-blur-md rounded-xl overflow-hidden border border-white/10 shadow-xl relative" data-status="' . $status . '" data-date="' . $booking['booking_date'] . '" data-payment="' . $paymentStatus . '" data-garage-id="' . $garageId . '">
                ' . $paymentBadge . '
                <div class="p-6">
                    <div class="flex justify-between items-start mb-4">
                        <h3 class="text-xl font-bold text-white">' . htmlspecialchars($garageName) . '</h3>
                        <span class="badge ' . $statusColor . ' text-white px-3 py-2 status-badge">' . $statusText . '</span>
                    </div>
                    
                    <div class="flex items-center gap-2 text-white/80 text-sm mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                        ' . htmlspecialchars($garageAddress) . '
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div class="bg-white/5 p-3 rounded-lg">
                            <div class="text-white/60 text-xs mb-1">Date</div>
                            <div class="text-white font-medium">' . $bookingDate . '</div>
                        </div>
                        <div class="bg-white/5 p-3 rounded-lg">
                            <div class="text-white/60 text-xs mb-1">Time</div>
                            <div class="text-white font-medium">' . $startTime . ' - ' . $endTime . '</div>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div class="bg-white/5 p-3 rounded-lg">
                            <div class="text-white/60 text-xs mb-1">Vehicle Info</div>
                            <div class="text-white font-medium">' . htmlspecialchars($vehicleInfo) . '</div>
                        </div>
                        <div class="bg-white/5 p-3 rounded-lg">
                            <div class="text-white/60 text-xs mb-1">Total Price</div>
                            <div class="flex items-center">
                                <div class="text-primary font-bold">৳' . number_format($price, 2) . '</div>';
                                
                                // Show payment status indicator next to price
                                if ($paymentStatus == 'paid') {
                                    echo '<span class="badge badge-success ml-2 badge-sm">Paid</span>';
                                } else if ($paymentStatus == 'pending') {
                                    echo '<span class="badge badge-warning ml-2 badge-sm">Pending</span>';
                                }
                                
                                echo '
                            </div>
                        </div>
                    </div>
                    
                    ' . $timerHTML . '
                    
                    <div class="flex justify-between items-center mt-6">
                        <div class="text-white/60 text-xs">Booking ID: #' . $bookingId . '</div>
                        <div class="flex gap-2 items-center">
                            ';
                            
                            // Add Pay Now button if payment status is pending
                            if ($paymentStatus == 'pending') {
                                echo '
                                <a href="payment.php?booking_id=' . $bookingId . '" class="btn btn-primary btn-sm text-white">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                                    Pay Now
                                </a>
                                ';
                            }
                            
                            // Only show Edit and Cancel buttons for upcoming bookings
                            if ($status == 'upcoming') {
                                echo '
                                <button type="button" onclick="openEditModal(' . $bookingId . ', \'' . $formattedBookingDate . '\', \'' . $formattedBookingTime . '\', ' . $duration . ')" class="btn btn-outline btn-sm text-white border-white/30 hover:bg-white/10 hover:border-white action-btn">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>
                                    Edit
                                </button>
                                <a href="?cancel=' . $bookingId . '" class="btn btn-outline btn-sm text-error border-error/30 hover:bg-error/10 hover:border-error action-btn" onclick="return confirm(\'Are you sure you want to cancel this booking?\')">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                                    Cancel
                                </a>
                                ';
                            }
                            
                            // *** STEP 3: ADD RATING BUTTONS FOR COMPLETED BOOKINGS ***
                if ($status == 'completed') {
    if ($hasRated) {
        echo '
        <div class="flex items-center gap-2">
            <div class="flex items-center gap-1 bg-success/20 px-2 py-1 rounded-full">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-yellow-400" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                </svg>
                <span class="text-success text-xs font-medium">' . number_format($existingRating, 1) . '</span>
            </div>
            <button type="button" onclick="openRatingModal(' . $bookingId . ', \'' . $garageId . '\', \'' . addslashes($garageName) . '\', ' . $existingRating . ', \'' . addslashes($existingReview ?? '') . '\')" class="btn btn-outline btn-sm text-white border-white/30 hover:bg-white/10 hover:border-white action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>
                Edit Rating
            </button>
        </div>
        ';
    } else {
        echo '
        <button type="button" onclick="openRatingModal(' . $bookingId . ', \'' . $garageId . '\', \'' . addslashes($garageName) . '\')" class="btn btn-primary btn-sm text-white rate-btn">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
            Rate Experience
        </button>
        ';
    }
}
                // *** END OF RATING BUTTONS ***

                            // Add view receipt button for paid bookings
                            if ($paymentStatus == 'paid') {
                                echo '
                                <a href="view_receipt.php?booking_id=' . $bookingId . '" class="btn btn-outline btn-sm text-white border-white/30 hover:bg-white/10 hover:border-white action-btn">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                                    Receipt
                                </a>
                                ';
                            }
                            
                            echo '
                        </div>
                    </div>
                </div>
            </div>
            ';
        }
    } else {
        echo '
        <div class="col-span-full flex flex-col items-center justify-center py-12 text-center">
            <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M8 15h8"></path><path d="M9 9h.01"></path><path d="M15 9h.01"></path></svg>
            </div>
            <h3 class="text-2xl font-semibold text-white mb-2">No Bookings</h3>
            <p class="text-white/70 mb-6 max-w-md">You don\'t have any active bookings. Click the button below to make a new booking.</p>
            <a href="home.php" class="btn bg-primary hover:bg-primary-dark text-white border-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                New Booking
            </a>
        </div>
        ';
    }
    ?>
</div>
        </section>
    </main>
    
    <!-- Edit Booking Modal -->
    <div id="editModal" class="edit-modal">
        <div class="edit-modal-content p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Edit Booking</h3>
                <button type="button" class="text-white/70 hover:text-white" onclick="closeEditModal()">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form id="editBookingForm" method="post" action="">
                <input type="hidden" id="edit_booking_id" name="booking_id">
                <input type="hidden" name="update_booking" value="1">
                
                <div class="form-control mb-4">
                    <label class="label">
                        <span class="label-text text-white">Date</span>
                    </label>
                    <input type="date" id="edit_booking_date" name="booking_date" class="input input-bordered bg-white/15 text-white border-white/20 focus:border-primary" required>
                </div>
                
                <div class="form-control mb-4">
                    <label class="label">
                        <span class="label-text text-white">Time</span>
                    </label>
                    <input type="time" id="edit_booking_time" name="booking_time" class="input input-bordered bg-white/15 text-white border-white/20 focus:border-primary" required>
                </div>
                
                <div class="form-control mb-6">
                    <label class="label">
                        <span class="label-text text-white">Duration (hours)</span>
                    </label>
                    <select id="edit_duration" name="duration" class="select select-bordered bg-white/15 text-white border-white/20 focus:border-primary" required>
                        <option value="1">1 hour</option>
                        <option value="2">2 hours</option>
                        <option value="3">3 hours</option>
                        <option value="4">4 hours</option>
                        <option value="5">5 hours</option>
                        <option value="6">6 hours</option>
                        <option value="12">12 hours</option>
                        <option value="24">24 hours (Full day)</option>
                    </select>
                </div>
                
                <div class="flex justify-end gap-3">
                    <button type="button" class="btn btn-outline border-white/20 text-white" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn bg-primary hover:bg-primary-dark text-white border-none">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Rating Modal - Add this after the Edit Booking Modal -->
<div id="ratingModal" class="edit-modal">
    <div class="edit-modal-content p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-white">Rate Your Experience</h3>
            <button type="button" class="text-white/70 hover:text-white" onclick="closeRatingModal()">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <div id="ratingGarageName" class="text-white/80 mb-4"></div>
        
        <form id="ratingForm">
            <input type="hidden" id="rating_booking_id" name="booking_id">
            <input type="hidden" id="rating_garage_id" name="garage_id">
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white">Rating</span>
                </label>
                <div class="flex gap-2 mb-2">
                    <div class="rating rating-lg">
                        <input type="radio" name="rating" value="1" class="mask mask-star-2 bg-orange-400" />
                        <input type="radio" name="rating" value="2" class="mask mask-star-2 bg-orange-400" />
                        <input type="radio" name="rating" value="3" class="mask mask-star-2 bg-orange-400" />
                        <input type="radio" name="rating" value="4" class="mask mask-star-2 bg-orange-400" />
                        <input type="radio" name="rating" value="5" class="mask mask-star-2 bg-orange-400" />
                    </div>
                </div>
                <div id="ratingText" class="text-sm text-white/60"></div>
            </div>
            
            <div class="form-control mb-6">
                <label class="label">
                    <span class="label-text text-white">Review (Optional)</span>
                </label>
                <textarea name="review_text" id="rating_review" class="textarea textarea-bordered bg-white/15 text-white border-white/20 focus:border-primary h-24" placeholder="Share your experience..."></textarea>
            </div>
            
            <div class="flex justify-end gap-3">
                <button type="button" class="btn btn-outline border-white/20 text-white" onclick="closeRatingModal()">Cancel</button>
                <button type="submit" class="btn bg-primary hover:bg-primary-dark text-white border-none">
                    <span id="submitRatingText">Submit Rating</span>
                    <span id="ratingLoader" class="loading loading-spinner loading-sm hidden"></span>
                </button>
            </div>
        </form>
    </div>
</div>
    
    <footer class="bg-black/70 backdrop-blur-md border-t border-white/10 py-8">
        <div class="container mx-auto px-4 text-center">
            <p class="text-white/70">&copy; <?php echo date('Y'); ?> Car Parking System. All rights reserved.</p>
        </div>
    </footer>
    
    <script>
    // Filter bookings based on status and date
document.getElementById('statusFilter').addEventListener('change', filterBookings);
document.getElementById('dateFilter').addEventListener('change', filterBookings);
document.getElementById('searchBooking').addEventListener('input', filterBookings);

function filterBookings() {
    const statusFilter = document.getElementById('statusFilter').value;
    const dateFilter = document.getElementById('dateFilter').value;
    const searchQuery = document.getElementById('searchBooking').value.toLowerCase();
    
    const bookingCards = document.querySelectorAll('.booking-card');
    
    bookingCards.forEach(card => {
        let showCard = true;
        
        // Status filtering
        if (statusFilter !== 'all') {
            const cardStatus = card.getAttribute('data-status');
            if (cardStatus !== statusFilter) {
                showCard = false;
            }
        }
        
        // Date filtering
        if (dateFilter !== 'all' && showCard) {
            const cardDate = new Date(card.getAttribute('data-date'));
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);
            
            const nextWeek = new Date(today);
            nextWeek.setDate(nextWeek.getDate() + 7);
            
            const nextMonth = new Date(today);
            nextMonth.setMonth(nextMonth.getMonth() + 1);
            
            if (dateFilter === 'today') {
                if (cardDate.toDateString() !== today.toDateString()) {
                    showCard = false;
                }
            } else if (dateFilter === 'tomorrow') {
                if (cardDate.toDateString() !== tomorrow.toDateString()) {
                    showCard = false;
                }
            } else if (dateFilter === 'this_week') {
                if (cardDate < today || cardDate >= nextWeek) {
                    showCard = false;
                }
            } else if (dateFilter === 'this_month') {
                if (cardDate < today || cardDate >= nextMonth) {
                    showCard = false;
                }
            } else if (dateFilter === 'past') {
                if (cardDate >= today) {
                    showCard = false;
                }
            }
        }
        
        // Search filtering
        if (showCard && searchQuery) {
            const cardContent = card.textContent.toLowerCase();
            if (!cardContent.includes(searchQuery)) {
                showCard = false;
            }
        }
        
        // Show or hide the card
        card.style.display = showCard ? 'block' : 'none';
    });
    
    // Check if no results are showing
    const visibleCards = document.querySelectorAll('.booking-card[style="display: block"]');
    const noResultsMsg = document.querySelector('.no-results-message');
    
    if (visibleCards.length === 0 && !noResultsMsg) {
        const noResults = document.createElement('div');
        noResults.className = 'no-results-message col-span-full py-10 text-center';
        noResults.innerHTML = `
            <div class="flex flex-col items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-white/30 mb-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                <h3 class="text-xl font-semibold text-white mb-2">No Results Found</h3>
                <p class="text-white/70">No bookings match your search criteria.</p>
            </div>
        `;
        
        const bookingsGrid = document.querySelector('.grid');
        bookingsGrid.appendChild(noResults);
    } else if (visibleCards.length > 0 && noResultsMsg) {
        noResultsMsg.remove();
    }
}

// Edit Modal Functions
function openEditModal(bookingId, date, time, duration) {
    document.getElementById('edit_booking_id').value = bookingId;
    document.getElementById('edit_booking_date').value = date;
    document.getElementById('edit_booking_time').value = time;
    document.getElementById('edit_duration').value = duration;
    
    document.getElementById('editModal').classList.add('active');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
}

// Close modal when clicking outside
document.getElementById('editModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeEditModal();
    }
});

// Timer functionality
function updateTimers() {
    const now = Math.floor(Date.now() / 1000); // Current time in seconds
    const timerContainers = document.querySelectorAll('.timer-container');
    
    timerContainers.forEach(container => {
        const timestamp = parseInt(container.getAttribute('data-timestamp'));
        const timerType = container.getAttribute('data-type');
        const countdownValue = container.querySelector('.countdown-value');
        
        let timeRemaining = timestamp - now;
        
        if (timeRemaining <= 0 && timerType === 'upcoming') {
            // Booking has started, refresh the page
            location.reload();
            return;
        } else if (timeRemaining <= 0 && timerType === 'active') {
            // Booking has ended, refresh the page
            location.reload();
            return;
        }
        
        // Format the time remaining
        const days = Math.floor(timeRemaining / 86400);
        timeRemaining %= 86400;
        const hours = Math.floor(timeRemaining / 3600);
        timeRemaining %= 3600;
        const minutes = Math.floor(timeRemaining / 60);
        const seconds = timeRemaining % 60;
        
        let timeString = '';
        
        if (days > 0) {
            timeString = `${days}d ${hours}h ${minutes}m`;
        } else if (hours > 0) {
            timeString = `${hours}h ${minutes}m ${seconds}s`;
        } else if (minutes > 0) {
            timeString = `${minutes}m ${seconds}s`;
        } else {
            timeString = `${seconds}s`;
            
            // Add extra emphasis when less than a minute remains
            if (timerType === 'active' && seconds < 60) {
                container.classList.add('bg-red-500/20');
                container.classList.add('text-red-300');
            }
        }
        
        countdownValue.textContent = timeString;
    });
}

// Add styles for the hover effect on cancel button
document.head.insertAdjacentHTML('beforeend', `
  <style>
    /* Cancel button hover effect */
    .action-btn.text-error {
      position: relative;
      overflow: hidden;
      transition: all 0.3s ease;
    }
    
    .action-btn.text-error:hover {
      background-color: #F87272 !important;
      color: white !important;
      border-color: #F87272 !important;
    }
    
    /* Show different text on hover */
    .action-btn.text-error .cancel-text {
      display: inline;
    }
    
    .action-btn.text-error .hover-text {
      display: none;
      white-space: nowrap;
    }
    
    .action-btn.text-error:hover .cancel-text {
      display: none;
    }
    
    .action-btn.text-error:hover .hover-text {
      display: inline;
    }
  </style>
`);

// Function to create custom confirmation dialog
function showCustomConfirm(message, onConfirm, onCancel) {
    const overlay = document.createElement('div');
    overlay.className = 'fixed inset-0 bg-black/70 flex items-center justify-center z-[9999]';
    
    const dialog = document.createElement('div');
    dialog.className = 'bg-black/80 backdrop-blur-md p-6 rounded-xl border border-white/20 max-w-md w-full';
    dialog.innerHTML = `
        <div class="flex items-center mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-yellow-500 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <h3 class="text-xl font-bold text-white">Confirm Cancellation</h3>
        </div>
        <p class="text-white/90 mb-6">${message}</p>
        <div class="flex justify-end gap-3">
            <button id="cancel-btn" class="btn btn-outline border-white/20 text-white">Go Back</button>
            <button id="confirm-btn" class="btn bg-error hover:bg-error/80 text-white border-none">Cancel Booking</button>
        </div>
    `;
    
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);
    
    document.getElementById('cancel-btn').addEventListener('click', function() {
        overlay.remove();
        if (typeof onCancel === 'function') onCancel();
    });
    
    document.getElementById('confirm-btn').addEventListener('click', function() {
        overlay.remove();
        if (typeof onConfirm === 'function') onConfirm();
    });
    
    return overlay;
}

// Update timers immediately and then every second
updateTimers();
setInterval(updateTimers, 1000);

// Function to synchronize booking status with home.php
function syncBookingWithHome() {
    // Check localStorage for any cancelled bookings from home.php
    const cancelledBookings = JSON.parse(localStorage.getItem('cancelledBookings') || '[]');
    
    if (cancelledBookings.length > 0) {
        // Update UI for any cancelled bookings
        cancelledBookings.forEach(garageId => {
            const bookingCards = document.querySelectorAll(`.booking-card[data-garage-id="${garageId}"]`);
            
            bookingCards.forEach(card => {
                // Update status badge
                const statusBadge = card.querySelector('.status-badge');
                if (statusBadge) {
                    statusBadge.className = 'badge bg-error text-white px-3 py-2 status-badge';
                    statusBadge.textContent = 'Cancelled';
                }
                
                // Update data-status attribute for filtering
                card.setAttribute('data-status', 'cancelled');
                
                // Remove timers if present
                const timer = card.querySelector('.timer-container');
                if (timer) {
                    timer.remove();
                }
                
                // Remove action buttons and add a note
                const actionBtns = card.querySelector('.flex.gap-2.items-center');
                if (actionBtns) {
                    actionBtns.innerHTML = '<span class="text-xs text-white/60">Cancelled from home page</span>';
                }
            });
        });
        
        // Clear the localStorage after processing
        localStorage.removeItem('cancelledBookings');
    }
}

// Modify cancel buttons to support hover text and show confirmation
document.addEventListener('DOMContentLoaded', function() {
    // Run synchronization on page load
    syncBookingWithHome();
    
    // Find all cancel buttons
    const cancelButtons = document.querySelectorAll('.action-btn.text-error');
    
    cancelButtons.forEach(button => {
        // Get the current inner HTML
        const originalHTML = button.innerHTML;
        
        // Replace "Cancel" text with spans for hover effect
        const newHTML = originalHTML.replace(
            'Cancel', 
            '<span class="cancel-text">Cancel</span><span class="hover-text">Cancel Booking</span>'
        );
        
        // Update button HTML
        button.innerHTML = newHTML;
        
        // Remove the default confirm handler (important fix!)
        button.removeAttribute('onclick');
        
        // Add confirmation dialog
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const bookingCard = this.closest('.booking-card');
            const garageName = bookingCard.querySelector('h3').textContent.trim();
            const href = this.getAttribute('href');
            
            showCustomConfirm(`Are you sure you want to cancel your booking at ${garageName}?`, function() {
                // On confirm, proceed with the cancellation
                window.location.href = href;
            });
        });
    });
});

// Rating Modal Functions - Add to existing script section
function openRatingModal(bookingId, garageId, garageName, existingRating = null, existingReview = null) {
    document.getElementById('rating_booking_id').value = bookingId;
    document.getElementById('rating_garage_id').value = garageId;
    document.getElementById('ratingGarageName').textContent = `Rate your experience at ${garageName}`;
    
    // Clear form
    document.getElementById('ratingForm').reset();
    document.getElementById('ratingText').textContent = '';
    
    // Pre-fill if editing existing rating
    if (existingRating) {
        document.querySelector(`input[name="rating"][value="${existingRating}"]`).checked = true;
        document.getElementById('ratingText').textContent = {
            1: "Poor - Not satisfied",
            2: "Fair - Below expectations", 
            3: "Good - Met expectations",
            4: "Very Good - Above expectations",
            5: "Excellent - Outstanding experience"
        }[existingRating];
        
        document.getElementById('submitRatingText').textContent = 'Update Rating';
    }
    
    if (existingReview) {
        document.getElementById('rating_review').value = existingReview;
    }
    
    document.getElementById('ratingModal').classList.add('active');
}

function closeRatingModal() {
    document.getElementById('ratingModal').classList.remove('active');
}

function submitRating() {
    const form = document.getElementById('ratingForm');
    const formData = new FormData(form);
    formData.append('action', 'submit_rating');
    
    // Debug: Log form data
    console.log('Form data being sent:');
    for (let [key, value] of formData.entries()) {
        console.log(key + ': ' + value);
    }
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const submitText = document.getElementById('submitRatingText');
    const loader = document.getElementById('ratingLoader');
    
    // Show loading state
    submitBtn.disabled = true;
    submitText.classList.add('hidden');
    loader.classList.remove('hidden');
    
    fetch('rating_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        return response.text(); // Change to text() first to see raw response
    })
    .then(text => {
        console.log('Raw response:', text);
        try {
            const data = JSON.parse(text);
            console.log('Parsed response:', data);
            
            if (data.success) {
                showNotification('Rating submitted successfully!', 'success');
                closeRatingModal();
                setTimeout(() => location.reload(), 1500);
            } else {
                showNotification(data.message || 'Error submitting rating', 'error');
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            console.error('Raw response was:', text);
            showNotification('Server response error. Check console for details.', 'error');
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        showNotification('Network error. Please check console for details.', 'error');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitText.classList.remove('hidden');
        loader.classList.add('hidden');
    });
}

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} fixed top-4 right-4 w-auto max-w-md z-[10000] shadow-lg`;
    notification.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
            ${type === 'success' ? 
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />' :
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />'
            }
        </svg>
        <span>${message}</span>
    `;
    
    document.body.appendChild(notification);
    setTimeout(() => notification.remove(), 3000);
}

// Initialize rating system - Add to existing DOMContentLoaded event
document.addEventListener('DOMContentLoaded', function() {
    // Add rating form handler
    const ratingForm = document.getElementById('ratingForm');
    if (ratingForm) {
        ratingForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitRating();
        });
        
        // Rating text update
        const ratingInputs = document.querySelectorAll('input[name="rating"]');
        const ratingText = document.getElementById('ratingText');
        const ratingTexts = {
            1: "Poor - Not satisfied",
            2: "Fair - Below expectations", 
            3: "Good - Met expectations",
            4: "Very Good - Above expectations",
            5: "Excellent - Outstanding experience"
        };
        
        ratingInputs.forEach(input => {
            input.addEventListener('change', function() {
                ratingText.textContent = ratingTexts[this.value];
            });
        });
    }
});
    </script>

    <!-- Points Modal - Add this before closing body tag -->
<dialog id="points_modal" class="modal modal-bottom sm:modal-middle">
  <div class="modal-box bg-black/80 backdrop-blur-xl border border-primary/20">
    <h3 class="font-bold text-lg text-white mb-4 flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary" fill="currentColor" viewBox="0 0 24 24">
            <polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26"></polygon>
        </svg>
        My Points
    </h3>
    
    <!-- Points Stats -->
    <div class="grid grid-cols-2 gap-4 mb-6">
        <div class="bg-primary/10 p-4 rounded-lg border border-primary/20">
            <div class="text-primary text-2xl font-bold"><?php echo $pointsStats['current_points']; ?></div>
            <div class="text-white/80 text-sm">Current Points</div>
        </div>
        
        <div class="bg-green-500/10 p-4 rounded-lg border border-green-500/20">
            <div class="text-green-400 text-2xl font-bold"><?php echo $pointsStats['free_hours_available']; ?></div>
            <div class="text-white/80 text-sm">Free Hours Available</div>
        </div>
        
        <div class="bg-blue-500/10 p-4 rounded-lg border border-blue-500/20">
            <div class="text-blue-400 text-xl font-bold"><?php echo $pointsStats['total_earned']; ?></div>
            <div class="text-white/80 text-sm">Total Earned</div>
        </div>
        
        <div class="bg-orange-500/10 p-4 rounded-lg border border-orange-500/20">
            <div class="text-orange-400 text-xl font-bold"><?php echo $pointsStats['total_spent']; ?></div>
            <div class="text-white/80 text-sm">Total Spent</div>
        </div>
    </div>
    
    <!-- How Points Work -->
    <div class="bg-white/5 p-4 rounded-lg mb-4">
        <h4 class="text-white font-semibold mb-2">How Points Work:</h4>
        <ul class="text-white/80 text-sm space-y-1">
            <li>• Earn 15 points for every hour you park</li>
            <li>• Use 150 points to get 1 hour of free parking</li>
            <li>• Points are awarded when your booking is completed</li>
        </ul>
    </div>
    
    <div class="modal-action">
      <button class="btn bg-primary hover:bg-primary-dark text-white border-none" onclick="points_modal.close()">
        Close
      </button>
    </div>
  </div>
</dialog>

<script>
function showPointsModal() {
    points_modal.showModal();
    
    // Add blur to main content
    document.body.style.filter = 'blur(5px)';
    document.querySelector('.modal-box').style.filter = 'none'; // Keep modal content sharp
}

// Add event listener to remove blur when modal closes
points_modal.addEventListener('close', function() {
    document.body.style.filter = 'none';
});

// Update points display when profile is updated
function updatePointsDisplay(newPoints) {
    document.getElementById('user-points-display').textContent = newPoints + ' PTS';
}
</script>

</body>
</html>