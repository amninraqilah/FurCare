<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];
$requestID = $_GET['request_id'] ?? 0;

// Fetch pet sit request details dengan completion data
$stmt = $conn->prepare("SELECT psr.*, 
                               p.Name AS PetName, p.Image AS PetImage, p.Type AS PetType, 
                               p.Breed AS PetBreed, p.Age AS PetAge, p.Gender AS PetGender, 
                               p.Description AS PetDescription, p.District AS PetDistrict, 
                               p.State AS PetState, p.SitStartDate, p.SitEndDate,
                               p.Price AS DailyRate,  -- AMBIL HARGA DARI TABLE PET
                               owner.Name AS OwnerName, owner.Email AS OwnerEmail, 
                               owner.Phone AS OwnerPhone, owner.ProfilePicture AS OwnerPhoto, 
                               sitter.Name AS SitterName, sitter.Email AS SitterEmail, 
                               sitter.Phone AS SitterPhone, sitter.ProfilePicture AS SitterPhoto,
                               owner.UserID AS OwnerID,
                               psr.SitterCompleted, psr.OwnerConfirmed, psr.SitterCompletedAt, psr.OwnerConfirmedAt
                        FROM PetSitRequest psr
                        INNER JOIN pet p ON psr.PetID = p.PetID
                        INNER JOIN user owner ON psr.OwnerID = owner.UserID
                        INNER JOIN user sitter ON psr.SitterID = sitter.UserID
                        WHERE psr.SitRequestID = ?");

$stmt->bind_param("i", $requestID);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();

if (!$request) {
    header("Location: adminPetSitRequests.php?error=Request not found");
    exit;
}

$isAdminOwner = ($request['OwnerID'] == $userID);

// CALCULATE DATE CONDITIONS
$today = new DateTime();
$startDate = new DateTime($request['SitStartDate']);
$endDate = new DateTime($request['SitEndDate']);
$today->setTime(0, 0, 0);
$endDate->setTime(0, 0, 0);
$hasStarted = ($today >= $startDate);
$hasEnded = ($today >= $endDate);

// CHECK COMPLETION STATUSES
$ownerConfirmed = $request['OwnerConfirmed'] ?? 0;
$sitterCompleted = $request['SitterCompleted'] ?? 0;

// CHECK PAYMENT STATUS
$payment_sql = "SELECT * FROM payment WHERE SitRequestID = ?";
$payment_stmt = $conn->prepare($payment_sql);
$payment_stmt->bind_param("i", $requestID);
$payment_stmt->execute();
$payment_info = $payment_stmt->get_result()->fetch_assoc();

// CHECK IF CAN CONFIRM COMPLETION (OWNER SIDE)
$canConfirmCompletion = (
    $isAdminOwner &&
    $request['Status'] === 'approved' &&
    $payment_info &&
    $payment_info['PaymentStatus'] === 'paid' &&
    $hasEnded &&
    $sitterCompleted == 1 &&
    $ownerConfirmed == 0
);


// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $conn->begin_transaction();
        try {
            // Get pet ID and current approved request info first
            $petQuery = $conn->prepare("SELECT PetID FROM PetSitRequest WHERE SitRequestID = ?");
            $petQuery->bind_param("i", $requestID);
            $petQuery->execute();
            $petResult = $petQuery->get_result();
            $petData = $petResult->fetch_assoc();

            if ($petData) {
                $petID = $petData['PetID'];

                // Check if there's already an approved request for this pet
                $checkApprovedQuery = "SELECT COUNT(*) as count FROM PetSitRequest 
                                      WHERE PetID = ? AND Status = 'approved'";
                $checkStmt = $conn->prepare($checkApprovedQuery);
                $checkStmt->bind_param("i", $petID);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                $alreadyApproved = $checkResult->fetch_assoc()['count'];

                if ($alreadyApproved > 0) {
                    header("Location: adminPetSitRequestDetails.php?request_id=$requestID&error=This pet already has an approved sitting request");
                    exit;
                }

                // AUTO-REJECT ALL OTHER PENDING REQUESTS FOR THE SAME PET
                $rejectOthersStmt = $conn->prepare("UPDATE PetSitRequest 
                                                    SET Status = 'rejected', 
                                                        RejectionReason = 'Another request for this pet has been approved',
                                                        UpdatedAt = NOW()
                                                    WHERE PetID = ? 
                                                    AND Status = 'pending' 
                                                    AND SitRequestID != ?");
                $rejectOthersStmt->bind_param("ii", $petID, $requestID);
                $rejectOthersStmt->execute();
                $othersRejected = $rejectOthersStmt->affected_rows;

                // Update current request status to approved
                $updateStmt = $conn->prepare("UPDATE PetSitRequest SET Status = 'approved', UpdatedAt = NOW() WHERE SitRequestID = ?");
                $updateStmt->bind_param("i", $requestID);
                $updateStmt->execute();

                // Update pet status to 'pet sit'
                $updatePet = $conn->prepare("UPDATE pet SET Status = 'pet sit' WHERE PetID = ?");
                $updatePet->bind_param("i", $petID);
                $updatePet->execute();

                $conn->commit();

                $successMsg = "Pet sit request approved successfully";
                if ($othersRejected > 0) {
                    $successMsg .= ". " . $othersRejected . " other pending request(s) for this pet have been automatically rejected.";
                }

                header("Location: adminPetSitRequestDetails.php?request_id=$requestID&success=" . urlencode($successMsg));
                exit;
            }
        } catch (Exception $e) {
            $conn->rollback();
            header("Location: adminPetSitRequestDetails.php?request_id=$requestID&error=Failed to approve request: " . urlencode($e->getMessage()));
            exit;
        }
    } elseif ($action === 'reject') {
        $rejectionReason = $_POST['rejection_reason'] ?? '';
        if (!empty($rejectionReason)) {
            $updateStmt = $conn->prepare("UPDATE PetSitRequest SET Status = 'rejected', RejectionReason = ?, UpdatedAt = NOW() WHERE SitRequestID = ?");
            $updateStmt->bind_param("si", $rejectionReason, $requestID);
            if ($updateStmt->execute()) {
                header("Location: adminPetSitRequestDetails.php?request_id=$requestID&success=Pet sit request rejected successfully");
                exit;
            }
        }
    }

    // HANDLE JOB COMPLETION CONFIRMATION
    elseif ($action === 'confirm_completion') {
        if ($canConfirmCompletion) {
            // Start transaction
            $conn->begin_transaction();

            try {
                $update_sql = "UPDATE PetSitRequest 
       SET 
           -- HANYA set owner confirmation:
           OwnerConfirmed = 1,
           OwnerConfirmedAt = NOW(),
           
           -- Update status to completed:
           Status = 'completed',
           CompletionStatus = 'completed',
           CompletedAt = NOW()
       WHERE SitRequestID = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("i", $requestID);
                $update_stmt->execute();

                // 🎉 RELEASE FUNDS TO SITTER'S WALLET (90% untuk sitter)
                $sitterEarnings = $request['TotalAmount'] * 0.90;

                // Check if wallet exists, if not create it
                $wallet_check = "SELECT * FROM user_wallet WHERE UserID = ?";
                $wallet_stmt = $conn->prepare($wallet_check);
                $wallet_stmt->bind_param("i", $request['SitterID']);
                $wallet_stmt->execute();
                $wallet_exists = $wallet_stmt->get_result()->fetch_assoc();

                if ($wallet_exists) {
                    // Update existing wallet balance
                    $wallet_sql = "UPDATE user_wallet SET Balance = Balance + ?, LastUpdated = NOW() WHERE UserID = ?";
                    $wallet_update = $conn->prepare($wallet_sql);
                    $wallet_update->bind_param("di", $sitterEarnings, $request['SitterID']);
                } else {
                    // Create new wallet with initial balance
                    $wallet_sql = "INSERT INTO user_wallet (UserID, Balance, LastUpdated) VALUES (?, ?, NOW())";
                    $wallet_update = $conn->prepare($wallet_sql);
                    $wallet_update->bind_param("id", $request['SitterID'], $sitterEarnings);
                }

                $wallet_update->execute();

                // Untuk SITTER
                $update_sitter_sql = "UPDATE user SET total_transactions = total_transactions + 1 WHERE UserID = ?";
                $update_sitter_stmt = $conn->prepare($update_sitter_sql);
                $update_sitter_stmt->bind_param("i", $request['SitterID']);
                $update_sitter_stmt->execute();

                // Untuk OWNER
                $update_owner_sql = "UPDATE user SET total_transactions = total_transactions + 1 WHERE UserID = ?";
                $update_owner_stmt = $conn->prepare($update_owner_sql);
                $update_owner_stmt->bind_param("i", $request['OwnerID']);
                $update_owner_stmt->execute();

                // Update pet status back to 'available'
                $update_pet_sql = "UPDATE pet SET Status = 'available' WHERE PetID = ?";
                $update_pet_stmt = $conn->prepare($update_pet_sql);
                $update_pet_stmt->bind_param("i", $request['PetID']);
                $update_pet_stmt->execute();

                $conn->commit();

                header("Location: adminPetSitRequestDetails.php?request_id=$requestID&success=Job confirmed completed! RM" . number_format($sitterEarnings, 2) . " released to sitter's wallet.");
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                header("Location: adminPetSitRequestDetails.php?request_id=$requestID&error=Failed to confirm job completion: " . $e->getMessage());
                exit;
            }
        } else {
            header("Location: adminPetSitRequestDetails.php?request_id=$requestID&error=Cannot confirm job completion. Make sure sitter has marked as completed and job period has ended.");
            exit;
        }
    } // Di bagian elseif ($action === 'submit_review'):
    elseif ($action === 'submit_review') {
        $rating = $_POST['rating'] ?? 0;
        $comment = $_POST['comment'] ?? '';
        $reviewerID = $userID;
        $sitterID = $request['SitterID'];

        // Validate rating
        if ($rating < 1 || $rating > 5) {
            header("Location: adminPetSitRequestDetails.php?request_id=$requestID&error=Please select a rating (1-5 stars)");
            exit;
        }

        // Validate comment
        $comment = trim($comment);
        if (empty($comment)) {
            header("Location: adminPetSitRequestDetails.php?request_id=$requestID&error=Please write your review comment");
            exit;
        }

        if (strlen($comment) < 10) {
            header("Location: adminPetSitRequestDetails.php?request_id=$requestID&error=Review comment must be at least 10 characters long");
            exit;
        }

        // Check if already reviewed
        $checkStmt = $conn->prepare("SELECT * FROM review WHERE SitRequestID = ? AND ReviewerID = ?");
        $checkStmt->bind_param("ii", $requestID, $userID);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            header("Location: adminPetSitRequestDetails.php?request_id=$requestID&error=You have already submitted a review for this request");
            exit;
        }

        // Insert review
        $reviewStmt = $conn->prepare("INSERT INTO review (SitRequestID, ReviewerID, SitterID, Rating, Comment) VALUES (?, ?, ?, ?, ?)");
        $reviewStmt->bind_param("iiiis", $requestID, $reviewerID, $sitterID, $rating, $comment);
        if ($reviewStmt->execute()) {
            header("Location: adminPetSitRequestDetails.php?request_id=$requestID&success=Review submitted successfully");
            exit;
        } else {
            header("Location: adminPetSitRequestDetails.php?request_id=$requestID&error=Failed to submit review. Please try again.");
            exit;
        }
    }
}

