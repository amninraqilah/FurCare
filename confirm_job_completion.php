<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['request_id'])) {
    // 🆕 CHECK USER ROLE FOR REDIRECT
    $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
    if ($isAdmin) {
        header("Location: adminPetSitRequests.php");
    } else {
        header("Location: ownerRequests.php");
    }
    exit;
}

$userID = $_SESSION['user_id'];
$requestID = $_POST['request_id'];
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

// Check if user is authorized (owner atau admin)
if ($isAdmin) {
    // Admin boleh confirm mana-mana request
    $check_sql = "SELECT psr.*, p.SitterEarnings 
                  FROM PetSitRequest psr 
                  LEFT JOIN payment p ON psr.SitRequestID = p.SitRequestID 
                  WHERE psr.SitRequestID = ? AND psr.SitterCompleted = TRUE";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $requestID);
} else {
    // Owner hanya boleh confirm request sendiri
    $check_sql = "SELECT psr.*, p.SitterEarnings 
                  FROM PetSitRequest psr 
                  LEFT JOIN payment p ON psr.SitRequestID = p.SitRequestID 
                  WHERE psr.SitRequestID = ? AND psr.OwnerID = ? AND psr.SitterCompleted = TRUE";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $requestID, $userID);
}

$check_stmt->execute();
$request = $check_stmt->get_result()->fetch_assoc();

if (!$request) {
    $errorMsg = $isAdmin ? 
        "Request not found or sitter hasn't completed the job" : 
        "Unauthorized access or sitter hasn't completed the job";
    
    if ($isAdmin) {
        header("Location: adminPetSitRequests.php?error=" . urlencode($errorMsg));
    } else {
        header("Location: ownerRequests.php?error=" . urlencode($errorMsg));
    }
    exit;
}

// Start transaction
$conn->begin_transaction();

try {
    // Update owner confirmation status
    $update_sql = "UPDATE PetSitRequest 
                   SET OwnerConfirmed = TRUE,
                       CompletionStatus = 'completed',
                       Status = 'completed',
                       OwnerConfirmedAt = NOW(),
                       CompletedAt = NOW()
                   WHERE SitRequestID = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("i", $requestID);
    $update_stmt->execute();

    // 🎉 RELEASE FUNDS TO SITTER'S WALLET
    $sitterEarnings = $request['SitterEarnings'];
    
    // Check if wallet exists, if not create it
    $wallet_check = "SELECT * FROM user_wallet WHERE UserID = ?";
    $wallet_stmt = $conn->prepare($wallet_check);
    $wallet_stmt->bind_param("i", $request['SitterID']);
    $wallet_stmt->execute();
    $wallet_exists = $wallet_stmt->get_result()->fetch_assoc();

    if ($wallet_exists) {
        // Update existing wallet
        $wallet_sql = "UPDATE user_wallet SET Balance = Balance + ?, LastUpdated = NOW() WHERE UserID = ?";
        $wallet_update = $conn->prepare($wallet_sql);
        $wallet_update->bind_param("di", $sitterEarnings, $request['SitterID']);
    } else {
        // Create new wallet
        $wallet_sql = "INSERT INTO user_wallet (UserID, Balance, LastUpdated) VALUES (?, ?, NOW())";
        $wallet_update = $conn->prepare($wallet_sql);
        $wallet_update->bind_param("id", $request['SitterID'], $sitterEarnings);
    }
    
    $wallet_update->execute();

    $conn->commit();
    
    // 🆕 SUCCESS REDIRECT BASED ON USER ROLE
    $successMsg = "Job confirmed completed! RM" . number_format($sitterEarnings, 2) . " released to sitter's wallet.";
    
    if ($isAdmin) {
        header("Location: adminPetSitRequestDetails.php?request_id=$requestID&success=" . urlencode($successMsg));
    } else {
        header("Location: ownerPetSitRequestDetails.php?request_id=$requestID&success=" . urlencode($successMsg));
    }
    exit;

} catch (Exception $e) {
    $conn->rollback();
    
    // 🆕 ERROR REDIRECT BASED ON USER ROLE
    $errorMsg = "Failed to confirm job completion: " . $e->getMessage();
    
    if ($isAdmin) {
        header("Location: adminPetSitRequestDetails.php?request_id=$requestID&error=" . urlencode($errorMsg));
    } else {
        header("Location: ownerPetSitRequestDetails.php?request_id=$requestID&error=" . urlencode($errorMsg));
    }
    exit;
}
?>