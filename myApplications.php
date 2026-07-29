<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];
$activeTab = $_GET['tab'] ?? 'adoption';
$status_filter = $_GET['status'] ?? 'active';

// Fetch user data untuk navbar
$user_stmt = $conn->prepare("SELECT * FROM user WHERE UserID = ?");
$user_stmt->bind_param("i", $userID);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

// Handle cancel pet sit request
if (isset($_GET['cancel_petsit'])) {
    $cancelID = intval($_GET['cancel_petsit']);

    // Confirm sitter owns this request dan check status
    $check = $conn->prepare("SELECT * FROM PetSitRequest WHERE SitRequestID = ? AND SitterID = ?");
    $check->bind_param("ii", $cancelID, $userID);
    $check->execute();
    $result = $check->get_result();

    if ($result && $result->num_rows > 0) {
        $requestData = $result->fetch_assoc();

        // Hanya benarkan cancel untuk status 'pending' sahaja
        if ($requestData['Status'] !== 'pending') {
            header("Location: myApplications.php?tab=petsit&error=Cannot cancel request that is already approved or completed.");
            exit;
        }

        $update = $conn->prepare("UPDATE PetSitRequest SET Status = 'cancelled' WHERE SitRequestID = ?");
        $update->bind_param("i", $cancelID);
        if ($update->execute()) {
            header("Location: myApplications.php?tab=petsit&success=Request cancelled successfully!");
            exit;
        } else {
            header("Location: myApplications.php?tab=petsit&error=Failed to cancel request. Please try again.");
            exit;
        }
    } else {
        header("Location: myApplications.php?tab=petsit&error=Invalid request or unauthorized access.");
        exit;
    }
}

// Handle different data based on active tab
if ($activeTab === 'adoption') {
    // Fetch adoption requests
    $where_conditions = ["ar.AdopterID = ?"];
    $params = [$userID];
    $types = "i";

    if ($status_filter === 'active') {
        $where_conditions[] = "ar.Status IN ('pending', 'approved', 'under_review')";
    } elseif ($status_filter === 'completed') {
        $where_conditions[] = "ar.Status IN ('approved', 'rejected')";
    } elseif ($status_filter === 'cancelled') {
        $where_conditions[] = "ar.Status = 'cancelled'";
    } elseif ($status_filter === 'all') {
        // Show all - no additional condition
    }

    $where_sql = implode(' AND ', $where_conditions);

    $query = "SELECT ar.*, p.Name as PetName, p.Image as PetImage, p.Type as PetType, 
              p.Breed as PetBreed, u.Name as OwnerName, u.Email as OwnerEmail, u.Phone as OwnerPhone
              FROM AdoptionRequest ar
              JOIN pet p ON ar.PetID = p.PetID
              JOIN user u ON ar.OwnerID = u.UserID
              WHERE $where_sql
              ORDER BY ar.RequestDate DESC";

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $requests = $stmt->get_result();
}

// Get counts for stats - INITIALIZE WITH ALL POSSIBLE STATUSES
$adoption_counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0, 'under_review' => 0];
$petsit_counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'completed' => 0, 'cancelled' => 0, 'overdue' => 0];

// Count adoption requests
$count_sql = "SELECT Status, COUNT(*) as count FROM AdoptionRequest WHERE AdopterID = ? GROUP BY Status";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param("i", $userID);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
while ($row = $count_result->fetch_assoc()) {
    $adoption_counts[$row['Status']] = $row['count'];
}

// Count pet sit requests  
$count_sql = "SELECT Status, COUNT(*) as count FROM PetSitRequest WHERE SitterID = ? GROUP BY Status";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param("i", $userID);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
while ($row = $count_result->fetch_assoc()) {
    $petsit_counts[$row['Status']] = $row['count'];
}

