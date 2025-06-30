<?php
// Start the session at the very top
session_start();

date_default_timezone_set('Asia/Dhaka');
// For connecting to database
require_once("connection.php");


if (isset($conn)) {
    // Set MySQL timezone to Bangladesh
    $conn->query("SET time_zone = '+06:00'");
    
    // STEP 3: ONE-TIME DATABASE CLEANUP - Fix all wrong timestamps
    $cleanup_queries = [
        // Reset all future notification check times to current time
        "UPDATE user_notification_checks SET last_check_time = NOW() WHERE last_check_time > NOW()",
        
        // Fix any future created_at times in bookings
        "UPDATE bookings SET created_at = NOW() WHERE created_at > NOW()",
        
        // Fix any future payment_date times
        "UPDATE payments SET payment_date = NOW() WHERE payment_date > NOW()",
        
        // Fix any future registration_date times
        "UPDATE garage_owners SET registration_date = NOW() WHERE registration_date > NOW()",
        "UPDATE dual_user SET registration_date = NOW() WHERE registration_date > NOW()",
    ];
    
    // Execute cleanup queries
    foreach ($cleanup_queries as $query) {
        $conn->query($query);
    }
}



if (isset($_POST['action']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    $response = ['success' => false];
    
    switch ($_POST['action']) {
        case 'get_notification_items':
            $username = $_SESSION['username'];
            $userNotifications = getUserNotifications($conn, $username);
            
            $response = [
                'success' => true,
                'bookings' => $userNotifications['bookings'],
                'verifications' => $userNotifications['verifications'],
                'payments' => $userNotifications['payments']
            ];
            break;
            
        case 'mark_notifications_read':
            $username = $_SESSION['username'];
            $currentTime = date('Y-m-d H:i:s'); // Bangladesh time
            $_SESSION['last_notification_check'] = $currentTime;
            
            // Update in database
            $checkQuery = "SELECT username FROM user_notification_checks WHERE username = ?";
            $stmt = $conn->prepare($checkQuery);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $checkResult = $stmt->get_result();
            
            if ($checkResult && $checkResult->num_rows > 0) {
                // Update existing record
                $updateQuery = "UPDATE user_notification_checks SET last_check_time = ? WHERE username = ?";
                $stmt = $conn->prepare($updateQuery);
                $stmt->bind_param("ss", $currentTime, $username);
            } else {
                // Insert new record
                $insertQuery = "INSERT INTO user_notification_checks (username, last_check_time) VALUES (?, ?)";
                $stmt = $conn->prepare($insertQuery);
                $stmt->bind_param("ss", $username, $currentTime);
            }
            
            $stmt->execute();
            $response = ['success' => true];
            break;

            // Add this case to your existing switch statement in home.php
case 'get_reviews':
    $garage_id = $_POST['garage_id'] ?? '';
    
    if (empty($garage_id)) {
        $response = ['success' => false, 'message' => 'Garage ID is required'];
        break;
    }

    try {
        // Get garage information
        $garageQuery = "SELECT Parking_Space_Name, Parking_Lot_Address 
                        FROM garage_information 
                        WHERE garage_id = ?";
        $garageStmt = $conn->prepare($garageQuery);
        $garageStmt->bind_param("s", $garage_id);
        $garageStmt->execute();
        $garageResult = $garageStmt->get_result();
        $garageInfo = $garageResult->fetch_assoc();

        // Get reviews for this garage
        $reviewQuery = "SELECT r.rating, r.review_text, r.rater_username, r.created_at,
                               CONCAT(COALESCE(p.firstName, ''), ' ', COALESCE(p.lastName, '')) as reviewer_name
                        FROM ratings r
                        LEFT JOIN personal_information p ON r.rater_username = p.username  
                        WHERE r.garage_id = ? 
                        ORDER BY r.created_at DESC";
        
        $reviewStmt = $conn->prepare($reviewQuery);
        $reviewStmt->bind_param("s", $garage_id);
        $reviewStmt->execute();
        $reviewResult = $reviewStmt->get_result();

        $reviews = [];
        while ($row = $reviewResult->fetch_assoc()) {
            // Format the date
            $row['formatted_date'] = date('M j, Y', strtotime($row['created_at']));
            $reviews[] = $row;
        }

        // Get rating summary from existing table
        $summaryQuery = "SELECT total_ratings, average_rating, five_star, four_star, three_star, two_star, one_star
                         FROM garage_ratings_summary 
                         WHERE garage_id = ?";
        $summaryStmt = $conn->prepare($summaryQuery);
        $summaryStmt->bind_param("s", $garage_id);
        $summaryStmt->execute();
        $summaryResult = $summaryStmt->get_result();
        $summary = $summaryResult->fetch_assoc();

        $response = [
            'success' => true,
            'garage' => $garageInfo,
            'reviews' => $reviews,
            'summary' => $summary
        ];

    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error loading reviews: ' . $e->getMessage()];
    }
    break;
    }
    
    // Return JSON response for AJAX requests
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// STEP 5: FIXED NOTIFICATION COUNTING LOGIC
$notificationCount = 0;

// Only calculate if user is logged in and we have database connection
if (isset($_SESSION['username']) && isset($conn)) {
    $username = $_SESSION['username'];
    
    // Set proper check time (24 hours ago)
    if (!isset($_SESSION['last_notification_check'])) {
        $_SESSION['last_notification_check'] = date('Y-m-d H:i:s', strtotime('-24 hours'));
    }
    
    $lastCheck = $_SESSION['last_notification_check'];
    
    try {
        // Count new bookings for garage owners
        $sql = "SELECT COUNT(*) as count FROM bookings b 
                JOIN garage_information g ON b.garage_id = g.garage_id 
                WHERE g.username = ? AND b.created_at > ? AND b.created_at <= NOW()";
        
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("ss", $username, $lastCheck);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $notificationCount += (int)$row['count'];
            }
            $stmt->close();
        }
        
        // Count verification updates
        $sql2 = "SELECT COUNT(*) as count FROM garage_owners 
                WHERE username = ? AND registration_date > ? AND registration_date <= NOW()";
        
        if ($stmt2 = $conn->prepare($sql2)) {
            $stmt2->bind_param("ss", $username, $lastCheck);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            if ($row2 = $result2->fetch_assoc()) {
                $notificationCount += (int)$row2['count'];
            }
            $stmt2->close();
        }
        
        // Count payment updates
        $sql3 = "SELECT COUNT(*) as count FROM payments p 
                JOIN bookings b ON p.booking_id = b.id 
                JOIN garage_information g ON b.garage_id = g.garage_id 
                WHERE g.username = ? AND p.payment_date > ? AND p.payment_date <= NOW()";
        
        if ($stmt3 = $conn->prepare($sql3)) {
            $stmt3->bind_param("ss", $username, $lastCheck);
            $stmt3->execute();
            $result3 = $stmt3->get_result();
            if ($row3 = $result3->fetch_assoc()) {
                $notificationCount += (int)$row3['count'];
            }
            $stmt3->close();
        }
        
        // Add manual notification for unverified users
        $checkUserStatus = "SELECT status FROM account_information WHERE username = ?";
        if ($statusStmt = $conn->prepare($checkUserStatus)) {
            $statusStmt->bind_param("s", $username);
            $statusStmt->execute();
            $statusResult = $statusStmt->get_result();
            if ($statusRow = $statusResult->fetch_assoc()) {
                if ($statusRow['status'] === 'unverified') {
                    $notificationCount += 1; // Add 1 notification for unverified status
                }
            }
            $statusStmt->close();
        }
        
    } catch (Exception $e) {
        // If there's any error, just keep count at 0
        $notificationCount = 0;
        error_log("Notification count error: " . $e->getMessage());
    }
} else {
    // User not logged in or no database connection
    $notificationCount = 0;
}

