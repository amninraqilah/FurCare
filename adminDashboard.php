<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  header("Location: login.php");
  exit;
}

include 'connect.php';
$userID = $_SESSION['user_id'];

// Fetch admin data untuk profile picture
$user_stmt = $conn->prepare("SELECT * FROM user WHERE UserID = ?");
$user_stmt->bind_param("i", $userID);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

// Set default jika tidak ada data
if (!$user) {
  $profilePicture = 'uploads/profile_icon.png';
} else {
  $profilePicture = !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png';
}

// 1. Total Users
$users_sql = "SELECT COUNT(*) as total_users FROM user";
$users_result = $conn->query($users_sql);
$total_users = $users_result->fetch_assoc()['total_users'];

// 2. Total Pets dengan status
$pets_sql = "SELECT 
              COUNT(*) as total_pets,
              SUM(CASE WHEN LOWER(TRIM(Status)) = 'available' AND ApprovalStatus = 'approved' THEN 1 ELSE 0 END) as available_pets,
              SUM(CASE WHEN LOWER(TRIM(Status)) = 'adopted' THEN 1 ELSE 0 END) as adopted_pets,
              SUM(CASE WHEN LOWER(TRIM(Status)) = 'pet sit' THEN 1 ELSE 0 END) as petsit_pets,
              SUM(CASE WHEN LOWER(TRIM(Status)) = 'overdue' THEN 1 ELSE 0 END) as overdue_pets,
              SUM(CASE WHEN LOWER(TRIM(Status)) = 'available' AND ApprovalStatus != 'approved' THEN 1 ELSE 0 END) as pending_approval_pets
            FROM pet";
$pets_result = $conn->query($pets_sql);
$pets_stats = $pets_result->fetch_assoc();

// 3. Pending Requests
$current_date = date('Y-m-d');
$pending_adoption_sql = "SELECT COUNT(*) as pending FROM AdoptionRequest WHERE Status = 'pending'";
$pending_petsit_sql = "SELECT COUNT(*) as pending FROM PetSitRequest WHERE Status = 'pending'";

$pending_adoption = $conn->query($pending_adoption_sql)->fetch_assoc()['pending'];
$pending_petsit = $conn->query($pending_petsit_sql)->fetch_assoc()['pending'];
$total_pending = $pending_adoption + $pending_petsit;

// 4. Today's Transactions
$today_adoption_sql = "SELECT COUNT(*) as today FROM AdoptionRequest WHERE DATE(RequestDate) = '$current_date'";
$today_petsit_sql = "SELECT COUNT(*) as today FROM PetSitRequest WHERE DATE(RequestDate) = '$current_date'";

$today_adoption = $conn->query($today_adoption_sql)->fetch_assoc()['today'];
$today_petsit = $conn->query($today_petsit_sql)->fetch_assoc()['today'];
$today_total = $today_adoption + $today_petsit;

// 5. Monthly Revenue (current month)
$current_month = date('Y-m');
$revenue_sql = "SELECT COALESCE(SUM(Amount), 0) as revenue FROM payment 
                      WHERE PaymentStatus = 'paid' 
                      AND DATE_FORMAT(PaymentDate, '%Y-%m') = '$current_month'";
$monthly_revenue = $conn->query($revenue_sql)->fetch_assoc()['revenue'];

// 6. Active Pet Sitters
$active_sitters_sql = "SELECT COUNT(DISTINCT SitterID) as active_sitters 
                            FROM PetSitRequest 
                            WHERE Status IN ('approved', 'completed') 
                            AND MONTH(RequestDate) = MONTH(CURDATE())";
$active_sitters = $conn->query($active_sitters_sql)->fetch_assoc()['active_sitters'];

// 7. Recent Completed (last 7 days)
$last_7_days = date('Y-m-d', strtotime('-7 days'));
$recent_completed_sql = "SELECT 
                                (SELECT COUNT(*) FROM PetSitRequest WHERE Status = 'completed' AND RequestDate >= '$last_7_days') as petsit_completed,
                                (SELECT COUNT(*) FROM AdoptionRequest WHERE Status = 'approved' AND RequestDate >= '$last_7_days') as adoption_completed";