// Function to format age from float to readable format
function formatAge($ageYears)
{
    if ($ageYears == 0 || $ageYears == '' || is_null($ageYears)) {
        return 'Unknown';
    }

    $totalMonths = round($ageYears * 12);
    $years = floor($totalMonths / 12);
    $months = $totalMonths % 12;

    if ($years == 0 && $months == 0) {
        return 'Less than 1 month';
    } elseif ($years == 0) {
        return $months . ' month' . ($months > 1 ? 's' : '');
    } elseif ($months == 0) {
        return $years . ' year' . ($years > 1 ? 's' : '');
    } else {
        return $years . ' year' . ($years > 1 ? 's' : '') .
            ' ' . $months . ' month' . ($months > 1 ? 's' : '');
    }
}

// Fetch admin data
$user_stmt = $conn->prepare("SELECT * FROM user WHERE UserID = ?");
$user_stmt->bind_param("i", $userID);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

// Fetch reviews
$reviews_sql = "SELECT r.*, u.Name as ReviewerName, u.ProfilePicture as ReviewerPhoto 
                FROM review r 
                JOIN user u ON r.ReviewerID = u.UserID 
                WHERE r.SitRequestID = ? 
                ORDER BY r.CreatedAt DESC";
$reviews_stmt = $conn->prepare($reviews_sql);
$reviews_stmt->bind_param("i", $requestID);
$reviews_stmt->execute();
$reviews = $reviews_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Check reviews
$hasReviewed = false;
if ($isAdminOwner) {
    $checkReviewStmt = $conn->prepare("SELECT * FROM review WHERE SitRequestID = ? AND ReviewerID = ?");
    $checkReviewStmt->bind_param("ii", $requestID, $userID);
    $checkReviewStmt->execute();
    $hasReviewed = $checkReviewStmt->get_result()->num_rows > 0;
}

