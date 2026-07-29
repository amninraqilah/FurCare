<?php
include 'connect.php';
session_start();

// TAMBAH INI: Define userID
$userID = $_SESSION['user_id'];

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$petID = $_GET['pet_id'] ?? $_GET['id'] ?? 0;
$currentUserId = $_SESSION['user_id'];

// Fetch pet data dengan owner info
$sql = "SELECT p.*, 
               u.Name as OwnerName, 
               u.Phone, 
               u.Email, 
               u.ProfilePicture as OwnerProfilePicture,
               u.UserID as OwnerUserID
        FROM pet p 
        LEFT JOIN user u ON p.OwnerID = u.UserID 
        WHERE p.PetID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $petID);
$stmt->execute();
$pet = $stmt->get_result()->fetch_assoc();

if (!$pet) {
    header("Location: adminBrowsePet.php?error=Pet not found");
    exit;
}

// Get current logged in user's profile picture for navbar
$currentUserPicture = 'uploads/profile_icon.png';

if ($currentUserId > 0) {
    $userSql = "SELECT ProfilePicture FROM user WHERE UserID = ?";
    $userStmt = $conn->prepare($userSql);
    $userStmt->bind_param("i", $currentUserId);
    $userStmt->execute();
    $userResult = $userStmt->get_result();

    if ($userRow = $userResult->fetch_assoc()) {
        if (!empty($userRow['ProfilePicture'])) {
            $currentUserPicture = $userRow['ProfilePicture'];
        }
    }
}

// Determine back URL based on referer
$backURL = 'adminBrowsePet.php'; // Default
$backText = 'Back to Browse';

