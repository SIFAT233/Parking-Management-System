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
$phone = "";
$address = "";
$firstName = "";
$lastName = "";

// Try to get user's personal information using username as foreign key
$query = "SELECT * FROM personal_information WHERE username = '$username'";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstName = $row['firstName'];
    $lastName = $row['lastName'];
    $fullName = $firstName . ' ' . $lastName;
    $email = $row['email'];
    $phone = $row['phone'];
    $address = $row['address'];
    
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

// Check user verification status
$verificationStatus = 'unverified';
$verificationQuery = "SELECT status FROM account_information WHERE username = ?";
$verificationStmt = $conn->prepare($verificationQuery);
$verificationStmt->bind_param("s", $username);
$verificationStmt->execute();
$verificationResult = $verificationStmt->get_result();

if ($verificationResult && $verificationResult->num_rows > 0) {
    $verificationRow = $verificationResult->fetch_assoc();
    $verificationStatus = $verificationRow['status'];
}

// Check if user has pending verification request
$pendingVerification = false;
$pendingQuery = "SELECT id FROM verification_requests WHERE username = ? AND overall_status IN ('pending', 'under_review') ORDER BY requested_at DESC LIMIT 1";
$pendingStmt = $conn->prepare($pendingQuery);
$pendingStmt->bind_param("s", $username);
$pendingStmt->execute();
$pendingResult = $pendingStmt->get_result();
$pendingVerification = $pendingResult && $pendingResult->num_rows > 0;

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
    'total_bonus' => 0,
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
}

// Get bonus points separately
$bonusQuery = "SELECT COALESCE(SUM(points_amount), 0) as total_bonus 
               FROM points_transactions 
               WHERE username = ? 
               AND transaction_type = 'bonus'";
$stmt3 = $conn->prepare($bonusQuery);
$stmt3->bind_param("s", $username);
$stmt3->execute();
$result3 = $stmt3->get_result();

if ($result3 && $result3->num_rows > 0) {
    $row3 = $result3->fetch_assoc();
    $stats['total_bonus'] = (int)$row3['total_bonus'];
}

// Calculate total earned (bookings + bonus)
$stats['total_earned'] = $stats['points_from_bookings'] + $stats['total_bonus'];
    
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
// Function to get recent points transactions
function getPointsTransactions($username, $conn, $limit = 5) {
    $query = "SELECT 
                pt.transaction_type,
                pt.points_amount,
                pt.description,
                pt.created_at,
                pt.booking_id,
                b.garage_id,
                gi.Parking_Space_Name
              FROM points_transactions pt
              LEFT JOIN bookings b ON pt.booking_id = b.id
              LEFT JOIN garage_information gi ON b.garage_id = gi.garage_id
              WHERE pt.username = ?
              ORDER BY pt.created_at DESC
              LIMIT ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $username, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $transactions = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
    }
    
    return $transactions;
}
$pointsStats = getDetailedPointsStats($username, $conn);
$recentTransactions = getPointsTransactions($username, $conn, 5); // Add this line

// Get user's vehicles
$vehicles = [];
$vehicleQuery = "SELECT * FROM vehicle_information WHERE username = '$username'";
$vehicleResult = $conn->query($vehicleQuery);

if ($vehicleResult && $vehicleResult->num_rows > 0) {
    while ($row = $vehicleResult->fetch_assoc()) {
        $vehicles[] = $row;
    }
}

// Get user's bookings
$bookings = [];
$bookingQuery = "SELECT b.*, g.Parking_Space_Name, g.Parking_Lot_Address, g.PriceperHour 
                FROM bookings b 
                JOIN garage_information g ON b.garage_id = g.garage_id 
                WHERE b.username = '$username' 
                ORDER BY b.booking_date DESC, b.booking_time DESC 
                LIMIT 5";
$bookingResult = $conn->query($bookingQuery);

if ($bookingResult && $bookingResult->num_rows > 0) {
    while ($row = $bookingResult->fetch_assoc()) {
        $bookings[] = $row;
    }
} else {
    // Sample data if no bookings found
    $bookings = [
        [
            'id' => 'N/A',
            'garage_id' => 'N/A',
            'Parking_Space_Name' => 'No bookings found',
            'Parking_Lot_Address' => 'N/A',
            'booking_date' => date('Y-m-d'),
            'booking_time' => '00:00:00',
            'duration' => 0,
            'status' => 'N/A',
            'payment_status' => 'N/A',
            'PriceperHour' => 0
        ]
    ];
}

// Get user's parking spaces (if they are an owner)
$parkingSpaces = [];
$parkingQuery = "SELECT * FROM garage_information WHERE username = '$username'";
$parkingResult = $conn->query($parkingQuery);

if ($parkingResult && $parkingResult->num_rows > 0) {
    while ($row = $parkingResult->fetch_assoc()) {
        $parkingSpaces[] = $row;
    }
}

// Check if user has account information
$accountQuery = "SELECT * FROM account_information WHERE username = '$username'";
$accountResult = $conn->query($accountQuery);
$accountInfo = null;

if ($accountResult && $accountResult->num_rows > 0) {
    $accountInfo = $accountResult->fetch_assoc();
}

// Function to calculate total spent on bookings
function calculateTotalSpent($username, $conn) {
    $query = "SELECT SUM(g.PriceperHour * b.duration) as total 
              FROM bookings b 
              JOIN garage_information g ON b.garage_id = g.garage_id 
              WHERE b.username = '$username' AND b.payment_status = 'paid'";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['total'] ? $row['total'] : 0;
    }
    return 0;
}

$totalSpent = calculateTotalSpent($username, $conn);

// Function to get total bookings count
function getTotalBookings($username, $conn) {
    $query = "SELECT COUNT(*) as count FROM bookings WHERE username = '$username'";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['count'];
    }
    return 0;
}

$totalBookings = getTotalBookings($username, $conn);

// Function to get favorite parking location
function getFavoriteLocation($username, $conn) {
    $query = "SELECT g.Parking_Space_Name, COUNT(*) as count 
              FROM bookings b 
              JOIN garage_information g ON b.garage_id = g.garage_id 
              WHERE b.username = '$username' 
              GROUP BY g.Parking_Space_Name 
              ORDER BY count DESC 
              LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['Parking_Space_Name'];
    }
    return 'None';
}

$favoriteLocation = getFavoriteLocation($username, $conn);

// Get account creation date
function getAccountCreationDate($username, $conn) {
    $query = "SELECT registration_date FROM account_information WHERE username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return date('F j, Y', strtotime($row['registration_date']));
    }
    
    // If registration date not found (for older accounts), return fallback
    return date('F j, Y');
}

// Get last login date
function getLastLoginDate($username, $conn) {
    $query = "SELECT last_login FROM account_information WHERE username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row['last_login']) {
            return date('F j, Y, g:i a', strtotime($row['last_login']));
        }
    }
    
    // Return current date/time if no last login found
    return date('F j, Y, g:i a');
}

$accountCreationDate = getAccountCreationDate($username, $conn);

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
    <title>My Profile - পার্কিং লাগবে</title>
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
</head>
<body class="relative min-h-screen">
    <!-- Background Image with Overlay -->
    <div class="fixed inset-0 bg-cover bg-center bg-no-repeat z-[-2]" 
         style="background-image: url('https://images.unsplash.com/photo-1573348722427-f1d6819fdf98?q=80&w=1374&auto=format&fit=crop&ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D')">
    </div>
    <div class="fixed inset-0 bg-black/50 z-[-1]"></div>
    
    <!-- Header Based on home.php -->
    <?php
// Add this to your my_profile.php after line where you get user information

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


?>

<!-- Updated Header with Points Display -->
<header class="sticky top-0 z-50 bg-black/50 backdrop-blur-md border-b border-white/20">
    <div class="container mx-auto px-4 py-4 flex justify-between items-center">
        <a href="#" class="flex items-center gap-4 text-white">
            <div class="w-10 h-10 bg-primary rounded-full flex justify-center items-center overflow-hidden">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><path d="M9 18V6h4.5a2.5 2.5 0 0 1 0 5H9"></path></svg>
            </div>
            <h1 class="text-xl font-semibold drop-shadow-md">পার্কিং লাগবে</h1>
        </a>
        
        <nav class="hidden md:block">
            <ul class="flex gap-8">
                <li><a href="home.php" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Home</a></li>
                <li><a href="#" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Find Parking</a></li>
                <li><a href="#" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">My Bookings</a></li>
                <li><a href="my_profile.php" class="text-primary font-medium transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-full after:bg-primary after:transition-all">My Profile</a></li>
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
        <!-- Profile Header -->
        <section class="flex flex-col md:flex-row items-center md:items-start gap-8 mb-10">
            <div class="w-32 h-32 rounded-full bg-primary/20 border-4 border-primary overflow-hidden flex items-center justify-center">
                <span class="text-5xl font-bold text-primary avatar-initial"><?php echo $firstLetter; ?></span>
            </div>
            
            <div class="flex-1 text-center md:text-left">
                <h2 id="display_header_fullName" class="text-3xl md:text-4xl font-bold text-white mb-2 flex items-center justify-center md:justify-start gap-2">
        <?php echo htmlspecialchars($fullName); ?>
        <?php if ($levelIcon): ?>
            <span class="text-4xl" title="<?php echo $levelName; ?> Level"><?php echo $levelIcon; ?></span>
        <?php endif; ?>
    </h2>
                <p class="text-white/80 mb-4">Member since <?php echo $accountCreationDate; ?></p>
                
                <div class="flex flex-wrap justify-center md:justify-start gap-4 mb-6">
                    <div class="bg-black/30 backdrop-blur-md rounded-lg border border-white/10 p-4 min-w-[120px]">
                        <p class="text-white/60 text-sm">Total Bookings</p>
                        <p class="text-white text-xl font-semibold"><?php echo $totalBookings; ?></p>
                    </div>
                    
                    <div class="bg-black/30 backdrop-blur-md rounded-lg border border-white/10 p-4 min-w-[120px]">
                        <p class="text-white/60 text-sm">Total Spent</p>
                        <p class="text-white text-xl font-semibold">৳<?php echo number_format($totalSpent, 0); ?></p>
                    </div>
                    
                    <div class="bg-black/30 backdrop-blur-md rounded-lg border border-white/10 p-4 min-w-[180px]">
                        <p class="text-white/60 text-sm">Favorite Location</p>
                        <p class="text-white text-xl font-semibold truncate"><?php echo htmlspecialchars($favoriteLocation); ?></p>
                    </div>
                </div>
                
                <div class="flex flex-wrap justify-center md:justify-start gap-4">
                    <button id="edit_profile_btn" class="btn bg-primary hover:bg-primary-dark text-white border-none">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        Edit Profile
                    </button>
                    <button id="change_password_btn" class="btn bg-primary hover:bg-primary-dark text-white border-none">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
    Change Password