$recent_result = $conn->query($recent_completed_sql);
$recent_stats = $recent_result->fetch_assoc();
$recent_completed = ($recent_stats['petsit_completed'] ?? 0) + ($recent_stats['adoption_completed'] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Admin Dashboard - FurCare</title>
  <link rel="stylesheet" href="css/adminDashboard.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .summary-section {
      margin: 20px 0;
      padding: 15px;
      background: #f8f9fa;
      border-radius: 10px;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }

    .summary-title {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 15px;
      color: #3B7A57;
    }

    .summary-title h2 {
      margin: 0;
      font-size: 20px;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 15px;
      margin-bottom: 20px;
    }

    .stat-card {
      background: white;
      padding: 15px;
      border-radius: 8px;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
      border: 1px solid #91e9b9ff;
    }

    .stat-card h4 {
      margin: 0 0 10px 0;
      color: #555;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .stat-number {
      font-size: 28px;
      font-weight: bold;
      color: #3B7A57;
      margin: 5px 0;
    }

    .stat-subtext {
      font-size: 12px;
      color: #777;
      margin-top: 5px;
    }

    .overdue-alert {
      background: #fff3cd;
      border: 1px solid #ffeaa7;
      border-radius: 8px;
      padding: 15px;
      margin-top: 15px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .overdue-alert h4 {
      margin: 0;
      color: #856404;
    }

    .overdue-count {
      background: #dc3545;
      color: white;
      padding: 3px 10px;
      border-radius: 20px;
      font-size: 14px;
      font-weight: bold;
    }

    .quick-links {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 10px;
    }

    .quick-link {
      background: #9cc0abff;
      color: white;
      padding: 8px 15px;
      border-radius: 5px;
      text-decoration: none;
      font-size: 13px;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .quick-link:hover {
      background: #7aa18eff;
    }

    .mini-chart-container {
      height: 100px;
      margin-top: 10px;
    }

    .stat-trend {
      font-size: 12px;
      color: #28a745;
      margin-top: 5px;
    }

    .stat-trend.down {
      color: #dc3545;
    }
  </style>
</head>

<body>

  <!-- Sidebar -->
  <div class="sidebar">
    <h2 class="logo">FurCare</h2>
    <a href="adminDashboard.php" class="active">🗂️ Main Menu</a>
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
    <div class="navbar">
      <h1>Welcome, Admin</h1>
      <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="Profile" class="profile-icon">
    </div>

    <!-- SUMMARY STATISTICS SECTION -->
    <div class="summary-section">
      <div class="summary-title">
        <h2>📊 Dashboard Overview</h2>
        <div class="quick-links">
          <a href="reports.php" class="quick-link">Full Reports</a>
        </div>
      </div>

      <div class="stats-grid">
        <!-- Stat 1: Total Users -->
        <div class="stat-card">
          <h4>👥 Total Users</h4>
          <div class="stat-number"><?php echo $total_users; ?></div>
          <div class="stat-subtext">All registered users</div>
        </div>

        <!-- Stat 2: Total Pets -->
        <div class="stat-card">
          <h4>🐾 Total Pets</h4>
          <div class="stat-number"><?php echo $pets_stats['total_pets']; ?></div>
          <div class="stat-subtext">
            <?php echo $pets_stats['available_pets']; ?> Available •
            <?php echo $pets_stats['adopted_pets']; ?> Adopted
          </div>
        </div>

        <!-- Stat 3: Pending Requests -->
        <div class="stat-card">
          <h4>⏳ Pending Requests</h4>
          <div class="stat-number"><?php echo $total_pending; ?></div>
          <div class="stat-subtext">
            <?php echo $pending_adoption; ?> Adoption •
            <?php echo $pending_petsit; ?> Pet Sitting
          </div>
          <?php if ($total_pending > 0): ?>
            <div class="stat-trend">Needs attention</div>
          <?php endif; ?>
        </div>

        <!-- Stat 4: Today's Activity -->
        <div class="stat-card">
          <h4>📅 Today's Activity</h4>
          <div class="stat-number"><?php echo $today_total; ?></div>
          <div class="stat-subtext">
            <?php echo $today_adoption; ?> Adoption •
            <?php echo $today_petsit; ?> Pet Sitting
          </div>
        </div>

        <!-- Stat 5: Monthly Revenue -->
        <div class="stat-card">
          <h4>💰 Monthly Revenue</h4>
          <div class="stat-number">RM<?php echo number_format($monthly_revenue, 2); ?></div>
          <div class="stat-subtext">Current month (<?php echo date('M Y'); ?>)</div>
          <?php if ($monthly_revenue > 0): ?>
            <div class="stat-trend">Active</div>
          <?php endif; ?>
        </div>

        <!-- Stat 6: Active Sitters -->
        <div class="stat-card">
          <h4>🌟 Active Sitters</h4>
          <div class="stat-number"><?php echo $active_sitters; ?></div>
          <div class="stat-subtext">This month</div>
        </div>
      </div>

      <!-- Overdue Alert -->
      <?php if ($pets_stats['overdue_pets'] > 0): ?>
        <div class="overdue-alert">
          <div style="display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 20px;">⚠️</span>
            <h4>Overdue Pets Detected</h4>
            <span class="overdue-count"><?php echo $pets_stats['overdue_pets']; ?></span>
          </div>
          <a href="adminManagePets.php?filter=overdue" class="quick-link">View Overdue Pets</a>
        </div>
      <?php endif; ?>

      <!-- Recent Completed -->
      <?php if ($recent_completed > 0): ?>
        <div style="margin-top: 15px; padding: 10px; background: #e9f7ef; border-radius: 8px; font-size: 14px;">
          <strong>Recent Success:</strong> <?php echo $recent_completed; ?> transactions completed in last 7 days
        </div>
      <?php endif; ?>
    </div>
    <!-- END SUMMARY SECTION -->

    <div class="card-grid">
      <div class="card green">
        <h3>Manage Pets</h3>
        <p>Add, edit, or remove pet listings.</p>
        <a href="adminManagePets.php" class="btn green">Go</a>
      </div>
      <div class="card pink">
        <h3>Manage Users</h3>
        <p>View and control user accounts.</p>
        <a href="manageUsers.php" class="btn pink">Go</a>
      </div>
      <div class="card green">
        <h3>Adoption Requests</h3>
        <p>View adoption requests from user.</p>
        <a href="adminAdoptionRequests.php" class="btn green">Go</a>
      </div>
      <div class="card pink">
        <h3>Pet Sitting Requests</h3>
        <p>View pet sitting requests from user.</p>
        <a href="adminPetSitRequests.php" class="btn pink">Go</a>
      </div>
      <div class="card green">
        <h3>Reports</h3>
        <p>View system statistics and reports.</p>
        <a href="reports.php" class="btn green">Go</a>
      </div>
      <div class="card pink">
        <h3> Settings</h3>
        <p>Manage your account information and details.</p>
        <a href="adminSettings.php" class="btn pink">Go</a>
      </div>
    </div>
  </div>

</body>

</html>