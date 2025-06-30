<?php
session_start();
require_once("connection.php");

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

$username = $_SESSION['username'];

if ($_POST['action'] === 'submit_verification') {
    
    // Create uploads directory if it doesn't exist
    $uploadDir = 'uploads/verification/' . $username . '/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    try {
        $conn->begin_transaction();
        
        // Check if user already has pending verification
        $checkQuery = "SELECT id FROM verification_requests WHERE username = ? AND overall_status IN ('pending', 'under_review') ORDER BY requested_at DESC LIMIT 1";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("s", $username);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult && $checkResult->num_rows > 0) {
            throw new Exception('You already have a pending verification request');
        }
        
        // Create verification request
        $requestQuery = "INSERT INTO verification_requests (username, request_type, overall_status) VALUES (?, 'full', 'pending')";
        $requestStmt = $conn->prepare($requestQuery);
        $requestStmt->bind_param("s", $username);
        
        if (!$requestStmt->execute()) {
            throw new Exception('Failed to create verification request');
        }
        
        $requestId = $conn->insert_id;
        $uploadedFiles = [];
        
        // Process identity document
        if (isset($_FILES['identity_file']) && $_FILES['identity_file']['error'] === UPLOAD_ERR_OK) {
            $documentNumber = $_POST['identity_document_number'] ?? '';
            $documentType = $_POST['identity_document_type'] ?? '';
            
            $result = processFileUpload(
                $_FILES['identity_file'], 
                $uploadDir, 
                $username, 
                $documentType, 
                $documentNumber,
                $conn
            );
            
            if ($result['success']) {
                $uploadedFiles[] = 'Identity: ' . $result['filename'];
            } else {
                throw new Exception('Failed to upload identity document: ' . $result['error']);
            }
        } else {
            throw new Exception('Identity document is required');
        }
        
        // Process vehicle registration (optional)
        if (isset($_FILES['registration_file']) && $_FILES['registration_file']['error'] === UPLOAD_ERR_OK && $_FILES['registration_file']['size'] > 0) {
            $result = processFileUpload(
                $_FILES['registration_file'], 
                $uploadDir, 
                $username, 
                'vehicle_registration', 
                '',
                $conn
            );
            
            if ($result['success']) {
                $uploadedFiles[] = 'Vehicle Registration: ' . $result['filename'];
            } else {
                throw new Exception('Failed to upload vehicle registration: ' . $result['error']);
            }
        }
        
        // Process vehicle insurance (optional)
        if (isset($_FILES['insurance_file']) && $_FILES['insurance_file']['error'] === UPLOAD_ERR_OK && $_FILES['insurance_file']['size'] > 0) {
            $result = processFileUpload(
                $_FILES['insurance_file'], 
                $uploadDir, 
                $username, 
                'vehicle_insurance', 
                '',
                $conn
            );
            
            if ($result['success']) {
                $uploadedFiles[] = 'Vehicle Insurance: ' . $result['filename'];
            } else {
                throw new Exception('Failed to upload vehicle insurance: ' . $result['error']);
            }
        }
        
        // Update verification request status
        $updateQuery = "UPDATE verification_requests SET overall_status = 'under_review' WHERE id = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("i", $requestId);
        
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update verification request status');
        }
        
        $conn->commit();
        
        // Send success response
        echo json_encode([
            'success' => true, 
            'message' => 'Verification request submitted successfully',
            'request_id' => $requestId,
            'uploaded_files' => $uploadedFiles
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        
        // Clean up uploaded files if transaction failed
        if (!empty($uploadedFiles)) {
            foreach ($uploadedFiles as $fileInfo) {
                $filename = explode(': ', $fileInfo)[1] ?? '';
                $filepath = $uploadDir . $filename;
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
            }
        }
        
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function processFileUpload($file, $uploadDir, $username, $documentType, $documentNumber, $conn) {
    try {
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
        $fileType = $file['type'];
        
        if (!in_array($fileType, $allowedTypes)) {
            return ['success' => false, 'error' => 'Invalid file type. Only JPG, PNG, and PDF files are allowed.'];
        }
        
        // Validate file size (5MB max)
        if ($file['size'] > 5 * 1024 * 1024) {
            return ['success' => false, 'error' => 'File size too large. Maximum 5MB allowed.'];
        }
        
        // Generate unique filename
        $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $documentType . '_' . time() . '_' . rand(1000, 9999) . '.' . $fileExtension;
        $filepath = $uploadDir . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            return ['success' => false, 'error' => 'Failed to save uploaded file'];
        }
        
        // Insert document record into database
        $insertQuery = "INSERT INTO verification_documents (username, document_type, document_number, file_path, original_filename, file_size, mime_type, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
        $insertStmt = $conn->prepare($insertQuery);
        
        if (!$insertStmt) {
            unlink($filepath); // Remove uploaded file
            return ['success' => false, 'error' => 'Database preparation failed: ' . $conn->error];
        }
        
        $originalFilename = $file['name'];
        $fileSize = $file['size'];
        
        $insertStmt->bind_param("sssssis", 
            $username, 
            $documentType, 
            $documentNumber, 
            $filepath, 
            $originalFilename, 
            $fileSize, 
            $fileType
        );
        
        if (!$insertStmt->execute()) {
            unlink($filepath); // Remove uploaded file
            return ['success' => false, 'error' => 'Failed to save file information to database: ' . $insertStmt->error];
        }
        
        return ['success' => true, 'filename' => $filename, 'filepath' => $filepath];
        
    } catch (Exception $e) {
        // Clean up file if it exists
        if (isset($filepath) && file_exists($filepath)) {
            unlink($filepath);
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Function to get user verification status (can be called via AJAX)
function getUserVerificationStatus($username, $conn) {
    $query = "SELECT vr.overall_status, vr.requested_at, vr.admin_notes,
                     COUNT(vd.id) as document_count,
                     GROUP_CONCAT(vd.document_type) as document_types
              FROM verification_requests vr
              LEFT JOIN verification_documents vd ON vr.username = vd.username
              WHERE vr.username = ?
              ORDER BY vr.requested_at DESC
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Handle AJAX request for verification status
if (isset($_GET['action']) && $_GET['action'] === 'get_status') {
    $status = getUserVerificationStatus($username, $conn);
    echo json_encode(['success' => true, 'status' => $status]);
    exit();
}
?>