</button>
                </div>
            </div>
        </section>
        
        <!-- Account Information -->
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-10">
            <!-- Personal Information -->
            <div class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl lg:col-span-2">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-white text-xl font-semibold">Personal Information</h3>
                    <button id="edit_personal_info_btn" class="btn btn-sm btn-ghost text-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        Edit
                    </button>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <p class="text-white/60 text-sm mb-1">Full Name</p>
                        <p id="display_fullName" class="text-white text-lg"><?php echo htmlspecialchars($fullName); ?></p>
                    </div>
                    
                    <div>
                        <p class="text-white/60 text-sm mb-1">Username</p>
                        <p class="text-white text-lg"><?php echo htmlspecialchars($username); ?></p>
                    </div>
                    
                    <div>
                        <p class="text-white/60 text-sm mb-1">Email Address</p>
                        <p id="display_email" class="text-white text-lg"><?php echo htmlspecialchars($email); ?></p>
                    </div>
                    
                    <div>
                        <p class="text-white/60 text-sm mb-1">Phone Number</p>
                        <p id="display_phone" class="text-white text-lg"><?php echo htmlspecialchars($phone); ?></p>
                    </div>
                    
                    <div class="md:col-span-2">
                        <p class="text-white/60 text-sm mb-1">Address</p>
                        <p id="display_address" class="text-white text-lg"><?php echo htmlspecialchars($address); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Account Security -->
            <div class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl">
    <h3 class="text-white text-xl font-semibold mb-6">Account Security</h3>
    
    <div class="space-y-6">
        <!-- Account Verification Status -->
        <div>
            <div class="flex justify-between items-center mb-2">
                <p class="text-white/60 text-sm">Account Verification</p>
                <?php if ($verificationStatus === 'unverified' && !$pendingVerification): ?>
                    <button id="verify_account_btn" class="btn btn-xs bg-primary hover:bg-primary-dark text-white border-none">
                        Verify Now
                    </button>
                <?php elseif ($pendingVerification): ?>
                    <span class="btn btn-xs btn-disabled">Under Review</span>
                <?php else: ?>
                    <span class="btn btn-xs btn-success">Verified ✓</span>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-2">
                <?php if ($verificationStatus === 'verified'): ?>
                    <div class="badge badge-success badge-sm">Verified</div>
                    <span class="text-white/80">Your account is verified</span>
                <?php elseif ($pendingVerification): ?>
                    <div class="badge badge-warning badge-sm">Pending</div>
                    <span class="text-white/80">Verification under review</span>
                <?php else: ?>
                    <div class="badge badge-error badge-sm">Not Verified</div>
                    <span class="text-white/80">Verify your account for enhanced security</span>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Password Section -->
        <div>
            <div class="flex justify-between items-center mb-2">
                <p class="text-white/60 text-sm">Password</p>
                <button id="change_password_security_btn" class="btn btn-xs btn-ghost text-primary">Change</button>
            </div>
            <p class="text-white text-lg">••••••••</p>
        </div>
        
        <!-- Last Login -->
        <div>
            <div class="flex justify-between items-center mb-2">
                <p class="text-white/60 text-sm">Last Login</p>
            </div>
            <p class="text-white/80"><?php echo getLastLoginDate($username, $conn); ?></p>
        </div>
    </div>
