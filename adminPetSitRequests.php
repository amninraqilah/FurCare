<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];

// Determine active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'all';

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Build query based on active tab
$where_conditions = ["1=1"];
$params = [];
$types = "";

if ($active_tab === 'pending_admin_approval') {
    // TAB APPROVAL: Hanya untuk haiwan yang dimiliki oleh admin (OwnerID = admin) dan status pending
    $where_conditions[] = "psr.OwnerID = ?";
    $params[] = $userID;
    $types .= "i";
    $where_conditions[] = "psr.Status = 'pending'";
} elseif ($active_tab === 'owner') {
    // Tab untuk semua requests untuk pets yang dimiliki oleh admin (owner)
    $where_conditions[] = "psr.OwnerID = ?";
    $params[] = $userID;
    $types .= "i";
}

// Tambahkan filter search jika ada
if (!empty($search)) {
    $where_conditions[] = "(p.Name LIKE ? OR owner.Name LIKE ? OR sitter.Name LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

// Tambahkan filter status jika bukan 'all' dan bukan tab pending_admin_approval
if ($status_filter !== 'all' && $active_tab !== 'pending_admin_approval') {
    $where_conditions[] = "psr.Status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$where_sql = implode(' AND ', $where_conditions);

$sql = "SELECT psr.*, 
               p.Name as PetName, p.Image as PetImage, p.Type as PetType,
               owner.Name as OwnerName, sitter.Name as SitterName,
               p.District as PetDistrict, p.State as PetState,
               p.SitStartDate, p.SitEndDate,
               p.ApprovalStatus as PetApprovalStatus,
               psr.TotalDays, 
               p.Price as DailyRate,  -- AMBIL HARGA DARI TABLE PET
               (p.Price * psr.TotalDays) as TotalAmount  -- KIRA TOTAL AMOUNT
        FROM PetSitRequest psr
        JOIN pet p ON psr.PetID = p.PetID
        JOIN user owner ON psr.OwnerID = owner.UserID
        JOIN user sitter ON psr.SitterID = sitter.UserID
        WHERE $where_sql
        ORDER BY psr.RequestDate DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Count requests for each tab dengan filter yang sama
$count_all_sql = "SELECT COUNT(*) as count 
                  FROM PetSitRequest psr
                  JOIN pet p ON psr.PetID = p.PetID
                  JOIN user owner ON psr.OwnerID = owner.UserID
                  JOIN user sitter ON psr.SitterID = sitter.UserID
                  WHERE 1=1";

// Count untuk tab 'all' dengan filter
$count_all_params = [];
$count_all_types = "";
$count_all_conditions = ["1=1"];

if (!empty($search)) {
    $count_all_conditions[] = "(p.Name LIKE ? OR owner.Name LIKE ? OR sitter.Name LIKE ?)";
    $search_term = "%{$search}%";
    $count_all_params[] = $search_term;
    $count_all_params[] = $search_term;
    $count_all_params[] = $search_term;
    $count_all_types .= "sss";
}

if ($status_filter !== 'all') {
    $count_all_conditions[] = "psr.Status = ?";
    $count_all_params[] = $status_filter;
    $count_all_types .= "s";
}

$count_all_where = implode(' AND ', $count_all_conditions);
$count_all_sql .= " AND " . $count_all_where;

$count_all_stmt = $conn->prepare($count_all_sql);
if (!empty($count_all_params)) {
    $count_all_stmt->bind_param($count_all_types, ...$count_all_params);
}
$count_all_stmt->execute();
$count_all_result = $count_all_stmt->get_result();
$count_all = $count_all_result->fetch_assoc()['count'];
$count_all_stmt->close();

// Count untuk tab 'owner' (pets yang dimiliki oleh admin) dengan filter
$owner_count_conditions = ["OwnerID = ?"];
$owner_count_params = [$userID];
$owner_count_types = "i";

if (!empty($search)) {
    $owner_count_conditions[] = "EXISTS (
        SELECT 1 FROM PetSitRequest psr2
        JOIN pet p2 ON psr2.PetID = p2.PetID
        JOIN user owner2 ON psr2.OwnerID = owner2.UserID
        JOIN user sitter2 ON psr2.SitterID = sitter2.UserID
        WHERE psr2.SitRequestID = psr.SitRequestID
        AND (p2.Name LIKE ? OR owner2.Name LIKE ? OR sitter2.Name LIKE ?)
    )";
    $search_term = "%{$search}%";
    $owner_count_params[] = $search_term;
    $owner_count_params[] = $search_term;
    $owner_count_params[] = $search_term;
    $owner_count_types .= "sss";
}

if ($status_filter !== 'all') {
    $owner_count_conditions[] = "Status = ?";
    $owner_count_params[] = $status_filter;
    $owner_count_types .= "s";
}

$owner_count_where = implode(' AND ', $owner_count_conditions);
$owner_count_sql = "SELECT COUNT(*) as count FROM PetSitRequest psr WHERE " . $owner_count_where;

$owner_count_stmt = $conn->prepare($owner_count_sql);
if (!empty($owner_count_params)) {
    $owner_count_stmt->bind_param($owner_count_types, ...$owner_count_params);
}
$owner_count_stmt->execute();
$owner_count_result = $owner_count_stmt->get_result();
$count_owner = $owner_count_result->fetch_assoc()['count'];
$owner_count_stmt->close();

// Count untuk tab 'pending_admin_approval' (pending untuk haiwan admin)
$pending_admin_count_sql = "SELECT COUNT(*) as count FROM PetSitRequest WHERE OwnerID = ? AND Status = 'pending'";
$pending_admin_count_stmt = $conn->prepare($pending_admin_count_sql);
$pending_admin_count_stmt->bind_param("i", $userID);
$pending_admin_count_stmt->execute();
$pending_admin_count_result = $pending_admin_count_stmt->get_result();
$count_pending_admin = $pending_admin_count_result->fetch_assoc()['count'];
$pending_admin_count_stmt->close();

// Count requests by status untuk stats card
$count_sql = "SELECT Status, COUNT(*) as count FROM PetSitRequest GROUP BY Status";
$count_result = $conn->query($count_sql);
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'completed' => 0, 'overdue' => 0, 'cancelled' => 0];

