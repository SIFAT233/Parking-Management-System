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
// Get first letter for avatar
$firstLetter = strtoupper(substr($fullName, 0, 1));

// Get payment history for the user
$paymentHistoryQuery = "SELECT b.id, b.booking_date, b.booking_time, b.duration, 
                        b.payment_status, b.status as booking_status,
                        b.paid_with_points, b.points_used as booking_points_used,
                        g.Parking_Space_Name, g.Parking_Lot_Address, g.PriceperHour,
                        v.licensePlate, v.vehicleType, v.make, v.model, v.color,
                        p.payment_method, p.transaction_id, p.payment_date, 
                        p.amount as paid_amount, p.points_used as payment_points_used
                        FROM bookings b
                        JOIN garage_information g ON b.garage_id = g.garage_id
                        LEFT JOIN vehicle_information v ON b.licenseplate = v.licensePlate
                        LEFT JOIN payments p ON b.id = p.booking_id
                        WHERE b.username = '$username'
                        ORDER BY b.booking_date DESC, b.booking_time DESC";

$paymentHistoryResult = $conn->query($paymentHistoryQuery);

// Updated calculation logic that accounts for points payments
$totalPaid = 0;
$totalDue = 0;
$paidPayments = [];
$duePayments = [];
$refundedPayments = [];

if ($paymentHistoryResult && $paymentHistoryResult->num_rows > 0) {
    while ($payment = $paymentHistoryResult->fetch_assoc()) {
        // Calculate amount based on payment method
        if ($payment['paid_with_points'] == 1) {
            // Points payment - show points used instead of money
            $amount = 0; // No money charged
            $pointsUsed = $payment['booking_points_used'];
        } else {
            // Regular payment
            $amount = $payment['paid_amount'] ? $payment['paid_amount'] : ($payment['PriceperHour'] * $payment['duration']);
            $pointsUsed = 0;
        }
        
        // Categorize payments by status
        if ($payment['payment_status'] == 'paid') {
            $totalPaid += $amount;
            $paidPayments[] = $payment;
        } elseif ($payment['payment_status'] == 'refunded') {
            $refundedPayments[] = $payment;
        } else {
            $totalDue += $amount;
            $duePayments[] = $payment;
        }
    }
}
// Function to get payment method badge
function getPaymentMethodBadge($payment) {
    if ($payment['paid_with_points'] == 1) {
        return '<span class="badge bg-purple-500 text-white">Points</span>';
    } elseif ($payment['payment_method']) {
        $method = ucfirst($payment['payment_method']);
        return '<span class="badge bg-blue-500 text-white">' . htmlspecialchars($method) . '</span>';
    }
    return '<span class="badge bg-gray-500 text-white">Pending</span>';
}

// Function to get booking status badge
function getBookingStatusBadge($status) {
    $statusColors = [
        'upcoming' => 'bg-blue-500',
        'active' => 'bg-green-500', 
        'completed' => 'bg-gray-500',
        'cancelled' => 'bg-red-500'
    ];
    
    $color = $statusColors[$status] ?? 'bg-gray-500';
    return '<span class="badge ' . $color . ' text-white">' . ucfirst($status) . '</span>';
}

