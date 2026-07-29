<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];
$requestID = $_GET['request_id'] ?? 0;

// Fetch adoption request details
$stmt = $conn->prepare("SELECT ar.*, 
          p.Name as PetName, p.Image as PetImage, p.Type as PetType, p.Breed as PetBreed, 
          p.Age as PetAge, p.Gender as PetGender, p.Description as PetDescription,
          p.District as PetDistrict, p.State as PetState, p.Status as PetStatus,
          p.PetID as PetID,
          u.Name as AdopterName, u.Email as AdopterEmail, u.Phone as AdopterPhone, 
          u.ProfilePicture as AdopterPhoto,
          owner.Name as OwnerName, owner.Email as OwnerEmail, owner.Phone as OwnerPhone,
          owner.ProfilePicture as OwnerPhoto
          FROM AdoptionRequest ar
          JOIN pet p ON ar.PetID = p.PetID
          JOIN user u ON ar.AdopterID = u.UserID
          JOIN user owner ON ar.OwnerID = owner.UserID
          WHERE ar.RequestID = ? AND ar.OwnerID = ?");
$stmt->bind_param("ii", $requestID, $userID);
$stmt->execute();
$result = $stmt->get_result();
$request = $result->fetch_assoc();

if (!$request) {
    header("Location: ownerAdoptionRequests.php?error=Request not found");
    exit;
}

// Handle form submissions for approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $requestID = $_POST['request_id'] ?? 0;
    $petID = $_POST['pet_id'] ?? 0;

    // Verify that the request belongs to the current user
    $verifyStmt = $conn->prepare("SELECT OwnerID FROM AdoptionRequest WHERE RequestID = ?");
    $verifyStmt->bind_param("i", $requestID);
    $verifyStmt->execute();
    $verifyResult = $verifyStmt->get_result()->fetch_assoc();

    if (!$verifyResult || $verifyResult['OwnerID'] != $userID) {
        header("Location: ownerAdoptionRequestDetails.php?request_id=$requestID&error=Unauthorized action");
        exit;
    }

    if ($action === 'approve') {
        // Start transaction
        $conn->begin_transaction();

        try {
            // Dapatkan PetID dari request
            $getPetID = $conn->prepare("SELECT PetID, AdopterID, OwnerID FROM AdoptionRequest WHERE RequestID = ?");
            $getPetID->bind_param("i", $requestID);
            $getPetID->execute();
            $petResult = $getPetID->get_result()->fetch_assoc();
            $petID = $petResult['PetID'];
            $adopterID = $petResult['AdopterID'];
            $ownerID = $petResult['OwnerID'];

            // Approve selected request
            $updateStmt = $conn->prepare("UPDATE AdoptionRequest SET Status = 'approved' WHERE RequestID = ?");
            $updateStmt->bind_param("i", $requestID);

            if (!$updateStmt->execute()) {
                throw new Exception("Error updating AdoptionRequest: " . $updateStmt->error);
            }

            // AUTO-REJECT: Reject SEMUA pending requests lain untuk PET SAMA
            $rejectStmt = $conn->prepare("UPDATE AdoptionRequest 
                                 SET Status = 'rejected', 
                                     RejectionReason = 'Another adopter was selected for this pet'
                                 WHERE PetID = ? 
                                 AND RequestID != ? 
                                 AND Status = 'pending'");
            $rejectStmt->bind_param("ii", $petID, $requestID);

            if (!$rejectStmt->execute()) {
                throw new Exception("Error rejecting other requests: " . $rejectStmt->error);
            }

            // 4️⃣ Update pet status to Adopted
            $petUpdateStmt = $conn->prepare("UPDATE pet SET Status = 'Adopted' WHERE PetID = ?");
            $petUpdateStmt->bind_param("i", $petID);

            if (!$petUpdateStmt->execute()) {
                // Try with 'Availability' column if 'Status' fails
                $petUpdateStmt = $conn->prepare("UPDATE pet SET Availability = 'Adopted' WHERE PetID = ?");
                $petUpdateStmt->bind_param("i", $petID);

                if (!$petUpdateStmt->execute()) {
                    throw new Exception("Error updating Pet: " . $petUpdateStmt->error);
                }
            }

            // Untuk ADOPTER
            $update_adopter_sql = "UPDATE user SET total_transactions = total_transactions + 1 WHERE UserID = ?";
            $update_adopter_stmt = $conn->prepare($update_adopter_sql);
            $update_adopter_stmt->bind_param("i", $adopterID);
            $update_adopter_stmt->execute();

            // Untuk OWNER
            $update_owner_sql = "UPDATE user SET total_transactions = total_transactions + 1 WHERE UserID = ?";
            $update_owner_stmt = $conn->prepare($update_owner_sql);
            $update_owner_stmt->bind_param("i", $ownerID);
            $update_owner_stmt->execute();
          

            // Commit transaction
            $conn->commit();
            header("Location: ownerAdoptionRequestDetails.php?request_id=$requestID&success=Adoption request approved successfully");
            exit;
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            header("Location: ownerAdoptionRequestDetails.php?request_id=$requestID&error=" . urlencode($e->getMessage()));
            exit;
        }
    } elseif ($action === 'reject') {
        $rejectionReason = $_POST['rejection_reason'] ?? '';

        if (!empty($rejectionReason)) {
            $updateStmt = $conn->prepare("UPDATE AdoptionRequest SET Status = 'rejected', RejectionReason = ? WHERE RequestID = ?");
            $updateStmt->bind_param("si", $rejectionReason, $requestID);

            if ($updateStmt->execute()) {
                header("Location: ownerAdoptionRequestDetails.php?request_id=$requestID&success=Adoption request rejected successfully");
                exit;
            } else {
                header("Location: ownerAdoptionRequestDetails.php?request_id=$requestID&error=Failed to reject request: " . $updateStmt->error);
                exit;
            }
        } else {
            header("Location: ownerAdoptionRequestDetails.php?request_id=$requestID&error=Rejection reason is required");
            exit;
        }
    } else {
        header("Location: ownerAdoptionRequestDetails.php?request_id=$requestID&error=Invalid action");
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

// Fetch user data for profile picture
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
    <title>Adoption Request Details - Owner - FurCare</title>
    <link rel="stylesheet" href="css/userDashboard.css">
    <style>
        /* ===== MAIN STYLES ===== */
        .details-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #FFF9F5;
            min-height: 100vh;
        }

        .page-title {
            font-size: 2em;
            color: #3B7A57;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
            font-weight: 600;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        @media (max-width: 992px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ===== LEFT COLUMN ===== */
        .left-column {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        /* ===== PET CARD ===== */
        .pet-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            height: fit-content;
        }

        .pet-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
        }

        .pet-name {
            font-size: 1.5em;
            color: #333;
            margin: 0 0 15px 0;
            font-weight: 600;
        }

        .pet-card p {
            margin: 8px 0;
            color: #555;
            font-size: 0.95em;
            display: flex;
            justify-content: space-between;
        }

        .pet-card p strong {
            color: #333;
            font-weight: 600;
            min-width: 80px;
        }

        .description-section {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0;
        }

        .description-section p {
            margin-top: 5px;
            color: #555;
            line-height: 1.6;
        }

        /* ===== ADOPTER CARD (SAME SIZE AS PET CARD) ===== */
        .adopter-card-container {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            height: fit-content;
        }

        .adopter-header-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f3f4;
        }

        .adopter-main-title {
            font-size: 1.3em;
            color: #333;
            font-weight: 600;
            margin-bottom: 15px;
            text-align: center;
            position: relative;
        }

        .adopter-main-title::after {
            content: '';
            position: absolute;
            bottom: -17px;
            left: 50%;
            transform: translateX(-50%);
            width: 50px;
            height: 2px;
        }

        .user-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 15px;
            border: 3px solid #3B7A57;
        }

        .user-name {
            font-size: 1.2em;
            color: #3B7A57;
            margin: 0 0 10px 0;
            font-weight: 600;
            text-align: center;
        }

        /* Adopter Info Grid */
        .adopter-info-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .adopter-info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .adopter-info-label {
            font-size: 0.85em;
            color: #666;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .adopter-info-value {
            font-weight: 500;
            color: #333;
            font-size: 0.95em;
            word-break: break-word;
        }

        /* Contact Button */
        .contact-button-container {
            text-align: center;
            margin-top: 15px;
        }

        .btn-contact-adopter {
            background: #38b2ac;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            width: 100%;
            justify-content: center;
        }

        .btn-contact-adopter:hover {
            background: #319795;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(56, 178, 172, 0.2);
        }

        /* ===== INFO CARD ===== */
        .info-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            height: fit-content;
        }

        .section-title {
            font-size: 1.3em;
            color: #333;
            margin: 0 0 20px 0;
            padding-bottom: 12px;
            border-bottom: 2px solid #e9ecef;
            font-weight: 600;
            position: relative;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 50px;
            height: 2px;
            background: #007bff;
        }

        /* ===== INFO GRID ===== */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #f1f3f4;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-label {
            font-size: 0.85em;
            color: #666;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-weight: 500;
            color: #333;
            font-size: 1em;
            word-break: break-word;
        }

        /* ===== STATUS BADGES ===== */
        .status-badge {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
            letter-spacing: 0.5px;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .status-cancelled {
            background: #e2e3e5;
            /* kelabu lembut */
            color: #6c757d;
            /* kelabu gelap */
            border: 1px solid #d6d8db;
        }


        /* ===== REJECTION BOX ===== */
        .rejection-box {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 8px;
            padding: 20px;
            margin-top: 25px;
        }

        .rejection-title {
            color: #721c24;
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 1.1em;
        }

        .rejection-box div {
            color: #721c24;
            line-height: 1.6;
            white-space: pre-line;
        }

        /* ===== MESSAGE BOX ===== */
        .message-box {
            background: #f0f9ff;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #007bff;
            margin: 20px 0;
        }

        /* ===== ACTION BUTTONS ===== */
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid #e9ecef;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.95em;
            font-weight: 500;
            font-size: smaller;
            /* atau 400 untuk regular weight */
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 150px;
            font-family: Arial, sans-serif;
            /* Tambah ini */
            font-weight: normal;
            /* atau 400 */
        }

        /* Atau kalau nak semua button types guna Arial dan regular weight */
        .btn-back,
        .btn-approve,
        .btn-reject,
        .btn-contact-both,
        .btn-contact-adopter {
            font-family: Arial, sans-serif;
            font-weight: normal;
        }

        .btn-back {
            background: #6c757d;
            color: white;
        }

        .btn-back:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.2);
        }

        .btn-approve {
            background: #28a745;
            color: white;
        }

        .btn-approve:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.2);
        }

        .btn-reject {
            background: #e53e3e;
            color: white;
        }

        .btn-reject:hover {
            background: #c53030;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.2);
        }

        .btn-contact-both {
            background: #007bff;
            color: white;
        }

        .btn-contact-both:hover {
            background: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.2);
        }

        /* Button dalam Adopter Card */
        .btn-contact-adopter {
            background: #38b2ac;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: normal;
            font-size: smaller;
            /* Ubah dari 600 ke normal */
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            width: 90%;
            justify-content: center;
            font-family: Arial, sans-serif;
            /* Tambah ini */
        }

        /* ===== ALERT MESSAGES ===== */
        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid;
        }

        .alert-success {
            background-color: #f0fdf4;
            color: #166534;
            border-color: #bbf7d0;
        }

        .alert-error {
            background-color: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }

        /* ===== MODAL STYLES ===== */
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
            padding: 30px;
            border-radius: 12px;
            width: 450px;
            max-width: 90%;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e9ecef;
        }

        .modal-header h3 {
            font-size: 1.3em;
            font-weight: 700;
            color: #333;
            margin: 0;
        }

        .close {
            background: #f8f9fa;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
            color: #666;
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
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
        }

        .form-group textarea:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 25px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .details-container {
                padding: 15px;
            }

            .page-title {
                font-size: 1.6em;
            }

            .details-grid {
                gap: 20px;
            }

            .pet-card,
            .adopter-card-container,
            .info-card {
                padding: 20px;
            }

            .info-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                min-width: auto;
            }

            .pet-image {
                height: 180px;
            }

            .user-avatar {
                width: 70px;
                height: 70px;
            }
        }

        /* Additional text styles */
        .text-area-content {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            margin-top: 10px;
            line-height: 1.6;
            white-space: pre-line;
        }

        /* Navbar and Main Content Styles */
        .main-content {
            margin-top: 10px;
            padding-top: 10px;
        }

        .title {
            color: #3B7A57;
            text-align: center;
            margin-bottom: 20px;
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

    <!-- Approve Modal -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Approve Adoption Request</h3>
                <span class="close" onclick="closeApproveModal()">&times;</span>
            </div>
            <form method="POST" action="ownerAdoptionRequestDetails.php?request_id=<?php echo $requestID; ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="request_id" value="<?php echo $request['RequestID']; ?>">
                <input type="hidden" name="pet_id" value="<?php echo $request['PetID']; ?>">
                <div class="form-group">
                    <p>Are you sure you want to approve this adoption request?</p>
                    <p><strong>This will:</strong></p>
                    <ul>
                        <li>Mark the adoption request as <strong>Approved</strong></li>
                        <li>Change the pet status to <strong>Adopted</strong></li>
                        <li>Notify both adopter and owner</li>
                    </ul>
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
                <h3>Reject Adoption Request</h3>
                <span class="close" onclick="closeRejectModal()">&times;</span>
            </div>
            <form method="POST" action="ownerAdoptionRequestDetails.php?request_id=<?php echo $requestID; ?>">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="request_id" value="<?php echo $request['RequestID']; ?>">
                <div class="form-group">
                    <label>Reason for Rejection (Required):</label>
                    <textarea name="rejection_reason" placeholder="Please provide a clear reason for rejecting this adoption request..." required></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-back" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn btn-reject">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <h1 class="title">Adoption Request Details</h1>

        <div class="details-container">
            <h1 class="page-title">Adoption Request #<?php echo $request['RequestID']; ?></h1>

            <!-- Success/Error Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success">✅ <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="details-grid">
                <!-- Left Column - Pet Card & Adopter Card -->
                <div class="left-column">
                    <!-- Pet Card -->
                    <div class="pet-card">
                        <img src="<?php echo htmlspecialchars($request['PetImage']); ?>"
                            alt="<?php echo htmlspecialchars($request['PetName']); ?>"
                            class="pet-image">

                        <h3 class="pet-name"><?php echo htmlspecialchars($request['PetName']); ?></h3>

                        <p><strong>Type:</strong> <?php echo htmlspecialchars($request['PetType']); ?></p>
                        <p><strong>Breed:</strong> <?php echo htmlspecialchars($request['PetBreed']); ?></p>
                        <p><strong>Age:</strong> <?php echo formatAge($request['PetAge']); ?></p>
                        <p><strong>Gender:</strong> <?php echo htmlspecialchars($request['PetGender']); ?></p>
                        <p><strong>Location:</strong> <?php echo htmlspecialchars($request['PetDistrict'] . ', ' . $request['PetState']); ?></p>
                        <p><strong>Pet Status:</strong>
                            <span style="color: <?php echo $request['PetStatus'] === 'Available' ? '#48bb78' : '#e53e3e'; ?>">
                                <?php echo htmlspecialchars($request['PetStatus']); ?>
                            </span>
                        </p>

                        <?php if (!empty($request['PetDescription'])): ?>
                            <div class="description-section">
                                <strong>Description:</strong>
                                <p><?php echo nl2br(htmlspecialchars($request['PetDescription'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Adopter Card - SAME SIZE AS PET CARD -->
                    <div class="adopter-card-container">
                        <div class="adopter-header-section">
                            <h3 class="adopter-main-title">Adopter Information</h3>
                            <img src="<?php echo !empty($request['AdopterPhoto']) ? htmlspecialchars($request['AdopterPhoto']) : 'uploads/profile_icon.png'; ?>"
                                alt="<?php echo htmlspecialchars($request['AdopterName']); ?>"
                                class="user-avatar">
                            <h4 class="user-name"><?php echo htmlspecialchars($request['AdopterName']); ?></h4>
                        </div>

                        <div class="adopter-info-grid">
                            <div class="adopter-info-item">
                                <span class="adopter-info-label">Email</span>
                                <span class="adopter-info-value">
                                    <a href="mailto:<?php echo htmlspecialchars($request['AdopterEmail']); ?>">
                                        <?php echo htmlspecialchars($request['AdopterEmail']); ?>
                                    </a>
                                </span>
                            </div>

                            <div class="adopter-info-item">
                                <span class="adopter-info-label">Phone</span>
                                <span class="adopter-info-value">
                                    <?php if (!empty($request['AdopterPhone'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($request['AdopterPhone']); ?>">
                                            <?php echo htmlspecialchars($request['AdopterPhone']); ?>
                                        </a>
                                    <?php else: ?>
                                        Not provided
                                    <?php endif; ?>
                                </span>
                            </div>

                            <?php if (!empty($request['AdopterAddress'])): ?>
                                <div class="adopter-info-item">
                                    <span class="adopter-info-label">Address</span>
                                    <span class="adopter-info-value"><?php echo nl2br(htmlspecialchars($request['AdopterAddress'])); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="contact-button-container">
                            <a href="mailto:<?php echo htmlspecialchars($request['AdopterEmail']); ?>"
                                class="btn-contact-adopter">
                                📧 Email Adopter
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Info Card -->
                <div class="info-card">
                    <!-- Status and Request Info -->
                    <h3 class="section-title">Adoption Request Information</h3>

                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Request ID</span>
                            <span class="info-value">#<?php echo $request['RequestID']; ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Status</span>
                            <span class="status-badge status-<?php echo strtolower($request['Status']); ?>">
                                <?php echo ucfirst($request['Status']); ?>
                            </span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Request Date</span>
                            <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($request['RequestDate'])); ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Family Members</span>
                            <span class="info-value"><?php echo $request['FamilyMembers']; ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Has Children</span>
                            <span class="info-value"><?php echo ucfirst($request['HasChildren']); ?></span>
                        </div>

                        <div class="info-item">
                            <span class="info-label">Other Pets</span>
                            <span class="info-value"><?php echo ucfirst($request['OtherPets']); ?></span>
                        </div>
                    </div>

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

                    <!-- Pet Experience -->
                    <?php if (!empty($request['PetExperience'])): ?>
                        <div class="info-item" style="margin-bottom: 15px;">
                            <span class="info-label">Pet Experience</span>
                            <div class="text-area-content">
                                <?php echo nl2br(htmlspecialchars($request['PetExperience'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Adoption Reason -->
                    <div class="info-item" style="margin-bottom: 15px;">
                        <span class="info-label">Adoption Reason</span>
                        <div class="text-area-content">
                            <?php echo nl2br(htmlspecialchars($request['AdoptionReason'])); ?>
                        </div>
                    </div>

                    <!-- Rejection Reason -->
                    <?php if ($request['Status'] === 'rejected' && !empty($request['RejectionReason'])): ?>
                        <div class="rejection-box">
                            <div class="rejection-title">Reason for Rejection:</div>
                            <div><?php echo nl2br(htmlspecialchars($request['RejectionReason'])); ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <a href="ownerRequests.php" class="btn btn-back">Back to Requests</a>

                        <?php if (strtolower($request['Status']) === 'pending'): ?>
                            <button class="btn btn-approve" onclick="openApproveModal()">Approve Request</button>
                            <button class="btn btn-reject" onclick="openRejectModal()">Reject Request</button>
                        <?php endif; ?>

                        <a href="mailto:<?php echo htmlspecialchars($request['AdopterEmail']); ?>?cc=<?php echo htmlspecialchars($request['OwnerEmail']); ?>"
                            class="btn btn-contact-both">
                            📧 Contact Both Parties
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

        // Close modals when clicking outside
        window.onclick = function(event) {
            const approveModal = document.getElementById('approveModal');
            const rejectModal = document.getElementById('rejectModal');

            if (event.target == approveModal) closeApproveModal();
            if (event.target == rejectModal) closeRejectModal();
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