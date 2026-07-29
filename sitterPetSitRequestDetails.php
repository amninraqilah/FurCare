<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['request_id'])) {
    header("Location: myApplications.php");
    exit;
}

$userID = $_SESSION['user_id'];
$requestID = $_GET['request_id'];

// Fetch detailed request information dengan SitterCompleted dan OwnerConfirmed
$sql = "SELECT psr.*, 
               p.Name as PetName, p.Image as PetImage, p.Type as PetType, p.Breed as PetBreed,
               p.Age as PetAge, p.Gender as PetGender, p.Description as PetDescription,
               p.District as PetDistrict, p.State as PetState,
               p.SitStartDate, p.SitEndDate,
               p.Price as PetPrice,  
               owner.Name as OwnerName, owner.Email as OwnerEmail, 
               owner.Phone as OwnerPhone, owner.ProfilePicture as OwnerPhoto,
               psr.SitterCompleted, psr.OwnerConfirmed, psr.SitterCompletedAt
        FROM PetSitRequest psr
        JOIN pet p ON psr.PetID = p.PetID
        JOIN user owner ON psr.OwnerID = owner.UserID
        WHERE psr.SitRequestID = ? AND psr.SitterID = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $requestID, $userID);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();

if (!$request) {
    header("Location: myApplications.php");
    exit;
}

// HANDLE CANCEL REQUEST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_request') {
    if ($request['Status'] === 'pending') {
        $cancel_sql = "UPDATE PetSitRequest SET Status = 'cancelled', CancelledAt = NOW() WHERE SitRequestID = ?";
        $cancel_stmt = $conn->prepare($cancel_sql);
        $cancel_stmt->bind_param("i", $requestID);

        if ($cancel_stmt->execute()) {
            header("Location: myApplications.php?tab=petsit&success=Pet sitting request cancelled successfully!");
            exit;
        } else {
            header("Location: sitterPetSitRequestDetails.php?request_id=$requestID&error=Failed to cancel request. Please try again.");
            exit;
        }
    } else {
        header("Location: sitterPetSitRequestDetails.php?request_id=$requestID&error=Cannot cancel request that is not pending.");
        exit;
    }
}

// CHECK PAYMENT STATUS
$payment_sql = "SELECT * FROM payment WHERE SitRequestID = ? AND PaymentStatus = 'paid'";
$payment_stmt = $conn->prepare($payment_sql);
$payment_stmt->bind_param("i", $requestID);
$payment_stmt->execute();
$payment_info = $payment_stmt->get_result()->fetch_assoc();

// CALCULATE DATE CONDITIONS
$today = new DateTime();
$startDate = new DateTime($request['SitStartDate']);
$endDate = new DateTime($request['SitEndDate']);

// Normalize times to midnight untuk accurate date comparison
$today->setTime(0, 0, 0);
$endDate->setTime(0, 0, 0);

$hasStarted = ($today >= $startDate);
$hasEnded = ($today >= $endDate); // BOLEH PADA END DATE SENDIRI ATAU SELEPAS

// CHECK COMPLETION STATUSES
$ownerConfirmed = $request['OwnerConfirmed'] ?? 0;
$sitterCompleted = $request['SitterCompleted'] ?? 0;

// LOGIC FLOW YANG BETUL:
// 1. Sitter boleh mark completed jika: approved + paid + hasEnded + sitter belum completed
$canMarkCompleted = (
    $request['Status'] === 'approved' &&
    $payment_info &&
    $hasEnded &&
    $sitterCompleted == 0
);

// 2. Waiting for owner confirmation: sitter sudah completed tapi owner belum confirm
$waitingForOwner = (
    $request['Status'] === 'approved' &&
    $payment_info &&
    $hasEnded &&
    $sitterCompleted == 1 &&
    $ownerConfirmed == 0
);

// 3. Payment ready for release: owner sudah confirm, sitter sudah completed
$canReleasePayment = (
    $request['Status'] === 'approved' &&
    $payment_info &&
    $hasEnded &&
    $sitterCompleted == 1 &&
    $ownerConfirmed == 1
);

