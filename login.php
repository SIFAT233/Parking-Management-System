<?php
// Move all PHP code to the top before ANY HTML output to prevent header issues
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// For production, comment out these session reset lines 
// $_SESSION = array();
// session_regenerate_id(true);

$error_message = "";
$remember_username = ""; // For remember me functionality
$debug_message = ""; // For capturing debug information

// Check if remember me cookie exists
if (isset($_COOKIE['remember_username']) && !isset($_POST['submit'])) {
    $remember_username = $_COOKIE['remember_username'];
}

if (isset($_POST['submit'])) {
    require_once("connection.php");
    
    // Debug logging to file
    file_put_contents('login_debug.txt', 'Form submitted: ' . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    
    // Secure the inputs
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password']; // We'll use the original password for verification
    
    // Remember me functionality
    if (isset($_POST['remember-me'])) {
        setcookie('remember_username', $username, time() + (86400 * 30), "/"); // 30 days
    } else {
        setcookie('remember_username', "", time() - 3600, "/"); // Delete cookie
    }
    
    // Use prepared statement to prevent SQL injection
    $query = "SELECT username, password, owner_id, default_dashboard FROM account_information WHERE username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        $error_message = "Invalid username or password"; // Generic error message for security
        file_put_contents('login_debug.txt', 'Login failed: Username not found - ' . $username . "\n", FILE_APPEND);
    } else {
        $row = $result->fetch_assoc();
        
        // Note: In a real-world application, passwords should be hashed. 
        // This is simplified to match your current system.
        if ($row['password'] == $password) {
            // Log successful login
            file_put_contents('login_debug.txt', 'Login successful for ' . $username . ' at ' . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
            
            // Store user info in session
            $_SESSION['username'] = $username;
            $_SESSION['logged_in'] = true;
            $_SESSION['last_activity'] = time(); // For session timeout
            
            // Get owner_id from account_information table
            $owner_id = $row['owner_id'];
            
            // Store owner_id in session if available
            if (!empty($owner_id)) {
                $_SESSION['owner_id'] = $owner_id;
            }
            
            // Modified login history recording - improved with better error handling
            file_put_contents('login_debug.txt', 'Trying to insert login history for ' . $username . "\n", FILE_APPEND);
            
            try {
                // Get the client IP address and user agent
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
                
                // Insert the login history record directly
                $insert_query = "INSERT INTO user_login_history (username, ip_address, user_agent) VALUES (?, ?, ?)";
                $stmt = $conn->prepare($insert_query);
                $stmt->bind_param("sss", $username, $ip_address, $user_agent);
                
                if ($stmt->execute()) {
                    $debug_message = "Login history recorded successfully!";
                    file_put_contents('login_debug.txt', 'Login history inserted successfully: ' . $username . "\n", FILE_APPEND);
                } else {
                    $debug_message = "Login history insert failed: " . $stmt->error;
                    file_put_contents('login_debug.txt', 'Login history insert failed: ' . $stmt->error . "\n", FILE_APPEND);
                }
                
                // Update last_login timestamp in the appropriate tables with prepared statements
                $current_time = date('Y-m-d H:i:s');
                
                // Update garage owner record
                $update_owner = "UPDATE garage_owners SET last_login = ? WHERE username = ?";
                $stmt = $conn->prepare($update_owner);
                $stmt->bind_param("ss", $current_time, $username);
                $stmt->execute();
                
                if ($conn->affected_rows > 0) {
                    file_put_contents('login_debug.txt', 'Updated garage owner last_login for ' . $username . "\n", FILE_APPEND);
                }
                
                // Update dual_user record
                $update_dual = "UPDATE dual_user SET last_login = ? WHERE username = ?";
                $stmt = $conn->prepare($update_dual);
                $stmt->bind_param("ss", $current_time, $username);
                $stmt->execute();
                
                if ($conn->affected_rows > 0) {
                    file_put_contents('login_debug.txt', 'Updated dual user last_login for ' . $username . "\n", FILE_APPEND);
                }
            } catch (Exception $e) {
                $debug_message = "Error recording login history: " . $e->getMessage();
                file_put_contents('login_debug.txt', 'Exception in login history: ' . $e->getMessage() . "\n", FILE_APPEND);
                // Continue with the login process even if history recording fails
            }
            
            file_put_contents('login_debug.txt', 'Login history tracking completed. Debug message: ' . $debug_message . "\n", FILE_APPEND);

            // Check if user is admin
            if ($username === 'admin') {
                // User is admin
                $_SESSION['user_type'] = 'admin';
                // Redirect to admin panel
                file_put_contents('login_debug.txt', 'Admin user - redirecting to admin.php' . "\n", FILE_APPEND);
                header("Location: admin.php");
                exit();
            }
            
            // Use default_dashboard to decide where to redirect
            if (!empty($row['default_dashboard']) && $row['default_dashboard'] === 'business') {
                // Business dashboard users
                $_SESSION['user_type'] = 'garage_owner';
                file_put_contents('login_debug.txt', 'Business user - redirecting to business_desh.php' . "\n", FILE_APPEND);
                header("Location: business_desh.php");
                exit();
            } else {
                // All other users go to home.php
                $_SESSION['user_type'] = 'regular_user';
                file_put_contents('login_debug.txt', 'Regular user - redirecting to home.php' . "\n", FILE_APPEND);
                header("Location: home.php");
                exit();
            }
        } else {
            $error_message = "Invalid username or password"; // Generic error message for security
            file_put_contents('login_debug.txt', 'Login failed: Incorrect password for ' . $username . "\n", FILE_APPEND);
        }
    }
    
    // If we reach here, something went wrong with the login process
    // Add a fallback redirect to prevent users from seeing a blank page
    if (empty($error_message)) {
        $error_message = "An unexpected error occurred. Please try again.";
        file_put_contents('login_debug.txt', 'Login failed with unexpected error' . "\n", FILE_APPEND);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>পার্কিং লাগবে - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
                        'float': 'float 6s ease-in-out infinite',
                        'fadeIn': 'fadeIn 1s ease-out',
                        'slideUp': 'slideUp 0.8s ease-out',
                        'slideRight': 'slideRight 0.8s ease-out',
                        'expand': 'expand 0.8s ease-out',
                        'bounce': 'bounce 2s infinite',
                        'pulse': 'pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'spin-slow': 'spin 8s linear infinite',
                        'glow': 'glow 2s ease-in-out infinite alternate',
                        'typing': 'typing 3.5s steps(40, end)',
                        'blink-caret': 'blink-caret .75s step-end infinite',
                    },
                    keyframes: {
                        float: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-10px)' },
                        },
                        fadeIn: {
                            '0%': { opacity: '0', transform: 'translateY(10px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                        slideUp: {
                            '0%': { opacity: '0', transform: 'translateY(20px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                        slideRight: {
                            '0%': { opacity: '0', transform: 'translateX(-20px)' },
                            '100%': { opacity: '1', transform: 'translateX(0)' },
                        },
                        expand: {
                            '0%': { transform: 'scale(0.8)', opacity: '0' },
                            '100%': { transform: 'scale(1)', opacity: '1' },
                        },
                        glow: {
                            '0%': { boxShadow: '0 0 5px rgba(249, 140, 0, 0.5)' },
                            '100%': { boxShadow: '0 0 20px rgba(249, 140, 0, 0.8)' }
                        },
                        typing: {
                            '0%': { width: '0' },
                            '100%': { width: '100%' }
                        },
                        'blink-caret': {
                            '0%, 100%': { borderColor: 'transparent' },
                            '50%': { borderColor: '#f98c00' }
                        }
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-dark-900 min-h-screen flex items-center justify-center p-4" style="font-family: 'Poppins', sans-serif;">
    <!-- Animated Background Elements -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-1/4 left-1/4 w-64 h-64 bg-primary-500/10 rounded-full filter blur-3xl animate-spin-slow"></div>
        <div class="absolute bottom-1/3 right-1/3 w-96 h-96 bg-primary-600/5 rounded-full filter blur-3xl animate-spin-slow" style="animation-delay: 2s; animation-direction: reverse;"></div>
        <div class="absolute top-2/3 left-1/2 w-48 h-48 bg-primary-700/10 rounded-full filter blur-3xl animate-spin-slow" style="animation-delay: 1s;"></div>
    </div>
    
    <!-- Particle Animation -->
    <div id="particles-js" class="fixed inset-0 z-0"></div>

    <!-- Display error message if there is one -->
    <?php if (!empty($error_message)): ?>
    <div class="fixed top-4 right-4 bg-red-500 text-white px-4 py-2 rounded-md shadow-lg z-50 animate-fadeIn">
        <?php echo htmlspecialchars($error_message); ?>
        <button class="ml-2 text-white font-bold" onclick="this.parentElement.style.display='none'">&times;</button>
    </div>
    <?php endif; ?>
    
    <!-- Display debug message during development (Remove in production) -->
    <?php if (!empty($debug_message) && ini_get('display_errors')): ?>
    <div class="fixed top-14 right-4 bg-blue-500 text-white px-4 py-2 rounded-md shadow-lg z-50 animate-fadeIn">
        Debug: <?php echo htmlspecialchars($debug_message); ?>
        <button class="ml-2 text-white font-bold" onclick="this.parentElement.style.display='none'">&times;</button>
    </div>
    <?php endif; ?>

    <div class="w-full max-w-6xl bg-dark-850 rounded-2xl shadow-2xl overflow-hidden flex flex-col md:flex-row animate-expand z-10 border border-dark-800/80">
        <!-- Left Section with Illustration -->
        <div class="md:w-1/2 bg-gradient-to-br from-primary-600 to-primary-800 p-12 text-white flex flex-col justify-between relative overflow-hidden">
            <!-- Background Pattern -->
            <div class="absolute top-0 left-0 w-full h-full opacity-10">
                <svg width="100%" height="100%" viewBox="0 0 100 100" preserveAspectRatio="none">
                    <defs>
                        <pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse">
                            <circle cx="5" cy="5" r="1" fill="currentColor" />
                        </pattern>
                    </defs>
                    <rect width="100%" height="100%" fill="url(#grid)" />
                </svg>
            </div>
            
            <!-- Animated Circles -->
            <div class="absolute top-20 right-20 w-16 h-16 bg-white/10 rounded-full animate-pulse"></div>
            <div class="absolute bottom-40 left-10 w-8 h-8 bg-white/20 rounded-full animate-pulse" style="animation-delay: 1s;"></div>
            <div class="absolute top-40 left-20 w-12 h-12 bg-white/15 rounded-full animate-pulse" style="animation-delay: 0.5s;"></div>
            
            <!-- Logo and Title -->
            <div class="relative z-10 animate-slideRight">
                <div class="flex items-center mb-6">
                    <div class="w-12 h-12 bg-white rounded-full flex items-center justify-center mr-4 animate-glow">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                            <path d="M9 18V6h4.5a2.5 2.5 0 0 1 0 5H9"></path>
                        </svg>
                    </div>
                    <h1 class="text-2xl font-bold">পার্কিং লাগবে</h1>
                </div>
                <h2 class="text-3xl font-bold mb-4 overflow-hidden whitespace-nowrap border-r-4 border-primary-300 animate-typing animate-blink-caret">Welcome Back!</h2>
                <p class="text-primary-100 mb-8">Log in to access your account and manage your parking reservations.</p>
            </div>
            
            <!-- Car Illustration -->
            <div class="relative z-10 flex justify-center items-center animate-float">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512" class="w-64 h-64 text-white/90 drop-shadow-[0_0_15px_rgba(249,140,0,0.5)]">
                    <path fill="currentColor" d="M171.3 96H224v96H111.3l30.4-75.9C146.5 104 158.2 96 171.3 96zM272 192V96h81.2c9.7 0 18.9 4.4 25 12l67.2 84H272zm256.2 1L428.2 68c-18.2-22.8-45.8-36-75-36H171.3c-39.3 0-74.6 23.9-89.1 60.3L40.6 196.4C16.8 205.8 0 228.9 0 256v112c0 17.7 14.3 32 32 32h33.3c7.6 45.4 47.1 80 94.7 80s87.1-34.6 94.7-80h130.6c7.6 45.4 47.1 80 94.7 80s87.1-34.6 94.7-80H608c17.7 0 32-14.3 32-32V320c0-65.2-48.8-119-111.8-127zM160 432c-26.5 0-48-21.5-48-48s21.5-48 48-48 48 21.5 48 48-21.5 48-48 48zm272 0c-26.5 0-48-21.5-48-48s21.5-48 48-48 48 21.5 48 48-21.5 48-48 48zm48-160H160v-64h320v64z"/>
                </svg>
            </div>
            
            <!-- Features -->
            <div class="relative z-10 mt-8 animate-slideUp" style="animation-delay: 0.3s;">
                <div class="grid grid-cols-2 gap-4">
                    <div class="flex items-start transform transition-transform hover:scale-105 duration-300">
                        <div class="bg-white/20 rounded-full p-2 mr-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold">Easy Booking</h3>
                            <p class="text-xs text-primary-100">Book parking spots in seconds</p>
                        </div>
                    </div>
                    <div class="flex items-start transform transition-transform hover:scale-105 duration-300">
                        <div class="bg-white/20 rounded-full p-2 mr-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold">24/7 Access</h3>
                            <p class="text-xs text-primary-100">Park anytime you need</p>
                        </div>
                    </div>
                    <div class="flex items-start transform transition-transform hover:scale-105 duration-300">
                        <div class="bg-white/20 rounded-full p-2 mr-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold">Secure Parking</h3>
                            <p class="text-xs text-primary-100">Safe and monitored locations</p>
                        </div>
                    </div>
                    <div class="flex items-start transform transition-transform hover:scale-105 duration-300">
                        <div class="bg-white/20 rounded-full p-2 mr-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold">User Friendly</h3>
                            <p class="text-xs text-primary-100">Simple and intuitive interface</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Right Section with Login Form -->
        <div class="md:w-1/2 p-12 md:p-16 flex flex-col justify-center bg-dark-850 relative">
            <!-- Animated Gradient Border -->
            <div class="absolute inset-0 p-0.5 rounded-2xl bg-gradient-to-r from-primary-500 via-primary-300 to-primary-600 opacity-50 blur-sm animate-pulse"></div>
            
            <!-- Login Header -->
            <div class="text-center mb-10 animate-slideUp">
                <div class="inline-block p-3 rounded-full bg-primary-500/10 mb-4 animate-pulse">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-primary-500" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 4c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm0 14c-2.03 0-4.43-.82-6-2.33 0-1.95 4-3.67 6-3.67s6 1.72 6 3.67c-1.57 1.51-3.97 2.33-6 2.33z"/>
                    </svg>
                </div>
                <h2 class="text-3xl font-bold text-white mb-2">Hi Friend!</h2>
                <p class="text-gray-400 mt-2">Please login to your account</p>
            </div>
            
            <!-- Login Form -->
            <form action="login.php" method="POST" class="space-y-6 relative z-10 animate-fadeIn" style="animation-delay: 0.2s;">
                <!-- CSRF Protection - Add a hidden token -->
                <input type="hidden" name="csrf_token" value="<?php echo bin2hex(random_bytes(32)); ?>">

                <div class="group">
                    <label for="username" class="block text-sm font-medium text-white mb-1 group-hover:text-primary-400 transition-colors">Username</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-500 group-hover:text-primary-500 transition-colors" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </div>
                        <input type="text" id="username" name="username" required
                               class="pl-10 w-full py-3 px-4 bg-white border border-gray-300 rounded-lg text-black focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent transition-all hover:border-gray-400"
                               placeholder="Enter your username" value="<?php echo htmlspecialchars($remember_username); ?>">
                    </div>
                </div>
                
                <div class="group">
                    <label for="password" class="block text-sm font-medium text-white mb-1 group-hover:text-primary-400 transition-colors">Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-500 group-hover:text-primary-500 transition-colors" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                        </div>
                        <input type="password" id="password" name="password" required
                               class="pl-10 w-full py-3 px-4 bg-white border border-gray-300 rounded-lg text-black focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent transition-all hover:border-gray-400"
                               placeholder="Enter your password">
                    </div>
                </div>
                
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <input id="remember-me" name="remember-me" type="checkbox" 
                               class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded bg-white"
                               <?php echo !empty($remember_username) ? 'checked' : ''; ?>>
                        <label for="remember-me" class="ml-2 block text-sm text-white">
                            Remember me
                        </label>
                    </div>
                    <a href="reset-password.php" class="text-sm font-medium text-primary-500 hover:text-primary-400 transition-colors">
                        Forgot password?
                    </a>
                </div>
                
                <button type="submit" name="submit" 
                        class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-all transform hover:scale-[1.02] active:scale-[0.98] hover:shadow-lg">
                    LOGIN
                </button>
            </form>
            
            <!-- Register Link -->
            <div class="text-center mt-8 animate-fadeIn" style="animation-delay: 0.4s; position: relative; z-index: 100;">
                <p class="text-sm text-white">
                    Don't have an account? 
                    <a href="registration.php" class="font-medium text-primary-500 hover:text-primary-400 transition-colors px-2 py-1 rounded hover:bg-white/10">
                        Register as new user
                    </a>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <div class="absolute bottom-4 text-center w-full text-gray-500 text-xs animate-fadeIn" style="animation-delay: 0.6s;">
        &copy; <?php echo date('Y'); ?> পার্কিং লাগবে. All rights reserved.
    </div>

    <!-- Particles.js for background animation -->
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
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
                        },
                        "polygon": {
                            "nb_sides": 5
                        }
                    },
                    "opacity": {
                        "value": 0.7,
                        "random": true,
                        "anim": {
                            "enable": true,
                            "speed": 1,
                            "opacity_min": 0.4,
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
                        "bubble": {
                            "distance": 400,
                            "size": 40,
                            "duration": 2,
                            "opacity": 8,
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
            const inputs = document.querySelectorAll('input[type="text"], input[type="password"]');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('transform', 'scale-[1.02]');
                    this.parentElement.style.transition = 'all 0.3s ease';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.classList.remove('transform', 'scale-[1.02]');
                });
            });
        });
    </script>
</body>
</html>