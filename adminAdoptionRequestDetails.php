<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];
$requestID = $_GET['request_id'] ?? 0;

// Fetch adoption request details - ADMIN boleh lihat semua requests
$stmt = $conn->prepare("SELECT ar.*, 
          p.Name as PetName, p.Image as PetImage, p.Type as PetType, p.Breed as PetBreed, 
          p.Age as PetAge, p.Gender as PetGender, p.Description as PetDescription,
          p.District as PetDistrict, p.State as PetState, p.Status as PetStatus,
          u.Name as AdopterName, u.Email as AdopterEmail, u.Phone as AdopterPhone, 
          u.ProfilePicture as AdopterPhoto,
          owner.Name as OwnerName, owner.Email as OwnerEmail, owner.Phone as OwnerPhone,
          owner.ProfilePicture as OwnerPhoto
          FROM AdoptionRequest ar
          JOIN pet p ON ar.PetID = p.PetID
          JOIN user u ON ar.AdopterID = u.UserID
          JOIN user owner ON ar.OwnerID = owner.UserID
          WHERE ar.RequestID = ?");
$stmt->bind_param("i", $requestID);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();

if (!$request) {
    header("Location: adminAdoptionRequests.php?error=Request not found");
    exit;
}

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        // Start transaction untuk consistency
        mysqli_begin_transaction($conn);

        try {
            // 1. Approve the current request
            $stmt = $conn->prepare("UPDATE AdoptionRequest SET Status = 'approved', RejectionReason = NULL WHERE RequestID = ?");
            $stmt->bind_param("i", $requestID);
            $stmt->execute();

            // 2. Update pet status to adopted
            $pet_stmt = $conn->prepare("UPDATE pet SET Status = 'Adopted' WHERE PetID = ?");
            $pet_stmt->bind_param("i", $request['PetID']);
            $pet_stmt->execute();

            // 3. AUTO-REJECT OTHER PENDING REQUESTS FOR SAME PET
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
            $auto_reject_stmt->bind_param("iii", $requestID, $request['PetID'], $requestID);
            $auto_reject_stmt->execute();

            // Untuk ADOPTER
            $update_adopter_sql = "UPDATE user SET total_transactions = total_transactions + 1 WHERE UserID = ?";
            $update_adopter_stmt = $conn->prepare($update_adopter_sql);
            $update_adopter_stmt->bind_param("i", $request['AdopterID']);
            $update_adopter_stmt->execute();

            // Untuk OWNER
            $update_owner_sql = "UPDATE user SET total_transactions = total_transactions + 1 WHERE UserID = ?";
            $update_owner_stmt = $conn->prepare($update_owner_sql);
            $update_owner_stmt->bind_param("i", $request['OwnerID']);
            $update_owner_stmt->execute();

            // Commit transaction
            mysqli_commit($conn);

            header("Location: adminAdoptionRequestDetails.php?request_id=$requestID&success=Adoption request approved successfully");
            exit;
        } catch (Exception $e) {
            // Rollback jika ada error
            mysqli_rollback($conn);
            header("Location: adminAdoptionRequestDetails.php?request_id=$requestID&error=Failed to approve request: " . $e->getMessage());
            exit;
        }
    } elseif ($action === 'reject') {
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');
        $stmt = $conn->prepare("UPDATE AdoptionRequest SET Status = 'rejected', RejectionReason = ? WHERE RequestID = ?");
        $stmt->bind_param("si", $rejection_reason, $requestID);
        $stmt->execute();

        header("Location: adminAdoptionRequestDetails.php?request_id=$requestID&success=Adoption request rejected successfully");
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
    <title>Adoption Request Details - Admin - FurCare</title>
    <link rel="stylesheet" href="css/adminDashboard.css">
    <link rel="stylesheet" href="css/adminAdoptionRequestDetails.css">

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
            <h1>Adoption Request Details</h1>
            <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
                alt="Profile" class="profile-icon">
        </div>

        <div class="compact-layout">
            <!-- Summary Cards -->
            <div class="summary-cards">
                <div class="summary-card">
                    <h3>Status</h3>
                    <div class="value status-badge status-<?php echo $request['Status']; ?>">
                        <?php echo ucfirst($request['Status']); ?>
                    </div>
                </div>
                <div class="summary-card">
                    <h3>Pet Status</h3>
                    <div class="value" style="color: <?php echo $request['PetStatus'] === 'Available' ? '#27ae60' : '#e74c3c'; ?>">
                        <?php echo htmlspecialchars($request['PetStatus']); ?>
                    </div>
                </div>
                <div class="summary-card">
                    <h3>Request Date</h3>
                    <div class="value"><?php echo date('M j, Y', strtotime($request['RequestDate'])); ?></div>
                </div>
                <div class="summary-card">
                    <h3>Housing Type</h3>
                    <div class="value"><?php echo ucfirst($request['HousingType']); ?></div>
                </div>
            </div>

            <!-- Success Message -->
            <?php if (isset($_GET['success'])): ?>
                <div class="success-message">
                    ✅ <?php echo htmlspecialchars($_GET['success']); ?>
                </div>
            <?php endif; ?>

            <div class="compact-grid">
                <!-- Sidebar - Essential Info -->
                <div class="sidebar-info">
                    <!-- Pet Card -->
                    <div class="card pet-card">
                        <img src="<?php echo htmlspecialchars($request['PetImage']); ?>"
                            alt="<?php echo htmlspecialchars($request['PetName']); ?>"
                            class="pet-image">
                        <h3 class="pet-name"><?php echo htmlspecialchars($request['PetName']); ?></h3>
                        <div class="pet-details">
                            <p><strong>Type:</strong> <?php echo htmlspecialchars($request['PetType']); ?></p>
                            <p><strong>Breed:</strong> <?php echo htmlspecialchars($request['PetBreed']); ?></p>
                            <p><strong>Age:</strong> <?php echo formatAge($request['PetAge']); ?></p>
                            <p><strong>Gender:</strong> <?php echo htmlspecialchars($request['PetGender']); ?></p>
                            <p><strong>Location:</strong> <?php echo htmlspecialchars($request['PetDistrict'] . ', ' . $request['PetState']); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Main Content - Scrollable -->
                <div class="main-content-scrollable">
                    <!-- Tabs Navigation -->
                    <div class="tabs-container">
                        <div class="tabs-header">
                            <div class="tab active" onclick="openTab('details')">Request Details</div>
                            <div class="tab" onclick="openTab('family')">Family Info</div>
                            <div class="tab" onclick="openTab('pet')">Pet Details</div>
                        </div>

                        <!-- Details Tab -->
                        <div id="details-tab" class="tab-content active">
                            <!-- Request Information Accordion -->
                            <div class="accordion active">
                                <div class="accordion-header">
                                    <span>Request Information</span>
                                    <span class="accordion-icon">▼</span>
                                </div>
                                <div class="accordion-content">
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="info-label">Request ID</span>
                                            <span class="info-value">#<?php echo $request['RequestID']; ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Request Date</span>
                                            <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($request['RequestDate'])); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Last Updated</span>
                                            <span class="info-value">
                                                <?php
                                                if (!empty($request['UpdatedAt'])) {
                                                    echo date('M j, Y g:i A', strtotime($request['UpdatedAt']));
                                                } else {
                                                    echo 'Never updated';
                                                }
                                                ?>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Adopter Address -->
                                    <div class="info-item-full">
                                        <span class="info-label">Adopter Address</span>
                                        <div class="info-value"><?php echo nl2br(htmlspecialchars($request['AdopterAddress'])); ?></div>
                                    </div>

                                    <!-- Adoption Reason -->
                                    <div class="info-item-full">
                                        <span class="info-label">Adoption Reason</span>
                                        <div class="info-value"><?php echo nl2br(htmlspecialchars($request['AdoptionReason'])); ?></div>
                                    </div>

                                    <!-- Pet Experience -->
                                    <?php if (!empty($request['PetExperience'])): ?>
                                        <div class="info-item-full">
                                            <span class="info-label">Pet Experience</span>
                                            <div class="info-value"><?php echo nl2br(htmlspecialchars($request['PetExperience'])); ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Rejection Reason -->
                                    <?php if ($request['Status'] === 'rejected' && !empty($request['RejectionReason'])): ?>
                                        <div class="rejection-box">
                                            <div class="rejection-title">Reason for Rejection:</div>
                                            <div><?php echo nl2br(htmlspecialchars($request['RejectionReason'])); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Family Tab -->
                        <div id="family-tab" class="tab-content">
                            <div class="adoption-details-card">
                                <h3>Family & Living Situation</h3>
                                <div class="family-grid">
                                    <div class="family-item">
                                        <span class="family-label">Housing Type</span>
                                        <span class="family-value"><?php echo ucfirst($request['HousingType']); ?></span>
                                    </div>
                                    <div class="family-item">
                                        <span class="family-label">Family Members</span>
                                        <span class="family-value"><?php echo $request['FamilyMembers']; ?> people</span>
                                    </div>
                                    <div class="family-item">
                                        <span class="family-label">Has Children</span>
                                        <span class="family-value" style="color: <?php echo $request['HasChildren'] === 'yes' ? '#27ae60' : '#e74c3c'; ?>">
                                            <?php echo ucfirst($request['HasChildren']); ?>
                                        </span>
                                    </div>
                                    <div class="family-item">
                                        <span class="family-label">Other Pets</span>
                                        <span class="family-value" style="color: <?php echo $request['OtherPets'] === 'yes' ? '#27ae60' : '#e74c3c'; ?>">
                                            <?php echo ucfirst($request['OtherPets']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pet Tab -->
                        <div id="pet-tab" class="tab-content">
                            <div class="adoption-details-card">
                                <h3>Pet Information</h3>
                                <div class="info-grid">
                                    <div class="info-item">
                                        <span class="info-label">Pet Name</span>
                                        <span class="info-value"><?php echo htmlspecialchars($request['PetName']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Type</span>
                                        <span class="info-value"><?php echo htmlspecialchars($request['PetType']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Breed</span>
                                        <span class="info-value"><?php echo htmlspecialchars($request['PetBreed']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Age</span>
                                        <span class="info-value"><?php echo formatAge($request['PetAge']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Gender</span>
                                        <span class="info-value"><?php echo htmlspecialchars($request['PetGender']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Location</span>
                                        <span class="info-value"><?php echo htmlspecialchars($request['PetDistrict'] . ', ' . $request['PetState']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Current Status</span>
                                        <span class="info-value" style="color: <?php echo $request['PetStatus'] === 'Available' ? '#27ae60' : '#e74c3c'; ?>">
                                            <?php echo htmlspecialchars($request['PetStatus']); ?>
                                        </span>
                                    </div>
                                </div>

                                <?php if (!empty($request['PetDescription'])): ?>
                                    <div class="info-item-full">
                                        <span class="info-label">Pet Description</span>
                                        <div class="info-value"><?php echo nl2br(htmlspecialchars($request['PetDescription'])); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Horizontal Bottom Section -->
            <div class="bottom-section">
                <div class="bottom-grid">
                    <!-- Quick Actions Card -->
                    <div class="bottom-card quick-actions">
                        <h3>Quick Actions</h3>
                        <div class="quick-actions-horizontal">
                            <a href="adminAdoptionRequests.php" class="btn btn-back">
                                <span style="font-size: 1.2rem;"></span> Back to List
                            </a>
                            <a href="mailto:<?php echo htmlspecialchars($request['AdopterEmail']); ?>?cc=<?php echo htmlspecialchars($request['OwnerEmail']); ?>"
                                class="btn btn-contact">
                                <span style="font-size: 1.2rem;">📧</span> Contact Both
                            </a>

                            <?php if ($request['Status'] === 'pending'): ?>
                                <button class="btn btn-approve" onclick="openApproveModal()">
                                    <span style="font-size: 1.2rem;"></span> Approve Request
                                </button>
                                <button class="btn btn-reject" onclick="openRejectModal()">
                                    <span style="font-size: 1.2rem;"></span> Reject Request
                                </button>
                            <?php else: ?>
                                <div class="action-disabled">
                                    <p style="text-align: center; color: var(--gray); padding: 10px;">
                                        No actions available - Request is <?php echo $request['Status']; ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Parties Involved Card -->
                    <div class="bottom-card parties">
                        <h3>Parties Involved</h3>
                        <div class="parties-horizontal">
                            <!-- Adopter -->
                            <div class="party-horizontal adopter">
                                <img src="<?php echo !empty($request['AdopterPhoto']) ? htmlspecialchars($request['AdopterPhoto']) : 'uploads/profile_icon.png'; ?>"
                                    class="party-avatar-horizontal" alt="Adopter">
                                <div class="party-info-horizontal">
                                    <span class="party-name-horizontal"><?php echo htmlspecialchars($request['AdopterName']); ?></span>
                                    <span class="party-role-horizontal">Pet Adopter</span>
                                    <div class="party-contact-horizontal">
                                        <a href="mailto:<?php echo htmlspecialchars($request['AdopterEmail']); ?>">
                                            <span>📧</span> Email
                                        </a>
                                        <span>📞 <?php echo htmlspecialchars($request['AdopterPhone']); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Owner -->
                            <div class="party-horizontal owner">
                                <img src="<?php echo !empty($request['OwnerPhoto']) ? htmlspecialchars($request['OwnerPhoto']) : 'uploads/profile_icon.png'; ?>"
                                    class="party-avatar-horizontal" alt="Owner">
                                <div class="party-info-horizontal">
                                    <span class="party-name-horizontal"><?php echo htmlspecialchars($request['OwnerName']); ?></span>
                                    <span class="party-role-horizontal">Pet Owner</span>
                                    <div class="party-contact-horizontal">
                                        <a href="mailto:<?php echo htmlspecialchars($request['OwnerEmail']); ?>">
                                            <span>📧</span> Email
                                        </a>
                                        <span>📞 <?php echo htmlspecialchars($request['OwnerPhone']); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div> <!-- Tutup compact-layout -->

    </div> <!-- Tutup main-content -->

    <!-- Approve Modal -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Approve Adoption Request</h3>
                <span class="close" onclick="closeApproveModal()">&times;</span>
            </div>
           
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <div class="form-group">
                    <p>Are you sure you want to approve this adoption request?</p>
                    <p><strong>This will:</strong></p>
                    <ul>
                        <li>Mark the adoption request as <strong>Approved</strong></li>
                        <li>Change the pet status to <strong>Adopted</strong></li>
                        <li>Notify both adopter and owner</li>
                        <li><strong>This action cannot be undone</strong></li>
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
          
            <form method="POST">
                <input type="hidden" name="action" value="reject">
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

    <script>
        // Tab functionality
        function openTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            event.currentTarget.classList.add('active');

            // Scroll to top of tab content
            document.getElementById(tabName + '-tab').scrollTop = 0;
        }

        // Accordion functionality
        document.querySelectorAll('.accordion-header').forEach(header => {
            header.addEventListener('click', () => {
                const accordion = header.parentElement;
                accordion.classList.toggle('active');
            });
        });

        // Modal functions
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

        // Auto-hide success message
        setTimeout(() => {
            const successMsg = document.querySelector('.success-message');
            if (successMsg) successMsg.style.display = 'none';
        }, 5000);

        // Auto-open first accordion
        document.addEventListener('DOMContentLoaded', function() {
            const firstAccordion = document.querySelector('.accordion');
            if (firstAccordion) {
                firstAccordion.classList.add('active');
            }
        });
    </script>
</body>

</html>