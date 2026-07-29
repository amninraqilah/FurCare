<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];
$requestID = $_GET['request_id'] ?? 0;

if ($requestID) {
    // Verify the request belongs to the user and is still pending
    $check_stmt = $conn->prepare("SELECT * FROM AdoptionRequest WHERE RequestID = ? AND AdopterID = ? AND Status = 'pending'");
    $check_stmt->bind_param("ii", $requestID, $userID);
    $check_stmt->execute();
    $request = $check_stmt->get_result()->fetch_assoc();
    
    if ($request) {
        // Update status to cancelled
        $update_stmt = $conn->prepare("UPDATE AdoptionRequest SET Status = 'cancelled' WHERE RequestID = ?");
        if ($update_stmt->bind_param("i", $requestID) && $update_stmt->execute()) {
            header("Location: myApplications.php?success=Adoption request cancelled successfully");
            exit;
        } else {
            header("Location: myApplications.php?error=Failed to cancel request");
            exit;
        }
    } else {
        header("Location: myApplications.php?error=Request not found or cannot be cancelled");
        exit;
    }
}

header("Location: myAdoptionRequests.php?error=Invalid request");
exit;
?>