</div>
        </section>
        
        <!-- Vehicles Section -->
        <section class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl mb-10">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-white text-xl font-semibold">My Vehicles</h3>
                <a href="add_vehicle.php" class="btn btn-sm bg-primary hover:bg-primary-dark text-white border-none">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Add Vehicle
                </a>
            </div>
            
            <?php if (empty($vehicles)): ?>
            <div class="text-center py-10">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mx-auto text-white/30 mb-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                <p class="text-white/70 text-lg mb-4">You haven't added any vehicles yet</p>
                <a href="add_vehicle.php" class="btn bg-primary hover:bg-primary-dark text-white border-none">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Add Your First Vehicle
                </a>
            </div>
            <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($vehicles as $vehicle): ?>
                <div class="bg-black/30 backdrop-blur-md rounded-lg border border-white/10 p-5 hover:border-primary/50 transition-all">
                    <div class="flex justify-between items-start mb-4">
                        <div class="w-12 h-12 rounded-full bg-primary/20 flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.5 2.8C1.4 11.3 1 12.1 1 13v3c0 .6.4 1 1 1h2"></path><circle cx="7" cy="17" r="2"></circle><circle cx="17" cy="17" r="2"></circle></svg>
                        </div>
                        <div class="dropdown dropdown-end">
                            <div tabindex="0" role="button" class="btn btn-ghost btn-xs">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-white/70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle cx="5" cy="12" r="1"></circle></svg>
                            </div>
                            <ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-32">
                                <li><a>Edit</a></li>
                                <li><a class="text-red-500">Delete</a></li>
                            </ul>
                        </div>
                    </div>
                    
                    <h4 class="text-white text-lg font-medium mb-1"><?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']); ?></h4>
                    <p class="text-white/70 text-sm mb-3"><?php echo htmlspecialchars($vehicle['vehicleType']); ?> • <?php echo htmlspecialchars($vehicle['color']); ?></p>
                    
                    <div class="bg-white/10 rounded-lg p-3 text-center">
                        <p class="text-white/60 text-xs mb-1">License Plate</p>
                        <p class="text-white text-lg font-semibold"><?php echo htmlspecialchars($vehicle['licensePlate']); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
        
        <!-- Recent Bookings -->
        <section class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl mb-10">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-white text-xl font-semibold">Recent Bookings</h3>
                <a href="#" class="text-primary hover:text-primary-dark text-sm">View All</a>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-white/90">
                    <thead class="text-left border-b border-white/20">
                        <tr>
                            <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Booking ID</th>
                            <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Parking Space</th>
                            <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Date</th>
                            <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Duration</th>
                            <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Amount</th>
                            <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Status</th>
                            <th class="py-3 px-2 text-xs font-medium text-white/60 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <?php foreach ($bookings as $booking): ?>
                        <tr class="hover:bg-white/5">
                            <td class="py-3 px-2 text-sm font-medium"><?php echo htmlspecialchars($booking['id']); ?></td>
                            <td class="py-3 px-2 text-sm"><?php echo htmlspecialchars($booking['Parking_Space_Name']); ?></td>
                            <td class="py-3 px-2 text-sm"><?php echo date('M j, Y', strtotime($booking['booking_date'])); ?></td>
                            <td class="py-3 px-2 text-sm"><?php echo $booking['duration']; ?> hours</td>
                            <td class="py-3 px-2 text-sm">৳<?php echo number_format($booking['duration'] * $booking['PriceperHour'], 0); ?></td>
                            <td class="py-3 px-2 text-sm">
                                <?php if ($booking['status'] === 'active'): ?>
                                <span class="px-2 py-1 rounded-full text-xs bg-green-500/20 text-green-400">Active</span>
                                <?php elseif ($booking['status'] === 'upcoming'): ?>
                                <span class="px-2 py-1 rounded-full text-xs bg-blue-500/20 text-blue-400">Upcoming</span>
                                <?php elseif ($booking['status'] === 'completed'): ?>
                                <span class="px-2 py-1 rounded-full text-xs bg-gray-500/20 text-gray-400">Completed</span>
                                <?php else: ?>
                                <span class="px-2 py-1 rounded-full text-xs bg-red-500/20 text-red-400">Cancelled</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-2 text-sm">
                                <div class="dropdown dropdown-end">
                                    <div tabindex="0" role="button" class="btn btn-ghost btn-xs">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle cx="5" cy="12" r="1"></circle></svg>
                                    </div>
                                    <ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-32">
                                        <li><a>View Details</a></li>
                                        <?php if ($booking['status'] === 'upcoming'): ?>
                                        <li><a class="text-red-500">Cancel</a></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        
        <!-- Parking Spaces (if user is an owner) -->
        <?php if (!empty($parkingSpaces)): ?>
        <section class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl mb-10">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-white text-xl font-semibold">My Parking Spaces</h3>
                <a href="business_dashboard.php" class="text-primary hover:text-primary-dark text-sm">Go to Business Dashboard</a>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($parkingSpaces as $index => $space): ?>
                <div class="bg-black/30 backdrop-blur-md rounded-lg border border-white/10 overflow-hidden shadow-xl">
                    <div class="h-32 bg-center bg-cover" style="background-image: url('<?php echo "https://source.unsplash.com/600x400/?parking,garage&sig=" . ($index + 1); ?>')"></div>
                    <div class="p-5">
                        <h3 class="text-white text-lg font-semibold mb-2"><?php echo htmlspecialchars($space['Parking_Space_Name']); ?></h3>
                        <p class="text-white/80 text-sm mb-3"><?php echo htmlspecialchars($space['Parking_Lot_Address']); ?></p>
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div class="text-center">
                                <p class="text-white/60 text-xs mb-1">Capacity</p>
                                <p class="text-white text-lg font-semibold"><?php echo htmlspecialchars($space['Parking_Capacity']); ?></p>
                            </div>
                            <div class="text-center">
                                <p class="text-white/60 text-xs mb-1">Price/Hour</p>
                                <p class="text-white text-lg font-semibold">৳<?php echo htmlspecialchars($space['PriceperHour']); ?></p>
                            </div>
                        </div>
                        <div class="flex space-x-2">
                            <a href="#" class="flex-1 bg-primary hover:bg-primary-dark text-white text-center text-sm py-2 rounded transition duration-300">Details</a>
                            <a href="#" class="flex-1 bg-white/10 hover:bg-white/20 text-white text-center text-sm py-2 rounded transition duration-300">Edit</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        
        <!-- Account Settings -->
        <section class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl mb-10">
            <h3 class="text-white text-xl font-semibold mb-6">Account Settings</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="flex items-center justify-between p-4 bg-black/30 backdrop-blur-md rounded-lg border border-white/10">
                    <div>
                        <h4 class="text-white text-lg font-medium">Email Notifications</h4>
                        <p class="text-white/70 text-sm">Receive booking confirmations and updates</p>
                    </div>
                    <input type="checkbox" class="toggle toggle-primary" checked />
                </div>
                
                <div class="flex items-center justify-between p-4 bg-black/30 backdrop-blur-md rounded-lg border border-white/10">
                    <div>
                        <h4 class="text-white text-lg font-medium">SMS Notifications</h4>
                        <p class="text-white/70 text-sm">Receive text messages for important updates</p>
                    </div>
                    <input type="checkbox" class="toggle toggle-primary" checked />
                </div>
                
                <div class="flex items-center justify-between p-4 bg-black/30 backdrop-blur-md rounded-lg border border-white/10">
                    <div>
                        <h4 class="text-white text-lg font-medium">Marketing Emails</h4>
                        <p class="text-white/70 text-sm">Receive offers and promotions</p>
                    </div>
                    <input type="checkbox" class="toggle toggle-primary" />
                </div>
                
                <div class="flex items-center justify-between p-4 bg-black/30 backdrop-blur-md rounded-lg border border-white/10">
                    <div>
                        <h4 class="text-white text-lg font-medium">Dark Mode</h4>
                        <p class="text-white/70 text-sm">Toggle between light and dark themes</p>
                    </div>
                    <input type="checkbox" class="toggle toggle-primary" checked />
                </div>
            </div>
            
            <div class="mt-8 flex justify-end">
                <button class="btn bg-primary hover:bg-primary-dark text-white border-none">Save Settings</button>
            </div>
        </section>
        
        <!-- Danger Zone -->
        <section class="bg-black/20 backdrop-blur-md rounded-lg border border-red-500/20 p-6 animate-fadeIn shadow-xl">
            <h3 class="text-red-400 text-xl font-semibold mb-6">Danger Zone</h3>
            
            <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 p-4 bg-red-500/10 backdrop-blur-md rounded-lg border border-red-500/20">
                <div>
                    <h4 class="text-white text-lg font-medium">Delete Account</h4>
                    <p class="text-white/70 text-sm">Once you delete your account, there is no going back. Please be certain.</p>
                </div>
                <button id="delete_account_btn" class="btn btn-error">Delete Account</button>
            </div>
        </section>
    </main>
    
    <!-- Footer -->
    <footer class="bg-black/70 backdrop-blur-md border-t border-white/10 pt-16 pb-8 mt-16">
        <div class="container mx-auto px-4 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10">
            <!-- Company Info -->
            <div>
                <h3 class="text-white text-lg font-semibold mb-4 pb-2 border-b border-primary w-max">About Us</h3>
                <ul class="space-y-2">
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Our Story</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">How It Works</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Testimonials</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Press & Media</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Careers</a></li>
                </ul>
            </div>
            
            <!-- Services -->
            <div>
                <h3 class="text-white text-lg font-semibold mb-4 pb-2 border-b border-primary w-max">Services</h3>
                <ul class="space-y-2">
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Find Parking</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">List Your Space</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Business Solutions</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Mobile App</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Partnerships</a></li>
                </ul>
            </div>
            
            <!-- Support -->
            <div>
                <h3 class="text-white text-lg font-semibold mb-4 pb-2 border-b border-primary w-max">Support</h3>
                <ul class="space-y-2">
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Help Center</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">FAQs</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Contact Us</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Refund Policy</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Terms of Service</a></li>
                </ul>
            </div>
            
            <!-- Contact -->
            <div>
                <h3 class="text-white text-lg font-semibold mb-4 pb-2 border-b border-primary w-max">Contact Us</h3>
                <ul class="space-y-4">
                    <li class="flex items-start gap-3 text-white/90">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 flex-shrink-0 mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                        123 Parking Avenue, Gulshan, Dhaka 1212
                    </li>
                    <li class="flex items-start gap-3 text-white/90">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 flex-shrink-0 mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                        (+880) 1700-000000
                    </li>
                    <li class="flex items-start gap-3 text-white/90">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 flex-shrink-0 mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        info@parkinglagbe.com
                    </li>
                </ul>
                
                <div class="flex gap-4 mt-6">
                    <a href="#" class="w-10 h-10 bg-white/10 rounded-full flex justify-center items-center transition-all hover:bg-primary hover:-translate-y-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path></svg>
                    </a>
                    <a href="#" class="w-10 h-10 bg-white/10 rounded-full flex justify-center items-center transition-all hover:bg-primary hover:-translate-y-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"></path></svg>
                    </a>
                    <a href="#" class="w-10 h-10 bg-white/10 rounded-full flex justify-center items-center transition-all hover:bg-primary hover:-translate-y-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg>
                    </a>
                </div>
            </div>
        </div>
        
        <div class="container mx-auto px-4 mt-10 pt-6 border-t border-white/10 flex flex-col md:flex-row justify-between items-center gap-4">
            <p class="text-white/90 text-sm">&copy; <?php echo date('Y'); ?> পার্কিং লাগবে. All rights reserved.</p>
            <div class="flex gap-6">
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Privacy Policy</a>
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Terms of Service</a>
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Cookie Policy</a>
            </div>
        </div>
    </footer>

    <!-- Edit Profile Modal -->
    <dialog id="edit_profile_modal" class="modal modal-bottom sm:modal-middle">
      <div class="modal-box bg-black/80 backdrop-blur-xl border border-white/10">
        <h3 class="font-bold text-lg text-white mb-4">Edit Profile</h3>
        
        <!-- Success/Error Messages -->
        <div id="edit_profile_success" class="alert alert-success mb-4 hidden">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          <span>Profile updated successfully!</span>
        </div>
        
        <div id="edit_profile_error" class="alert alert-error mb-4 hidden">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          <span id="error_message">Error updating profile</span>
        </div>
        
        <form id="edit_profile_form" class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="form-control">
              <label class="label">
                <span class="label-text text-white/80">First Name</span>
              </label>
              <input type="text" id="edit_firstName" name="firstName" value="<?php echo htmlspecialchars($firstName); ?>" class="input input-bordered w-full bg-black/30 border-white/20 text-white" required>
            </div>
            
            <div class="form-control">
              <label class="label">
                <span class="label-text text-white/80">Last Name</span>
              </label>
              <input type="text" id="edit_lastName" name="lastName" value="<?php echo htmlspecialchars($lastName); ?>" class="input input-bordered w-full bg-black/30 border-white/20 text-white" required>
            </div>
          </div>
          
          <div class="form-control">
            <label class="label">
              <span class="label-text text-white/80">Email Address</span>
            </label>
            <input type="email" id="edit_email" name="email" value="<?php echo htmlspecialchars($email); ?>" class="input input-bordered w-full bg-black/30 border-white/20 text-white" required>
          </div>
          
          <div class="form-control">
            <label class="label">
              <span class="label-text text-white/80">Phone Number</span>
            </label>
            <input type="text" id="edit_phone" name="phone" value="<?php echo htmlspecialchars($phone); ?>" class="input input-bordered w-full bg-black/30 border-white/20 text-white">
          </div>
          
          <div class="form-control">
            <label class="label">
              <span class="label-text text-white/80">Address</span>
            </label>
            <textarea id="edit_address" name="address" class="textarea textarea-bordered w-full bg-black/30 border-white/20 text-white" rows="3"><?php echo htmlspecialchars($address); ?></textarea>
          </div>
        </form>
        
        <div class="modal-action">
          <button form="edit_profile_form" type="button" id="save_profile_btn" class="btn bg-primary hover:bg-primary-dark text-white border-none">
            Save Changes
          </button>
          <button class="btn btn-outline border-white/20 text-white hover:bg-white/10" onclick="edit_profile_modal.close()">
            Cancel
          </button>
        </div>
      </div>
    </dialog>

    <!-- Change Password Modal -->