// Create the notification table if it doesn't exist
$createTableQuery = "CREATE TABLE IF NOT EXISTS `user_notification_checks` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `username` varchar(255) NOT NULL,
    `last_check_time` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`)
)";

if (!$conn->query($createTableQuery)) {
    error_log("Error creating user_notification_checks table: " . $conn->error);
}
if (!isset($_SESSION['username'])) {
  header("Location: login.php");
  exit();
}
// Debug info (remove after confirming everything works)
echo "<!-- TIMEZONE FIX APPLIED -->";
echo "<!-- Current Bangladesh Time: " . date('Y-m-d H:i:s T') . " -->";
echo "<!-- Notification Count: " . $notificationCount . " -->";
/**
 * Get notifications for the current user
 * 
 * @param mysqli $conn Database connection
 * @param string $username Current username
 * @return array Array of notifications by type
 */
function getUserNotifications($conn, $username) {
    $notifications = [
        'bookings' => [],
        'verifications' => [],
        'payments' => []
    ];
    
    // Set proper check time
    if (!isset($_SESSION['last_notification_check'])) {
        // Try to get from the database first
        $checkQuery = "SELECT last_check_time FROM user_notification_checks WHERE username = ?";
        if ($stmt = $conn->prepare($checkQuery)) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $checkResult = $stmt->get_result();
            
            if ($checkResult && $checkResult->num_rows > 0) {
                $row = $checkResult->fetch_assoc();
                $_SESSION['last_notification_check'] = $row['last_check_time'];
            } else {
                $_SESSION['last_notification_check'] = date('Y-m-d H:i:s', strtotime('-24 hours'));
            }
            $stmt->close();
        }
    }
    
    $lastCheck = $_SESSION['last_notification_check'];
    
    // Get new bookings
    $bookingsQuery = "SELECT b.id, b.booking_date, b.booking_time, g.Parking_Space_Name as parking_name, 
                     a.username as customer_username, CONCAT(COALESCE(p.firstName, ''), ' ', COALESCE(p.lastName, '')) as customer_name
                     FROM bookings b
                     JOIN garage_information g ON b.garage_id = g.garage_id
                     JOIN account_information a ON b.username = a.username
                     LEFT JOIN personal_information p ON a.username = p.username
                     WHERE g.username = ? 
                     AND b.created_at > ? AND b.created_at <= NOW()
                     ORDER BY b.created_at DESC
                     LIMIT 10";
    if ($stmt = $conn->prepare($bookingsQuery)) {
        $stmt->bind_param("ss", $username, $lastCheck);
        $stmt->execute();
        $bookingsResult = $stmt->get_result();
        
        while ($row = $bookingsResult->fetch_assoc()) {
            $row['booking_date_formatted'] = date('M d, Y', strtotime($row['booking_date']));
            $row['booking_time_formatted'] = date('h:i A', strtotime($row['booking_time']));
            $notifications['bookings'][] = $row;
        }
        $stmt->close();
    }
    
    // Get verification updates - check current user status
    $userStatusQuery = "SELECT username, status, registration_date FROM account_information WHERE username = ?";
    if ($stmt = $conn->prepare($userStatusQuery)) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $userResult = $stmt->get_result();
        
        if ($userRow = $userResult->fetch_assoc()) {
            // Add verification notification based on current status
            $notification = [
                'username' => $userRow['username'],
                'status' => $userRow['status'],
                'registration_date' => $userRow['registration_date'],
                'is_verified' => ($userRow['status'] === 'verified') ? 1 : 0
            ];
            $notifications['verifications'][] = $notification;
        }
        $stmt->close();
    }
    
    // Get payment updates
    $paymentsQuery = "SELECT p.payment_id, p.booking_id, p.amount, p.payment_status, 
                     p.payment_date, g.Parking_Space_Name as parking_name
                     FROM payments p
                     JOIN bookings b ON p.booking_id = b.id
                     JOIN garage_information g ON b.garage_id = g.garage_id
                     WHERE g.username = ? AND p.payment_date > ? AND p.payment_date <= NOW()
                     ORDER BY p.payment_date DESC
                     LIMIT 10";
    if ($stmt = $conn->prepare($paymentsQuery)) {
        $stmt->bind_param("ss", $username, $lastCheck);
        $stmt->execute();
        $paymentsResult = $stmt->get_result();
        
        while ($row = $paymentsResult->fetch_assoc()) {
            $row['payment_date_formatted'] = date('M d, Y h:i A', strtotime($row['payment_date']));
            $notifications['payments'][] = $row;
        }
        $stmt->close();
    }
    
    return $notifications;
}

// Check if user is logged in, redirect to login page if not
if (!isset($_SESSION['username'])) {
  header("Location: login.php");
  exit();
}
// Query garages from database (JOIN on username)
$garageQuery = "SELECT g.id, g.username, g.garage_id, g.Parking_Space_Name, g.Parking_Lot_Address, 
            g.Parking_Type, g.Parking_Space_Dimensions, g.Parking_Capacity, 
            g.Availability, g.PriceperHour, gl.Latitude, gl.Longitude,
            COALESCE(grs.average_rating, 0) as average_rating,
            COALESCE(grs.total_ratings, 0) as total_ratings,
            gos.opening_time, gos.closing_time, gos.operating_days, gos.is_24_7,
            grts.current_status, grts.is_manual_override
            FROM garage_information g
            JOIN garagelocation gl ON g.username = gl.username AND g.garage_id = gl.garage_id
            LEFT JOIN garage_ratings_summary grs ON g.garage_id = grs.garage_id
            LEFT JOIN garage_operating_schedule gos ON g.garage_id = gos.garage_id
            LEFT JOIN garage_real_time_status grts ON g.garage_id = grts.garage_id";

// Execute the query
$garageResult = $conn->query($garageQuery);

// Initialize the array
$garageLocations = array();

// Check if there are results
if ($garageResult && $garageResult->num_rows > 0) {
 // Loop through the results
 while ($row = $garageResult->fetch_assoc()) {
   // Add each garage to the array with all timing and status data
   $garageLocations[] = array(
  'id' => $row['garage_id'],
  'username' => $row['username'],
  'name' => $row['Parking_Space_Name'],
  'address' => $row['Parking_Lot_Address'],
  'lat' => $row['Latitude'],
  'lng' => $row['Longitude'],
  'type' => $row['Parking_Type'],
  'dimensions' => $row['Parking_Space_Dimensions'],
  'capacity' => $row['Parking_Capacity'],
  'available' => $row['Availability'],
  'price' => $row['PriceperHour'],  // ✅ Correct key name (lowercase 'p')
  // Add timing and status fields for filtering
  'average_rating' => $row['average_rating'],
  'total_ratings' => $row['total_ratings'],
  'opening_time' => $row['opening_time'],
  'closing_time' => $row['closing_time'],
  'operating_days' => $row['operating_days'],
  'is_24_7' => $row['is_24_7'],
  'current_status' => $row['current_status'],
  'is_manual_override' => $row['is_manual_override']
);
 }
} else {
 // If no results, initialize with an empty array
 $garageLocations = array();
 // Optional: Add debug message
 // echo "No garage locations found.";
}

// Filter out closed garages using the same logic as Featured Parking
$filteredGarageLocations = array();
foreach ($garageLocations as $garage) {
   $garageStatus = getGarageStatus($garage);
   
   // Skip closed garages (same logic as Featured Parking)
   if (in_array($garageStatus['status'], ['Closed', 'Manually Closed', 'Maintenance', 'Emergency Closed', 'Closed Today'])) {
       continue;
   }
   
   $filteredGarageLocations[] = $garage;
}

// Replace the original array with filtered one
$garageLocations = $filteredGarageLocations;

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
echo "<script>\n";
echo "// Make garage location data available to JavaScript\n";
echo "const dbGarageLocations = " . json_encode($garageLocations) . ";\n";
echo "console.log('Garage locations loaded for distance calculation:', dbGarageLocations.length);\n";
echo "</script>\n";
?>

<!DOCTYPE html>
<html lang="en" data-theme="dark">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Car Parking System - Home</title>
  <!-- Tailwind CSS and daisyUI -->
  <link href="https://cdn.jsdelivr.net/npm/daisyui@4.7.3/dist/full.min.css" rel="stylesheet" type="text/css" />
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Leaflet CSS for maps -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
  <!-- Leaflet Geocoder for location search -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
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
  <!-- Add this style section in the <head> of your HTML -->
  <style>
    /* Make modal appear above map with proper z-index */
    #bookingModal {
      z-index: 2000 !important;
      /* Higher than Leaflet's z-index (usually 1000) */
    }

    /* Make sure the map has a lower z-index */
    .leaflet-container,
    .leaflet-control-container,
    .leaflet-pane {
      z-index: 900 !important;
    }

    .leaflet-popup {
      z-index: 950 !important;
    }

    /* Make modal content styled properly */
    #bookingModal .relative {
      position: relative;
      z-index: 2001;
    }

    /* Make background overlay visible but below modal content */
    #bookingModal .fixed.inset-0.bg-black {
      z-index: 1999;
    }

    /* FIXED: Separate styles for "Fully Booked" vs "Booked" buttons */
    /* Only apply red to "Fully Booked" buttons */
    .leaflet-popup-content button[disabled]:not(:contains("Booked")),
    button[disabled].bg-error:not(:contains("Booked")),
    .leaflet-popup-content button[disabled]:contains("Fully Booked") {
      background-color: #F87272 !important;
      color: white !important;
      text-shadow: 0 1px 2px rgba(0, 0, 0, 0.4) !important;
      font-weight: bold !important;
      opacity: 1 !important;
    }

    /* New rule specifically for "Booked" buttons (your bookings) */
    .leaflet-popup-content button[disabled]:contains("Booked"):not(:contains("Fully")),
    button.bg-success[disabled] {
      background-color: #36D399 !important;
      /* Green success color */
      border-color: #36D399 !important;
      color: white !important;
      text-shadow: 0 1px 2px rgba(0, 0, 0, 0.4) !important;
      font-weight: bold !important;
      opacity: 1 !important;
    }

    /* General button styling */
    .leaflet-popup-content button {
      color: white !important;
      font-weight: bold !important;
      font-size: 1rem !important;
    }

    /* Add to your existing styles */
    .timer-availability {
      background-color: rgba(59, 130, 246, 0.2) !important;
      /* Blue background */
      border: 1px solid rgba(59, 130, 246, 0.5) !important;
      color: #1e3a8a !important;
    }

    .timer-availability .countdown-value {
      font-weight: bold !important;
    }

    /* Add these styles to your existing stylesheet */
    /* Book For Later button styles */
    .btn.bg-blue-600 {
      background-color: #2563eb;
      border-color: #2563eb;
    }

    .btn.bg-blue-600:hover {
      background-color: #1d4ed8;
      border-color: #1d4ed8;
    }

    /* Special styling for the popup with two buttons */
    .leaflet-popup-content {
      min-width: 240px !important;
    }

    /* Highlight the Book For Later button with a subtle animation */
    @keyframes pulse-blue {
      0% {
        box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.7);
      }

      70% {
        box-shadow: 0 0 0 5px rgba(37, 99, 235, 0);
      }

      100% {
        box-shadow: 0 0 0 0 rgba(37, 99, 235, 0);
      }
    }

    .btn.bg-blue-600 {
      animation: pulse-blue 2s infinite;
    }

    /* Notification System Styles */
.notification-container {
    position: relative;
    display: inline-block;
    z-index: 1000;
}

.notification-dropdown {
    position: absolute;
    right: 0;
    margin-top: 8px;
    width: 320px;
    background-color: #1f2937;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
    border: 1px solid #374151;
    overflow: hidden;
    z-index: 9999 !important;
    transform-origin: top right;
    transition: all 0.2s ease-out;
}

.notification-dropdown.hidden {
    display: none;
    opacity: 0;
    transform: scale(0.95) translateY(-10px);
}

.notification-dropdown:not(.hidden) {
    display: block;
    opacity: 1;
    transform: scale(1) translateY(0);
    animation: slideDown 0.2s ease-out forwards;
}

.notification-content {
    max-height: 384px;
    overflow-y: auto;
}

.notification-content::-webkit-scrollbar {
    width: 4px;
}

.notification-content::-webkit-scrollbar-track {
    background: #374151;
}

.notification-content::-webkit-scrollbar-thumb {
    background: #6b7280;
    border-radius: 2px;
}

.notification-content::-webkit-scrollbar-thumb:hover {
    background: #9ca3af;
}

/* Animation for notification dropdown */
@keyframes slideDown {
    from {
        opacity: 0;
        transform: scale(0.95) translateY(-10px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

/* Loading spinner animation */
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.animate-spin {
    animation: spin 1s linear infinite;
}

/* Notification item hover effects */
.notification-item {
    padding: 12px 16px;
    border-bottom: 1px solid #374151;
    transition: background-color 0.2s ease;
    cursor: pointer;
}

.notification-item:hover {
    background-color: #374151;
}

.notification-item:last-child {
    border-bottom: none;
}

/* Badge pulse animation */
@keyframes pulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.1);
    }
}

#notification-count {
    animation: pulse 2s infinite;
}

/* Ensure dropdown appears above everything */
.notification-dropdown {
    z-index: 99999 !important;
    position: absolute !important;
}

/* Make sure the header doesn't interfere */
header {
    z-index: 1001 !important;
}

.notification-container {
    z-index: 99998 !important;
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
  <header class="sticky top-0 z-50 bg-black/50 backdrop-blur-md border-b border-white/20 "
    style="z-index: 1001 !important;">
    <div class="container mx-auto px-4 py-4 flex justify-between items-center">
      <a href="#" class="flex items-center gap-4 text-white">
        <div class="w-10 h-10 bg-primary rounded-full flex justify-center items-center overflow-hidden">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
            <path d="M9 18V6h4.5a2.5 2.5 0 0 1 0 5H9"></path>
          </svg>
        </div>
        <h1 class="text-xl font-semibold drop-shadow-md">পার্কিং লাগবে ?</h1>
      </a>

      <nav class="hidden md:block">
        <ul class="flex gap-8">
          <li><a href="#"
              class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Home</a>
          </li>
          <li><a href="#how-it-works"
              class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">How
              It Works</a></li>
          <li><a href="#contact"
              class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Contact</a>
          </li>
        </ul>
      </nav>

      <div class="hidden md:flex items-center gap-4">
        <a href="business_redirect.php"
          class="btn btn-outline btn-sm text-white border-primary hover:bg-primary hover:border-primary">
          Switch To Business
        </a>

        <!-- ==================== COMPLETE NOTIFICATION SYSTEM ==================== -->
<div class="notification-container relative">
    <!-- Notification Button -->
    <button id="notification-button" class="btn btn-sm btn-ghost text-white relative">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
        </svg>
        <!-- Notification count badge (only shows when count > 0) -->
        <?php if ($notificationCount > 0): ?>
    <span id="notification-count" class="absolute -top-1 -right-1 bg-primary text-white text-xs font-bold rounded-full h-5 w-5 flex items-center justify-center" style="background-color: #f39c12 !important; z-index: 10;">
        <?php echo $notificationCount; ?>
    </span>
    <?php endif; ?>
    </button>
    
    <!-- Notification Dropdown -->
    <div id="notification-dropdown" class="notification-dropdown hidden absolute right-0 mt-2 w-80 bg-gray-800 rounded-lg shadow-lg border border-gray-700 overflow-hidden z-50">
        <!-- Dropdown Header -->
        <div class="p-3 border-b border-gray-700 bg-gray-900">
            <h3 class="font-bold text-white">Notifications</h3>
        </div>
        
        <!-- Notification Content Container -->
        <div id="notification-content" class="notification-content max-h-96 overflow-y-auto">
            <!-- Loading state shown initially -->
            <div class="p-6 text-center text-white/70">
                <div class="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-primary mx-auto mb-3"></div>
                <p>Loading notifications...</p>
            </div>
        </div>
        
        <!-- Dropdown Footer with Mark as Read button -->
        <div class="p-3 border-t border-gray-700 text-center bg-gray-900">
            <button id="mark-read-btn" class="btn btn-sm bg-primary hover:bg-primary-dark text-white w-full">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="9 11 12 14 22 4"></polyline>
                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                </svg>
                Mark All as Read
            </button>
        </div>
    </div>
</div>
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
    <!-- Hero Section -->
    <section class="flex flex-col items-center text-center py-16">
      <h2 class="text-4xl md:text-5xl font-bold text-white mb-5 drop-shadow-md">Find and Reserve Parking Spaces in
        Real-Time</h2>
      <p class="text-lg text-white/90 max-w-2xl mb-8">Discover available parking spots, compare prices, and book in
        advance to save time and money.</p>


    </section>

    <!-- Map Section -->
    <section class="mb-16">
      <div class="flex justify-between items-center mb-6">
        <h2 class="text-3xl font-bold text-white drop-shadow-md">Parking Locations Near You</h2>
        <div class="flex gap-2">
          <button id="locateMe"
            class="btn btn-outline btn-sm text-white border-primary hover:bg-primary hover:border-primary flex items-center gap-1">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10"></circle>
              <circle cx="12" cy="12" r="1"></circle>
              <line x1="12" y1="2" x2="12" y2="4"></line>
              <line x1="12" y1="20" x2="12" y2="22"></line>
              <line x1="2" y1="12" x2="4" y2="12"></line>
              <line x1="20" y1="12" x2="22" y2="12"></line>
            </svg>
            Locate Me
          </button>


        </div>
      </div>


      <div class="rounded-xl overflow-hidden border border-white/20 shadow-xl h-[500px]">
        <div id="map" class="w-full h-full"></div>
      </div>

      <!-- Location Status -->
      <div id="locationStatus" class="mt-2 text-sm text-white/80 hidden">
        <span id="locationMessage"></span>
        <div class="flex items-center gap-2 mt-1">
          <span class="w-2 h-2 bg-primary rounded-full animate-pulse"></span>
          <span id="locationAccuracy"></span>
        </div>
      </div>
    </section>

    <!-- Featured Parking Carousel -->
    <section class="mb-16">
      <div class="flex justify-between items-center mb-6">
        <h2 class="text-3xl font-bold text-white drop-shadow-md">Featured Parking Locations</h2>
        
      </div>

      <!-- Carousel Container -->
      <div class="relative">
        <!-- Left Arrow -->
        <button id="prevParking"
          class="absolute left-[-40px] top-1/2 -translate-y-1/2 bg-black/80 hover:bg-primary text-white rounded-full p-3 z-10 transition-all duration-300 hover:scale-110 focus:outline-none shadow-lg">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="15 18 9 12 15 6"></polyline>
          </svg>
        </button>


        <!-- Carousel Items - Fixed Width Container to Show Exactly 3 Cards -->
        <div class="overflow-hidden">
          <div id="parkingCarousel" class="flex gap-6 scroll-smooth transition-all duration-300">
            <?php
 // Query to get featured parking locations with real ratings, status, and timing
$featuredQuery = "SELECT g.id, g.username, g.garage_id, g.Parking_Space_Name, g.Parking_Lot_Address, 
             g.Parking_Type, g.Parking_Space_Dimensions, g.Parking_Capacity, 
             g.Availability, g.PriceperHour, gl.Latitude, gl.Longitude,
             COALESCE(grs.average_rating, 0) as average_rating,
             COALESCE(grs.total_ratings, 0) as total_ratings,
             gos.opening_time, gos.closing_time, gos.operating_days, gos.is_24_7,
             grts.current_status, grts.is_manual_override
             FROM garage_information g
             JOIN garagelocation gl ON g.username = gl.username AND g.garage_id = gl.garage_id
             LEFT JOIN garage_ratings_summary grs ON g.garage_id = grs.garage_id
             LEFT JOIN garage_operating_schedule gos ON g.garage_id = gos.garage_id
             LEFT JOIN garage_real_time_status grts ON g.garage_id = grts.garage_id
             ORDER BY g.id";

$featuredResult = $conn->query($featuredQuery);

function getGarageStatus($garage) {
    $currentTime = date('H:i:s'); // Current time
    $currentDay = strtolower(date('l')); // Current day
    
    // Check real-time status first
    if ($garage['current_status'] === 'maintenance') {
        return ['status' => 'Maintenance', 'color' => 'bg-yellow-500', 'text_color' => 'text-yellow-400'];
    }
    if ($garage['current_status'] === 'emergency_closed') {
        return ['status' => 'Emergency Closed', 'color' => 'bg-red-600', 'text_color' => 'text-red-400'];
    }
    if ($garage['current_status'] === 'closed' && $garage['is_manual_override']) {
        return ['status' => 'Manually Closed', 'color' => 'bg-red-500', 'text_color' => 'text-red-400'];
    }
    // ADD THIS LINE - Check for any closed status
    if ($garage['current_status'] === 'closed') {
        return ['status' => 'Closed', 'color' => 'bg-red-500', 'text_color' => 'text-red-400'];
    }
    
    // Check if 24/7
    if ($garage['is_24_7'] == 1) {
        return ['status' => '24/7', 'color' => 'bg-green-500', 'text_color' => 'text-green-400'];
    }
    
    // Check if current day is in operating days
    if (!empty($garage['operating_days'])) {
        $operatingDays = explode(',', $garage['operating_days']);
        if (!in_array($currentDay, $operatingDays)) {
            return ['status' => 'Closed Today', 'color' => 'bg-red-500', 'text_color' => 'text-red-400'];
        }
    }
    
    // Check current time against schedule
    $openTime = $garage['opening_time'];
    $closeTime = $garage['closing_time'];
    
    if ($openTime && $closeTime) {
        if ($closeTime < $openTime) {
            // Overnight operation (e.g., 22:00 to 06:00)
            if ($currentTime >= $openTime || $currentTime <= $closeTime) {
                return ['status' => 'Open', 'color' => 'bg-green-500', 'text_color' => 'text-green-400'];
            } else {
                return ['status' => 'Closed', 'color' => 'bg-red-500', 'text_color' => 'text-red-400'];
            }
        } else {
            // Normal day operation
            if ($currentTime >= $openTime && $currentTime <= $closeTime) {
                return ['status' => 'Open', 'color' => 'bg-green-500', 'text_color' => 'text-green-400'];
            } else {
                return ['status' => 'Closed', 'color' => 'bg-red-500', 'text_color' => 'text-red-400'];
            }
        }
    }
    
    // Default to open if no schedule info
    return ['status' => 'Open', 'color' => 'bg-green-500', 'text_color' => 'text-green-400'];
}

// Function to format operating hours
function formatOperatingHours($garage) {
    if ($garage['is_24_7']) return '24/7';
    if (!$garage['opening_time'] || !$garage['closing_time']) return 'N/A';
    
    $openTime = date('g:i A', strtotime($garage['opening_time']));
    $closeTime = date('g:i A', strtotime($garage['closing_time']));
    return $openTime . ' - ' . $closeTime;
}

if ($featuredResult && $featuredResult->num_rows > 0) {
  while ($parking = $featuredResult->fetch_assoc()) {
    // Set default values for display
    $name = $parking['Parking_Space_Name'];
    $address = $parking['Parking_Lot_Address'];
    $type = $parking['Parking_Type'];
    $dimensions = $parking['Parking_Space_Dimensions'];
    $spaces = $parking['Parking_Capacity'];
    $available = $parking['Availability'];
    $price = $parking['PriceperHour'];
    
    // Get garage status and timing
    $garageStatus = getGarageStatus($parking);
    $operatingHours = formatOperatingHours($parking);
    
    // Get real rating from database
    $rating = floatval($parking['average_rating']);
    $totalRatings = intval($parking['total_ratings']);
    
    // UPDATED FILTERING LOGIC - Skip ALL closed garages
    if (in_array($garageStatus['status'], ['Closed', 'Manually Closed', 'Maintenance', 'Emergency Closed', 'Closed Today'])) {
        continue; // Skip this garage if it's closed in any way
    }
    
    // Format rating display
    if ($rating > 0) {
      $ratingDisplay = number_format($rating, 1);
      $ratingText = "({$totalRatings} review" . ($totalRatings != 1 ? "s" : "") . ")";
    } else {
      $ratingDisplay = "New";
      $ratingText = "(No reviews yet)";
    }

    // Determine availability status
    $statusColor = "bg-success";
    $statusText = "Available";

    if ($available <= 0) {
      $statusColor = "bg-error";
      $statusText = "Full";
    } else if ($available < $spaces / 2) {
      $statusColor = "bg-warning";
      $statusText = "Limited";
    }

    // Format price
    $priceFormatted = number_format($price, 2);

    // Output parking card - Rest of your card HTML remains the same
    echo '
<div class="parking-card flex-shrink-0 w-[calc(33.333%-16px)] card bg-black/50 backdrop-blur-md border border-white/20 shadow-xl overflow-hidden transition-all hover:-translate-y-1 hover:shadow-2xl">
    <figure class="h-48 overflow-hidden">
        <img src="https://placehold.co/600x400" alt="' . htmlspecialchars($name) . '" class="w-full h-full object-cover transition-transform hover:scale-110" />
    </figure>
    <div class="card-body">
        <!-- Header with name and status badges on the right -->
        <div class="flex justify-between items-start mb-3">
            <div class="flex-1">
                <h3 class="card-title text-white mb-2">' . htmlspecialchars($name) . '</h3>
                <div class="flex items-center gap-2 text-white/90 text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                    ' . htmlspecialchars($address) . '
                </div>
            </div>
            <!-- Status Badges on the right -->
            <div class="flex gap-2 ml-3">
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-500/20 text-green-400 border border-green-500/30">
                    ● ' . $garageStatus['status'] . '
                </span>
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-500/20 text-blue-400 border border-blue-500/30">
                    ' . $operatingHours . '
                </span>
            </div>
        </div>
        
        <div class="text-xs text-white/60 mb-3">
            ' . ucfirst($type) . ' • ' . $dimensions . ' size
        </div>
        
        <!-- Stats Section -->
        <div class="flex justify-between mb-4">
            <div class="flex flex-col items-center">
                <span class="text-white font-semibold">' . htmlspecialchars($spaces) . '</span>
                <span class="text-white/70 text-xs">Spaces</span>
            </div>
            <div class="flex flex-col items-center">
                <div class="flex items-center gap-1">
                    ' . generateStarRating($rating) . '
                    <span class="text-white font-semibold ml-1">' . $ratingDisplay . '</span>
                </div>
                <span class="text-white/70 text-xs">' . $ratingText . '</span>
            </div>
            <div class="flex flex-col items-center">
                <span class="text-primary font-semibold">৳' . $priceFormatted . '</span>
                <span class="text-white/70 text-xs">per hour</span>
            </div>
        </div>
        
        <!-- Availability Status -->
        <div class="flex justify-between items-center mb-3">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 ' . $statusColor . ' rounded-full"></span>
                <span class="text-white/90 text-sm">' . $statusText . ' (' . $available . ' spots)</span>
            </div>
        </div>
        
        <!-- Buttons Section -->
        <div class="space-y-2">
            <!-- Reviews Button -->
            <button onclick="showReviews(\'' . $parking['garage_id'] . '\')" class="btn btn-outline btn-sm text-white border-white/30 hover:bg-white/10 w-full">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                </svg>
                View Reviews (' . $totalRatings . ')
            </button>
            
            <!-- Main Action Button -->
            ' . ($available <= 0 ? '
            <a href="javascript:void(0)" class="btn bg-error text-white w-full font-semibold transition-all duration-300" disabled>Fully Booked</a>
            <button onclick="bookForLater(\'' . $parking['garage_id'] . '\')" class="btn bg-blue-600 hover:bg-blue-700 text-white w-full font-semibold transition-all duration-300">Book For Later</button>
            ' : '
            <a href="javascript:void(0)" onclick="openBookingModal(\'' . $parking['garage_id'] . '\')" class="btn bg-primary hover:bg-orange-600 text-white w-full font-semibold transition-all duration-300">Book Now</a>
            ') . '
        </div>
    </div>
</div>
';
  }
} else {
  // Display a message if no parking locations found
  echo '
        <div class="w-full text-center py-16">
            <p class="text-white/90 text-lg">No open parking locations available at the moment.</p>
        </div>
        ';
}
// Add this function before the closing PHP tag to generate star ratings
function generateStarRating($rating) {
    $stars = '';
    $fullStars = floor($rating);
    $hasHalfStar = ($rating - $fullStars) >= 0.5;
    
    // Full stars
    for ($i = 0; $i < $fullStars; $i++) {
        $stars .= '<svg class="w-4 h-4 text-yellow-400 fill-current" viewBox="0 0 20 20">
            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
        </svg>';
    }
    
    // Half star
    if ($hasHalfStar) {
        $stars .= '<svg class="w-4 h-4 text-yellow-400" viewBox="0 0 20 20">
            <defs>
                <linearGradient id="half-fill">
                    <stop offset="50%" stop-color="currentColor"/>
                    <stop offset="50%" stop-color="transparent"/>
                </linearGradient>
            </defs>
            <path fill="url(#half-fill)" d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
        </svg>';
        $fullStars++;
    }
    
    // Empty stars
    for ($i = $fullStars; $i < 5; $i++) {
        $stars .= '<svg class="w-4 h-4 text-gray-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1">
            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
        </svg>';
    }
    
    return $stars;
}

            ?>
          </div>
        </div>

        <!-- Right Arrow -->
        <button id="nextParking"
          class="absolute right-[-40px] top-1/2 -translate-y-1/2 bg-black/80 hover:bg-primary text-white rounded-full p-3 z-10 transition-all duration-300 hover:scale-110 focus:outline-none shadow-lg">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </button>

        <!-- Pagination Dots -->
        <div class="flex justify-center gap-2 mt-6">
          <span id="dot-0" class="pagination-dot active w-3 h-3 rounded-full bg-primary cursor-pointer"
            onclick="scrollToIndex(0)"></span>
          <span id="dot-1" class="pagination-dot w-3 h-3 rounded-full bg-white/30 cursor-pointer"
            onclick="scrollToIndex(3)"></span>
          <span id="dot-2" class="pagination-dot w-3 h-3 rounded-full bg-white/30 cursor-pointer"
            onclick="scrollToIndex(6)"></span>
        </div>
      </div>
    </section>

    <!-- How It Works -->
    <section id="how-it-works"
      class="bg-black/50 backdrop-blur-md rounded-xl p-8 mb-16 border border-white/20 shadow-xl">
      <h2 class="text-3xl font-bold text-white mb-8 drop-shadow-md">How It Works</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
        <!-- Step 1 -->
        <div class="flex flex-col items-center text-center">
          <div
            class="w-16 h-16 bg-primary/20 rounded-full flex justify-center items-center mb-4 border-2 border-primary">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-primary" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="12" y1="8" x2="12" y2="16"></line>
              <line x1="8" y1="12" x2="16" y2="12"></line>
            </svg>
          </div>
          <h3 class="text-xl font-semibold text-white mb-2 drop-shadow-md">Search</h3>
          <p class="text-white/90 text-sm">Enter your destination, select date and time, and find available parking
            spots near your location.</p>
        </div>

        <!-- Step 2 -->
        <div class="flex flex-col items-center text-center">
          <div
            class="w-16 h-16 bg-primary/20 rounded-full flex justify-center items-center mb-4 border-2 border-primary">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-primary" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
              <line x1="16" y1="2" x2="16" y2="6"></line>
              <line x1="8" y1="2" x2="8" y2="6"></line>
              <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
          </div>
          <h3 class="text-xl font-semibold text-white mb-2 drop-shadow-md">Reserve</h3>
          <p class="text-white/90 text-sm">Compare prices, view details, and reserve your parking spot in advance with
            just a few clicks.</p>
        </div>

        <!-- Step 3 -->
        <div class="flex flex-col items-center text-center">
          <div
            class="w-16 h-16 bg-primary/20 rounded-full flex justify-center items-center mb-4 border-2 border-primary">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-primary" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
              <line x1="16" y1="13" x2="8" y2="13"></line>
              <line x1="16" y1="17" x2="8" y2="17"></line>
              <polyline points="10 9 9 9 8 9"></polyline>
            </svg>
          </div>
          <h3 class="text-xl font-semibold text-white mb-2 drop-shadow-md">Confirm</h3>
          <p class="text-white/90 text-sm">Receive your parking confirmation and instructions via email or in the app
            for easy access.</p>
        </div>

        <!-- Step 4 -->
        <div class="flex flex-col items-center text-center">
          <div
            class="w-16 h-16 bg-primary/20 rounded-full flex justify-center items-center mb-4 border-2 border-primary">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-primary" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
              <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
          </div>
          <h3 class="text-xl font-semibold text-white mb-2 drop-shadow-md">Park</h3>
          <p class="text-white/90 text-sm">Arrive at the parking location, show your confirmation, and enjoy stress-free
            parking at your destination.</p>
        </div>
      </div>
    </section>
  </main>

  <!-- Footer -->
  <footer id="contact" class="footer-glow border-t border-white/20 pt-16 pb-8">
    <div class="container mx-auto px-4">
      
      <!-- Main Footer Content -->
      <div class="text-center mb-12">
        <!-- Contact Information Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 max-w-4xl mx-auto mb-10">
          
          <!-- Address Card -->
          <div class="contact-card bg-black/80 rounded-xl p-6 border border-primary/30 shadow-lg">
            <div class="w-12 h-12 bg-primary rounded-full flex items-center justify-center mx-auto mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-black" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                <circle cx="12" cy="10" r="3"></circle>
              </svg>
            </div>
            <h4 class="text-white font-semibold mb-2">Visit Us</h4>
            <p class="text-white/80 text-sm leading-relaxed">
              651, Ibrahimpur <br>
              Mirpur 14 , Dhaka 1206
            </p>
          </div>

          <!-- Phone Card -->
          <div class="contact-card bg-black/80 rounded-xl p-6 border border-primary/30 shadow-lg">
            <div class="w-12 h-12 bg-black/80 rounded-full flex items-center justify-center mx-auto mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
              </svg>
            </div>
            <h4 class="text-white font-semibold mb-2">Call Us</h4>
            <p class="text-white/80 text-sm">
              <a href="tel:+1234567890" class="hover:text-black/60 transition-colors font-medium">
                01874126156
              </a>
            </p>
          </div>

          <!-- Email Card -->
          <div class="contact-card bg-black/80 rounded-xl p-6 border border-primary/30 shadow-lg">
            <div class="w-12 h-12 bg-primary rounded-full flex items-center justify-center mx-auto mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-black" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                <polyline points="22,6 12,13 2,6"></polyline>
              </svg>
            </div>
            <h4 class="text-white font-semibold mb-2">Email Us</h4>
            <p class="text-white/80 text-sm">
              <a href="mailto:support@carparkingsystem.com" class="hover:text-primary transition-colors">
                support@parkinglagbe.com
              </a>
            </p>
          </div>
        </div>

        <!-- Social Media Links -->
        <div class="mb-8">
          <h4 class="text-white font-semibold mb-6">Follow Us</h4>
          <div class="flex justify-center gap-4">
            
            <!-- Facebook -->
            <a href="#" class="social-icon w-12 h-12 bg-white/10 rounded-full flex justify-center items-center group">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white group-hover:text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path>
              </svg>
            </a>

            <!-- Twitter -->
            <a href="#" class="social-icon w-12 h-12 bg-white/10 rounded-full flex justify-center items-center group">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white group-hover:text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"></path>
              </svg>
            </a>

            <!-- Instagram -->
            <a href="#" class="social-icon w-12 h-12 bg-white/10 rounded-full flex justify-center items-center group">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white group-hover:text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect>
                <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
                <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line>
              </svg>
            </a>

            <!-- LinkedIn -->
            <a href="#" class="social-icon w-12 h-12 bg-white/10 rounded-full flex justify-center items-center group">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white group-hover:text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"></path>
                <rect x="2" y="9" width="4" height="12"></rect>
                <circle cx="4" cy="4" r="2"></circle>
              </svg>
            </a>
          </div>
        </div>
      </div>

      <!-- Bottom Bar -->
      <div class="border-t border-white/10 pt-6">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
          <!-- Copyright -->
          <p class="text-white/70 text-sm">
            &copy; 2025 Car Parking System. All rights reserved.
          </p>
          
          <!-- Legal Links -->
          <div class="flex gap-6">
            <a href="#" class="text-white/70 text-sm hover:text-primary transition-colors">Privacy Policy</a>
            <a href="#" class="text-white/70 text-sm hover:text-primary transition-colors">Terms of Service</a>
            <a href="#" class="text-white/70 text-sm hover:text-primary transition-colors">Cookie Policy</a>
            <a href="#" class="text-white/70 text-sm hover:text-primary transition-colors">Sitemap</a>
          </div>
        </div>
      </div>
    </div>
  </footer>

  <!-- Leaflet CSS for maps -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />

  <!-- Leaflet Geocoder CSS -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />

  <!-- Leaflet JS -->
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

  <!-- Leaflet Geocoder JS -->
  <script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>

  <script>
    // একটি সরল, আলাদা স্ক্রিপ্ট যা ম্যাপ সঠিকভাবে প্রদর্শন করবে
    document.addEventListener('DOMContentLoaded', function () {
      try {
        console.log("Document loaded, initializing map...");

        // ম্যাপ এলিমেন্ট চেক করুন
        const mapContainer = document.getElementById('map');
        if (!mapContainer) {
          console.error("Error: Map container div with id='map' not found!");
          return;
        }

        // ম্যাপ কন্টেইনার স্টাইল নিশ্চিত করুন
        mapContainer.style.width = '100%';
        mapContainer.style.height = '500px';
        mapContainer.style.display = 'block';

        // Leaflet লাইব্রেরি চেক করুন
        if (typeof L === 'undefined') {
          console.error("Error: Leaflet library not loaded! Check script tags.");
          return;
        }

        console.log("Creating map...");

        // ম্যাপ ইনিশিয়ালাইজ করুন
        const map = L.map('map').setView([23.7985, 90.3867], 13); // ঢাকা সেন্টার
        // Make map globally accessible for updateAvailabilityUI function
        window.parkingMap = map;

        // টাইল লেয়ার যোগ করুন
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
          maxZoom: 19
        }).addTo(map);

        console.log("Map created with center at: 23.7985, 90.3867");

        // গ্যারেজ আইকন তৈরি করুন
        const garageIcon = L.divIcon({
          html: `
                <div style="
                    background-color: #f39c12;
                    color: white;
                    border-radius: 50%;
                    width: 30px;
                    height: 30px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-weight: bold;
                    border: 2px solid white;
                    box-shadow: 0 0 10px rgba(0,0,0,0.3);
                ">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <path d="M9 18V6h4.5a2.5 2.5 0 0 1 0 5H9"></path>
                    </svg>
                </div>
            `,
          className: "",
          iconSize: [30, 30],
          iconAnchor: [15, 15],
          popupAnchor: [0, -15]
        });

        // গ্যারেজ লোকেশন ডাটা থেকে নিন
        const dbGarageLocations = <?php echo json_encode($garageLocations); ?>;
        console.log("Garage locations loaded:", dbGarageLocations);

        // গ্যারেজ ডাটা যাচাই করুন ও লোড করুন
        if (Array.isArray(dbGarageLocations) && dbGarageLocations.length > 0) {
          // গ্যারেজ লোকেশন ম্যাপে যোগ করুন
          dbGarageLocations.forEach(function (garage) {
            try {
            
              // নিশ্চিত করুন কোঅর্ডিনেট সঠিক নাম্বার
              const lat = parseFloat(garage.lat);
              const lng = parseFloat(garage.lng);

              if (isNaN(lat) || isNaN(lng)) {
                console.warn("Invalid coordinates for garage:", garage);
                return;
              }

              // মার্কার যোগ করুন
              const marker = L.marker([lat, lng], { icon: garageIcon }).addTo(map);

              // অবস্থা নির্ধারণ করুন
              let statusColor = 'bg-success';
              let statusText = `Available (${garage.available} spots)`;

              if (garage.available <= 0) {
                statusColor = 'bg-error';
                statusText = 'Full (0 spots)';
              } else if (garage.available < garage.capacity / 2) {
                statusColor = 'bg-warning';
                statusText = `Limited (${garage.available} spots)`;
              }

              // মূল্য ফরম্যাট করুন
              const price = garage.price ? `$${parseFloat(garage.price).toFixed(2)}/hr` : '$5.00/hr';

              // Check if this garage is already booked by the user
              // Check if this garage is already booked by the user
              checkBookingStatus(garage.id, function (isBooked, bookingData) {
                // Add timer HTML if this is a booked garage
                let timerHTML = '';

                if (isBooked && bookingData) {
                  // Current time in seconds
                  const now = Math.floor(Date.now() / 1000);

                  // Booking details from returned data
                  const bookingDate = bookingData.booking_date;
                  const bookingTime = bookingData.booking_time;
                  const duration = parseInt(bookingData.duration);
                  const status = bookingData.status;

                  // Calculate booking timestamps
                  const startDateTime = new Date(`${bookingDate}T${bookingTime}`).getTime() / 1000;
                  const endDateTime = startDateTime + (duration * 3600);

                  if (status === 'upcoming') {
                    const timeToStart = startDateTime - now;

                    if (timeToStart > 0) {
                      timerHTML = `
                    <div class="timer-container timer-upcoming bg-blue-500/20 text-blue-200 p-2 rounded-md flex items-center justify-center gap-2 text-sm my-2" data-timestamp="${startDateTime}" data-type="upcoming">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span>Starts in: <span class="font-semibold countdown-value">calculating...</span></span>
                    </div>
                `;
                    }
                  } else if (status === 'active') {
                    const timeToEnd = endDateTime - now;

                    if (timeToEnd > 0) {
                      timerHTML = `
                    <div class="timer-container timer-active timer-pulse bg-green-500/20 text-green-200 p-2 rounded-md flex items-center justify-center gap-2 text-sm my-2" data-timestamp="${endDateTime}" data-type="active">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span>Ends in: <span class="font-semibold countdown-value">calculating...</span></span>
                    </div>
                `;
                    }
                  }
                }

                // Button style and text based on booking status
                let buttonClass = "bg-primary hover:bg-orange-600";
                let buttonText = "Book Now";
                let buttonDisabled = "";

                if (isBooked) {
                  buttonClass = "bg-success hover:bg-success"; // Green success color for booked
                  buttonText = "Booked";
                  buttonDisabled = "disabled";

                } else if (garage.available <= 0) {
                  buttonClass = "bg-error"; // Red error color for fully booked
                  buttonText = "Fully Booked";
                  buttonDisabled = "disabled";
                }

                // Existing code:
                const popupContent = `
        <div class="p-2" style="min-width: 220px;">
            <h3 class="font-bold text-lg">${garage.name}</h3>
            <p class="text-sm mb-2">${garage.address}</p>
            <div class="flex justify-between mb-2">
                <span class="text-sm">Type: ${garage.type || 'Standard'}</span>
                <span class="text-sm">Size: ${garage.dimensions || 'Standard'}</span>
            </div>
            <div class="flex justify-between items-center mb-2">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full ${garage.available <= 0 ? 'bg-error' : garage.available < garage.capacity / 2 ? 'bg-warning' : 'bg-success'}"></span>
                    <span class="text-sm">${garage.available <= 0 ? 'Full' : garage.available < garage.capacity / 2 ? 'Limited' : 'Available'} (${garage.available} spots)</span>
                </div>
                <div class="text-primary font-semibold">${price}</div>
            </div>
            ${timerHTML}
            <button onclick="openBookingModal('${garage.id}')" class="btn ${buttonClass} text-white w-full mt-1 font-semibold transition-all duration-300" ${buttonDisabled}>${buttonText}</button>
        </div>
    `;

                // পপআপ যোগ করুন
                marker.bindPopup(popupContent);
                // পপআপ যোগ করুন
                marker.bindPopup(popupContent);

                // Add this new code here
                // Fully booked spots: fetch and show next availability time
                // Fully booked spots: fetch and show next availability time
                if (parseInt(garage.available) <= 0) {
                  // Fetch next availability time
                  fetch(`get_next_availability.php?garage_id=${garage.id}`)
                    .then(response => response.json())
                    .then(data => {
                      if (data.success && !data.has_own_booking) {
                        // Only show availability timer if user hasn't booked this spot
                        const popupEl = marker.getPopup();
                        const currentContent = popupEl.getContent();

                        // Create availability timer HTML
                        const now = Math.floor(Date.now() / 1000);
                        const nextAvailableTime = data.next_available.timestamp;
                        const timeUntilAvailable = nextAvailableTime - now;

                        // Store next availability data with the garage object
                        garage.nextAvailable = data.next_available;

                        if (timeUntilAvailable > 0) {
                          // Create temporary container to manipulate HTML
                          const container = document.createElement('div');
                          container.innerHTML = currentContent;

                          // Find the "Fully Booked" button
                          const buttonElement = container.querySelector('button');

                          if (buttonElement) {
                            // Create availability timer HTML
                            const availabilityTimerHTML = `
                            <div class="timer-container timer-availability bg-blue-500/20 text-blue-800 p-2 rounded-md flex items-center justify-center gap-2 text-sm my-2" data-timestamp="${nextAvailableTime}" data-type="available-soon">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                <span>Available in: <span class="font-semibold countdown-value">calculating...</span></span>
                            </div>
                        `;

                            // Add "Book For Later" button after the disabled "Fully Booked" button
                            const bookForLaterButton = `
                            <button onclick="bookForLater('${garage.id}')" class="btn bg-blue-600 hover:bg-blue-700 text-white w-full mt-2 font-semibold transition-all duration-300">Book For Later</button>
                        `;

                            // Add both components to the popup
                            buttonElement.insertAdjacentHTML('beforebegin', availabilityTimerHTML);
                            buttonElement.insertAdjacentHTML('afterend', bookForLaterButton);

                            // Set the updated content
                            popupEl.setContent(container.innerHTML);

                            // Update timer if popup is open
                            if (marker.isPopupOpen()) {
                              const timerContainer = marker.getPopup()._contentNode.querySelector('.timer-container[data-type="available-soon"]');
                              if (timerContainer) {
                                updateTimer(timerContainer);
                              }
                            }
                          }
                        }
                      } else {
                        console.log("Not showing availability timer: User has own booking or no availability data");
                      }
                    })
                    .catch(error => console.error('Error fetching next availability:', error));
                }
              });

            } catch (error) {
              console.error("Error adding garage marker:", error);
            }
          });

          console.log("Added all garage locations to map");
        } else {
          console.warn("No garage locations found or invalid data");

          // টেস্ট মার্কার যোগ করুন যাতে ম্যাপ সঠিকভাবে লোড হয়েছে কিনা দেখা যায়
          L.marker([23.7985, 90.3867]).addTo(map)
            .bindPopup('ঢাকা শহর')
            .openPopup();
        }

        // লোকেট মি বাটন সেটআপ
        const locateMeBtn = document.getElementById('locateMe');
        if (locateMeBtn) {
          locateMeBtn.addEventListener('click', function () {
            console.log("Locate me button clicked");

            if (!navigator.geolocation) {
              console.error("Geolocation not supported by this browser");
              return;
            }

            // লোকেশন সন্ধান করুন
            navigator.geolocation.getCurrentPosition(
              function (position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                const accuracy = position.coords.accuracy;

                console.log(`Location found: ${lat}, ${lng} (±${accuracy}m)`);

                // ম্যাপে লোকেশনে যান
                map.flyTo([lat, lng], 15);

                // মার্কার যোগ করুন
                L.marker([lat, lng], {
                  icon: L.icon({
                    iconUrl: 'https://cdn-icons-png.flaticon.com/512/684/684908.png',
                    iconSize: [30, 30],
                    iconAnchor: [15, 30],
                    popupAnchor: [0, -30]
                  })
                }).addTo(map).bindPopup("আপনি এখানে আছেন").openPopup();

                // লোকেশন স্ট্যাটাস আপডেট করুন যদি UI এলিমেন্ট থাকে
                const statusDiv = document.getElementById('locationStatus');
                const messageSpan = document.getElementById('locationMessage');
                const accuracySpan = document.getElementById('locationAccuracy');

                if (statusDiv && messageSpan && accuracySpan) {
                  statusDiv.classList.remove('hidden');
                  messageSpan.innerText = 'You are here!';
                  accuracySpan.innerText = `Accuracy: ±${Math.round(accuracy)} meters`;
                }
              },
              function (error) {
                // শুধু কনসোলে এরর লগ করুন
                console.error("Geolocation error:", error);
              },
              { enableHighAccuracy: true, timeout: 5000, maximumAge: 0 }
            );
          });

          console.log("Locate me button handler setup complete");
        }

        // কাস্টম সার্চ বক্স
        const searchBtn = document.getElementById('searchButton');
        const searchBox = document.getElementById('customSearchBox');
        const searchInput = document.getElementById('searchInput');
        const searchSubmit = document.getElementById('searchSubmit');

        if (searchBtn && searchBox && searchInput && searchSubmit) {
          // সার্চ বাটন ক্লিক
          searchBtn.addEventListener('click', function () {
            console.log("Search button clicked");
            searchBox.classList.toggle('hidden');
            searchInput.focus();
          });

          // সার্চ সাবমিট
          searchSubmit.addEventListener('click', function () {
            const query = searchInput.value.trim();
            if (!query) return;

            console.log("Searching for:", query);

            // সার্চ ফাংশন কল
            performSearch(query, map);
            searchBox.classList.add('hidden');
          });

          // বাইরে ক্লিক করলে সার্চ বক্স বন্ধ করুন
          document.addEventListener('click', function (e) {
            if (!searchBox.contains(e.target) && e.target !== searchBtn) {
              searchBox.classList.add('hidden');
            }
          });

          // সার্চ ইনপুটে এন্টার কী প্রেস
          searchInput.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
              e.preventDefault();
              searchSubmit.click();
            }
          });

          console.log("Search functionality setup complete");
        }

        // মূল সার্চ ফর্ম এবং ডিটেক্ট করা সার্চ এলিমেন্টগুলি

        // ছবিতে দেখানো সার্চবক্স
        const searchElements = document.querySelectorAll('input[placeholder="mirpur"], input[placeholder="Enter location:"]');
        searchElements.forEach(function (input) {
          if (input) {
            const searchContainer = input.closest('div, form');
            const button = searchContainer?.querySelector('button');

            if (button) {
              console.log("Found search input:", input);

              // বাটনে ক্লিক ইভেন্ট লিসেনার
              button.addEventListener('click', function (e) {
                e.preventDefault();

                const query = input.value.trim();
                if (!query) return;

                console.log("Search triggered for:", query);
                performSearch(query, map);
              });

              // ইনপুটে এন্টার কী প্রেস
              input.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  button.click();
                }
              });
            }
          }
        });

        // মূল সার্চ ফর্ম
        const mainForm = document.getElementById('searchForm');
        if (mainForm) {
          const mainInput = mainForm.querySelector('#locationInput');

          if (mainInput) {
            console.log("Found main search form");

            mainForm.addEventListener('submit', function (e) {
              e.preventDefault();

              const query = mainInput.value.trim();
              if (!query) return;

              console.log("Main form search for:", query);
              performSearch(query, map);
            });
          }
        }

        // গ্লোবাল সার্চ ফাংশন
        
// Enhanced performSearch function - Bangladesh only with garage focus
// COMPLETE REPLACEMENT - Enhanced performSearch function - Bangladesh only with garage focus
function performSearch(query, map) {
  console.log("performSearch called with:", query);
  
  if (!query || !map) {
    console.error("Missing query or map:", {query, map});
    return;
  }

  // Clear previous search markers
  if (window.searchMarkers) {
    window.searchMarkers.forEach(marker => map.removeLayer(marker));
    window.searchMarkers = [];
  } else {
    window.searchMarkers = [];
  }

  // Show loading notification
  showCustomNotification("🔍 Searching in Bangladesh...", "loading");

  // First search in existing garages
  searchInExistingGarages(query, map)
    .then(garageFound => {
      if (!garageFound) {
        // If no garage found, search Bangladesh locations (NO POPUPS)
        return searchBangladeshLocationsClean(query, map);
      }
    })
    .catch(error => {
      console.error("Search error:", error);
      showCustomNotification("❌ Search failed. Please try again.", "error");
    });
}

// Function to search in existing garage locations
function searchInExistingGarages(query, map) {
  return new Promise((resolve) => {
    // Assuming you have garage data in dbGarageLocations or similar
    if (typeof dbGarageLocations === 'undefined') {
      resolve(false);
      return;
    }

    const searchTerm = query.toLowerCase().trim();
    const matchingGarages = [];

    // Search in garage names, addresses, and areas
    dbGarageLocations.forEach(garage => {
      const garageName = (garage.name || '').toLowerCase();
      const garageAddress = (garage.address || '').toLowerCase();
      const garageArea = (garage.area || '').toLowerCase();
      
      if (garageName.includes(searchTerm) || 
          garageAddress.includes(searchTerm) || 
          garageArea.includes(searchTerm)) {
        matchingGarages.push(garage);
      }
    });

    if (matchingGarages.length > 0) {
      // Show matching garages
      showMatchingGarages(matchingGarages, map);
      showCustomNotification(`🅿️ Found ${matchingGarages.length} parking garage(s)`, "success");
      resolve(true);
    } else {
      resolve(false);
    }
  });
}

// Function to display matching garages with special highlighting
function showMatchingGarages(garages, map) {
  if (garages.length === 1) {
    // Single garage - focus closely
    const garage = garages[0];
    map.setView([parseFloat(garage.lat), parseFloat(garage.lng)], 17);
    
    // Find and highlight the garage marker
    map.eachLayer(layer => {
      if (layer.getLatLng && layer.getPopup) {
        const pos = layer.getLatLng();
        const latDiff = Math.abs(pos.lat - parseFloat(garage.lat));
        const lngDiff = Math.abs(pos.lng - parseFloat(garage.lng));
        
        if (latDiff < 0.0001 && lngDiff < 0.0001) {
          // Add pulsing effect to the marker
          addPulsingEffect(layer);
          layer.openPopup();
        }
      }
    });
  } else {
    // Multiple garages - show all in view
    const bounds = L.latLngBounds();
    garages.forEach(garage => {
      bounds.extend([parseFloat(garage.lat), parseFloat(garage.lng)]);
    });
    
    map.fitBounds(bounds, { padding: [50, 50] });
    
    // Highlight all matching garages
    garages.forEach(garage => {
      map.eachLayer(layer => {
        if (layer.getLatLng && layer.getPopup) {
          const pos = layer.getLatLng();
          const latDiff = Math.abs(pos.lat - parseFloat(garage.lat));
          const lngDiff = Math.abs(pos.lng - parseFloat(garage.lng));
          
          if (latDiff < 0.0001 && lngDiff < 0.0001) {
            addPulsingEffect(layer);
          }
        }
      });
    });
  }
}

// Function to add pulsing effect to markers
function addPulsingEffect(marker) {
  // Add CSS for pulsing effect if not already added
  if (!document.getElementById('pulsing-marker-style')) {
    const style = document.createElement('style');
    style.id = 'pulsing-marker-style';
    style.textContent = `
      @keyframes pulse {
        0% { box-shadow: 0 0 0 0 rgba(243, 156, 18, 0.7); }
        70% { box-shadow: 0 0 0 20px rgba(243, 156, 18, 0); }
        100% { box-shadow: 0 0 0 0 rgba(243, 156, 18, 0); }
      }
      .pulsing-marker {
        animation: pulse 2s infinite;
        border-radius: 50%;
      }
    `;
    document.head.appendChild(style);
  }
  
  // Add pulsing class to marker
  if (marker._icon) {
    marker._icon.classList.add('pulsing-marker');
    
    // Remove pulsing after 10 seconds
    setTimeout(() => {
      if (marker._icon) {
        marker._icon.classList.remove('pulsing-marker');
      }
    }, 10000);
  }
}

// CLEAN VERSION - No popups for locations without garages
function searchBangladeshLocationsClean(query, map) {
  return new Promise((resolve, reject) => {
    // Bangladesh bounding box coordinates
    const bangladeshBounds = {
      north: 26.6382,
      south: 20.7488,
      east: 92.6733,
      west: 88.0075
    };
    
    // Construct search query with Bangladesh constraint
    const searchUrl = `https://nominatim.openstreetmap.org/search?` +
      `format=json` +
      `&q=${encodeURIComponent(query + ', Bangladesh')}` +
      `&limit=5` +
      `&bounded=1` +
      `&viewbox=${bangladeshBounds.west},${bangladeshBounds.north},${bangladeshBounds.east},${bangladeshBounds.south}` +
      `&countrycodes=bd`;

    fetch(searchUrl)
      .then(response => {
        if (!response.ok) throw new Error('Network error');
        return response.json();
      })
      .then(results => {
        if (results && results.length > 0) {
          // Filter results to ensure they're in Bangladesh
          const bangladeshResults = results.filter(result => {
            const lat = parseFloat(result.lat);
            const lon = parseFloat(result.lon);
            return lat >= bangladeshBounds.south && lat <= bangladeshBounds.north &&
                   lon >= bangladeshBounds.west && lon <= bangladeshBounds.east;
          });
          
          if (bangladeshResults.length > 0) {
            const firstResult = bangladeshResults[0];
            const lat = parseFloat(firstResult.lat);
            const lon = parseFloat(firstResult.lon);
            const locationName = firstResult.display_name.split(',')[0];
            
            // Check for nearby garages
            const nearbyGarages = checkNearbyGaragesStrict(lat, lon);
            
            if (nearbyGarages.count > 0) {
              // Show garages if found within 5km
              showNearbyGaragesOnMap(nearbyGarages.garages, map);
              showCustomNotification(`🅿️ Found ${nearbyGarages.count} parking garage(s) near ${locationName}`, "success");
            } else {
              // NO POPUP - Just focus on location and show notification
              map.setView([lat, lon], 13);
              showCustomNotification(`📍 Location "${locationName}" found but no parking garages available in this area. All garages are in Dhaka metro area.`, "warning");
            }
          } else {
            showCustomNotification("❌ No locations found in Bangladesh", "error");
          }
        } else {
          showCustomNotification("❌ No locations found in Bangladesh", "error");
        }
        resolve();
      })
      .catch(error => {
        console.error("Bangladesh search error:", error);
        showCustomNotification("❌ Search failed. Please try again.", "error");
        reject(error);
      });
  });
}

