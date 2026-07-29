<?php
include 'connect.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];

// Check if we have a valid action
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $petID = intval($_GET['id']);
    $reason = $_GET['reason'] ?? 'No reason provided';
    
    // Validate action
    if (!in_array($action, ['approve', 'reject'])) {
        header("Location: adminManagePets.php?error=Invalid action");
        exit();
    }
    
    // Prepare the query based on action
    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE pet SET ApprovalStatus = 'approved', ApprovedBy = ?, ApprovedAt = NOW(), RejectionReason = NULL WHERE PetID = ?");
        $stmt->bind_param("ii", $userID, $petID);
    } else {
        $stmt = $conn->prepare("UPDATE pet SET ApprovalStatus = 'rejected', RejectionReason = ?, ApprovedBy = ?, ApprovedAt = NOW() WHERE PetID = ?");
        $stmt->bind_param("sii", $reason, $userID, $petID);
    }
    
    // Execute the query
    if ($stmt->execute()) {
        $actionText = $action === 'approve' ? 'approved' : 'rejected';
        header("Location: adminManagePets.php?tab=pending&success=Pet {$actionText} successfully");
    } else {
        header("Location: adminManagePets.php?tab=pending&error=Failed to {$action} pet");
    }
} else {
    // No valid parameters, redirect back
    header("Location: adminManagePets.php");
}

exit();
?>