<dialog id="change_password_modal" class="modal modal-bottom sm:modal-middle">
  <div class="modal-box bg-black/80 backdrop-blur-xl border border-white/10">
    <h3 class="font-bold text-lg text-white mb-4">Change Password</h3>
    
    <!-- Success/Error Messages -->
    <div id="password_success" class="alert alert-success mb-4 hidden">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
      <span>Password changed successfully!</span>
    </div>
    
    <div id="password_error" class="alert alert-error mb-4 hidden">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
      <span id="password_error_message">Error changing password</span>
    </div>
    
    <form id="change_password_form" class="space-y-4">
      <div class="form-control">
        <label class="label">
          <span class="label-text text-white/80">Current Password</span>
        </label>
        <input type="password" id="current_password" name="current_password" class="input input-bordered w-full bg-black/30 border-white/20 text-white" required>
      </div>
      
      <div class="form-control">
        <label class="label">
          <span class="label-text text-white/80">New Password</span>
        </label>
        <input type="password" id="new_password" name="new_password" class="input input-bordered w-full bg-black/30 border-white/20 text-white" required>
      </div>
      
      <div class="form-control">
        <label class="label">
          <span class="label-text text-white/80">Confirm New Password</span>
        </label>
        <input type="password" id="confirm_password" name="confirm_password" class="input input-bordered w-full bg-black/30 border-white/20 text-white" required>
      </div>
    </form>
    
    <div class="modal-action">
      <button form="change_password_form" type="button" id="save_password_btn" class="btn bg-primary hover:bg-primary-dark text-white border-none">
        Save Changes
      </button>
      <button class="btn btn-outline border-white/20 text-white hover:bg-white/10" onclick="change_password_modal.close()">
        Cancel
      </button>
    </div>
  </div>
</dialog>

<!-- Delete Account Confirmation Modal -->
<dialog id="delete_account_modal" class="modal modal-bottom sm:modal-middle">
  <div class="modal-box bg-black/80 backdrop-blur-xl border border-red-500/20">
    <h3 class="font-bold text-lg text-red-400 mb-4">Delete Your Account</h3>
    
    <p class="text-white mb-4">Are you absolutely sure you want to delete your account? This action cannot be undone.</p>
    
    <p class="text-white/70 mb-6">This will permanently delete:</p>
    <ul class="list-disc list-inside text-white/70 mb-6 space-y-1">
      <li>Your personal information</li>
      <li>All your vehicle records</li>
      <li>All your booking history</li>
      <li>All your parking spaces (if any)</li>
    </ul>
    
    <form id="delete_account_form" class="space-y-4">
      <div class="form-control">
        <label class="label">
          <span class="label-text text-white/80">Please enter your password to confirm deletion</span>
        </label>
        <input type="password" id="password_confirm" name="password_confirm" class="input input-bordered w-full bg-black/30 border-white/20 text-white" required>
      </div>
    </form>
    
    <div class="modal-action">
      <button form="delete_account_form" type="button" id="confirm_delete_btn" class="btn btn-error">
        Yes, Delete My Account
      </button>
      <button class="btn bg-gray-700 hover:bg-gray-600 text-white border-none" onclick="delete_account_modal.close()">
        Cancel
      </button>
    </div>
  </div>
</dialog>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Get references to elements
      const editProfileBtn = document.getElementById('edit_profile_btn');
      const editPersonalInfoBtn = document.getElementById('edit_personal_info_btn');
      const editProfileModal = document.getElementById('edit_profile_modal');
      const saveProfileBtn = document.getElementById('save_profile_btn');
      const profileForm = document.getElementById('edit_profile_form');
      const successAlert = document.getElementById('edit_profile_success');
      const errorAlert = document.getElementById('edit_profile_error');
      const errorMessage = document.getElementById('error_message');
      
      // Open modal when edit button is clicked
      editProfileBtn.addEventListener('click', function() {
        editProfileModal.showModal();
      });
      
      // Also open modal when edit button in personal info section is clicked
      editPersonalInfoBtn.addEventListener('click', function() {
        editProfileModal.showModal();
      });
      
      // Handle form submission
      saveProfileBtn.addEventListener('click', function() {
        // Hide any previous alerts
        successAlert.classList.add('hidden');
        errorAlert.classList.add('hidden');
        
        // Get form data
        const formData = new FormData(profileForm);
        
        // Send AJAX request
        fetch('update_profile.php', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            // Show success message
            successAlert.classList.remove('hidden');
            
            // Update page content with new data
            const fullName = formData.get('firstName') + ' ' + formData.get('lastName');
            
            document.getElementById('display_fullName').textContent = fullName;
            document.getElementById('display_header_fullName').textContent = fullName;
            document.getElementById('display_email').textContent = formData.get('email');
            document.getElementById('display_phone').textContent = formData.get('phone');
            document.getElementById('display_address').textContent = formData.get('address');
            
            // Update avatar initial if name changed
            const firstLetter = formData.get('firstName').charAt(0).toUpperCase();
            const avatarInitials = document.querySelectorAll('.avatar-initial');
            avatarInitials.forEach(element => {
              element.textContent = firstLetter;
            });
            
            // Close modal after short delay
            setTimeout(() => {
              editProfileModal.close();
            }, 1500);
          } else {
            // Show error message
            errorMessage.textContent = data.message || 'Error updating profile';
            errorAlert.classList.remove('hidden');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          errorMessage.textContent = 'An unexpected error occurred';
          errorAlert.classList.remove('hidden');
        });
      });
    });

    // Password Change Functionality
const changePasswordBtn = document.getElementById('change_password_btn');
const changePasswordSecurityBtn = document.getElementById('change_password_security_btn');
const changePasswordModal = document.getElementById('change_password_modal');
const savePasswordBtn = document.getElementById('save_password_btn');
const passwordForm = document.getElementById('change_password_form');
const passwordSuccessAlert = document.getElementById('password_success');
const passwordErrorAlert = document.getElementById('password_error');
const passwordErrorMessage = document.getElementById('password_error_message');

// Open modal when change password button is clicked
if (changePasswordBtn) {
  changePasswordBtn.addEventListener('click', function() {
    changePasswordModal.showModal();
  });
}

// Also open modal when change button in security section is clicked
if (changePasswordSecurityBtn) {
  changePasswordSecurityBtn.addEventListener('click', function() {
    changePasswordModal.showModal();
  });
}

// Handle form submission
if (savePasswordBtn) {
  savePasswordBtn.addEventListener('click', function() {
    // Hide any previous alerts
    passwordSuccessAlert.classList.add('hidden');
    passwordErrorAlert.classList.add('hidden');
    
    // Get form data
    const formData = new FormData(passwordForm);
    
    // Validate passwords
    const newPassword = formData.get('new_password');
    const confirmPassword = formData.get('confirm_password');
    
    if (newPassword !== confirmPassword) {
      passwordErrorMessage.textContent = "New passwords do not match";
      passwordErrorAlert.classList.remove('hidden');
      return;
    }
    
    if (newPassword.length < 5) {
      passwordErrorMessage.textContent = "Password must be at least 5 characters";
      passwordErrorAlert.classList.remove('hidden');
      return;
    }
    
    // Send AJAX request
    fetch('update_password.php', {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Show success message
        passwordSuccessAlert.classList.remove('hidden');
        
        // Reset form
        passwordForm.reset();
        
        // Close modal after short delay
        setTimeout(() => {
          changePasswordModal.close();
        }, 1500);
      } else {
        // Show error message
        passwordErrorMessage.textContent = data.message || 'Error changing password';
        passwordErrorAlert.classList.remove('hidden');
      }
    })
    .catch(error => {
      console.error('Error:', error);
      passwordErrorMessage.textContent = 'An unexpected error occurred';
      passwordErrorAlert.classList.remove('hidden');
    });
  });
}

// Account Deletion Functionality
const deleteAccountBtn = document.getElementById('delete_account_btn');
const deleteAccountModal = document.getElementById('delete_account_modal');
const confirmDeleteBtn = document.getElementById('confirm_delete_btn');
const deleteAccountForm = document.getElementById('delete_account_form');

// Open modal when delete account button is clicked
if (deleteAccountBtn) {
  deleteAccountBtn.addEventListener('click', function() {
    deleteAccountModal.showModal();
  });
}

// Handle account deletion
if (confirmDeleteBtn) {
  confirmDeleteBtn.addEventListener('click', function() {
    // Get form data
    const formData = new FormData(deleteAccountForm);
    
    // Check if password is provided
    if (!formData.get('password_confirm')) {
      alert('Please enter your password to confirm deletion');
      return;
    }
    
    // Disable button to prevent multiple clicks
    confirmDeleteBtn.disabled = true;
    confirmDeleteBtn.innerHTML = 'Deleting...';
    
    // Send AJAX request
    fetch('delete_account.php', {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Show success message and redirect to logout
        alert('Your account has been successfully deleted.');
        window.location.href = 'logout.php';
      } else {
        // Show error message
        alert(data.message || 'Error deleting account. Please try again.');
        confirmDeleteBtn.disabled = false;
        confirmDeleteBtn.innerHTML = 'Yes, Delete My Account';
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('An unexpected error occurred. Please try again.');
      confirmDeleteBtn.disabled = false;
      confirmDeleteBtn.innerHTML = 'Yes, Delete My Account';
    });
  });
}

    </script>




