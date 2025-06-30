<?php
// Move all PHP code to the top before ANY HTML output to prevent header issues
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Initialize variables
$error_message = "";
$success_message = "";

// Check if user is logged in
$isLoggedIn = isset($_SESSION['username']) && !empty($_SESSION['username']);
$username = $_SESSION['username'] ?? '';

// Variable to track if user is verified
$isVerified = false;

// Get user information
$fullName = $username; // Default to username
$userRole = "User"; // Default role
$email = ""; // Default empty email
$userType = ""; // Will track if user is already a garage owner or dual user
$ownerId = ""; // Will store owner ID if user is already an owner
$displayUsername = $username; // Initialize display username with session username

// Try to get user's personal information if logged in
if ($isLoggedIn) {
    // DATABASE CONNECTION - DIRECT METHOD
    $server = "localhost"; // Your MySQL server
    $db_username = "root"; // Your database username
    $db_password = ""; // Your database password
    $database = "car_parking_db_new"; // Your database name
    
    // Create connection directly
    $conn = new mysqli($server, $db_username, $db_password, $database);
    
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Check if user is verified
    $verifyQuery = "SELECT status FROM account_information WHERE username = ?";
    $stmt = $conn->prepare($verifyQuery);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $verifyResult = $stmt->get_result();
    
    if ($verifyResult && $verifyResult->num_rows > 0) {
        $verifyRow = $verifyResult->fetch_assoc();
        $isVerified = ($verifyRow['status'] === 'verified');
    }
    
    // More secure approach using prepared statement
    $query = "SELECT firstName, lastName, email FROM personal_information WHERE username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        // Explicitly concatenate name parts
        $firstName = isset($row['firstName']) ? trim($row['firstName']) : '';
        $lastName = isset($row['lastName']) ? trim($row['lastName']) : '';
        $fullName = $firstName . ' ' . $lastName;
        $email = $row['email'] ?? '';
    }
    
    // Check if user is already a garage owner
    $checkGarageOwner = "SELECT owner_id FROM garage_owners WHERE username = '$username'";
    $ownerResult = $conn->query($checkGarageOwner);
    
    if ($ownerResult && $ownerResult->num_rows > 0) {
        $userType = "garage_owner";
        $userRole = "Garage Owner";
        $ownerRow = $ownerResult->fetch_assoc();
        $ownerId = $ownerRow['owner_id'];
    } else {
        // Check if user is already a dual user
        $checkDualUser = "SELECT owner_id FROM dual_user WHERE username = '$username'";
        $dualResult = $conn->query($checkDualUser);
        
        if ($dualResult && $dualResult->num_rows > 0) {
            $userType = "dual_user";
            $userRole = "User & Garage Owner";
            $dualRow = $dualResult->fetch_assoc();
            $ownerId = $dualRow['owner_id'];
        } else {
            $userType = "normal_user";
            $userRole = "User";
        }
    }
    
    // Check account_information for default_dashboard preference
    $dashboardQuery = "SELECT default_dashboard FROM account_information WHERE username = '$username'";
    $dashResult = $conn->query($dashboardQuery);
    
    if ($dashResult && $dashResult->num_rows > 0) {
        $dashRow = $dashResult->fetch_assoc();
        $defaultDashboard = $dashRow['default_dashboard'] ?? 'user';
    }
    
    // Get first letter for avatar from full name
    $firstLetter = strtoupper(substr($fullName, 0, 1));
}

