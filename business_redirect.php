<?php
// Start the session at the very top
session_start();

// Include database connection file if exists, otherwise define connection manually
if (file_exists("connection.php")) {
    require_once("connection.php");
    // Get database connection using the imported function
    $conn = $GLOBALS['conn'] ?? null;
    
    // If connection.php doesn't set $conn as a global, try creating it
    if (!$conn) {
        if (function_exists('connect_db')) {
            $conn = connect_db();
        }
    }
} else {
    // Database connection function as fallback
    function connect_db() {
        $servername = "localhost";
        $username = "root";
        $password = "";
        $dbname = "car_parking_db_new";
       
        // Create connection
        $conn = new mysqli($servername, $username, $password, $dbname);
       
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }
       
        return $conn;
    }
    
    // Get database connection
    $conn = connect_db();
}

// Make sure we have a valid connection
if (!isset($conn) || !$conn) {
    die("Failed to establish database connection. Please check your settings.");
}

// Check if user is logged in, redirect to login page if not
if (!isset($_SESSION['username']) && !isset($_SESSION['user_id']) && !isset($_SESSION['login_username'])) {
    header("Location: login.php");
    exit();
}

// Preserve all session variables
// First get all existing session data
$username = $_SESSION['login_username'] ?? $_SESSION['username'] ?? ($_SESSION['user_name'] ?? '');
$userId = $_SESSION['user_id'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'User';
$fullName = $_SESSION['fullName'] ?? $_SESSION['user_name'] ?? $username;
$email = $_SESSION['email'] ?? '';

// Make sure all session variables are properly set before checking
$_SESSION['username'] = $username;
$_SESSION['login_username'] = $username;

// Function to check if specific user has already registered a garage
function userHasGarage($username, $conn) {
    // Check if the user has a garage in garage_information table using username
    $sql = "SELECT COUNT(*) as garage_count FROM garage_information WHERE username = ?";
    $stmt = $conn->prepare($sql);
   
    if (!$stmt) {
        error_log("Error in prepare statement: " . $conn->error);
        return false;
    }
   
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
   
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row['garage_count'] > 0) {
            // User has at least one garage
            return true;
        }
    }
   
    // No garage found for this user
    return false;
}

// Function to check user's owner type (normal, dual, or garage owner)
function getUserType($username, $conn) {
    $userType = "normal_user"; // Default
    
    // Check if user is in garage_owners table
    $checkGarageOwner = "SELECT owner_id FROM garage_owners WHERE username = ?";
    $stmt = $conn->prepare($checkGarageOwner);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $userType = "garage_owner";
        return $userType;
    }
    
    // Check if user is in dual_user table
    $checkDualUser = "SELECT owner_id FROM dual_user WHERE username = ?";
    $stmt = $conn->prepare($checkDualUser);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $userType = "dual_user";
        return $userType;
    }
    
    return $userType;
}

// Function to get user's default dashboard preference
function getDefaultDashboard($username, $conn) {
    $defaultDashboard = "user"; // Default
    
    $query = "SELECT default_dashboard FROM account_information WHERE username = ?";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        return $defaultDashboard;
    }
    
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $defaultDashboard = $row['default_dashboard'] ?? 'user';
    }
    
    return $defaultDashboard;
}

// Make sure we have a user ID before checking for garages
if (!isset($_SESSION['user_id']) && $username) {
    // Try to fetch user ID from database if not available in session
    $stmt = $conn->prepare("SELECT * FROM personal_information WHERE email LIKE ? OR firstName LIKE ? OR lastName LIKE ?");
    $search = "%$username%";
    $stmt->bind_param("sss", $search, $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();
   
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $_SESSION['user_id'] = $row['id'] ?? ('USR' . rand(10000, 99999)); // Use existing ID if available, otherwise generate
        $_SESSION['fullName'] = $row['firstName'] . ' ' . $row['lastName'];
        $_SESSION['email'] = $row['email'];
        $userId = $_SESSION['user_id']; // Update local variable with new session value
    } else {
        // If no user found, create a generic ID
        $_SESSION['user_id'] = 'USR' . rand(10000, 99999);
        $userId = $_SESSION['user_id']; // Update local variable
    }
}

