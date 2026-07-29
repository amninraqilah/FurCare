<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'connect.php';
$userID = $_SESSION['user_id'];

// Fetch user data with detailed statistics - UPDATED TO COMPREHENSIVE LOGIC
$stmt1 = $conn->prepare("
    SELECT u.*, 
           -- BREAKDOWN DETAILED TRANSACTIONS (COMPREHENSIVE):
           -- Sebagai Sitter: completed sitting requests (COMPREHENSIVE)
           (SELECT COUNT(DISTINCT SitRequestID) FROM petsitrequest 
            WHERE SitterID = u.UserID 
            AND (
                Status = 'completed' OR
                CompletionStatus = 'completed' OR
                (SitterCompleted = 1 AND OwnerConfirmed = 1)
            )) as sitting_as_sitter,
           
           -- Sebagai Owner: completed sitting requests (COMPREHENSIVE)  
           (SELECT COUNT(DISTINCT SitRequestID) FROM petsitrequest 
            WHERE OwnerID = u.UserID 
            AND (
                Status = 'completed' OR
                CompletionStatus = 'completed' OR
                (SitterCompleted = 1 AND OwnerConfirmed = 1)
            )) as sitting_as_owner,
           
           -- Sebagai Owner: approved adoption requests (giving pet for adoption)
           (SELECT COUNT(DISTINCT RequestID) FROM adoptionrequest 
            WHERE OwnerID = u.UserID AND Status = 'approved') as adoption_as_owner,
           
           -- Sebagai Adopter: approved adoption requests (receiving pet)
           (SELECT COUNT(DISTINCT RequestID) FROM adoptionrequest 
            WHERE AdopterID = u.UserID AND Status = 'approved') as adoption_as_adopter,
           
           -- TOTAL KESELURUHAN UNIQUE TRANSACTIONS (COMPREHENSIVE)
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
           ) as total_transactions,
           
           -- TRUST LEVEL BERDASARKAN TRANSAKSI (TANPA TRUST SCORE PERCENTAGE) - COMPREHENSIVE
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
           END as trust_level
    FROM user u 
    WHERE u.UserID = ?
");
$stmt1->bind_param("i", $userID);
$stmt1->execute();
$user = $stmt1->get_result()->fetch_assoc();
$stmt1->close();

// Initialize user variable if no data
if (!$user) {
    $user = [
        'Name' => '',
        'Email' => '',
        'Phone' => '',
        'ProfilePicture' => 'uploads/profile_icon.png',
        'total_transactions' => 0,
        'sitting_as_sitter' => 0,
        'sitting_as_owner' => 0,
        'adoption_as_owner' => 0,
        'adoption_as_adopter' => 0,
        'trust_level' => 'New User'
    ];
}

// Calculate transaction totals
$total_pet_sitting = $user['sitting_as_sitter'] + $user['sitting_as_owner'];
$total_adoption = $user['adoption_as_owner'] + $user['adoption_as_adopter'];