while ($row = $count_result->fetch_assoc()) {
    if (isset($counts[$row['Status']])) {
        $counts[$row['Status']] = $row['count'];
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
    <title>Manage Pet Sit Requests - FurCare</title>
    <link rel="stylesheet" href="css/adminDashboard.css">
    <style>
        .requests-container {
            padding: 20px;
        }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 5px;
            color: #3B7A57;
        }

        /* TABS */
        .tabs {
            display: flex;
            background: white;
            border-radius: 8px;
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
        }

        .tab.tab-owner.active .tab-badge {
            background: #6c757d;
        }

        /* REQUESTS TABLE */
        .requests-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .table-header {
            display: grid;
            grid-template-columns: 100px 1fr 1fr 1fr 120px 120px 100px;
            gap: 15px;
            padding: 15px 20px;
            background: #f8f9fa;
            font-weight: bold;
            border-bottom: 1px solid #e0e0e0;
        }

        .request-row {
            display: grid;
            grid-template-columns: 100px 1fr 1fr 1fr 120px 120px 100px;
            gap: 15px;
            padding: 15px 20px;
            border-bottom: 1px solid #f0f0f0;
            align-items: center;
        }

        .request-row:hover {
            background: #f8f9fa;
        }

        .pet-image-small {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 6px;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
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
            background: #48bb78e7;
            color: white;
        }

        .status-overdue {
            background: #f78a8aff;
            color: white;
        }

        .status-cancelled {
            background: #979fafca;
            color: white;
        }

        .admin-approval-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7em;
            font-weight: 600;
            background: #9f7aea;
            color: white;
            margin-top: 4px;
        }

        .owner-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7em;
            font-weight: 600;
            background: #FF6F91;
            color: white;
            margin-top: 4px;
        }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.8em;
            font-weight: 500;
            cursor: pointer;
            text-align: center;
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

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .tab-note {
            background: #FFF9F5;
            padding: 10px 15px;
            border-radius: 8px;
            border: 1px solid #fdc1cfff;
            margin-bottom: 15px;
            font-size: 0.9em;
            color: #666;
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

        @media (max-width: 768px) {

            .table-header,
            .request-row {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .tabs {
                flex-direction: column;
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
        <a href="adminPetSitRequests.php" class="active">🏠 Pet Sit Request</a>
        <a href="reports.php">📑 Reports</a>
        <a href="adminSetting.php">⚙️ Settings</a>
        <a href="logout.php" class="logout">🚪 Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="navbar">
            <h1>Manage Pet Sit Requests</h1>
            <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
                alt="Profile"
                class="profile-icon">
        </div>

        <div class="requests-container">
            <!-- Stats Cards - KEEPS ALL STATS CARDS -->
            <div class="stats-cards">
                <div class="stat-card stat-pending">
                    <div class="stat-number"><?php echo $counts['pending']; ?></div>
                    <div>Pending</div>
                </div>

                <div class="stat-card stat-approved">
                    <div class="stat-number"><?php echo $counts['approved']; ?></div>
                    <div>Approved</div>
                </div>

                <div class="stat-card stat-rejected">
                    <div class="stat-number"><?php echo $counts['rejected']; ?></div>
                    <div>Rejected</div>
                </div>

                <div class="stat-card stat-completed">
                    <div class="stat-number"><?php echo $counts['completed']; ?></div>
                    <div>Completed</div>
                </div>

                <div class="stat-card stat-admin-pending">
                    <div class="stat-number"><?php echo $count_pending_admin; ?></div>
                    <div>My Pets Pending</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <div class="tab <?php echo $active_tab === 'all' ? 'active' : ''; ?>"
                    onclick="window.location.href='adminPetSitRequests.php?tab=all'">
                    All Requests
                    <span class="tab-badge"><?php echo $count_all; ?></span>
                </div>
                <div class="tab tab-owner <?php echo $active_tab === 'owner' ? 'active' : ''; ?>"
                    onclick="window.location.href='adminPetSitRequests.php?tab=owner'">
                    My Pets
                    <span class="tab-badge"><?php echo $count_owner; ?></span>
                </div>
                <div class="tab <?php echo $active_tab === 'pending_admin_approval' ? 'active' : ''; ?>"
                    onclick="window.location.href='adminPetSitRequests.php?tab=pending_admin_approval'">
                    My Pets Pending
                    <span class="tab-badge"><?php echo $count_pending_admin; ?></span>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <form method="GET">
                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                    <div class="filter-group">
                        <div class="search-container">
                            <input type="text" name="search" placeholder="Search by pet name, adopter, or owner..."
                                value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <?php if ($active_tab === 'all'): ?>
                            <div class="filter-container">
                                <select name="status">
                                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                                    <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                    <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                        <?php elseif ($active_tab === 'owner'): ?>
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
                            <a href="adminPetSitRequests.php?tab=<?php echo urlencode($active_tab); ?>" class="btn" style="background: #6c757d; color: white; padding: 10px 20px; text-decoration: none;">Clear</a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Requests Table -->
            <div class="requests-table">
                <div class="table-header">
                    <div>Pet</div>
                    <div>Pet Details</div>
                    <div>Owner</div>
                    <div>Sitter</div>
                    <div>Period</div>
                    <div>Amount</div>
                    <div>Actions</div>
                </div>

                <?php if ($result->num_rows > 0): ?>
                    <?php while ($request = $result->fetch_assoc()):
                        $needs_admin_approval = ($request['PetApprovalStatus'] === 'pending' && $request['Status'] === 'pending');
                        $is_owned_by_admin = ($request['OwnerID'] == $userID);
                    ?>
                        <div class="request-row">
                            <div>
                                <img src="<?php echo htmlspecialchars($request['PetImage']); ?>"
                                    alt="<?php echo htmlspecialchars($request['PetName']); ?>"
                                    class="pet-image-small">
                            </div>

                            <div>
                                <strong><?php echo htmlspecialchars($request['PetName']); ?></strong>
                                <div style="font-size: 0.9em; color: #666;">
                                    <?php echo htmlspecialchars($request['PetType']); ?> •
                                    <?php echo htmlspecialchars($request['PetDistrict'] . ', ' . $request['PetState']); ?>
                                </div>
                                <?php if ($needs_admin_approval && $is_owned_by_admin): ?>
                                    <div class="admin-approval-badge">Needs Your Approval</div>
                                <?php endif; ?>
                            </div>

                            <div><?php echo htmlspecialchars($request['OwnerName']); ?></div>
                            <div><?php echo htmlspecialchars($request['SitterName']); ?></div>

                            <div>
                                <?php
                                $start = !empty($request['SitStartDate']) ? date('M j', strtotime($request['SitStartDate'])) : '—';
                                $end = !empty($request['SitEndDate']) && $request['SitEndDate'] !== '0000-00-00'
                                    ? date('M j', strtotime($request['SitEndDate']))
                                    : 'Ongoing';
                                echo $start . ' - ' . $end;
                                ?>

                                <div style="font-size: 0.8em; color: #666;">
                                    <?php echo $request['TotalDays']; ?> days
                                </div>
                            </div>

                            <div>
                                <strong>RM<?php echo number_format($request['TotalAmount'], 2); ?></strong>
                                <div style="font-size: 0.8em; color: #666;">
                                    RM<?php echo number_format($request['DailyRate'], 2); ?>/day
                                </div>
                            </div>

                            <div style="display: flex; gap: 5px; flex-direction: column;">
                                <span class="status-badge status-<?php echo $request['Status']; ?>">
                                    <?php echo ucfirst($request['Status']); ?>
                                </span>
                                <?php if ($needs_admin_approval && $is_owned_by_admin): ?>
                                    <a href="adminPetSitRequestDetails.php?request_id=<?php echo $request['SitRequestID']; ?>&needs_approval=1"
                                        class="btn btn-urgent">
                                        ⚠️ Review
                                    </a>
                                <?php else: ?>
                                    <a href="adminPetSitRequestDetails.php?request_id=<?php echo $request['SitRequestID']; ?>"
                                        class="btn btn-view">
                                        View Details
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <h3>No Pet Sit Requests Found</h3>
                        <p>
                            <?php if ($active_tab === 'pending_admin_approval'): ?>
                                There are no pending pet sitting requests for <strong>pets that you own</strong> at the moment.
                            <?php elseif ($active_tab === 'owner'): ?>
                                You don't have any pet sitting requests for your pets.
                                <br>
                                <small style="color: #888; margin-top: 10px; display: block;">
                                    This tab shows all pet sitting requests for pets that you own.
                                </small>
                            <?php else: ?>
                                There are no pet sitting requests at the moment.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>