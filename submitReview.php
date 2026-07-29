<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['request_id'])) {
    header("Location: myApplications.php");
    exit;
}

$userID = $_SESSION['user_id'];
$requestID = $_POST['request_id'];
$revieweeID = $_POST['reviewee_id'];
$rating = intval($_POST['rating'] ?? 0);
$comment = trim($_POST['comment'] ?? '');

// Validate rating
if ($rating < 1 || $rating > 5) {
    header("Location: sitterPetSitRequestDetails.php?request_id=$requestID&error=Please select a valid rating (1-5 stars)");
    exit;
}

// Check if user is authorized to review this request
$check_sql = "SELECT psr.*, psr.SitterID, psr.OwnerID
              FROM PetSitRequest psr
              WHERE psr.SitRequestID = ? AND psr.Status = 'completed'";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("i", $requestID);
$check_stmt->execute();
$request_data = $check_stmt->get_result()->fetch_assoc();

if (!$request_data) {
    header("Location: sitterPetSitRequestDetails.php?request_id=$requestID&error=Cannot review this request");
    exit;
}

// Check if user sudah bagi review untuk request ini
$existing_review_sql = "SELECT * FROM review WHERE SitRequestID = ? AND ReviewerID = ?";
$existing_review_stmt = $conn->prepare($existing_review_sql);
$existing_review_stmt->bind_param("ii", $requestID, $userID);
$existing_review_stmt->execute();

if ($existing_review_stmt->get_result()->num_rows > 0) {
    header("Location: sitterPetSitRequestDetails.php?request_id=$requestID&error=You have already reviewed this request");
    exit;
}

// TENTUKAN SITTER ID BERDASARKAN CONTEXT
// Jika sitter yang review owner, maka SitterID = userID (sitter sendiri)
// Jika owner yang review sitter, maka SitterID = SitterID dari request
$sitter_id = $userID; // Default: assume user adalah sitter

// Jika user adalah owner dan sedang review sitter, guna SitterID dari request
if ($userID == $request_data['OwnerID']) {
    $sitter_id = $request_data['SitterID'];
}

// Insert review dengan SitterID
$insert_sql = "INSERT INTO review (SitRequestID, ReviewerID, SitterID, Rating, Comment) 
               VALUES (?, ?, ?, ?, ?)";
$insert_stmt = $conn->prepare($insert_sql);
$insert_stmt->bind_param("iiiis", $requestID, $userID, $sitter_id, $rating, $comment);

if ($insert_stmt->execute()) {
    header("Location: sitterPetSitRequestDetails.php?request_id=$requestID&success=Review submitted successfully!");
} else {
    header("Location: sitterPetSitRequestDetails.php?request_id=$requestID&error=Failed to submit review: " . $conn->error);
}
exit;
?>