// Helper function - strict garage checking
function checkNearbyGaragesStrict(lat, lon) {
  if (typeof dbGarageLocations === 'undefined') {
    return { count: 0, garages: [] };
  }
  
  const garagesWithDistance = dbGarageLocations.map(garage => {
    const distance = calculateDistance(lat, lon, parseFloat(garage.lat), parseFloat(garage.lng));
    return { ...garage, distance };
  });
  
  // Only within 5km
  const nearbyGarages = garagesWithDistance.filter(garage => garage.distance <= 5);
  
  return {
    count: nearbyGarages.length,
    garages: nearbyGarages.sort((a, b) => a.distance - b.distance)
  };
}

// Show nearby garages on map (for areas that actually have garages)
function showNearbyGaragesOnMap(garages, map) {
  showMatchingGarages(garages, map);
}

// Helper function to calculate distance (if not already exists)
function calculateDistance(lat1, lon1, lat2, lon2) {
  const R = 6371; // Radius of Earth in kilometers
  const dLat = (lat2 - lat1) * Math.PI / 180;
  const dLon = (lon2 - lon1) * Math.PI / 180;
  const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLon / 2) * Math.sin(dLon / 2);
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  return R * c;
}

// Helper function to format distance (if not already exists)
function formatDistance(distanceKm) {
  if (distanceKm < 1) {
    return Math.round(distanceKm * 1000) + 'm';
  } else {
    return distanceKm.toFixed(1) + 'km';
  }
}
        // ডিফল্ট সার্চ ফাংশন (কোনো লাইব্রেরি ছাড়া)
        function defaultSearch(query, map) {
          console.log("Using default search for:", query);

          // ঢাকার কয়েকটি এলাকার সাধারণ কোঅর্ডিনেট
          const commonLocations = {
            'mirpur': [23.8223, 90.3654],
            'gulshan': [23.7931, 90.4143],
            'dhanmondi': [23.7461, 90.3742],
            'uttara': [23.8759, 90.3795],
            'mohammadpur': [23.7578, 90.3603],
            'bashundhara': [23.8218, 90.4364],
            'banani': [23.7937, 90.4037],
            'motijheel': [23.7328, 90.4185]
          };

          // লোয়ারকেস কোয়েরি
          const lowerQuery = query.toLowerCase();

          // কমন লোকেশনগুলিতে ম্যাচ আছে কিনা চেক করুন
          for (const [location, coordinates] of Object.entries(commonLocations)) {
            if (lowerQuery.includes(location)) {
              map.flyTo(coordinates, 15);
              L.marker(coordinates).addTo(map)
                .bindPopup(location.charAt(0).toUpperCase() + location.slice(1))
                .openPopup();
              return;
            }
          }

          // কোনও ম্যাচ না পাওয়া গেলে ডিফল্ট লোকেশন
          map.flyTo([23.7985, 90.3867], 13); // ঢাকা সেন্টার
          L.marker([23.7985, 90.3867]).addTo(map)
            .bindPopup("Dhaka")
            .openPopup();
        }

             

        

        // ম্যাপের ভিতরে একটি সুন্দর কাস্টম সার্চ বাটন যোগ করুন
        setTimeout(function () {
          if (typeof L !== 'undefined' && typeof map !== 'undefined') {
            // আগের কোন জিওকোডার কন্ট্রোল থাকলে সেটি সরিয়ে ফেলুন
            const oldControls = document.querySelectorAll('.leaflet-control-geocoder');
            oldControls.forEach(control => control.remove());

            // কাস্টম সার্চ কন্ট্রোল তৈরি করুন
            const ParkingSearchControl = L.Control.extend({
              options: {
                position: 'topright'
              },

              onAdd: function (map) {
                // সার্চ কন্টেইনার তৈরি
                const container = L.DomUtil.create('div', 'custom-parking-search-control');
                container.innerHTML = `
                            <div class="search-button-container">
                                <button class="custom-search-button">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="11" cy="11" r="8"></circle>
                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                    </svg>
                                    <span>Parking Search</span>
                                </button>
                            </div>
                            <div class="search-input-container hidden">
                                <input type="text" placeholder="Search for parking..." class="search-input" />
                                <button class="search-submit">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="11" cy="11" r="8"></circle>
                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                    </svg>
                                </button>
                            </div>
                        `;

                // ইভেন্ট প্রোপাগেশন রোধ করুন (ম্যাপ ক্লিক ইভেন্ট প্রিভেন্ট)
                L.DomEvent.disableClickPropagation(container);

                // সার্চ বাটন ক্লিক ইভেন্ট
                const searchButton = container.querySelector('.custom-search-button');
                const searchContainer = container.querySelector('.search-input-container');

                searchButton.addEventListener('click', function () {
                  searchContainer.classList.toggle('hidden');
                  if (!searchContainer.classList.contains('hidden')) {
                    searchContainer.querySelector('.search-input').focus();
                  }
                });

                // সার্চ সাবমিট বাটন ক্লিক
                const searchSubmit = container.querySelector('.search-submit');
                const searchInput = container.querySelector('.search-input');

                searchSubmit.addEventListener('click', function () {
                  const query = searchInput.value.trim();
                  if (query) {
                    performSearch(query, map);
                  }
                });

                // ইনপুটে এন্টার কী প্রেস
                searchInput.addEventListener('keypress', function (e) {
                  if (e.key === 'Enter') {
                    const query = searchInput.value.trim();
                    if (query) {
                      performSearch(query, map);
                    }
                  }
                });

                return container;
              }
            });

            // কাস্টম সার্চ কন্ট্রোল ম্যাপে যোগ করুন
            const parkingSearchControl = new ParkingSearchControl();
            map.addControl(parkingSearchControl);

            // স্টাইল শীট যোগ করুন
            const style = document.createElement('style');
            style.textContent = `
                    .custom-parking-search-control {
                        background: transparent;
                        margin: 10px;
                        z-index: 1000;
                    }
                    
                    .search-button-container {
                        display: flex;
                        justify-content: flex-end;
                    }
                    
                    .custom-search-button {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        background-color: #222;
                        color: #f39c12;
                        border: 1px solid #f39c12;
                        border-radius: 30px;
                        padding: 8px 16px;
                        font-size: 14px;
                        font-weight: 500;
                        cursor: pointer;
                        transition: all 0.2s ease;
                        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
                    }
                    
                    .custom-search-button:hover {
                        background-color: #f39c12;
                        color: #222;
                    }
                    
                    .search-input-container {
                        display: flex;
                        margin-top: 10px;
                        background: rgba(20, 20, 20, 0.9);
                        border-radius: 30px;
                        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
                        transition: all 0.3s ease;
                        position: relative;
                        padding: 4px;
                        border: 1px solid #f39c12;
                    }
                    
                    .search-input-container.hidden {
                        display: none;
                    }
                    
                    .search-input {
                        flex: 1;
                        border: none;
                        background: transparent;
                        padding: 8px 16px;
                        font-size: 14px;
                        color: white;
                        width: 200px;
                        outline: none;
                    }
                    
                    .search-input::placeholder {
                        color: rgba(255, 255, 255, 0.6);
                    }
                    
                    .search-submit {
                        background-color: #f39c12;
                        border: none;
                        color: #222;
                        border-radius: 50%;
                        width: 32px;
                        height: 32px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        cursor: pointer;
                        transition: all 0.2s ease;
                    }
                    
                    .search-submit:hover {
                        background-color: #e67e22;
                    }
                `;
            document.head.appendChild(style);

            console.log("Custom Parking Search button added to map");
          }
        }, 1000); // ম্যাপ লোড হওয়ার জন্য 1 সেকেন্ড অপেক্ষা করুন

        // নিশ্চিত করুন যে ম্যাপ সঠিকভাবে রেন্ডার হয়েছে
        setTimeout(function () {
          map.invalidateSize();
          console.log("Map size invalidated for proper rendering");
        }, 500);

      } catch (error) {
        console.error("Critical error initializing map:", error);
      }
    });

    // Function to check booking status from the database
    function checkBookingStatus(garageId, callback) {
      // Make AJAX request to check booking status
      const xhr = new XMLHttpRequest();
      xhr.open('GET', `check_booking_status.php?garage_id=${garageId}`, true);

      xhr.onload = function () {
        if (xhr.status === 200) {
          try {
            const response = JSON.parse(xhr.responseText);

            if (response.success && response.has_booking) {
              // User has active booking for this garage
              callback(true);
            } else {
              // No active booking found
              callback(false);
            }
          } catch (e) {
            console.error('Error parsing JSON response:', e);
            callback(false);
          }
        } else {
          callback(false);
        }
      };

      xhr.onerror = function () {
        console.error('Network error while checking booking status');
        callback(false);
      };

      xhr.send();
    }

    // Function to update availability UI after booking
    function updateAvailabilityUI(updatedGarage, isUserBooking = false) {
      const garageId = updatedGarage.id;
      const newAvailable = updatedGarage.available;
      const capacity = updatedGarage.capacity;
      const garageName = updatedGarage.name;

      console.log("Updating UI for garage:", garageId, "New availability:", newAvailable);

      // Determine new status
      let statusText = "Available";
      let statusColor = "bg-success";

      if (newAvailable <= 0) {
        statusText = "Full";
        statusColor = "bg-error";
      } else if (newAvailable < capacity / 2) {
        statusText = "Limited";
        statusColor = "bg-warning";
      }

      // 1. Update the map markers
      if (typeof window.parkingMap !== 'undefined') {
        window.parkingMap.eachLayer(function (layer) {
          if (layer.getPopup && layer.getPopup()) {
            const popup = layer.getPopup();
            const content = popup.getContent();

            if (content && content.includes(`openBookingModal('${garageId}')`)) {
              console.log("Found matching map marker for garage:", garageId);

              // Create a temporary div to manipulate the HTML
              const tempDiv = document.createElement('div');
              tempDiv.innerHTML = content;

              // Update the status text and color
              const statusSpan = tempDiv.querySelector('.text-sm');
              if (statusSpan) {
                statusSpan.textContent = `${statusText} (${newAvailable} spots)`;
              }

              // Update the status dot color
              const statusDot = tempDiv.querySelector('.rounded-full');
              if (statusDot) {
                // Remove old status classes
                statusDot.classList.remove('bg-success', 'bg-warning', 'bg-error');
                statusDot.classList.add(statusColor);
              }

              // If this was just booked by the user, mark it visually
              const bookingBtn = tempDiv.querySelector('button');
              if (bookingBtn) {
                if (isUserBooking) {
                  // Change "Book Now" to "Booked" for the user who just booked - use green color
                  bookingBtn.textContent = "Booked";
                  bookingBtn.classList.add('bg-success hover:bg-success'); // Changed to green (success) color
                  bookingBtn.classList.remove('bg-primary', 'hover:bg-orange-600', 'bg-gray-600');
                  bookingBtn.disabled = true;
                  
                } else if (newAvailable === 0) {
                  bookingBtn.textContent = "Fully Booked";
                  bookingBtn.classList.add('bg-error'); // Use red color for full
                  bookingBtn.classList.remove('bg-primary', 'hover:bg-orange-600', 'bg-gray-600');
                  bookingBtn.disabled = true;
                }
              }

              // Set the updated content
              layer.setPopupContent(tempDiv.innerHTML);

              // If popup is open, refresh it
              if (layer.isPopupOpen()) {
                layer.closePopup();
                layer.openPopup();
              }
            }
          }
        });
      } else {
        console.log("Map not found or not initialized");
      }

      // 2. Update featured parking cards
      const cards = document.querySelectorAll('.parking-card');
      cards.forEach(card => {
        // Check if the card is for our garage - look for the booking button with the right ID
        const bookButton = card.querySelector(`[onclick*="openBookingModal('${garageId}')"]`);

        if (bookButton) {
          console.log("Found matching card for garage:", garageId);

          // Update status text
          const statusSpan = card.querySelector('.text-white\\/90.text-sm');
          if (statusSpan) {
            statusSpan.textContent = `${statusText} (${newAvailable} spots)`;
          }

          // Update status dot
          const statusDot = card.querySelector('.w-2\\.5.h-2\\.5');
          if (statusDot) {
            // Remove old status classes
            statusDot.classList.remove('bg-success', 'bg-warning', 'bg-error');
            statusDot.classList.add(statusColor);
          }

          // If this was just booked by the user, update the button
          if (isUserBooking) {
            // Change "Book Now" to "Booked" for the user who just booked
            bookButton.textContent = "Booked";
            bookButton.classList.add('bg-success'); // Changed to green (success) color
            bookButton.classList.remove('bg-primary', 'hover:bg-orange-600', 'bg-gray-600');
            bookButton.disabled = true;
          } else if (newAvailable === 0) {
            bookButton.textContent = "Fully Booked";
            bookButton.classList.add('bg-error'); // Use red color for full
            bookButton.classList.remove('bg-primary', 'hover:bg-orange-600', 'bg-gray-600');
            bookButton.disabled = true;
          }
        }
      });
    }

    // On page load, check all booking status from database
    document.addEventListener('DOMContentLoaded', function () {
      // Get all booking buttons
      const bookingButtons = document.querySelectorAll('[onclick*="openBookingModal"]');

      if (bookingButtons.length > 0) {
        // Create a list of garage IDs to check
        const garageIds = Array.from(bookingButtons).map(button => {
          const matches = button.getAttribute('onclick').match(/openBookingModal\('([^']+)'\)/);
          return matches && matches[1];
        }).filter(id => id); // Filter out any null/undefined values

        // If we have garage IDs, check their booking status
        if (garageIds.length > 0) {
          // For each garage ID, update buttons
          garageIds.forEach(garageId => {
            checkBookingStatus(garageId, function (isBooked) {
              if (isBooked) {
                // Update all buttons for this garage
                document.querySelectorAll(`[onclick*="openBookingModal('${garageId}')"]`).forEach(button => {
                  button.textContent = "Booked";
                  button.classList.add('bg-success'); // Green success color
                  button.classList.remove('bg-primary', 'hover:bg-orange-600', 'bg-gray-600');
                  button.disabled = true;
                });

                // Also update map markers with the same styling
                updateMapMarkerBookingStatus(garageId, true);
              }
            });
          });
        }
      }
    });
    function updateMapMarkerBookingStatus(garageId, isBooked) {
      if (typeof window.parkingMap !== 'undefined') {
        window.parkingMap.eachLayer(function (layer) {
          if (layer.getPopup && layer.getPopup()) {
            const popup = layer.getPopup();
            const content = popup.getContent();

            if (content && content.includes(`openBookingModal('${garageId}')`)) {
              console.log("Updating booking status for map marker:", garageId);

              // Create a temporary div to manipulate the HTML
              const tempDiv = document.createElement('div');
              tempDiv.innerHTML = content;

              // Find the button element
              const bookingBtn = tempDiv.querySelector('button[onclick*="openBookingModal"]');
              if (bookingBtn) {
                if (isBooked) {
                  // Instead of creating a new button, modify the existing one with inline styles
                  bookingBtn.textContent = "Booked";
                  bookingBtn.disabled = true;

                  // Add a special class we can target in CSS
                  bookingBtn.classList.add('booked-status');

                  // Remove any classes that might cause conflicts
                  bookingBtn.classList.remove('bg-error');

                  // Apply inline styles with !important
                  bookingBtn.setAttribute('style',
                    'background-color: #36D399 !important; ' +
                    'border-color: #36D399 !important; ' +
                    'color: white !important; ' +
                    'font-weight: bold !important; ' +
                    'position: relative; ' +
                    'z-index: 5;'
                  );

                  // Add additional wrapper if needed for visibility
                  bookingBtn.innerHTML = "<span style='color: white !important; position: relative; z-index: 10;'>Booked</span>";
                }
              }

              // Set the updated content
              layer.setPopupContent(tempDiv.innerHTML);

              // If popup is open, refresh it
              if (layer.isPopupOpen()) {
                layer.closePopup();
                layer.openPopup();
              }
            }
          }
        });
      }

      // Add a manual check after a short delay to ensure styles are applied
      setTimeout(function () {
        document.querySelectorAll('.leaflet-popup-content button').forEach(btn => {
          if (btn.textContent.trim() === 'Booked') {
            btn.setAttribute('style',
              'background-color: #36D399 !important; ' +
              'border-color: #36D399 !important; ' +
              'color: white !important; ' +
              'font-weight: bold !important;'
            );
          }
        });
      }, 100);
    }

    // Add this to your script to continuously monitor and fix booked buttons
    function monitorBookedButtons() {
      setInterval(function () {
        document.querySelectorAll('.leaflet-popup-content button, .leaflet-popup-content span').forEach(el => {
          if (el.textContent.trim() === 'Booked') {
            const button = el.tagName === 'BUTTON' ? el : el.closest('button');
            if (button) {
              button.setAttribute('style',
                'background-color: #36D399 !important; ' +
                'border-color: #36D399 !important; ' +
                'color: white !important; ' +
                'font-weight: bold !important;'
              );
            }
          }
        });
      }, 500);
    }

    // Run this when page loads
    document.addEventListener('DOMContentLoaded', monitorBookedButtons);
  </script>





  <!-- Custom Search Popup -->
  <div id="customSearchBox"
    class="fixed top-20 right-6 z-[1000] bg-black/90 p-4 rounded-xl shadow-lg border border-primary hidden">
    <label for="searchInput" class="block text-white text-sm mb-2">Enter location:</label>
    <input type="text" id="searchInput" class="input input-sm w-full mb-2 text-black"
      placeholder="e.g. Gulshan, Dhaka" />
    <button id="searchSubmit" class="btn btn-sm bg-primary hover:bg-primary-dark text-white w-full">Search</button>
  </div>

  <!-- Card's Animation -->
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      // Add the pulsing animation CSS to the document
      const style = document.createElement('style');
      style.textContent = `
        .pulsing {
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% {
                transform: translateY(-50%) scale(0.95);
                box-shadow: 0 0 0 0 rgba(243, 156, 18, 0.7);
            }
            70% {
                transform: translateY(-50%) scale(1);
                box-shadow: 0 0 0 10px rgba(243, 156, 18, 0);
            }
            100% {
                transform: translateY(-50%) scale(0.95);
                box-shadow: 0 0 0 0 rgba(243, 156, 18, 0);
            }
        }
    `;
      document.head.appendChild(style);

      const carousel = document.getElementById('parkingCarousel');
      const prevBtn = document.getElementById('prevParking');
      const nextBtn = document.getElementById('nextParking');
      const cards = document.querySelectorAll('.parking-card');

      if (!carousel || !prevBtn || !nextBtn || cards.length === 0) {
        console.error("Carousel elements not found");
        return;
      }

      const visibleItems = 3;
      let currentIndex = 0;
      const totalPages = Math.ceil(cards.length / visibleItems);

      window.scrollToIndex = function (index) {
        const maxIndex = cards.length - visibleItems;
        index = Math.max(0, Math.min(index, maxIndex));
        currentIndex = index;
        const cardWidth = cards[0].offsetWidth + 24;
        carousel.style.transition = 'transform 0.5s ease';
        carousel.style.transform = `translateX(-${index * cardWidth}px)`;
        updatePaginationDots();
        updateArrowVisibility();
      };

      const updateArrowVisibility = () => {
        const isAtStart = currentIndex === 0;
        const isAtEnd = currentIndex >= cards.length - visibleItems;

        prevBtn.style.opacity = isAtStart ? '0.5' : '1';
        prevBtn.style.cursor = isAtStart ? 'not-allowed' : 'pointer';
        nextBtn.style.opacity = isAtEnd ? '0.5' : '1';
        nextBtn.style.cursor = isAtEnd ? 'not-allowed' : 'pointer';

        prevBtn.classList.toggle('pulsing', !isAtStart);
        nextBtn.classList.toggle('pulsing', !isAtEnd);
      };

      prevBtn.addEventListener('click', () => {
        scrollToIndex(currentIndex - visibleItems);
      });

      nextBtn.addEventListener('click', () => {
        scrollToIndex(currentIndex + visibleItems);
      });

      const updatePaginationDots = () => {
        const dots = document.querySelectorAll('.pagination-dot');
        const currentPage = Math.floor(currentIndex / visibleItems);
        dots.forEach((dot, i) => {
          dot.classList.toggle('bg-primary', i === currentPage);
          dot.classList.toggle('bg-white/30', i !== currentPage);
        });
      };

      const createPaginationDots = () => {
        const dotsContainer = document.querySelector('.pagination-dot').parentElement;
        dotsContainer.innerHTML = '';
        for (let i = 0; i < totalPages; i++) {
          const dot = document.createElement('span');
          dot.id = 'dot-' + i;
          dot.classList.add('pagination-dot', 'w-3', 'h-3', 'rounded-full', 'cursor-pointer');
          dot.classList.add(i === 0 ? 'bg-primary' : 'bg-white/30');
          dot.setAttribute('onclick', `scrollToIndex(${i * visibleItems})`);
          dot.addEventListener('click', () => scrollToIndex(i * visibleItems));
          dotsContainer.appendChild(dot);
        }
        carousel.addEventListener('transitionend', updatePaginationDots);
      };

      document.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowLeft') scrollToIndex(currentIndex - visibleItems);
        if (e.key === 'ArrowRight') scrollToIndex(currentIndex + visibleItems);
      });

      createPaginationDots();

      const existingDots = document.querySelectorAll('.pagination-dot');
      existingDots.forEach((dot, i) => {
        dot.setAttribute('onclick', `scrollToIndex(${i * visibleItems})`);
      });

      updateArrowVisibility();

      if (cards.length <= visibleItems) {
        prevBtn.style.display = 'none';
        nextBtn.style.display = 'none';
        document.querySelector('.pagination-dot').parentElement.style.display = 'none';
      }

      let autoScrollInterval;

      const startAutoScroll = () => {
        autoScrollInterval = setInterval(() => {
          const maxIndex = cards.length - visibleItems;
          const nextIndex = currentIndex >= maxIndex ? 0 : currentIndex + visibleItems;
          scrollToIndex(nextIndex);
        }, 5000);
      };

      const stopAutoScroll = () => clearInterval(autoScrollInterval);

      startAutoScroll();

      carousel.addEventListener('mouseenter', stopAutoScroll);
      prevBtn.addEventListener('mouseenter', stopAutoScroll);
      nextBtn.addEventListener('mouseenter', stopAutoScroll);
      carousel.addEventListener('mouseleave', startAutoScroll);
      prevBtn.addEventListener('mouseleave', startAutoScroll);
      nextBtn.addEventListener('mouseleave', startAutoScroll);
    });
  </script>
  <!-- Booking Modal -->
  <div id="bookingModal" class="fixed inset-0 z-50 hidden overflow-y-auto">
    <div class="flex min-h-screen items-center justify-center px-4">
      <!-- Modal Background Overlay -->
      <div class="fixed inset-0 bg-black opacity-50" onclick="closeBookingModal()"></div>

      <!-- Modal Content -->
      <div
        class="relative bg-black/80 backdrop-blur-md w-full max-w-md rounded-xl border border-white/20 shadow-xl px-6 py-5 overflow-hidden transform transition-all">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-xl font-bold text-white" id="modalTitle">Book Parking Space</h3>
          <button type="button" class="text-white/70 hover:text-white" onclick="closeBookingModal()">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>

        <form id="bookingForm" class="space-y-4">
          <input type="hidden" id="garage_id" name="garage_id">

          <div class="form-control">
            <label class="label">
              <span class="label-text text-white">Date</span>
            </label>
            <input type="date" id="booking_date" name="booking_date"
              class="input input-bordered bg-white/15 text-white border-white/20 focus:border-primary" required>
          </div>

          <div class="form-control">
            <label class="label">
              <span class="label-text text-white">Time</span>
            </label>
            <input type="time" id="booking_time" name="booking_time"
              class="input input-bordered bg-white/15 text-white border-white/20 focus:border-primary" required>
          </div>

          <div class="form-control">
            <label class="label">
              <span class="label-text text-white">Duration (hours)</span>
            </label>
            <select id="duration" name="duration"
              class="select select-bordered bg-white/15 text-white border-white/20 focus:border-primary" required>
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

          <!-- Vehicle Information Field -->
          <div class="mb-4">
            <label for="vehicle_select" class="block text-white mb-2">Vehicle Information</label>
            <select id="vehicle_select" name="licenseplate"
              class="w-full bg-neutral-800 text-white border-0 rounded-lg p-3">
              <!-- Options will be loaded dynamically -->
            </select>
          </div>

          <div class="mt-5 flex justify-end gap-3">
            <button type="button" class="btn btn-outline border-white/20 text-white"
              onclick="closeBookingModal()">Cancel</button>
            <button type="submit" class="btn bg-primary hover:bg-primary-dark text-white border-none">Confirm
              Booking</button>
          </div>

          <div id="bookingMessage" class="mt-4 hidden"></div>
        </form>
      </div>
    </div>
  </div>
    <!-- Reviews Modal - Add this after your booking modal -->