// Now check if the user has any registered garages and get their user type
$hasGarage = userHasGarage($username, $conn);
$userType = getUserType($username, $conn);
$defaultDashboard = getDefaultDashboard($username, $conn);

// Update session with user info
$_SESSION['has_garage'] = $hasGarage;
$_SESSION['user_type'] = $userType;
$_SESSION['default_dashboard'] = $defaultDashboard;

// Handle URL parameters
$requestedDashboard = $_GET['dashboard'] ?? null;

// Close the database connection
$conn->close();

// Make sure all session variables are properly set before redirecting
if (!isset($_SESSION['username']) && isset($_SESSION['user_name'])) {
    $_SESSION['username'] = $_SESSION['user_name'];
}

// Log the state for debugging if needed
error_log("User: $username, HasGarage: " . ($hasGarage ? 'Yes' : 'No') . ", UserType: $userType, RequestedDashboard: " . ($requestedDashboard ?? 'None'));

// Store the current page URL to detect page refresh
$current_url = "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$_SESSION['last_url'] = $current_url;

// SIMPLIFIED LOGIC THAT MATCHES THE ORIGINAL BEHAVIOR
// Basic check: If user has garage -> business dashboard, if not -> registration page
if ($hasGarage) {
    header("Location: business_desh.php");
    exit();
} else {
    header("Location: reg_for_business.php");
    exit();
}

/* 
// Original complex routing logic - COMMENTING OUT
// Handle explicit dashboard requests from URL parameters
if ($requestedDashboard === 'business' || $requestedDashboard === 'user') {
    // User explicitly requested a specific dashboard
    if ($requestedDashboard === 'business') {
        if (!$hasGarage) {
            // Redirect to garage registration if requesting business dashboard but has no garage
            header("Location: reg_for_business.php");
            exit();
        } else {
            // Has garage and requested business dashboard
            header("Location: business_desh.php");
            exit();
        }
    } else if ($requestedDashboard === 'user') {
        // Explicitly requested user dashboard - always honor this
        header("Location: home.php");
        exit();
    }
} else {
    // No specific dashboard requested, use smarter default logic
    
    // For dual users - go to requested dashboard or the user's preference
    if ($userType === 'dual_user') {
        // For dual users - first check if they're coming from a specific page
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        
        if (strpos($referer, 'business_desh.php') !== false && isset($_GET['home'])) {
            // If coming from business dashboard and clicked home, go to user dashboard
            header("Location: home.php");
            exit();
        } else if (strpos($referer, 'home.php') !== false && isset($_GET['business'])) {
            // If coming from user dashboard and clicked business, go to business dashboard
            header("Location: business_desh.php");
            exit();
        } else {
            // Use default dashboard preference for dual users
            $targetDashboard = ($defaultDashboard === 'business') ? 'business_desh.php' : 'home.php';
            header("Location: $targetDashboard");
            exit();
        }
    } 
    // For dedicated garage owners - always go to business dashboard
    else if ($userType === 'garage_owner') {
        header("Location: business_desh.php");
        exit();
    } 
    // For regular users with garages
    else if ($hasGarage) {
        // Standard user who happens to have a garage
        // Honor their dashboard preference
        $targetDashboard = ($defaultDashboard === 'business') ? 'business_desh.php' : 'home.php';
        header("Location: $targetDashboard");
        exit();
    } 
    // For users without garages who want to register one
    else {
        // If they came to this page specifically for business registration
        if (isset($_GET['register'])) {
            header("Location: reg_for_business.php");
            exit();
        } else {
            // Default to regular user dashboard for users without garages
            header("Location: home.php");
            exit();
        }
    }
}
*/