<!-- Points Modal - Add this before closing body tag -->
<!-- Points Modal - Enhanced Version -->
<dialog id="points_modal" class="modal modal-bottom sm:modal-middle">
  <div class="modal-box bg-black/80 backdrop-blur-xl border border-primary/20 max-w-4xl">
    <h3 class="font-bold text-lg text-white mb-4 flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary" fill="currentColor" viewBox="0 0 24 24">
            <polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26"></polygon>
        </svg>
        My Points Dashboard
    </h3>
    
    <!-- Enhanced Points Stats Grid -->
    <div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-primary/10 p-4 rounded-lg border border-primary/20">
            <div class="text-primary text-2xl font-bold"><?php echo $pointsStats['current_points']; ?></div>
            <div class="text-white/80 text-sm">Current Points</div>
        </div>
        
        <div class="bg-green-500/10 p-4 rounded-lg border border-green-500/20">
            <div class="text-green-400 text-2xl font-bold"><?php echo $pointsStats['free_hours_available']; ?></div>
            <div class="text-white/80 text-sm">Free Hours Available</div>
        </div>
        
        <div class="bg-blue-500/10 p-4 rounded-lg border border-blue-500/20">
            <div class="text-blue-400 text-xl font-bold"><?php echo $pointsStats['points_from_bookings']; ?></div>
            <div class="text-white/80 text-sm">From Bookings</div>
        </div>
        
        <!-- NEW: Bonus Points Card -->
        <div class="bg-purple-500/10 p-4 rounded-lg border border-purple-500/20">
            <div class="text-purple-400 text-xl font-bold"><?php echo $pointsStats['total_bonus']; ?></div>
            <div class="text-white/80 text-sm">Bonus Points</div>
        </div>
        
        <div class="bg-orange-500/10 p-4 rounded-lg border border-orange-500/20">
            <div class="text-orange-400 text-xl font-bold"><?php echo $pointsStats['total_spent']; ?></div>
            <div class="text-white/80 text-sm">Total Spent</div>
        </div>
        
        <div class="bg-yellow-500/10 p-4 rounded-lg border border-yellow-500/20">
            <div class="text-yellow-400 text-xl font-bold"><?php echo $pointsStats['total_earned']; ?></div>
            <div class="text-white/80 text-sm">Total Earned</div>
        </div>
    </div>
    
    <!-- How Points Work -->
    <div class="bg-white/5 p-4 rounded-lg mb-4">
        <h4 class="text-white font-semibold mb-2">How Points Work:</h4>
        <ul class="text-white/80 text-sm space-y-1">
            <li>• Earn 15 points for every hour you park</li>
            <li>• Use 150 points to get 1 hour of free parking</li>
            <li>• Get bonus points from admin rewards</li>
            <li>• Points are awarded when your booking is completed</li>
        </ul>
    </div>
    
    <!-- Recent Transactions -->
    <div class="bg-white/5 p-4 rounded-lg mb-4">
        <div class="flex justify-between items-center mb-3">
            <h4 class="text-white font-semibold">Recent Transactions</h4>
            <button onclick="showAllTransactions()" class="btn btn-xs bg-primary hover:bg-primary-dark text-white border-none">
                View All
            </button>
        </div>
        
        <?php if (empty($recentTransactions)): ?>
        <p class="text-white/60 text-center py-4">No transactions yet</p>
        <?php else: ?>
        <div class="space-y-2 max-h-48 overflow-y-auto">
            <?php foreach ($recentTransactions as $transaction): ?>
            <div class="flex justify-between items-center p-3 bg-black/30 rounded-lg">
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <?php if ($transaction['transaction_type'] === 'earned'): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-green-400" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                        </svg>
                        <span class="text-green-400 text-sm font-medium">+<?php echo $transaction['points_amount']; ?></span>
                        <?php elseif ($transaction['transaction_type'] === 'bonus'): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-purple-400" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                        </svg>
                        <span class="text-purple-400 text-sm font-medium">+<?php echo $transaction['points_amount']; ?></span>
                        <?php else: ?>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-red-400" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                        </svg>
                        <span class="text-red-400 text-sm font-medium">-<?php echo $transaction['points_amount']; ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="text-white/80 text-xs mt-1"><?php echo htmlspecialchars($transaction['description']); ?></p>
                    <?php if ($transaction['Parking_Space_Name']): ?>
                    <p class="text-white/60 text-xs"><?php echo htmlspecialchars($transaction['Parking_Space_Name']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="text-right">
                    <p class="text-white/60 text-xs"><?php echo date('M j, Y', strtotime($transaction['created_at'])); ?></p>
                    <p class="text-white/60 text-xs"><?php echo date('g:i a', strtotime($transaction['created_at'])); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
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

<!-- Add this modal before the closing body tag in my_profile.php -->

<!-- All Transactions Modal -->
<dialog id="all_transactions_modal" class="modal modal-bottom sm:modal-middle">
  <div class="modal-box bg-black/80 backdrop-blur-xl border border-primary/20 max-w-5xl max-h-[80vh]">
    <h3 class="font-bold text-lg text-white mb-4 flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary" fill="currentColor" viewBox="0 0 24 24">
            <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z" />
        </svg>
        All Points Transactions
    </h3>
    
    <!-- Loading indicator -->
    <div id="transactions_loading" class="text-center py-8">
        <span class="loading loading-spinner loading-lg text-primary"></span>
        <p class="text-white/60 mt-2">Loading transactions...</p>
    </div>
    
    <!-- Transactions content -->
    <div id="transactions_content" class="hidden">
        <!-- Filter and search -->
        <div class="flex flex-col sm:flex-row gap-4 mb-4">
            <select id="transaction_type_filter" class="select select-bordered bg-black/30 border-white/20 text-white">
                <option value="all">All Types</option>
                <option value="earned">Earned Points</option>
                <option value="spent">Spent Points</option>
                <option value="bonus">Bonus Points</option>
            </select>
            
            <input type="text" id="transaction_search" placeholder="Search description..." 
                   class="input input-bordered bg-black/30 border-white/20 text-white flex-1">
        </div>
        
        <!-- Transactions table -->
        <div class="overflow-x-auto max-h-96">
            <table class="table table-zebra w-full">
                <thead class="sticky top-0 bg-black/80">
                    <tr class="text-white/80">
                        <th>Date</th>
                        <th>Type</th>
                        <th>Points</th>
                        <th>Description</th>
                        <th>Garage</th>
                    </tr>
                </thead>
                <tbody id="transactions_table_body" class="text-white/90">
                    <!-- Transactions will be loaded here -->
                </tbody>
            </table>
        </div>
        
        <!-- Summary -->
        <div id="transactions_summary" class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
            <!-- Summary cards will be loaded here -->
        </div>
    </div>
    
    <div class="modal-action">
      <button class="btn bg-primary hover:bg-primary-dark text-white border-none" onclick="all_transactions_modal.close()">
        Close
      </button>
    </div>
  </div>
</dialog>

<script>
// Replace the existing showAllTransactions function with this:
function showAllTransactions() {
    // Show modal and loading state
    all_transactions_modal.showModal();
    document.getElementById('transactions_loading').classList.remove('hidden');
    document.getElementById('transactions_content').classList.add('hidden');
    
    // Fetch all transactions
    fetch('get_all_points_transactions.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                populateTransactionsTable(data.transactions);
                updateTransactionsSummary(data.summary);
                
                // Hide loading, show content
                document.getElementById('transactions_loading').classList.add('hidden');
                document.getElementById('transactions_content').classList.remove('hidden');
                
                // Set up filters
                setupTransactionFilters(data.transactions);
            } else {
                alert('Error loading transactions: ' + data.message);
                all_transactions_modal.close();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to load transactions');
            all_transactions_modal.close();
        });
}

let allTransactions = [];

function populateTransactionsTable(transactions) {
    allTransactions = transactions;
    const tbody = document.getElementById('transactions_table_body');
    tbody.innerHTML = '';
    
    if (transactions.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-8 text-white/60">No transactions found</td></tr>';
        return;
    }
    
    transactions.forEach(transaction => {
        const row = document.createElement('tr');
        row.className = 'hover:bg-white/5';
        
        // Format date
        const date = new Date(transaction.created_at);
        const formattedDate = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        
        // Type badge
        let typeBadge = '';
        if (transaction.transaction_type === 'earned') {
            typeBadge = '<span class="badge badge-success badge-sm">+' + transaction.points_amount + '</span>';
        } else if (transaction.transaction_type === 'spent') {
            typeBadge = '<span class="badge badge-error badge-sm">-' + transaction.points_amount + '</span>';
        } else {
            typeBadge = '<span class="badge badge-info badge-sm">+' + transaction.points_amount + '</span>';
        }
        
        row.innerHTML = `
            <td class="text-sm">${formattedDate}</td>
            <td>${typeBadge}</td>
            <td class="font-semibold">${transaction.points_amount}</td>
            <td class="max-w-xs truncate">${transaction.description}</td>
            <td class="text-sm text-white/70">${transaction.garage_name || 'N/A'}</td>
        `;
        
        tbody.appendChild(row);
    });
}

