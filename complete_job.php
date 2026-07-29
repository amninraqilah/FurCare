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

// Check if user is the sitter for this request
$check_sql = "SELECT psr.*, p.SitterEarnings 
              FROM PetSitRequest psr 
              LEFT JOIN payment p ON psr.SitRequestID = p.SitRequestID 
              WHERE psr.SitRequestID = ? AND psr.SitterID = ?";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("ii", $requestID, $userID);
$check_stmt->execute();
$request = $check_stmt->get_result()->fetch_assoc();

if (!$request) {
    header("Location: myApplications.php?error=Unauthorized access");
    exit;
}

// DEBUG: TENGOK DATA YANG KITA DAPAT
error_log("DEBUG - Request ID: " . $requestID);
error_log("DEBUG - SitEndDate: " . $request['SitEndDate']);
error_log("DEBUG - Status: " . $request['Status']);

// FIXED DATE COMPARISON
$today = new DateTime();
$endDate = new DateTime($request['SitEndDate']);

// NORMALIZE TIME TO MIDNIGHT FOR ACCURATE DATE COMPARISON
$today->setTime(0, 0, 0);
$endDate->setTime(0, 0, 0);

$hasEnded = ($today >= $endDate);

// DEBUG DATES
error_log("DEBUG - Today: " . $today->format('Y-m-d H:i:s'));
error_log("DEBUG - End Date: " . $endDate->format('Y-m-d H:i:s'));
error_log("DEBUG - Has Ended: " . ($hasEnded ? 'YES' : 'NO'));

// ALTERNATIVE: BOLEH COMPLETE PADA END DATE SENDIRI
// $hasEnded = ($today >= $endDate); // Guna ini jika nak allow pada end date

if ($request['Status'] !== 'approved' || !$hasEnded) {
    error_log("DEBUG - Validation Failed: Status=" . $request['Status'] . ", HasEnded=" . ($hasEnded ? 'YES' : 'NO'));
    header("Location: sitterPetSitRequestDetails.php?request_id=$requestID&error=Cannot complete job yet. Job can only be marked completed after the end date. Today: " . $today->format('Y-m-d') . ", End Date: " . $endDate->format('Y-m-d'));
    exit;
}

// Check payment status
$payment_sql = "SELECT * FROM payment WHERE SitRequestID = ? AND PaymentStatus = 'paid'";
$payment_stmt = $conn->prepare($payment_sql);
$payment_stmt->bind_param("i", $requestID);
$payment_stmt->execute();
$payment_info = $payment_stmt->get_result()->fetch_assoc();

if (!$payment_info) {
    header("Location: sitterPetSitRequestDetails.php?request_id=$requestID&error=Payment not confirmed yet");
    exit;
}

// Update sitter completion status
$update_sql = "UPDATE PetSitRequest 
               SET SitterCompleted = TRUE,
                   CompletionStatus = 'pending_owner_confirm',
                   SitterCompletedAt = NOW()
               WHERE SitRequestID = ?";
$update_stmt = $conn->prepare($update_sql);
$update_stmt->bind_param("i", $requestID);

if ($update_stmt->execute()) {
    echo "
    <script>
        alert('✅ Job marked as completed!\\\\n\\\\nOwner will be notified to confirm completion.\\\\nPayment will be released after owner confirmation.');
        window.location.href = 'sitterPetSitRequestDetails.php?request_id=$requestID&success=Job marked as completed! Waiting for owner confirmation.';
    </script>";
    exit;
} else {
    header("Location: sitterPetSitRequestDetails.php?request_id=$requestID&error=Failed to mark job as completed");
    exit;
}
?>