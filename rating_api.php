<?php
// rating_api.php - Handle rating operations
session_start();
require_once("connection.php");

header('Content-Type: application/json');

// Add error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$username = $_SESSION['username'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'submit_rating':
        submitRating();
        break;
    case 'get_garage_ratings':
        getGarageRatings();
        break;
    case 'get_user_ratings':
        getUserRatings();
        break;
    case 'check_can_rate':
        checkCanRate();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
}

function submitRating() {
    global $conn, $username;
    
    $booking_id = $_POST['booking_id'] ?? '';
    $garage_id = $_POST['garage_id'] ?? '';
    $rating = $_POST['rating'] ?? '';
    $review_text = $_POST['review_text'] ?? '';
    
    // Debug logging
    error_log("Submit rating - booking_id: $booking_id, garage_id: $garage_id, rating: $rating, username: $username");
    
    // Validate inputs
    if (!$booking_id || !$garage_id || !$rating) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields: booking_id, garage_id, or rating']);
        return;
    }
    
    if ($rating < 1 || $rating > 5) {
        echo json_encode(['success' => false, 'message' => 'Rating must be between 1 and 5']);
        return;
    }
    
    // Verify the booking belongs to the user and is completed
    $bookingQuery = "SELECT b.*, g.username as garage_owner 
                     FROM bookings b 
                     JOIN garage_information g ON b.garage_id = g.garage_id 
                     WHERE b.id = ? AND b.username = ? AND b.status = 'completed'";
    $stmt = $conn->prepare($bookingQuery);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
        return;
    }
    
    $stmt->bind_param("is", $booking_id, $username);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    
    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Invalid booking or booking not completed']);
        return;
    }
    
    // Check if already rated
    $existingQuery = "SELECT id FROM ratings WHERE booking_id = ?";
    $stmt = $conn->prepare($existingQuery);
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    
    if ($existing) {
        // Update existing rating
        $updateQuery = "UPDATE ratings SET rating = ?, review_text = ?, updated_at = NOW() WHERE booking_id = ?";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("dsi", $rating, $review_text, $booking_id);
        $success = $stmt->execute();
        $message = $success ? 'Rating updated successfully!' : 'Error updating rating: ' . $stmt->error;
    } else {
        // Insert new rating
        $insertQuery = "INSERT INTO ratings (booking_id, garage_id, rater_username, garage_owner_username, rating, review_text) 
                        VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insertQuery);
        $stmt->bind_param("isssds", $booking_id, $garage_id, $username, $booking['garage_owner'], $rating, $review_text);
        $success = $stmt->execute();
        $message = $success ? 'Rating submitted successfully!' : 'Error submitting rating: ' . $stmt->error;
    }
    
    echo json_encode(['success' => $success, 'message' => $message]);
}

function getGarageRatings() {
    global $conn;
    
    $garage_id = $_GET['garage_id'] ?? '';
    
    if (!$garage_id) {
        echo json_encode(['success' => false, 'message' => 'Garage ID required']);
        return;
    }
    
    // Get summary
    $summaryQuery = "SELECT * FROM garage_ratings_summary WHERE garage_id = ?";
    $stmt = $conn->prepare($summaryQuery);
    $stmt->bind_param("s", $garage_id);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();
    
    // Get individual reviews (latest 10)
    $reviewsQuery = "SELECT r.*, p.firstName, p.lastName 
                     FROM ratings r 
                     LEFT JOIN personal_information p ON r.rater_username = p.username 
                     WHERE r.garage_id = ? 
                     ORDER BY r.created_at DESC 
                     LIMIT 10";
    $stmt = $conn->prepare($reviewsQuery);
    $stmt->bind_param("s", $garage_id);
    $stmt->execute();
    $reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'reviews' => $reviews
    ]);
}

function getUserRatings() {
    global $conn, $username;
    
    $ratingsQuery = "SELECT r.*, g.Parking_Space_Name, g.Parking_Lot_Address 
                     FROM ratings r 
                     JOIN garage_information g ON r.garage_id = g.garage_id 
                     WHERE r.rater_username = ? 
                     ORDER BY r.created_at DESC";
    $stmt = $conn->prepare($ratingsQuery);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $ratings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode(['success' => true, 'ratings' => $ratings]);
}

function checkCanRate() {
    global $conn, $username;
    
    $booking_id = $_GET['booking_id'] ?? '';
    
    if (!$booking_id) {
        echo json_encode(['success' => false, 'message' => 'Booking ID required']);
        return;
    }
    
    // Check if booking exists, belongs to user, and is completed
    $bookingQuery = "SELECT b.*, r.id as rating_id, r.rating, r.review_text 
                     FROM bookings b 
                     LEFT JOIN ratings r ON b.id = r.booking_id 
                     WHERE b.id = ? AND b.username = ? AND b.status = 'completed'";
    $stmt = $conn->prepare($bookingQuery);
    $stmt->bind_param("is", $booking_id, $username);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if (!$result) {
        echo json_encode(['success' => false, 'can_rate' => false, 'message' => 'Cannot rate this booking']);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'can_rate' => true,
        'already_rated' => !empty($result['rating_id']),
        'existing_rating' => $result['rating'] ?? null,
        'existing_review' => $result['review_text'] ?? null
    ]);
}
?>