<div id="reviewsModal" class="fixed inset-0 z-50 hidden overflow-y-auto">
    <div class="flex min-h-screen items-center justify-center px-4">
        <!-- Modal Background Overlay -->
        <div class="fixed inset-0 bg-black opacity-50" onclick="closeReviewsModal()"></div>

        <!-- Modal Content -->
        <div class="relative bg-black/80 backdrop-blur-md w-full max-w-2xl rounded-xl border border-white/20 shadow-xl p-6 max-h-[90vh] overflow-hidden">
            <div class="flex justify-between items-center mb-4">
                <div>
                    <h3 class="text-xl font-bold text-white">Reviews & Ratings</h3>
                    <p class="text-white/70 text-sm" id="garageName">Loading...</p>
                </div>
                <button type="button" class="text-white/70 hover:text-white" onclick="closeReviewsModal()">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Rating Summary -->
            <div id="ratingSummary" class="bg-white/10 rounded-lg p-4 mb-4">
                <!-- Summary will be loaded here -->
            </div>

            <!-- Reviews Content -->
            <div id="reviewsContent" class="max-h-96 overflow-y-auto">
                <!-- Loading state -->
                <div class="flex justify-center items-center py-8">
                    <div class="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-primary"></div>
                    <span class="ml-3 text-white">Loading reviews...</span>
                </div>
            </div>

            
        </div>
    </div>