// Handle form submission
if (isset($_POST['submit']) && $isLoggedIn) {
    // DATABASE CONNECTION - DIRECT METHOD (if not already connected)
    if (!isset($conn)) {
        $server = "localhost"; 
        $db_username = "root"; 
        $db_password = ""; 
        $database = "car_parking_db_new";
        
        $conn = new mysqli($server, $db_username, $db_password, $database);
        
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }
        
        // Check if user is verified
        $verifyQuery = "SELECT status FROM account_information WHERE username = ?";
        $stmt = $conn->prepare($verifyQuery);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $verifyResult = $stmt->get_result();
        
        if ($verifyResult && $verifyResult->num_rows > 0) {
            $verifyRow = $verifyResult->fetch_assoc();
            $isVerified = ($verifyRow['status'] === 'verified');
        }
    }
    
    // Debug logging to file for troubleshooting
    file_put_contents('business_reg_debug.txt', 'Form submitted: ' . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    
    // Check if user is verified
    if (!$isVerified) {
        $error_message = "Your account needs to be verified before registering a garage. Please contact support.";
    } else {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            $error_message = "Invalid form submission";
        } else {
            // Validate required fields
            $required_fields = ['GarageName', 'ParkingLotAddress', 'ParkingType', 'ParkingDimensions', 'GarageSlots', 'PricingPerHour'];
            $is_valid = true;
            
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    $error_message = "All fields marked with * are required";
                    $is_valid = false;
                    break;
                }
            }
            
            if ($is_valid) {
                // Get form data and sanitize
                $garageName = mysqli_real_escape_string($conn, trim($_POST['GarageName']));
                $parkingLotAddress = mysqli_real_escape_string($conn, trim($_POST['ParkingLotAddress']));
                $parkingType = mysqli_real_escape_string($conn, trim($_POST['ParkingType']));
                $parkingSpaceDimensions = mysqli_real_escape_string($conn, trim($_POST['ParkingDimensions']));
                $garageSlots = intval($_POST['GarageSlots']);
                $pricingPerHour = floatval($_POST['PricingPerHour']);
                $latitude = !empty($_POST['latitude']) ? floatval($_POST['latitude']) : null;
                $longitude = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : null;
                
                // Set availability equal to garage slots for new registrations
                $availability = $garageSlots;
                
                // Validate data
                if ($garageSlots <= 0) {
                    $error_message = "Garage slots must be greater than zero";
                } elseif ($pricingPerHour <= 0) {
                    $error_message = "Price per hour must be greater than zero";
                } else {
                    try {
                        // Generate a unique garage_id
                        $garageIdPrefix = $username . "_G_";
                        
                        // Find the highest existing garage_id for this user
                        $countQuery = "SELECT MAX(CAST(SUBSTRING_INDEX(garage_id, '_G_', -1) AS UNSIGNED)) as max_id 
                                      FROM garage_information 
                                      WHERE username = '$username' AND garage_id LIKE '$garageIdPrefix%'";
                        $countResult = $conn->query($countQuery);
                        $countRow = $countResult->fetch_assoc();
                        
                        $nextId = 1;
                        if ($countRow && !is_null($countRow['max_id'])) {
                            $nextId = $countRow['max_id'] + 1;
                        }
                        
                        // Format with leading zeros (001, 002, etc.)
                        $garageId = $garageIdPrefix . sprintf("%03d", $nextId);
                        
                        // Start transaction to ensure both tables are updated together
                        $conn->begin_transaction();
                        
                        // If user is not already a garage owner or dual user, make them a dual user
                        if ($userType == "normal_user") {
                            $ownerId = 'U_owner_' . $username;
                            $registerOwnerQuery = "INSERT INTO dual_user (owner_id, username, is_verified, registration_date, account_status) 
                                                  VALUES ('$ownerId', '$username', 0, NOW(), 'active')";
                            $conn->query($registerOwnerQuery);
                            
                            // Update account_information table to set owner_id
                            $updateAccountQuery = "UPDATE account_information SET owner_id = '$ownerId' WHERE username = '$username'";
                            $conn->query($updateAccountQuery);
                            
                            // Set default commission rate for new dual user
                            $commissionQuery = "INSERT INTO owner_commissions (owner_id, owner_type, rate) VALUES ('$ownerId', 'dual', 30.00)";
                            $conn->query($commissionQuery);
                        }
                        
                        // Insert into garage_information table
                        $insertGarageQuery = "INSERT INTO garage_information (
                            username,
                            garage_id,
                            Parking_Space_Name, 
                            Parking_Lot_Address, 
                            Parking_Type, 
                            Parking_Space_Dimensions, 
                            Parking_Capacity, 
                            Availability, 
                            PriceperHour,
                            is_verified
                        ) VALUES (
                            '$username',
                            '$garageId',
                            '$garageName',
                            '$parkingLotAddress', 
                            '$parkingType', 
                            '$parkingSpaceDimensions', 
                            $garageSlots, 
                            $availability, 
                            $pricingPerHour,
                            0
                        )";
                        
                        $result = $conn->query($insertGarageQuery);
                        
                        if (!$result) {
                            throw new Exception("Error inserting garage data: " . $conn->error);
                        }
                        
                        // Now insert the location data if provided
                        if ($latitude !== null && $longitude !== null) {
                            $insertLocationQuery = "INSERT INTO garagelocation (garage_id, Latitude, Longitude, username) 
                                                  VALUES ('$garageId', $latitude, $longitude, '$username')";
                            $locResult = $conn->query($insertLocationQuery);
                            
                            if (!$locResult) {
                                throw new Exception("Error inserting location data: " . $conn->error);
                            }
                        }
                        
                        // If we got here, all operations were successful
                        $conn->commit();
                        
                        // Set success message
                        $success_message = "Garage registered successfully! Your garage will be verified by an admin soon. Redirecting to dashboard...";
                        
                        // Redirect after a short delay
                        echo "<script>
                            setTimeout(function() {
                                window.location.href = 'business_desh.php';
                            }, 3000);
                        </script>";
                        
                    } catch (Exception $e) {
                        // Roll back the transaction if an error occurred
                        $conn->rollback();
                        $error_message = "Error: " . $e->getMessage();
                        file_put_contents('business_reg_debug.txt', 'Error: ' . $e->getMessage() . "\n", FILE_APPEND);
                    }
                }
            }
        }
    }
}

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Close the database connection if open
if (isset($conn)) {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>পার্কিং লাগবে - Business Registration</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .card-glow {
            box-shadow: 0 0 10px 5px rgba(249, 140, 0, 0.5);
            border: 1px solid rgba(249, 140, 0, 0.3);
        }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#fff9eb',
                            100: '#ffefc7',
                            200: '#ffdb8a',
                            300: '#ffc14d',
                            400: '#ffa41c',
                            500: '#f98c00',
                            600: '#dd6b00',
                            700: '#b74d04',
                            800: '#943d0c',
                            900: '#7a340f',
                        },
                        dark: {
                            800: '#1a1a1a',
                            850: '#141414',
                            900: '#0f0f0f',
                            950: '#080808',
                        }
                    },
                    animation: {
                        'fadeIn': 'fadeIn 0.5s ease-in-out',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: 0 },
                            '100%': { opacity: 1 },
                        }
                    },
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Leaflet CSS for maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
</head>
<body class="bg-dark-900 min-h-screen flex items-center justify-center" style="font-family: 'Poppins', sans-serif;">
    <!-- Particle Animation -->
    <div id="particles-js" class="fixed inset-0 z-0"></div>
    
    <!-- Display error message if there is one -->
    <?php if (!empty($error_message)): ?>
    <div class="fixed top-4 right-4 bg-red-500 text-white px-4 py-2 rounded-md shadow-lg z-50 animate-fadeIn">
        <?php echo htmlspecialchars($error_message); ?>
        <button class="ml-2 text-white font-bold" onclick="this.parentElement.style.display='none'">&times;</button>
    </div>
    <?php endif; ?>
    
    <!-- Display success message if there is one -->
    <?php if (!empty($success_message)): ?>
    <div class="fixed top-4 right-4 bg-green-500 text-white px-4 py-2 rounded-md shadow-lg z-50 animate-fadeIn">
        <?php echo htmlspecialchars($success_message); ?>
        <button class="ml-2 text-white font-bold" onclick="this.parentElement.style.display='none'">&times;</button>
    </div>
    <?php endif; ?>

    <div class="w-full max-w-5xl bg-dark-850/80 backdrop-blur-md rounded-lg card-glow overflow-hidden z-10 my-8">
        <!-- User Identity Banner -->
        <?php if ($isLoggedIn): ?>
        <div class="bg-black/40 backdrop-blur-md border-b border-white/10 p-4">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-primary-500/20 border-2 border-primary-500 flex items-center justify-center">
                    <span class="text-xl font-bold text-primary-500"><?php echo $firstLetter; ?></span>
                </div>
                <div class="flex-1">
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <span class="text-white/70 text-xs">Name:</span>
                            <p class="text-white font-medium"><?php echo htmlspecialchars($fullName); ?></p>
                        </div>
                        <div>
                            <span class="text-white/70 text-xs">Role:</span>
                            <p class="text-white font-medium"><?php echo htmlspecialchars($userRole); ?></p>
                        </div>
                        <div>
                            <span class="text-white/70 text-xs">Username:</span>
                            <p class="text-white font-medium"><?php echo htmlspecialchars($displayUsername); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Registration Header -->
        <div class="text-center p-6">
            <h2 class="text-2xl font-bold text-white mb-2">পার্কিং লাগবে ??</h2>
            <h3 class="text-xl font-semibold text-white mb-2">Register Your Garage</h3>
            <p class="text-gray-400">Fill in the details to list your parking space</p>
        </div>
        
        <!-- Content area -->
        <div class="flex flex-col md:flex-row px-8 pb-6 gap-8">
            <?php if ($isLoggedIn && !$isVerified): ?>
                <!-- Show verification warning for logged in but unverified users -->
                <div class="w-full">
                    <div class="p-6 bg-yellow-500/20 border border-yellow-500/30 rounded-md mb-6 animate-fadeIn flex flex-col items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-yellow-500 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <h3 class="text-xl font-bold text-yellow-400 mb-2">Account Verification Required</h3>
                        <p class="text-yellow-300 text-center mb-6">Your account needs to be verified before registering a garage. Please contact support.</p>
                        <a href="home.php" class="btn bg-[#e67e00] hover:bg-[#d67200] text-white px-8 py-3 font-medium rounded transition-colors duration-300">
                            Go To Home
                        </a>
                    </div>
                </div>
            <?php elseif (!$isLoggedIn): ?>
                <!-- Show login required message -->
                <div class="w-full">
                    <div class="p-6 bg-blue-500/20 border border-blue-500/30 rounded-md mb-6 animate-fadeIn flex flex-col items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-blue-500 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                        </svg>
                        <h3 class="text-xl font-bold text-blue-400 mb-2">Login Required</h3>
                        <p class="text-blue-300 text-center mb-6">You need to be logged in to register a garage.</p>
                        <div class="flex gap-4">
                            <a href="login.php" class="btn bg-[#e67e00] hover:bg-[#d67200] text-white px-8 py-3 font-medium rounded transition-colors duration-300">
                                Login
                            </a>
                            <a href="home.php" class="btn bg-transparent border border-[#e67e00] hover:bg-[#e67e00]/10 text-[#e67e00] px-8 py-3 font-medium rounded transition-colors duration-300">
                                Go To Home
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Show registration form for verified users -->
                <!-- Left column: Form fields -->
                <div class="w-full md:w-1/2">
                    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST" id="garage-form">
                        <!-- CSRF Protection -->
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        
                        <div class="mb-4">
                            <label for="GarageName" class="block text-sm font-medium text-white mb-1">Garage Name*</label>
                            <input type="text" id="GarageName" name="GarageName" 
                                class="w-full p-3 bg-white/10 border border-white/20 rounded-md text-white placeholder-white/50 focus:ring-2 focus:ring-primary-500 focus:border-transparent transition-all"
                                placeholder="Enter garage name" required>
                        </div>
                        
                        <div class="mb-4">
                            <label for="ParkingLotAddress" class="block text-sm font-medium text-white mb-1">Parking Lot Address*</label>
                            <input type="text" id="ParkingLotAddress" name="ParkingLotAddress" 
                                class="w-full p-3 bg-white/10 border border-white/20 rounded-md text-white placeholder-white/50 focus:ring-2 focus:ring-primary-500 focus:border-transparent transition-all"
                                placeholder="Enter full address" required>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4 mb-4">
    <div class="relative z-50">
        <label for="ParkingType" class="block text-sm font-medium text-white mb-1">Parking Type*</label>
        <select id="ParkingType" name="ParkingType" 
            class="w-full p-3 bg-gray-800 border border-gray-600 rounded-md text-white focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-all appearance-none cursor-pointer relative z-50"
            style="background-color: #1f2937 !important; color: white !important; position: relative; z-index: 1000;"
            required>
            <option value="" disabled selected style="background-color: #1f2937 !important; color: #9ca3af !important;">Select type</option>
            <option value="Covered" style="background-color: #1f2937 !important; color: white !important; padding: 8px;">Covered</option>
            <option value="Open" style="background-color: #1f2937 !important; color: white !important; padding: 8px;">Open</option>
            <option value="Valet" style="background-color: #1f2937 !important; color: white !important; padding: 8px;">Valet</option>
        </select>
        <!-- Custom dropdown arrow -->
        <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none" style="top: 24px;">
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </div>
    </div>
    
    <div class="relative z-50">
        <label for="ParkingDimensions" class="block text-sm font-medium text-white mb-1">Parking Dimensions*</label>
        <select id="ParkingDimensions" name="ParkingDimensions" 
            class="w-full p-3 bg-gray-800 border border-gray-600 rounded-md text-white focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-all appearance-none cursor-pointer relative z-50"
            style="background-color: #1f2937 !important; color: white !important; position: relative; z-index: 1000;"
            required>
            <option value="" disabled selected style="background-color: #1f2937 !important; color: #9ca3af !important;">Select size</option>
            <option value="Compact" style="background-color: #1f2937 !important; color: white !important; padding: 8px;">Compact</option>
            <option value="Standard" style="background-color: #1f2937 !important; color: white !important; padding: 8px;">Standard</option>
            <option value="Large" style="background-color: #1f2937 !important; color: white !important; padding: 8px;">Large</option>
        </select>
        <!-- Custom dropdown arrow -->
        <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none" style="top: 24px;">
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </div>
    </div>
