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

// Fetch pet details
if (!empty($petID)) {
    $stmt = $conn->prepare("SELECT p.*, u.Name as OwnerName, u.UserID as OwnerID
                           FROM pet p 
                           LEFT JOIN user u ON p.OwnerID = u.UserID 
                           WHERE p.PetID = ? AND p.Status = 'Available' AND p.ApprovalStatus = 'approved'");
    if ($stmt) {
        $stmt->bind_param("i", $petID);
        $stmt->execute();
        $pet_result = $stmt->get_result();
        if ($pet_result && $pet_result->num_rows > 0) {
            $pet = $pet_result->fetch_assoc();
        } else {
            header("Location: userDashboard.php?error=Pet not found or not available for adoption");
            exit;
        }
        $stmt->close();
    }
}

// Check if pet exists
if (!$pet) {
    header("Location: userDashboard.php?error=Pet not found or not available for adoption");
    exit;
}

// Check if pet is for adoption
if ($pet['PostType'] !== 'Adopt') {
    header("Location: petDetails.php?pet_id=" . $petID . "&error=This pet is not available for adoption");
    exit;
}

// Check if user is trying to adopt their own pet
if ($pet['OwnerID'] == $userID) {
    header("Location: petDetails.php?pet_id=" . $petID . "&error=You cannot adopt your own pet");
    exit;
}

// Check if user already has pending adoption request for this pet
if (!empty($petID) && !empty($userID)) {
    $check_stmt = $conn->prepare("SELECT * FROM AdoptionRequest WHERE PetID = ? AND AdopterID = ? AND Status = 'pending'");
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

// Handle adoption form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existing_request && $pet && $user) {

    // Check phone number before processing form
    if (!$hasPhoneNumber) {
        $error = "Please add your phone number in your profile before submitting adoption request";
    } else {
        // Get form data dengan default values
        $adopter_name = trim($_POST['adopter_name'] ?? '');
        $adopter_phone = trim($_POST['adopter_phone'] ?? '');
        $adopter_address = trim($_POST['adopter_address'] ?? '');
        $housing_type = $_POST['housing_type'] ?? '';
        $family_members = intval($_POST['family_members'] ?? 1);
        $has_children = $_POST['has_children'] ?? 'no';
        $other_pets = $_POST['other_pets'] ?? 'no';
        $pet_experience = trim($_POST['pet_experience'] ?? '');
        $adoption_reason = trim($_POST['adoption_reason'] ?? '');

        // Validate semua required fields
        $required_fields = [
            'adopter_name' => 'Full Name',
            'adopter_phone' => 'Phone Number',
            'adopter_address' => 'Address',
            'housing_type' => 'Housing Type',
            'adoption_reason' => 'Adoption Reason'
        ];

        $missing_fields = [];
        foreach ($required_fields as $field => $label) {
            if (empty($$field)) {
                $missing_fields[] = $label;
            }
        }

        if (!empty($missing_fields)) {
            $error = "Please fill in the following required fields: " . implode(', ', $missing_fields);
        } else {
            try {
                // Gunakan INSERT IGNORE untuk avoid duplicates
                $stmt = $conn->prepare("INSERT IGNORE INTO AdoptionRequest 
                                       (PetID, AdopterID, OwnerID, AdopterName, AdopterPhone, AdopterAddress, 
                                        HousingType, FamilyMembers, HasChildren, OtherPets, PetExperience, AdoptionReason, Status) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");

                if ($stmt) {
                    $bind_result = $stmt->bind_param(
                        "iiissssissss",
                        $petID,
                        $userID,
                        $pet['OwnerID'],
                        $adopter_name,
                        $adopter_phone,
                        $adopter_address,
                        $housing_type,
                        $family_members,
                        $has_children,
                        $other_pets,
                        $pet_experience,
                        $adoption_reason
                    );

                    if ($bind_result && $stmt->execute()) {
                        if ($stmt->affected_rows > 0) {
                            $success = true;
                            $success_message = "Adoption request submitted successfully!";
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
    <title>Adopt <?php echo isset($pet['Name']) ? htmlspecialchars($pet['Name']) : 'Pet'; ?> - FurCare</title>
    <link rel="stylesheet" href="css/userDashboard.css">
    <link rel="stylesheet" href="css/adoptionRequestDetails.css">
    <link rel="stylesheet" href="css/sitterPetSitRequestDetails.css">
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

        .adopt-container {
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
        .adopt-form {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
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

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 90%;
            padding: 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            font-family: 'Poppins', sans-serif;
            transition: var(--transition);
            background: #fff;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 122, 87, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
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

        /* ===== FORM SECTIONS ===== */
        .form-section {
            grid-column: 1 / -1;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
        }

        .form-section h3 {
            color: var(--primary);
            font-size: 1.3rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }

        .form-section h3 i {
            color: var(--primary);
        }

        /* ===== BUTTON CONTAINER - PERBAIKAN UTAMA ===== */
        .form-submit {
            grid-column: 1 / -1;
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

            .adopt-form {
                grid-template-columns: 1fr;
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
        }

        @media (max-width: 480px) {
            .adopt-container {
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
        <div class="adopt-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1>Adopt a Pet</h1>
                <p>Complete the adoption application form below. Please provide accurate information to increase your chances of approval.</p>
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
                            <p>You need to add your phone number before you can submit adoption requests. This helps pet owners contact you about your application.</p>
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
                        <h3><i class="fas fa-check-circle"></i> Adoption Request Submitted!</h3>
                        <p>Your adoption request for <strong><?php echo htmlspecialchars($pet['Name'] ?? 'Pet'); ?></strong> has been submitted successfully.</p>
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
                        <h3><i class="fas fa-clock"></i> Adoption Request Pending</h3>
                        <p>You already have a pending adoption request for <strong><?php echo htmlspecialchars($pet['Name'] ?? 'Pet'); ?></strong>.</p>
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

                            <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #e0e0e0;">
                                <p><strong>Status:</strong> <span style="color: var(--success); font-weight: bold;">Available for Adoption</span></p>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Adoption Form -->
                    <div class="info-card">
                        <div class="form-header">
                            <h2>Adoption Application Form</h2>
                            <p>Please fill out all required fields (*) to complete your adoption application</p>
                        </div>

                        <form method="POST" id="adoptionForm" class="adopt-form">
                            <!-- Personal Information Section -->
                            <div class="form-section">
                                <h3><i class="fas fa-user-circle"></i> Personal Information</h3>
                            </div>

                            <div class="form-group">
                                <label class="required">Full Name</label>
                                <input type="text" name="adopter_name"
                                    value="<?php echo htmlspecialchars($user['Name'] ?? ''); ?>"
                                    required
                                    placeholder="Enter your full name">
                            </div>

                            <div class="form-group">
                                <label class="required">Phone Number</label>
                                <input type="tel" name="adopter_phone"
                                    value="<?php echo htmlspecialchars($user['Phone'] ?? ''); ?>"
                                    required
                                    placeholder="e.g., +60 12-345 6789"
                                    <?php echo !$hasPhoneNumber ? 'readonly' : ''; ?>>
                                <?php if (!$hasPhoneNumber): ?>
                                    <span class="form-help error">
                                        <i class="fas fa-exclamation-circle"></i> Please add your phone number in your profile first
                                    </span>
                                <?php else: ?>
                                <?php endif; ?>
                            </div>

                            <div class="form-group full-width">
                                <label class="required">Address</label>
                                <textarea name="adopter_address"
                                    required
                                    placeholder="Enter your complete address including postal code"><?php echo htmlspecialchars($user['Address'] ?? ''); ?></textarea>
                            </div>

                            <!-- Living Situation Section -->
                            <div class="form-section">
                                <h3><i class="fas fa-home"></i> Living Situation</h3>
                            </div>

                            <div class="form-group">
                                <label class="required">Housing Type</label>
                                <select name="housing_type" required <?php echo !$hasPhoneNumber ? 'disabled' : ''; ?>>
                                    <option value="">Select housing type</option>
                                    <option value="rented" <?php echo (isset($_POST['housing_type']) && $_POST['housing_type'] == 'rented') ? 'selected' : ''; ?>>Rented House</option>
                                    <option value="owned" <?php echo (isset($_POST['housing_type']) && $_POST['housing_type'] == 'owned') ? 'selected' : ''; ?>>Owned House</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="required">Family Members</label>
                                <input type="number" name="family_members"
                                    value="<?php echo isset($_POST['family_members']) ? htmlspecialchars($_POST['family_members']) : '1'; ?>"
                                    min="1"
                                    max="20"
                                    required
                                    placeholder="Number of people"
                                    <?php echo !$hasPhoneNumber ? 'disabled' : ''; ?>>
                            </div>

                            <div class="form-group">
                                <label class="required">Children in Home</label>
                                <select name="has_children" required <?php echo !$hasPhoneNumber ? 'disabled' : ''; ?>>
                                    <option value="no" <?php echo (isset($_POST['has_children']) && $_POST['has_children'] == 'no') ? 'selected' : ''; ?>>No Children</option>
                                    <option value="yes" <?php echo (isset($_POST['has_children']) && $_POST['has_children'] == 'yes') ? 'selected' : ''; ?>>Yes, Have Children</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="required">Other Pets</label>
                                <select name="other_pets" required <?php echo !$hasPhoneNumber ? 'disabled' : ''; ?>>
                                    <option value="no" <?php echo (isset($_POST['other_pets']) && $_POST['other_pets'] == 'no') ? 'selected' : ''; ?>>No Other Pets</option>
                                    <option value="yes" <?php echo (isset($_POST['other_pets']) && $_POST['other_pets'] == 'yes') ? 'selected' : ''; ?>>Yes, Have Other Pets</option>
                                </select>
                            </div>

                            <!-- Experience Section -->
                            <div class="form-section">
                                <h3><i class="fas fa-star"></i> Experience & Motivation</h3>
                            </div>

                            <div class="form-group full-width">
                                <label>Pet Experience</label>
                                <textarea name="pet_experience"
                                    placeholder="Tell us about your experience with pets, training experience, and any relevant background..."
                                    <?php echo !$hasPhoneNumber ? 'disabled' : ''; ?>></textarea>
                                <span class="form-help">Optional: Describe your previous experience with pets</span>
                            </div>

                            <div class="form-group full-width">
                                <label class="required">Adoption Reason</label>
                                <textarea name="adoption_reason"
                                    required
                                    placeholder="Why do you want to adopt this particular pet? How will you care for them?"
                                    <?php echo !$hasPhoneNumber ? 'disabled' : ''; ?>></textarea>
                                <span class="form-help">This helps the owner understand your intentions</span>
                            </div>

                            <!-- Submit Section - DENGAN BUTTON SAMA LEBAR -->
                            <div class="form-submit">
                                <div class="button-container">
                                    <button type="submit" class="btn-submit"
                                        <?php echo !$hasPhoneNumber ? 'disabled' : ''; ?>
                                        id="submitBtn">
                                        <i class="fas fa-paper-plane"></i>
                                        <?php if ($hasPhoneNumber): ?>
                                            Submit Adoption Request
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
            const form = document.getElementById('adoptionForm');
            const submitBtn = document.getElementById('submitBtn');

            if (form) {
                form.addEventListener('submit', function(e) {
                    const phoneInput = document.querySelector('input[name="adopter_phone"]');
                    const phoneValue = phoneInput.value.trim();

                    // Phone validation
                    if (!phoneValue) {
                        e.preventDefault();
                        showAlert('Please enter your phone number', 'error');
                        phoneInput.focus();
                        return false;
                    }

                    // Basic phone format validation
                    const phoneRegex = /^[\d\s\-\+\(\)]{10,}$/;
                    if (!phoneRegex.test(phoneValue.replace(/\s/g, ''))) {
                        e.preventDefault();
                        showAlert('Please enter a valid phone number (minimum 10 digits)', 'error');
                        phoneInput.focus();
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
            const formInputs = document.querySelectorAll('.adopt-form input, .adopt-form select, .adopt-form textarea');
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