</div>

  <script>
    // Modal and booking functions - Updated to support preset date and time
    function openBookingModal(garageId, presetDate = null, presetTime = null) {
      
    // Check if user is verified
  const isUserVerified = checkUserVerification();
  
  if (!isUserVerified) {
    showCustomNotification("Your account needs verification before booking. Go to Profile and sumbit the required documents.", "error");
    return; // Stop the function and don't open the modal
  }

  function checkUserVerification() {
  // This relies on a server-side endpoint to check verification status
  // For immediate implementation without refreshing, you can add a hidden field with this information
  return document.body.getAttribute('data-user-verified') === 'true';
}
      // Set the garage_id in the form
      document.getElementById('garage_id').value = garageId;

      // Set default date to today or preset date
      if (presetDate) {
        document.getElementById('booking_date').value = presetDate;
      } else {
        const today = new Date();
const year = today.getFullYear();
const month = String(today.getMonth() + 1).padStart(2, '0');
const day = String(today.getDate()).padStart(2, '0');
const formattedDate = `${year}-${month}-${day}`;
document.getElementById('booking_date').value = formattedDate;
      }

      // Set default time to current time + 1 hour, rounded to nearest hour or preset time
      if (presetTime) {
        document.getElementById('booking_time').value = presetTime.substr(0, 5); // Format HH:MM
      } else {
        let today = new Date();
        let hours = today.getHours() + 1;
        const formattedTime = `${hours.toString().padStart(2, '0')}:00`;
        document.getElementById('booking_time').value = formattedTime;
      }

      // Update modal title if preset date and time
      if (presetDate && presetTime) {
        const formattedDateTime = new Date(`${presetDate}T${presetTime}`);
        const options = {
          month: 'short',
          day: 'numeric',
          year: 'numeric',
          hour: 'numeric',
          minute: '2-digit',
          hour12: true
        };
        const readableDateTime = formattedDateTime.toLocaleDateString('en-US', options);
        document.getElementById('modalTitle').innerHTML = `Book for ${readableDateTime}`;
      } else {
        document.getElementById('modalTitle').innerHTML = `Book Parking Space`;
      }

      // Load user's vehicles
      loadUserVehicles();

      // Show the modal
      document.getElementById('bookingModal').classList.remove('hidden');
    }

    // Function to handle the "Book For Later" button click
    function bookForLater(garageId) {
      // Show a loading indicator
      showCustomNotification('Checking next available time...', 'info');

      // Fetch the next available time from the API
      fetch(`get_next_availability.php?garage_id=${garageId}`)
        .then(response => response.json())
        .then(data => {
          if (data.success && data.next_available) {
            // Extract date and time from the response
            const nextDate = data.next_available.date;
            const nextTime = data.next_available.time;

            // Open the booking modal and set the date and time
            openBookingModal(garageId, nextDate, nextTime);

            // Show a success notification
            showCustomNotification('Next available time slot loaded!', 'success');
          } else {
            // Show an error notification
            showCustomNotification('Unable to determine next available time', 'error');

            // Open the booking modal normally
            openBookingModal(garageId);
          }
        })
        .catch(error => {
          console.error('Error fetching next availability:', error);
          showCustomNotification('Error fetching availability data', 'error');

          // Open the booking modal normally as a fallback
          openBookingModal(garageId);
        });
    }

    // Function to load user's vehicles
    function loadUserVehicles() {
      // Create AJAX request
      const xhr = new XMLHttpRequest();
      xhr.open('GET', 'get_user_vehicles.php', true);

      xhr.onload = function () {
        if (xhr.status === 200) {
          try {
            const response = JSON.parse(xhr.responseText);

            // Get the vehicle select element
            const vehicleSelect = document.getElementById('vehicle_select');
            vehicleSelect.innerHTML = ''; // Clear existing options

            if (response.success && response.vehicles.length > 0) {
              // Add vehicle options
              response.vehicles.forEach(function (vehicle) {
                const option = document.createElement('option');
                option.value = vehicle.licenseplate;
                option.textContent = `${vehicle.make} ${vehicle.model} (${vehicle.licenseplate})`;
                vehicleSelect.appendChild(option);
              });
            } else {
              // No vehicles found, show message and add option
              const option = document.createElement('option');
              option.value = "";
              option.textContent = "No vehicles found";
              option.disabled = true;
              option.selected = true;
              vehicleSelect.appendChild(option);

              // Add "Add Vehicle" option
              const addOption = document.createElement('option');
              addOption.value = "add_new";
              addOption.textContent = "➕ Add New Vehicle";
              vehicleSelect.appendChild(addOption);
            }
          } catch (e) {
            console.error('Error parsing JSON from get_user_vehicles.php:', e);
            console.log('Raw response:', xhr.responseText);

            // Handle error gracefully
            const vehicleSelect = document.getElementById('vehicle_select');
            vehicleSelect.innerHTML = ''; // Clear existing options

            const option = document.createElement('option');
            option.value = "";
            option.textContent = "Error loading vehicles";
            option.disabled = true;
            option.selected = true;
            vehicleSelect.appendChild(option);

            const addOption = document.createElement('option');
            addOption.value = "add_new";
            addOption.textContent = "➕ Add New Vehicle";
            vehicleSelect.appendChild(addOption);
          }
        } else {
          console.error('Error loading vehicles. Status:', xhr.status);
        }
      };

      xhr.onerror = function () {
        console.error('Network error while loading vehicles');
      };

      xhr.send();
    }

    function closeBookingModal() {
      // Hide the modal
      document.getElementById('bookingModal').classList.add('hidden');

      // Reset the form
      document.getElementById('bookingForm').reset();
      document.getElementById('bookingMessage').classList.add('hidden');
    }

    // Process the booking form submission via AJAX
    document.getElementById('bookingForm').addEventListener('submit', function (e) {
      e.preventDefault();

      // Get the garage ID being booked
      const garageId = document.getElementById('garage_id').value;

      // Show a loading indicator in the message div
      const messageDiv = document.getElementById('bookingMessage');
      messageDiv.classList.remove('hidden');
      messageDiv.className = 'mt-4 p-4 bg-info/20 text-white rounded-lg';
      messageDiv.innerHTML = 'Processing your booking...';

      // Get form data
      const formData = new FormData(this);

      // Add the current username from session
      formData.append('username', '<?php echo $_SESSION["username"]; ?>');

      // Create an AJAX request
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'process_booking.php', true);

      xhr.onload = function () {
        if (xhr.status === 200) {
          try {
            console.log('Raw server response:', xhr.responseText);
            const response = JSON.parse(xhr.responseText);

            // Show message
            if (response.success) {
              messageDiv.className = 'mt-4 p-4 bg-success/20 text-white rounded-lg';
              messageDiv.innerHTML = response.message;

              // Update UI with new availability if returned
              if (response.updated_garage) {
                // Pass true to indicate this is the user's booking
                updateAvailabilityUI(response.updated_garage, true);
              }

              // Close modal after 3 seconds if successful
              setTimeout(function () {
                closeBookingModal();
                window.location.reload(); // রিলোড পেজ টু আপডেট UI
              }, 3000);
            } else {
              messageDiv.className = 'mt-4 p-4 bg-error/20 text-white rounded-lg';
              messageDiv.innerHTML = response.message || 'An error occurred while processing your booking.';
            }
          } catch (e) {
            // JSON parsing error
            console.error('JSON parsing error:', e);
            console.log('Server response:', xhr.responseText);

            // ম্যানুয়ালি চেক করুন রেসপন্স কন্টেন্ট - সফল বুকিং ট্র্যাক করার জন্য
            if (xhr.responseText.includes('success') && xhr.responseText.includes('true')) {
              messageDiv.className = 'mt-4 p-4 bg-success/20 text-white rounded-lg';
              messageDiv.innerHTML = 'Your booking appears to be successful! Refreshing page to update status...';

              // পেজ রিলোড করুন যাতে সমস্ত UI আপডেট হয়
              setTimeout(function () {
                window.location.reload();
              }, 2000);
            } else {
              messageDiv.className = 'mt-4 p-4 bg-error/20 text-white rounded-lg';
              messageDiv.innerHTML = 'An error occurred while processing your request. Please check My Bookings page to verify if booking was successful.';

              // এরর থাকলেও, বুকিং পেজ চেক করার পরামর্শ দিন
              setTimeout(function () {
                closeBookingModal();
              }, 4000);
            }
          }
        } else {
          // HTTP error
          messageDiv.className = 'mt-4 p-4 bg-error/20 text-white rounded-lg';
          messageDiv.innerHTML = 'Server error: ' + xhr.status + '. Please check My Bookings page to verify status.';

          setTimeout(function () {
            closeBookingModal();
          }, 4000);
        }
      };

      xhr.onerror = function () {
        // Network error
        messageDiv.className = 'mt-4 p-4 bg-error/20 text-white rounded-lg';
        messageDiv.innerHTML = 'Network error. Please check your connection and verify booking status on My Bookings page.';

        setTimeout(function () {
          closeBookingModal();
        }, 4000);
      };

      xhr.send(formData);
    });

    // Add event listener to vehicle select dropdown
    document.addEventListener('DOMContentLoaded', function () {
      const vehicleSelect = document.getElementById('vehicle_select');

      if (vehicleSelect) {
        vehicleSelect.addEventListener('change', function () {
          if (this.value === 'add_new') {
            // Redirect to add_vehicle.php page
            window.location.href = 'add_vehicle.php';
          }
        });
      }
    });
  </script>

  <script>
    // Function to create popup content with timer support
    function createPopupContent(garage, isBooked, bookingStatus) {
      // Add timer HTML if this is a booked garage
      let timerHTML = '';
      let availabilityTimerHTML = ''; // New variable for availability timer
      let bookForLaterBtn = ''; // New variable for Book For Later button

      if (isBooked && bookingStatus) {
        // Current time in seconds
        const now = Math.floor(Date.now() / 1000);

        if (bookingStatus.status === 'upcoming') {
          // Calculate start time from booking info
          const startTime = new Date(bookingStatus.booking_date + ' ' + bookingStatus.booking_time).getTime() / 1000;
          const timeToStart = startTime - now;

          if (timeToStart > 0) {
            timerHTML = `
                    <div class="timer-container timer-upcoming bg-blue-500/20 text-blue-200 p-2 rounded-md flex items-center justify-center gap-2 text-sm my-2" data-timestamp="${startTime}" data-type="upcoming">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span>Starts in: <span class="font-semibold countdown-value">calculating...</span></span>
                    </div>
                `;
          }
        } else if (bookingStatus.status === 'active') {
          // Calculate end time based on start time and duration
          const startTime = new Date(bookingStatus.booking_date + ' ' + bookingStatus.booking_time).getTime() / 1000;
          const endTime = startTime + (bookingStatus.duration * 3600);
          const timeToEnd = endTime - now;

          if (timeToEnd > 0) {
            timerHTML = `
                    <div class="timer-container timer-active timer-pulse bg-green-500/20 text-green-200 p-2 rounded-md flex items-center justify-center gap-2 text-sm my-2" data-timestamp="${endTime}" data-type="active">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span>Ends in: <span class="font-semibold countdown-value">calculating...</span></span>
                    </div>
                `;
          }
        }
      }

      // Button styling based on booking status
      let buttonClass = "bg-primary hover:bg-orange-600";
      let buttonText = "Book Now";
      let buttonDisabled = "";

      if (isBooked) {
        buttonClass = "bg-success hover:bg-success";
        buttonText = "Booked";
        buttonDisabled = "disabled";
      } else if (garage.available <= 0) {
        buttonClass = "bg-error text-black";  // Changed from text-black to text-white
        buttonText = "Fully Booked";
        buttonDisabled = "disabled";

        // Add Book For Later button when a spot is fully booked
        bookForLaterBtn = `<button onclick="bookForLater('${garage.id}')" class="btn bg-blue-600 hover:bg-blue-700 text-white w-full mt-2 font-semibold transition-all duration-300">Book For Later</button>`;

        // Add availability timer if we have next available time data
        if (garage.nextAvailable && garage.nextAvailable.timestamp) {
          const nextAvailableTime = garage.nextAvailable.timestamp;
          const timeUntilAvailable = nextAvailableTime - Math.floor(Date.now() / 1000);

          if (timeUntilAvailable > 0) {
            availabilityTimerHTML = `
                    <div class="timer-container timer-availability bg-blue-500/20 text-blue-800 p-2 rounded-md flex items-center justify-center gap-2 text-sm my-2" data-timestamp="${nextAvailableTime}" data-type="available-soon">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span>Available in: <span class="font-semibold countdown-value">calculating...</span></span>
                    </div>
                `;
          }
        }
      }

      // Status indicator
      let statusColor = 'bg-success';
      let statusText = `Available (${garage.available} spots)`;

      if (garage.available <= 0) {
        statusColor = 'bg-error';
        statusText = 'Full (0 spots)';
      } else if (garage.available < garage.capacity / 2) {
        statusColor = 'bg-warning';
        statusText = `Limited (${garage.available} spots)`;
      }

      // Format price
      const price = garage.price ? `৳${parseFloat(garage.price).toFixed(2)}/hr` : '৳45.00/hr';

      return `
        <div class="p-2" style="min-width: 240px;">
            <h3 class="font-bold text-lg">${garage.name}</h3>
            <p class="text-sm mb-2">${garage.address}</p>
            <div class="flex justify-between mb-2">
                <span class="text-sm">Type: ${garage.type || 'Standard'}</span>
                <span class="text-sm">Size: ${garage.dimensions || 'Standard'}</span>
            </div>
            <div class="flex justify-between items-center mb-2">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full ${statusColor}"></span>
                    <span class="text-sm">${statusText}</span>
                </div>
                <div class="text-primary font-semibold">${price}</div>
            </div>
            ${timerHTML}
            ${availabilityTimerHTML}
            <button onclick="openBookingModal('${garage.id}')" class="btn ${buttonClass} text-white w-full mt-1 font-semibold transition-all duration-300" ${buttonDisabled} style="color: white !important; font-weight: bold !important; ${garage.available <= 0 ? 'background-color: #F87272 !important;' : ''}">${buttonText}</button>
            ${bookForLaterBtn}
        </div>
    `;
    }

    // Specific function for Leaflet map popups
    function enhanceMapPopups() {
      // Find all Leaflet popups
      document.querySelectorAll('.leaflet-popup-content').forEach(popup => {
        // Check if it contains a Booked button
        const button = popup.querySelector('button');
        if (button && button.textContent.trim() === 'Booked') {
          // Get the parent element that contains the button
          const buttonParent = button.parentElement;

          // Add hover styles to the Booked button
          button.style.position = 'relative';
          button.style.zIndex = '1';
          button.style.transition = 'all 0.3s';

          // Create custom CSS for this specific popup
          const popupId = 'popup-' + Math.random().toString(36).substr(2, 9);
          const customStyle = document.createElement('style');
          customStyle.textContent = `
        #${popupId} {
          position: relative;
          width: 100%;
        }
        #${popupId} .cancel-button {
          position: absolute;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          background-color: #F87272;
          color: white;
          border: none;
          border-radius: 0.5rem;
          font-weight: bold;
          cursor: pointer;
          opacity: 0;
          transition: opacity 0.3s ease;
          z-index: 2;
          display: flex;
          justify-content: center;
          align-items: center;
        }
        #${popupId}:hover .cancel-button {
          opacity: 1;
        }
      `;
          document.head.appendChild(customStyle);

          // Create wrapper with unique ID for styling
          const wrapper = document.createElement('div');
          wrapper.id = popupId;
          wrapper.style.position = 'relative';
          wrapper.style.width = '100%';

          // Extract the garage ID if present
          let garageId = '';
          if (button.getAttribute('onclick')) {
            const match = button.getAttribute('onclick').match(/openBookingModal\('([^']+)'\)/);
            if (match && match[1]) garageId = match[1];
          }

          // Create the cancel button that appears on hover
          const cancelButton = document.createElement('button');
          cancelButton.className = 'cancel-button';
          cancelButton.textContent = 'Cancel Booking';

          // Add click handler to cancel button
          cancelButton.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            if (garageId) {
              if (confirm('Are you sure you want to cancel this booking?')) {
                // Send cancellation request
                fetch('cancel_booking.php', {
                  method: 'POST',
                  headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                  },
                  body: 'garage_id=' + encodeURIComponent(garageId)
                })
                  .then(response => response.json())
                  .then(data => {
                    if (data.success) {
                      alert('Booking cancelled successfully!');
                      window.location.reload();
                    } else {
                      alert(data.message || 'Error cancelling booking.');
                    }
                  })
                  .catch(error => {
                    console.error('Error:', error);
                    alert('Error cancelling booking. Please try again.');
                  });
              }
            }
          });

          // Clone the original button to preserve its appearance
          const clonedButton = button.cloneNode(true);

          // Before replacing the button, check if there's a timer to preserve
          const timerContainer = buttonParent.querySelector('.timer-container');

          // Remove the original button and replace it with our structure
          button.remove();
          wrapper.appendChild(clonedButton);
          wrapper.appendChild(cancelButton);
          buttonParent.appendChild(wrapper);

          // If there was a timer, it's now gone. Let's check if we need to add it back
          if (timerContainer) {
            // Move timer before the new button structure
            buttonParent.insertBefore(timerContainer, wrapper);
          } else if (garageId) {
            // No timer yet, but we have a garage ID. Let's check if there should be a timer
            checkBookingStatus(garageId, function (isBooked, bookingData) {
              if (isBooked && bookingData) {
                const newTimerContainer = createTimerElement(bookingData);
                if (newTimerContainer) {
                  buttonParent.insertBefore(newTimerContainer, wrapper);
                  initializeTimer(newTimerContainer);
                }
              }
            });
          }
        }
      });
    }

    // Add this where markers are created and added to the map
    // This fetches the next availability time for fully booked spots
    // Find the code where you process fully booked spots and add this:
    if (parseInt(garage.available) <= 0) {
      // Fetch next availability time
      fetch(`get_next_availability.php?garage_id=${garage.id}`)
        .then(response => response.json())
        .then(data => {
          if (data.success && !data.has_own_booking) {
            // Store next availability data with the garage object for use in popup
            garage.nextAvailable = data.next_available;

            // Update popup content with next availability info
            const popupEl = marker.getPopup();
            const newContent = createPopupContent(garage, isBooked, bookingStatus);
            popupEl.setContent(newContent);

            // Update timer if popup is open
            if (marker.isPopupOpen()) {
              const timerContainer = marker.getPopup()._contentNode.querySelector('.timer-container[data-type="available-soon"]');
              if (timerContainer) {
                updateTimer(timerContainer);
              }
            }
          }
        })
        .catch(error => console.error('Error fetching next availability:', error));
    }

    // Run the function when the page loads and when popups might be created
    document.addEventListener('DOMContentLoaded', function () {
      // Initial run - might not catch dynamically added popups
      setTimeout(enhanceMapPopups, 2000);

      // Watch for Leaflet popup events - this is tricky because Leaflet manages its own events
      document.addEventListener('click', function (e) {
        // When any element is clicked, check if new popups appeared
        setTimeout(enhanceMapPopups, 500);
      });

      // Watch for map move events which might trigger new popups
      const mapElement = document.getElementById('map');
      if (mapElement) {
        mapElement.addEventListener('mousemove', function () {
          // Debounce by only running occasionally
          if (Math.random() < 0.05) { // Run about 5% of the time to avoid too many checks
            setTimeout(enhanceMapPopups, 500);
          }
        });
      }
    });



  </script>


  <script>
// Create custom notification function
function showCustomNotification(message, type = 'success') {
  // Remove any existing notification
  const existingNotification = document.getElementById('custom-notification');
  if (existingNotification) {
    existingNotification.remove();
  }

  // Create notification container
  const notification = document.createElement('div');
  notification.id = 'custom-notification';

  // Set styles based on type
  let bgColor, textColor, borderColor, icon;

  if (type === 'success') {
    bgColor = '#36D399';
    textColor = 'white';
    borderColor = '#2BB37F';
    icon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>`;
  } else if (type === 'error') {
    bgColor = '#F87272';
    textColor = 'white';
    borderColor = '#E05252';
    icon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>`;
  } else if (type === 'warning') {
    bgColor = '#FBBD23';
    textColor = 'white';
    borderColor = '#DB9D03';
    icon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>`;
  } else { // info
    bgColor = '#3ABFF8';
    textColor = 'white';
    borderColor = '#1A9FD8';
    icon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>`;
  }
  
  // Add styles to notification
  notification.style.position = 'fixed';
  notification.style.top = '20px';
  notification.style.left = '50%';
  notification.style.transform = 'translateX(-50%)';
  notification.style.zIndex = '9999';
  notification.style.padding = '16px 24px';
  notification.style.backgroundColor = bgColor;
  notification.style.color = textColor;
  notification.style.borderRadius = '8px';
  notification.style.boxShadow = '0 4px 12px rgba(0, 0, 0, 0.15)';
  notification.style.display = 'flex';
  notification.style.alignItems = 'center';
  notification.style.maxWidth = '90%';
  notification.style.width = 'fit-content';
  notification.style.border = `1px solid ${borderColor}`;
  notification.style.backdropFilter = 'blur(4px)';
  
  // Add content to notification
  notification.innerHTML = `
      <div class="flex items-center">
        ${icon}
    <div>${message}</div>
    </div>
      <button id="close-notification" class="ml-4" style="background: transparent; border: none; color: ${textColor}; cursor: pointer; margin-left: 16px;">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    `;
  
  // Add to document
  document.body.appendChild(notification);
  
  // Add event listener to close button
  document.getElementById('close-notification').addEventListener('click', function() {
    notification.remove();
  });
  
  // Auto close after 5 seconds
  setTimeout(() => {
    if (notification.parentNode) {
      notification.remove();
    }
  }, 5000);
  
  return notification;
}

// Create custom confirm dialog that matches the theme
function showCustomConfirm(message, onConfirm, onCancel) {
  // Remove any existing confirm dialog
  const existingDialog = document.getElementById('custom-confirm-dialog');
  if (existingDialog) {
    existingDialog.remove();
  }
  
  // Create overlay
  const overlay = document.createElement('div');
  overlay.style.position = 'fixed';
  overlay.style.top = '0';
  overlay.style.left = '0';
  overlay.style.right = '0';
  overlay.style.bottom = '0';
  overlay.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
  overlay.style.zIndex = '9999';
  overlay.style.display = 'flex';
  overlay.style.alignItems = 'center';
  overlay.style.justifyContent = 'center';
  overlay.id = 'custom-confirm-dialog';
  
  // Create dialog
  const dialog = document.createElement('div');
  dialog.style.backgroundColor = '#1D232A'; // Dark theme background
  dialog.style.color = 'white';
  dialog.style.borderRadius = '8px';
  dialog.style.padding = '24px';
  dialog.style.maxWidth = '90%';
  dialog.style.width = '400px';
  dialog.style.boxShadow = '0 4px 20px rgba(0, 0, 0, 0.3)';
  dialog.style.border = '1px solid rgba(255, 255, 255, 0.1)';
  dialog.style.backdropFilter = 'blur(4px)';
  
  // Add content
  dialog.innerHTML = `
      <div style="display: flex; align-items: center; margin-bottom: 16px;">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <h3 style="font-weight: 600; font-size: 18px;">Confirmation</h3>
    </div>
    <p style="margin-bottom: 24px;">${message}</p>
    <div style="display: flex; justify-content: flex-end; gap: 12px;">
      <button id="cancel-btn" style="background-color: rgba(255, 255, 255, 0.1); color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">Cancel</button>
      <button id="confirm-btn" style="background-color: #F87272; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">Confirm</button>
    </div>
    `;
  
  // Add dialog to overlay
  overlay.appendChild(dialog);
  
  // Add to document
  document.body.appendChild(overlay);
  
  // Add event listeners
  document.getElementById('confirm-btn').addEventListener('click', function() {
    if (typeof onConfirm === 'function') {
      onConfirm();
    }
    overlay.remove();
  });
  
  document.getElementById('cancel-btn').addEventListener('click', function() {
    if (typeof onCancel === 'function') {
      onCancel();
    }
    overlay.remove();
  });
  
  // Close on overlay click
  overlay.addEventListener('click', function(e) {
    if (e.target === overlay) {
      if (typeof onCancel === 'function') {
        onCancel();
      }
      overlay.remove();
    }
  });
  
  return dialog;
}