// Fetch user's reviews (EXCLUDE REVIEWS FROM SELF)
$reviews = [];
$stmt_reviews = $conn->prepare("
    SELECT r.*, u.Name as ReviewerName, u.ProfilePicture as ReviewerPicture 
    FROM review r 
    JOIN user u ON r.ReviewerID = u.UserID 
    WHERE r.SitterID = ? AND r.ReviewerID != ?
    ORDER BY r.CreatedAt DESC
");
$stmt_reviews->bind_param("ii", $userID, $userID);
$stmt_reviews->execute();
$reviews_result = $stmt_reviews->get_result();
while ($review = $reviews_result->fetch_assoc()) {
    $reviews[] = $review;
}
$stmt_reviews->close();

// Calculate average rating (EXCLUDE REVIEWS FROM SELF)
$avg_rating = 0;
if (count($reviews) > 0) {
    $total_rating = 0;
    foreach ($reviews as $review) {
        $total_rating += $review['Rating'];
    }
    $avg_rating = round($total_rating / count($reviews), 1);
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $profilePic = $user['ProfilePicture']; // Keep existing profile picture

    // Handle profile picture upload
    if (!empty($_FILES['profile_picture']['name']) && $_FILES['profile_picture']['error'] === 0) {
        $targetDir = 'uploads/';
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

        // Validate image file
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $fileType = mime_content_type($_FILES['profile_picture']['tmp_name']);

        if (in_array($fileType, $allowedTypes)) {
            $filename = 'profile_' . $userID . '_' . time() . '.' . pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $profilePic = $targetDir . $filename;

            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $profilePic)) {
                if (!empty($user['ProfilePicture']) && $user['ProfilePicture'] !== 'uploads/profile_icon.png' && file_exists($user['ProfilePicture'])) {
                    unlink($user['ProfilePicture']);
                }
            } else {
                $error = "Failed to upload profile picture. Please try again.";
            }
        } else {
            $error = "Invalid file type. Only JPG, PNG, GIF allowed.";
        }
    }

    // Check if email already exists (excluding current user)
    if (!isset($error)) {
        $checkEmail = $conn->prepare("SELECT UserID FROM user WHERE Email = ? AND UserID != ?");
        $checkEmail->bind_param("si", $email, $userID);
        $checkEmail->execute();
        $emailExists = $checkEmail->get_result()->fetch_assoc();
        $checkEmail->close();

        if ($emailExists) {
            $error = "Email already exists. Please use a different email.";
        }
    }

    // Update user info
    if (!isset($error)) {
        $stmt2 = $conn->prepare("UPDATE user SET Name = ?, Email = ?, Phone = ?, ProfilePicture = ? WHERE UserID = ?");
        $stmt2->bind_param("ssssi", $name, $email, $phone, $profilePic, $userID);

        if ($stmt2->execute()) {
            $_SESSION['profile_picture'] = $profilePic;
            $user['ProfilePicture'] = $profilePic;

            $success = "Profile updated successfully!";
            $user['Name'] = $name;
            $user['Email'] = $email;
            $user['Phone'] = $phone;
            $user['ProfilePicture'] = $profilePic;

            $_SESSION['username'] = $name;
            $_SESSION['profile_picture'] = $profilePic;

            // Refresh the page to show updated data
            header("Location: userProfile.php");
            exit;
        } else {
            $error = "Failed to update profile. Please try again.";
        }
        $stmt2->close();
    }
}