// Function to display amount or points
function getAmountDisplay($payment) {
    if ($payment['paid_with_points'] == 1) {
        return '<div class="text-purple-500 font-bold">' . $payment['booking_points_used'] . ' Points</div>';
    } else {
        $amount = $payment['paid_amount'] ? $payment['paid_amount'] : ($payment['PriceperHour'] * $payment['duration']);
        return '<div class="text-white font-bold">৳' . number_format($amount, 2) . '</div>';
    }
}
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
    <title>Payment History - Car Parking System</title>
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
                        fadeIn: 'fadeIn 0.5s ease-out'
                    }
                }
            }
        }
    </script>
    <style>
        .payment-card {
            transition: all 0.3s ease;
        }
        .payment-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
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
        <!-- Page Header -->
        <section class="flex flex-col md:flex-row justify-between items-center mb-8">
            <div>
                <h2 class="text-3xl md:text-4xl font-bold text-white mb-2">Payment History</h2>
                <p class="text-white/80">View your payment history and pending payments</p>
            </div>
        </section>
        
        <!-- Payment Summary -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
            <div class="bg-[#0f1122]/90 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl">
                <div class="flex items-center gap-4 mb-4">
                    <div class="w-12 h-12 bg-primary/20 rounded-full flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-white">Total Payments</h3>
                        <p class="text-white/60 text-sm"><?php echo count($paidPayments) + count($duePayments); ?> bookings</p>
                    </div>
                </div>
                <div class="text-3xl font-bold text-white">৳<?php echo number_format($totalPaid + $totalDue, 2); ?></div>
            </div>
            
            <div class="bg-[#0f1122]/90 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl">
                <div class="flex items-center gap-4 mb-4">
                    <div class="w-12 h-12 bg-green-500/20 rounded-full flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-white">Paid</h3>
                        <p class="text-white/60 text-sm"><?php echo count($paidPayments); ?> bookings</p>
                    </div>
                </div>
                <div class="text-3xl font-bold text-green-500">৳<?php echo number_format($totalPaid, 2); ?></div>
            </div>
            
            <div class="bg-[#0f1122]/90 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl">
                <div class="flex items-center gap-4 mb-4">
                    <div class="w-12 h-12 bg-red-500/20 rounded-full flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-red-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-white">Due</h3>
                        <p class="text-white/60 text-sm"><?php echo count($duePayments); ?> bookings</p>
                    </div>
                </div>
                <div class="text-3xl font-bold text-red-500">৳<?php echo number_format($totalDue, 2); ?></div>
            </div>
        </div>
        
        <!-- Tabs for Pending and Paid Payments -->
        <div class="tabs tabs-boxed bg-black/30 backdrop-blur-sm mb-6 inline-flex">
            <a class="tab tab-active" id="tab-pending">Pending Payments</a>
            <a class="tab" id="tab-paid">Payment History</a>
        </div>
        
        <!-- Pending Payments Section -->
        <div id="pending-payments" class="animate-fadeIn">
    <?php if (count($duePayments) > 0): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($duePayments as $payment): ?>
                <?php
                    $bookingId = $payment['id'];
                    $bookingDate = date('d M Y', strtotime($payment['booking_date']));
                    $startTime = date('h:i A', strtotime($payment['booking_time']));
                    $duration = $payment['duration'];
                    $endTime = date('h:i A', strtotime($payment['booking_time'] . " + {$duration} hours"));
                    
                    // Calculate amount - check if it can be paid with points
                    $amount = $payment['PriceperHour'] * $payment['duration'];
                    $pointsNeeded = $amount * 10; // Adjust based on your points system (currently 10 points per taka)
                    $canPayWithPoints = $userPoints >= $pointsNeeded;
                    
                    $locationName = $payment['Parking_Space_Name'];
                    $vehicleInfo = $payment['licensePlate'] ? $payment['make'] . ' ' . $payment['model'] . ' (' . $payment['licensePlate'] . ')' : 'Not provided';
                ?>
                <div class="payment-card bg-[#0f1122]/90 backdrop-blur-md rounded-lg border border-white/10 overflow-hidden shadow-xl">
                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <h3 class="text-xl font-bold text-white"><?php echo htmlspecialchars($locationName); ?></h3>
                            <div class="flex gap-2">
                                <?php echo getBookingStatusBadge($payment['booking_status']); ?>
                                <span class="badge bg-red-500 text-white px-3 py-2">Due</span>
                            </div>
                        </div>
                        
                        <div class="space-y-4 mb-6">
                            <div class="flex justify-between items-center pb-2 border-b border-white/10">
                                <span class="text-white/70">Booking ID</span>
                                <span class="text-white font-medium">#<?php echo $bookingId; ?></span>
                            </div>
                            
                            <div class="flex justify-between items-center pb-2 border-b border-white/10">
                                <span class="text-white/70">Date & Time</span>
                                <span class="text-white font-medium"><?php echo $bookingDate; ?>, <?php echo $startTime; ?> - <?php echo $endTime; ?></span>
                            </div>
                            
                            <div class="flex justify-between items-center pb-2 border-b border-white/10">
                                <span class="text-white/70">Vehicle</span>
                                <span class="text-white font-medium"><?php echo htmlspecialchars($vehicleInfo); ?></span>
                            </div>
                            
                            <div class="flex justify-between items-center">
                                <span class="text-white font-semibold">Amount Due</span>
                                <span class="text-red-500 text-xl font-bold">৳<?php echo number_format($amount, 2); ?></span>
                            </div>
                            
                            <?php if ($canPayWithPoints): ?>
                            <div class="bg-purple-500/20 p-3 rounded-lg border border-purple-500/30">
                                <div class="flex justify-between items-center">
                                    <span class="text-purple-300">Pay with Points</span>
                                    <span class="text-purple-300 font-bold"><?php echo $pointsNeeded; ?> Points</span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="flex gap-2">
                            <a href="payment.php?booking_id=<?php echo $bookingId; ?>" class="btn bg-primary hover:bg-primary-dark text-white border-none flex-1">
                                Pay Now
                            </a>
                            <?php if ($canPayWithPoints): ?>
                            <a href="payment.php?booking_id=<?php echo $bookingId; ?>&use_points=1" class="btn bg-purple-500 hover:bg-purple-600 text-white border-none">
                                Use Points
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="bg-[#0f1122]/90 backdrop-blur-md rounded-lg border border-white/10 p-10 text-center">
            <div class="w-20 h-20 bg-green-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            </div>
            <h3 class="text-2xl font-semibold text-white mb-2">No Pending Payments</h3>
            <p class="text-white/70 mb-6">You don't have any pending payments at the moment.</p>
        </div>
    <?php endif; ?>
