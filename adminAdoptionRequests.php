<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $request_id = $_POST['request_id'] ?? 0;

    if ($request_id && $action) {
        // Start transaction
        mysqli_begin_transaction($conn);

        try {
            if ($action === 'approve') {
                // 1. Get pet ID from request
                $get_pet_stmt = $conn->prepare("SELECT PetID, AdopterID, OwnerID FROM AdoptionRequest WHERE RequestID = ?");
                $get_pet_stmt->bind_param("i", $request_id);
                $get_pet_stmt->execute();
                $pet_result = $get_pet_stmt->get_result();

                if ($pet_result->num_rows > 0) {
                    $pet_data = $pet_result->fetch_assoc();
                    $petID = $pet_data['PetID'];
                    $adopterID = $pet_data['AdopterID']; // Dapatkan AdopterID
                    $ownerID = $pet_data['OwnerID'];     // Dapatkan OwnerID

                    // 2. Approve the current request
                    $stmt = $conn->prepare("UPDATE AdoptionRequest SET Status = 'approved', RejectionReason = NULL WHERE RequestID = ?");
                    $stmt->bind_param("i", $request_id);
                    $stmt->execute();

                    // 3. Update pet status to adopted
                    $pet_stmt = $conn->prepare("UPDATE pet SET Status = 'Adopted' WHERE PetID = ?");
                    $pet_stmt->bind_param("i", $petID);
                    $pet_stmt->execute();

                    // 4. AUTO-REJECT OTHER PENDING REQUESTS FOR SAME PET
                    $auto_reject_stmt = $conn->prepare("
            UPDATE AdoptionRequest 
            SET 
                Status = 'rejected',
                RejectionReason = CONCAT('Automatically rejected: This pet has already been adopted under Request #', ?, '.'),
                UpdatedAt = CURRENT_TIMESTAMP
            WHERE 
                PetID = ? 
                AND RequestID != ?
                AND Status = 'pending'
        ");
                    $auto_reject_stmt->bind_param("iii", $request_id, $petID, $request_id);
                    $auto_reject_stmt->execute();

                    // TAMBAH: UPDATE TOTAL_TRANSACTIONS
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

                    // 5. Commit transaction
                    mysqli_commit($conn);

                    header("Location: adminAdoptionRequests.php?success=Adoption request approved successfully. Other pending requests for this pet have been automatically rejected.");
                    exit;
                } else {
                    throw new Exception("Request not found");
                }
            } elseif ($action === 'reject') {
                $rejection_reason = trim($_POST['rejection_reason'] ?? '');

                if (empty($rejection_reason)) {
                    throw new Exception("Rejection reason is required");
                }

                $stmt = $conn->prepare("UPDATE AdoptionRequest SET Status = 'rejected', RejectionReason = ? WHERE RequestID = ?");
                $stmt->bind_param("si", $rejection_reason, $request_id);
                $stmt->execute();

                mysqli_commit($conn);

                header("Location: adminAdoptionRequests.php?success=Adoption request rejected successfully");
                exit;
            }
        } catch (Exception $e) {
            // Rollback jika ada error
            mysqli_rollback($conn);
            header("Location: adminAdoptionRequests.php?error=" . urlencode($e->getMessage()));
            exit;
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$tab = $_GET['tab'] ?? 'all';

// Build query with filters
$where_conditions = ["1=1"];
$params = [];
$types = "";

if ($tab === 'pending_admin_approval') {
    // TETAPKAN: Hanya tunjuk adoption requests untuk haiwan yang dimiliki oleh admin
    $where_conditions[] = "ar.OwnerID = ?";
    $params[] = $userID;
    $types .= "i";
    $where_conditions[] = "ar.Status = 'pending'";
} elseif ($tab === 'owner') {
    // Show adoption requests for pets owned by admin
    $where_conditions[] = "ar.OwnerID = ?";
    $params[] = $userID;
    $types .= "i";

    // Apply status filter untuk tab 'owner'
    if ($status_filter !== 'all') {
        $where_conditions[] = "ar.Status = ?";
        $params[] = $status_filter;
        $types .= "s";
    }
} elseif ($status_filter !== 'all') {
    // Apply status filter hanya untuk tab 'all'
    $where_conditions[] = "ar.Status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($search)) {
    $where_conditions[] = "(p.Name LIKE ? OR u.Name LIKE ? OR owner.Name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$where_sql = implode(' AND ', $where_conditions);

// Get adoption requests
$query = "SELECT ar.*, 
          p.Name as PetName, p.Image as PetImage, p.Type as PetType, p.Breed as PetBreed,
          p.ApprovalStatus as PetApprovalStatus, p.OwnerID as PetOwnerID,
          u.Name as AdopterName, u.Email as AdopterEmail, u.Phone as AdopterPhone, u.Role as AdopterRole,
          owner.Name as OwnerName, owner.Email as OwnerEmail, owner.Phone as OwnerPhone, owner.Role as OwnerRole
          FROM AdoptionRequest ar
          JOIN pet p ON ar.PetID = p.PetID
          JOIN user u ON ar.AdopterID = u.UserID
          JOIN user owner ON ar.OwnerID = owner.UserID
          WHERE $where_sql
          ORDER BY ar.RequestDate DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$requests = $stmt->get_result();

// Get stats for dashboard - menggunakan prepared statements untuk security
$stats_params = [];
$stats_types = "";
$stats_where = "1=1";

// Apply search filter untuk stats juga
if (!empty($search)) {
    $stats_where .= " AND (p.Name LIKE ? OR u.Name LIKE ? OR owner.Name LIKE ?)";
    $search_param = "%$search%";
    $stats_params = [$search_param, $search_param, $search_param];
    $stats_types = "sss";
}

// Total requests dengan search filter
$total_query = "SELECT COUNT(*) as count FROM AdoptionRequest ar 
                JOIN pet p ON ar.PetID = p.PetID
                JOIN user u ON ar.AdopterID = u.UserID
                JOIN user owner ON ar.OwnerID = owner.UserID
                WHERE $stats_where";
$stmt_total = $conn->prepare($total_query);
if (!empty($stats_params)) {
    $stmt_total->bind_param($stats_types, ...$stats_params);
}
$stmt_total->execute();
$total_result = $stmt_total->get_result()->fetch_assoc();
$total_requests = $total_result['count'] ?? 0;

// Pending requests
$pending_query = "SELECT COUNT(*) as count FROM AdoptionRequest ar 
                  JOIN pet p ON ar.PetID = p.PetID
                  JOIN user u ON ar.AdopterID = u.UserID
                  JOIN user owner ON ar.OwnerID = owner.UserID
                  WHERE $stats_where AND ar.Status = 'pending'";
$stmt_pending = $conn->prepare($pending_query);
if (!empty($stats_params)) {
    $stmt_pending->bind_param($stats_types, ...$stats_params);
}
$stmt_pending->execute();
$pending_result = $stmt_pending->get_result()->fetch_assoc();
$pending_requests = $pending_result['count'] ?? 0;

// Approved requests
$approved_query = "SELECT COUNT(*) as count FROM AdoptionRequest ar 
                   JOIN pet p ON ar.PetID = p.PetID
                   JOIN user u ON ar.AdopterID = u.UserID
                   JOIN user owner ON ar.OwnerID = owner.UserID
                   WHERE $stats_where AND ar.Status = 'approved'";
$stmt_approved = $conn->prepare($approved_query);
if (!empty($stats_params)) {
    $stmt_approved->bind_param($stats_types, ...$stats_params);
}
$stmt_approved->execute();
$approved_result = $stmt_approved->get_result()->fetch_assoc();
$approved_requests = $approved_result['count'] ?? 0;

// Rejected requests
$rejected_query = "SELECT COUNT(*) as count FROM AdoptionRequest ar 
                   JOIN pet p ON ar.PetID = p.PetID
                   JOIN user u ON ar.AdopterID = u.UserID
                   JOIN user owner ON ar.OwnerID = owner.UserID
                   WHERE $stats_where AND ar.Status = 'rejected'";
$stmt_rejected = $conn->prepare($rejected_query);
if (!empty($stats_params)) {
    $stmt_rejected->bind_param($stats_types, ...$stats_params);
}
$stmt_rejected->execute();
$rejected_result = $stmt_rejected->get_result()->fetch_assoc();
$rejected_requests = $rejected_result['count'] ?? 0;

// Untuk stat adoption requests untuk admin sendiri (pets owned by admin)
$owner_where = "ar.OwnerID = ?";
$owner_params = [$userID];
$owner_types = "i";

if (!empty($search)) {
    $owner_where .= " AND (p.Name LIKE ? OR u.Name LIKE ? OR owner.Name LIKE ?)";
    $search_param = "%$search%";
    array_push($owner_params, $search_param, $search_param, $search_param);
    $owner_types .= "sss";
}

$owner_requests_query = "SELECT COUNT(*) as count FROM AdoptionRequest ar 
                         JOIN pet p ON ar.PetID = p.PetID
                         JOIN user u ON ar.AdopterID = u.UserID
                         JOIN user owner ON ar.OwnerID = owner.UserID
                         WHERE $owner_where";
$stmt_owner = $conn->prepare($owner_requests_query);
$stmt_owner->bind_param($owner_types, ...$owner_params);
$stmt_owner->execute();
$owner_result = $stmt_owner->get_result()->fetch_assoc();
$owner_requests = $owner_result['count'] ?? 0;

// Pending admin approval (admin's own pets that are pending)
$pending_admin_where = "ar.OwnerID = ? AND ar.Status = 'pending'";
$pending_admin_params = [$userID];
$pending_admin_types = "i";

if (!empty($search)) {
    $pending_admin_where .= " AND (p.Name LIKE ? OR u.Name LIKE ? OR owner.Name LIKE ?)";
    $search_param = "%$search%";
    array_push($pending_admin_params, $search_param, $search_param, $search_param);
    $pending_admin_types .= "sss";
}

$pending_admin_approval_query = "SELECT COUNT(*) as count FROM AdoptionRequest ar 
                                 JOIN pet p ON ar.PetID = p.PetID
                                 JOIN user u ON ar.AdopterID = u.UserID
                                 JOIN user owner ON ar.OwnerID = owner.UserID
                                 WHERE $pending_admin_where";
$stmt_pending_admin = $conn->prepare($pending_admin_approval_query);
$stmt_pending_admin->bind_param($pending_admin_types, ...$pending_admin_params);
$stmt_pending_admin->execute();
$pending_admin_result = $stmt_pending_admin->get_result()->fetch_assoc();
$pending_admin_approval = $pending_admin_result['count'] ?? 0;

// Error message handling
$error_message = '';
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
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
    <title>Manage Adoption Requests - FurCare</title>
    <link rel="stylesheet" href="css/adminDashboard.css">
    <style>
        .requests-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-title {
            color: #3B7A57;
            margin-bottom: 20px;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .stat-card.stat-owner {
            border-left: 4px solid #FF6F91;
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #3B7A57;
            margin-bottom: 5px;
        }

        .stat-card.stat-admin-pending .stat-number {
            color: #3B7A57;
        }

        .stat-card.stat-owner .stat-number {
            color: #FF6F91;
        }

        .stat-label {
            color: #666;
            font-size: 0.9em;
        }

        .tabs {
            display: flex;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab {
            padding: 15px 20px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            min-width: 120px;
            justify-content: center;
            text-align: center;
        }

        .tab:hover {
            background: #e9ecef;
        }

        .tab.active {
            border-bottom-color: #6DBE81;
            background: #f0f7ff;
            color: #6DBE81;
        }

        .tab.tab-owner.active {
            border-bottom-color: #6DBE81;
            color: #6DBE81;
        }

        .tab-badge {
            background: #6c757d;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
        }

        .tab.active .tab-badge {
            background: #6c757d;
            color: white;
        }

        .tab.tab-owner .tab-badge {
            background: #6c757d;
            ;
        }

        .tab.tab-owner.active .tab-badge {
            background: #6c757d;
        }

        .filters {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
        }

        .filter-group {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 15px;
            align-items: end;
        }

        .search-container input {
            width: 95%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
        }

        .filter-container select {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            background: white;
        }

        .requests-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }

        .pet-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
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

        .status-cancelled {
            background: #aca2a2a1;
            color: white;
        }

        .admin-approval-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7em;
            font-weight: 600;
            background: #3B7A57;
            color: white;
            margin-top: 4px;
            display: inline-block;
        }

        .owner-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7em;
            font-weight: 600;
            background: #FF6F91;
            color: white;
            margin-top: 4px;
            display: inline-block;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.75em;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
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

        .btn-view {
            background: #4299e1;
            color: white;
        }

        .btn-view:hover {
            background: #3182ce;
        }

        .btn-urgent {
            background: #e53e3e;
            color: white;
        }

        .btn-urgent:hover {
            background: #c53030;
        }

        .btn-owner-action {
            background: #FF6F91;
            color: white;
        }

        .btn-owner-action:hover {
            background: #EC407A;
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
        }

        .form-group {
            margin-bottom: 15px;
            width: 95%;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-group textarea {
            width: 100%;
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

        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }

        .no-requests {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .owner-note {
            background: #FFF9F5;
            padding: 10px 15px;
            border-radius: 8px;
            border-left: 4px solid #FF6F91;
            margin-bottom: 15px;
            font-size: 0.9em;
            color: #666;
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
        <a href="adminAdoptionRequests.php" class="active">📋 Adoption Request</a>
        <a href="adminPetSitRequests.php">🏠 Pet Sit Request</a>
        <a href="reports.php">📑 Reports</a>
        <a href="adminSetting.php">⚙️ Settings</a>
        <a href="logout.php" class="logout">🚪 Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="navbar">
            <h1>Manage Adoption Requests</h1>
            <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
                alt="Profile"
                class="profile-icon">
        </div>

        <div class="requests-container">
            <!-- Success Message -->
            <?php if (isset($_GET['success'])): ?>
                <div class="success-message">
                    ✅ <?php echo htmlspecialchars($_GET['success']); ?>
                </div>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if (!empty($error_message)): ?>
                <div class="error-message">
                    ❌ <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_requests; ?></div>
                    <div class="stat-label">Total Requests</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $pending_requests; ?></div>
                    <div class="stat-label">Pending Review</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $approved_requests; ?></div>
                    <div class="stat-label">Approved</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $rejected_requests; ?></div>
                    <div class="stat-label">Rejected</div>
                </div>
                <div class="stat-card stat-admin-pending">
                    <div class="stat-number"><?php echo $pending_admin_approval; ?></div>
                    <div class="stat-label">Admin Approval</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <div class="tab <?php echo $tab === 'all' ? 'active' : ''; ?>"
                    onclick="window.location.href='adminAdoptionRequests.php?tab=all&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>'">
                    All Requests
                    <span class="tab-badge"><?php echo $total_requests; ?></span>
                </div>
                <div class="tab tab-owner <?php echo $tab === 'owner' ? 'active' : ''; ?>"
                    onclick="window.location.href='adminAdoptionRequests.php?tab=owner&search=<?php echo urlencode($search); ?>'">
                    My Pets
                    <span class="tab-badge"><?php echo $owner_requests; ?></span>
                </div>
                <div class="tab <?php echo $tab === 'pending_admin_approval' ? 'active' : ''; ?>"
                    onclick="window.location.href='adminAdoptionRequests.php?tab=pending_admin_approval&search=<?php echo urlencode($search); ?>'">
                    Approval
                    <span class="tab-badge"><?php echo $pending_admin_approval; ?></span>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <form method="GET">
                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                    <div class="filter-group">
                        <div class="search-container">
                            <input type="text" name="search" placeholder="Search by pet name, adopter, or owner..."
                                value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <?php if ($tab === 'all'): ?>
                            <div class="filter-container">
                                <select name="status">
                                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                                    <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                    <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                        <?php elseif ($tab === 'owner'): ?>
                            <div class="filter-container">
                                <select name="status">
                                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                                    <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                    <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                        <?php else: ?>
                            <div class="filter-container">
                                <select disabled style="background: #f8f9fa; color: #666;">
                                    <option>Status: Pending Only</option>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="filter-actions">
                            <button type="submit" class="btn" style="background: #3B7A57; color: white; padding: 10px 20px;">Search</button>
                            <a href="adminAdoptionRequests.php?tab=<?php echo urlencode($tab); ?>" class="btn" style="background: #6c757d; color: white; padding: 10px 20px; text-decoration: none;">Clear</a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Requests Table -->
            <div class="requests-table">
                <?php if ($requests->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Request ID</th>
                                <th>Pet</th>
                                <th>Adopter</th>
                                <th>Owner</th>
                                <th>Request Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($request = $requests->fetch_assoc()):
                                $needs_admin_approval = ($request['PetApprovalStatus'] === 'pending' && $request['Status'] === 'pending');
                                $is_owned_by_admin = ($request['OwnerID'] == $userID);
                                $status_class = 'status-' . strtolower($request['Status']);
                            ?>
                                <tr>
                                    <td>#<?php echo $request['RequestID']; ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <img src="<?php echo htmlspecialchars($request['PetImage']); ?>"
                                                alt="<?php echo htmlspecialchars($request['PetName']); ?>"
                                                class="pet-image">
                                            <div>
                                                <strong><?php echo htmlspecialchars($request['PetName']); ?></strong>
                                                <div style="font-size: 0.8em; color: #666;">
                                                    <?php echo htmlspecialchars($request['PetType']); ?> • <?php echo htmlspecialchars($request['PetBreed']); ?>
                                                </div>
                                                <?php if ($needs_admin_approval): ?>
                                                    <div class="admin-approval-badge">Needs Pet Approval</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($request['AdopterName']); ?></strong>
                                        <div style="font-size: 0.8em; color: #666;">
                                            <?php echo htmlspecialchars($request['AdopterEmail']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($request['OwnerName']); ?></strong>
                                        <div style="font-size: 0.8em; color: #666;">
                                            <?php echo htmlspecialchars($request['OwnerEmail']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($request['RequestDate'])); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo ucfirst($request['Status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="adminAdoptionRequestDetails.php?request_id=<?php echo $request['RequestID']; ?>"
                                                class="btn btn-view">View</a>

                                            <?php if ($request['Status'] === 'pending'): ?>
                                                <?php if ($needs_admin_approval && !$is_owned_by_admin): ?>
                                                    <a href="adminAdoptionRequestDetails.php?request_id=<?php echo $request['RequestID']; ?>&needs_approval=1"
                                                        class="btn btn-urgent">⚠️ Review</a>
                                                <?php elseif ($is_owned_by_admin): ?>
                                                    <!-- Owner actions for their own pets -->
                                                    <button class="btn btn-owner-action"
                                                        onclick="openOwnerApproveModal(<?php echo $request['RequestID']; ?>)">
                                                        Approve as Owner
                                                    </button>
                                                    <button class="btn btn-reject"
                                                        onclick="openOwnerRejectModal(<?php echo $request['RequestID']; ?>)">
                                                        Reject as Owner
                                                    </button>
                                                <?php else: ?>
                                                    <!-- Direct approval form -->
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="request_id" value="<?php echo $request['RequestID']; ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" class="btn btn-approve"
                                                            onclick="return confirm('Are you sure you want to approve request #<?php echo $request['RequestID']; ?>?\n\nThis will: \n✅ Approve this request\n✅ Mark pet as adopted\n❌ Auto-reject other pending requests for this pet')">
                                                            Approve
                                                        </button>
                                                    </form>
                                                    <!-- Reject with modal -->
                                                    <button class="btn btn-reject"
                                                        onclick="openRejectModal(<?php echo $request['RequestID']; ?>)">
                                                        Reject
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-requests">
                        <h3>No Adoption Requests Found</h3>
                        <p>
                            <?php if ($tab === 'pending_admin_approval'): ?>
                                There are no pending adoption requests for <strong>your own pets</strong> at the moment.
                            <?php elseif ($tab === 'owner'): ?>
                                You don't have any adoption requests for your pets.
                                <br>
                                <small style="color: #888; margin-top: 10px; display: block;">
                                    This tab shows adoption requests for pets that you own.
                                </small>
                            <?php else: ?>
                                No adoption requests match your search criteria.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reject Adoption Request</h3>
                <span class="close" onclick="closeRejectModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="request_id" id="rejectRequestID">
                <input type="hidden" name="action" value="reject">
                <div class="form-group">
                    <label>Reason for Rejection (Required):</label>
                    <textarea name="rejection_reason" placeholder="Please provide a reason for rejecting this adoption request..." required></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn" style="background: #6c757d; color: white;" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn btn-reject">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Owner Approve Modal -->
    <div id="ownerApproveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="color: #FF6F91;">Approve as Pet Owner</h3>
                <span class="close" onclick="closeOwnerApproveModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="request_id" id="ownerApproveRequestID">
                <input type="hidden" name="action" value="approve">
                <div class="form-group">
                    <label>Owner Notes (Optional):</label>
                    <textarea name="admin_notes" placeholder="Add any notes for the adopter..."></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn" style="background: #6c757d; color: white;" onclick="closeOwnerApproveModal()">Cancel</button>
                    <button type="submit" class="btn btn-owner-action">Approve as Owner</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Owner Reject Modal -->
    <div id="ownerRejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="color: #FF6F91;">Reject as Pet Owner</h3>
                <span class="close" onclick="closeOwnerRejectModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="request_id" id="ownerRejectRequestID">
                <input type="hidden" name="action" value="reject">
                <div class="form-group">
                    <label>Reason for Rejection (Required):</label>
                    <textarea name="rejection_reason" placeholder="Please provide a reason for rejecting this adoption request..." required></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn" style="background: #6c757d; color: white;" onclick="closeOwnerRejectModal()">Cancel</button>
                    <button type="submit" class="btn btn-reject">Reject as Owner</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openRejectModal(requestID) {
            console.log("Opening reject modal for request:", requestID);
            document.getElementById('rejectRequestID').value = requestID;
            document.getElementById('rejectModal').style.display = 'block';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }

        function openOwnerApproveModal(requestID) {
            console.log("Opening owner approve modal for request:", requestID);
            document.getElementById('ownerApproveRequestID').value = requestID;
            document.getElementById('ownerApproveModal').style.display = 'block';
        }

        function closeOwnerApproveModal() {
            document.getElementById('ownerApproveModal').style.display = 'none';
        }

        function openOwnerRejectModal(requestID) {
            console.log("Opening owner reject modal for request:", requestID);
            document.getElementById('ownerRejectRequestID').value = requestID;
            document.getElementById('ownerRejectModal').style.display = 'block';
        }

        function closeOwnerRejectModal() {
            document.getElementById('ownerRejectModal').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['rejectModal', 'ownerApproveModal', 'ownerRejectModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target == modal) {
                    if (modalId === 'rejectModal') closeRejectModal();
                    if (modalId === 'ownerApproveModal') closeOwnerApproveModal();
                    if (modalId === 'ownerRejectModal') closeOwnerRejectModal();
                }
            });
        }

        // Auto-hide success message after 5 seconds
        setTimeout(() => {
            const successMsg = document.querySelector('.success-message');
            if (successMsg) successMsg.style.display = 'none';
        }, 5000);
    </script>
</body>

</html>