function updateTransactionsSummary(summary) {
    const summaryDiv = document.getElementById('transactions_summary');
    summaryDiv.innerHTML = `
        <div class="bg-green-500/10 p-3 rounded-lg border border-green-500/20">
            <div class="text-green-400 text-lg font-bold">+${summary.total_earned}</div>
            <div class="text-white/80 text-xs">Total Earned</div>
        </div>
        <div class="bg-red-500/10 p-3 rounded-lg border border-red-500/20">
            <div class="text-red-400 text-lg font-bold">-${summary.total_spent}</div>
            <div class="text-white/80 text-xs">Total Spent</div>
        </div>
        <div class="bg-purple-500/10 p-3 rounded-lg border border-purple-500/20">
            <div class="text-purple-400 text-lg font-bold">+${summary.total_bonus}</div>
            <div class="text-white/80 text-xs">Bonus Points</div>
        </div>
        <div class="bg-blue-500/10 p-3 rounded-lg border border-blue-500/20">
            <div class="text-blue-400 text-lg font-bold">${summary.transaction_count}</div>
            <div class="text-white/80 text-xs">Total Transactions</div>
        </div>
    `;
}

function setupTransactionFilters(transactions) {
    const typeFilter = document.getElementById('transaction_type_filter');
    const searchInput = document.getElementById('transaction_search');
    
    function filterTransactions() {
        const selectedType = typeFilter.value;
        const searchTerm = searchInput.value.toLowerCase();
        
        let filtered = allTransactions;
        
        // Filter by type
        if (selectedType !== 'all') {
            filtered = filtered.filter(t => t.transaction_type === selectedType);
        }
        
        // Filter by search term
        if (searchTerm) {
            filtered = filtered.filter(t => 
                t.description.toLowerCase().includes(searchTerm) ||
                (t.garage_name && t.garage_name.toLowerCase().includes(searchTerm))
            );
        }
        
        populateTransactionsTable(filtered);
    }
    
    typeFilter.addEventListener('change', filterTransactions);
    searchInput.addEventListener('input', filterTransactions);
}

</script>
<!-- Account Verification Modal -->
<dialog id="verification_modal" class="modal modal-bottom sm:modal-middle">
  <div class="modal-box bg-black/80 backdrop-blur-xl border border-primary/20 max-w-4xl max-h-[90vh] overflow-y-auto">
    <h3 class="font-bold text-lg text-white mb-4 flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.707-4.293a1 1 0 010 1.414l-9 9a1 1 0 01-1.414 0l-5-5a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" />
        </svg>
        Account Verification
    </h3>
    
    <!-- Verification Steps -->
    <div class="mb-6">
        <div class="steps steps-horizontal w-full">
            <div class="step step-primary">Identity</div>
            <div class="step" id="vehicle_step">Vehicle (Optional)</div>
            <div class="step" id="review_step">Review</div>
        </div>
    </div>
    
    <!-- Step 1: Identity Verification -->
    <div id="identity_verification_step" class="verification-step">
        <div class="bg-white/5 p-4 rounded-lg mb-4">
            <h4 class="text-white font-semibold mb-2">📋 Identity Verification Required</h4>
            <p class="text-white/80 text-sm mb-4">Please upload one of the following documents to verify your identity:</p>
            <ul class="text-white/70 text-sm space-y-1 list-disc list-inside">
                <li>National ID Card (NID) - 10 or 13-17 digits</li>
                <li>Passport - Format: AB1234567 (2 letters + 7 digits)</li>
                <li>Driving License - Various formats accepted</li>
            </ul>
        </div>
        
        <form id="identity_verification_form" enctype="multipart/form-data">
            <input type="hidden" name="verification_type" value="identity">
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white/80">Document Type</span>
                </label>
                <select name="document_type" id="identity_document_type" class="select select-bordered bg-black/30 border-white/20 text-white" required>
                    <option value="">Select Document Type</option>
                    <option value="nid">National ID Card (NID)</option>
                    <option value="passport">Passport</option>
                    <option value="driving_license">Driving License</option>
                </select>
            </div>
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white/80">Document Number</span>
                </label>
                <input type="text" name="document_number" id="document_number_input" class="input input-bordered bg-black/30 border-white/20 text-white" placeholder="Enter document number" required>
                
                <!-- Dynamic format hints -->
                <div class="label">
                    <span class="label-text-alt text-white/60" id="format_hint">Select document type to see format</span>
                </div>
                
                <!-- Validation message -->
                <div id="validation_message" class="hidden mt-2">
                    <div class="alert alert-error py-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span id="validation_text">Invalid format</span>
                    </div>
                </div>
                
                <!-- Success message -->
                <div id="success_message" class="hidden mt-2">
                    <div class="alert alert-success py-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Valid format ✓</span>
                    </div>
                </div>
            </div>
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white/80">Upload Document Image</span>
                </label>
                <input type="file" name="document_file" id="identity_file" class="file-input file-input-bordered bg-black/30 border-white/20 text-white" accept="image/*,.pdf" required>
                <div class="label">
                    <span class="label-text-alt text-white/60">Accepted formats: JPG, PNG, PDF (Max: 5MB)</span>
                </div>
            </div>
            
            <!-- File Preview -->
            <div id="identity_preview" class="hidden mb-4">
                <div class="bg-black/30 p-4 rounded-lg border border-white/10">
                    <h5 class="text-white font-medium mb-2">Preview:</h5>
                    <div id="identity_preview_content"></div>
                </div>
            </div>
        </form>
        
        <div class="flex justify-between">
            <button class="btn btn-outline border-white/20 text-white hover:bg-white/10" onclick="verification_modal.close()">
                Cancel
            </button>
            <button id="next_to_vehicle_btn" class="btn bg-primary hover:bg-primary-dark text-white border-none" disabled>
                Next: Vehicle Info (Optional)
            </button>
        </div>
    </div>
    
    <!-- Step 2: Vehicle Verification (Optional) -->
    <div id="vehicle_verification_step" class="verification-step hidden">
        <div class="bg-white/5 p-4 rounded-lg mb-4">
            <h4 class="text-white font-semibold mb-2">🚗 Vehicle Verification (Optional)</h4>
            <p class="text-white/80 text-sm mb-4">Upload vehicle documents for enhanced verification (recommended for frequent parkers):</p>
            <ul class="text-white/70 text-sm space-y-1 list-disc list-inside">
                <li>Vehicle Registration Certificate</li>
                <li>Vehicle Insurance Policy</li>
            </ul>
        </div>
        
        <form id="vehicle_verification_form" enctype="multipart/form-data">
            <input type="hidden" name="verification_type" value="vehicle">
            
            <!-- Vehicle Registration -->
            <div class="mb-6">
                <h5 class="text-white font-medium mb-3">Vehicle Registration</h5>
                <div class="form-control mb-3">
                    <input type="file" name="registration_file" class="file-input file-input-bordered bg-black/30 border-white/20 text-white" accept="image/*,.pdf">
                    <div class="label">
                        <span class="label-text-alt text-white/60">Upload vehicle registration certificate</span>
                    </div>
                </div>
            </div>
            
            <!-- Vehicle Insurance -->
            <div class="mb-6">
                <h5 class="text-white font-medium mb-3">Vehicle Insurance</h5>
                <div class="form-control mb-3">
                    <input type="file" name="insurance_file" class="file-input file-input-bordered bg-black/30 border-white/20 text-white" accept="image/*,.pdf">
                    <div class="label">
                        <span class="label-text-alt text-white/60">Upload vehicle insurance policy</span>
                    </div>
                </div>
            </div>
        </form>
        
        <div class="flex justify-between">
            <button id="back_to_identity_btn" class="btn btn-outline border-white/20 text-white hover:bg-white/10">
                Back
            </button>
            <div class="flex gap-2">
                <button id="skip_vehicle_btn" class="btn btn-ghost text-white/70">
                    Skip Vehicle Info
                </button>
                <button id="next_to_review_btn" class="btn bg-primary hover:bg-primary-dark text-white border-none">
                    Review & Submit
                </button>
            </div>
        </div>
    </div>
    
    <!-- Step 3: Review & Submit -->
    <div id="review_verification_step" class="verification-step hidden">
        <div class="bg-white/5 p-4 rounded-lg mb-4">
            <h4 class="text-white font-semibold mb-2">📋 Review Your Submission</h4>
            <p class="text-white/80 text-sm">Please review your uploaded documents before submitting for verification.</p>
        </div>
        
        <!-- Review Content -->
        <div id="review_content" class="space-y-4 mb-6">
            <!-- Content will be populated by JavaScript -->
        </div>
        
        <!-- Submit Status -->
        <div id="submit_status" class="hidden mb-4">
            <div class="alert alert-info">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>Submitting your verification request...</span>
            </div>
        </div>
        
        <div class="flex justify-between">
            <button id="back_to_vehicle_btn" class="btn btn-outline border-white/20 text-white hover:bg-white/10">
                Back
            </button>
            <button id="submit_verification_btn" class="btn bg-primary hover:bg-primary-dark text-white border-none">
                Submit for Verification
            </button>
        </div>
    </div>
  </div>
</dialog>

<style>
.verification-step {
    min-height: 400px;
}

.file-preview-image {
    max-width: 200px;
    max-height: 150px;
    object-fit: cover;
    border-radius: 8px;
}

.step-primary {
    --step-color: #f39c12;
}

.input.invalid {
    border-color: #ef4444;
    background-color: rgba(239, 68, 68, 0.1);
}

.input.valid {
    border-color: #10b981;
    background-color: rgba(16, 185, 129, 0.1);
}
</style>

<script>
    // Add this JavaScript to my_profile.php before closing body tag

