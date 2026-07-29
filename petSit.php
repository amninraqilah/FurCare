<?php
session_start();
include 'connect.php';

// Initialize variables
$success = false;
$error = '';
$hasPhoneNumber = false;
$pet = null;
$existing_request = null;
$user = null;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];
$petID = $_GET['pet_id'] ?? 0;

// Fetch user details and check phone number
if (!empty($userID)) {
    $user_stmt = $conn->prepare("SELECT * FROM user WHERE UserID = ?");
    if ($user_stmt) {
        $user_stmt->bind_param("i", $userID);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_result && $user_result->num_rows > 0) {
            $user = $user_result->fetch_assoc();
            // Check if user has phone number
            $hasPhoneNumber = !empty($user['Phone']) && trim($user['Phone']) !== '';
        } else {
            // User not found, redirect to login
            header("Location: login.php");
            exit;
        }
        $user_stmt->close();
    }
}

// Fetch pet details with owner info
if (!empty($petID)) {
    $stmt = $conn->prepare("SELECT p.*, u.Name as OwnerName, u.UserID as OwnerID
                           FROM pet p 
                           LEFT JOIN user u ON p.OwnerID = u.UserID 
                           WHERE p.PetID = ? AND p.PostType = 'Pet Sit' AND p.ApprovalStatus = 'approved'");
    if ($stmt) {
        $stmt->bind_param("i", $petID);
        $stmt->execute();
        $pet_result = $stmt->get_result();
        if ($pet_result && $pet_result->num_rows > 0) {
            $pet = $pet_result->fetch_assoc();
        } else {
            header("Location: userDashboard.php?error=Pet not found or not available for pet sitting");
            exit;
        }
        $stmt->close();
    }
}

// Check if pet exists
if (!$pet) {
    header("Location: userDashboard.php?error=Pet not found or not available for pet sitting");
    exit;
}

// Check if pet is for pet sitting
if ($pet['PostType'] !== 'Pet Sit') {
    header("Location: petDetails.php?pet_id=" . $petID . "&error=This pet is not available for pet sitting");
    exit;
}

// Check if pet has available dates
if (empty($pet['SitStartDate']) || empty($pet['SitEndDate']) || $pet['SitStartDate'] == '0000-00-00' || $pet['SitEndDate'] == '0000-00-00') {
    header("Location: petDetails.php?pet_id=" . $petID . "&error=Pet sitting dates not available");
    exit;
}

// Check if user is trying to sit their own pet
if ($pet['OwnerID'] == $userID) {
    header("Location: petDetails.php?pet_id=" . $petID . "&error=You cannot sit your own pet");
    exit;
}

// Check if user already has pending pet sitting request for this pet
if (!empty($petID) && !empty($userID)) {
    $check_stmt = $conn->prepare("SELECT * FROM PetSitRequest WHERE PetID = ? AND SitterID = ? AND Status = 'pending'");
    if ($check_stmt) {
        $check_stmt->bind_param("ii", $petID, $userID);
        $check_stmt->execute();
        $request_result = $check_stmt->get_result();
        if ($request_result && $request_result->num_rows > 0) {
            $existing_request = $request_result->fetch_assoc();
        }
        $check_stmt->close();
    }
}

// Handle pet sitting form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existing_request && $pet && $user) {
    // Check phone number before processing form
    if (!$hasPhoneNumber) {
        $error = "Please add your phone number in your profile before submitting pet sitting request";
    } else {
        // Get form data
        $sitter_message = trim($_POST['sitter_message'] ?? '');

        // Validate required fields
        if (empty($sitter_message)) {
            $error = "Please write a message to the pet owner";
        } else {
            try {
                // Calculate duration and total amount
                $startDate = $pet['SitStartDate'];
                $endDate = $pet['SitEndDate'];
                $start = new DateTime($startDate);
                $end = new DateTime($endDate);
                $totalDays = $start->diff($end)->days + 1;
                $totalAmount = $totalDays * $pet['Price'];

                // Insert pet sitting request
                $stmt = $conn->prepare("INSERT IGNORE INTO PetSitRequest 
                                       (PetID, SitterID, OwnerID, Status, RequestDate, StartDate, EndDate, 
                                        TotalDays, DailyRate, TotalAmount, SitterMessage) 
                                       VALUES (?, ?, ?, 'pending', NOW(), ?, ?, ?, ?, ?, ?)");

                if ($stmt) {
                    $bind_result = $stmt->bind_param(
                        "iiissiids",
                        $petID,
                        $userID,
                        $pet['OwnerID'],
                        $startDate,
                        $endDate,
                        $totalDays,
                        $pet['Price'],
                        $totalAmount,
                        $sitter_message
                    );

                    if ($bind_result && $stmt->execute()) {
                        if ($stmt->affected_rows > 0) {
                            $success = true;
                            $success_message = "Pet sitting request submitted successfully!";
                        } else {
                            $error = "No rows affected - possible duplicate entry";
                        }
                    } else {
                        $error = "Database error: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error = "Failed to prepare statement: " . $conn->error;
                }
            } catch (Exception $e) {
                $error = "System error: " . $e->getMessage();
            }
        }
    }
}

// Calculate days and amount for display
if ($pet) {
    $start = new DateTime($pet['SitStartDate']);
    $end = new DateTime($pet['SitEndDate']);
    $totalDays = $start->diff($end)->days + 1;
    $totalAmount = $totalDays * $pet['Price'];
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Pet Sit <?php echo isset($pet['Name']) ? htmlspecialchars($pet['Name']) : 'Pet'; ?> - FurCare</title>
    <link rel="stylesheet" href="css/userDashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3B7A57;
            --primary-dark: #2d6145;
            --secondary: #FFC107;
            --secondary-dark: #e0a800;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --light: #f8f9fa;
            --dark: #343a40;
            --border-radius: 12px;
            --box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }

        body {
            background: #FFF9F5;
            min-height: 100vh;
            font-family: 'Poppins', sans-serif;
            margin: 0;
        }

        .main-content {
            padding: 40px 0;
            min-height: calc(100vh - 70px);
        }

        .sit-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* ===== PAGE HEADER ===== */
        .page-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px;
            background: #FFF9F5;
        }

        .page-header h1 {
            font-size: 2.2rem;
            color: var(--primary);
            margin-bottom: 10px;
            font-weight: 700;
            position: relative;
            display: inline-block;
        }

        .page-header h1:after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            border-radius: 2px;
        }

        .page-header p {
            color: #666;
            font-size: 1.1rem;
            max-width: 700px;
            margin: 20px auto 0;
            line-height: 1.6;
        }

        /* ===== PET SUMMARY CARD (Left Column Style) ===== */
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

        .left-column {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .pet-card {
            background: white;
            padding: 25px;
            border-radius: var(--border-radius);
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
            font-size: 1.5rem;
            color: #333;
            margin: 0 0 15px 0;
            font-weight: 600;
        }

        .pet-card p {
            margin: 8px 0;
            color: #555;
            font-size: 0.95rem;
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

        /* ===== PET SITTING DETAILS SECTION ===== */
        .sitting-details {
            margin-top: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .sitting-details h4 {
            color: var(--primary);
            margin-bottom: 15px;
            font-size: 1.2rem;
        }

        .date-range {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }

        .date-item {
            flex: 1;
        }

        .date-label {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 5px;
        }

        .date-value {
            font-size: 1.1rem;
            color: #333;
            font-weight: 600;
        }

        .price-summary {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .price-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .price-item.total {
            border-top: 2px solid #e0e0e0;
            padding-top: 10px;
            margin-top: 10px;
            font-weight: 600;
            color: var(--primary);
            font-size: 1.1rem;
        }

        /* ===== WARNING BANNER ===== */
        .warning-banner {
            background: linear-gradient(135deg, #fff9e6 0%, #fff3cd 100%);
            border: 2px solid var(--warning);
            border-radius: var(--border-radius);
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(255, 193, 7, 0.15);
        }

        .warning-content {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .warning-icon {
            background: var(--warning);
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: #856404;
            flex-shrink: 0;
        }

        .warning-text {
            flex: 1;
        }

        .warning-text strong {
            color: #856404;
            font-size: 1.2rem;
            display: block;
            margin-bottom: 8px;
        }

        .warning-text p {
            color: #856404;
            margin: 0;
            opacity: 0.9;
            line-height: 1.5;
        }

        .btn-warning {
            background: var(--warning);
            color: #856404;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-family: 'Poppins', sans-serif;
            font-weight: 400;
            font-size: smaller;
            border: 2px solid var(--warning);
            transition: var(--transition);
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-warning:hover {
            background: var(--secondary-dark);
            border-color: var(--secondary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);
        }

        /* ===== MESSAGE CONTAINER ===== */
        .message-container {
            margin-bottom: 30px;
        }

        .success-message,
        .error-message,
        .existing-request {
            padding: 25px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            box-shadow: var(--box-shadow);
            border: 1px solid #e9ecef;
        }

        .success-message {
            background: linear-gradient(135deg, #d4f5d4 0%, #c8e6c9 100%);
            color: #155724;
            border-left: 5px solid var(--success);
        }

        .error-message {
            background: linear-gradient(135deg, #ffe6e6 0%, #ffcdd2 100%);
            color: #721c24;
            border-left: 5px solid var(--danger);
        }

        .existing-request {
            background: linear-gradient(135deg, #fff9e6 0%, #fff3cd 100%);
            color: #856404;
            border-left: 5px solid var(--warning);
        }

        /* ===== INFO CARD STYLE FOR FORM (Right Column) ===== */
        .info-card {
            background: white;
            padding: 30px;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            height: fit-content;
        }

        .form-header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }

        .form-header h2 {
            color: var(--primary);
            font-size: 1.8rem;
            margin-bottom: 10px;
            font-weight: 600;
            position: relative;
            display: inline-block;
        }

        .form-header h2:after {
            content: '';
            position: absolute;
            bottom: -12px;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 3px;
            border-radius: 2px;
        }

        .form-header p {
            color: #666;
            font-size: 1.1rem;
            margin-top: 10px;
        }

        /* ===== FORM STYLES ===== */
        .sit-form {
            display: grid;
            gap: 25px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #444;
            font-size: 1rem;
        }

        .form-group label.required:after {
            content: " *";
            color: var(--danger);
        }

        .form-group textarea {
            width: 90%;
            padding: 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            font-family: 'Poppins', sans-serif;
            transition: var(--transition);
            background: #fff;
            min-height: 150px;
            resize: vertical;
        }

        .form-group textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 122, 87, 0.1);
        }

        /* ===== FORM HELP TEXT ===== */
        .form-help {
            display: block;
            margin-top: 8px;
            font-size: 0.875rem;
            color: #666;
        }

        .form-help.error {
            color: var(--danger);
        }

        /* ===== BUTTON CONTAINER ===== */
        .form-submit {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
        }

        .button-container {
            display: flex;
            gap: 15px;
            justify-content: center;
            align-items: stretch;
            margin-bottom: 20px;
            width: 100%;
        }

        /* STYLE UNTUK KEDUA-DUA BUTTON SAMA */
        .btn-submit,
        .btn-back {
            flex: 1;
            min-width: 200px;
            max-width: 300px;
            padding: 14px 24px;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-weight: 400;
            font-size: smaller;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            text-align: center;
            box-sizing: border-box;
            border: none;
            height: auto;
            line-height: normal;
            white-space: nowrap;
        }

        /* SUBMIT BUTTON – Soft Blue */
        .btn-submit {
            background: linear-gradient(135deg, #5fa8d3 0%, #4e97c8 100%);
            color: white;
            transition: 0.25s ease;
        }

        .btn-submit:hover:not(:disabled) {
            transform: translateY(-3px);
            opacity: 0.95;
        }

        .btn-submit:disabled {
            background: #c7c7c7;
            border-color: #b1b1b1;
            cursor: not-allowed;
            opacity: 0.7;
        }

        /* BACK BUTTON – Soft Grey */
        .btn-back {
            background: linear-gradient(135deg, #9099a2 0%, #7f8891 100%);
            color: white;
            transition: 0.25s ease;
        }

        .btn-back:hover {
            background: linear-gradient(135deg, #7f8891 0%, #9099a2 100%);
            transform: translateY(-3px);
            opacity: 0.95;
        }

        /* Untuk link button back */
        a.btn-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        /* ===== DISABLED FORM STYLES ===== */
        input:disabled,
        select:disabled,
        textarea:disabled {
            background-color: #f8f9fa;
            cursor: not-allowed;
            opacity: 0.7;
            border-color: #ddd;
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

        /* ===== RESPONSIVE DESIGN ===== */
        @media (max-width: 992px) {
            .details-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .pet-card,
            .info-card {
                padding: 20px;
            }

            .pet-image {
                height: 180px;
            }

            .warning-content {
                flex-direction: column;
                text-align: center;
            }

            .page-header h1 {
                font-size: 1.8rem;
            }

            .page-header {
                padding: 20px;
            }
        }

        @media (max-width: 768px) {
            .button-container {
                flex-direction: column;
                gap: 12px;
            }

            .btn-submit,
            .btn-back {
                width: 100%;
                min-width: 100%;
                max-width: 100%;
            }

            .date-range {
                flex-direction: column;
                gap: 10px;
            }
        }

        @media (max-width: 480px) {
            .sit-container {
                padding: 0 15px;
            }

            .btn-submit,
            .btn-back {
                padding: 12px 20px;
                font-size: 14px;
            }
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <div class="navbar">
        <h2 class="logo">FurCare</h2>
        <img src="<?php echo !empty($user['ProfilePicture']) ? htmlspecialchars($user['ProfilePicture']) : 'uploads/profile_icon.png'; ?>"
            alt="Profile" class="profile-icon">
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="sit-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1>Apply for Pet Sitting</h1>
                <p>Complete the pet sitting application form below. Please provide accurate information to increase your chances of approval.</p>
            </div>

            <!-- Phone Number Warning Banner -->
            <?php if (!$hasPhoneNumber): ?>
                <div class="warning-banner">
                    <div class="warning-content">
                        <div class="warning-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="warning-text">
                            <strong>Phone Number Required</strong>
                            <p>You need to add your phone number before you can submit pet sitting requests. This helps pet owners contact you about your application.</p>
                        </div>
                        <a href="userProfile.php" class="btn-warning">
                            Add Phone Number
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Message Container -->
            <div class="message-container">
                <?php if ($success): ?>
                    <div class="success-message">
                        <h3><i class="fas fa-check-circle"></i> Pet Sitting Request Submitted!</h3>
                        <p>Your pet sitting request for <strong><?php echo htmlspecialchars($pet['Name'] ?? 'Pet'); ?></strong> has been submitted successfully.</p>
                        <p>The pet owner will review your application and contact you soon.</p>
                        <div class="button-container" style="margin-top: 20px;">
                            <a href="userDashboard.php" class="btn-back">
                                Back to Dashboard
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($existing_request && !$success): ?>
                    <div class="existing-request">
                        <h3><i class="fas fa-clock"></i> Pet Sitting Request Pending</h3>
                        <p>You already have a pending pet sitting request for <strong><?php echo htmlspecialchars($pet['Name'] ?? 'Pet'); ?></strong>.</p>
                        <p>Please wait for the owner to review your application.</p>
                        <div class="button-container" style="margin-top: 20px;">
                            <a href="userDashboard.php" class="btn-back">
                                Back to Dashboard
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error) && !$success): ?>
                    <div class="error-message">
                        <h3><i class="fas fa-exclamation-circle"></i> Error</h3>
                        <p><?php echo htmlspecialchars($error); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($pet && !$existing_request && !$success): ?>
                <div class="details-grid">
                    <!-- Left Column - Pet Card -->
                    <div class="left-column">
                        <div class="pet-card">
                            <img src="<?php echo htmlspecialchars($pet['Image'] ?? 'uploads/default_pet.jpg'); ?>"
                                alt="<?php echo htmlspecialchars($pet['Name'] ?? 'Pet'); ?>"
                                class="pet-image">

                            <h3 class="pet-name"><?php echo htmlspecialchars($pet['Name'] ?? 'Pet'); ?></h3>

                            <p><strong>Type:</strong> <?php echo htmlspecialchars($pet['Type'] ?? 'Not specified'); ?></p>
                            <p><strong>Breed:</strong> <?php echo htmlspecialchars($pet['Breed'] ?? 'Not specified'); ?></p>
                            <p><strong>Age:</strong> <?php echo formatAge($pet['Age']); ?></p>
                            <p><strong>Gender:</strong> <?php echo htmlspecialchars($pet['Gender'] ?? 'Not specified'); ?></p>
                            <p><strong>Location:</strong> <?php echo htmlspecialchars(($pet['District'] ?? '') . ', ' . ($pet['State'] ?? '')); ?></p>
                            <p><strong>Owner:</strong> <?php echo htmlspecialchars($pet['OwnerName'] ?? 'Not specified'); ?></p>

                            <div class="description-section">
                                <strong>Description:</strong>
                                <p><?php echo nl2br(htmlspecialchars($pet['Description'] ?? 'No description available')); ?></p>
                            </div>

                            <!-- Pet Sitting Details -->
                            <div class="sitting-details">
                                <h4>Pet Sitting Details</h4>

                                <div class="date-range">
                                    <div class="date-item">
                                        <div class="date-label">Start Date</div>
                                        <div class="date-value">
                                            <?php echo date('d M Y', strtotime($pet['SitStartDate'])); ?>
                                        </div>
                                    </div>
                                    <div class="date-item">
                                        <div class="date-label">End Date</div>
                                        <div class="date-value">
                                            <?php echo date('d M Y', strtotime($pet['SitEndDate'])); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="date-item" style="margin-top: 10px;">
                                    <div class="date-label">Duration</div>
                                    <div class="date-value">
                                        <?php echo $totalDays; ?> day<?php echo $totalDays > 1 ? 's' : ''; ?>
                                    </div>
                                </div>

                                <div class="price-summary">
                                    <div class="price-item">
                                        <span>Daily Rate:</span>
                                        <span>RM <?php echo number_format($pet['Price'], 2); ?></span>
                                    </div>
                                    <div class="price-item">
                                        <span>Duration:</span>
                                        <span><?php echo $totalDays; ?> day<?php echo $totalDays > 1 ? 's' : ''; ?></span>
                                    </div>
                                    <div class="price-item total">
                                        <span>Total Amount:</span>
                                        <span>RM <?php echo number_format($totalAmount, 2); ?></span>
                                    </div>
                                </div>
                            </div>

                            <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #e0e0e0;">
                                <p><strong>Status:</strong> <span style="color: var(--primary); font-weight: bold;">Available for Pet Sitting</span></p>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Pet Sitting Form -->
                    <div class="info-card">
                        <div class="form-header">
                            <h2>Pet Sitting Application Form</h2>
                            <p>Please write a message to the pet owner explaining why you'd be a good fit *</p>
                        </div>

                        <form method="POST" id="petSitForm" class="sit-form">
                            <div class="form-group full-width">
                                <label class="required">Your Message to the Owner</label>
                                <textarea name="sitter_message"
                                    required
                                    placeholder="Tell the owner about your experience with pets, why you're interested in sitting this pet, and why you'd be a good sitter..."
                                    <?php echo !$hasPhoneNumber ? 'disabled' : ''; ?>><?php echo isset($_POST['sitter_message']) ? htmlspecialchars($_POST['sitter_message']) : ''; ?></textarea>
                                <span class="form-help">This message helps the owner understand why you'd be a good fit for their pet</span>
                                <?php if (!$hasPhoneNumber): ?>
                                    <span class="form-help error">
                                        <i class="fas fa-exclamation-circle"></i> Please add your phone number in your profile first
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Important Notes -->
                            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px;">
                                <h4 style="color: var(--primary); margin-bottom: 10px;"><i class="fas fa-info-circle"></i> Important Notes</h4>
                                <ul style="margin: 0; padding-left: 20px; color: #555;">
                                    <li>The pet sitting dates are fixed as shown above</li>
                                    <li>The owner will review your application and contact you</li>
                                    <li>Payment will be arranged directly with the owner</li>
                                    <li>Make sure you're available for the entire duration</li>
                                </ul>
                            </div>

                            <!-- Submit Section -->
                            <div class="form-submit">
                                <div class="button-container">
                                    <button type="submit" class="btn-submit"
                                        <?php echo !$hasPhoneNumber ? 'disabled' : ''; ?>
                                        id="submitBtn">
                                        <i class="fas fa-paper-plane"></i>
                                        <?php if ($hasPhoneNumber): ?>
                                            Submit Pet Sitting Request
                                        <?php else: ?>
                                            Add Phone Number First
                                        <?php endif; ?>
                                    </button>

                                    <a href="userDashboard.php" class="btn btn-back">
                                        Back to Browse Pets
                                    </a>
                                </div>

                                <?php if (!$hasPhoneNumber): ?>
                                    <div style="text-align: center; margin-top: 20px;">
                                        <a href="userProfile.php" class="btn-warning" style="display: inline-flex;">
                                            <i class="fas fa-phone"></i> Update Profile with Phone Number
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('petSitForm');
            const submitBtn = document.getElementById('submitBtn');

            if (form) {
                form.addEventListener('submit', function(e) {
                    const messageTextarea = document.querySelector('textarea[name="sitter_message"]');
                    const messageValue = messageTextarea.value.trim();

                    // Message validation
                    if (!messageValue) {
                        e.preventDefault();
                        showAlert('Please write a message to the pet owner', 'error');
                        messageTextarea.focus();
                        return false;
                    }

                    // Minimum length validation
                    if (messageValue.length < 20) {
                        e.preventDefault();
                        showAlert('Please write a more detailed message (at least 20 characters)', 'error');
                        messageTextarea.focus();
                        return false;
                    }

                    // Show loading state
                    if (submitBtn && !submitBtn.disabled) {
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                        submitBtn.disabled = true;
                    }
                });
            }

            function showAlert(message, type) {
                // Create alert element
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert ${type === 'error' ? 'alert-error' : 'alert-success'}`;
                alertDiv.innerHTML = `
                    <i class="fas fa-${type === 'error' ? 'exclamation-triangle' : 'check-circle'}"></i>
                    <span>${message}</span>
                `;

                // Insert at top of form
                const formHeader = document.querySelector('.form-header');
                if (formHeader) {
                    formHeader.parentNode.insertBefore(alertDiv, formHeader.nextSibling);

                    // Remove after 5 seconds
                    setTimeout(() => {
                        alertDiv.remove();
                    }, 5000);
                }
            }

            // Add focus effects to form fields
            const formInputs = document.querySelectorAll('.sit-form input, .sit-form select, .sit-form textarea');
            formInputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'translateY(-2px)';
                });

                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'translateY(0)';
                });
            });
        });
    </script>
</body>

</html>