<?php
include 'connect.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];

// Handle actions
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    // Jangan benarkan delete admin sendiri
    if ($id != $userID) {
        // Check if user has related data in other tables (CARA SIMPLE)
        $pet_check = $conn->query("SELECT COUNT(*) as count FROM pet WHERE OwnerID = $id");
        $pet_count = $pet_check->fetch_assoc()['count'];
        
        $adoption_check = $conn->query("SELECT COUNT(*) as count FROM adoptionrequest WHERE OwnerID = $id OR AdopterID = $id");
        $adoption_count = $adoption_check->fetch_assoc()['count'];
        
        $petsit_check = $conn->query("SELECT COUNT(*) as count FROM petsitrequest WHERE OwnerID = $id OR SitterID = $id");
        $petsit_count = $petsit_check->fetch_assoc()['count'];
        
        $payment_check = $conn->query("SELECT COUNT(*) as count FROM payment WHERE PayerID = $id OR SitterID = $id");
        $payment_count = $payment_check->fetch_assoc()['count'];

        $total_related = $pet_count + $adoption_count + $petsit_count + $payment_count;

        if ($total_related > 0) {
            // User has related data, cannot delete
            $error_msg = "Cannot delete user. User has: ";
            $errors = [];
            if ($pet_count > 0) $errors[] = "$pet_count pets";
            if ($adoption_count > 0) $errors[] = "$adoption_count adoption requests";
            if ($petsit_count > 0) $errors[] = "$petsit_count pet sitting requests";
            if ($payment_count > 0) $errors[] = "$payment_count payment records";

            $error_msg .= implode(", ", $errors) . ". Please remove or transfer these items first.";
            header("Location: manageUsers.php?error=" . urlencode($error_msg));
            exit();
        } else {
            // User has no related data, safe to delete
            $conn->query("DELETE FROM user WHERE UserID = $id");
            header("Location: manageUsers.php?success=User deleted successfully");
            exit();
        }
    } else {
        header("Location: manageUsers.php?error=Cannot delete your own account");
        exit();
    }
}

// Handle role change
if (isset($_POST['change_role'])) {
    $targetUserID = intval($_POST['user_id']);
    $newRole = $_POST['new_role'];

    // Jangan benarkan ubah role sendiri
    if ($targetUserID != $userID) {
        $stmt = $conn->prepare("UPDATE user SET Role = ? WHERE UserID = ?");
        $stmt->bind_param("si", $newRole, $targetUserID);
        $stmt->execute();
        header("Location: manageUsers.php?success=User role updated successfully");
        exit();
    } else {
        header("Location: manageUsers.php?error=Cannot change your own role");
        exit();
    }
}

// ... REST OF THE CODE REMAINS THE SAME ...

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? 'all';

// Build query with filters
$where_conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(u.Name LIKE ? OR u.Email LIKE ? OR u.Phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'sss';
}

if ($role_filter !== 'all') {
    $where_conditions[] = "u.Role = ?";
    $params[] = $role_filter;
    $types .= 's';
}

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Get users count for statistics
$total_users = $conn->query("SELECT COUNT(*) as count FROM user")->fetch_assoc()['count'];
$admin_count = $conn->query("SELECT COUNT(*) as count FROM user WHERE Role = 'admin'")->fetch_assoc()['count'];
$user_count = $conn->query("SELECT COUNT(*) as count FROM user WHERE Role = 'user'")->fetch_assoc()['count'];