document.addEventListener('DOMContentLoaded', function() {
    // Verification Modal Elements
    const verifyAccountBtn = document.getElementById('verify_account_btn');
    const verificationModal = document.getElementById('verification_modal');
    
    // Step navigation
    const identityStep = document.getElementById('identity_verification_step');
    const vehicleStep = document.getElementById('vehicle_verification_step');
    const reviewStep = document.getElementById('review_verification_step');
    
    // Buttons
    const nextToVehicleBtn = document.getElementById('next_to_vehicle_btn');
    const backToIdentityBtn = document.getElementById('back_to_identity_btn');
    const nextToReviewBtn = document.getElementById('next_to_review_btn');
    const skipVehicleBtn = document.getElementById('skip_vehicle_btn');
    const backToVehicleBtn = document.getElementById('back_to_vehicle_btn');
    const submitVerificationBtn = document.getElementById('submit_verification_btn');
    
    // File inputs
    const identityFileInput = document.getElementById('identity_file');
    
    // Storage for form data
    let verificationData = {
        identity: {},
        vehicle: {},
        files: {}
    };
    
    // Open verification modal
    if (verifyAccountBtn) {
        verifyAccountBtn.addEventListener('click', function() {
            verificationModal.showModal();
            showStep('identity');
        });
    }
    
    // File preview functionality
    identityFileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            previewFile(file, 'identity_preview', 'identity_preview_content');
            verificationData.files.identity = file;
        }
    });
    
    // Step navigation
    nextToVehicleBtn.addEventListener('click', function() {
        if (validateIdentityForm()) {
            collectIdentityData();
            showStep('vehicle');
        }
    });
    
    backToIdentityBtn.addEventListener('click', function() {
        showStep('identity');
    });
    
    nextToReviewBtn.addEventListener('click', function() {
        collectVehicleData();
        populateReview();
        showStep('review');
    });
    
    skipVehicleBtn.addEventListener('click', function() {
        populateReview();
        showStep('review');
    });
    
    backToVehicleBtn.addEventListener('click', function() {
        showStep('vehicle');
    });
    
    submitVerificationBtn.addEventListener('click', function() {
        submitVerification();
    });
    
    // Functions
    function showStep(stepName) {
        // Hide all steps
        identityStep.classList.add('hidden');
        vehicleStep.classList.add('hidden');
        reviewStep.classList.add('hidden');
        
        // Update step indicators
        const steps = document.querySelectorAll('.step');
        steps.forEach(step => step.classList.remove('step-primary'));
        
        // Show current step
        switch(stepName) {
            case 'identity':
                identityStep.classList.remove('hidden');
                steps[0].classList.add('step-primary');
                break;
            case 'vehicle':
                vehicleStep.classList.remove('hidden');
                steps[0].classList.add('step-primary');
                steps[1].classList.add('step-primary');
                break;
            case 'review':
                reviewStep.classList.remove('hidden');
                steps[0].classList.add('step-primary');
                steps[1].classList.add('step-primary');
                steps[2].classList.add('step-primary');
                break;
        }
    }
    
    function validateIdentityForm() {
        const form = document.getElementById('identity_verification_form');
        const formData = new FormData(form);
        
        if (!formData.get('document_type') || !formData.get('document_number') || !formData.get('document_file').name) {
            alert('Please fill in all required identity verification fields.');
            return false;
        }
        
        // File size validation (5MB)
        const file = formData.get('document_file');
        if (file.size > 5 * 1024 * 1024) {
            alert('File size must be less than 5MB.');
            return false;
        }
        
        return true;
    }
    
    function collectIdentityData() {
        const form = document.getElementById('identity_verification_form');
        const formData = new FormData(form);
        
        verificationData.identity = {
            document_type: formData.get('document_type'),
            document_number: formData.get('document_number'),
            file: formData.get('document_file')
        };
    }
    
    function collectVehicleData() {
        const form = document.getElementById('vehicle_verification_form');
        const formData = new FormData(form);
        
        verificationData.vehicle = {
            registration_file: formData.get('registration_file'),
            insurance_file: formData.get('insurance_file')
        };
    }
    
    function populateReview() {
        const reviewContent = document.getElementById('review_content');
        let html = '';
        
        // Identity section
        html += `
            <div class="bg-black/30 p-4 rounded-lg">
                <h5 class="text-white font-medium mb-3">Identity Verification</h5>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-white/60 text-sm">Document Type</p>
                        <p class="text-white">${getDocumentTypeLabel(verificationData.identity.document_type)}</p>
                    </div>
                    <div>
                        <p class="text-white/60 text-sm">Document Number</p>
                        <p class="text-white">${verificationData.identity.document_number}</p>
                    </div>
                </div>
                <div class="mt-3">
                    <p class="text-white/60 text-sm">Uploaded File</p>
                    <p class="text-white">${verificationData.identity.file.name}</p>
                </div>
            </div>
        `;
        
        // Vehicle section (if any files uploaded)
        if (verificationData.vehicle.registration_file?.name || verificationData.vehicle.insurance_file?.name) {
            html += `
                <div class="bg-black/30 p-4 rounded-lg">
                    <h5 class="text-white font-medium mb-3">Vehicle Verification</h5>
                    <div class="space-y-2">
            `;
            
            if (verificationData.vehicle.registration_file?.name) {
                html += `
                    <div>
                        <p class="text-white/60 text-sm">Registration Certificate</p>
                        <p class="text-white">${verificationData.vehicle.registration_file.name}</p>
                    </div>
                `;
            }
            
            if (verificationData.vehicle.insurance_file?.name) {
                html += `
                    <div>
                        <p class="text-white/60 text-sm">Insurance Policy</p>
                        <p class="text-white">${verificationData.vehicle.insurance_file.name}</p>
                    </div>
                `;
            }
            
            html += `
                    </div>
                </div>
            `;
        }
        
        reviewContent.innerHTML = html;
    }
    
    function getDocumentTypeLabel(type) {
        const labels = {
            'nid': 'National ID Card',
            'passport': 'Passport',
            'driving_license': 'Driving License'
        };
        return labels[type] || type;
    }
    
    function previewFile(file, previewContainerId, previewContentId) {
        const previewContainer = document.getElementById(previewContainerId);
        const previewContent = document.getElementById(previewContentId);
        
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewContent.innerHTML = `
                    <img src="${e.target.result}" class="file-preview-image" alt="Document preview">
                    <p class="text-white/70 text-sm mt-2">${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)</p>
                `;
                previewContainer.classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        } else {
            previewContent.innerHTML = `
                <div class="flex items-center gap-3 p-3 bg-white/5 rounded">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                    <div>
                        <p class="text-white font-medium">${file.name}</p>
                        <p class="text-white/70 text-sm">${(file.size / 1024 / 1024).toFixed(2)} MB</p>
                    </div>
                </div>
            `;
            previewContainer.classList.remove('hidden');
        }
    }
    
    function submitVerification() {
    const submitStatus = document.getElementById('submit_status');
    const submitBtn = document.getElementById('submit_verification_btn');
    
    // Show loading state
    submitStatus.classList.remove('hidden');
    submitBtn.disabled = true;
    submitBtn.innerHTML = 'Submitting...';
    
    // Prepare form data
    const formData = new FormData();
    
    // Add identity data
    formData.append('action', 'submit_verification');
    formData.append('identity_document_type', verificationData.identity.document_type);
    formData.append('identity_document_number', verificationData.identity.document_number);
    formData.append('identity_file', verificationData.identity.file);
    
    // Add vehicle data if available
    if (verificationData.vehicle.registration_file?.name) {
        formData.append('registration_file', verificationData.vehicle.registration_file);
    }
    if (verificationData.vehicle.insurance_file?.name) {
        formData.append('insurance_file', verificationData.vehicle.insurance_file);
    }
    
    // Debug: Log form data
    console.log('Submitting verification with data:');
    for (let [key, value] of formData.entries()) {
        console.log(key, value);
    }
    
    // Submit to server
    fetch('process_verification.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.text(); // Get raw text first
    })
    .then(text => {
        console.log('Raw response:', text);
        
        // Try to parse as JSON
        try {
            const data = JSON.parse(text);
            console.log('Parsed JSON:', data);
            
            if (data.success) {
                alert('Verification request submitted successfully! We will review your documents within 24-48 hours.');
                verificationModal.close();
                location.reload(); // Refresh to update UI
            } else {
                alert('Error submitting verification: ' + (data.message || 'Unknown error'));
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            console.error('Response was:', text);
            alert('Server error: ' + text.substring(0, 200));
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        alert('Network error occurred while submitting verification.');
    })
    .finally(() => {
        submitStatus.classList.add('hidden');
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Submit for Verification';
    });
}

// Enhanced form validation
function validateCompleteForm() {
    const form = document.getElementById('identity_verification_form');
    const formData = new FormData(form);
    
    console.log('Validating form...');
    
    // Check document type
    if (!formData.get('document_type')) {
        alert('Please select a document type');
        return false;
    }
    
    // Check document number
    const docNumber = formData.get('document_number');
    if (!docNumber || docNumber.trim() === '') {
        alert('Please enter a document number');
        return false;
    }
    
    // Check document number format
    if (!isValidFormat) {
        alert('Please enter a valid document number in the correct format');
        documentNumberInput.focus();
        return false;
    }
    
    // Check file upload
    const file = formData.get('document_file');
    if (!file || !file.name) {
        alert('Please upload a document image');
        return false;
    }
    
    // File size validation (5MB)
    if (file.size > 5 * 1024 * 1024) {
        alert('File size must be less than 5MB');
        return false;
    }
    
    console.log('Form validation passed');
    return true;
}

// Enhanced data collection
function collectIdentityData() {
    const form = document.getElementById('identity_verification_form');
    const formData = new FormData(form);
    
    const file = formData.get('document_file');
    console.log('Collecting identity data:');
    console.log('Document type:', formData.get('document_type'));
    console.log('Document number:', formData.get('document_number'));
    console.log('File name:', file.name);
    console.log('File size:', file.size);
    
    verificationData.identity = {
        document_type: formData.get('document_type'),
        document_number: formData.get('document_number'),
        file: file
    };
}

function collectVehicleData() {
    const form = document.getElementById('vehicle_verification_form');
    const formData = new FormData(form);
    
    console.log('Collecting vehicle data...');
    
    verificationData.vehicle = {
        registration_file: formData.get('registration_file'),
        insurance_file: formData.get('insurance_file')
    };
    
    console.log('Registration file:', verificationData.vehicle.registration_file?.name || 'None');
    console.log('Insurance file:', verificationData.vehicle.insurance_file?.name || 'None');
}
});