$canReview = $isAdminOwner && $request['Status'] === 'completed' && !$hasReviewed;

// Check how many other pending requests exist for this pet
$otherRequestsQuery = "SELECT COUNT(*) as count FROM PetSitRequest 
                      WHERE PetID = ? AND Status = 'pending' AND SitRequestID != ?";
$otherStmt = $conn->prepare($otherRequestsQuery);
$otherStmt->bind_param("ii", $request['PetID'], $requestID);
$otherStmt->execute();
$otherResult = $otherStmt->get_result();
$otherRequests = $otherResult->fetch_assoc()['count'];

// CHECK FOR OVERDUE STATUS
$isOverdue = false;
$overdueReason = '';

// Hanya check untuk pending requests
if ($request['Status'] === 'pending') {
    // Buat copy startDate dan tambah 1 hari
    $overdueDate = clone $startDate;
    $overdueDate->modify('+1 day'); // Overdue bermula sehari selepas start date
    
    // Check if today is ON or AFTER overdue date (start date + 1)
    if ($today >= $overdueDate) {
        $isOverdue = true;
        
        // Calculate how many days overdue
        $daysOverdue = $today->diff($overdueDate)->days;
        
        if ($daysOverdue == 0) {
            // Today is exactly 1 day after start date (first overdue day)
            $overdueReason = "Sit started yesterday (" . $startDate->format('M j, Y') . ") but request is still pending";
        } else {
            // More than 1 day overdue
            $overdueReason = "Sit started " . ($daysOverdue + 1) . " days ago (" . $startDate->format('M j, Y') . ") but request is still pending";
        }
        
        // Add info about multiple requests if any
        if ($otherRequests > 0) {
            $overdueReason .= " (Owner hasn't approved any sitter from " . ($otherRequests + 1) . " requests)";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Pet Sit Request Details - FurCare</title>
    <link rel="stylesheet" href="css/adminDashboard.css">
    <link rel="stylesheet" href="css/adminPetSitRequestDetails.css">
    <style>
        /* ===== JOB COMPLETION CARD ===== */
        .completion-card {
            border: 1px solid #F7D9A8;
            /* pastel peach */
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            margin-top: 15px;
        }

        /* ===== PROGRESS TRACKER ===== */
        .job-progress-tracker {
            background: #FFF7F9;
            border: 1px solid #F5B7C5;
            /* pastel pink */
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .progress-step {
            text-align: center;
            padding: 12px;
            border-radius: 8px;
            transition: 0.3s;
        }

        .progress-step.completed {
            background: #A5D6A7;
            /* pastel green */
            color: white;
        }

        .progress-step.pending {
            background: #f5f5f5;
            color: #666;
        }

        .progress-step .step-number {
            font-size: 1.3em;
            font-weight: bold;
        }

        .progress-step .step-title {
            font-size: 0.9em;
            margin-top: 2px;
        }

        .progress-step .step-status {
            font-size: 0.8em;
            margin-top: 6px;
        }

        /* Step Number */
        .progress-step div:first-child {
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 4px;
        }

        /* Completed Step Number Bubble */
        .step-completed div:first-child {
            background: white;
            color: #2E7D32;
            display: inline-block;
            padding: 4px 8px;
            border-radius: 50%;
            border: 1px solid #A5D6A7;
        }

        /* ===== STATUS GRID ===== */
        .completion-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-top: 20px;
        }

        .status-item {
            background: #ffffff;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid #e6e6e6;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            transition: 0.2s ease-in-out;
        }

        .status-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
        }

        .status-label {
            display: block;
            font-size: 0.9rem;
            color: #7a7a7a;
            margin-bottom: 6px;
            font-weight: normal;
        }

        .status-value {
            font-size: 1rem;
            font-weight: normal;
            /* No bold */
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Completed = Green pastel */
        .status-value.completed {
            color: #2ecc71;
        }

        /* Pending = soft orange/pink */
        .status-value.pending {
            color: #e67e22;
        }

        /* ===== CONFIRMATION BOX ===== */
        .confirmation-prompt {
            background: #FFF3E0;
            border: 1px solid #F7D9A8;
            padding: 18px;
            border-radius: 10px;
            margin-top: 20px;
        }

        .btn-confirm {
            background: #7FB3D5;
            padding: 10px 15px;
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            margin-top: 10px;
            transition: 0.3s;
        }

        .btn-confirm:hover {
            background: #6AA2C4;
        }


        .confirmation-prompt {
            background: #fff8e6;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            border: 1px solid #ffc107;
        }

        .waiting-info {
            background: #e6f3ff;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            border: 1px solid #2196f3;
        }

        .waiting-sitter {
            background: #fff3cd;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            border: 1px solid #ffc107;
        }

        .completed-info {
            background: #e8f5e9;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            border: 1px solid #4caf50;
        }

        .admin-owner-notice {
            background: #e6f3ff;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border: 1px solid #2196f3;
        }

        .warning-notice {
            background: #fff3cd;
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            border: 1px solid #ffc107;
        }

        /* ===== REJECTION REASON STYLES ===== */
        .rejection-box {
            background: #fff3f3;
            border: 1px solid #ffcccc;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            border-left: 5px solid #ff6b6b;
        }

        .rejection-title {
            font-weight: bold;
            color: #d32f2f;
            margin-bottom: 10px;
            font-size: 1.1em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .rejection-title:before {
            font-size: 1.2em;
        }

        .rejection-box>div {
            color: #333;
            line-height: 1.6;
            white-space: pre-wrap;
            background: white;
            padding: 12px;
            border-radius: 6px;
            border: 1px solid #ffe6e6;
        }

        /* Enhanced styles for Pet Sit Request rejection */
        .rejection-box.important {
            background: #fff8e1;
            border-color: #ffd54f;
            border-left-color: #ffb300;
        }

        .rejection-box.important .rejection-title {
            color: #ff8f00;
        }

        .rejection-box.important .rejection-title:before {
            content: "⚠️";
        }

        /* Animation for rejection box */
        @keyframes fadeInRejection {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .rejection-box {
            animation: fadeInRejection 0.3s ease-out;
        }

        .status-overdue {
            background: #ffecb3 !important;
            color: #ff6f00 !important;
            border: 1px solid #ffd54f !important;
        }

        /* ===== OVERDUE ALERT STYLES ===== */
        .overdue-alert {
            background: #fff8e1;
            border: 2px solid #ffd54f;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }

        .overdue-alert h4 {
            color: #ff6f00;
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .overdue-alert h4:before {
            content: "⚠️";
            font-size: 1.2em;
        }

        .overdue-reason {
            background: white;
            padding: 12px;
            border-radius: 6px;
            margin-top: 10px;
            border: 1px solid #ffcc80;
        }

        .overdue-reason p {
            margin: 0;
            color: #333;
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <h2 class="logo">FurCare</h2>
        <a href="adminDashboard.php">🗂️ Main Menu</a>
        <a href="index.php">🏠 Home</a>
        <a href="adminBrowsePet.php">🔍 Browse Pets</a>
        <a href="adminManagePets.php">🐾 Manage Pets</a>
        <a href="manageUsers.php">👥 Manage Users</a>
        <a href="adminAdoptionRequests.php">📋 Adoption Request</a>
        <a href="adminPetSitRequests.php" class="active">🏠 Pet Sit Request</a>
        <a href="reports.php">📑 Reports</a>
        <a href="adminSetting.php">⚙️ Settings</a>
        <a href="logout.php" class="logout">🚪 Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="navbar">
            <h1>Pet Sit Request Details</h1>
            <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
                alt="Profile" class="profile-icon">
        </div>

        <div class="compact-layout">
            <!-- Summary Cards -->
            <div class="summary-cards">
                <div class="summary-card">
                    <h3>Status</h3>
                    <div class="value status-badge status-<?php echo $request['Status']; ?>">
                        <?php echo ucfirst($request['Status']); ?>
                    </div>
                </div>
                <div class="summary-card">
                    <h3>Total Amount</h3>
                    <div class="value">RM <?php echo number_format($request['TotalAmount'], 2); ?></div>
                </div>
                <div class="summary-card">
                    <h3>Duration</h3>
                    <div class="value"><?php echo $request['TotalDays']; ?> days</div>
                </div>
                <div class="summary-card">
                    <h3>Request Date</h3>
                    <div class="value"><?php echo date('M j, Y', strtotime($request['RequestDate'])); ?></div>
                </div>
            </div>

            <!-- ADMIN AS OWNER NOTICE -->
            <?php if ($isAdminOwner): ?>
                <div class="admin-owner-notice">
                    <strong>You are the owner of this pet</strong>
                    <p>As the pet owner, you can make payment and confirm job completion for this pet sitting service.</p>
                </div>
            <?php endif; ?>

            <!-- Success/Error Messages -->
            <?php if (isset($_GET['success'])): ?>
                <div class="success-message">✅ <?php echo htmlspecialchars($_GET['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="error-message">❌ <?php echo htmlspecialchars($_GET['error']); ?></div>
            <?php endif; ?>

            <div class="compact-grid">
                <!-- Left Column: Pet Card -->
                <div class="left-column">
                    <div class="card pet-card">
                        <img src="<?php echo htmlspecialchars($request['PetImage']); ?>"
                            alt="<?php echo htmlspecialchars($request['PetName']); ?>"
                            class="pet-image">
                        <h3 class="pet-name"><?php echo htmlspecialchars($request['PetName']); ?></h3>
                        <div class="pet-details">
                            <p><strong>Type:</strong> <?php echo htmlspecialchars($request['PetType']); ?></p>
                            <p><strong>Breed:</strong> <?php echo htmlspecialchars($request['PetBreed']); ?></p>
                            <p><strong>Age:</strong> <?php echo formatAge($request['PetAge']); ?></p>
                            <p><strong>Gender:</strong> <?php echo htmlspecialchars($request['PetGender']); ?></p>
                            <p><strong>Location:</strong> <?php echo htmlspecialchars($request['PetDistrict'] . ', ' . $request['PetState']); ?></p>
                        </div>

                        <!-- Payment Button Section -->
                        <div class="payment-section">
                            <?php if ($isAdminOwner): ?>
                                <?php if ($request['Status'] === 'approved' || $request['Status'] === 'completed'): ?>
                                    <?php if (!$payment_info): ?>
                                        <a href="adminMakePayment.php?request_id=<?php echo $requestID; ?>" class="btn-pay">
                                            Pay RM<?php echo number_format($request['TotalAmount'], 2); ?>
                                        </a>
                                        <p style="font-size: 0.9em; color: #666; margin-top: 5px;">
                                            <em>Sitter will earn: RM<?php echo number_format($request['TotalAmount'] * 0.90, 2); ?></em>
                                        </p>
                                    <?php else: ?>
                                        <div class="payment-completed">
                                            <div class="status-paid">Paid</div>
                                            <p class="receipt-info">
                                                Receipt: <?php echo htmlspecialchars($payment_info['ReceiptNumber'] ?? 'N/A'); ?>
                                            </p>
                                            <p style="font-size: 0.9em; color: #4caf50; margin-top: 5px;">
                                                <strong>Sitter earnings:</strong> RM<?php echo number_format($payment_info['SitterEarnings'] ?? ($request['TotalAmount'] * 0.90), 2); ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="payment-pending">
                                        <p>⏳ Payment available after approval</p>
                                        <p style="font-size: 0.9em; color: #666; margin-top: 5px;">
                                            <em>Sitter earnings upon completion: RM<?php echo number_format($request['TotalAmount'] * 0.90, 2); ?></em>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Main Content -->
                <div class="right-column">
                    <!-- Tabs Navigation -->
                    <div class="tabs-container">
                        <div class="tabs-header">
                            <div class="tab active" onclick="openTab('details')">Request Details</div>
                            <div class="tab" onclick="openTab('payment')">Payment Info</div>
                            <div class="tab" onclick="openTab('progress')">Job Progress</div>
                            <div class="tab" onclick="openTab('reviews')">Reviews</div>
                        </div>

                        <!-- Details Tab -->
                        <div id="details-tab" class="tab-content active">
                            <!-- Sitting Period Accordion -->
                            <div class="accordion active">
                                <div class="accordion-header">
                                    <span>Sitting Period & Pricing</span>
                                    <span class="accordion-icon">▼</span>
                                </div>
                                <div class="accordion-content">
                                    <div class="period-grid">
                                        <div class="period-item">
                                            <div class="period-label">Start Date</div>
                                            <div class="period-value">
                                                <?php echo !empty($request['SitStartDate']) ? date('F j, Y', strtotime($request['SitStartDate'])) : 'Not set'; ?>
                                            </div>
                                        </div>
                                        <div class="period-item">
                                            <div class="period-label">End Date</div>
                                            <div class="period-value">
                                                <?php
                                                if (!empty($request['SitEndDate']) && $request['SitEndDate'] !== '0000-00-00') {
                                                    echo date('F j, Y', strtotime($request['SitEndDate']));
                                                } else {
                                                    echo 'Ongoing';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div class="period-item">
                                            <div class="period-label">Duration</div>
                                            <div class="period-value">
                                                <?php
                                                if (!empty($request['SitStartDate']) && !empty($request['SitEndDate']) && $request['SitEndDate'] !== '0000-00-00') {
                                                    $start = new DateTime($request['SitStartDate']);
                                                    $end = new DateTime($request['SitEndDate']);
                                                    $days = $start->diff($end)->days + 1;
                                                    echo $days . ' days';
                                                } else {
                                                    echo '-';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="price-summary">
                                        <h4>Price Summary</h4>
                                        <div class="price-row">
                                            <span>Daily Rate:</span>
                                            <span>RM <?php echo number_format($request['DailyRate'], 2); ?> / day</span>
                                        </div>
                                        <div class="price-row">
                                            <span>Duration:</span>
                                            <span><?php echo $request['TotalDays']; ?> days</span>
                                        </div>
                                        <div class="price-row total">
                                            <span>Total Amount:</span>
                                            <span>RM <?php echo number_format($request['TotalAmount'], 2); ?></span>
                                        </div>
                                        <div class="price-row" style="color: #4caf50;">
                                            <span>Sitter Earnings (90%):</span>
                                            <span>RM <?php echo number_format($request['TotalAmount'] * 0.90, 2); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Request Info Accordion -->
                            <div class="accordion active">
                                <div class="accordion-header">
                                    <span>Request Information</span>
                                    <span class="accordion-icon">▼</span>
                                </div>
                                <div class="accordion-content">
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="info-label">Request ID</span>
                                            <span class="info-value">#<?php echo $request['SitRequestID']; ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Request Date</span>
                                            <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($request['RequestDate'])); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Last Updated</span>
                                            <span class="info-value">
                                                <?php
                                                echo !empty($request['UpdatedAt']) ?
                                                    date('M j, Y g:i A', strtotime($request['UpdatedAt'])) :
                                                    'Never updated';
                                                ?>
                                            </span>
                                        </div>
                                    </div>

                                    <?php if (!empty($request['SitterMessage'])): ?>
                                        <div class="info-item-full">
                                            <span class="info-label">Sitter's Message</span>
                                            <div class="info-value"><?php echo nl2br(htmlspecialchars($request['SitterMessage'])); ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($request['Status'] === 'rejected' && !empty($request['RejectionReason'])): ?>
                                        <div class="rejection-box">
                                            <div class="rejection-title">Reason for Rejection:</div>
                                            <div><?php echo nl2br(htmlspecialchars($request['RejectionReason'])); ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- OVERDUE ALERT - DUA SEBAB SAHAJA -->
                                    <?php if ($isOverdue): ?>
                                        <div class="overdue-alert">
                                            <h4>OVERDUE REQUEST</h4>
                                            <div class="overdue-reason">
                                                <p><strong>Reason:</strong> <?php echo htmlspecialchars($overdueReason); ?></p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Pet Details Accordion -->
                            <div class="accordion">
                                <div class="accordion-header">
                                    <span>Pet Details</span>
                                    <span class="accordion-icon">▼</span>
                                </div>
                                <div class="accordion-content">
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="info-label">Pet Name</span>
                                            <span class="info-value"><?php echo htmlspecialchars($request['PetName']); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Type</span>
                                            <span class="info-value"><?php echo htmlspecialchars($request['PetType']); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Breed</span>
                                            <span class="info-value"><?php echo htmlspecialchars($request['PetBreed']); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Age</span>
                                            <span class="info-value"><?php echo formatAge($request['PetAge']); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Gender</span>
                                            <span class="info-value"><?php echo htmlspecialchars($request['PetGender']); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Location</span>
                                            <span class="info-value"><?php echo htmlspecialchars($request['PetDistrict'] . ', ' . $request['PetState']); ?></span>
                                        </div>
                                    </div>

                                    <?php if (!empty($request['PetDescription'])): ?>
                                        <div class="info-item-full">
                                            <span class="info-label">Description</span>
                                            <div class="info-value"><?php echo nl2br(htmlspecialchars($request['PetDescription'])); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Tab -->
                        <div id="payment-tab" class="tab-content">
                            <div class="payment-receipt-card">
                                <h3>Payment & Receipt Information</h3>

                                <?php if ($payment_info): ?>
                                    <div class="receipt-details">
                                        <div class="info-grid">
                                            <div class="info-item">
                                                <span class="info-label">Payment Status</span>
                                                <span class="status-badge status-<?php echo strtolower($payment_info['PaymentStatus']); ?>">
                                                    <?php echo ucfirst($payment_info['PaymentStatus']); ?>
                                                </span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Receipt Number</span>
                                                <span class="info-value"><?php echo htmlspecialchars($payment_info['ReceiptNumber']); ?></span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Amount Paid</span>
                                                <span class="info-value">RM<?php echo number_format($payment_info['Amount'], 2); ?></span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Payment Date</span>
                                                <span class="info-value"><?php echo date('F j, Y g:i A', strtotime($payment_info['PaymentDate'])); ?></span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Payment Method</span>
                                                <span class="info-value"><?php echo ucfirst(str_replace('_', ' ', $payment_info['PaymentMethod'])); ?></span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Sitter Earnings</span>
                                                <span class="info-value" style="color: #48bb78; font-weight: bold;">
                                                    RM<?php echo number_format($payment_info['SitterEarnings'], 2); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <button class="btn-download-receipt" onclick="downloadReceipt(<?php echo $payment_info['PaymentID']; ?>)">
                                            Download Receipt
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="no-payment">
                                        <p>No payment record found for this request</p>
                                        <?php if ($isAdminOwner && ($request['Status'] === 'approved' || $request['Status'] === 'completed')): ?>
                                            <a href="adminMakePayment.php?request_id=<?php echo $requestID; ?>" class="btn-pay">
                                                Make Payment Now - RM<?php echo number_format($request['TotalAmount'], 2); ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div id="progress-tab" class="tab-content">
                            <div class="completion-card">
                                <h3>Job Completion Status</h3>

                                <!-- JOB PROGRESS TRACKER -->
                                <?php if ($request['Status'] === 'approved' && $payment_info): ?>
                                    <div class="job-progress-tracker">
                                        <h4 style="margin-top:0;">Job Progress</h4>

                                        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-top:15px;">

                                            <!-- Step 1 -->
                                            <div class="progress-step <?php echo $payment_info ? 'completed' : 'pending'; ?>">
                                                <div class="step-number">1</div>
                                                <div class="step-title">Payment</div>
                                                <div class="step-status">
                                                    <?php echo $payment_info ? 'Paid' : '⏳'; ?>
                                                </div>
                                            </div>

                                            <!-- Step 2 -->
                                            <div class="progress-step <?php echo $hasEnded ? 'completed' : 'pending'; ?>">
                                                <div class="step-number">2</div>
                                                <div class="step-title">Job Period</div>
                                                <div class="step-status">
                                                    <?php echo $hasEnded ? 'Ended' : '⏳'; ?>
                                                </div>
                                            </div>

                                            <!-- Step 3 -->
                                            <div class="progress-step <?php echo $sitterCompleted ? 'completed' : 'pending'; ?>">
                                                <div class="step-number">3</div>
                                                <div class="step-title">Sitter Confirm</div>
                                                <div class="step-status">
                                                    <?php echo $sitterCompleted ? 'Done' : '⏳'; ?>
                                                </div>
                                            </div>

                                            <!-- Step 4 -->
                                            <div class="progress-step <?php echo $ownerConfirmed ? 'completed' : 'pending'; ?>">
                                                <div class="step-number">4</div>
                                                <div class="step-title">Owner Confirm</div>
                                                <div class="step-status">
                                                    <?php echo $ownerConfirmed ? 'Done' : '⏳'; ?>
                                                </div>
                                            </div>

                                        </div>

                                        <?php if ($sitterCompleted && $request['SitterCompletedAt']): ?>
                                            <p style="margin-top:10px; font-size:0.9em;">
                                                <strong>Sitter completed on:</strong>
                                                <?php echo date('F j, Y g:i A', strtotime($request['SitterCompletedAt'])); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- STATUS SUMMARY -->
                                <div class="completion-grid">
                                    <div class="status-item">
                                        <span class="status-label">Sitter Marked Completed</span>
                                        <span class="status-value <?php echo $sitterCompleted ? 'completed' : 'pending'; ?>">
                                            <?php echo $sitterCompleted ? 'Yes' : '⏳ No'; ?>
                                        </span>
                                    </div>

                                    <div class="status-item">
                                        <span class="status-label">Owner Confirmed</span>
                                        <span class="status-value <?php echo $ownerConfirmed ? 'completed' : 'pending'; ?>">
                                            <?php echo $ownerConfirmed ? 'Yes' : '⏳ No'; ?>
                                        </span>
                                    </div>

                                    <div class="status-item">
                                        <span class="status-label">Final Status</span>
                                        <span class="status-value <?php echo $request['Status'] === 'completed' ? 'completed' : 'pending'; ?>">
                                            <?php echo $request['Status'] === 'completed' ? 'Completed' : '⏳ In Progress'; ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- CONFIRMATION FLOW -->
                                <?php if ($canConfirmCompletion): ?>
                                    <div class="confirmation-prompt">

                                        <h4 style="margin-top:0;">🔔 Job Ready for Confirmation</h4>
                                        <p><strong>Sitter has marked the job completed!</strong></p>

                                        <div style="background:white; padding:15px; border-radius:8px; margin:15px 0;">
                                            <p>Please verify:</p>
                                            <ul style="padding-left:20px;">
                                                <li>Pet is healthy</li>
                                                <li>Sitting period has ended</li>
                                                <li>No issues reported</li>
                                            </ul>

                                            <p><strong>Completed On:</strong>
                                                <?php echo date('F j, Y g:i A', strtotime($request['SitterCompletedAt'])); ?>
                                            </p>

                                            <p><strong>Sitter Earnings:</strong>
                                                RM<?php echo number_format($request['TotalAmount'] * 0.90, 2); ?>
                                            </p>
                                        </div>

                                        <form method="POST" action="">
                                            <input type="hidden" name="action" value="confirm_completion">
                                            <button class="btn-confirm">
                                                ✅ Confirm Completion & Release Payment
                                            </button>
                                        </form>
                                    </div>

                                <?php elseif ($ownerConfirmed): ?>
                                    <div class="confirmation-prompt">
                                        <p><strong>✅ Job Completed & Payment Released</strong></p>
                                    </div>
                                <?php endif; ?>

                            </div>
                        </div>


                        <!-- Reviews Tab -->
                        <div id="reviews-tab" class="tab-content">
                            <div class="reviews-card">
                                <h3>⭐ Reviews & Ratings</h3>

                                <?php if ($canReview): ?>
                                    <div class="review-prompt">
                                        <p><strong>How was your experience with <?php echo htmlspecialchars($request['SitterName']); ?>?</strong></p>
                                        <button class="btn btn-review" onclick="openReviewModal()">⭐ Write a Review</button>
                                    </div>
                                <?php elseif ($isAdminOwner && $hasReviewed): ?>
                                    <div class="review-prompt">
                                        <p style="color: #48bb78;">✅ You have already submitted a review.</p>
                                    </div>
                                <?php endif; ?>

                                <?php if (count($reviews) > 0): ?>
                                    <div class="reviews-container">
                                        <?php foreach ($reviews as $review): ?>
                                            <div class="review-item">
                                                <div class="review-header">
                                                    <img src="<?php echo !empty($review['ReviewerPhoto']) ? htmlspecialchars($review['ReviewerPhoto']) : 'uploads/profile_icon.png'; ?>"
                                                        alt="<?php echo htmlspecialchars($review['ReviewerName']); ?>">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($review['ReviewerName']); ?></strong>
                                                        <div class="rating-stars">
                                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                <span class="star <?php echo $i <= $review['Rating'] ? 'active' : ''; ?>">★</span>
                                                            <?php endfor; ?>
                                                            <span>(<?php echo $review['Rating']; ?>/5)</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <p class="review-comment">"<?php echo htmlspecialchars($review['Comment']); ?>"</p>
                                                <small class="review-date">
                                                    <?php echo date('F j, Y g:i A', strtotime($review['CreatedAt'])); ?>
                                                </small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="no-reviews">
                                        <p>No reviews yet for this pet sitting request</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Horizontal Bottom Section -->
            <div class="horizontal-section">
                <div class="horizontal-grid">
                    <!-- Quick Actions -->
                    <div class="horizontal-card quick-actions">
                        <h3>Quick Actions</h3>
                        <div class="actions-grid">
                            <a href="adminPetSitRequests.php" class="action-btn back">
                                Back to List
                            </a>
                            <a href="mailto:<?php echo htmlspecialchars($request['OwnerEmail']); ?>?cc=<?php echo htmlspecialchars($request['SitterEmail']); ?>"
                                class="action-btn contact">
                                📧 Contact Both
                            </a>

                            <?php if ($request['Status'] === 'pending'): ?>
                                <button class="action-btn approve" onclick="openApproveModal()">
                                    Approve Request
                                </button>
                                <button class="action-btn reject" onclick="openRejectModal()">
                                    Reject Request
                                </button>
                            <?php endif; ?>

                            <?php if ($canConfirmCompletion): ?>
                                <!-- BUTTON CONFIRM COMPLETION IN QUICK ACTIONS -->
                                <form method="POST" action="adminPetSitRequestDetails.php?request_id=<?php echo $requestID; ?>" style="display: inline;">
                                    <input type="hidden" name="action" value="confirm_completion">
                                    <button type="submit" class="action-btn confirm-completion"
                                        onclick="return confirm('Confirm job completion and release payment?')">
                                        ✅ Confirm Completion
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($canReview): ?>
                                <button class="action-btn review" onclick="openReviewModal()">
                                    ⭐ Write Review
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Parties Involved -->
                    <div class="horizontal-card parties">
                        <h3>Parties Involved</h3>
                        <div class="parties-grid">
                            <!-- Owner -->
                            <div class="party-card">
                                <img src="<?php echo !empty($request['OwnerPhoto']) ? htmlspecialchars($request['OwnerPhoto']) : 'uploads/profile_icon.png'; ?>"
                                    class="party-avatar" alt="Owner">
                                <div class="party-details">
                                    <h4><?php echo htmlspecialchars($request['OwnerName']); ?></h4>
                                    <p class="party-role">Pet Owner <?php echo $isAdminOwner ? '(You)' : ''; ?></p>
                                    <div class="party-contact">
                                        <a href="mailto:<?php echo htmlspecialchars($request['OwnerEmail']); ?>">
                                            📧 <?php echo htmlspecialchars($request['OwnerEmail']); ?>
                                        </a>
                                        <p>📞 <?php echo htmlspecialchars($request['OwnerPhone']); ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Sitter -->
                            <div class="party-card">
                                <img src="<?php echo !empty($request['SitterPhoto']) ? htmlspecialchars($request['SitterPhoto']) : 'uploads/profile_icon.png'; ?>"
                                    class="party-avatar" alt="Sitter">
                                <div class="party-details">
                                    <h4><?php echo htmlspecialchars($request['SitterName']); ?></h4>
                                    <p class="party-role">Pet Sitter</p>
                                    <div class="party-contact">
                                        <a href="mailto:<?php echo htmlspecialchars($request['SitterEmail']); ?>">
                                            📧 <?php echo htmlspecialchars($request['SitterEmail']); ?>
                                        </a>
                                        <p>📞 <?php echo htmlspecialchars($request['SitterPhone']); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Approve Pet Sit Request</h3>
                <span class="close" onclick="closeApproveModal()">&times;</span>
            </div>
            <form method="POST" action="adminPetSitRequestDetails.php?request_id=<?php echo $requestID; ?>">
                <input type="hidden" name="action" value="approve">
                <div class="form-group">
                    <p>Are you sure you want to approve this pet sit request?</p>

                    <?php if ($otherRequests > 0): ?>
                        <div class="warning-notice">
                            <strong>⚠️ Notice:</strong> Approving this request will automatically reject
                            <strong><?php echo $otherRequests; ?> other pending request(s)</strong> for the same pet.
                        </div>
                    <?php endif; ?>

                    <p><strong>This will:</strong></p>
                    <ul>
                        <li>Mark this request as <strong>Approved</strong></li>
                        <?php if ($otherRequests > 0): ?>
                            <li>Automatically reject <?php echo $otherRequests; ?> other pending request(s) for this pet</li>
                        <?php endif; ?>
                        <li>Notify both owner and sitter</li>
                        <li>Change pet status to 'pet sit'</li>
                        <li>Allow the sitting arrangement to proceed</li>
                    </ul>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-back" onclick="closeApproveModal()">Cancel</button>
                    <button type="submit" class="btn btn-approve">Confirm Approval</button>
                </div>
            </form>
        </div>
    </div>

    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reject Pet Sit Request</h3>
                <span class="close" onclick="closeRejectModal()">&times;</span>
            </div>
            <form method="POST" action="adminPetSitRequestDetails.php?request_id=<?php echo $requestID; ?>">
                <input type="hidden" name="action" value="reject">
                <div class="form-group">
                    <label>Reason for Rejection (Required):</label>
                    <textarea name="rejection_reason" placeholder="Please provide a clear reason for rejecting this pet sit request..." required></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-back" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn btn-reject">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>

    <div id="reviewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Write a Review</h3>
                <span class="close" onclick="closeReviewModal()">&times;</span>
            </div>
            <form method="POST" action="adminPetSitRequestDetails.php?request_id=<?php echo $requestID; ?>">
                <input type="hidden" name="action" value="submit_review">
                <div class="form-group">
                    <label>Rating (1-5 stars):</label>
                    <div class="star-rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <span class="star-select" data-rating="<?php echo $i; ?>" onclick="setRating(<?php echo $i; ?>)">★</span>
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="rating" id="rating" value="0" required>
                </div>
                <div class="form-group">
                    <label>Your Review:</label>
                    <textarea name="comment" placeholder="Share your experience with the pet sitter..." required></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-back" onclick="closeReviewModal()">Cancel</button>
                    <button type="submit" class="btn btn-review">Submit Review</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab functionality
        function openTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.getElementById(tabName + '-tab').classList.add('active');
            event.currentTarget.classList.add('active');
        }

        // Accordion functionality
        document.querySelectorAll('.accordion-header').forEach(header => {
            header.addEventListener('click', () => {
                header.parentElement.classList.toggle('active');
            });
        });

        // Modal functions
        function openApproveModal() {
            document.getElementById('approveModal').style.display = 'block';
        }

        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
        }

        function openRejectModal() {
            document.getElementById('rejectModal').style.display = 'block';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }

        function openReviewModal() {
            document.getElementById('reviewModal').style.display = 'block';
        }

        function closeReviewModal() {
            document.getElementById('reviewModal').style.display = 'none';
        }

        // Star rating functionality
        function setRating(rating) {
            document.getElementById('rating').value = rating;
            document.querySelectorAll('.star-select').forEach((star, index) => {
                star.classList.toggle('active', index < rating);
            });
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['approveModal', 'rejectModal', 'reviewModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target == modal) {
                    eval('close' + modalId.charAt(0).toUpperCase() + modalId.slice(1) + '()');
                }
            });
        }

        // Auto-hide messages
        setTimeout(() => {
            document.querySelectorAll('.success-message, .error-message').forEach(msg => {
                msg.style.display = 'none';
            });
        }, 5000);

        // Download receipt
        function downloadReceipt(paymentID) {
            window.open(`download_receipt.php?payment_id=${paymentID}`, '_blank');
        }

        // Auto-open first accordion
        document.addEventListener('DOMContentLoaded', function() {
            const firstAccordion = document.querySelector('.accordion');
            if (firstAccordion) firstAccordion.classList.add('active');
        });
    </script>
</body>

</html>