<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];
$requestID = $_GET['request_id'] ?? 0;

// Fetch request details - HANYA untuk pets owned by current user
$stmt = $conn->prepare("SELECT psr.*, 
                               p.Name AS PetName, p.Image AS PetImage, p.Type AS PetType,
                               p.Breed AS PetBreed, p.Age AS PetAge, p.Gender AS PetGender,
                               p.SitStartDate AS PetSitStartDate, 
                               p.SitEndDate AS PetSitEndDate,
                               sitter.Name AS SitterName, sitter.Email AS SitterEmail, 
                               sitter.Phone AS SitterPhone, sitter.ProfilePicture AS SitterPhoto,
                               psr.OwnerConfirmed, psr.SitterCompleted, psr.SitterCompletedAt, psr.OwnerConfirmedAt  
                        FROM petsitrequest psr
                        JOIN pet p ON psr.PetID = p.PetID
                        JOIN user sitter ON psr.SitterID = sitter.UserID
                        WHERE psr.SitRequestID = ? AND psr.OwnerID = ?");
$stmt->bind_param("ii", $requestID, $userID);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();

if (!$request) {
    header("Location: ownerRequests.php?error=Request not found");
    exit;
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

// CHECK IF PAYMENT ALREADY EXISTS
$payment_sql = "SELECT * FROM payment WHERE SitRequestID = ? AND PayerID = ?";
$payment_stmt = $conn->prepare($payment_sql);
$payment_stmt->bind_param("ii", $requestID, $userID);
$payment_stmt->execute();
$payment_info = $payment_stmt->get_result()->fetch_assoc();

// CHECK IF REVIEW ALREADY EXISTS
$review_sql = "SELECT * FROM review WHERE SitRequestID = ? AND ReviewerID = ?";
$review_stmt = $conn->prepare($review_sql);
$review_stmt->bind_param("ii", $requestID, $userID);
$review_stmt->execute();
$existing_review = $review_stmt->get_result()->fetch_assoc();

// FETCH ALL REVIEWS FOR THIS SITTER
$all_reviews_sql = "SELECT r.*, u.Name AS ReviewerName, u.ProfilePicture AS ReviewerPhoto 
                    FROM review r 
                    JOIN user u ON r.ReviewerID = u.UserID 
                    WHERE r.SitterID = ? 
                    ORDER BY r.CreatedAt DESC";
$all_reviews_stmt = $conn->prepare($all_reviews_sql);
$all_reviews_stmt->bind_param("i", $request['SitterID']);
$all_reviews_stmt->execute();
$all_reviews = $all_reviews_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// CALCULATE DATE CONDITIONS
$today = new DateTime();
$startDate = new DateTime($request['PetSitStartDate']);
$endDate = new DateTime($request['PetSitEndDate']);
$today->setTime(0, 0, 0);
$endDate->setTime(0, 0, 0);
$hasStarted = ($today >= $startDate);
$hasEnded = ($today >= $endDate); 

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        // START TRANSACTION untuk auto-reject
        $conn->begin_transaction();

        try {
            // Dapatkan maklumat request yang di-approve
            $getInfo = $conn->prepare("SELECT PetID FROM PetSitRequest WHERE SitRequestID = ?");
            $getInfo->bind_param("i", $requestID);
            $getInfo->execute();
            $infoResult = $getInfo->get_result()->fetch_assoc();

            $petID = $infoResult['PetID'];

            // Approve request yang dipilih
            $updateStmt = $conn->prepare("UPDATE PetSitRequest SET Status = 'approved' WHERE SitRequestID = ?");
            $updateStmt->bind_param("i", $requestID);
            $updateStmt->execute();

            // AUTO-REJECT: Reject SEMUA pending requests lain untuk PET SAMA
            $rejectStmt = $conn->prepare("UPDATE PetSitRequest 
                                     SET Status = 'rejected', 
                                         RejectionReason = 'Another sitter was selected for this pet'
                                     WHERE PetID = ? 
                                     AND SitRequestID != ? 
                                     AND Status = 'pending'");
            $rejectStmt->bind_param("ii", $petID, $requestID);
            $rejectStmt->execute();

            // Update pet status
            $updatePet = $conn->prepare("UPDATE pet SET Status = 'Pet Sit' WHERE PetID = ?");
            $updatePet->bind_param("i", $petID);
            $updatePet->execute();

            // COMMIT TRANSACTION
            $conn->commit();

            header("Location: ownerPetSitRequestDetails.php?request_id=$requestID&success=Pet sit request approved successfully");
            exit;
        } catch (Exception $e) {
            // ROLLBACK jika ada error
            $conn->rollback();
            header("Location: ownerPetSitRequestDetails.php?request_id=$requestID&error=Failed to process request: " . $e->getMessage());
            exit;
        }
    } elseif ($action === 'reject') {
        $rejectionReason = $_POST['rejection_reason'] ?? '';

        if (!empty($rejectionReason)) {
            $updateStmt = $conn->prepare("UPDATE PetSitRequest SET Status = 'rejected', RejectionReason = ? WHERE SitRequestID = ?");
            $updateStmt->bind_param("si", $rejectionReason, $requestID);

            if ($updateStmt->execute()) {
                header("Location: ownerPetSitRequestDetails.php?request_id=$requestID&success=Pet sit request rejected successfully");
                exit;
            } else {
                header("Location: ownerPetSitRequestDetails.php?request_id=$requestID&error=Failed to reject request");
                exit;
            }
        } else {
            header("Location: ownerPetSitRequestDetails.php?request_id=$requestID&error=Rejection reason is required");
            exit;
        }
    }

    // HANDLE JOB COMPLETION CONFIRMATION
    // HANDLE JOB COMPLETION CONFIRMATION
    elseif ($action === 'confirm_completion') {
        // Start transaction
        $conn->begin_transaction();

        try {
            // STANDARD UPDATE QUERY UNTUK SEMUA FILE
            // Line 129-150 GANTI dengan:
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

            // Untuk OWNER (jika owner juga perlu kira dalam transaction count)
            $update_owner_sql = "UPDATE user SET total_transactions = total_transactions + 1 WHERE UserID = ?";
            $update_owner_stmt = $conn->prepare($update_owner_sql);
            $update_owner_stmt->bind_param("i", $request['OwnerID']);
            $update_owner_stmt->execute();


            // Update pet status back to 'available'
            $update_pet_sql = "UPDATE pet SET Status = 'Pet Sit' WHERE PetID = ?";
            $update_pet_stmt = $conn->prepare($update_pet_sql);
            $update_pet_stmt->bind_param("i", $request['PetID']);
            $update_pet_stmt->execute();

            $conn->commit();

            header("Location: ownerPetSitRequestDetails.php?request_id=$requestID&success=Job confirmed completed! RM" . number_format($sitterEarnings, 2) . " released to sitter's wallet.");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            header("Location: ownerPetSitRequestDetails.php?request_id=$requestID&error=Failed to confirm job completion: " . $e->getMessage());
            exit;
        }
    }

    // HANDLE REVIEW SUBMISSION
    elseif ($action === 'submit_review') {
        $rating = $_POST['rating'] ?? '';
        $review_text = $_POST['review_text'] ?? '';

        if (!empty($rating) && !empty($review_text)) {
            // GUNA SitterID bukan RevieweeID
            $review_sql = "INSERT INTO review (SitRequestID, ReviewerID, SitterID, Rating, Comment, CreatedAt) 
                           VALUES (?, ?, ?, ?, ?, NOW())";
            $review_stmt = $conn->prepare($review_sql);
            $review_stmt->bind_param("iiids", $requestID, $userID, $request['SitterID'], $rating, $review_text);

            if ($review_stmt->execute()) {
                header("Location: ownerPetSitRequestDetails.php?request_id=$requestID&success=Review submitted successfully!");
                exit;
            } else {
                header("Location: ownerPetSitRequestDetails.php?request_id=$requestID&error=Failed to submit review");
                exit;
            }
        } else {
            header("Location: ownerPetSitRequestDetails.php?request_id=$requestID&error=Please provide both rating and review text");
            exit;
        }
    }
}

// Fetch user data
$user_stmt = $conn->prepare("SELECT * FROM user WHERE UserID = ?");
$user_stmt->bind_param("i", $userID);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Pet Sit Request Details - FurCare</title>
    <link rel="stylesheet" href="css/userDashboard.css">
    <link rel="stylesheet" href="css/ownerPetSitRequestDetails.css">
    <style>
        /* Additional inline styles for button fonts */
        button,
        .btn,
        [class*="btn-"],
        .modal-actions button,
        .action-buttons a,
        .contact-button-container a {
            font-family: Arial, sans-serif;
            font-size: smaller;
        }

        /* New styles for completion sections */
        .confirmation-section {
            background: #fff8e6;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;

        }

        .waiting-section {
            background: #e6f3ff;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }

        .ongoing-section {
            background: #f0f0f0;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }

        .confirmed-section {
            background: #e8f5e9;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }

        .completion-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }

        .progress-step {
            text-align: center;
            padding: 10px;
            border-radius: 4px;
        }

        .btn-confirm {
            background: #4caf50;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: smaller;
            margin-top: 10px;
        }

        .btn-confirm:hover {
            background: #388e3c;
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <div class="navbar">
        <h2 class="logo">FurCare</h2>
        <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
            alt="Profile"
            class="profile-icon"
            onclick="toggleSidebar()">
    </div>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <span class="close-btn" onclick="toggleSidebar()">&times;</span>
        </div>
        <a href="userDashboard.php">🐾 Browse Pet</a>
        <a href="userProfile.php">👤 My Profile</a>
        <a href="index.php">🏠 Home</a>
        <a href="myPets.php">🐶 My Pets</a>
        <a href="addPet.php">➕ Post a Pet</a>
        <a href="ownerRequests.php">📩 My Pet Requests</a>
        <a href="myApplications.php">📋 My Requests</a>
        <a href="logout.php" class="logout">🚪 Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="details-container">
            <h1 class="page-title">Pet Sitting Request Details</h1>

            <!-- Success/Error Messages -->
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">✅ <?php echo htmlspecialchars($_GET['success']); ?></div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-error">❌ <?php echo htmlspecialchars($_GET['error']); ?></div>
            <?php endif; ?>

            <div class="details-grid">
                <!-- Left Column - Pet Card & Reviews -->
                <div class="left-column">
                    <!-- Pet Card -->
                    <div class="pet-card">
                        <img src="<?php echo htmlspecialchars($request['PetImage']); ?>"
                            alt="<?php echo htmlspecialchars($request['PetName']); ?>"
                            class="pet-image">

                        <h3 class="pet-name"><?php echo htmlspecialchars($request['PetName']); ?></h3>

                        <p><strong>Type:</strong> <?php echo htmlspecialchars($request['PetType']); ?></p>
                        <p><strong>Breed:</strong> <?php echo htmlspecialchars($request['PetBreed'] ?: 'Not specified'); ?></p>
                        <p><strong>Age:</strong> <?php echo formatAge($request['PetAge']); ?></p>
                        <p><strong>Gender:</strong> <?php echo htmlspecialchars($request['PetGender'] ?: 'Not specified'); ?></p>

                        <p><strong>Sitting Dates:</strong>
                            <?php echo date('M j, Y', strtotime($request['PetSitStartDate'])); ?>
                            -
                            <?php echo date('M j, Y', strtotime($request['PetSitEndDate'])); ?>
                        </p>
                    </div>

                    <!-- Sitter Card - SAME SIZE AS PET CARD -->
                    <div class="sitter-card-container">
                        <div class="sitter-header-section">
                            <h3 class="sitter-main-title">🐕 Pet Sitter</h3>
                            <img src="<?php echo !empty($request['SitterPhoto']) ? htmlspecialchars($request['SitterPhoto']) : 'uploads/profile_icon.png'; ?>"
                                alt="<?php echo htmlspecialchars($request['SitterName']); ?>"
                                class="user-avatar">
                            <h4 class="user-name"><?php echo htmlspecialchars($request['SitterName']); ?></h4>
                        </div>

                        <div class="sitter-info-grid">
                            <div class="sitter-info-item">
                                <span class="sitter-info-label">Email</span>
                                <span class="sitter-info-value">
                                    <a href="mailto:<?php echo htmlspecialchars($request['SitterEmail']); ?>">
                                        <?php echo htmlspecialchars($request['SitterEmail']); ?>
                                    </a>
                                </span>
                            </div>

                            <div class="sitter-info-item">
                                <span class="sitter-info-label">Phone</span>
                                <span class="sitter-info-value">
                                    <?php if (!empty($request['SitterPhone'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($request['SitterPhone']); ?>">
                                            <?php echo htmlspecialchars($request['SitterPhone']); ?>
                                        </a>
                                    <?php else: ?>
                                        Not provided
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>

                        <div class="contact-button-container">
                            <a href="mailto:<?php echo htmlspecialchars($request['SitterEmail']); ?>"
                                class="btn-contact-sitter">
                                📧 Contact Sitter
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Info Card -->
                <div class="info-card">
                    <!-- Status and Request Info -->
                    <h3 class="section-title">Sitting Request Information</h3>

                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Request ID</span>
                            <span class="info-value">#<?php echo $request['SitRequestID']; ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Status</span>
                            <span class="status-badge status-<?php echo $request['Status']; ?>">
                                <?php echo ucfirst($request['Status']); ?>
                            </span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Request Date</span>
                            <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($request['RequestDate'])); ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Start Date</span>
                            <span class="info-value"><?php echo date('M j, Y', strtotime($request['PetSitStartDate'])); ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">End Date</span>
                            <span class="info-value"><?php echo date('M j, Y', strtotime($request['PetSitEndDate'])); ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Duration</span>
                            <span class="info-value"><?php echo $request['TotalDays']; ?> days</span>
                        </div>
                    </div>

                    <!-- JOB PROGRESS TRACKER -->
                    <?php if ($request['Status'] === 'approved' && $payment_info && $payment_info['PaymentStatus'] === 'paid'): ?>
                        <div class="completion-info">
                            <h4 style="margin-top: 0;">Job Progress</h4>

                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-top: 15px;">
                                <!-- Step 1: Job Started -->
                                <div class="progress-step" style="
                background: <?php echo $hasStarted ? '#e8f5e9' : '#f5f5f5'; ?>;
                border: 2px solid <?php echo $hasStarted ? '#81c784' : '#e0e0e0'; ?>;
                border-radius: 12px;
                padding: 12px;
                text-align: center;
                transition: all 0.3s ease;
                box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            ">
                                    <div style="
                    font-size: 1.4em;
                    font-weight: bold;
                    color: <?php echo $hasStarted ? '#2e7d32' : '#757575'; ?>;
                    margin-bottom: 5px;
                ">1</div>
                                    <div style="
                    font-size: 0.9em;
                    color: <?php echo $hasStarted ? '#388e3c' : '#9e9e9e'; ?>;
                    font-weight: 500;
                    margin-bottom: 8px;
                ">Job Started</div>
                                    <div style="
                    font-size: 1.2em;
                    margin-top: 5px;
                    color: <?php echo $hasStarted ? '#4caf50' : '#bdbdbd'; ?>;
                ">
                                        <?php echo $hasStarted ? '✅' : '⏳'; ?>
                                    </div>
                                    <?php if ($hasStarted): ?>
                                        <div style="
                        font-size: 0.75em;
                        color: #43a047;
                        margin-top: 8px;
                        background: #c8e6c9;
                        padding: 3px 8px;
                        border-radius: 10px;
                        display: inline-block;
                    ">
                                            Started
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Step 2: Period Ended -->
                                <div class="progress-step" style="
                background: <?php echo $hasEnded ? '#e3f2fd' : '#f5f5f5'; ?>;
                border: 2px solid <?php echo $hasEnded ? '#64b5f6' : '#e0e0e0'; ?>;
                border-radius: 12px;
                padding: 12px;
                text-align: center;
                transition: all 0.3s ease;
                box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            ">
                                    <div style="
                    font-size: 1.4em;
                    font-weight: bold;
                    color: <?php echo $hasEnded ? '#1565c0' : '#757575'; ?>;
                    margin-bottom: 5px;
                ">2</div>
                                    <div style="
                    font-size: 0.9em;
                    color: <?php echo $hasEnded ? '#1976d2' : '#9e9e9e'; ?>;
                    font-weight: 500;
                    margin-bottom: 8px;
                ">Period Ended</div>
                                    <div style="
                    font-size: 1.2em;
                    margin-top: 5px;
                    color: <?php echo $hasEnded ? '#2196f3' : '#bdbdbd'; ?>;
                ">
                                        <?php echo $hasEnded ? '✅' : '⏳'; ?>
                                    </div>
                                    <?php if ($hasEnded): ?>
                                        <div style="
                        font-size: 0.75em;
                        color: #1565c0;
                        margin-top: 8px;
                        background: #bbdefb;
                        padding: 3px 8px;
                        border-radius: 10px;
                        display: inline-block;
                    ">
                                            Completed
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Step 3: Sitter Confirmed -->
                                <div class="progress-step" style="
                background: <?php echo $request['SitterCompleted'] ? '#f3e5f5' : '#f5f5f5'; ?>;
                border: 2px solid <?php echo $request['SitterCompleted'] ? '#ba68c8' : '#e0e0e0'; ?>;
                border-radius: 12px;
                padding: 12px;
                text-align: center;
                transition: all 0.3s ease;
                box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            ">
                                    <div style="
                    font-size: 1.4em;
                    font-weight: bold;
                    color: <?php echo $request['SitterCompleted'] ? '#7b1fa2' : '#757575'; ?>;
                    margin-bottom: 5px;
                ">3</div>
                                    <div style="
                    font-size: 0.9em;
                    color: <?php echo $request['SitterCompleted'] ? '#8e24aa' : '#9e9e9e'; ?>;
                    font-weight: 500;
                    margin-bottom: 8px;
                ">Sitter Confirmed</div>
                                    <div style="
                    font-size: 1.2em;
                    margin-top: 5px;
                    color: <?php echo $request['SitterCompleted'] ? '#9c27b0' : '#bdbdbd'; ?>;
                ">
                                        <?php echo $request['SitterCompleted'] ? '✅' : '⏳'; ?>
                                    </div>
                                    <?php if ($request['SitterCompleted']): ?>
                                        <div style="
                        font-size: 0.75em;
                        color: #7b1fa2;
                        margin-top: 8px;
                        background: #e1bee7;
                        padding: 3px 8px;
                        border-radius: 10px;
                        display: inline-block;
                    ">
                                            Confirmed
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($request['SitterCompleted'] == 1 && $request['SitterCompletedAt']): ?>
                                <p style="margin-top: 10px; font-size: 0.9em;">
                                    Sitter marked as completed on:
                                    <?php echo date('F j, Y g:i A', strtotime($request['SitterCompletedAt'])); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Pricing Information -->
                    <h4 class="section-title">Pricing & Payment</h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Daily Rate</span>
                            <span class="info-value">RM<?php echo number_format($request['DailyRate'], 2); ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Total Days</span>
                            <span class="info-value"><?php echo $request['TotalDays']; ?> days</span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Total Amount</span>
                            <span class="info-value" style="font-weight: bold; font-size: 1.1em;">
                                RM<?php echo number_format($request['TotalAmount'], 2); ?>
                            </span>
                        </div>

                        <?php if ($payment_info): ?>
                            <div class="info-item">
                                <span class="info-label">Payment Status</span>
                                <span class="info-value" style="color: #48bb78; font-weight: bold;">
                                    Paid
                                </span>
                            </div>

                            <div class="info-item">
                                <span class="info-label">Sitter Earnings</span>
                                <span class="info-value" style="color: #4caf50; font-weight: bold;">
                                    RM<?php echo number_format($request['TotalAmount'] * 0.90, 2); ?>
                                </span>
                            </div>
                        <?php else: ?>
                            <div class="info-item">
                                <span class="info-label">Payment Status</span>
                                <span class="info-value" style="color: #ed8936; font-weight: bold;">
                                    ⏳ Pending
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Sitter Message -->
                    <?php if (!empty($request['SitterMessage'])): ?>
                        <div class="message-box">
                            <strong>Sitter's Message:</strong>
                            <p style="margin: 10px 0 0 0;"><?php echo nl2br(htmlspecialchars($request['SitterMessage'])); ?></p>
                        </div>
                    <?php endif; ?>

                    <!-- Rejection Reason -->
                    <?php if ($request['Status'] === 'rejected' && !empty($request['RejectionReason'])): ?>
                        <div class="rejection-box">
                            <div class="rejection-title">Reason for Rejection:</div>
                            <div><?php echo nl2br(htmlspecialchars($request['RejectionReason'])); ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- OWNER CONFIRMATION SECTION -->
                    <?php if ($request['Status'] === 'approved' && $payment_info && $payment_info['PaymentStatus'] === 'paid'): ?>
                        <?php if ($hasEnded && $request['SitterCompleted'] == 1 && $request['OwnerConfirmed'] == 0): ?>
                            <div class="confirmation-section">
                                <h3 style="margin-top: 0;">🔔 NOTIFICATION: Job Completion Pending Your Confirmation</h3>
                                <p><strong>Sitter has marked the pet sitting job as completed!</strong></p>

                                <div style="background: white; padding: 15px; border-radius: 8px; margin: 15px 0;">
                                    <p>Please verify that:</p>
                                    <ul style="margin: 10px 0; padding-left: 20px;">
                                        <li>Pet is in good health condition</li>
                                        <li>Pet sitting period has ended</li>
                                        <li>No issues or complaints</li>
                                    </ul>

                                    <p><strong>Job Period:</strong>
                                        <?php echo date('M j, Y', strtotime($request['PetSitStartDate'])); ?>
                                        -
                                        <?php echo date('M j, Y', strtotime($request['PetSitEndDate'])); ?>
                                    </p>

                                    <p><strong>Sitter Completed On:</strong>
                                        <?php echo date('F j, Y g:i A', strtotime($request['SitterCompletedAt'])); ?>
                                    </p>
                                </div>

                                <form method="POST">
                                    <input type="hidden" name="action" value="confirm_completion">
                                    <button type="submit" class="btn-confirm"
                                        onclick="return confirm('CONFIRM JOB COMPLETION?\\n\\nThis will release payment RM<?php echo number_format($request['TotalAmount'] * 0.90, 2); ?> to the sitter.\\n\\nAre you sure?')">
                                        Confirm Completion & Release Payment
                                    </button>
                                </form>

                                <p style="color: #666; font-size: 0.9em; margin-top: 15px;">
                                    <em>Note: After confirmation, RM<?php echo number_format($request['TotalAmount'] * 0.90, 2); ?> will be released to the sitter's wallet.</em>
                                </p>
                            </div>

                        <?php elseif ($hasEnded && $request['SitterCompleted'] == 0): ?>
                            <div class="waiting-section">
                                <h4 style="margin-top: 0;">⏳ Waiting for Sitter Confirmation</h4>
                                <p>Sitting period has ended. Waiting for sitter to mark the job as completed.</p>
                            </div>

                        <?php elseif (!$hasEnded): ?>
                            <div class="ongoing-section">
                                <h4 style="margin-top: 0;">Job In Progress</h4>
                                <p>Pet sitting job is currently ongoing. Ends on <?php echo date('M j, Y', strtotime($request['PetSitEndDate'])); ?>.</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- AFTER OWNER CONFIRMS -->
                    <?php if ($request['OwnerConfirmed'] == 1): ?>
                        <div class="confirmed-section">
                            <h4 style="margin-top: 0;">Job Completed & Payment Released</h4>
                            <p><strong>You have confirmed job completion.</strong></p>

                            <p>Confirmed on: <?php echo date('F j, Y g:i A', strtotime($request['OwnerConfirmedAt'])); ?></p>
                            <p>RM<?php echo number_format($request['TotalAmount'] * 0.90, 2); ?> has been released to the sitter's wallet.</p>
                        </div>
                    <?php endif; ?>

                    <!-- REVIEW SECTION -->
                    <?php if ($request['Status'] === 'completed' && !$existing_review): ?>
                        <div class="review-section">
                            <h3>⭐ Rate Your Experience</h3>
                            <p>How was your experience with <?php echo htmlspecialchars($request['SitterName']); ?>?</p>

                            <form method="POST">
                                <input type="hidden" name="action" value="submit_review">

                                <div class="form-group">
                                    <label>Rating:</label>
                                    <div class="star-rating-input" id="ratingInput">
                                        <span class="star-input" data-rating="1">★</span>
                                        <span class="star-input" data-rating="2">★</span>
                                        <span class="star-input" data-rating="3">★</span>
                                        <span class="star-input" data-rating="4">★</span>
                                        <span class="star-input" data-rating="5">★</span>
                                    </div>
                                    <input type="hidden" name="rating" id="selectedRating" required>
                                </div>

                                <div class="form-group">
                                    <label>Your Review:</label>
                                    <textarea name="review_text" placeholder="Share your experience with this pet sitter... How was the service? Would you recommend them?" required></textarea>
                                </div>

                                <button type="submit" class="btn btn-review">Submit Review</button>
                            </form>
                        </div>
                    <?php elseif ($existing_review): ?>
                        <div class="review-section">
                            <h3>⭐ Your Review</h3>
                            <div class="rating-stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span class="star <?php echo $i <= $existing_review['Rating'] ? 'active' : ''; ?>">★</span>
                                <?php endfor; ?>
                            </div>
                            <p style="margin-top: 10px;"><strong>Your feedback:</strong>
                                <?php echo htmlspecialchars($existing_review['Comment'] ?? $existing_review['ReviewText']); ?>
                            </p>
                            <p style="color: #666; font-size: 0.9em; margin-top: 10px;">
                                Reviewed on <?php echo date('F j, Y', strtotime($existing_review['CreatedAt'])); ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <!-- ALL REVIEWS SECTION -->
                    <?php if (($request['Status'] === 'completed' || $request['Status'] === 'approved') && count($all_reviews) > 0): ?>
                        <div class="all-reviews-section">
                            <h3 class="section-title">⭐ Sitter Reviews</h3>
                            <div class="reviews-container">
                                <h4 class="reviews-title">All Reviews (<?php echo count($all_reviews); ?>)</h4>
                                <div class="reviews-grid">
                                    <?php foreach ($all_reviews as $review): ?>
                                        <div class="review-item">
                                            <div class="review-header">
                                                <img src="<?php echo !empty($review['ReviewerPhoto']) ? htmlspecialchars($review['ReviewerPhoto']) : 'uploads/profile_icon.png'; ?>"
                                                    alt="<?php echo htmlspecialchars($review['ReviewerName']); ?>"
                                                    class="reviewer-avatar">
                                                <div class="reviewer-info">
                                                    <strong class="reviewer-name"><?php echo htmlspecialchars($review['ReviewerName']); ?></strong>
                                                    <div class="rating-stars">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <span class="star <?php echo $i <= $review['Rating'] ? 'active' : ''; ?>">★</span>
                                                        <?php endfor; ?>
                                                        <span class="rating-value">(<?php echo $review['Rating']; ?>.0)</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if (!empty($review['Comment'])): ?>
                                                <p class="review-comment">"<?php echo htmlspecialchars($review['Comment']); ?>"</p>
                                            <?php endif; ?>
                                            <small class="review-date">
                                                <?php echo date('F j, Y', strtotime($review['CreatedAt'])); ?>
                                            </small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <a href="ownerRequests.php?tab=petsit" class="btn btn-back">Back to Requests</a>

                        <?php if ($request['Status'] === 'pending'): ?>
                            <!-- Button untuk pending requests -->
                            <button class="btn btn-approve" onclick="openApproveModal()">Approve Request</button>
                            <button class="btn btn-reject" onclick="openRejectModal()">Reject Request</button>

                        <?php elseif ($request['Status'] === 'approved'): ?>

                            <?php if (!$payment_info): ?>
                                <!-- Bayar untuk approved requests yang belum bayar -->
                                <a href="payment.php?request_id=<?php echo $requestID; ?>" class="btn btn-pay">
                                    Pay Now (RM<?php echo number_format($request['TotalAmount'], 2); ?>)
                                </a>

                            <?php elseif ($payment_info['PaymentStatus'] === 'pending'): ?>
                                <!-- Continue payment untuk payment pending -->
                                <a href="payment.php?request_id=<?php echo $requestID; ?>" class="btn btn-pay">
                                    ⏳ Complete Payment
                                </a>
                            <?php endif; ?>

                        <?php endif; ?>

                        <!-- RECEIPT BUTTON - SELALU TUNJUK JIKA SUDAH BAYAR -->
                        <?php if ($payment_info && $payment_info['PaymentStatus'] === 'paid'): ?>
                            <a href="download_receipt.php?payment_id=<?php echo $payment_info['PaymentID']; ?>" class="btn btn-receipt">
                                View Receipt
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Approve Modal -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Approve Pet Sit Request</h3>
                <span class="close" onclick="closeApproveModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <div class="form-group">
                    <p>Are you sure you want to approve this pet sit request?</p>
                    <p><strong>Note:</strong> After approval, you will need to make payment to confirm the booking.</p>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-back" onclick="closeApproveModal()">Cancel</button>
                    <button type="submit" class="btn btn-approve">Confirm Approval</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reject Pet Sit Request</h3>
                <span class="close" onclick="closeRejectModal()">&times;</span>
            </div>
            <form method="POST">
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

    <!-- Review Modal -->
    <div id="reviewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Write a Review</h3>
                <span class="close" onclick="closeReviewModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="submit_review">

                <div class="form-group">
                    <label>Rating:</label>
                    <div class="star-rating-input" id="modalRatingInput">
                        <span class="star-input" data-rating="1">★</span>
                        <span class="star-input" data-rating="2">★</span>
                        <span class="star-input" data-rating="3">★</span>
                        <span class="star-input" data-rating="4">★</span>
                        <span class="star-input" data-rating="5">★</span>
                    </div>
                    <input type="hidden" name="rating" id="modalSelectedRating" required>
                </div>

                <div class="form-group">
                    <label>Your Review:</label>
                    <textarea name="review_text" placeholder="Share your experience with this pet sitter... How was the service? Would you recommend them?" required></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-back" onclick="closeReviewModal()">Cancel</button>
                    <button type="submit" class="btn btn-review">Submit Review</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('active');
        }

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

        function scrollToReview() {
            const reviewSection = document.querySelector('.review-section');
            if (reviewSection) {
                reviewSection.scrollIntoView({
                    behavior: 'smooth'
                });
            }
        }

        // Star rating functionality for inline form
        const stars = document.querySelectorAll('.star-input');
        const selectedRating = document.getElementById('selectedRating');
        const modalSelectedRating = document.getElementById('modalSelectedRating');

        function setupStarRating(starsElement, ratingInput) {
            starsElement.forEach(star => {
                star.addEventListener('click', function() {
                    const rating = this.getAttribute('data-rating');
                    ratingInput.value = rating;

                    starsElement.forEach(s => {
                        if (s.getAttribute('data-rating') <= rating) {
                            s.classList.add('active');
                        } else {
                            s.classList.remove('active');
                        }
                    });
                });

                star.addEventListener('mouseover', function() {
                    const rating = this.getAttribute('data-rating');
                    starsElement.forEach(s => {
                        if (s.getAttribute('data-rating') <= rating) {
                            s.style.color = '#ffc107';
                        } else {
                            s.style.color = '#ddd';
                        }
                    });
                });

                star.addEventListener('mouseout', function() {
                    const currentRating = ratingInput.value;
                    starsElement.forEach(s => {
                        if (s.getAttribute('data-rating') <= currentRating) {
                            s.style.color = '#ffc107';
                        } else {
                            s.style.color = '#ddd';
                        }
                    });
                });
            });
        }

        // Initialize star ratings
        const inlineStars = document.querySelectorAll('#ratingInput .star-input');
        const modalStars = document.querySelectorAll('#modalRatingInput .star-input');

        if (inlineStars.length > 0) {
            setupStarRating(inlineStars, selectedRating);
        }

        if (modalStars.length > 0) {
            setupStarRating(modalStars, modalSelectedRating);
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const approveModal = document.getElementById('approveModal');
            const rejectModal = document.getElementById('rejectModal');
            const reviewModal = document.getElementById('reviewModal');

            if (event.target == approveModal) closeApproveModal();
            if (event.target == rejectModal) closeRejectModal();
            if (event.target == reviewModal) closeReviewModal();
        }

        // Auto-hide success/error messages
        setTimeout(() => {
            const successMsg = document.querySelector('.alert-success');
            const errorMsg = document.querySelector('.alert-error');

            if (successMsg) successMsg.style.display = 'none';
            if (errorMsg) errorMsg.style.display = 'none';
        }, 5000);
    </script>
</body>

</html>