// Document validation patterns
const documentPatterns = {
    nid: {
        patterns: [
            /^\d{10}$/,           // Old NID: 10 digits
            /^\d{13}$/,           // New NID: 13 digits  
            /^\d{17}$/            // Extended NID: 17 digits
        ],
        hint: "Enter 10 digits (old NID) or 13-17 digits (new smart NID)",
        examples: ["1234567890", "1234567890123", "12345678901234567"]
    },
    passport: {
        patterns: [
            /^[A-Z]{2}\d{7}$/     // 2 uppercase letters + 7 digits
        ],
        hint: "Enter 2 letters followed by 7 digits (e.g., AB1234567)",
        examples: ["AB1234567", "CD9876543"]
    },
    driving_license: {
        patterns: [
            /^[A-Z]{1,3}\d{6,10}$/,           // 1-3 letters + 6-10 digits
            /^\d{8,12}$/,                     // 8-12 digits only
            /^[A-Z]{2}-\d{2}-\d{6}$/,        // Format: XX-XX-XXXXXX
            /^DHA-[A-Z]-\d{2}-\d{4}$/        // Dhaka format: DHA-X-XX-XXXX
        ],
        hint: "Various formats accepted (e.g., ABC123456, 12345678, DH-01-123456)",
        examples: ["ABC123456", "12345678901", "DH-01-123456", "DHA-A-12-3456"]
    }
};

document.addEventListener('DOMContentLoaded', function() {
    const documentTypeSelect = document.getElementById('identity_document_type');
    const documentNumberInput = document.getElementById('document_number_input');
    const formatHint = document.getElementById('format_hint');
    const validationMessage = document.getElementById('validation_message');
    const successMessage = document.getElementById('success_message');
    const nextButton = document.getElementById('next_to_vehicle_btn');
    
    let currentDocumentType = '';
    let isValidFormat = false;

    // Update format hint when document type changes
    documentTypeSelect.addEventListener('change', function() {
        currentDocumentType = this.value;
        documentNumberInput.value = '';
        hideValidationMessages();
        updateFormatHint();
        updateNextButton();
        
        // Focus on document number input
        if (currentDocumentType) {
            documentNumberInput.focus();
        }
    });

    // Validate document number in real-time
    documentNumberInput.addEventListener('input', function() {
        const documentNumber = this.value.trim().toUpperCase();
        validateDocumentNumber(documentNumber);
    });

    // Also validate on blur
    documentNumberInput.addEventListener('blur', function() {
        const documentNumber = this.value.trim().toUpperCase();
        validateDocumentNumber(documentNumber);
    });

    function updateFormatHint() {
        if (currentDocumentType && documentPatterns[currentDocumentType]) {
            const pattern = documentPatterns[currentDocumentType];
            formatHint.textContent = pattern.hint;
            formatHint.className = 'label-text-alt text-primary/80';
        } else {
            formatHint.textContent = 'Select document type to see format';
            formatHint.className = 'label-text-alt text-white/60';
        }
    }

    function validateDocumentNumber(documentNumber) {
        hideValidationMessages();
        
        if (!currentDocumentType) {
            isValidFormat = false;
            updateNextButton();
            return;
        }

        if (!documentNumber) {
            isValidFormat = false;
            updateNextButton();
            return;
        }

        const pattern = documentPatterns[currentDocumentType];
        if (!pattern) {
            isValidFormat = false;
            updateNextButton();
            return;
        }

        // Check if document number matches any of the patterns
        const isValid = pattern.patterns.some(regex => regex.test(documentNumber));

        if (isValid) {
            showSuccessMessage();
            documentNumberInput.classList.remove('invalid');
            documentNumberInput.classList.add('valid');
            isValidFormat = true;
        } else {
            showValidationError(pattern);
            documentNumberInput.classList.remove('valid');
            documentNumberInput.classList.add('invalid');
            isValidFormat = false;
        }

        updateNextButton();
    }

    function showValidationError(pattern) {
        const validationText = document.getElementById('validation_text');
        validationText.innerHTML = `
            Invalid format. Expected: ${pattern.hint}<br>
            <small>Examples: ${pattern.examples.join(', ')}</small>
        `;
        validationMessage.classList.remove('hidden');
    }

    function showSuccessMessage() {
        successMessage.classList.remove('hidden');
    }

    function hideValidationMessages() {
        validationMessage.classList.add('hidden');
        successMessage.classList.add('hidden');
        documentNumberInput.classList.remove('valid', 'invalid');
    }

    function updateNextButton() {
        if (isValidFormat && currentDocumentType && documentNumberInput.value.trim()) {
            nextButton.disabled = false;
            nextButton.classList.remove('btn-disabled');
        } else {
            nextButton.disabled = true;
            nextButton.classList.add('btn-disabled');
        }
    }

    // Enhanced form validation for the next step
    const originalNextFunction = nextToVehicleBtn?.onclick;
    if (nextButton) {
        nextButton.addEventListener('click', function(e) {
            if (!validateCompleteForm()) {
                e.preventDefault();
                return false;
            }
            // Proceed with original function if exists
            if (originalNextFunction) {
                originalNextFunction.call(this, e);
            }
        });
    }

    function validateCompleteForm() {
        const form = document.getElementById('identity_verification_form');
        const formData = new FormData(form);
        
        // Check document type
        if (!formData.get('document_type')) {
            alert('Please select a document type');
            return false;
        }
        
        // Check document number format
        if (!isValidFormat) {
            alert('Please enter a valid document number in the correct format');
            documentNumberInput.focus();
            return false;
        }
        
        // Check file upload
        if (!formData.get('document_file').name) {
            alert('Please upload a document image');
            return false;
        }
        
        // File size validation (5MB)
        const file = formData.get('document_file');
        if (file.size > 5 * 1024 * 1024) {
            alert('File size must be less than 5MB');
            return false;
        }
        
        return true;
    }

    // Add some helpful formatting for certain document types
    documentNumberInput.addEventListener('keyup', function(e) {
        let value = this.value.toUpperCase();
        
        // Auto-format passport numbers
        if (currentDocumentType === 'passport') {
            // Remove any non-alphanumeric characters
            value = value.replace(/[^A-Z0-9]/g, '');
            
            // Format as XX1234567
            if (value.length > 2) {
                value = value.substring(0, 2) + value.substring(2);
            }
            
            this.value = value;
        }
        
        // Auto-format some driving license formats
        if (currentDocumentType === 'driving_license') {
            // Remove spaces and special characters for now
            value = value.replace(/[^A-Z0-9-]/g, '');
            this.value = value;
        }
        
        // For NID, only allow numbers
        if (currentDocumentType === 'nid') {
            value = value.replace(/[^0-9]/g, '');
            this.value = value;
        }
    });
});

// Additional helper functions for document validation

function isValidNID(nid) {
    // Basic NID validation for Bangladesh
    if (!nid || typeof nid !== 'string') return false;
    
    const cleanNID = nid.replace(/\s/g, '');
    
    // Check length (10, 13, or 17 digits)
    if (!/^\d{10}$|^\d{13}$|^\d{17}$/.test(cleanNID)) {
        return false;
    }
    
    // Additional validation can be added here
    // For example, check digit validation for new smart NIDs
    
    return true;
}

function isValidPassport(passport) {
    // Bangladesh passport format: 2 letters + 7 digits
    if (!passport || typeof passport !== 'string') return false;
    
    const cleanPassport = passport.replace(/\s/g, '').toUpperCase();
    return /^[A-Z]{2}\d{7}$/.test(cleanPassport);
}

function isValidDrivingLicense(license) {
    // Various formats accepted for Bangladesh driving licenses
    if (!license || typeof license !== 'string') return false;
    
    const cleanLicense = license.replace(/\s/g, '').toUpperCase();
    
    const patterns = [
        /^[A-Z]{1,3}\d{6,10}$/,      // Letters + digits
        /^\d{8,12}$/,                 // Digits only
        /^[A-Z]{2}-\d{2}-\d{6}$/,    // XX-XX-XXXXXX
        /^DHA-[A-Z]-\d{2}-\d{4}$/    // Dhaka format
    ];
    
    return patterns.some(pattern => pattern.test(cleanLicense));
}

// Export validation functions for use in other parts of the application
window.documentValidation = {
    isValidNID,
    isValidPassport,
    isValidDrivingLicense,
    patterns: documentPatterns
};
</script>
</body>
</html>