// Function to handle cancel booking
function handleCancelBooking(garageId) {
  console.log("Cancelling booking for garage ID:", garageId);
  
  // Use custom confirm dialog
  showCustomConfirm('Are you sure you want to cancel this booking?', 
    function() { // onConfirm
      // Show loading notification
      const loadingNotification = showCustomNotification('Cancelling your booking...', 'info');
      
      // Send cancellation request
      fetch('cancel_booking.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'garage_id=' + encodeURIComponent(garageId)
      })
      .then(response => response.json())
      .then(data => {
        // Remove loading notification
        if (loadingNotification.parentNode) {
          loadingNotification.remove();
        }
        
        if (data.success) {
          // Show success notification
          showCustomNotification('Booking cancelled successfully!', 'success');
          setTimeout(() => window.location.reload(), 2000);
        } else {
          showCustomNotification(data.message || 'Error cancelling booking.', 'error');
        }
      })
      .catch(error => {
        // Remove loading notification
        if (loadingNotification.parentNode) {
          loadingNotification.remove();
        }
        
        console.error('Error:', error);
        showCustomNotification('Error cancelling booking. Please try again.', 'error');
      });
    }
  );
}

// Add global styles for map popups and timers
document.head.insertAdjacentHTML('beforeend', `
      <style>
  /* Map popup hover styles */
  .hover-booking-container {
      position: relative;
      width: 100%;
    }
  
  .booked-button {
      background-color: #36D399!important;
      color: white!important;
      width: 100%;
      padding: 0.75rem;
      border-radius: 0.5rem!important;
      font-weight: bold;
      cursor: default;
      position: relative;
      z-index: 1;
      border: none;
    }
  
  .cancel-button-overlay {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      background-color: #F87272;
      color: white;
      font-weight: bold;
      border-radius: 0.5rem;
      cursor: pointer;
      opacity: 0;
      transition: opacity 0.3s ease;
      z-index: 2;
    }
  
  .hover-booking-container:hover .cancel-button-overlay {
      opacity: 1;
    }

  /* Featured cards hover styles */
  .card-booking-container {
      position: relative;
      width: 100%;
      overflow: hidden;
    }
  
  .card-booked-button {
      background-color: #36D399;
      color: white;
      width: 100%;
      padding: 0.75rem;
      border-radius: 0.5rem;
      font-weight: bold;
      cursor: default;
      border: none;
    }
  
  .card-cancel-overlay {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      background-color: #F87272;
      color: white;
      font-weight: bold;
      border-radius: 0.5rem;
      cursor: pointer;
      opacity: 0;
      transition: opacity 0.3s ease;
    }
  
  .card-booking-container:hover .card-cancel-overlay {
      opacity: 1;
    }

  /* IMPROVED TIMER STYLES */
  .timer-container {
      margin: 8px 0;
      padding: 10px 12px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      font-size: 0.95rem;
      transition: all 0.3s ease;
      text-align: center;
      font-weight: medium;
    }

  /* Upcoming timer styling (blue) */
  .timer-upcoming {
      background-color: rgba(59, 130, 246, 0.3);
      color: black;
      border: 1px solid rgba(59, 130, 246, 0.5);
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

  /* Active timer styling (green) */
  .timer-active {
      background-color: rgba(16, 185, 129, 0.3);
      color: black;
      border: 1px solid rgba(16, 185, 129, 0.5);
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

  /* Warning state (less than 10 minutes) */
  .timer-warning {
      background-color: rgba(245, 158, 11, 0.3)!important;
      color: black!important;
      border: 1px solid rgba(245, 158, 11, 0.5)!important;
    }

  /* Critical state (less than 2 minutes) */
  .timer-critical {
      background-color: rgba(239, 68, 68, 0.3)!important;
      color: black!important;
      border: 1px solid rgba(239, 68, 68, 0.5)!important;
      animation: timer-pulse 1s infinite alternate;
    }

    /* Pulse animation for critical timers */
    @keyframes timer-pulse {
    from { opacity: 0.8; }
    to { opacity: 1; }
    }

  /* Make the countdown value more visible */
  .countdown-value {
      font-weight: 700;
      color: black;
    }

  /* Leaflet popup specific styles */
  .leaflet-popup-content .timer-container {
      width: 100%;
      margin-bottom: 12px;
      margin-top: 2px;
    }

  /* Make timer icon white */
  .timer-container svg {
      color: black;
      width: 18px;
      height: 18px;
    }
</style>
      `);

// Simple function to extract garage ID from button
function getGarageIdFromButton(button) {
  let garageId = null;
  
  // Try to get from onclick attribute
  if (button.getAttribute('onclick')) {
    const match = button.getAttribute('onclick').match(/openBookingModal\(['"]([^'"]+)['"]\)/);
    if (match && match[1]) {
      garageId = match[1];
    }
  }
  
  // Get from parent card data attribute
  if (!garageId) {
    const card = button.closest('.parking-card, .card');
    if (card && card.dataset && card.dataset.garageId) {
      garageId = card.dataset.garageId;
    }
  }
  
  // Last resort - check if we have a title element for Saba's BF
  if (!garageId) {
    const card = button.closest('.parking-card, .card');
    if (card) {
      const title = card.querySelector('h3, .card-title');
      if (title && title.textContent.includes("Saba's BF")) {
        garageId = "saba-bf"; // Just a fallback ID
      }
    }
  }
  
  // Log for debugging
  console.log("Extracted garage ID:", garageId);
  
  return garageId;
}

// Function to enhance map popups
function enhanceMapPopups() {
  // Find all Leaflet popups
  document.querySelectorAll('.leaflet-popup-content').forEach(popup => {
    // Log for debugging
    console.log("Processing popup:", popup);
    
    // Check if it contains a button
    const buttons = popup.querySelectorAll('button');
    buttons.forEach(button => {
      // Check if this is a Booked button and not already processed
      if (button.textContent.trim() === 'Booked' && !button.hasAttribute('data-processed')) {
        console.log("Found Booked button in popup:", button);
        
        // Mark as processed
        button.setAttribute('data-processed', 'true');
        
        // Get the garage ID
        const garageId = getGarageIdFromButton(button);
        
        // Create the hover container
        const container = document.createElement('div');
        container.className = 'hover-booking-container';
        
        // Clone the button
        const bookedBtn = document.createElement('button');
        bookedBtn.className = 'booked-button';
        bookedBtn.textContent = 'Booked';
        bookedBtn.disabled = true;
        
        // Create the cancel overlay
        const cancelBtn = document.createElement('div');
        cancelBtn.className = 'cancel-button-overlay';
        cancelBtn.textContent = 'Cancel Booking';
        
        // Add click handler to cancel button
        cancelBtn.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          console.log("Cancel button clicked for garageId:", garageId);
          if (garageId) {
            handleCancelBooking(garageId);
          } else {
            showCustomNotification("Could not identify the booking to cancel", "error");
          }
        });
        
        // Add to container
        container.appendChild(bookedBtn);
        container.appendChild(cancelBtn);
        
        // Get parent container
        const parentContainer = button.closest('.card-body, .p-2, div');
        
        // Before replacing, check if there's a timer we should preserve
        const existingTimer = parentContainer ? parentContainer.querySelector('.timer-container') : null;
        
        // Replace the button with our container
        if (button.parentNode) {
          button.parentNode.replaceChild(container, button);
        }
        
        // If no existing timer and we have a garage ID, check if we need to add a timer
        if (!existingTimer && garageId && parentContainer) {
          // Check booking status to create timer if needed
          checkBookingStatus(garageId, function(isBooked, bookingData) {
            if (isBooked && bookingData) {
              const timerContainer = createTimerElement(bookingData);
              if (timerContainer) {
                parentContainer.insertBefore(timerContainer, container);
                initializeTimer(timerContainer);
              }
            }
          });
        }
      }
    });
  });
}

// Function to create a timer element for a booking
function createTimerElement(bookingData) {
  if (!bookingData) return null;
  
  const timerContainer = document.createElement('div');
  
  // Determine if upcoming or active
  if (bookingData.status === 'upcoming') {
    timerContainer.className = 'timer-container timer-upcoming';
    timerContainer.setAttribute('data-type', 'upcoming');
    
    // Calculate start timestamp if needed
    let startTimestamp;
    if (bookingData.start_timestamp) {
      startTimestamp = bookingData.start_timestamp;
    } else if (bookingData.booking_date && bookingData.booking_time) {
      startTimestamp = new Date(bookingData.booking_date + ' ' + bookingData.booking_time).getTime() / 1000;
    } else if (bookingData.date && bookingData.time) {
      startTimestamp = new Date(bookingData.date + ' ' + bookingData.time).getTime() / 1000;
    }
    
    timerContainer.setAttribute('data-timestamp', startTimestamp);
    
    timerContainer.innerHTML = `
      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"></circle>
        <polyline points="12 6 12 12 16 14"></polyline>
      </svg>
      <span>Starts in: <span class="countdown-value">calculating...</span></span>
    `;
  } else { // active
    timerContainer.className = 'timer-container timer-active';
    timerContainer.setAttribute('data-type', 'active');
    
    // Calculate end timestamp
    let endTimestamp;
    if (bookingData.end_timestamp) {
      endTimestamp = bookingData.end_timestamp;
    } else if (bookingData.start_timestamp && bookingData.duration) {
      endTimestamp = bookingData.start_timestamp + (bookingData.duration * 3600);
    } else if (bookingData.date && bookingData.time && bookingData.duration) {
      const startTime = new Date(bookingData.date + ' ' + bookingData.time).getTime() / 1000;
      endTimestamp = startTime + (bookingData.duration * 3600);
    } else if (bookingData.booking_date && bookingData.booking_time && bookingData.duration) {
      const startTime = new Date(bookingData.booking_date + ' ' + bookingData.booking_time).getTime() / 1000;
      endTimestamp = startTime + (bookingData.duration * 3600);
    }
    
    timerContainer.setAttribute('data-timestamp', endTimestamp);
    
    timerContainer.innerHTML = `
      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"></circle>
        <polyline points="12 6 12 12 16 14"></polyline>
      </svg>
      <span>Ends in: <span class="countdown-value">calculating...</span></span>
    `;
  }
  
  return timerContainer;
}

// Initialize a timer with immediate update
function initializeTimer(container) {
  updateTimer(container);
}

// Check booking status for a garage
function checkBookingStatus(garageId, callback) {
  fetch(`check_booking_status.php?garage_id=${garageId}`)
    .then(response => response.json())
    .then(data => {
      if (data.success && data.has_booking) {
        callback(true, data.booking);
      } else {
        callback(false);
      }
    })
    .catch(error => {
      console.error('Error checking booking status:', error);
      callback(false);
    });
}

// Function to enhance featured parking cards
function enhanceFeaturedParkingCards() {
  // Find all "Booked" buttons in featured cards
  document.querySelectorAll('.parking-card button, .card button').forEach(button => {
    if (button.textContent.trim() === 'Booked' && !button.hasAttribute('data-enhanced')) {
      button.setAttribute('data-enhanced', 'true');
      
      // Get parent card container
      const card = button.closest('.parking-card, .card');
      if (!card) return;
      
      // Get garage ID
      const garageId = getGarageIdFromButton(button);
      if (!garageId) return;
      
      // Create wrapper for hover effect
      const container = document.createElement('div');
      container.className = 'card-booking-container';
      
      // Create booking button
      const bookedBtn = document.createElement('button');
      bookedBtn.className = 'card-booked-button';
      bookedBtn.textContent = 'Booked';
      bookedBtn.disabled = true;
      
      // Create cancel overlay
      const cancelOverlay = document.createElement('div');
      cancelOverlay.className = 'card-cancel-overlay';
      cancelOverlay.textContent = 'Cancel Booking';
      
      // Add click handler to cancel overlay
      cancelOverlay.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        if (garageId) {
          handleCancelBooking(garageId);
        } else {
          showCustomNotification("Could not identify the booking to cancel", "error");
        }
      });
      
      // Build the structure
      container.appendChild(bookedBtn);
      container.appendChild(cancelOverlay);
      
      // Get parent container
      const parentContainer = button.parentNode;
      
      // Before replacing, check if there's a timer we should preserve
      const existingTimer = parentContainer.querySelector('.timer-container');
      
      // Replace button with our enhanced container
      parentContainer.replaceChild(container, button);
      
      // If no existing timer and we have a garage ID, check if we need to add a timer
      if (!existingTimer && garageId) {
        // Check booking status to create timer if needed
        checkBookingStatus(garageId, function(isBooked, bookingData) {
          if (isBooked && bookingData) {
            const timerContainer = createTimerElement(bookingData);
            if (timerContainer) {
              parentContainer.insertBefore(timerContainer, container);
              initializeTimer(timerContainer);
            }
          }
        });
      }
    }
  });
}

// Function to format time remaining in human-readable format
function formatTimeRemaining(seconds) {
  if (seconds <= 0) return "0s";
  
  const days = Math.floor(seconds / 86400);
  seconds %= 86400;
  const hours = Math.floor(seconds / 3600);
  seconds %= 3600;
  const minutes = Math.floor(seconds / 60);
  const remainingSeconds = seconds % 60;
  
  if (days > 0) {
    return `${days}d ${hours}h ${minutes}m`;
  } else if (hours > 0) {
    return `${hours}h ${minutes}m ${remainingSeconds}s`;
  } else if (minutes > 0) {
    return `${minutes}m ${remainingSeconds}s`;
  } else {
    return `${remainingSeconds}s`;
  }
}

// Update a single timer
function updateTimer(container) {
  if (!container) return;
  
  // Get current time and target timestamp
  const now = Math.floor(Date.now() / 1000);
  const timestamp = parseInt(container.getAttribute('data-timestamp'));
  const timerType = container.getAttribute('data-type');
  const countdownEl = container.querySelector('.countdown-value');
  
  if (!countdownEl || !timestamp) return;
  
  // Calculate time remaining
  let timeRemaining = timestamp - now;
  
  // Handle expired timers
  if (timeRemaining <= 0) {
    if (timerType === 'upcoming') {
      countdownEl.textContent = "Starting now...";
      container.classList.add('timer-critical');
      
      // Refresh the page after a short delay
      if (!container.hasAttribute('data-refresh-scheduled')) {
        container.setAttribute('data-refresh-scheduled', 'true');
        setTimeout(() => location.reload(), 3000);
      }
    } else if (timerType === 'active') {
      countdownEl.textContent = "Just ended";
      container.classList.add('timer-critical');
      
      // Refresh the page after a short delay
      if (!container.hasAttribute('data-refresh-scheduled')) {
        container.setAttribute('data-refresh-scheduled', 'true');
        setTimeout(() => location.reload(), 3000);
      }
    }
    return;
  }
  
  // Format the time remaining
  const formattedTime = formatTimeRemaining(timeRemaining);
  countdownEl.textContent = formattedTime;
  
  // Update styling based on time remaining for active bookings
  if (timerType === 'active') {
    if (timeRemaining < 120) { // Less than 2 minutes
      container.classList.add('timer-critical');
      container.classList.remove('timer-warning');
    } else if (timeRemaining < 600) { // Less than 10 minutes
      container.classList.add('timer-warning');
      container.classList.remove('timer-critical');
    } else {
      container.classList.remove('timer-warning', 'timer-critical');
    }
  }
}

// Update all timers function
function updateAllTimers() {
  const timerContainers = document.querySelectorAll('.timer-container');
  timerContainers.forEach(updateTimer);
}

// Load active bookings for the current user
function loadActiveBookings() {
  fetch('get_active_bookings.php')
    .then(response => response.json())
    .then(data => {
      if (data.success && data.bookings && data.bookings.length > 0) {
        // Process each booking
        data.bookings.forEach(booking => {
          // Find all buttons for this garage to add timers
          const garageId = booking.garage_id;
          
          // Find buttons that might need timers
          const buttons = document.querySelectorAll(`button[onclick*="openBookingModal('${garageId}')"], button[onclick*='openBookingModal("${garageId}")']`);
          
          buttons.forEach(button => {
            // Get parent container
            const parentContainer = button.closest('.card-body, .p-2, div');
            if (!parentContainer) return;
            
            // Skip if there's already a timer
            if (parentContainer.querySelector('.timer-container')) return;
            
            // Create a timer element
            const timerContainer = createTimerElement(booking);
            if (timerContainer) {
              // Insert before the button
              parentContainer.insertBefore(timerContainer, button);
              initializeTimer(timerContainer);
            }
          });
        });
      }
    })
    .catch(error => {
      console.error('Error loading active bookings:', error);
    });
}

// Modified function to insert timer into popups
function processLeafletPopups() {
  // Find all popup contents
  document.querySelectorAll('.leaflet-popup-content').forEach(popup => {
    // Find booked buttons
    const bookedBtn = popup.querySelector('button');
    if (!bookedBtn || bookedBtn.textContent.trim() !== 'Booked') return;
    
    // Check if this popup already has a timer
    if (popup.querySelector('.timer-container')) return;
    
    // Try to get garage ID
    const garageId = getGarageIdFromButton(bookedBtn);
    if (!garageId) return;
    
    // Check booking status
    checkBookingStatus(garageId, function(isBooked, bookingData) {
      if (isBooked && bookingData) {
        const timerContainer = createTimerElement(bookingData);
        if (timerContainer) {
          // Insert before the button
          const parentContainer = bookedBtn.closest('.p-2, div');
          if (parentContainer) {
            parentContainer.insertBefore(timerContainer, bookedBtn);
            initializeTimer(timerContainer);
          }
        }
      }
    });
  });
}

// Run both enhancement functions when page loads
document.addEventListener('DOMContentLoaded', function() {
  console.log("DOM loaded, enhancing buttons");
  
  // Add the timer update function that already exists
  // Initialize timer updates
  updateAllTimers(); // Initial update
  setInterval(updateAllTimers, 1000); // Update every second
  
  // Load active bookings to show timers
  loadActiveBookings();
  
  // Initial run with a delay to ensure DOM is fully loaded
  setTimeout(function() {
    enhanceMapPopups();
    enhanceFeaturedParkingCards();
    processLeafletPopups(); // Process popups specifically
  }, 1000);
  
  // Set up event listeners for potential changes
  document.addEventListener('click', function() {
    setTimeout(function() {
      enhanceMapPopups();
      enhanceFeaturedParkingCards();
      processLeafletPopups();
    }, 500);
  });
  
  // Watch for map move events which might trigger new popups
  const mapElement = document.getElementById('map');
  if (mapElement) {
    mapElement.addEventListener('mousemove', function() {
      // Debounce by only running occasionally
      if (Math.random() < 0.05) { // Run about 5% of the time
        setTimeout(function() {
          enhanceMapPopups();
          processLeafletPopups();
        }, 500);
      }
    });
  }
  
  // Watch for carousel/slider movements
  const carouselElements = document.querySelectorAll('#parkingCarousel, .carousel, .slider');
  carouselElements.forEach(function(carousel) {
    carousel.addEventListener('transitionend', function() {
      setTimeout(enhanceFeaturedParkingCards, 300);
    });
  });
  
  // Update timers when popups open
  if (window.parkingMap) {
    window.parkingMap.on('popupopen', function() {
      setTimeout(function() {
        updateAllTimers();
        enhanceMapPopups();
        processLeafletPopups();
      }, 100);
    });
  }
  
  // Set up more aggressive timer updates for popups
  setInterval(processLeafletPopups, 1000);
});


// Function to create popup content with timer support
function createPopupContent(garage, isBooked, bookingStatus) {
    // Add timer HTML if this is a booked garage
    let timerHTML = '';
    let availabilityTimerHTML = ''; // New variable for availability timer
    let bookForLaterBtn = ''; // New variable for Book For Later button
    
    if (isBooked && bookingStatus) {
        // Current time in seconds
        const now = Math.floor(Date.now() / 1000);
        
        if (bookingStatus.status === 'upcoming') {
            // Calculate start time from booking info
            const startTime = new Date(bookingStatus.booking_date + ' ' + bookingStatus.booking_time).getTime() / 1000;
            const timeToStart = startTime - now;
            
            if (timeToStart > 0) {
                timerHTML = `
      <div class="timer-container timer-upcoming bg-blue-500/20 text-blue-200 p-2 rounded-md flex items-center justify-center gap-2 text-sm my-2" data-timestamp="${startTime}" data-type="upcoming">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span>Starts in: <span class="font-semibold countdown-value">calculating...</span></span>
                    </div>
      `;
            }
        } else if (bookingStatus.status === 'active') {
            // Calculate end time based on start time and duration
            const startTime = new Date(bookingStatus.booking_date + ' ' + bookingStatus.booking_time).getTime() / 1000;
            const endTime = startTime + (bookingStatus.duration * 3600);
            const timeToEnd = endTime - now;
            
            if (timeToEnd > 0) {
                timerHTML = `
      <div class="timer-container timer-active timer-pulse bg-green-500/20 text-green-200 p-2 rounded-md flex items-center justify-center gap-2 text-sm my-2" data-timestamp="${endTime}" data-type="active">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span>Ends in: <span class="font-semibold countdown-value">calculating...</span></span>
                    </div>
      `;
            }
        }
    }
    
    // Button styling based on booking status
    let buttonClass = "bg-primary hover:bg-orange-600";
    let buttonText = "Book Now";
    let buttonDisabled = "";
    
    if (isBooked) {
        buttonClass = "bg-success hover:bg-success";
        buttonText = "Booked";
        buttonDisabled = "disabled";
    } else if (garage.available <= 0) {
        buttonClass = "bg-error text-white";  // Changed from text-black to text-white
        buttonText = "Fully Booked";
        buttonDisabled = "disabled";
        
        // Add Book For Later button when a spot is fully booked
        bookForLaterBtn = `<button onclick="bookForLater('${garage.id}')" class="btn bg-blue-600 hover:bg-blue-700 text-white w-full mt-2 font-semibold transition-all duration-300">Book For Later</button>`;
        
        // Add availability timer if we have next available time data
        if (garage.nextAvailable && garage.nextAvailable.timestamp) {
            const nextAvailableTime = garage.nextAvailable.timestamp;
            const timeUntilAvailable = nextAvailableTime - Math.floor(Date.now() / 1000);
            
            if (timeUntilAvailable > 0) {
                availabilityTimerHTML = `
      <div class="timer-container timer-availability bg-blue-500/20 text-blue-800 p-2 rounded-md flex items-center justify-center gap-2 text-sm my-2" data-timestamp="${nextAvailableTime}" data-type="available-soon">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span>Available in: <span class="font-semibold countdown-value">calculating...</span></span>
                    </div>
      `;
            }
        }
    }
    
    // Status indicator
    let statusColor = 'bg-success';
    let statusText = `Available(${garage.available} spots)`;
    
    if (garage.available <= 0) {
        statusColor = 'bg-error';
        statusText = 'Full (0 spots)';
    } else if (garage.available < garage.capacity / 2) {
        statusColor = 'bg-warning';
        statusText = `Limited(${garage.available} spots)`;
    }
    
    // Format price
    const price = garage.price ? `৳${parseFloat(garage.price).toFixed(2)}/hr` : '৳45.00/hr';

    return `
        <div class="p-2" style="min-width: 240px;">
            <h3 class="font-bold text-lg">${garage.name}</h3>
            <p class="text-sm mb-2">${garage.address}</p>
            <div class="flex justify-between mb-2">
                <span class="text-sm">Type: ${garage.type || 'Standard'}</span>
                <span class="text-sm">Size: ${garage.dimensions || 'Standard'}</span>
            </div>
            <div class="flex justify-between items-center mb-2">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full ${statusColor}"></span>
                    <span class="text-sm">${statusText}</span>
                </div>
                <div class="text-primary font-semibold">${price}</div>
            </div>
            ${timerHTML}
            ${availabilityTimerHTML}
            <button onclick="openBookingModal('${garage.id}')" class="btn ${buttonClass} text-white w-full mt-1 font-semibold transition-all duration-300" ${buttonDisabled} style="color: white !important; font-weight: bold !important; ${garage.available <= 0 ? 'background-color: #F87272 !important;' : ''}">${buttonText}</button>
            ${bookForLaterBtn}
        </div>
    `;
}
    // Function to handle the "Book For Later" button click
    function bookForLater(garageId) {
      // Show a loading indicator
      showCustomNotification('Checking next available time...', 'info');

      // Fetch the next available time from the API
      fetch(`get_next_availability.php?garage_id=${garageId}`)
        .then(response => response.json())
        .then(data => {
          if (data.success && data.next_available) {
            // Extract date and time from the response
            const nextDate = data.next_available.date;
            const nextTime = data.next_available.time;

            // Open the booking modal and set the date and time
            openBookingModal(garageId, nextDate, nextTime);

            // Show a success notification
            showCustomNotification('Next available time slot loaded!', 'success');
          } else {
            // Show an error notification
            showCustomNotification('Unable to determine next available time', 'error');

            // Open the booking modal normally
            openBookingModal(garageId);
          }
        })
        .catch(error => {
          console.error('Error fetching next availability:', error);
          showCustomNotification('Error fetching availability data', 'error');

          // Open the booking modal normally as a fallback
          openBookingModal(garageId);
        });
    }
  </script>