// Handle success/error messages
$success_message = $_GET['success'] ?? '';
$error_message = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Applications - FurCare</title>
    <link rel="stylesheet" href="css/myApplications.css">
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
        <a href="addPetUser.php">➕ Post a Pet</a>
        <a href="ownerRequests.php">📩 My Pet Requests</a>
        <a href="myApplications.php" class="active">📋 My Requests</a>
        <a href="logout.php" class="logout">🚪 Logout</a>
    </div>

    <div class="overlay" onclick="toggleSidebar()"></div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="applications-container">
            <h1 class="page-title">My Applications</h1>

            <!-- Success/Error Messages -->
            <?php if (!empty($success_message)): ?>
                <div class="success-message">
                    ✅ <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="error-message">
                    ❌ <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Main Tabs -->
            <div class="main-tabs">
                <button class="main-tab <?= $activeTab === 'adoption' ? 'active' : '' ?>"
                    onclick="switchTab('adoption')">
                    🐕 Adoption Applications
                </button>
                <button class="main-tab <?= $activeTab === 'petsit' ? 'active' : '' ?>"
                    onclick="switchTab('petsit')">
                    🏠 Pet Sit Applications
                </button>
            </div>

            <!-- Adoption Applications Tab -->
            <div id="adoption-tab" class="tab-content <?= $activeTab === 'adoption' ? 'active' : '' ?>">
                <!-- Stats for Adoption -->
                <div class="stats-cards">
                    <div class="stat-card">
                        <div class="stat-number"><?= array_sum($adoption_counts) ?></div>
                        <div class="stat-label">Total Applications</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $adoption_counts['pending'] + $adoption_counts['under_review'] ?></div>
                        <div class="stat-label">Active</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $adoption_counts['approved'] ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $adoption_counts['rejected'] ?></div>
                        <div class="stat-label">Rejected</div>
                    </div>
                </div>

                <!-- Status Tabs for Adoption -->
                <div class="status-tabs">
                    <button class="status-tab <?= $status_filter === 'active' ? 'active' : '' ?>"
                        onclick="updateStatusFilter('active')">Active</button>
                    <button class="status-tab <?= $status_filter === 'completed' ? 'active' : '' ?>"
                        onclick="updateStatusFilter('completed')">Completed</button>
                    <button class="status-tab <?= $status_filter === 'cancelled' ? 'active' : '' ?>"
                        onclick="updateStatusFilter('cancelled')">Cancelled</button>
                    <button class="status-tab <?= $status_filter === 'all' ? 'active' : '' ?>"
                        onclick="updateStatusFilter('all')">All</button>
                </div>

                <?php if ($activeTab === 'adoption' && $requests->num_rows > 0): ?>
                    <div class="requests-grid">
                        <?php while ($request = $requests->fetch_assoc()): ?>
                            <div class="request-card adoption">
                                <img src="<?php echo htmlspecialchars($request['PetImage']); ?>"
                                    alt="<?php echo htmlspecialchars($request['PetName']); ?>"
                                    class="pet-image">

                                <div class="request-info">
                                    <h3 class="pet-name"><?php echo htmlspecialchars($request['PetName']); ?></h3>

                                    <div class="request-meta">
                                        <div class="meta-item">
                                            <span class="meta-label">Pet Type</span>
                                            <span class="meta-value"><?php echo htmlspecialchars($request['PetType']); ?></span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Breed</span>
                                            <span class="meta-value"><?php echo htmlspecialchars($request['PetBreed']); ?></span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Owner</span>
                                            <span class="meta-value"><?php echo htmlspecialchars($request['OwnerName']); ?></span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Request Date</span>
                                            <span class="meta-value"><?php echo date('M j, Y', strtotime($request['RequestDate'])); ?></span>
                                        </div>
                                    </div>

                                    <div style="margin-bottom: 10px;">
                                        <span class="meta-label">Status:</span>
                                        <span class="status-badge status-<?php echo $request['Status']; ?>">
                                            <?php
                                            $status_display = [
                                                'pending' => 'Pending Review',
                                                'approved' => 'Approved',
                                                'rejected' => 'Rejected',
                                                'cancelled' => 'Cancelled',
                                                'under_review' => 'Under Review'
                                            ];
                                            echo $status_display[$request['Status']] ?? ucfirst($request['Status']);
                                            ?>
                                        </span>
                                    </div>

                                    <?php if ($request['Status'] === 'rejected' && !empty($request['RejectionReason'])): ?>
                                        <div class="rejection-reason">
                                            <div class="rejection-label">Reason for Rejection:</div>
                                            <div><?php echo htmlspecialchars($request['RejectionReason']); ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="action-buttons">
                                        <a href="adoptionRequestDetails.php?request_id=<?php echo $request['RequestID']; ?>"
                                            class="btn btn-view">View Details</a>

                                        <?php if ($request['Status'] === 'approved'): ?>
                                            <a href="mailto:<?php echo $request['OwnerEmail']; ?>"
                                                class="btn btn-contact">
                                                📧 Email Owner
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($request['Status'] === 'pending'): ?>
                                            <a href="cancelAdoptionRequest.php?request_id=<?php echo $request['RequestID']; ?>"
                                                class="btn btn-cancel"
                                                onclick="return confirm('Are you sure you want to cancel this adoption request? This action cannot be undone.')">
                                                Cancel Request
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-disabled"
                                                title="Cannot cancel <?php echo $request['Status']; ?> requests">
                                                Cancel Request
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php elseif ($activeTab === 'adoption'): ?>
                    <div class="no-requests">
                        <h3>No Adoption Applications Found</h3>
                        <p>
                            <?php
                            $messages = [
                                'active' => 'You have no active adoption applications.',
                                'completed' => 'You have no completed adoption applications.',
                                'cancelled' => 'You have no cancelled adoption applications.',
                                'all' => 'You have no adoption applications yet.'
                            ];
                            echo $messages[$status_filter] ?? 'No applications found for this filter.';
                            ?>
                        </p>
                        <?php if ($status_filter === 'active' || $status_filter === 'all'): ?>
                            <a href="userDashboard.php" class="btn" style="background: #5aa17aff; color: white; padding: 12px 24px; display: inline-block; margin-top: 15px; text-decoration: none;">
                                Browse Pets for Adoption
                            </a>
                        <?php else: ?>
                            <a href="myApplications.php?tab=adoption&status=active" class="btn" style="background: #4299e1; color: white; padding: 12px 24px; display: inline-block; margin-top: 15px; text-decoration: none;">
                                View Active Applications
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pet Sit Applications Tab -->
            <div id="petsit-tab" class="tab-content <?= $activeTab === 'petsit' ? 'active' : '' ?>">

                <!-- Stats for Pet Sit -->
                <div class="stats-cards">
                    <div class="stat-card">
                        <div class="stat-number"><?= array_sum($petsit_counts) ?></div>
                        <div class="stat-label">Total Applications</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $petsit_counts['pending'] + $petsit_counts['approved'] ?></div>
                        <div class="stat-label">Active</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $petsit_counts['completed'] ?></div>
                        <div class="stat-label">Completed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $petsit_counts['overdue'] ?></div>
                        <div class="stat-label">Overdue</div>
                    </div>
                </div>

                <!-- Status Tabs for Pet Sit -->
                <div class="status-tabs">
                    <button class="status-tab <?= $status_filter === 'active' ? 'active' : '' ?>"
                        onclick="updateStatusFilter('active')">Active</button>
                    <button class="status-tab <?= $status_filter === 'completed' ? 'active' : '' ?>"
                        onclick="updateStatusFilter('completed')">Completed</button>
                    <button class="status-tab <?= $status_filter === 'overdue' ? 'active' : '' ?>"
                        onclick="updateStatusFilter('overdue')">Overdue</button>
                    <button class="status-tab <?= $status_filter === 'cancelled' ? 'active' : '' ?>"
                        onclick="updateStatusFilter('cancelled')">Cancelled</button>
                    <button class="status-tab <?= $status_filter === 'all' ? 'active' : '' ?>"
                        onclick="updateStatusFilter('all')">All</button>
                </div>

                <?php
                // === PET SIT REQUESTS FILTER ===
                $where_conditions = ["psr.SitterID = ?"];
                $params = [$userID];
                $types = "i";

                if ($status_filter === 'active') {
                    $where_conditions[] = "psr.Status IN ('pending', 'approved')";
                } elseif ($status_filter === 'completed') {
                    $where_conditions[] = "psr.Status = 'completed'";
                } elseif ($status_filter === 'overdue') {
                    $where_conditions[] = "psr.Status = 'overdue'";
                } elseif ($status_filter === 'cancelled') {
                    $where_conditions[] = "psr.Status = 'cancelled'";
                } elseif ($status_filter === 'all') {
                    // Show all - no additional condition
                }

                $where_sql = implode(' AND ', $where_conditions);
               $sql = "SELECT psr.*, 
       p.Name as PetName, p.Image as PetImage, p.Type as PetType,
       p.SitStartDate, p.SitEndDate, 
       owner.Name as OwnerName, owner.Email as OwnerEmail, 
       owner.Phone as OwnerPhone, owner.ProfilePicture as OwnerPhoto,
       p.District as PetDistrict, p.State as PetState,
       p.price as DailyPrice  -- AMBIK PRICE DARI TABLE PET