</div>

                        
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="GarageSlots" class="block text-sm font-medium text-white mb-1">Total Parking Slots*</label>
                                <input type="number" id="GarageSlots" name="GarageSlots" min="1" 
                                    class="w-full p-3 bg-white/10 border border-white/20 rounded-md text-white placeholder-white/50 focus:ring-2 focus:ring-primary-500 focus:border-transparent transition-all"
                                    placeholder="Number of slots" required>
                            </div>
                            
                            <div>
                                <label for="PricingPerHour" class="block text-sm font-medium text-white mb-1">Price per Hour (৳)*</label>
                                <input type="number" id="PricingPerHour" name="PricingPerHour" min="0" step="0.01" 
                                    class="w-full p-3 bg-white/10 border border-white/20 rounded-md text-white placeholder-white/50 focus:ring-2 focus:ring-primary-500 focus:border-transparent transition-all"
                                    placeholder="Price per hour" required>
                            </div>
                        </div>
                        
                        <!-- Hidden inputs to store latitude and longitude -->
                        <input type="hidden" id="latitude" name="latitude" value="">
                        <input type="hidden" id="longitude" name="longitude" value="">
                        
                        <div id="selected-location" class="mt-2 mb-4 p-3 bg-white/10 border border-white/20 rounded-md hidden">
                            <p class="text-white/90 mb-1 text-xs font-medium">Selected Location:</p>
                            <p id="selected-lat" class="text-white/90 text-xs">Latitude: </p>
                            <p id="selected-lng" class="text-white/90 text-xs">Longitude: </p>
                        </div>
                        
                        <button type="submit" name="submit" 
                                class="w-full bg-[#e67e00] hover:bg-[#d67200] text-white py-3 font-medium rounded transition-colors duration-300">
                            REGISTER GARAGE
                        </button>
                    </form>
                    
                    <!-- Go to Dashboard Link -->
                    <div class="text-center mt-3">
                        <?php if ($userType == "garage_owner" || $userType == "dual_user"): ?>
                        <a href="business_desh.php" class="text-[#e67e00] hover:text-[#ffa41c] text-sm">Go to Business Dashboard</a>
                        <?php else: ?>
                        <a href="home.php" class="text-[#e67e00] hover:text-[#ffa41c] text-sm">Go to Home</a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Right column: Map -->
                <div class="w-full md:w-1/2">
                    <label class="block text-sm font-medium text-white mb-1">Garage Location (Click on map to select)</label>
                    <div id="map" class="h-[400px] rounded-md border border-white/20 bg-white/5"></div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Footer -->
        <div class="text-center py-2 border-t border-white/10">
            <p class="text-gray-500 text-xs">&copy; <?php echo date('Y'); ?> পার্কিং লাগবে. All rights reserved.</p>
        </div>
    </div>

    <!-- Particles.js for background animation -->
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <!-- Leaflet JS for maps -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize particles.js
            particlesJS('particles-js', {
                "particles": {
                    "number": {
                        "value": 80,
                        "density": {
                            "enable": true,
                            "value_area": 800
                        }
                    },
                    "color": {
                        "value": "#f98c00"
                    },
                    "shape": {
                        "type": "circle",
                        "stroke": {
                            "width": 0,
                            "color": "#000000"
                        }
                    },
                    "opacity": {
                        "value": 0.5,
                        "random": true,
                        "anim": {
                            "enable": true,
                            "speed": 1,
                            "opacity_min": 0.1,
                            "sync": false
                        }
                    },
                    "size": {
                        "value": 3,
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
                        "color": "#f98c00",
                        "opacity": 0.2,
                        "width": 1
                    },
                    "move": {
                        "enable": true,
                        "speed": 1,
                        "direction": "none",
                        "random": true,
                        "straight": false,
                        "out_mode": "out",
                        "bounce": false,
                        "attract": {
                            "enable": false,
                            "rotateX": 600,
                            "rotateY": 1200
                        }
                    }
                },
                "interactivity": {
                    "detect_on": "canvas",
                    "events": {
                        "onhover": {
                            "enable": true,
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
                            "distance": 140,
                            "line_linked": {
                                "opacity": 0.5
                            }
                        },
                        "push": {
                            "particles_nb": 4
                        }
                    }
                },
                "retina_detect": true
            });
            
            // Initialize map if the element exists and user is logged in and verified
            const mapElement = document.getElementById('map');
            const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
            const isVerified = <?php echo $isVerified ? 'true' : 'false'; ?>;
            
            if (mapElement && isLoggedIn && isVerified) {
                // Map initialization - Default to Dhaka, Bangladesh
                var map = L.map('map').setView([23.8103, 90.4125], 13);
                
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                }).addTo(map);
                
                // Variables to store markers
                let userMarker, userCircle;
                let selectedMarker = null;
                let selectedLocation = null;
                
                // DOM elements
                const selectedLocationDiv = document.getElementById('selected-location');
                const selectedLatElement = document.getElementById('selected-lat');
                const selectedLngElement = document.getElementById('selected-lng');
                const latitudeInput = document.getElementById('latitude');
                const longitudeInput = document.getElementById('longitude');
                
                // Get user's current location
                navigator.geolocation.getCurrentPosition(
                    function(pos) {
                        const lat = pos.coords.latitude;
                        const lng = pos.coords.longitude;
                        const accuracy = pos.coords.accuracy;
                        
                        // Center map on user's location
                        map.setView([lat, lng], 15);
                        
                        // Remove previous markers if they exist
                        if (userMarker) {
                            map.removeLayer(userMarker);
                        }
                        if (userCircle) {
                            map.removeLayer(userCircle);
                        }
                        
                        // Add marker for user's location
                        userMarker = L.marker([lat, lng]).addTo(map);
                        userMarker.bindPopup("Your current location").openPopup();
                        
                        userCircle = L.circle([lat, lng], {
                            radius: accuracy,
                            color: '#f98c00',
                            fillColor: '#f98c00',
                            fillOpacity: 0.2
                        }).addTo(map);
                    },
                    function(err) {
                        console.error("Error getting location:", err);
                        // Default to Dhaka, Bangladesh if location access is denied
                        map.setView([23.8103, 90.4125], 13);
                    }
                );
                
                // Add click event to map
                map.on('click', function(e) {
                    const lat = e.latlng.lat;
                    const lng = e.latlng.lng;
                    
                    // Update selected location
                    selectedLocation = { lat, lng };
                    
                    // Update UI
                    selectedLatElement.textContent = `Latitude: ${lat.toFixed(6)}`;
                    selectedLngElement.textContent = `Longitude: ${lng.toFixed(6)}`;
                    selectedLocationDiv.classList.remove('hidden');
                    
                    // Update hidden form inputs
                    latitudeInput.value = lat.toFixed(6);
                    longitudeInput.value = lng.toFixed(6);
                    
                    // Remove previous selected marker if exists
                    if (selectedMarker) {
                        map.removeLayer(selectedMarker);
                    }
                    
                    // Add new marker
                    selectedMarker = L.marker([lat, lng]).addTo(map);
                    selectedMarker.bindPopup("Selected garage location").openPopup();
                });
            }
        });
    </script>
</body>
</html>