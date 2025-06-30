<?php
// Start the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// For connecting to database
require_once("connection.php");

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_SESSION['username'];
    $licenseplate = $_POST['licenseplate'];
    $vehicleType = $_POST['vehicleType'];
    $make = $_POST['make'];
    $model = $_POST['model'];
    $color = $_POST['color'];
    
    // Validate inputs
    if (empty($licenseplate) || empty($vehicleType) || empty($make) || empty($model) || empty($color)) {
        $error = "All fields are required.";
    } else {
        // Insert new vehicle
        $query = "INSERT INTO vehicle_information (licenseplate, vehicleType, make, model, color, username) 
                 VALUES ('$licenseplate', '$vehicleType', '$make', '$model', '$color', '$username')";
        
        if ($conn->query($query) === TRUE) {
            $success = "Vehicle added successfully!";
            
            // Redirect back to booking page after a short delay
            header("Refresh: 2; url=home.php");
        } else {
            $error = "Error adding vehicle: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Vehicle - পার্কিং লাগবে?</title>
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
            <a href="home.php" class="flex items-center gap-4 text-white">
                <div class="w-10 h-10 bg-primary rounded-full flex justify-center items-center overflow-hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><path d="M9 18V6h4.5a2.5 2.5 0 0 1 0 5H9"></path></svg>
                </div>
                <h1 class="text-xl font-semibold drop-shadow-md">পার্কিং লাগবে ?</h1>
            </a>
        </div>
    </header>
    
    <!-- Main Content -->
    <main class="container mx-auto px-4 py-10">
        <div class="max-w-2xl mx-auto bg-black/50 backdrop-blur-md rounded-xl p-8 border border-white/20 shadow-xl">
            <h2 class="text-3xl font-bold text-white mb-6 drop-shadow-md">Add New Vehicle</h2>
            
            <?php if (isset($error)): ?>
                <div class="bg-error/20 text-white p-4 rounded-lg mb-6">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($success)): ?>
                <div class="bg-success/20 text-white p-4 rounded-lg mb-6">
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="space-y-6">
                <!-- License Plate Field -->
<div class="form-control">
    <label for="licenseplate" class="label">
        <span class="label-text text-white">License Plate</span>
    </label>
    <input 
        type="text" 
        id="licenseplate" 
        name="licenseplate" 
        class="input input-bordered bg-white/15 text-white border-white/20 focus:border-primary" 
        placeholder="e.g., DHA-D-11-1234" 
        pattern="^[A-Z]{2,4}-[A-Z]-\d{1,2}-\d{3,4}$" 
        required
    >
</div>
                
                <div class="form-control">
                    <label for="vehicleType" class="label">
                        <span class="label-text text-white">Vehicle Type</span>
                    </label>
                    <select id="vehicleType" name="vehicleType" class="select select-bordered bg-white/15 text-white border-white/20 focus:border-primary" required>
                        <option value="sedan">Sedan</option>
                        <option value="suv">SUV</option>
                        <option value="hatchback">Hatchback</option>
                        <option value="truck">Truck</option>
                        <option value="motorcycle">Motorcycle</option>
                    </select>
                </div>
                
                <!-- Make Dropdown (Car Brands) -->
<div class="form-control">
    <label for="make" class="label">
        <span class="label-text text-white">Make</span>
    </label>
    <select 
        id="make" 
        name="make" 
        class="select select-bordered bg-white/15 text-white border-white/20 focus:border-primary" 
        required
    >
        <option disabled selected>Select a brand</option>
        <option value="Toyota">Toyota</option>
        <option value="Honda">Honda</option>
        <option value="Nissan">Nissan</option>
        <option value="BMW">BMW</option>
        <option value="Mercedes">Mercedes</option>
        <option value="Ford">Ford</option>
        <option value="Hyundai">Hyundai</option>
        <option value="Mitsubishi">Mitsubishi</option>
        <option value="Suzuki">Suzuki</option>
        <option value="Kia">Kia</option>
        <option value="Other">Other</option>
    </select>
</div>
                <div class="form-control">
                    <label for="model" class="label">
                        <span class="label-text text-white">Model Year</span>
                    </label>
                    <input type="text" id="model" name="model" class="input input-bordered bg-white/15 text-white border-white/20 focus:border-primary" required>
                </div>
                
                <div class="form-control">
                    <label for="color" class="label">
                        <span class="label-text text-white">Color</span>
                    </label>
                    <input type="text" id="color" name="color" class="input input-bordered bg-white/15 text-white border-white/20 focus:border-primary" required>
                </div>
                
                <div class="flex gap-4 pt-4">
                    <a href="home.php" class="btn btn-outline border-white/20 text-white flex-1">Cancel</a>
                    <button type="submit" class="btn bg-primary hover:bg-primary-dark text-white border-none flex-1">Add Vehicle</button>
                </div>
            </form>
        </div>
    </main>
    
    <!-- Footer -->
    <footer class="bg-black/70 backdrop-blur-md border-t border-white/10 py-8 mt-16">
        <div class="container mx-auto px-4 text-center">
            <p class="text-white/60 text-sm">&copy; <?php echo date('Y'); ?> পার্কিং লাগবে? All rights reserved.</p>
        </div>
    </footer>

    <script>
document.addEventListener("DOMContentLoaded", function () {
    const plateInput = document.getElementById("licenseplate");

    plateInput.addEventListener("input", function (e) {
        // Get current value and convert to uppercase
        let value = e.target.value.toUpperCase();
        
        // Remove any character that's not a letter, number or hyphen
        value = value.replace(/[^A-Z0-9\-]/g, '');
        
        // Apply the strict format: XXX-X-NN-NNNN
        // Where X is letter and N is number
        
        // Split by hyphens (if any)
        let parts = value.split('-').filter(part => part.length > 0);
        let formatted = '';
        
        // First part (area code): 2-3 letters (e.g., DHA, SHA)
        if (parts.length > 0) {
            // Extract only letters from the first part
            let areaCode = parts[0].replace(/[^A-Z]/g, '');
            // Limit to 3 letters
            areaCode = areaCode.substring(0, 3);
            if (areaCode.length > 0) {
                formatted = areaCode;
            }
        }
        
        // Second part (series): 1 letter (e.g., D)
        if (parts.length > 1) {
            // Extract only the first letter from the second part
            let series = parts[1].replace(/[^A-Z]/g, '');
            series = series.substring(0, 1);
            if (series.length > 0) {
                formatted += '-' + series;
            }
        } else if (formatted.length >= 3) {
            // If we have a complete area code but no series yet
            // Extract from remaining characters in the first part
            let remaining = parts[0].substring(3).replace(/[^A-Z]/g, '');
            if (remaining.length > 0) {
                formatted += '-' + remaining.substring(0, 1);
            }
        }
        
        // Third part (number group 1): 1-2 digits (e.g., 11)
        if (parts.length > 2) {
            // Extract only numbers from the third part
            let numGroup1 = parts[2].replace(/[^0-9]/g, '');
            // Limit to 2 digits
            numGroup1 = numGroup1.substring(0, 2);
            if (numGroup1.length > 0) {
                formatted += '-' + numGroup1;
            }
        } else if (formatted.includes('-') && parts.length > 1) {
            // If we have area code and series but no number group yet
            // Extract from remaining characters in the second part
            let remaining = parts[1].substring(1).replace(/[^0-9]/g, '');
            if (remaining.length > 0) {
                formatted += '-' + remaining.substring(0, 2);
            }
        }
        
        // Fourth part (number group 2): 3-4 digits (e.g., 1234)
        if (parts.length > 3) {
            // Extract only numbers from the fourth part
            let numGroup2 = parts[3].replace(/[^0-9]/g, '');
            // Limit to 4 digits
            numGroup2 = numGroup2.substring(0, 4);
            if (numGroup2.length > 0) {
                formatted += '-' + numGroup2;
            }
        } else if (formatted.includes('-') && formatted.split('-').length > 2) {
            // If we have area code, series, and first number group
            // Extract from remaining characters in the third part
            let remaining = parts[2] ? parts[2].substring(2).replace(/[^0-9]/g, '') : '';
            if (remaining.length > 0) {
                formatted += '-' + remaining.substring(0, 4);
            }
        }
        
        // Update the input value
        e.target.value = formatted;
    });
    
    // Add validation on form submit
    if (plateInput.form) {
        plateInput.form.addEventListener('submit', function(e) {
            const value = plateInput.value;
            // Check if the value matches the pattern: XXX-X-NN-NNNN
            // Where X is letter and N is number
            const pattern = /^[A-Z]{2,3}-[A-Z]-[0-9]{1,2}-[0-9]{3,4}$/;
            
            if (!pattern.test(value)) {
                e.preventDefault();
                alert('Please enter a valid license plate in the format: DHA-D-11-1234');
                plateInput.focus();
            }
        });
    }
});
</script>
</body>
</html>