FROM PetSitRequest psr
JOIN pet p ON psr.PetID = p.PetID
JOIN user owner ON psr.OwnerID = owner.UserID
WHERE $where_sql
ORDER BY psr.RequestDate DESC";

                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $requests = $stmt->get_result();
                ?>

                <?php if ($requests->num_rows > 0): ?>
                    <div class="requests-grid">
                        <?php while ($request = $requests->fetch_assoc()):
                            $isOverdue = $request['Status'] === 'overdue';
                        ?>
                            <div class="request-card petsit <?php echo $isOverdue ? 'overdue' : ''; ?>">

                                <img src="<?php echo htmlspecialchars($request['PetImage']); ?>" alt="<?php echo htmlspecialchars($request['PetName']); ?>" class="pet-image">
                                <img src="<?php echo !empty($request['OwnerPhoto']) ? htmlspecialchars($request['OwnerPhoto']) : 'uploads/profile_icon.png'; ?>" alt="<?php echo htmlspecialchars($request['OwnerName']); ?>" class="user-avatar">

                                <div class="request-info">
                                    <h3 class="pet-name"><?php echo htmlspecialchars($request['PetName']); ?></h3>
                                    <h4 class="user-name">Pet Owner: <?php echo htmlspecialchars($request['OwnerName']); ?></h4>

                                    <?php if ($isOverdue): ?>
                                        <div class="overdue-alert-card">
                                            <div class="overdue-alert-content">
                                                <div class="overdue-alert-title">SITTING PERIOD HAS PASSED</div>
                                                <div class="overdue-alert-desc">
                                                    The owner didn't approve any sitter before the start date.
                                                    This request is no longer available.
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="request-meta">
                                        <div class="meta-item">
                                            <span class="meta-label">Pet Type</span>
                                            <span class="meta-value"><?php echo htmlspecialchars($request['PetType']); ?></span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Location</span>
                                            <span class="meta-value"><?php echo htmlspecialchars($request['PetDistrict'] . ', ' . $request['PetState']); ?></span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Sitting Period</span>
                                            <span class="meta-value">
                                                <?php
                                                $startDateValid = !empty($request['SitStartDate']) && $request['SitStartDate'] != '0000-00-00';
                                                $endDateValid = !empty($request['SitEndDate']) && $request['SitEndDate'] != '0000-00-00';
                                                if ($startDateValid && $endDateValid) {
                                                    echo date('M j, Y', strtotime($request['SitStartDate'])) . ' - ' . date('M j, Y', strtotime($request['SitEndDate']));
                                                } else {
                                                    echo '<span style="color: #a0aec0;">Dates to be arranged</span>';
                                                }
                                                ?>
                                            </span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Total Amount</span>
                                            <span class="meta-value price-highlight">
                                                RM<?php echo number_format($request['TotalAmount'], 2); ?><br>
                                                <small>(RM<?php echo number_format($request['DailyPrice'], 2); ?>/day)</small>
                                            </span>
                                        </div>
                                    </div>

                                    <div style="margin-bottom: 10px;">
                                        <span class="meta-label">Status:</span>
                                        <span class="status-badge status-<?php echo $request['Status']; ?> <?php echo $isOverdue ? 'status-overdue' : ''; ?>">
                                            <?php echo ucfirst($request['Status']); ?>
                                        </span>
                                    </div>

                                    <div class="action-buttons">
                                        <a href="sitterPetSitRequestDetails.php?request_id=<?php echo $request['SitRequestID']; ?>" class="btn btn-view">View Details</a>

                                        <?php if ($request['Status'] === 'pending'): ?>
                                            <a href="?cancel_petsit=<?php echo $request['SitRequestID']; ?>"
                                                class="btn btn-cancel"
                                                onclick="return confirm('Are you sure you want to cancel this pet sit request? This action cannot be undone.')"
                                                style="background:#f87171; color:white;">Cancel Request</a>
                                        <?php else: ?>
                                            <button class="btn btn-disabled"
                                                title="Cannot cancel <?php echo $request['Status']; ?> requests">
                                                Cancel Request
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="no-requests">
                        <h3>No Pet Sit Applications Found</h3>
                        <p>
                            <?php
                            $messages = [
                                'active' => 'You have no active pet sitting applications.',
                                'completed' => 'You have no completed pet sitting applications.',
                                'overdue' => 'You have no overdue pet sitting applications.',
                                'cancelled' => 'You have no cancelled pet sitting applications.',
                                'all' => 'You have not made any pet sitting applications yet.'
                            ];
                            echo $messages[$status_filter] ?? 'No applications found for this filter.';
                            ?>
                        </p>
                        <?php if ($status_filter === 'active' || $status_filter === 'all'): ?>
                            <a href="userDashboard.php" class="btn" style="background: #ac8d5dd7; color: white; padding: 12px 24px; display: inline-block; margin-top: 15px; text-decoration: none;">
                                Browse Pets Needing Sitting
                            </a>
                        <?php else: ?>
                            <a href="myApplications.php?tab=petsit&status=active" class="btn" style="background: #4299e1; color: white; padding: 12px 24px; display: inline-block; margin-top: 15px; text-decoration: none;">
                                View Active Applications
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>

        </div>
    </div>

    <script>
        function toggleSidebar() {
            document.body.classList.toggle('sidebar-open');
        }

        function switchTab(tabName) {
            // Update URL without page reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            // Reset status filter when switching main tabs
            url.searchParams.set('status', 'active');
            window.location.href = url.toString();
        }

        function updateStatusFilter(status) {
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            window.location.href = url.toString();
        }

        // Auto-hide messages after 5 seconds
        setTimeout(() => {
            const messages = document.querySelectorAll('.success-message, .error-message');
            messages.forEach(msg => {
                if (msg) {
                    msg.style.opacity = '0';
                    setTimeout(() => msg.remove(), 300);
                }
            });
        }, 5000);

        // Add loading state to buttons
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('btn-cancel')) {
                e.target.innerHTML = '⏳ Cancelling...';
                e.target.disabled = true;
            }
        });

        // Prevent click on disabled buttons
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('btn-disabled')) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    </script>
</body>

</html>

<?php
// Close database connection
$conn->close();
?>