</div>
        
        <!-- Payment History Section -->
        <div id="payment-history" class="animate-fadeIn hidden">
            <?php if (count($paidPayments) > 0): ?>
                <div class="bg-[#0f1122]/90 backdrop-blur-md rounded-lg border border-white/10 overflow-hidden shadow-xl">
    <div class="overflow-x-auto">
        <table class="table table-zebra">
            <thead>
                <tr class="bg-black/30 text-white">
                    <th>Booking ID</th>
                    <th>Date</th>
                    <th>Location</th>
                    <th>Vehicle</th>
                    <th>Duration</th>
                    <th>Amount/Points</th>
                    <th>Payment Method</th>
                    <th>Booking Status</th>
                    <th>Payment Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paidPayments as $payment): ?>
                    <?php
                        $bookingId = $payment['id'];
                        $bookingDate = date('d M Y', strtotime($payment['booking_date']));
                        $startTime = date('h:i A', strtotime($payment['booking_time']));
                        $duration = $payment['duration'];
                        $locationName = $payment['Parking_Space_Name'];
                        $vehicleInfo = $payment['licensePlate'] ? $payment['licensePlate'] : 'Not provided';
                    ?>
                    <tr>
                        <td>#<?php echo $bookingId; ?></td>
                        <td>
                            <div><?php echo $bookingDate; ?></div>
                            <div class="text-xs text-gray-500"><?php echo $startTime; ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($locationName); ?></td>
                        <td><?php echo htmlspecialchars($vehicleInfo); ?></td>
                        <td><?php echo $duration; ?> hours</td>
                        <td><?php echo getAmountDisplay($payment); ?></td>
                        <td><?php echo getPaymentMethodBadge($payment); ?></td>
                        <td><?php echo getBookingStatusBadge($payment['booking_status']); ?></td>
                        <td>
                            <?php if ($payment['payment_status'] == 'refunded'): ?>
                                <span class="badge bg-orange-500 text-white">Refunded</span>
                            <?php else: ?>
                                <span class="badge bg-green-500 text-white">Paid</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="view_booking.php?id=<?php echo $bookingId; ?>" class="btn btn-ghost btn-xs">
                                View
                            </a>
                            <?php if ($payment['payment_method'] && $payment['transaction_id']): ?>
                                <div class="text-xs text-gray-500 mt-1">
                                    TX: <?php echo substr($payment['transaction_id'], -8); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
            <?php else: ?>
                <div class="bg-[#0f1122]/90 backdrop-blur-md rounded-lg border border-white/10 p-10 text-center">
                    <div class="w-20 h-20 bg-white/5 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M8 15h8"></path><path d="M9 9h.01"></path><path d="M15 9h.01"></path></svg>
                    </div>
                    <h3 class="text-2xl font-semibold text-white mb-2">No Payment History</h3>
                    <p class="text-white/70 mb-6">You haven't made any payments yet.</p>
                </div>
            <?php endif; ?>
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
    
    <script>
        // Tab switching functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tabPending = document.getElementById('tab-pending');
            const tabPaid = document.getElementById('tab-paid');
            const pendingSection = document.getElementById('pending-payments');
            const historySection = document.getElementById('payment-history');
            
            tabPending.addEventListener('click', function() {
                tabPending.classList.add('tab-active');
                tabPaid.classList.remove('tab-active');
                pendingSection.classList.remove('hidden');
                historySection.classList.add('hidden');
            });
            
            tabPaid.addEventListener('click', function() {
                tabPaid.classList.add('tab-active');
                tabPending.classList.remove('tab-active');
                historySection.classList.remove('hidden');
                pendingSection.classList.add('hidden');
            });
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
// Function to show points modal
function showPointsModal() {
    points_modal.showModal();
    
    // Add blur to main content
    document.body.style.filter = 'blur(3px)';
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