// Handle remove profile picture
if (isset($_GET['remove_picture'])) {
    $stmt3 = $conn->prepare("UPDATE user SET ProfilePicture = 'uploads/profile_icon.png' WHERE UserID = ?");
    $stmt3->bind_param("i", $userID);
    $stmt3->execute();
    $stmt3->close();

    if (!empty($user['ProfilePicture']) && $user['ProfilePicture'] !== 'uploads/profile_icon.png' && file_exists($user['ProfilePicture'])) {
        unlink($user['ProfilePicture']);
    }

    $_SESSION['profile_picture'] = 'uploads/profile_icon.png';
    $user['ProfilePicture'] = 'uploads/profile_icon.png';

    header("Location: userProfile.php?success=Profile picture removed");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Profile - FurCare</title>
    <link rel="stylesheet" href="css/userDashboard.css">
    <link rel="stylesheet" href="css/userProfile.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Trust Level Badge Styles */
        .trust-level-badge {
            background: linear-gradient(135deg, #ffffff 0%, #f8fff8 100%);
            border-radius: 12px;
            padding: 15px;
            margin: 20px 0;
            border: 2px solid #e8f5e8;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .trust-level-badge .badge-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5em;
            color: white;
        }

        .trust-level-badge .badge-info {
            flex: 1;
        }

        .trust-level-badge .badge-label {
            font-size: 0.8em;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-bottom: 3px;
        }

        .trust-level-badge .badge-level {
            font-size: 1.1em;
            font-weight: 600;
            color: #2d3748;
        }

        /* Trust Level Colors */
        .trust-level-badge.administrator .badge-icon {
            background: linear-gradient(135deg, #d4a5a5, #e8c4c4);
        }

        .trust-level-badge.highly-trusted .badge-icon {
            background: linear-gradient(135deg, #ffeb3b, #ffd54f);
        }

        .trust-level-badge.trusted .badge-icon {
            background: linear-gradient(135deg, #81c784, #a5d6a7);
        }

        .trust-level-badge.verified .badge-icon {
            background: linear-gradient(135deg, #4fc3f7, #81d4fa);
        }

        .trust-level-badge.new-user .badge-icon {
            background: linear-gradient(135deg, #bdbdbd, #e0e0e0);
        }

        /* Stat Card Update - Remove Trust Score Percentage */
        .stat-card .stat-info h3 {
            margin-bottom: 10px;
            color: #2d3748;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .score-display {
            margin-bottom: 5px;
        }

        .stat-card .score {
            font-size: 1.8em;
            font-weight: 700;
            background: linear-gradient(135deg, #ec407a, #ff80ab);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-card .progress-bar {
            display: none;
            /* Remove progress bar */
        }

        .stat-card p {
            margin-top: 5px;
            color: #718096;
            font-size: 0.85em;
        }

        .stat-card .stat-number {
            font-size: 1.8em;
            font-weight: 700;
            background: linear-gradient(135deg, #ec407a, #ff80ab);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 5px;
        }
    </style>
    <script>
        function toggleSidebar() {
            document.body.classList.toggle('sidebar-open');
        }
    </script>
</head>

<body>
    <!-- Navbar -->
    <div class="navbar">
        <h2 class="logo">FurCare</h2>
        <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
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
        <a href="userProfile.php" class="active">👤 My Profile</a>
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
        <!-- CREATIVE HEADER SECTION -->
        <div class="profile-header">
            <h1>My Profile</h1>
            <p>Manage your personal information and profile settings</p>
        </div>

        <div class="profile-layout">
            <!-- Left Column - Profile Info & Stats -->
            <div class="profile-left">
                <div class="profile-card">
                    <div class="profile-picture-section">
                        <img src="<?php echo !empty($user['ProfilePicture']) ? htmlspecialchars($user['ProfilePicture']) : 'uploads/profile_icon.png'; ?>"
                            alt="Profile Picture" class="current-profile-pic" id="profile-pic-left">

                        <!-- Hanya info, bukan form -->
                        <div class="profile-picture-info" style="text-align: center; margin-top: 10px;">
                            <p style="color: #666; font-size: 0.9em;">
                                <i class="fas fa-info-circle"></i>
                                Change photo in the form on the right
                            </p>

                            <?php if (!empty($user['ProfilePicture']) && $user['ProfilePicture'] !== 'uploads/profile_icon.png'): ?>
                                <a href="?remove_picture=1" class="remove-picture"
                                    onclick="return confirm('Are you sure you want to remove your profile picture?')">
                                    <i class="fas fa-trash"></i> Remove Picture
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Trust Level Badge (Kekal) -->
                    <div class="trust-level-badge <?php echo strtolower(str_replace(' ', '-', $user['trust_level'])); ?>">
                        <div class="badge-icon">
                            <?php
                            $badgeIcons = [
                                'Administrator' => 'fas fa-crown',
                                'Highly Trusted' => 'fas fa-gem',
                                'Trusted' => 'fas fa-award',
                                'Verified' => 'fas fa-check-circle',
                                'New User' => 'fas fa-seedling'
                            ];
                            $badgeIcon = $badgeIcons[$user['trust_level']] ?? 'fas fa-user';
                            ?>
                            <i class="<?php echo $badgeIcon; ?>"></i>
                        </div>
                        <div class="badge-info">
                            <span class="badge-label">Trust Level</span>
                            <span class="badge-level"><?php echo $user['trust_level']; ?></span>
                        </div>
                    </div>

                    <div class="user-stats">
                        <!-- Total Transactions Card -->
                        <div class="stat-card">
                            <div class="stat-icon transactions">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                            <div class="stat-info">
                                <h3>Total Transactions</h3>
                                <div class="stat-number"><?php echo $user['total_transactions']; ?></div>
                                <p>Completed pet services</p>
                            </div>
                        </div>

                        <!-- Pet Sitting Card -->
                        <div class="stat-card">
                            <div class="stat-icon pet-sitting">
                                <i class="fas fa-home"></i>
                            </div>
                            <div class="stat-info">
                                <h3>Pet Sitting Jobs</h3>
                                <div class="stat-number"><?php echo $total_pet_sitting; ?></div>
                                <p><?php echo $user['sitting_as_sitter']; ?> as sitter, <?php echo $user['sitting_as_owner']; ?> as owner</p>
                            </div>
                        </div>

                        <!-- Adoption Card -->
                        <div class="stat-card">
                            <div class="stat-icon adoption">
                                <i class="fas fa-heart"></i>
                            </div>
                            <div class="stat-info">
                                <h3>Adoption Transactions</h3>
                                <div class="stat-number"><?php echo $total_adoption; ?></div>
                                <p><?php echo $user['adoption_as_owner']; ?> given, <?php echo $user['adoption_as_adopter']; ?> received</p>
                            </div>
                        </div>

                        <!-- Rating Card -->
                        <div class="stat-card">
                            <div class="stat-icon rating">
                                <i class="fas fa-star"></i>
                            </div>
                            <div class="stat-info">
                                <h3>Average Rating</h3>
                                <div class="rating-display">
                                    <span class="rating-number"><?php echo $avg_rating; ?></span>
                                    <div class="stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= round($avg_rating) ? 'active' : ''; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                <p>Based on <?php echo count($reviews); ?> reviews</p>
                            </div>
                        </div>
                    </div>

                    <!-- Transaction Breakdown (Optional - boleh buang jika redundant) -->
                    <div class="transaction-breakdown">
                        <h3><i class="fas fa-chart-pie"></i> Transaction Details</h3>
                        <div class="breakdown-grid">
                            <div class="breakdown-item">
                                <span class="breakdown-label">Pet Sitting as Sitter</span>
                                <span class="breakdown-count"><?php echo $user['sitting_as_sitter']; ?></span>
                            </div>
                            <div class="breakdown-item">
                                <span class="breakdown-label">Pet Sitting as Owner</span>
                                <span class="breakdown-count"><?php echo $user['sitting_as_owner']; ?></span>
                            </div>
                            <div class="breakdown-item">
                                <span class="breakdown-label">Adoptions Given</span>
                                <span class="breakdown-count"><?php echo $user['adoption_as_owner']; ?></span>
                            </div>
                            <div class="breakdown-item">
                                <span class="breakdown-label">Adoptions Received</span>
                                <span class="breakdown-count"><?php echo $user['adoption_as_adopter']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column - Profile Form & Reviews -->
            <div class="profile-right">
                <?php if (isset($success)): ?>
                    <div class="success-message">✅ <?php echo $success; ?></div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="error-message">❌ <?php echo $error; ?></div>
                <?php endif; ?>

                <div class="profile-form-card">
                    <h2><i class="fas fa-user-edit"></i> Edit Profile Information</h2>
                    <!-- SATU FORM UNTUK SEMUA DATA -->
                    <form method="POST" enctype="multipart/form-data">

                        <!-- Profile Picture Section -->
                        <div class="form-group">
                            <label><i class="fas fa-camera"></i> Profile Picture</label>
                            <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 15px;">
                                <img src="<?php echo !empty($user['ProfilePicture']) ? htmlspecialchars($user['ProfilePicture']) : 'uploads/profile_icon.png'; ?>"
                                    alt="Current Profile"
                                    id="current-profile-preview"
                                    style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0;">

                                <div style="flex: 1;">
                                    <label class="upload-btn" style="display: inline-block; margin-bottom: 10px; cursor: pointer;">
                                        <i class="fas fa-camera"></i> Change Profile Photo
                                        <input type="file" name="profile_picture" id="profile_picture"
                                            accept="image/*" style="display:none;" onchange="showFileName(this)">
                                    </label>
                                    <div id="file-name" class="file-name">No file chosen</div>

                                    <?php if (!empty($user['ProfilePicture']) && $user['ProfilePicture'] !== 'uploads/profile_icon.png'): ?>
                                        <a href="?remove_picture=1" class="remove-picture"
                                            onclick="return confirm('Are you sure you want to remove your profile picture?')"
                                            style="font-size: 0.85em; margin-top: 5px; display: inline-block;">
                                            <i class="fas fa-trash"></i> Remove Picture
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="name"><i class="fas fa-user"></i> Full Name *</label>
                            <input type="text" name="name" id="name"
                                value="<?php echo htmlspecialchars($user['Name']); ?>"
                                placeholder="Enter your full name" required>
                        </div>

                        <div class="form-group">
                            <label for="email"><i class="fas fa-envelope"></i> Email Address *</label>
                            <input type="email" name="email" id="email"
                                value="<?php echo htmlspecialchars($user['Email']); ?>"
                                placeholder="Enter your email address" required>
                        </div>

                        <div class="form-group">
                            <label for="phone"><i class="fas fa-phone"></i> Phone Number *</label>
                            <input type="tel" name="phone" id="phone"
                                value="<?php echo htmlspecialchars($user['Phone'] ?? ''); ?>"
                                placeholder="e.g. 0123456789"
                                pattern="^0[0-9]{1,2}[0-9]{7,8}$"
                                title="Please enter a valid Malaysian phone number (e.g., 0123456789). No dashes or letters allowed."
                                oninput="this.value = this.value.replace(/[^\d]/g, '')"
                                required>
                            <small>Required for posting pets and contacting others.</small>
                        </div>

                        <button type="submit" class="btn primary">
                            <i class="fas fa-save"></i> Update Profile
                        </button>
                    </form>
                </div>


                <!-- Reviews Section -->
                <div class="reviews-section">
                    <div class="reviews-header">
                        <h2><i class="fas fa-comments"></i> Reviews & Feedback</h2>
                        <span class="reviews-count"><?php echo count($reviews); ?> reviews</span>
                    </div>

                    <?php if (count($reviews) > 0): ?>
                        <div class="reviews-list">
                            <?php foreach ($reviews as $review): ?>
                                <div class="review-card">
                                    <div class="review-header">
                                        <div class="reviewer-info">
                                            <img src="<?php echo htmlspecialchars($review['ReviewerPicture']); ?>"
                                                alt="<?php echo htmlspecialchars($review['ReviewerName']); ?>"
                                                class="reviewer-avatar">
                                            <div class="reviewer-details">
                                                <h4><?php echo htmlspecialchars($review['ReviewerName']); ?></h4>
                                                <span class="review-date">
                                                    <?php echo date('M j, Y', strtotime($review['CreatedAt'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="review-rating">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star <?php echo $i <= $review['Rating'] ? 'active' : ''; ?>"></i>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                    <div class="review-content">
                                        <p><?php echo htmlspecialchars($review['Comment']); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-reviews">
                            <i class="fas fa-comment-slash"></i>
                            <h3>No Reviews Yet</h3>
                            <p>You haven't received any reviews from other users yet. Complete more transactions to get feedback from the community.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($user['Phone'])): ?>
                    <div class="warning-box">
                        <h3><i class="fas fa-exclamation-triangle"></i> Phone Number Required</h3>
                        <p>You need to add your phone number to:</p>
                        <ul>
                            <li>Post new pets</li>
                            <li>Send adoption requests</li>
                            <li>Offer pet sitting services</li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function showFileName(input) {
            const fileNameDisplay = document.getElementById('file-name');
            if (input.files.length > 0) {
                fileNameDisplay.textContent = input.files[0].name;
            } else {
                fileNameDisplay.textContent = 'No file chosen';
            }
        }

        // Trigger file input when upload button is clicked
        document.querySelector('.upload-btn').addEventListener('click', function() {
            document.getElementById('profile_picture').click();
        });

        // Update profile pictures when new image is selected
        document.getElementById('profile_picture').addEventListener('change', function(e) {
            if (e.target.files && e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    // Update both profile picture and navbar icon
                    document.querySelector('.current-profile-pic').src = e.target.result;
                    document.querySelector('.profile-icon').src = e.target.result;
                }
                reader.readAsDataURL(e.target.files[0]);

                // Show file name
                showFileName(e.target);
            }
        });

        // Auto-hide success/error messages after 5 seconds
        setTimeout(() => {
            const messages = document.querySelectorAll('.success-message, .error-message');
            messages.forEach(msg => {
                if (msg.style.display !== 'none') {
                    msg.style.display = 'none';
                }
            });
        }, 5000);
    </script>
</body>

</html>