<script>
  // "Fully Booked" buttons proper styling (comprehensive solution)
  document.addEventListener('DOMContentLoaded', function () {
    function fixFullyBookedElements() {
      // Regular elements
      document.querySelectorAll('div, span, p, a, button').forEach(element => {
        if (element.textContent.trim() === 'Fully Booked') {
          element.style.backgroundColor = '#F87272'; // bright red
          element.style.color = 'white';
          element.style.fontWeight = 'bold';
          element.style.opacity = '1';
          
          // Only add these styles to non-button elements (to avoid breaking button styling)
          if (element.tagName.toLowerCase() !== 'button') {
            element.style.padding = '12px 12px';
            element.style.borderRadius = '4px';
            element.style.textAlign = 'center';
            element.style.margin = '8px 0';
            element.style.display = 'block';
          }
        }
      });
      
      // Special handling for Leaflet popup buttons which need stronger styling
      document.querySelectorAll('.leaflet-popup-content button').forEach(button => {
        if (button.textContent.trim() === 'Fully Booked') {
          button.setAttribute('style', `
            background-color: #F87272 !important; 
            color: white !important;
            border-color: #F87272 !important;
            font-weight: bold !important;
            opacity: 1 !important;
          `);
        }
      });
    }
    
    // Run immediately
    fixFullyBookedElements();
    
    // Run when map popups open
    if (window.parkingMap) {
      window.parkingMap.on('popupopen', function() {
        setTimeout(fixFullyBookedElements, 100);
      });
    }
    
    // Run periodically to catch any new elements
    setInterval(fixFullyBookedElements, 1000);
  });
</script>

<script>
  // Fix button alignment and styling
  document.addEventListener('DOMContentLoaded', function() {
    function fixButtonAlignment() {
      // Find button containers
      document.querySelectorAll('.button-flex-container').forEach(container => {
        const buttons = container.querySelectorAll('button, a');
        
        // Make all buttons in container match height and styling
        buttons.forEach(btn => {
          btn.style.cssText += `
            height: 48px !important;
            padding: 0 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 8px !important;
            margin: 0 !important;
            font-weight: bold !important;
            font-size: 14px !important;
            flex: 1 !important;
          `;
        });
        
        // Specific adjustments for the container
        container.style.cssText += `
          display: flex !important;
          gap: 8px !important;
          margin-top: 12px !important;
          width: 100% !important;
          align-items: stretch !important;
        `;
      });
    }
    
    // Run immediately and then periodically
    fixButtonAlignment();
    setInterval(fixButtonAlignment, 500);
    
    // Also run when popups open
    if (window.parkingMap) {
      window.parkingMap.on('popupopen', function() {
        setTimeout(fixButtonAlignment, 100);
      });
    }
  });
</script>

<script>
console.log("🚀 NOTIFICATION SCRIPT STARTED");

document.addEventListener('DOMContentLoaded', function() {
    console.log("🎯 DOM LOADED - Setting up notification system...");
    
    // Get notification elements
    const notificationButton = document.getElementById('notification-button');
    const notificationDropdown = document.getElementById('notification-dropdown');
    const notificationContent = document.getElementById('notification-content');
    const markReadBtn = document.getElementById('mark-read-btn');
    
    console.log("Button found:", !!notificationButton);
    console.log("Dropdown found:", !!notificationDropdown);
    console.log("Content found:", !!notificationContent);
    console.log("Mark read btn found:", !!markReadBtn);
    
    if (notificationButton && notificationDropdown) {
        console.log("✅ Setting up notification click handler");
        
        // Toggle notification dropdown
        notificationButton.addEventListener('click', function(e) {
            console.log("🔔 NOTIFICATION BUTTON CLICKED!");
            e.preventDefault();
            e.stopPropagation();
            
            const isHidden = notificationDropdown.classList.contains('hidden');
            console.log("Dropdown currently hidden:", isHidden);
            
            if (isHidden) {
                console.log("📱 SHOWING dropdown");
                notificationDropdown.classList.remove('hidden');
                
                // Force the dropdown to be visible with inline styles as backup
                notificationDropdown.style.display = 'block';
                notificationDropdown.style.opacity = '1';
                notificationDropdown.style.zIndex = '99999';
                notificationDropdown.style.position = 'absolute';
                notificationDropdown.style.right = '0';
                notificationDropdown.style.top = '100%';
                notificationDropdown.style.marginTop = '8px';
                
                fetchNotifications(); // Load notifications when opened
            } else {
                console.log("🚫 HIDING dropdown");
                notificationDropdown.classList.add('hidden');
                notificationDropdown.style.display = 'none';
            }
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            if (!notificationButton.contains(event.target) && 
                !notificationDropdown.contains(event.target)) {
                console.log("📱 Closing dropdown - clicked outside");
                notificationDropdown.classList.add('hidden');
                notificationDropdown.style.display = 'none';
            }
        });
        
        // Prevent dropdown from closing when clicking inside it
        notificationDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });
        
        // Mark all as read functionality
        if (markReadBtn) {
            markReadBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log("🔴 Mark as read clicked");
                
                // Disable button during request
                markReadBtn.disabled = true;
                markReadBtn.innerHTML = 'Marking as read...';
                
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'action=mark_notifications_read'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log("✅ Notifications marked as read");
                        
                        // Update UI to show no notifications
                        const countElement = document.getElementById('notification-count');
                        if (countElement) {
                            countElement.style.animation = 'none';
                            countElement.style.transform = 'scale(0)';
                            setTimeout(() => countElement.remove(), 300);
                        }
                        
                        showEmptyState();
                        
                        // Close dropdown after a moment
                        setTimeout(() => {
                            notificationDropdown.classList.add('hidden');
                            notificationDropdown.style.display = 'none';
                        }, 1500);
                    }
                })
                .catch(error => {
                    console.error('Error marking notifications as read:', error);
                })
                .finally(() => {
                    // Re-enable button
                    markReadBtn.disabled = false;
                    markReadBtn.innerHTML = `
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="9 11 12 14 22 4"></polyline>
                            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                        </svg>
                        Mark All as Read
                    `;
                });
            });
        }
        
    } else {
        console.error("❌ Notification elements not found!");
        console.log("Available elements:", {
            button: !!notificationButton,
            dropdown: !!notificationDropdown,
            content: !!notificationContent,
            markBtn: !!markReadBtn
        });
    }
    
    // Function to fetch notifications
    function fetchNotifications() {
        if (!notificationContent) {
            console.error("❌ Notification content element not found!");
            return;
        }
        
        console.log("📡 Fetching notifications...");
        
        // Show loading state
        notificationContent.innerHTML = `
            <div class="p-6 text-center text-white/70">
                <div class="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-primary mx-auto mb-3"></div>
                <p>Loading notifications...</p>
            </div>
        `;
        
        // Fetch notifications via AJAX
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'action=get_notification_items'
        })
        .then(response => {
            console.log("📡 Response status:", response.status);
            return response.json();
        })
        .then(data => {
            console.log("📦 Notifications received:", data);
            if (data.success) {
                renderNotifications(data);
            } else {
                console.warn("⚠️ No notifications or error:", data);
                showEmptyState();
            }
        })
        .catch(error => {
            console.error('❌ Error fetching notifications:', error);
            showErrorState();
        });
    }
    
    // Function to render notifications
    function renderNotifications(data) {
        if (!notificationContent) return;
        
        const { bookings, verifications, payments } = data;
        let content = '';
        
        console.log("📊 Rendering notifications:", {
            bookings: bookings?.length || 0,
            verifications: verifications?.length || 0,
            payments: payments?.length || 0
        });
        
        // If no notifications, show empty state
        if ((!bookings || bookings.length === 0) && 
            (!verifications || verifications.length === 0) && 
            (!payments || payments.length === 0)) {
            showEmptyState();
            return;
        }
        
        // Display verifications
        if (verifications && verifications.length > 0) {
            content += `
                <div class="p-3 bg-gray-700 border-b border-gray-600">
                    <h4 class="font-semibold text-white text-sm">Account Status (${verifications.length})</h4>
                </div>
            `;
            
            verifications.forEach(verification => {
                let statusClass, statusText, message;
                
                if (verification.status === 'verified' || verification.is_verified === 1) {
                    statusClass = 'text-green-400';
                    statusText = 'verified';
                    message = 'Your account has been verified successfully!';
                } else if (verification.status === 'unverified' || verification.is_verified === 0) {
                    statusClass = 'text-yellow-400';
                    statusText = 'pending verification';
                    message = 'Your account requires verification. Please contact support.';
                } else {
                    statusClass = 'text-blue-400';
                    statusText = verification.status || 'under review';
                    message = `Your account status: ${statusText}`;
                }
                
                content += `
                    <div class="notification-item">
                        <div class="flex items-start space-x-3">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-white text-sm">Account Verification</p>
                                <p class="text-sm text-gray-300 mt-1">${message}</p>
                                <p class="text-xs ${statusClass} mt-1">Status: ${statusText}</p>
                            </div>
                        </div>
                    </div>
                `;
            });
        }
        
        // Display bookings
        if (bookings && bookings.length > 0) {
            content += `
                <div class="p-3 bg-gray-700 border-b border-gray-600">
                    <h4 class="font-semibold text-white text-sm">New Bookings (${bookings.length})</h4>
                </div>
            `;
            
            bookings.forEach(booking => {
                const customerName = booking.customer_name || booking.customer_username || 'Customer';
                const bookingDate = booking.booking_date_formatted || booking.booking_date;
                const bookingTime = booking.booking_time_formatted || booking.booking_time;
                
                content += `
                    <div class="notification-item">
                        <div class="flex items-start space-x-3">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-green-500 rounded-full flex items-center justify-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-white text-sm">${booking.parking_name || 'New Booking'}</p>
                                <p class="text-sm text-gray-300 mt-1">
                                    Booked by ${customerName}<br>
                                    ${bookingDate} at ${bookingTime}
                                </p>
                                <a href="booking.php" class="text-xs text-primary hover:underline mt-2 inline-block">View All Bookings</a>
                            </div>
                        </div>
                    </div>
                `;
            });
        }
        
        // Display payments
        if (payments && payments.length > 0) {
            content += `
                <div class="p-3 bg-gray-700 border-b border-gray-600">
                    <h4 class="font-semibold text-white text-sm">Payment Updates (${payments.length})</h4>
                </div>
            `;
            
            payments.forEach(payment => {
                const statusClass = payment.payment_status === 'paid' ? 'text-green-400' : 'text-yellow-400';
                const formattedDate = payment.payment_date_formatted || payment.payment_date;
                
                content += `
                    <div class="notification-item">
                        <div class="flex items-start space-x-3">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-yellow-500 rounded-full flex items-center justify-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1" />
                                    </svg>
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-white text-sm">৳${payment.amount || '0'} - ${payment.parking_name || 'Payment'}</p>
                                <p class="text-sm text-gray-300 mt-1">
                                    Payment <span class="${statusClass}">${payment.payment_status}</span><br>
                                    ${formattedDate}
                                </p>
                                <a href="payment_history.php" class="text-xs text-primary hover:underline mt-2 inline-block">View Payment History</a>
                            </div>
                        </div>
                    </div>
                `;
            });
        }
        
        if (content) {
            notificationContent.innerHTML = content;
        } else {
            showEmptyState();
        }
    }
    
    function showEmptyState() {
        if (notificationContent) {
            notificationContent.innerHTML = `
                <div class="p-8 text-center text-gray-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-gray-500 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-5 5v-5zM9 7h11a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9a2 2 0 012-2h2" />
                    </svg>
                    <p class="text-sm">No new notifications</p>
                    <p class="text-xs mt-1">You're all caught up!</p>
                </div>
            `;
        }
    }
    
    function showErrorState() {
        if (notificationContent) {
            notificationContent.innerHTML = `
                <div class="p-8 text-center text-gray-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-red-400 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <p class="text-sm">Failed to load notifications</p>
                    <button onclick="fetchNotifications()" class="text-xs text-primary hover:underline mt-2">Try again</button>
                </div>
            `;
        }
    }
    
    console.log("🏁 Notification system setup complete");
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Force create notification badge
    setTimeout(function() {
        const notificationButton = document.getElementById('notification-button');
        let badge = document.getElementById('notification-count');
       
        if (notificationButton && !badge) {
            console.log("Creating missing notification badge...");
           
            // Create badge element
            badge = document.createElement('span');
            badge.id = 'notification-count';
            badge.textContent = '1';
            badge.style.cssText = `
                position: absolute !important;
                top: -6px !important;
                right: -6px !important;
                background: linear-gradient(135deg, #f39c12, #e67e22) !important;
                color: white !important;
                border-radius: 50% !important;
                width: 20px !important;
                height: 20px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                font-size: 11px !important;
                font-weight: bold !important;
                z-index: 9999 !important;
                border: 2px solid #1a1a1a !important;
                box-shadow: 0 2px 8px rgba(243, 156, 18, 0.4) !important;
                visibility: visible !important;
                opacity: 1 !important;
                animation: pulse-orange 2s infinite !important;
            `;
           
            // Add to button
            notificationButton.appendChild(badge);
            console.log("Notification badge created and added!");
        } else if (badge) {
            console.log("Badge exists, forcing visibility...");
            badge.style.display = 'flex';
            badge.style.visibility = 'visible';
            badge.style.opacity = '1';
        }
    }, 1000);
});
</script>

<?php
// Check verification status
$username = $_SESSION['username'];
$query = "SELECT status FROM account_information WHERE username = '$username'";
$result = $conn->query($query);
$isVerified = false;
if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
    $isVerified = ($user['status'] === 'verified');
}
?>

<script>
// Store user verification status for client-side checks
document.body.setAttribute('data-user-verified', '<?php echo $isVerified ? 'true' : 'false'; ?>');
</script>

<script>
  // Add these functions to your existing JavaScript section in home.php

// Global variable to store current garage ID for reviews
let currentReviewGarageId = '';

// Function to show reviews modal
function showReviews(garageId) {
    currentReviewGarageId = garageId;
    
    // Show modal
    document.getElementById('reviewsModal').classList.remove('hidden');
    
    // Reset content to loading state
    document.getElementById('reviewsContent').innerHTML = `
        <div class="flex justify-center items-center py-8">
            <div class="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-primary"></div>
            <span class="ml-3 text-white">Loading reviews...</span>
        </div>
    `;
    
    document.getElementById('garageName').textContent = 'Loading...';
    
    // Fetch reviews
    fetchReviews(garageId);
}

// Function to fetch reviews via AJAX
function fetchReviews(garageId) {
    const formData = new FormData();
    formData.append('action', 'get_reviews');
    formData.append('garage_id', garageId);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayReviews(data);
        } else {
            showReviewsError(data.message || 'Failed to load reviews');
        }
    })
    .catch(error => {
        console.error('Error fetching reviews:', error);
        showReviewsError('Network error occurred');
    });
}

// Function to display reviews in modal
function displayReviews(data) {
    const garage = data.garage;
    const reviews = data.reviews;
    const summary = data.summary;
    
    // Update garage name
    if (garage) {
        document.getElementById('garageName').textContent = garage.Parking_Space_Name;
    }
    
    // Display rating summary
    displayRatingSummary(summary);
    
    // Display reviews
    const reviewsContainer = document.getElementById('reviewsContent');
    
    if (!reviews || reviews.length === 0) {
        reviewsContainer.innerHTML = `
            <div class="text-center py-8">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-white/50 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                </svg>
                <p class="text-white/70 text-lg">No reviews yet</p>
                <p class="text-white/50 text-sm mt-2">Be the first one to review this parking space!</p>
            </div>
        `;
        return;
    }
    
    let reviewsHTML = '';
    reviews.forEach(review => {
        const reviewerName = review.reviewer_name || review.rater_username || 'Anonymous';
        const reviewText = review.review_text || 'No comment provided.';
        const rating = parseFloat(review.rating) || 0;
        
        reviewsHTML += `
            <div class="bg-white/10 rounded-lg p-4 mb-4 border border-white/10">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-primary/20 rounded-full flex items-center justify-center">
                            <span class="text-primary font-bold">${reviewerName.charAt(0).toUpperCase()}</span>
                        </div>
                        <div>
                            <p class="text-white font-semibold">${reviewerName}</p>
                            <div class="flex items-center gap-1">
                                ${generateStarRatingJS(rating)}
                            </div>
                        </div>
                    </div>
                    <span class="text-white/60 text-sm">${review.formatted_date}</span>
                </div>
                <p class="text-white/90 leading-relaxed">${reviewText}</p>
            </div>
        `;
    });
    
    reviewsContainer.innerHTML = reviewsHTML;
}

// Function to display rating summary
function displayRatingSummary(summary) {
    const summaryContainer = document.getElementById('ratingSummary');
    
    if (!summary) {
        summaryContainer.innerHTML = `
            <div class="text-center">
                <p class="text-white/70">No ratings available</p>
            </div>
        `;
        return;
    }
    
    const totalRatings = summary.total_ratings || 0;
    const averageRating = parseFloat(summary.average_rating) || 0;
    
    summaryContainer.innerHTML = `
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="text-center">
                    <div class="text-3xl font-bold text-white">${averageRating.toFixed(1)}</div>
                    <div class="flex items-center justify-center gap-1 mb-1">
                        ${generateStarRatingJS(averageRating)}
                    </div>
                    <div class="text-white/70 text-sm">${totalRatings} review${totalRatings !== 1 ? 's' : ''}</div>
                </div>
            </div>
            <div class="flex-1 ml-6">
                ${generateRatingBars(summary)}
            </div>
        </div>
    `;
}

// Function to generate star rating in JavaScript
function generateStarRatingJS(rating) {
    let stars = '';
    const fullStars = Math.floor(rating);
    const hasHalfStar = (rating - fullStars) >= 0.5;
    
    // Full stars
    for (let i = 0; i < fullStars; i++) {
        stars += '<svg class="w-4 h-4 text-yellow-400 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>';
    }
    
    // Half star
    if (hasHalfStar) {
        stars += '<svg class="w-4 h-4 text-yellow-400" viewBox="0 0 20 20"><defs><linearGradient id="half-fill"><stop offset="50%" stop-color="currentColor"/><stop offset="50%" stop-color="transparent"/></linearGradient></defs><path fill="url(#half-fill)" d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>';
    }
    
    // Empty stars
    const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);
    for (let i = 0; i < emptyStars; i++) {
        stars += '<svg class="w-4 h-4 text-gray-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>';
    }
    
    return stars;
}

// Function to generate rating bars
function generateRatingBars(summary) {
    const total = summary.total_ratings || 0;
    if (total === 0) return '<p class="text-white/70 text-sm">No ratings yet</p>';
    
    const ratings = [
        { stars: 5, count: summary.five_star || 0 },
        { stars: 4, count: summary.four_star || 0 },
        { stars: 3, count: summary.three_star || 0 },
        { stars: 2, count: summary.two_star || 0 },
        { stars: 1, count: summary.one_star || 0 }
    ];
    
    let barsHTML = '';
    ratings.forEach(rating => {
        const percentage = total > 0 ? (rating.count / total) * 100 : 0;
        barsHTML += `
            <div class="flex items-center gap-2 mb-1">
                <span class="text-white/70 text-sm w-8">${rating.stars}★</span>
                <div class="flex-1 bg-white/20 rounded-full h-2">
                    <div class="bg-yellow-400 h-2 rounded-full" style="width: ${percentage}%"></div>
                </div>
                <span class="text-white/70 text-sm w-8">${rating.count}</span>
            </div>
        `;
    });
    
    return barsHTML;
}

// Function to close reviews modal
function closeReviewsModal() {
    document.getElementById('reviewsModal').classList.add('hidden');
    currentReviewGarageId = '';
}

// Function to show error in reviews
function showReviewsError(message) {
    document.getElementById('reviewsContent').innerHTML = `
        <div class="text-center py-8">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-red-400 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <p class="text-red-400 text-lg">${message}</p>
            <button onclick="fetchReviews(currentReviewGarageId)" class="btn btn-sm bg-primary hover:bg-primary-dark text-white mt-4">
                Try Again
            </button>
        </div>
    `;
}

// Function to open write review (placeholder for now)
function openWriteReview() {
    // You can implement this later for writing reviews
    alert('Write review functionality will be implemented next!');
    // For now, just close the modal
    closeReviewsModal();
}
</script>


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

<script>
  // Add this to your existing JavaScript in home.php

// Global variable to store user location
let userLocation = null;

// Function to calculate distance between two coordinates using Haversine formula
function calculateDistance(lat1, lon1, lat2, lon2) {
    const R = 6371; // Radius of the Earth in kilometers
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    const distance = R * c; // Distance in kilometers
    return distance;
}

// Function to format distance for display
function formatDistance(distanceKm) {
    if (distanceKm < 1) {
        return Math.round(distanceKm * 1000) + 'm';
    } else {
        return distanceKm.toFixed(1) + 'km';
    }
}