// If there's a 'from' parameter, use it first
if (isset($_GET['from'])) {
    $from = $_GET['from'];

    if ($from === 'manage') {
        $backURL = 'adminManagePets.php';
        $backText = 'Back to Manage Pets';
    } elseif ($from === 'browse') {
        $backURL = 'adminBrowsePet.php';
        $backText = 'Back to Browse Pets';
    }
}
// If no 'from' parameter, check HTTP referer
elseif (isset($_SERVER['HTTP_REFERER'])) {
    $referer = $_SERVER['HTTP_REFERER'];

    // Parse referer to get the base URL without query parameters
    $refererPath = parse_url($referer, PHP_URL_PATH);
    $refererFile = basename($refererPath);

    // Map files to back URLs
    $fileMap = [
        'adminManagePets.php' => 'adminManagePets.php',
        'adminBrowsePet.php' => 'adminBrowsePet.php',
    ];

    if (isset($fileMap[$refererFile])) {
        $backURL = $fileMap[$refererFile];

        // Preserve query parameters from referer
        $refererQuery = parse_url($referer, PHP_URL_QUERY);
        if ($refererQuery) {
            $backURL .= '?' . $refererQuery;
        }
    }

    // Set back text based on file
    $textMap = [
        'adminManagePets.php' => 'Back to Manage Pets',
        'adminBrowsePet.php' => 'Back to Browse Pets'
    ];

    if (isset($textMap[$refererFile])) {
        $backText = $textMap[$refererFile];
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

// Fetch admin data untuk profile picture
$user_stmt = $conn->prepare("SELECT * FROM user WHERE UserID = ?");
$user_stmt->bind_param("i", $userID);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin - <?php echo htmlspecialchars($pet['Name']); ?> - FurCare</title>
    <link rel="stylesheet" href="css/adminDashboard.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #FFB6C1;
            /* Pastel pink */
            --primary-dark: #FF8BA0;
            --secondary: #B5EAD7;
            /* Pastel mint */
            --secondary-dark: #8CD9C5;
            --accent: #C7CEEA;
            /* Pastel lavender */
            --accent-dark: #A5B4E0;
            --success: #A8E6CF;
            /* Pastel green */
            --warning: #FFD3B6;
            /* Pastel peach */
            --danger: #FFAAA5;
            /* Pastel coral */
            --light: #FFF9F5;
            --dark: #6B7280;
            --border-radius: 10px;
            --box-shadow: 0 3px 12px rgba(0, 0, 0, 0.08);
            --transition: all 0.2s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #FFF9F5;
            display: flex;
            min-height: 100vh;
        }

        /* ===== PAGE CONTENT ===== */
        .page-content {
            padding: 25px 30px;
        }

        /* ===== PAGE HEADER ===== */
        .page-header {
            text-align: center;
            margin-bottom: 25px;
            padding: 20px;
        }

        .page-header h1 {
            color: #3B7A57;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 1.8rem;
        }

        .page-header p {
            color: #666;
            max-width: 700px;
            margin: 10px auto 0;
            line-height: 1.5;
            font-size: 1rem;
        }

        /* ===== DETAILS GRID ===== */
        .details-grid {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 992px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        .left-column {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* ===== PET CARD ===== */
        .pet-card {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border: 1px solid #f0f0f0;
            height: fit-content;
        }

        .pet-image {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid #e0e0e0;
        }

        .pet-name {
            font-size: 1.3rem;
            color: #333;
            margin: 0 0 12px 0;
            font-weight: 600;
        }

        .pet-card p {
            margin: 6px 0;
            color: #555;
            font-size: 0.9rem;
            display: flex;
            justify-content: space-between;
        }

        .pet-card p strong {
            color: #333;
            font-weight: 500;
            min-width: 70px;
        }

        /* ===== STATUS BADGES ===== */
        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 500;
            display: inline-block;
            margin-bottom: 15px;
            text-transform: capitalize;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .status-available {
            background: linear-gradient(135deg, #A8E6CF, #8CD9C5);
            color: #2D6A4F;
        }

        .status-pending {
            background: linear-gradient(135deg, #FFD3B6, #FFB8A0);
            color: #9A3412;
        }

        .status-adopted,
        .status-sitting,
        .status-completed {
            background: linear-gradient(135deg, #C7CEEA, #A5B4E0);
            color: #4C51BF;
        }

        .status-cancelled {
            background: linear-gradient(135deg, #E5E7EB, #D1D5DB);
            color: #6B7280;
        }

        .status-overdue {
            background: linear-gradient(135deg, #FFAAA5, #FF8B8B);
            color: #991B1B;
        }

        /* ===== POST TYPE BADGE ===== */
        .post-type-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .post-type-badge.adopt {
            background: linear-gradient(135deg, #B5EAD7, #95D9C3);
            color: #065F46;
        }

        .post-type-badge.pet-sit {
            background: linear-gradient(135deg, #FFB6C1, #FF8BA0);
            color: #9D174D;
        }

        /* ===== PET SIT DETAILS ===== */
        .pet-sit-details {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .pet-sit-details h4 {
            color: var(--primary-dark);
            margin-bottom: 12px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .date-range {
            display: flex;
            gap: 12px;
            margin-bottom: 12px;
        }

        .period-item {
            flex: 1;
            background: white;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .period-label {
            font-size: 0.85em;
            color: #666;
            margin-bottom: 6px;
            font-weight: 500;
        }

        .period-value {
            font-weight: 500;
            color: #2d3748;
            font-size: 1em;
        }

        .price-summary {
            background: white;
            padding: 12px;
            border-radius: 8px;
            margin-top: 12px;
        }

        .total-amount {
            font-size: 1.2em;
            font-weight: 500;
            color: var(--primary-dark);
            text-align: center;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 2px solid #e0e0e0;
        }

        /* ===== INFO CARD ===== */
        .info-card {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border: 1px solid #f0f0f0;
            height: fit-content;
        }

        /* ===== DESCRIPTION SECTION ===== */
        .description-section {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e0e0e0;
        }

        .description-section p {
            margin-top: 5px;
            color: #555;
            line-height: 1.5;
            white-space: pre-line;
            font-size: 0.9rem;
        }

        /* ===== ADMIN INFO SECTION ===== */
        .admin-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }

        .admin-info h4 {
            color: var(--primary-dark);
            margin-bottom: 12px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .admin-info p {
            margin: 6px 0;
            color: #555;
            font-size: 0.9rem;
            display: flex;
            justify-content: space-between;
        }

        .admin-info p strong {
            color: #333;
            font-weight: 500;
            min-width: 120px;
        }

        .approval-status {
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 500;
            display: inline-block;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .status-approved {
            background: #A8E6CF;
            color: #065F46;
        }

        .status-pending {
            background: #FFD3B6;
            color: #9A3412;
        }

        .status-rejected {
            background: #FFAAA5;
            color: #991B1B;
        }

        /* ===== ADMIN ACTIONS ===== */
        .admin-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 400;
            text-decoration: none;
            text-align: center;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.85rem;
            gap: 6px;
            min-width: 140px;
            font-family: Arial, sans-serif;
        }

        .btn.primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn.primary:hover:not(:disabled) {
            background: linear-gradient(135deg, var(--primary-dark) 0%, #FF7B9C 100%);
            transform: translateY(-2px);
        }

        .btn.secondary {
            background: linear-gradient(135deg, var(--secondary) 0%, var(--secondary-dark) 100%);
            color: #065F46;
        }

        .btn.secondary:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, #7ACCB4 100%);
            transform: translateY(-2px);
            color: #065F46;
        }

        .btn.warning {
            background: linear-gradient(135deg, var(--warning) 0%, #FFB347 100%);
            color: #9A3412;
        }

        .btn.warning:hover {
            background: linear-gradient(135deg, #FFB347 0%, #FF9A1F 100%);
            transform: translateY(-2px);
            color: #9A3412;
        }

        .btn.danger {
            background: linear-gradient(135deg, var(--danger) 0%, #FF6B6B 100%);
            color: #991B1B;
        }

        .btn.danger:hover {
            background: linear-gradient(135deg, #FF6B6B 0%, #FF5252 100%);
            transform: translateY(-2px);
            color: #991B1B;
        }

        .btn.outline {
            background: white;
            color: #4a5568;
            border: 1px solid #e2e8f0;
        }

        .btn.outline:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
            transform: translateY(-2px);
        }

        /* ===== LOCATION SECTION ===== */
        .location-section {
            margin: 15px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .location-section h3 {
            color: var(--primary-dark);
            margin-bottom: 10px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        #map {
            height: 200px;
            width: 100%;
            border-radius: 8px;
            margin-top: 8px;
            z-index: 1;
        }

        /* ===== OWNER INFO ===== */
        .owner-info {
            margin: 15px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .owner-info h3 {
            color: var(--primary-dark);
            margin-bottom: 10px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .owner-details {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .owner-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary);
        }

        .owner-details div p {
            margin: 4px 0;
            color: #555;
            font-size: 0.9rem;
        }

        .owner-details div p:first-child {
            font-size: 1rem;
            font-weight: 500;
            color: #333;
            margin-bottom: 6px;
        }

        /* ===== ACTION BUTTONS ===== */
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eaeaea;
        }

        /* ===== RESPONSIVE DESIGN ===== */
        @media (max-width: 992px) {
            .sidebar {
                width: 220px;
            }

            .main-content {
                margin-left: 220px;
            }

            .details-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .pet-card,
            .info-card {
                padding: 15px;
            }

            .pet-image {
                height: 160px;
            }

            .admin-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                padding: 20px 0;
            }

            .sidebar .logo {
                font-size: 0;
            }

            .sidebar .logo:before {
                content: "FC";
                font-size: 1.5em;
            }

            .sidebar a {
                text-align: center;
                padding: 15px 5px;
                font-size: 0.8rem;
            }

            .sidebar a span {
                display: block;
                margin-top: 5px;
            }

            .sidebar a i {
                font-size: 1.2em;
            }

            .main-content {
                margin-left: 70px;
            }

            .page-content {
                padding: 15px;
            }

            .navbar {
                padding: 12px 20px;
            }

            .navbar h1 {
                font-size: 1.2rem;
            }

            .page-header h1 {
                font-size: 1.5rem;
            }

            .pet-name {
                font-size: 1.2rem;
            }

            .owner-details {
                flex-direction: column;
                text-align: center;
                gap: 12px;
            }

            .owner-avatar {
                width: 60px;
                height: 60px;
            }

            .date-range {
                flex-direction: column;
                gap: 8px;
            }
        }

        @media (max-width: 480px) {
            .btn {
                padding: 8px 12px;
                font-size: 0.8rem;
                min-width: auto;
            }

            .profile-icon {
                width: 36px;
                height: 36px;
            }

            .admin-info p {
                flex-direction: column;
                gap: 2px;
            }

            .admin-info p strong {
                min-width: auto;
            }
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
        <a href="adminPetSitRequests.php">🏠 Pet Sit Request</a>
        <a href="reports.php">📑 Reports</a>
        <a href="adminSetting.php">⚙️ Settings</a>
        <a href="logout.php" class="logout">🚪 Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Navbar -->
        <div class="navbar">
            <h1>Pet Details - Admin View</h1>
            <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
                alt="Profile"
                class="profile-icon">
        </div>

        <!-- Page Content -->
        <div class="page-content">
            <!-- Page Header -->
            <div class="page-header">
                <h1>Pet Details</h1>
                <p>Admin view of <?php echo htmlspecialchars($pet['Name']); ?>'s information</p>
            </div>

            <div class="details-grid">
                <!-- Left Column - Pet Card -->
                <div class="left-column">
                    <div class="pet-card">
                        <img src="<?php echo htmlspecialchars($pet['Image']); ?>"
                            alt="<?php echo htmlspecialchars($pet['Name']); ?>"
                            class="pet-image">

                        <h3 class="pet-name"><?php echo htmlspecialchars($pet['Name']); ?></h3>

                        <!-- Post Type Badge -->
                        <div class="post-type-badge <?php echo strtolower(str_replace(' ', '-', $pet['PostType'])); ?>">
                            <?php echo htmlspecialchars($pet['PostType']); ?>
                            <?php if ($pet['PostType'] === 'Pet Sit' && !empty($pet['Price'])): ?>
                                • RM<?php echo number_format($pet['Price'], 2); ?>/day
                            <?php endif; ?>
                        </div>

                        <!-- Pet Status Badge -->
                        <div class="status-badge status-<?php echo strtolower($pet['Status']); ?>">
                            <i class="fas fa-paw"></i> Status: <?php echo htmlspecialchars($pet['Status']); ?>
                        </div>

                        <p><strong>Type:</strong> <?php echo htmlspecialchars($pet['Type']); ?></p>
                        <p><strong>Breed:</strong> <?php echo htmlspecialchars($pet['Breed']); ?></p>
                        <p><strong>Age:</strong> <?php echo formatAge($pet['Age']); ?></p>
                        <p><strong>Gender:</strong> <?php echo htmlspecialchars($pet['Gender']); ?></p>
                        <p><strong>Location:</strong> <?php echo htmlspecialchars(($pet['District'] ?? '') . ', ' . ($pet['State'] ?? '')); ?></p>
                        <p><strong>Owner:</strong> <?php echo htmlspecialchars($pet['OwnerName'] ?? 'Not specified'); ?></p>

                        <!-- Pet Sit Details -->
                        <?php if ($pet['PostType'] === 'Pet Sit' && !empty($pet['SitStartDate']) && !empty($pet['SitEndDate'])): ?>
                            <?php
                            $startDate = new DateTime($pet['SitStartDate']);
                            $endDate = new DateTime($pet['SitEndDate']);
                            $totalDays = $startDate->diff($endDate)->days + 1;
                            $totalAmount = $totalDays * ($pet['Price'] ?? 0);
                            ?>
                            <div class="pet-sit-details">
                                <h4>Pet Sitting Details</h4>

                                <div class="date-range">
                                    <div class="period-item">
                                        <div class="period-label">Start Date</div>
                                        <div class="period-value"><?php echo date('M j, Y', strtotime($pet['SitStartDate'])); ?></div>
                                    </div>
                                    <div class="period-item">
                                        <div class="period-label">End Date</div>
                                        <div class="period-value"><?php echo date('M j, Y', strtotime($pet['SitEndDate'])); ?></div>
                                    </div>
                                </div>

                                <div class="price-summary">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; padding: 4px 0;">
                                        <span>Daily Rate:</span>
                                        <span style="font-weight: 500; color: var(--primary-dark);">RM<?php echo number_format($pet['Price'], 2); ?>/day</span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; padding: 4px 0;">
                                        <span>Duration:</span>
                                        <span style="font-weight: 500;"><?php echo $totalDays; ?> days</span>
                                    </div>
                                    <div class="total-amount">
                                        Total: RM<?php echo number_format($totalAmount, 2); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="description-section">
                            <strong>Description:</strong>
                            <p><?php echo nl2br(htmlspecialchars($pet['Description'] ?? 'No description available')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Info Card -->
                <div class="info-card">
                    <!-- Admin Information Section -->
                    <div class="admin-info">
                        <h4><i class="fas fa-user-shield"></i> Admin Information</h4>
                        <p><strong>Approval Status:</strong>
                            <span class="approval-status status-<?php echo strtolower($pet['ApprovalStatus']); ?>">
                                <?php echo htmlspecialchars($pet['ApprovalStatus']); ?>
                            </span>
                        </p>
                        <p><strong>Pet ID:</strong> <?php echo htmlspecialchars($pet['PetID']); ?></p>
                        <p><strong>Owner ID:</strong> <?php echo htmlspecialchars($pet['OwnerID']); ?></p>
                        <p><strong>Posted Date:</strong> <?php echo date('M j, Y g:i A', strtotime($pet['PostDate'])); ?></p>

                        <?php if (!empty($pet['ApprovedBy'])): ?>
                            <?php
                            $admin_stmt = $conn->prepare("SELECT Name FROM user WHERE UserID = ?");
                            $admin_stmt->bind_param("i", $pet['ApprovedBy']);
                            $admin_stmt->execute();
                            $admin_result = $admin_stmt->get_result();
                            $admin_name = $admin_result->fetch_assoc()['Name'] ?? 'Unknown Admin';
                            ?>
                            <p><strong>Approved By:</strong> <?php echo htmlspecialchars($admin_name); ?></p>
                        <?php endif; ?>

                        <?php if (!empty($pet['ApprovedAt'])): ?>
                            <p><strong>Approved At:</strong> <?php echo date('M j, Y g:i A', strtotime($pet['ApprovedAt'])); ?></p>
                        <?php endif; ?>

                        <?php if (!empty($pet['RejectionReason'])): ?>
                            <p><strong>Rejection Reason:</strong> <?php echo htmlspecialchars($pet['RejectionReason']); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Admin Actions -->
                    <div class="admin-actions">
                        <a href="editPet.php?id=<?php echo $pet['PetID']; ?>&from=admin" class="btn primary">
                            <i class="fas fa-edit"></i> Edit Pet
                        </a>
                    </div>

                    <!-- Location Section -->
                    <div class="location-section">
                        <h3><i class="fas fa-map-marker-alt"></i> Location</h3>
                        <p><?php echo htmlspecialchars($pet['District'] . ', ' . $pet['State']); ?></p>

                        <?php if (!empty($pet['Latitude']) && !empty($pet['Longitude'])): ?>
                            <div id="map"></div>
                        <?php else: ?>
                            <p style="background: #FFEBEE; padding: 10px; border-radius: 6px; color: #E74C3C; text-align: center; font-size: 0.85rem;">
                                <i class="fas fa-map-marked-alt"></i> Location map not available
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Owner Info -->
                    <div class="owner-info">
                        <h3><i class="fas fa-user"></i> Owner Information</h3>
                        <div class="owner-details">
                            <img src="<?php echo !empty($pet['OwnerProfilePicture']) ? htmlspecialchars($pet['OwnerProfilePicture']) : 'uploads/profile_icon.png'; ?>"
                                alt="<?php echo htmlspecialchars($pet['OwnerName']); ?>"
                                class="owner-avatar">
                            <div>
                                <p><strong><?php echo htmlspecialchars($pet['OwnerName']); ?></strong></p>
                                <?php if (!empty($pet['Phone'])): ?>
                                    <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($pet['Phone']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($pet['Email'])): ?>
                                    <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($pet['Email']); ?></p>
                                <?php endif; ?>
                                <p><strong>User ID:</strong> <?php echo htmlspecialchars($pet['OwnerUserID']); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <a href="<?php echo $backURL; ?>" class="btn outline">
                            <i class="fas fa-arrow-left"></i> <?php echo $backText; ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

    <script>
        // INITIALIZE MAP FUNCTION
        function initializeMap() {
            <?php if (!empty($pet['Latitude']) && !empty($pet['Longitude'])): ?>
                const petLat = <?php echo $pet['Latitude']; ?>;
                const petLng = <?php echo $pet['Longitude']; ?>;

                // Initialize map
                const map = L.map('map').setView([petLat, petLng], 13);

                // Add tile layer
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);

                // Custom icon
                const customIcon = L.divIcon({
                    html: '<div style="background: var(--primary); width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 14px; border: 2px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.2);">🐾</div>',
                    className: 'custom-marker',
                    iconSize: [28, 28],
                    iconAnchor: [14, 14]
                });

                // Add marker with custom icon
                L.marker([petLat, petLng], {
                        icon: customIcon
                    })
                    .addTo(map)
                    .bindPopup('<strong><?php echo htmlspecialchars($pet['Name']); ?></strong><br><?php echo htmlspecialchars($pet['District'] . ', ' . $pet['State']); ?>')
                    .openPopup();
            <?php endif; ?>
        }

        // INITIALIZE MAP WHEN PAGE LOADS
        document.addEventListener('DOMContentLoaded', function() {
            initializeMap();
        });
    </script>

</body>

</html>