<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];
$activeTab = $_GET['tab'] ?? 'adoption'; // Default to adoption tab

// Fetch current user data untuk profile picture
$user_stmt = $conn->prepare("SELECT ProfilePicture FROM user WHERE UserID = ?");
$user_stmt->bind_param("i", $userID);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();

// AUTO UPDATE OVERDUE STATUS - HANYA UNTUK PENDING REQUESTS YANG BELUM BAYAR
$auto_overdue_sql = "UPDATE PetSitRequest 
                    SET Status = 'overdue'
                    WHERE Status = 'pending' 
                    AND StartDate < CURDATE()
                    AND SitRequestID NOT IN (
                        SELECT SitRequestID FROM payment WHERE PaymentStatus = 'paid'
                    )";
$conn->query($auto_overdue_sql);

// Handle actions based on active tab
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestID = intval($_POST['request_id']);
    $action = $_POST['action'];
    $requestType = $_POST['request_type']; // 'adoption' or 'petsit'

    if ($requestType === 'adoption') {
        // Adoption request handling
        if ($action === 'approve') {
            $conn->begin_transaction();
            try {
                // Get pet ID and AdopterID from request
                $pet_stmt = $conn->prepare("SELECT PetID, AdopterID FROM AdoptionRequest WHERE RequestID = ?");
                $pet_stmt->bind_param("i", $requestID);
                $pet_stmt->execute();
                $pet_result = $pet_stmt->get_result()->fetch_assoc();
                $petID = $pet_result['PetID'];
                $adopterID = $pet_result['AdopterID'];

                // Approve selected request
                $approve_stmt = $conn->prepare("UPDATE AdoptionRequest SET Status = 'approved' WHERE RequestID = ?");
                $approve_stmt->bind_param("i", $requestID);
                $approve_stmt->execute();

                // Reject all other pending requests for this pet
                $reject_stmt = $conn->prepare("UPDATE AdoptionRequest SET Status = 'rejected', RejectionReason = 'Another adopter was selected' WHERE PetID = ? AND RequestID != ? AND Status = 'pending'");
                $reject_stmt->bind_param("ii", $petID, $requestID);
                $reject_stmt->execute();

                // Update pet status to adopted
                $pet_update_stmt = $conn->prepare("UPDATE pet SET Status = 'Adopted' WHERE PetID = ?");
                $pet_update_stmt->bind_param("i", $petID);
                $pet_update_stmt->execute();

                // TAMBAH: UPDATE TOTAL_TRANSACTIONS UNTUK ADOPTION
                // Untuk ADOPTER
                $update_adopter_sql = "UPDATE user SET total_transactions = total_transactions + 1 WHERE UserID = ?";
                $update_adopter_stmt = $conn->prepare($update_adopter_sql);
                $update_adopter_stmt->bind_param("i", $adopterID);
                $update_adopter_stmt->execute();

                // Untuk OWNER (current user)
                $update_owner_sql = "UPDATE user SET total_transactions = total_transactions + 1 WHERE UserID = ?";
                $update_owner_stmt = $conn->prepare($update_owner_sql);
                $update_owner_stmt->bind_param("i", $userID);
                $update_owner_stmt->execute();

                $conn->commit();
                header("Location: ownerRequests.php?tab=adoption&success=Adoption request approved successfully");
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                header("Location: ownerRequests.php?tab=adoption&error=Failed to process request");
                exit;
            }
        } elseif ($action === 'reject') {
            $rejection_reason = trim($_POST['rejection_reason'] ?? '');
            $stmt = $conn->prepare("UPDATE AdoptionRequest SET Status = 'rejected', RejectionReason = ? WHERE RequestID = ?");
            $stmt->bind_param("si", $rejection_reason, $requestID);
            $stmt->execute();
            header("Location: ownerRequests.php?tab=adoption&success=Adoption request rejected");
            exit;
        }
    } elseif ($requestType === 'petsit') {
        if ($action === 'approve') {
            try {
                $conn->begin_transaction();

                // Get pet and date info dari request yang di-approve
                $info_stmt = $conn->prepare("SELECT PetID, SitterID, StartDate, EndDate FROM PetSitRequest WHERE SitRequestID = ?");
                $info_stmt->bind_param("i", $requestID);
                $info_stmt->execute();
                $info_result = $info_stmt->get_result()->fetch_assoc();

                $petID = $info_result['PetID'];
                $sitterID = $info_result['SitterID'];
                $startDate = $info_result['StartDate'];
                $endDate = $info_result['EndDate'];

                // 1. Approve selected request
                $approve_stmt = $conn->prepare("UPDATE PetSitRequest SET Status = 'approved' WHERE SitRequestID = ?");
                $approve_stmt->bind_param("i", $requestID);
                $approve_stmt->execute();

                // 2. Reject all OTHER pending requests untuk SAME PET dengan OVERLAPPING DATES
                $reject_stmt = $conn->prepare("UPDATE PetSitRequest 
                                          SET Status = 'rejected', 
                                              RejectionReason = 'Another sitter was selected for this period'
                                          WHERE PetID = ? 
                                          AND SitRequestID != ? 
                                          AND Status = 'pending'
                                          AND (
                                              (StartDate BETWEEN ? AND ?)
                                              OR (EndDate BETWEEN ? AND ?)
                                              OR (? BETWEEN StartDate AND EndDate)
                                              OR (? BETWEEN StartDate AND EndDate)
                                          )");
                $reject_stmt->bind_param("iissssss", $petID, $requestID, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate);
                $reject_stmt->execute();

                // 3. Update pet status
                $pet_update = $conn->prepare("UPDATE pet SET Status = 'Pet Sit' WHERE PetID = ?");
                $pet_update->bind_param("i", $petID);
                $pet_update->execute();

                // ❌ JANGAN UPDATE TOTAL_TRANSACTIONS DI SINI!
                // Untuk PET SITTING, transaction BELUM COMPLETE
                // Payment belum dibuat, service belum dilakukan
                // Update total_transactions akan dibuat dalam ownerPetSitRequestDetails.php
                // apabila owner CONFIRM COMPLETION

                $conn->commit();

                header("Location: ownerRequests.php?tab=petsit&success=Pet sit request approved successfully");
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                header("Location: ownerRequests.php?tab=petsit&error=Failed to process request: " . $e->getMessage());
                exit;
            }
        } elseif ($action === 'reject') {
            $rejectionReason = trim($_POST['rejection_reason'] ?? '');
            $stmt = $conn->prepare("UPDATE PetSitRequest SET Status = 'rejected', RejectionReason = ? WHERE SitRequestID = ?");
            $stmt->bind_param("si", $rejectionReason, $requestID);

            if ($stmt->execute()) {
                header("Location: ownerRequests.php?tab=petsit&success=Pet sit request rejected");
            } else {
                header("Location: ownerRequests.php?tab=petsit&error=Failed to reject request");
            }
            exit;
        }
    }
}