// HANDLE JOB COMPLETION (SITTER MARK COMPLETED)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_job') {
    if ($canMarkCompleted) {
        // Update SitterCompleted saja
        // 🟢 STANDARD UPDATE QUERY UNTUK SEMUA FILE
        $update_sql = "UPDATE PetSitRequest 
       SET SitterCompleted = 1,
           SitterCompletedAt = NOW()
       WHERE SitRequestID = ?";
        // HAPUS: Status, CompletionStatus, CompletedAt
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $requestID);

        if ($update_stmt->execute()) {
            header("Location: sitterPetSitRequestDetails.php?request_id=$requestID&success=Job marked as completed! Waiting for owner confirmation.");
            exit;
        } else {
            header("Location: sitterPetSitRequestDetails.php?request_id=$requestID&error=Failed to mark job as completed.");
            exit;
        }
    } else {
        $errorMsg = "Cannot mark as completed because: ";
        if (!$hasEnded) {
            $errorMsg .= "Sitting period has not ended yet.";
        } elseif ($sitterCompleted == 1) {
            $errorMsg .= "You have already marked this as completed.";
        } elseif (!$payment_info) {
            $errorMsg .= "Payment not verified.";
        } else {
            $errorMsg .= "Request is not approved.";
        }

        header("Location: sitterPetSitRequestDetails.php?request_id=$requestID&error=" . urlencode($errorMsg));
        exit;
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

// CHECK REVIEW STATUS
$user_review_sql = "SELECT * FROM review WHERE SitRequestID = ? AND ReviewerID = ?";
$user_review_stmt = $conn->prepare($user_review_sql);
$user_review_stmt->bind_param("ii", $requestID, $userID);
$user_review_stmt->execute();
$user_review = $user_review_stmt->get_result()->fetch_assoc();

$canReview = ($request['Status'] === 'completed' && !$user_review);

// Get all reviews untuk request ini
$reviews_sql = "SELECT r.*, u.Name as ReviewerName, u.ProfilePicture as ReviewerPhoto 
                FROM review r 
                JOIN user u ON r.ReviewerID = u.UserID 
                WHERE r.SitRequestID = ? 
                ORDER BY r.CreatedAt DESC";
$reviews_stmt = $conn->prepare($reviews_sql);
$reviews_stmt->bind_param("i", $requestID);
$reviews_stmt->execute();
$all_reviews = $reviews_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch user data untuk profile picture
$user_stmt = $conn->prepare("SELECT * FROM user WHERE UserID = ?");
$user_stmt->bind_param("i", $userID);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

// Handle success/error messages
$success = isset($_GET['success']) ? $_GET['success'] : '';
$error = isset($_GET['error']) ? $_GET['error'] : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Pet Sitting Request Details - FurCare</title>
    <link rel="stylesheet" href="css/adoptionRequestDetails.css">
    <link rel="stylesheet" href="css/sitterPetSitRequestDetails.css">
    <link rel="stylesheet" href="css/userDashboard.css">
    <style>
        .waiting-owner {
            background-color: #fff8e6;
            border: 1px solid #ffc107;
            padding: 12px 15px;
            margin: 15px 0;
            border-radius: 4px;
        }

        .payment-released {
            background-color: #e8f5e9;
            border: 1px solid #4caf50;
            padding: 12px 15px;
            margin: 15px 0;
            border-radius: 4px;
        }

        .owner-confirmation-info {
            background-color: #e6f3ff;
            border: px solid #2196f3;
            padding: 12px 15px;
            margin: 15px 0;
            border-radius: 4px;
        }

        .job-progress-tracker {
            background: #ffffff;
            padding: 18px;
            border-radius: 12px;
            margin: 15px 0;
            border: 1px solid #ececec;
        }

        .job-progress-tracker h4 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            font-family: Arial, sans-serif;
            color: #444;
        }

        .progress-steps {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-top: 14px;
        }

        /* STEP BOX GENERAL */
        .progress-step {
            text-align: center;
            padding: 14px 10px;
            border-radius: 10px;
            font-size: 13px;
            font-family: Arial, sans-serif;
            border: 1px solid #e8e8e8;
            background: #fafafa;
            transition: 0.3s ease;
        }

        /* PASTEL COMPLETED (Mint Green Pastel) */
        .step-completed {
            background: #C8E6C9;
            /* pastel mint */
            color: #2E7D32;
            border-color: #A5D6A7;
            box-shadow: 0 2px 4px rgba(170, 215, 170, 0.6);
        }

        /* PASTEL PENDING (Soft Grey) */
        .step-pending {
            background: #F5F5F5;
            color: #888;
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
    </style>
</head>

<body>
    <!-- Navbar -->
    <div class="navbar">
        <h2 class="logo">FurCare</h2>
        <img src="<?php echo !empty($user['ProfilePicture']) ? htmlspecialchars($user['ProfilePicture']) : 'uploads/profile_icon.png'; ?>"
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
        <a href="myApplications.php" class="active">📋 My Requests</a>
        <a href="logout.php" class="logout">🚪 Logout</a>
    </div>

    <!-- Cancel Confirmation Modal -->
    <div id="cancelModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">Cancel Request</div>
                <button class="close-modal" onclick="closeCancelModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to cancel this pet sitting request?</p>
                <p style="color: #666; font-size: 0.9em; margin-top: 10px;">
                    This action cannot be undone. The owner will be notified of your cancellation.
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel-modal" onclick="closeCancelModal()">Keep Request</button>
                <form id="cancelForm" method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="cancel_request">
                    <button type="submit" class="btn-confirm-cancel">Yes, Cancel Request</button>
                </form>
            </div>
        </div>
    </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="details-container">
            <h1 class="page-title">Pet Sitting Request Details</h1>

            <!-- Success/Error Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success">✅ <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
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
                        <p><strong>Location:</strong> <?php echo htmlspecialchars($request['PetDistrict'] . ', ' . $request['PetState']); ?></p>

                        <?php if (!empty($request['PetDescription'])): ?>
                            <div class="description-section">
                                <strong>Description:</strong>
                                <p><?php echo nl2br(htmlspecialchars($request['PetDescription'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Review Card - SAME SIZE AS PET CARD -->
                    <div class="review-card-container">
                        <div class="review-header-section">
                            <h3 class="review-main-title">Reviews & Ratings</h3>
                        </div>

                        <div class="reviews-grid">
                            <?php if (count($all_reviews) > 0): ?>
                                <?php foreach ($all_reviews as $review): ?>
                                    <div class="review-item-card">
                                        <div class="review-card-header">
                                            <img src="<?php echo !empty($review['ReviewerPhoto']) ? htmlspecialchars($review['ReviewerPhoto']) : 'uploads/profile_icon.png'; ?>"
                                                alt="<?php echo htmlspecialchars($review['ReviewerName']); ?>"
                                                class="reviewer-avatar">
                                            <div class="reviewer-details">
                                                <div class="reviewer-name"><?php echo htmlspecialchars($review['ReviewerName']); ?></div>
                                                <div class="review-date">
                                                    <?php echo date('F j, Y g:i A', strtotime($review['CreatedAt'])); ?>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="rating-container">
                                            <div class="stars">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <span class="star <?php echo $i <= $review['Rating'] ? 'active' : ''; ?>">★</span>
                                                <?php endfor; ?>
                                            </div>
                                            <span class="rating-value">(<?php echo $review['Rating']; ?>/5)</span>
                                        </div>

                                        <?php if (!empty($review['Comment'])): ?>
                                            <div class="review-content">
                                                <p class="review-comment">"<?php echo htmlspecialchars($review['Comment']); ?>"</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-reviews-card">
                                    <p class="no-reviews-text">No reviews yet.</p>
                                </div>
                            <?php endif; ?>
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
                            <span class="info-value"><?php echo date('M j, Y', strtotime($request['SitStartDate'])); ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">End Date</span>
                            <span class="info-value"><?php echo date('M j, Y', strtotime($request['SitEndDate'])); ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Duration</span>
                            <span class="info-value"><?php echo $request['TotalDays']; ?> days</span>
                        </div>
                    </div>

                    <!-- JOB PROGRESS TRACKER -->
                    <?php if ($request['Status'] === 'approved' && $payment_info): ?>
                        <div class="job-progress-tracker">
                            <h4 style="margin-top: 0;">Job Progress</h4>

                            <div class="progress-steps">
                                <div class="progress-step <?php echo $payment_info ? 'step-completed' : 'step-pending'; ?>">
                                    <div>1</div>
                                    <div>Payment</div>
                                    <div><?php echo $payment_info ? 'Paid' : '⏳'; ?></div>
                                </div>

                                <div class="progress-step <?php echo $hasEnded ? 'step-completed' : 'step-pending'; ?>">
                                    <div>2</div>
                                    <div>Job Period</div>
                                    <div><?php echo $hasEnded ? 'Ended' : '⏳'; ?></div>
                                </div>

                                <div class="progress-step <?php echo $sitterCompleted ? 'step-completed' : 'step-pending'; ?>">
                                    <div>3</div>
                                    <div>Sitter Confirm</div>
                                    <div><?php echo $sitterCompleted ? 'Done' : '⏳'; ?></div>
                                </div>

                                <div class="progress-step <?php echo $ownerConfirmed ? 'step-completed' : 'step-pending'; ?>">
                                    <div>4</div>
                                    <div>Owner Confirm</div>
                                    <div><?php echo $ownerConfirmed ? 'Done' : '⏳'; ?></div>
                                </div>
                            </div>

                            <?php if ($sitterCompleted == 1): ?>
                                <p style="margin-top: 10px; font-size: 0.9em;">
                                    <strong>You marked as completed:</strong>
                                    <?php echo date('F j, Y g:i A', strtotime($request['SitterCompletedAt'])); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Completion Status Messages -->
                    <?php if ($request['Status'] === 'approved' && $payment_info && $hasEnded): ?>
                        <?php if ($sitterCompleted == 1 && $ownerConfirmed == 1): ?>
                            <div class="payment-released">
                                <strong>✅ Job Completed & Payment Released</strong>
                                <p>Both owner and sitter have confirmed job completion.</p>
                                <p>RM<?php echo number_format($payment_info['SitterEarnings'], 2); ?> has been credited to your wallet.</p>
                            </div>
                        <?php elseif ($sitterCompleted == 1 && $ownerConfirmed == 0): ?>
                            <div class="waiting-owner">
                                <strong>⏳ Waiting for Owner Confirmation</strong>
                                <p>You have marked the job as completed. Waiting for owner to confirm.</p>
                                <p><em>Payment will be released after owner confirms.</em></p>
                            </div>
                        <?php elseif ($sitterCompleted == 0): ?>
                            <div class="owner-confirmation-info">
                                <strong>✅ Ready to Mark as Completed</strong>
                                <p>Sitting period has ended. You can now mark this job as completed.</p>
                                <p><em>Owner will need to confirm before payment is released.</em></p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Owner Information -->
                    <h4 class="section-title">Pet Owner Information</h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Owner Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($request['OwnerName']); ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Email</span>
                            <span class="info-value">
                                <a href="mailto:<?php echo htmlspecialchars($request['OwnerEmail']); ?>">
                                    <?php echo htmlspecialchars($request['OwnerEmail']); ?>
                                </a>
                            </span>
                        </div>

                        <?php if (!empty($request['OwnerPhone'])): ?>
                            <div class="info-item">
                                <span class="info-label">Phone</span>
                                <span class="info-value">
                                    <a href="tel:<?php echo htmlspecialchars($request['OwnerPhone']); ?>">
                                        <?php echo htmlspecialchars($request['OwnerPhone']); ?>
                                    </a>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Pricing Information -->
                    <h4 class="section-title">Pricing & Payment</h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Daily Rate</span>
                            <span class="info-value">RM<?php echo number_format($request['PetPrice'], 2); ?></span>
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
                                <span class="info-label">Your Earnings</span>
                                <span class="info-value" style="color: #4caf50; font-weight: bold;">
                                    RM<?php echo number_format($payment_info['SitterEarnings'], 2); ?>
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
                            <strong>Your Message to Owner:</strong>
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

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <?php if ($request['Status'] === 'pending' || $request['Status'] === 'approved'): ?>
                            <a href="mailto:<?php echo htmlspecialchars($request['OwnerEmail']); ?>?subject=Pet Sitting Request for <?php echo htmlspecialchars($request['PetName']); ?>"
                                class="btn btn-contact">
                                📧 Email Owner
                            </a>
                        <?php endif; ?>

                        <?php if ($payment_info && $payment_info['PaymentStatus'] === 'paid'): ?>
                            <a href="download_receipt.php?payment_id=<?php echo $payment_info['PaymentID']; ?>"
                                class="btn btn-receipt"
                                target="_blank">
                                View Receipt
                            </a>
                        <?php endif; ?>

                        <?php if ($request['Status'] === 'pending'): ?>
                            <button type="button"
                                class="btn-cancel-new"
                                onclick="openCancelModal()"
                                style="
            background: #e53e3e;
            color: white;
            min-width: 150px;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-size: smaller;
    font-weight: 300;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        "
                                onmouseover="this.style.background='#c53030'; this.style.transform='translateY(-2px)'"
                                onmouseout="this.style.background='#e53e3e'; this.style.transform='translateY(0)'">
                                Cancel Request
                            </button>
                        <?php endif; ?>

                        <?php if ($canMarkCompleted): ?>
                            <!-- SITTER MARK COMPLETED FIRST -->
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="complete_job">
                                <button type="submit" class="btn btn-success"
                                    onclick="return confirm('Mark this job as completed?\\n\\nOwner will need to confirm before payment is released.')">
                                    Mark as Completed
                                </button>
                            </form>

                        <?php elseif ($canReleasePayment): ?>
                            <!-- PAYMENT ALREADY RELEASED -->
                            <div class="payment-released" style="margin: 15px 0; padding: 10px;">
                                <p style="color: #4caf50; margin: 0; text-align: center;">
                                    ✅ Payment Released to Your Wallet
                                </p>
                                <p style="color: #666; font-size: 0.9em; margin: 5px 0 0 0;">
                                    RM<?php echo number_format($payment_info['SitterEarnings'], 2); ?> has been credited.
                                </p>
                            </div>
                        <?php elseif ($request['Status'] === 'approved' && $payment_info && !$hasEnded): ?>
                            <button class="btn btn-outline" disabled>
                                ⏳ Available after <?php echo date('M j, Y', strtotime($request['SitEndDate'])); ?>
                            </button>
                        <?php endif; ?>

                        <a href="myApplications.php" class="btn btn-back">
                            Back to My Requests
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('active');
        }

        // Cancel Modal Functions
        function openCancelModal() {
            document.getElementById('cancelModal').style.display = 'block';
        }

        function closeCancelModal() {
            document.getElementById('cancelModal').style.display = 'none';
        }

        // Review Modal Functions
        function openReviewModal() {
            document.getElementById('reviewModal').style.display = 'block';
        }

        function closeReviewModal() {
            document.getElementById('reviewModal').style.display = 'none';
        }

        function setRating(rating) {
            document.getElementById('ratingInput').value = rating;

            // Update star display
            document.querySelectorAll('.star-rate').forEach((star, index) => {
                if (index < rating) {
                    star.classList.add('active');
                } else {
                    star.classList.remove('active');
                }
            });
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const reviewModal = document.getElementById('reviewModal');
            const cancelModal = document.getElementById('cancelModal');

            if (event.target == reviewModal) {
                closeReviewModal();
            }
            if (event.target == cancelModal) {
                closeCancelModal();
            }
        }

        // Form handling
        document.addEventListener('DOMContentLoaded', function() {
            const cancelForm = document.getElementById('cancelForm');
            if (cancelForm) {
                cancelForm.addEventListener('submit', function() {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    submitBtn.innerHTML = '⏳ Cancelling...';
                    submitBtn.disabled = true;
                });
            }

            // Initialize star rating
            document.querySelectorAll('.star-rate').forEach((star, index) => {
                star.addEventListener('click', function() {
                    setRating(index + 1);
                });
            });
        });
    </script>
</body>

</html>