// Get users with filters and NEW trust level (based on transactions)
// 🟢 FIX QUERY INI - Guna logic yang COMPREHENSIVE
$query = "SELECT u.*, 
          (SELECT COUNT(DISTINCT SitRequestID) FROM petsitrequest 
           WHERE (SitterID = u.UserID OR OwnerID = u.UserID) 
           AND (
               Status = 'completed' OR
               CompletionStatus = 'completed' OR
               (SitterCompleted = 1 AND OwnerConfirmed = 1)
           )) as completed_petsitting,
           
          (SELECT COUNT(DISTINCT RequestID) FROM adoptionrequest 
           WHERE (OwnerID = u.UserID OR AdopterID = u.UserID) 
           AND Status = 'approved') as completed_adoption,
           
          (
              (SELECT COUNT(DISTINCT SitRequestID) FROM petsitrequest 
               WHERE (SitterID = u.UserID OR OwnerID = u.UserID) 
               AND (
                   Status = 'completed' OR
                   CompletionStatus = 'completed' OR
                   (SitterCompleted = 1 AND OwnerConfirmed = 1)
               ))
              +
              (SELECT COUNT(DISTINCT RequestID) FROM adoptionrequest 
               WHERE (OwnerID = u.UserID OR AdopterID = u.UserID) 
               AND Status = 'approved')
          ) as total_completed_transactions,
          
          CASE 
              WHEN u.Role = 'admin' THEN 'Administrator'
              WHEN (
                  (SELECT COUNT(DISTINCT SitRequestID) FROM petsitrequest 
                   WHERE (SitterID = u.UserID OR OwnerID = u.UserID) 
                   AND (
                       Status = 'completed' OR
                       CompletionStatus = 'completed' OR
                       (SitterCompleted = 1 AND OwnerConfirmed = 1)
                   ))
                  +
                  (SELECT COUNT(DISTINCT RequestID) FROM adoptionrequest 
                   WHERE (OwnerID = u.UserID OR AdopterID = u.UserID) 
                   AND Status = 'approved')
              ) >= 8 THEN 'Highly Trusted'
              WHEN (
                  (SELECT COUNT(DISTINCT SitRequestID) FROM petsitrequest 
                   WHERE (SitterID = u.UserID OR OwnerID = u.UserID) 
                   AND (
                       Status = 'completed' OR
                       CompletionStatus = 'completed' OR
                       (SitterCompleted = 1 AND OwnerConfirmed = 1)
                   ))
                  +
                  (SELECT COUNT(DISTINCT RequestID) FROM adoptionrequest 
                   WHERE (OwnerID = u.UserID OR AdopterID = u.UserID) 
                   AND Status = 'approved')
              ) >= 6 THEN 'Trusted'
              WHEN (
                  (SELECT COUNT(DISTINCT SitRequestID) FROM petsitrequest 
                   WHERE (SitterID = u.UserID OR OwnerID = u.UserID) 
                   AND (
                       Status = 'completed' OR
                       CompletionStatus = 'completed' OR
                       (SitterCompleted = 1 AND OwnerConfirmed = 1)
                   ))
                  +
                  (SELECT COUNT(DISTINCT RequestID) FROM adoptionrequest 
                   WHERE (OwnerID = u.UserID OR AdopterID = u.UserID) 
                   AND Status = 'approved')
              ) >= 3 THEN 'Verified'
              ELSE 'New User'
          END as trust_level,
          
          (SELECT COUNT(*) FROM pet WHERE OwnerID = u.UserID) as pet_count,
          (SELECT COUNT(*) FROM pet WHERE OwnerID = u.UserID AND ApprovalStatus = 'approved') as approved_pets
          
          FROM user u 
          $where_sql 
          ORDER BY u.UserID DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result();

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
    <title>Manage Users - FurCare</title>
    <link rel="stylesheet" href="css/adminDashboard.css">
    <link rel="stylesheet" href="css/manageUsers.css">
    <style>
        /* Error message styling */
        .error-message {
            background: linear-gradient(135deg, #ffe6e6, #ffcccc);
            border: 1px solid #ff9999;
            border: 1px solid #ff4d4d;
            color: #cc0000;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9em;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Success message styling */
        .success-message {
            background: linear-gradient(135deg, #e6ffe6, #ccffcc);
            border: 1px solid #99ff99;
            border: 1px solid #00cc00;
            color: #006600;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9em;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Warning badge untuk user yang ada data */
        .data-warning-badge {
            display: inline-block;
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.7em;
            margin-left: 5px;
        }

        /* Disabled delete button */
        .btn-delete.disabled {
            background: #cccccc;
            color: #666666;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .btn-delete.disabled:hover {
            background: #cccccc;
            transform: none;
        }

        /* Trust Level Badge Styles */
        .trust-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7em;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: white !important;
        }

        .trust-badge.administrator {
            background: linear-gradient(135deg, #b76e79, #d8a5b2);
        }

        .trust-badge.highly-trusted {
            background: linear-gradient(135deg, #ffeb3b, #ffd54f);
        }

        .trust-badge.trusted {
            background: linear-gradient(135deg, #81c784, #a5d6a7);
        }

        .trust-badge.verified {
            background: linear-gradient(135deg, #4fc3f7, #81d4fa);
        }

        .trust-badge.new-user {
            background: linear-gradient(135deg, #bdbdbd, #e0e0e0);
        }

        /* Transaction info styling */
        .transaction-info {
            font-size: 0.8em;
            color: #666;
            margin-top: 2px;
        }

        /* Table improvements */
        .user-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .user-table th {
            background-color: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #e9ecef;
        }

        .user-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }

        .user-table tr:hover {
            background-color: #f8f9fa;
        }

        .user-table tr.current-user {
            background-color: #fff3cd !important;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e9ecef;
        }

        .owner-avatar-link {
            display: inline-block;
            transition: transform 0.2s;
        }

        .owner-avatar-link:hover {
            transform: scale(1.05);
        }

        .role-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.75em;
            font-weight: 500;
        }

        .role-badge.role-admin {
            background-color: #d4edda;
            color: #155724;
        }

        .role-badge.role-user {
            background-color: #d1ecf1;
            color: #0c5460;
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
        <a href="manageUsers.php" class="active">👥 Manage Users</a>
        <a href="adminAdoptionRequests.php">📋 Adoption Request</a>
        <a href="adminPetSitRequests.php">🏠 Pet Sit Request</a>
        <a href="reports.php">📑 Reports</a>
        <a href="adminSetting.php">⚙️ Settings</a>
        <a href="logout.php" class="logout">🚪 Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="navbar">
            <h1>Manage Users</h1>
            <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
                alt="Profile"
                class="profile-icon">
        </div>

        <!-- Statistics Cards -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_users; ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $admin_count; ?></div>
                <div class="stat-label">Administrators</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $user_count; ?></div>
                <div class="stat-label">Regular Users</div>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_GET['success'])): ?>
            <div class="success-message">✅ <?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="error-message">❌ <?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <!-- Filters Section -->
        <div class="filters">
            <form method="GET" id="filterForm">
                <div class="filter-group">
                    <div class="search-container">
                        <input type="text" name="search" placeholder="Search by name, email, or phone..."
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="filter-container">
                        <select name="role">
                            <option value="all" <?php echo $role_filter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                            <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="user" <?php echo $role_filter === 'user' ? 'selected' : ''; ?>>User</option>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-search">Search</button>
                        <button type="button" class="btn btn-clear" onclick="clearFilters()">Clear</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Users Table -->
        <div class="user-table">
            <?php if ($users->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Contact</th>
                            <th>Role</th>
                            <th>Trust Level</th>
                            <th>Transactions</th>
                            <th>Pets</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($user = $users->fetch_assoc()): ?>
                            <tr class="<?php echo $user['UserID'] == $userID ? 'current-user' : ''; ?>">
                                <td><?php echo $user['UserID']; ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <a href="adminViewUsers.php?user_id=<?php echo $user['UserID']; ?>" class="owner-avatar-link">
                                            <img src="<?php echo !empty($user['ProfilePicture']) ? htmlspecialchars($user['ProfilePicture']) : 'uploads/profile_icon.png'; ?>"
                                                alt="<?php echo htmlspecialchars($user['Name']); ?>"
                                                class="user-avatar">
                                        </a>
                                        <div>
                                            <a href="adminViewUsers.php?user_id=<?= $user['UserID'] ?>" style="text-decoration: none; color: inherit;">
                                                <strong><?php echo htmlspecialchars($user['Name']); ?></strong>
                                            </a>
                                            <?php if ($user['UserID'] == $userID): ?>
                                                <span style="color: #ffc107; font-size: 0.8em;">(You)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($user['Email']); ?></div>
                                    <small style="color: #666;"><?php echo !empty($user['Phone']) ? htmlspecialchars($user['Phone']) : 'No phone'; ?></small>
                                </td>
                                <td>
                                    <span class="role-badge role-<?php echo $user['Role']; ?>">
                                        <?php echo htmlspecialchars($user['Role']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="trust-badge <?php echo strtolower(str_replace(' ', '-', $user['trust_level'])); ?>">
                                        <?php echo $user['trust_level']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-size: 0.85em;">
                                        <div>Pet Sitting: <?php echo $user['completed_petsitting']; ?></div>
                                        <div>Adoption: <?php echo $user['completed_adoption']; ?></div>
                                        <div><strong>Total: <?php echo $user['total_completed_transactions']; ?></strong></div>
                                    </div>
                                </td>
                                <td>
                                    <div><?php echo $user['pet_count']; ?> total</div>
                                    <small style="color: #666;"><?php echo $user['approved_pets']; ?> approved</small>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($user['UserID'] != $userID): ?>
                                            <button class="btn-role" onclick="openRoleModal(<?php echo $user['UserID']; ?>, '<?php echo $user['Role']; ?>')">
                                                Change Role
                                            </button>
                                            <a href="manageUsers.php?delete=<?php echo $user['UserID']; ?>"
                                                class="btn-delete"
                                                onclick="return confirm('Are you sure you want to delete <?php echo addslashes($user['Name']); ?>? This action cannot be undone.')">
                                                Delete
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #666; font-size: 0.8em;">Current user</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-users">
                    <h3>No Users Found</h3>
                    <p>No users match your search criteria.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Role Change Modal -->
    <div id="roleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Change User Role</h3>
                <span class="close" onclick="closeRoleModal()">&times;</span>
            </div>
            <form method="POST" id="roleForm">
                <input type="hidden" name="user_id" id="roleUserID">
                <input type="hidden" name="change_role" value="1">
                <div class="form-group">
                    <label for="new_role">Select New Role:</label>
                    <select name="new_role" id="new_role" required>
                        <option value="user">User</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeRoleModal()">Cancel</button>
                    <button type="submit" class="btn btn-confirm">Change Role</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function clearFilters() {
            window.location.href = 'manageUsers.php';
        }

        function openRoleModal(userID, currentRole) {
            document.getElementById('roleUserID').value = userID;
            document.getElementById('new_role').value = currentRole;
            document.getElementById('roleModal').style.display = 'block';
        }

        function closeRoleModal() {
            document.getElementById('roleModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const roleModal = document.getElementById('roleModal');
            if (event.target == roleModal) {
                closeRoleModal();
            }
        }

        // Auto-hide success/error messages after 5 seconds
        setTimeout(() => {
            const messages = document.querySelectorAll('.success-message, .error-message');
            messages.forEach(msg => {
                if (msg) msg.style.display = 'none';
            });
        }, 5000);
    </script>
</body>

</html>