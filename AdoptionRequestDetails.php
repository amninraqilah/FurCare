<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];
$requestID = $_GET['request_id'] ?? 0;

// Ambil data user untuk profile picture
$user_query = "SELECT * FROM user WHERE UserID = '$userID'";
$user_result = mysqli_query($conn, $user_query);
$user = mysqli_fetch_assoc($user_result);


// Fetch adoption request details
$stmt = $conn->prepare("SELECT ar.*, p.Name as PetName, p.Image as PetImage, p.Type as PetType, 
                        p.Breed as PetBreed, p.Age as PetAge, p.Gender as PetGender, p.Description as PetDescription,
                        u.Name as OwnerName, u.Email as OwnerEmail, u.Phone as OwnerPhone,
                        owner.Name as AdopterName
                        FROM AdoptionRequest ar
                        JOIN pet p ON ar.PetID = p.PetID
                        JOIN user u ON ar.OwnerID = u.UserID
                        JOIN user owner ON ar.AdopterID = owner.UserID
                        WHERE ar.RequestID = ? AND ar.AdopterID = ?");
$stmt->bind_param("ii", $requestID, $userID);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();

if (!$request) {
    header("Location: myAdoptionRequests.php?error=Request not found");
    exit;
}

// Function to format age from float to readable format
function formatAge($ageYears) {
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
    <title>Adoption Request Details - FurCare</title>
    <link rel="stylesheet" href="css/adoptionRequestDetails.css">
    <link rel="stylesheet" href="css/userDashboard.css">
</head>

<body>
    <!-- Navbar -->
    <div class="navbar">
        <h2 class="logo">FurCare</h2>
        <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
            alt="Profile"
            class="profile-icon">
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
        <a href="myApplications.php">📋 My Requests</a>
        <a href="logout.php" class="logout">🚪 Logout</a>
    </div>

    <div class="overlay" onclick="toggleSidebar()"></div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="details-container">
            <h1 class="page-title">Adoption Request Details</h1>

            <div class="details-grid">
                <!-- Pet Information -->
                <div class="pet-card">
                    <img src="<?php echo htmlspecialchars($request['PetImage']); ?>"
                        alt="<?php echo htmlspecialchars($request['PetName']); ?>"
                        class="pet-image">
                    <h3 class="pet-name"><?php echo htmlspecialchars($request['PetName']); ?></h3>
                    <p><strong>Type:</strong> <?php echo htmlspecialchars($request['PetType']); ?></p>
                    <p><strong>Breed:</strong> <?php echo htmlspecialchars($request['PetBreed']); ?></p>
                    <p><strong>Age:</strong> <?php echo formatAge($request['PetAge']); ?></p>
                    <p><strong>Gender:</strong> <?php echo htmlspecialchars($request['PetGender']); ?></p>
                </div>

                <!-- Request Information -->
                <div class="info-card">
                    <h3 class="section-title">Request Information</h3>

                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Request ID</span>
                            <span class="info-value">#<?php echo $request['RequestID']; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Status</span>
                            <span class="status-badge status-<?php echo $request['Status']; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $request['Status'])); ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Request Date</span>
                            <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($request['RequestDate'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Last Updated</span>
                            <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($request['UpdatedAt'])); ?></span>
                        </div>
                    </div>

                    <h4 class="section-title">Your Information</h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Full Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($request['AdopterName']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Phone</span>
                            <span class="info-value"><?php echo htmlspecialchars($request['AdopterPhone']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Address</span>
                            <span class="info-value"><?php echo nl2br(htmlspecialchars($request['AdopterAddress'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Housing Type</span>
                            <span class="info-value"><?php echo ucfirst($request['HousingType']); ?></span>
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

                    <?php if (!empty($request['PetExperience'])): ?>
                        <div class="info-item" style="margin-bottom: 15px;">
                            <span class="info-label">Pet Experience</span>
                            <span class="info-value"><?php echo nl2br(htmlspecialchars($request['PetExperience'])); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="info-item" style="margin-bottom: 15px;">
                        <span class="info-label">Adoption Reason</span>
                        <span class="info-value"><?php echo nl2br(htmlspecialchars($request['AdoptionReason'])); ?></span>
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
                            <span class="info-value"><?php echo htmlspecialchars($request['OwnerEmail']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Phone</span>
                            <span class="info-value"><?php echo htmlspecialchars($request['OwnerPhone']); ?></span>
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
                        <a href="myApplications.php" class="btn btn-back">Back to Requests</a>

                        <?php if ($request['Status'] === 'pending'): ?>
                            <a href="cancelAdoptionRequest.php?request_id=<?php echo $request['RequestID']; ?>"
                                class="btn btn-cancel"
                                onclick="return confirm('Are you sure you want to cancel this adoption request?')">
                                Cancel Request
                            </a>
                        <?php endif; ?>

                        <?php if ($request['Status'] === 'approved'): ?>
                            <a href="mailto:<?php echo $request['OwnerEmail']; ?>"
                                class="btn btn-contact">
                                📧 Email Owner
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            document.body.classList.toggle('sidebar-open');
        }
    </script>
</body>

</html>