// Function to update distance displays in cards
function updateDistanceInCards() {
    if (!userLocation) return;
    
    // Get all parking cards
    const parkingCards = document.querySelectorAll('.parking-card');
    
    parkingCards.forEach(card => {
        // Try to find garage coordinates from the garage data
        const bookButton = card.querySelector('button[onclick*="openBookingModal"]');
        if (!bookButton) return;
        
        // Extract garage ID from button onclick
        const onclickAttr = bookButton.getAttribute('onclick');
        const garageIdMatch = onclickAttr.match(/openBookingModal\('([^']+)'\)/);
        if (!garageIdMatch) return;
        
        const garageId = garageIdMatch[1];
        
        // Find the garage in our data
        const garageData = dbGarageLocations.find(garage => garage.id === garageId);
        if (!garageData) return;
        
        // Calculate distance
        const distance = calculateDistance(
            userLocation.lat,
            userLocation.lng,
            parseFloat(garageData.lat),
            parseFloat(garageData.lng)
        );
        
        // Find or create distance display element
        let distanceElement = card.querySelector('.distance-display');
        if (!distanceElement) {
            // Create new distance element
            distanceElement = document.createElement('div');
            distanceElement.className = 'distance-display flex items-center gap-1 text-white/70 text-sm mt-1';
            distanceElement.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <span class="distance-text"></span>
            `;
            
            // Insert after the address element
            const addressElement = card.querySelector('.flex.items-center.gap-2.text-white\\/90.text-sm');
            if (addressElement && addressElement.parentNode) {
                addressElement.parentNode.insertBefore(distanceElement, addressElement.nextSibling);
            }
        }
        
        // Update distance text
        const distanceText = distanceElement.querySelector('.distance-text');
        if (distanceText) {
            distanceText.textContent = formatDistance(distance) + ' away';
        }
        
        // Add distance to garage data for sorting
        garageData.distance = distance;
    });
    
    // Optional: Sort cards by distance
    sortCardsByDistance();
}

// Function to sort parking cards by distance
function sortCardsByDistance() {
    if (!userLocation) return;
    
    const carousel = document.getElementById('parkingCarousel');
    if (!carousel) return;
    
    const cards = Array.from(carousel.querySelectorAll('.parking-card'));
    
    // Create array of cards with their distances
    const cardsWithDistance = cards.map(card => {
        const bookButton = card.querySelector('button[onclick*="openBookingModal"]');
        if (!bookButton) return { card, distance: Infinity };
        
        const onclickAttr = bookButton.getAttribute('onclick');
        const garageIdMatch = onclickAttr.match(/openBookingModal\('([^']+)'\)/);
        if (!garageIdMatch) return { card, distance: Infinity };
        
        const garageId = garageIdMatch[1];
        const garageData = dbGarageLocations.find(garage => garage.id === garageId);
        
        if (!garageData) return { card, distance: Infinity };
        
        const distance = calculateDistance(
            userLocation.lat,
            userLocation.lng,
            parseFloat(garageData.lat),
            parseFloat(garageData.lng)
        );
        
        return { card, distance };
    });
    
    // Sort by distance
    cardsWithDistance.sort((a, b) => a.distance - b.distance);
    
    // Reorder cards in carousel
    cardsWithDistance.forEach(({ card }) => {
        carousel.appendChild(card);
    });
    
    // Reset carousel position
    if (window.scrollToIndex) {
        window.scrollToIndex(0);
    }
}

// Function to update map markers with distance
function updateMapMarkersWithDistance() {
    if (!userLocation || !window.parkingMap) return;
    
    window.parkingMap.eachLayer(function(layer) {
        if (layer.getPopup && layer.getPopup()) {
            const popup = layer.getPopup();
            const content = popup.getContent();
            
            // Check if this is a garage marker
            if (content && content.includes('openBookingModal')) {
                // Extract garage ID from popup content
                const garageIdMatch = content.match(/openBookingModal\('([^']+)'\)/);
                if (!garageIdMatch) return;
                
                const garageId = garageIdMatch[1];
                const garageData = dbGarageLocations.find(garage => garage.id === garageId);
                if (!garageData) return;
                
                // Calculate distance
                const distance = calculateDistance(
                    userLocation.lat,
                    userLocation.lng,
                    parseFloat(garageData.lat),
                    parseFloat(garageData.lng)
                );
                
                // Update popup content with distance
                if (!content.includes('distance-info')) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(content, 'text/html');
                    const popupDiv = doc.querySelector('div');
                    
                    if (popupDiv) {
                        // Find the address paragraph and add distance after it
                        const addressP = popupDiv.querySelector('p');
                        if (addressP) {
                            const distanceDiv = document.createElement('div');
                            distanceDiv.className = 'distance-info flex items-center gap-1 text-sm mb-2 text-blue-600';
                            distanceDiv.innerHTML = `
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <span>${formatDistance(distance)} away</span>
                            `;
                            
                            addressP.parentNode.insertBefore(distanceDiv, addressP.nextSibling);
                            popup.setContent(popupDiv.outerHTML);
                        }
                    }
                }
            }
        }
    });
}

// Function to show nearest parking suggestions
function showNearestParkingSuggestions() {
    if (!userLocation) return;
    
    // Calculate distances for all garages
    const garagesWithDistance = dbGarageLocations.map(garage => {
        const distance = calculateDistance(
            userLocation.lat,
            userLocation.lng,
            parseFloat(garage.lat),
            parseFloat(garage.lng)
        );
        return { ...garage, distance };
    });
    
    // Sort by distance and get top 5
    const nearestGarages = garagesWithDistance
        .sort((a, b) => a.distance - b.distance)
        .slice(0, 5);
    
    // Create dark theme notification content
    let suggestionHTML = `
        <div class="nearest-parking-dark" style="
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 0;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(243, 156, 18, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
            overflow: hidden;
            max-width: 400px;
            width: 100%;
        ">
            <!-- Header -->
            <div style="
                background: linear-gradient(135deg, rgba(243, 156, 18, 0.15) 0%, rgba(0, 0, 0, 0.3) 100%);
                padding: 20px;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            ">
                <div style="display: flex; items-center; gap: 12px;">
                    <div style="
                        width: 48px;
                        height: 48px;
                        background: linear-gradient(135deg, #f39c12, #e67e22);
                        border-radius: 12px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        box-shadow: 0 4px 12px rgba(243, 156, 18, 0.3);
                    ">
                        <svg xmlns="http://www.w3.org/2000/svg" style="width: 24px; height: 24px; color: white;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <div>
                        <h4 style="
                            color: white;
                            font-size: 20px;
                            font-weight: 700;
                            margin: 0;
                            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
                        ">🅿️ Nearest Parking</h4>
                        <p style="
                            color: rgba(255, 255, 255, 0.7);
                            font-size: 14px;
                            margin: 0;
                        ">Found ${nearestGarages.length} spots nearby</p>
                    </div>
                </div>
            </div>
            
            <!-- Parking List -->
            <div style="padding: 20px; max-height: 350px; overflow-y: auto;">
    `;
    
    nearestGarages.forEach((garage, index) => {
        const isAvailable = garage.available > 0;
        const statusColor = isAvailable ? '#36D399' : '#F87272';
        const statusText = isAvailable ? `${garage.available} spots` : 'Full';
        const statusIcon = isAvailable ? '🟢' : '🔴';
        
        suggestionHTML += `
            <div onclick="focusOnSpecificParking('${garage.id}')" style="
                background: rgba(255, 255, 255, 0.05);
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 12px;
                padding: 16px;
                cursor: pointer;
                transition: all 0.3s ease;
                margin-bottom: 12px;
                position: relative;
                overflow: hidden;
            " onmouseover="this.style.transform='translateY(-2px)'; this.style.background='rgba(255,255,255,0.08)'; this.style.borderColor='rgba(243,156,18,0.3)'; this.style.boxShadow='0 8px 25px rgba(0,0,0,0.15)'" 
               onmouseout="this.style.transform='translateY(0)'; this.style.background='rgba(255,255,255,0.05)'; this.style.borderColor='rgba(255,255,255,0.1)'; this.style.boxShadow='none'">
                
                <!-- Rank Number -->
                <div style="
                    position: absolute;
                    top: 12px;
                    right: 12px;
                    width: 28px;
                    height: 28px;
                    background: linear-gradient(135deg, #f39c12, #e67e22);
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: white;
                    font-weight: bold;
                    font-size: 14px;
                    box-shadow: 0 2px 8px rgba(243, 156, 18, 0.3);
                ">#${index + 1}</div>
                
                <div style="margin-right: 40px;">
                    <!-- Parking Name -->
                    <div style="
                        color: white;
                        font-weight: 600;
                        font-size: 16px;
                        margin-bottom: 8px;
                        display: flex;
                        align-items: center;
                        gap: 8px;
                    ">
                        🅿️ ${garage.name}
                    </div>
                    
                    <!-- Distance & Status Row -->
                    <div style="
                        display: flex;
                        align-items: center;
                        gap: 16px;
                        margin-bottom: 8px;
                    ">
                        <div style="
                            display: flex;
                            align-items: center;
                            gap: 6px;
                            color: #f39c12;
                            font-size: 14px;
                            font-weight: 600;
                        ">
                            📍 ${formatDistance(garage.distance)} away
                        </div>
                        
                        <div style="
                            display: flex;
                            align-items: center;
                            gap: 6px;
                            color: ${statusColor};
                            font-size: 14px;
                            font-weight: 600;
                        ">
                            ${statusIcon} ${statusText}
                        </div>
                    </div>
                    
                    <!-- Details Row -->
                    <div style="
                        color: rgba(255, 255, 255, 0.6);
                        font-size: 13px;
                        display: flex;
                        align-items: center;
                        gap: 12px;
                    ">
                        <span>${garage.type || 'Standard'}</span>
                        <span>•</span>
                        <span>৳${parseFloat(garage.price || 45).toFixed(0)}/hr</span>
                    </div>
                </div>
                
                <!-- Click Arrow -->
                <div style="
                    position: absolute;
                    right: 16px;
                    bottom: 16px;
                    color: rgba(255, 255, 255, 0.4);
                ">
                    <svg xmlns="http://www.w3.org/2000/svg" style="width: 16px; height: 16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </div>
            </div>
        `;
    });
    
    suggestionHTML += `
            </div>
            
            <!-- Action Buttons with Centered Close Button -->
<div style="
    padding: 20px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.03);
">
    <div style="
        display: flex;
        justify-content: center; /* This centers the button */
        align-items: center;
        gap: 12px;
    ">
        <button onclick="document.getElementById('custom-notification').remove()" style="
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            padding: 14px 20px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 80px;
        " onmouseover="this.style.background='rgba(255, 255, 255, 0.15)'; this.style.color='white'"
            onmouseout="this.style.background='rgba(255, 255, 255, 0.1)'; this.style.color='rgba(255, 255, 255, 0.8)'">
            ✕ Close
        </button>
    </div>
</div>
        </div>
    `;
    
    // Show enhanced dark notification
    // Show enhanced dark notification without blue background
showEnhancedNotification(suggestionHTML);
}
// Custom notification function without blue background
function showEnhancedNotification(htmlContent) {
  // Remove any existing notification
  const existingNotification = document.getElementById('custom-notification');
  if (existingNotification) {
    existingNotification.remove();
  }

  // Create notification container
  const notification = document.createElement('div');
  notification.id = 'custom-notification';
  
  // Set styles for transparent wrapper
  notification.style.position = 'fixed';
  notification.style.top = '20px';
  notification.style.left = '50%';
  notification.style.transform = 'translateX(-50%)';
  notification.style.zIndex = '9999';
  notification.style.background = 'transparent';
  notification.style.border = 'none';
  notification.style.padding = '0';
  notification.style.margin = '0';
  
  // Set the HTML content directly
  notification.innerHTML = htmlContent;
  
  // Add to document
  document.body.appendChild(notification);
  
  // Auto close after 10 seconds
  setTimeout(() => {
    if (notification.parentNode) {
      notification.remove();
    }
  }, 10000);
  
  return notification;
}


// Function to focus on a specific parking spot (same functionality, different styling)
// Fixed function to focus on specific parking and auto-open popup
function focusOnSpecificParking(garageId) {
    if (!window.parkingMap) return;
    
    // Find the garage data
    const garage = dbGarageLocations.find(g => g.id === garageId);
    if (!garage) return;
    
    // Close the notification with animation
    const notification = document.getElementById('custom-notification');
    if (notification) {
        notification.style.opacity = '0';
        notification.style.transform = 'scale(0.95)';
        setTimeout(() => notification.remove(), 200);
    }
    
    // Focus on the specific parking spot with higher zoom
    window.parkingMap.flyTo([parseFloat(garage.lat), parseFloat(garage.lng)], 18, {
        duration: 1.2
    });
    
    // Multiple attempts to find and open the popup
    const tryOpenPopup = (attempts = 0) => {
        let popupOpened = false;
        
        // Method 1: Try to find marker by coordinates
        window.parkingMap.eachLayer(function(layer) {
            if (layer.getLatLng && layer.getPopup && !popupOpened) {
                const pos = layer.getLatLng();
                const latDiff = Math.abs(pos.lat - parseFloat(garage.lat));
                const lngDiff = Math.abs(pos.lng - parseFloat(garage.lng));
                
                // More precise coordinate matching
                if (latDiff < 0.0001 && lngDiff < 0.0001) {
                    layer.openPopup();
                    popupOpened = true;
                    console.log('Popup opened for:', garage.name);
                    return;
                }
            }
        });
        
        // Method 2: If popup not opened and we still have attempts, try again
        if (!popupOpened && attempts < 3) {
            setTimeout(() => tryOpenPopup(attempts + 1), 500);
        } else if (!popupOpened) {
            // Method 3: Create a temporary popup if marker popup fails
            const tempPopup = L.popup()
                .setLatLng([parseFloat(garage.lat), parseFloat(garage.lng)])
                .setContent(`
                    <div style="text-align: center; padding: 8px;">
                        <h3 style="margin: 0 0 8px 0; color: #333; font-size: 16px;">${garage.name}</h3>
                        <p style="margin: 0 0 8px 0; color: #666; font-size: 14px;">📍 ${formatDistance(garage.distance)} away</p>
                        <p style="margin: 0 0 12px 0; color: #666; font-size: 13px;">${garage.available > 0 ? `🟢 ${garage.available} spots available` : '🔴 Full'}</p>
                        <button onclick="openBookingModal('${garage.id}')" style="
                            background: #f39c12; 
                            color: white; 
                            border: none; 
                            padding: 8px 16px; 
                            border-radius: 6px; 
                            cursor: pointer; 
                            font-weight: bold;
                            font-size: 14px;
                        ">${garage.available > 0 ? 'Book Now' : 'Fully Booked'}</button>
                    </div>
                `)
                .openOn(window.parkingMap);
            
            console.log('Created temporary popup for:', garage.name);
        }
    };
    
    // Start trying to open popup after map animation
    setTimeout(() => tryOpenPopup(), 1300);
    
    // Show a themed success message
    setTimeout(() => {
        showCustomNotification(`📍 Focused on ${garage.name} - ${formatDistance(garage.distance)} away`, 'success');
    }, 1500);
}

// Alternative enhanced version with better marker detection
function focusOnSpecificParkingEnhanced(garageId) {
    if (!window.parkingMap) return;
    
    // Find the garage data
    const garage = dbGarageLocations.find(g => g.id === garageId);
    if (!garage) return;
    
    // Close the notification
    const notification = document.getElementById('custom-notification');
    if (notification) {
        notification.style.opacity = '0';
        notification.style.transform = 'scale(0.95)';
        setTimeout(() => notification.remove(), 200);
    }
    
    // Store the target coordinates
    const targetLat = parseFloat(garage.lat);
    const targetLng = parseFloat(garage.lng);
    
    // Focus on the specific parking spot
    window.parkingMap.flyTo([targetLat, targetLng], 18, {
        duration: 1.2
    });
    
    // Enhanced popup detection and opening
    setTimeout(() => {
        let markerFound = false;
        
        // Get all markers on the map
        window.parkingMap.eachLayer(function(layer) {
            // Check if this is a marker with popup
            if (layer instanceof L.Marker && layer.getPopup) {
                const markerPos = layer.getLatLng();
                
                // Check if coordinates match (with tolerance)
                if (Math.abs(markerPos.lat - targetLat) < 0.001 && 
                    Math.abs(markerPos.lng - targetLng) < 0.001) {
                    
                    // Found the marker, open its popup
                    layer.openPopup();
                    markerFound = true;
                    
                    // Add a slight bounce effect to the marker
                    const icon = layer.getElement();
                    if (icon) {
                        icon.style.animation = 'bounce 0.6s ease-in-out';
                        setTimeout(() => {
                            if (icon.style) icon.style.animation = '';
                        }, 600);
                    }
                    
                    console.log('Successfully opened popup for:', garage.name);
                    return false; // Break the loop
                }
            }
        });
        
        // If no marker found, create a custom popup
        if (!markerFound) {
            console.log('Creating custom popup for:', garage.name);
            
            const customPopup = L.popup({
                closeButton: true,
                autoClose: false,
                className: 'custom-parking-popup'
            })
            .setLatLng([targetLat, targetLng])
            .setContent(`
                <div style="min-width: 200px; text-align: center; padding: 12px;">
                    <h3 style="margin: 0 0 10px 0; color: #333; font-size: 18px; font-weight: bold;">${garage.name}</h3>
                    <div style="margin-bottom: 10px;">
                        <span style="color: #f39c12; font-size: 14px; font-weight: 600;">📍 ${formatDistance(garage.distance)} away</span>
                    </div>
                    <div style="margin-bottom: 12px; font-size: 14px; color: #666;">
                        <div>Type: ${garage.type || 'Standard'} • Size: ${garage.dimensions || 'Standard'}</div>
                        <div style="margin-top: 4px;">
                            <span style="color: ${garage.available > 0 ? '#36D399' : '#F87272'}; font-weight: bold;">
                                ${garage.available > 0 ? `🟢 ${garage.available} spots available` : '🔴 Full (0 spots)'}
                            </span>
                        </div>
                        <div style="margin-top: 4px; color: #f39c12; font-weight: bold;">
                            ৳${parseFloat(garage.price || 45).toFixed(0)}/hr
                        </div>
                    </div>
                    ${garage.available > 0 ? 
                        `<button onclick="openBookingModal('${garage.id}')" style="
                            background: linear-gradient(135deg, #f39c12, #e67e22);
                            color: white;
                            border: none;
                            padding: 10px 20px;
                            border-radius: 8px;
                            cursor: pointer;
                            font-weight: bold;
                            font-size: 14px;
                            box-shadow: 0 2px 8px rgba(243, 156, 18, 0.3);
                            transition: all 0.3s ease;
                        " onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform='translateY(0)'">
                            Book Now
                        </button>` : 
                        `<button disabled style="
                            background: #F87272;
                            color: white;
                            border: none;
                            padding: 10px 20px;
                            border-radius: 8px;
                            font-weight: bold;
                            font-size: 14px;
                            opacity: 0.8;
                        ">
                            Fully Booked
                        </button>`
                    }
                </div>
            `)
            .openOn(window.parkingMap);
        }
    }, 1300);
    
    // Show success message
    setTimeout(() => {
        showCustomNotification(`📍 Focused on ${garage.name} - ${formatDistance(garage.distance)} away`, 'success');
    }, 1500);
}

// Add bounce animation CSS
const bounceStyles = document.createElement('style');
bounceStyles.textContent = `
    @keyframes bounce {
        0%, 20%, 60%, 100% {
            transform: translateY(0);
        }
        40% {
            transform: translateY(-10px);
        }
        80% {
            transform: translateY(-5px);
        }
    }
    
    .custom-parking-popup .leaflet-popup-content-wrapper {
        background: rgba(0, 0, 0, 0.9);
        border-radius: 12px;
        border: 1px solid rgba(243, 156, 18, 0.3);
    }
    
    .custom-parking-popup .leaflet-popup-content {
        margin: 0;
        padding: 0;
    }
    
    .custom-parking-popup .leaflet-popup-tip {
        background: rgba(0, 0, 0, 0.9);
        border: 1px solid rgba(243, 156, 18, 0.3);
    }
`;
document.head.appendChild(bounceStyles)

// Enhanced "Locate Me" functionality
document.addEventListener('DOMContentLoaded', function() {
    const locateMeBtn = document.getElementById('locateMe');
    if (locateMeBtn) {
        // Remove existing event listener and add enhanced one
        locateMeBtn.onclick = null;
        
        locateMeBtn.addEventListener('click', function() {
            console.log("Enhanced locate me clicked");
            
            if (!navigator.geolocation) {
                showCustomNotification("Geolocation is not supported by this browser", "error");
                return;
            }
            
            // Show loading state
            locateMeBtn.innerHTML = `
                <div class="animate-spin rounded-full h-4 w-4 border-t-2 border-b-2 border-white"></div>
                Locating...
            `;
            locateMeBtn.disabled = true;
            
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    const accuracy = position.coords.accuracy;
                    
                    // Store user location globally
                    userLocation = { lat, lng };
                    
                    console.log(`Location found: ${lat}, ${lng} (±${accuracy}m)`);
                    
                    // Fly to user location on map
                    if (window.parkingMap) {
                        window.parkingMap.flyTo([lat, lng], 15);
                        
                        // Add user location marker
                        const userIcon = L.icon({
                            iconUrl: 'data:image/svg+xml;base64,' + btoa(`
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#3B82F6" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10" fill="#3B82F6" fill-opacity="0.2"/>
                                    <circle cx="12" cy="12" r="3" fill="#3B82F6"/>
                                </svg>
                            `),
                            iconSize: [24, 24],
                            iconAnchor: [12, 12],
                            popupAnchor: [0, -12]
                        });
                        
                        L.marker([lat, lng], { icon: userIcon })
                            .addTo(window.parkingMap)
                            .bindPopup("📍 You are here!")
                            .openPopup();
                    }
                    
                    // Update distances in cards and map
                    updateDistanceInCards();
                    updateMapMarkersWithDistance();
                    
                    // Show nearest parking suggestions
                    setTimeout(() => {
                        showNearestParkingSuggestions();
                    }, 1000);
                    
                    // Update location status
                    const statusDiv = document.getElementById('locationStatus');
                    const messageSpan = document.getElementById('locationMessage');
                    const accuracySpan = document.getElementById('locationAccuracy');
                    
                    if (statusDiv && messageSpan && accuracySpan) {
                        statusDiv.classList.remove('hidden');
                        messageSpan.innerText = '📍 Location found! Distances updated.';
                        accuracySpan.innerText = `Accuracy: ±${Math.round(accuracy)} meters`;
                    }
                    
                    // Success notification
                    showCustomNotification(`Location found! Showing distances to parking spots (±${Math.round(accuracy)}m accuracy)`, "success");
                },
                function(error) {
                    console.error("Geolocation error:", error);
                    let errorMessage = "Unable to get your location. ";
                    
                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            errorMessage += "Please allow location access.";
                            break;
                        case error.POSITION_UNAVAILABLE:
                            errorMessage += "Location information unavailable.";
                            break;
                        case error.TIMEOUT:
                            errorMessage += "Location request timed out.";
                            break;
                        default:
                            errorMessage += "Unknown error occurred.";
                            break;
                    }
                    
                    showCustomNotification(errorMessage, "error");
                },
                { 
                    enableHighAccuracy: true, 
                    timeout: 10000, 
                    maximumAge: 60000 
                }
            );
            
            // Reset button after a delay
            setTimeout(() => {
                locateMeBtn.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <circle cx="12" cy="12" r="1"></circle>
                        <line x1="12" y1="2" x2="12" y2="4"></line>
                        <line x1="12" y1="20" x2="12" y2="22"></line>
                        <line x1="2" y1="12" x2="4" y2="12"></line>
                        <line x1="20" y1="12" x2="22" y2="12"></line>
                    </svg>
                    Locate Me
                `;
                locateMeBtn.disabled = false;
            }, 3000);
        });
    }
});

// Add CSS for distance display
const distanceStyles = document.createElement('style');
distanceStyles.textContent = `
    .distance-display {
        opacity: 0;
        animation: fadeIn 0.5s ease-in-out forwards;
        animation-delay: 0.3s;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .distance-display .distance-text {
        color: #60A5FA;
        font-weight: 600;
    }
    
    .distance-info {
        background: rgba(59, 130, 246, 0.1);
        padding: 4px 8px;
        border-radius: 4px;
        border: 1px solid rgba(59, 130, 246, 0.3);
    }
`;
document.head.appendChild(distanceStyles);
</script>
</body>

</html>