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

// Get user's vehicles
$vehicles = [];
$vehicleQuery = "SELECT * FROM vehicle_information WHERE username = '$username'";
$vehicleResult = $conn->query($vehicleQuery);

if ($vehicleResult && $vehicleResult->num_rows > 0) {
    while ($row = $vehicleResult->fetch_assoc()) {
        $vehicles[] = $row;
    }
}

// Check if delete action is requested
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['plate'])) {
    $plateToDelete = $_GET['plate'];
    
    // Delete the vehicle
    $deleteQuery = "DELETE FROM vehicle_information WHERE licensePlate = ? AND username = ?";
    $stmt = $conn->prepare($deleteQuery);
    $stmt->bind_param("ss", $plateToDelete, $username);
    
    if ($stmt->execute()) {
        // Redirect to avoid resubmission
        header("Location: myvehicles.php?deleted=success");
        exit();
    } else {
        $deleteError = "Failed to delete vehicle. It may be associated with active bookings.";
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
    <title>My Vehicles - পার্কিং লাগবে</title>
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
                    <li><a href="my_profile.php" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">My Profile</a></li>
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
                <h2 class="text-3xl md:text-4xl font-bold text-white mb-2">My Vehicles</h2>
                <p class="text-white/80">Manage your vehicles for parking reservations</p>
            </div>
            
            <a href="add_vehicle.php" class="btn bg-primary hover:bg-primary-dark text-white border-none mt-4 md:mt-0">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Add New Vehicle
            </a>
        </section>
        
        <?php if (isset($_GET['deleted']) && $_GET['deleted'] == 'success'): ?>
        <div class="alert alert-success mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <span>Vehicle successfully deleted!</span>
        </div>
        <?php endif; ?>
        
        <?php if (isset($deleteError)): ?>
        <div class="alert alert-error mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <span><?php echo $deleteError; ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Vehicles Grid -->
        <section class="mb-10">
            <?php if (empty($vehicles)): ?>
            <div class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-10 animate-fadeIn shadow-xl text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mx-auto text-white/30 mb-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                <h3 class="text-white text-xl font-semibold mb-2">No Vehicles Found</h3>
                <p class="text-white/70 mb-6">You haven't added any vehicles to your account yet.</p>
                <a href="add_vehicle.php" class="btn bg-primary hover:bg-primary-dark text-white border-none">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Add Your First Vehicle
                </a>
            </div>
            <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($vehicles as $index => $vehicle): ?>
                <div class="bg-black/20 backdrop-blur-md rounded-lg border border-white/10 p-6 animate-fadeIn shadow-xl hover:border-primary/50 transition-all">
                    <div class="flex justify-between items-start mb-6">
                        <div class="w-14 h-14 rounded-full bg-primary/20 border-2 border-primary flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.5 2.8C1.4 11.3 1 12.1 1 13v3c0 .6.4 1 1 1h2"></path><circle cx="7" cy="17" r="2"></circle><circle cx="17" cy="17" r="2"></circle></svg>
                        </div>
                        <div class="dropdown dropdown-end">
                            <div tabindex="0" role="button" class="btn btn-ghost btn-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white/70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle cx="5" cy="12" r="1"></circle></svg>
                            </div>
                            <ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-40">
                                <li><a href="edit_vehicle.php?plate=<?php echo urlencode($vehicle['licensePlate']); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    Edit
                                </a></li>
                                <li><a href="myvehicles.php?action=delete&plate=<?php echo urlencode($vehicle['licensePlate']); ?>" class="text-red-500" onclick="return confirm('Are you sure you want to delete this vehicle?');">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                    Delete
                                </a></li>
                            </ul>
                        </div>
                    </div>
                    
                    <h3 class="text-white text-xl font-semibold mb-1"><?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']); ?></h3>
                    <p class="text-white/70 text-sm mb-4"><?php echo ucfirst(htmlspecialchars($vehicle['vehicleType'])); ?> • <?php echo htmlspecialchars($vehicle['color']); ?></p>
                    
                    <div class="bg-black/30 rounded-lg p-4 text-center mb-4">
                        <p class="text-white/60 text-xs mb-1">License Plate</p>
                        <p class="text-white text-lg font-semibold"><?php echo htmlspecialchars($vehicle['licensePlate']); ?></p>
                    </div>
                    
                    <div class="flex space-x-2">
                        <a href="edit_vehicle.php?plate=<?php echo urlencode($vehicle['licensePlate']); ?>" class="flex-1 bg-primary hover:bg-primary-dark text-white text-center text-sm py-2 rounded transition duration-300">Edit</a>
                        <a href="myvehicles.php?action=delete&plate=<?php echo urlencode($vehicle['licensePlate']); ?>" class="flex-1 bg-white/10 hover:bg-white/20 text-white text-center text-sm py-2 rounded transition duration-300" onclick="return confirm('Are you sure you want to delete this vehicle?');">Delete</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
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