// Fetch adoption requests dengan AdopterID
$adoption_requests = [];
if ($activeTab === 'adoption') {
    $stmt = $conn->prepare("SELECT ar.*, 
              p.Name as PetName, p.Image as PetImage, p.Type as PetType, p.Breed as PetBreed,
              p.Age as PetAge, p.Gender as PetGender, p.Status as PetStatus,
              u.UserID as AdopterID, u.Name as AdopterName, u.Email as AdopterEmail, 
              u.Phone as AdopterPhone, u.ProfilePicture as AdopterPhoto
              FROM AdoptionRequest ar
              JOIN pet p ON ar.PetID = p.PetID
              JOIN user u ON ar.AdopterID = u.UserID
              WHERE ar.OwnerID = ? AND p.OwnerID = ?
              ORDER BY ar.RequestDate DESC");
    $stmt->bind_param("ii", $userID, $userID);
    $stmt->execute();
    $adoption_requests = $stmt->get_result();
}

// Fetch pet sit requests dengan payment info DAN SitterID
$petsit_requests = [];
if ($activeTab === 'petsit') {
    $sql = "SELECT 
                psr.SitRequestID, psr.Status, psr.RequestDate, psr.StartDate, psr.EndDate,
                psr.TotalDays, psr.DailyRate, psr.TotalAmount, psr.SitterMessage,
                psr.SitterCompleted, psr.OwnerConfirmed, psr.CompletionStatus,
                p.PetID, p.Name AS PetName, p.Image AS PetImage, p.Type AS PetType, p.Breed AS PetBreed,
                p.Age AS PetAge, p.Gender AS PetGender, p.Status AS PetStatus,
                p.District AS PetDistrict, p.State AS PetState,
                sitter.UserID as SitterID, sitter.Name AS SitterName, sitter.Email AS SitterEmail, 
                sitter.Phone AS SitterPhone, sitter.ProfilePicture AS SitterPhoto,
                pay.PaymentStatus, pay.PaymentDate
            FROM PetSitRequest psr
            JOIN pet p ON psr.PetID = p.PetID
            JOIN user sitter ON psr.SitterID = sitter.UserID
            LEFT JOIN payment pay ON psr.SitRequestID = pay.SitRequestID
            WHERE psr.OwnerID = ?
            ORDER BY 
                CASE 
                    WHEN psr.Status = 'overdue' THEN 1
                    WHEN psr.Status = 'pending' THEN 2
                    ELSE 3
                END,
                psr.RequestDate DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $petsit_requests = $stmt->get_result();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Requests - FurCare</title>
    <link rel="stylesheet" href="css/userDashboard.css">
    <style>
        .requests-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-title {
            color: #3B7A57;
            margin-bottom: 20px;
            text-align: center;
        }

        /* Tab Styles */
        .tabs {
            display: flex;
            background: white;
            border-radius: 12px;
            padding: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .tab {
            flex: 1;
            padding: 15px 20px;
            text-align: center;
            background: none;
            border: none;
            border-radius: 8px;
            font-size: 1em;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #666;
        }

        .tab.active {
            background: #3B7A57;
            color: white;
            box-shadow: 0 2px 8px rgba(59, 122, 87, 0.3);
        }

        .tab:hover:not(.active) {
            background: #f8f9fa;
            color: #3B7A57;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Common Card Styles */
        .requests-grid {
            display: grid;
            gap: 20px;
        }

        .request-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            gap: 20px;
            transition: transform 0.3s ease;
            position: relative;
        }

        .request-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .pet-image {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid #e0e0e0;
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e0e0e0;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .user-avatar:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            border-color: #3B7A57;
        }

        .user-avatar-link {
            display: inline-block;
            text-decoration: none;
        }

        .request-info {
            flex: 1;
        }

        .pet-name {
            font-size: 1.3em;
            color: #3B7A57;
            margin: 0 0 10px 0;
            font-weight: 600;
        }

        .user-name {
            font-size: 1.1em;
            color: #333;
            margin: 0 0 5px 0;
            font-weight: 600;
        }

        .request-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 0.8em;
            color: #666;
            margin-bottom: 2px;
            font-weight: 500;
        }

        .meta-value {
            font-weight: 500;
            color: #333;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-pending {
            background: #ed8936;
            color: white;
        }

        .status-approved {
            background: #48bb78;
            color: white;
        }

        .status-rejected {
            background: #e53e3e;
            color: white;
        }

        .status-completed {
            background: #4299e1;
            color: white;
        }

        .status-overdue {
            background: #ff6b6b;
            color: white;
            animation: pulse 2s infinite;
        }

        .status-cancelled {
            background: #95a5a6;
            color: white;
        }

        .payment-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7em;
            font-weight: 600;
            display: inline-block;
            margin-left: 5px;
        }

        .payment-paid {
            background: #48bb78;
            color: white;
        }

        .payment-pending {
            background: #ed8936;
            color: white;
        }

        /* Status Notification Sections */
        .status-notification {
            margin-top: 15px;
            padding: 12px 15px;
            border-radius: 8px;
            font-size: 0.9em;
            line-height: 1.4;
        }

        .status-notification-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .status-notification-header h5 {
            margin: 0;
            font-size: 0.95em;
        }

        .status-notification small {
            font-size: 0.85em;
            opacity: 0.8;
        }

        /* Specific Status Types */
        .status-overdue-notification {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }

        .status-paid-notification {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .status-warning-notification {
            background: #e3f2fd;
            border: 1px solid #90caf9;
            color: #1565c0;
        }

        .status-performance-notification {
            background: #fff3e0;
            border: 1px solid #ffcc80;
            color: #e65100;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85em;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-block;
            text-align: center;
            font-family: Arial, sans-serif;
            font-weight: normal;
        }

        .btn-view {
            background: #4299e1;
            color: white;
        }

        .btn-view:hover {
            background: #3182ce;
        }

        .btn-approve {
            background: #48bb78;
            color: white;
        }

        .btn-approve:hover {
            background: #38a169;
        }

        .btn-reject {
            background: #e53e3e;
            color: white;
        }

        .btn-reject:hover {
            background: #c53030;
        }

        .btn-contact {
            background: #38b2ac;
            color: white;
        }

        .btn-contact:hover {
            background: #319795;
        }

        .no-requests {
            text-align: center;
            padding: 60px 20px;
            color: #666;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .no-requests::before {
            content: '🐾';
            font-size: 4em;
            display: block;
            margin-bottom: 20px;
            opacity: 0.6;
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
        }

        .price-highlight {
            color: #3B7A57;
            font-weight: bold;
            font-size: 1.1em;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 20px;
            border-radius: 12px;
            width: 500px;
            max-width: 90%;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .close {
            font-size: 24px;
            cursor: pointer;
            color: #666;
            background: #f8f9fa;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .close:hover {
            background: #e9ecef;
            color: #333;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-group textarea {
            width: 90%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            resize: vertical;
            min-height: 80px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        @keyframes pulse {
            0% {
                opacity: 1;
            }

            50% {
                opacity: 0.7;
            }

            100% {
                opacity: 1;
            }
        }

        @media (max-width: 768px) {
            .request-card {
                flex-direction: column;
                text-align: center;
            }

            .pet-image,
            .user-avatar {
                margin: 0 auto;
            }

            .request-meta {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                justify-content: center;
            }

            .tabs {
                flex-direction: column;
            }
        }

        /* Navbar Profile Picture */
        .profile-icon-link {
            display: inline-block;
            text-decoration: none;
        }

        .profile-icon {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid #fff;
            transition: transform 0.3s;
        }

        .profile-icon:hover {
            transform: scale(1.1);
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
        <a href="addPetUser.php">➕ Post a Pet</a>
        <a href="ownerRequests.php" class="active">📩 My Pet Requests</a>
        <a href="myApplications.php">📋 My Requests</a>
        <a href="logout.php" class="logout">🚪 Logout</a>
    </div>

    <div class="overlay" onclick="toggleSidebar()"></div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="requests-container">
            <h1 class="page-title">Requests for My Pets</h1>

            <!-- Success/Error Messages -->
            <?php if (isset($_GET['success'])): ?>
                <div class="success-message">
                    ✅ <?php echo htmlspecialchars($_GET['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="error-message">
                    ❌ <?php echo htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Tab Navigation -->
            <div class="tabs">
                <button class="tab <?php echo $activeTab === 'adoption' ? 'active' : ''; ?>"
                    onclick="switchTab('adoption')">
                    🏠 Adoption Requests
                </button>
                <button class="tab <?php echo $activeTab === 'petsit' ? 'active' : ''; ?>"
                    onclick="switchTab('petsit')">
                    🐾 Pet Sit Requests
                </button>
            </div>

            <!-- Adoption Requests Tab -->
            <div id="adoption-tab" class="tab-content <?php echo $activeTab === 'adoption' ? 'active' : ''; ?>">
                <?php if ($adoption_requests && $adoption_requests->num_rows > 0): ?>
                    <div class="requests-grid">
                        <?php while ($request = $adoption_requests->fetch_assoc()): ?>
                            <div class="request-card adoption">
                                <img src="<?php echo htmlspecialchars($request['PetImage']); ?>"
                                    alt="<?php echo htmlspecialchars($request['PetName']); ?>"
                                    class="pet-image">

                                <a href="profile.php?user_id=<?php echo $request['AdopterID']; ?>"
                                    class="user-avatar-link"
                                    title="View <?php echo htmlspecialchars($request['AdopterName']); ?>'s Profile">
                                    <img src="<?php echo !empty($request['AdopterPhoto']) ? htmlspecialchars($request['AdopterPhoto']) : 'uploads/profile_icon.png'; ?>"
                                        alt="<?php echo htmlspecialchars($request['AdopterName']); ?>"
                                        class="user-avatar">
                                </a>

                                <div class="request-info">
                                    <h3 class="pet-name"><?php echo htmlspecialchars($request['PetName']); ?></h3>
                                    <h4 class="user-name">
                                        <a href="profile.php?user_id=<?php echo $request['AdopterID']; ?>"
                                            style="text-decoration: none; color: #333;">
                                            Adoption Request from <?php echo htmlspecialchars($request['AdopterName']); ?>
                                        </a>
                                    </h4>

                                    <div class="request-meta">
                                        <div class="meta-item">
                                            <span class="meta-label">Pet Type</span>
                                            <span class="meta-value"><?php echo htmlspecialchars($request['PetType']); ?> • <?php echo htmlspecialchars($request['PetBreed']); ?></span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Adopter Contact</span>
                                            <span class="meta-value">
                                                <?php echo htmlspecialchars($request['AdopterEmail']); ?><br>
                                                <?php echo htmlspecialchars($request['AdopterPhone']); ?>
                                            </span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Request Date</span>
                                            <span class="meta-value"><?php echo date('M j, Y', strtotime($request['RequestDate'])); ?></span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Pet Status</span>
                                            <span class="meta-value" style="color: <?php echo $request['PetStatus'] === 'Available' ? '#48bb78' : '#e53e3e'; ?>">
                                                <?php echo htmlspecialchars($request['PetStatus']); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div>
                                        <span class="meta-label">Request Status:</span>
                                        <span class="status-badge status-<?php echo $request['Status']; ?>">
                                            <?php echo ucfirst($request['Status']); ?>
                                        </span>
                                    </div>

                                    <div class="action-buttons">
                                        <a href="ownerAdoptionRequestDetails.php?request_id=<?php echo $request['RequestID']; ?>"
                                            class="btn btn-view">View Full Details</a>

                                        <?php if ($request['Status'] === 'pending' && $request['PetStatus'] === 'Available'): ?>
                                            <button class="btn btn-approve"
                                                onclick="openApproveModal(<?php echo $request['RequestID']; ?>, 'adoption', '<?php echo htmlspecialchars($request['PetName']); ?>', '<?php echo htmlspecialchars($request['AdopterName']); ?>')">
                                                Approve
                                            </button>
                                            <button class="btn btn-reject"
                                                onclick="openRejectModal(<?php echo $request['RequestID']; ?>, 'adoption', '<?php echo htmlspecialchars($request['AdopterName']); ?>')">
                                                Reject
                                            </button>
                                        <?php endif; ?>

                                        <a href="mailto:<?php echo htmlspecialchars($request['AdopterEmail']); ?>"
                                            class="btn btn-contact">
                                            📧 Contact Adopter
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="no-requests">
                        <h3>No Adoption Requests Yet</h3>
                        <p>You haven't received any adoption requests for your pets yet.</p>
                        <p>When someone applies to adopt your pet, you'll see their requests here.</p>
                        <a href="myPets.php" class="btn" style="background: #5e8a72ff; color: white; padding: 12px 24px; display: inline-block; margin-top: 15px; text-decoration: none;">
                            View My Pets
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pet Sit Requests Tab -->
            <div id="petsit-tab" class="tab-content <?php echo $activeTab === 'petsit' ? 'active' : ''; ?>">
                <?php if ($petsit_requests && $petsit_requests->num_rows > 0): ?>
                    <div class="requests-grid">
                        <?php while ($request = $petsit_requests->fetch_assoc()):
                            // Overdue hanya untuk pending requests YANG BELUM BAYAR
                            $isOverdue = $request['Status'] === 'overdue';

                            // Check payment status
                            $hasPaid = $request['PaymentStatus'] === 'paid';
                            $paymentDate = $request['PaymentDate'] ?? null;

                            // Sitter performance issue (bukan overdue)
                            $isSitterProblem = ($request['Status'] === 'approved' &&
                                strtotime($request['EndDate']) < time() &&
                                $request['SitterCompleted'] == 0);

                            // Check if service is completed
                            $isCompleted = $request['Status'] === 'completed';
                        ?>
                            <div class="request-card petsit <?php echo $isOverdue ? 'overdue' : ''; ?> <?php echo $hasPaid ? 'paid' : ''; ?>">
                                <img src="<?php echo htmlspecialchars($request['PetImage']); ?>"
                                    alt="<?php echo htmlspecialchars($request['PetName']); ?>"
                                    class="pet-image">

                                <a href="profile.php?user_id=<?php echo $request['SitterID']; ?>"
                                    class="user-avatar-link"
                                    title="View <?php echo htmlspecialchars($request['SitterName']); ?>'s Profile">
                                    <img src="<?php echo !empty($request['SitterPhoto']) ? htmlspecialchars($request['SitterPhoto']) : 'uploads/profile_icon.png'; ?>"
                                        alt="<?php echo htmlspecialchars($request['SitterName']); ?>"
                                        class="user-avatar">
                                </a>

                                <div class="request-info">
                                    <h3 class="pet-name"><?php echo htmlspecialchars($request['PetName']); ?></h3>
                                    <h4 class="user-name">
                                        <a href="profile.php?user_id=<?php echo $request['SitterID']; ?>"
                                            style="text-decoration: none; color: #333;">
                                            Pet Sit Request from <?php echo htmlspecialchars($request['SitterName']); ?>
                                        </a>
                                    </h4>

                                    <div class="request-meta">
                                        <div class="meta-item">
                                            <span class="meta-label">Pet Type</span>
                                            <span class="meta-value"><?php echo htmlspecialchars($request['PetType']); ?> • <?php echo htmlspecialchars($request['PetBreed']); ?></span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Sitter Contact</span>
                                            <span class="meta-value">
                                                <?php echo htmlspecialchars($request['SitterEmail']); ?><br>
                                                <?php echo htmlspecialchars($request['SitterPhone']); ?>
                                            </span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Sitting Period</span>
                                            <span class="meta-value">
                                                <?php echo date('M j, Y', strtotime($request['StartDate'])); ?> to<br>
                                                <?php echo date('M j, Y', strtotime($request['EndDate'])); ?>
                                            </span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Total Amount</span>
                                            <span class="meta-value price-highlight">
                                                RM<?php echo number_format($request['TotalAmount'], 2); ?><br>
                                                <small>(RM<?php echo number_format($request['DailyRate'], 2); ?>/day)</small>
                                            </span>
                                        </div>
                                    </div>

                                    <div>
                                        <span class="meta-label">Request Status:</span>
                                        <span class="status-badge status-<?php echo $request['Status']; ?> <?php echo $isOverdue ? 'status-overdue' : ''; ?>">
                                            <?php echo ucfirst($request['Status']); ?>
                                        </span>
                                        <?php if ($hasPaid && !$isCompleted): ?>
                                            <span class="payment-badge payment-paid">PAID</span>
                                        <?php elseif ($request['PaymentStatus'] === 'pending' && !$isCompleted): ?>
                                            <span class="payment-badge payment-pending">PENDING PAYMENT</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($request['SitterMessage'])): ?>
                                        <div style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 6px;">
                                            <span class="meta-label">Sitter's Message:</span>
                                            <p style="margin: 5px 0 0 0; font-size: 0.9em;">"<?php echo htmlspecialchars($request['SitterMessage']); ?>"</p>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Status Notifications -->
                                    <?php if ($isOverdue): ?>
                                        <div class="status-notification status-overdue-notification">
                                            <div class="status-notification-header">
                                                ⚠️ <h5>OVERDUE</h5>
                                            </div>
                                            You didn't approve any sitter before the start date.
                                            <small>Please post your pet again for new requests.</small>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($hasPaid && !$isCompleted): ?>
                                        <div class="status-notification status-paid-notification">
                                            <div class="status-notification-header">
                                                ✅ <h5>PAYMENT CONFIRMED</h5>
                                            </div>
                                            You have paid for this service on <?php echo date('M j, Y', strtotime($paymentDate)); ?>.
                                            <small>Waiting for sitter to complete the service.</small>
                                        </div>
                                    <?php elseif ($hasPaid && $isCompleted): ?>
                                        <div class="status-notification status-paid-notification">
                                            <div class="status-notification-header">
                                                ✅ <h5>SERVICE COMPLETED</h5>
                                            </div>
                                            Payment was confirmed on <?php echo date('M j, Y', strtotime($paymentDate)); ?>.
                                            <small>This pet sitting service has been completed successfully.</small>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($isSitterProblem && !$hasPaid && !$isCompleted): ?>
                                        <div class="status-notification status-performance-notification">
                                            <div class="status-notification-header">
                                                ⚠️ <h5>PERFORMANCE NOTE</h5>
                                            </div>
                                            Sitter hasn't completed the service by end date.
                                            <small>This affects the sitter's reliability rating.</small>
                                        </div>
                                    <?php elseif ($isSitterProblem && $hasPaid && !$isCompleted): ?>
                                        <div class="status-notification status-warning-notification">
                                            <div class="status-notification-header">
                                                ⚠️ <h5>ACTION REQUIRED</h5>
                                            </div>
                                            Sitter hasn't completed the service.
                                            <small>Please contact the sitter or request a refund.</small>
                                        </div>
                                    <?php endif; ?>

                                    <div class="action-buttons">
                                        <a href="ownerPetSitRequestDetails.php?request_id=<?php echo $request['SitRequestID']; ?>"
                                            class="btn btn-view">View Full Details</a>

                                        <?php if ($request['Status'] === 'pending'): ?>
                                            <button class="btn btn-approve"
                                                onclick="openApproveModal(<?php echo $request['SitRequestID']; ?>, 'petsit', '<?php echo htmlspecialchars($request['PetName']); ?>', '<?php echo htmlspecialchars($request['SitterName']); ?>')">
                                                Approve Sitter
                                            </button>
                                            <button class="btn btn-reject"
                                                onclick="openRejectModal(<?php echo $request['SitRequestID']; ?>, 'petsit', '<?php echo htmlspecialchars($request['SitterName']); ?>')">
                                                Reject
                                            </button>
                                        <?php endif; ?>

                                        <a href="mailto:<?php echo htmlspecialchars($request['SitterEmail']); ?>?subject=Pet Sitting Request for <?php echo htmlspecialchars($request['PetName']); ?>"
                                            class="btn btn-contact">
                                            📧 Contact Sitter
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="no-requests">
                        <h3>No Pet Sit Requests Yet</h3>
                        <p>You haven't received any pet sitting requests for your pets yet.</p>
                        <p>When someone applies to sit your pet, you'll see their requests here.</p>
                        <a href="myPets.php" class="btn" style="background: #9296abff; color: white; padding: 12px 24px; display: inline-block; margin-top: 15px; text-decoration: none;">
                            View My Pets
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Approve Modal -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="approveModalTitle">Approve Request</h3>
                <button class="close" onclick="closeApproveModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="request_id" id="approveRequestID">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="request_type" id="approveRequestType">
                <div class="form-group">
                    <p id="approveModalMessage"></p>
                    <p><strong>Note:</strong> <span id="approveModalNote"></span></p>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn" style="background: #6c757d; color: white;" onclick="closeApproveModal()">Cancel</button>
                    <button type="submit" class="btn btn-approve">Confirm Approval</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="rejectModalTitle">Reject Request</h3>
                <button class="close" onclick="closeRejectModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="request_id" id="rejectRequestID">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="request_type" id="rejectRequestType">
                <div class="form-group">
                    <label>Reason for Rejection (Optional):</label>
                    <textarea name="rejection_reason" placeholder="Let them know why you're rejecting their request..."></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn" style="background: #6c757d; color: white;" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn btn-reject">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            document.body.classList.toggle('sidebar-open');
        }

        function switchTab(tabName) {
            console.log('Switching to tab:', tabName);
            window.location.href = `ownerRequests.php?tab=${tabName}`;
        }

        function openApproveModal(requestID, requestType, petName, userName) {
            document.getElementById('approveRequestID').value = requestID;
            document.getElementById('approveRequestType').value = requestType;

            if (requestType === 'adoption') {
                document.getElementById('approveModalTitle').textContent = 'Approve Adoption Request';
                document.getElementById('approveModalMessage').innerHTML =
                    `Are you sure you want to approve <strong>${userName}</strong>'s adoption request for <strong>${petName}</strong>?`;
                document.getElementById('approveModalNote').textContent =
                    'This will automatically reject all other pending requests for this pet and mark the pet as adopted.';
            } else {
                document.getElementById('approveModalTitle').textContent = 'Approve Pet Sit Request';
                document.getElementById('approveModalMessage').innerHTML =
                    `Are you sure you want to approve <strong>${userName}</strong> as the sitter for <strong>${petName}</strong>?`;
                document.getElementById('approveModalNote').textContent =
                    'This will automatically reject all other pending requests for the same dates.';
            }

            document.getElementById('approveModal').style.display = 'block';
        }

        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
        }

        function openRejectModal(requestID, requestType, userName) {
            document.getElementById('rejectRequestID').value = requestID;
            document.getElementById('rejectRequestType').value = requestType;

            if (requestType === 'adoption') {
                document.getElementById('rejectModalTitle').textContent = 'Reject Adoption Request';
            } else {
                document.getElementById('rejectModalTitle').textContent = 'Reject Pet Sit Request';
            }

            document.getElementById('rejectModal').style.display = 'block';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const approveModal = document.getElementById('approveModal');
            const rejectModal = document.getElementById('rejectModal');

            if (event.target == approveModal) closeApproveModal();
            if (event.target == rejectModal) closeRejectModal();
        }

        // Auto-hide messages after 5 seconds
        setTimeout(() => {
            const messages = document.querySelectorAll('.success-message, .error-message');
            messages.forEach(msg => {
                if (msg) msg.style.display = 'none';
            });
        }, 5000);

        // Add event listeners untuk tabs
        document.addEventListener('DOMContentLoaded', function() {
            const adoptionTab = document.querySelector('.tab[onclick*="adoption"]');
            const petsitTab = document.querySelector('.tab[onclick*="petsit"]');

            if (adoptionTab) {
                adoptionTab.addEventListener('click', function() {
                    switchTab('adoption');
                });
            }

            if (petsitTab) {
                petsitTab.addEventListener('click', function() {
                    switchTab('petsit');
                });
            }
        });
    </script>
</body>

</html>