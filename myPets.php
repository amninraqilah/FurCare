<?php
session_start();
if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

include 'connect.php';

$userID = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'user';

// Ambil data user dari database (untuk paparkan profile picture)
$user_query = "SELECT * FROM user WHERE UserID = '$userID'";
$user_result = mysqli_query($conn, $user_query);
$user = mysqli_fetch_assoc($user_result);

// Handle delete pet
if (isset($_GET['delete_id'])) {
  $deleteID = intval($_GET['delete_id']);

  // Check if pet can be deleted
  $checkQuery = "SELECT * FROM pet WHERE PetID = ? AND OwnerID = ?";
  $checkStmt = $conn->prepare($checkQuery);
  $checkStmt->bind_param("ii", $deleteID, $userID);
  $checkStmt->execute();
  $petCheck = $checkStmt->get_result()->fetch_assoc();
  $checkStmt->close();

  if ($petCheck) {
    $canDelete = true;

    if ($petCheck['PostType'] === 'Adopt' && strtolower($petCheck['Status']) === 'adopted') {
      $canDelete = false;
      header("Location: myPets.php?error=Cannot delete adopted pet");
      exit;
    } elseif ($petCheck['PostType'] === 'Pet Sit') {
      // Check if there are ANY pet sit requests (not cancelled)
      // JANGAN exclude 'overdue' dari sini - kita check semua status kecuali cancelled
      $sitCheck = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM petsitrequest 
        WHERE PetID = ? 
        AND Status NOT IN ('cancelled')
      ");
      $sitCheck->bind_param("i", $deleteID);
      $sitCheck->execute();
      $sitResult = $sitCheck->get_result();
      $sitRow = $sitResult->fetch_assoc();
      $sitCheck->close();

      if ($sitRow['count'] > 0) {
        $canDelete = false;
        header("Location: myPets.php?error=Cannot delete pet with pet sitting requests");
        exit;
      }
    }

    if ($canDelete) {
      $stmt = $conn->prepare("DELETE FROM pet WHERE PetID = ? AND OwnerID = ?");
      $stmt->bind_param("ii", $deleteID, $userID);
      $stmt->execute();
      header("Location: myPets.php?deleted=1");
      exit;
    }
  }
}

// Function to format age from float to readable format
function formatAge($ageYears)
{
  if ($ageYears == 0 || $ageYears == '' || is_null($ageYears)) {
    return '';
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

// Fetch user's pets
$stmt = $conn->prepare("SELECT * FROM pet WHERE OwnerID = ? ORDER BY PetID DESC");
$stmt->bind_param("i", $userID);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <title>My Pets — FurCare</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="css/myPets.css">
  <link rel="stylesheet" href="css/userDashboard.css">
  <style>
    .btn-edit-disabled,
    .btn-delete-disabled {
      background: #cccccc !important;
      color: #888888 !important;
      cursor: not-allowed !important;
      opacity: 0.6 !important;
      pointer-events: none !important;
      border: none !important;
      padding: 8px 16px !important;
      border-radius: 4px !important;
      font-size: 14px !important;
      text-decoration: none !important;
      display: inline-block !important;
    }

    .btn-edit-disabled:hover,
    .btn-delete-disabled:hover {
      transform: none !important;
      box-shadow: none !important;
    }

    /* Tooltip for disabled buttons */
    .btn-edit-disabled[title],
    .btn-delete-disabled[title] {
      position: relative;
    }

    .btn-edit-disabled[title]::before,
    .btn-delete-disabled[title]::before {
      content: attr(title);
      position: absolute;
      bottom: -35px;
      left: 50%;
      transform: translateX(-50%);
      background: #333;
      color: white;
      padding: 6px 10px;
      border-radius: 6px;
      font-size: 11px;
      white-space: nowrap;
      opacity: 0;
      transition: opacity 0.3s;
      pointer-events: none;
      z-index: 1000;
      font-weight: 500;
    }

    .btn-edit-disabled[title]:hover::before,
    .btn-delete-disabled[title]:hover::before {
      opacity: 1;
    }

    .error-toast {
      background: #f8d7da;
      color: #721c24;
      border-left: 4px solid #f5c6cb;
    }

    .error-toast .toast-icon {
      color: #721c24;
    }

    .pet-card-disabled {
      position: relative;
    }
  </style>
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
    <a href="userProfile.php">👤 My Profile</a>
    <a href="index.php">🏠 Home</a>
    <a href="myPets.php" class="active">🐶 My Pets</a>
    <a href="addPetUser.php">➕ Post a Pet</a>
    <a href="ownerRequests.php">📩 My Pet Requests</a>
    <a href="myApplications.php">📋 My Requests</a>
    <a href="logout.php" class="logout">🚪 Logout</a>
  </div>

  <div class="overlay" onclick="toggleSidebar()"></div>

  <div class="main-content">
    <div class="container">
      <h1 class="title">My Pets</h1>
    </div>
    <div class="pets-container">
      <!-- Success Messages dengan Toast Notification -->
      <?php if (isset($_GET['added'])): ?>
        <div id="successToast" class="toast success">
          <div class="toast-content">
            <span class="toast-icon">✅</span>
            <span class="toast-message">Pet successfully added! Your pet is now listed.</span>
            <button class="toast-close" onclick="hideToast('successToast')">×</button>
          </div>
        </div>
        <script>
          setTimeout(() => {
            showToast('successToast');
          }, 500);
        </script>
      <?php endif; ?>

      <?php if (isset($_GET['pending'])): ?>
        <div id="pendingToast" class="toast warning">
          <div class="toast-content">
            <span class="toast-icon">⏳</span>
            <span class="toast-message">Pet successfully posted! Waiting for admin approval.</span>
            <button class="toast-close" onclick="hideToast('pendingToast')">×</button>
          </div>
        </div>
        <script>
          setTimeout(() => {
            showToast('pendingToast');
          }, 500);
        </script>
      <?php endif; ?>

      <?php if (isset($_GET['deleted'])): ?>
        <div id="deletedToast" class="toast success">
          <div class="toast-content">
            <span class="toast-icon">✅</span>
            <span class="toast-message">Pet successfully deleted.</span>
            <button class="toast-close" onclick="hideToast('deletedToast')">×</button>
          </div>
        </div>
        <script>
          setTimeout(() => {
            showToast('deletedToast');
          }, 500);
        </script>
      <?php endif; ?>

      <?php if (isset($_GET['updated'])): ?>
        <div id="updatedToast" class="toast success">
          <div class="toast-content">
            <span class="toast-icon">✅</span>
            <span class="toast-message">Pet successfully updated.</span>
            <button class="toast-close" onclick="hideToast('updatedToast')">×</button>
          </div>
        </div>
        <script>
          setTimeout(() => {
            showToast('updatedToast');
          }, 500);
        </script>
      <?php endif; ?>

      <?php if (isset($_GET['error'])): ?>
        <div id="errorToast" class="toast error-toast">
          <div class="toast-content">
            <span class="toast-icon">⚠️</span>
            <span class="toast-message"><?php echo htmlspecialchars($_GET['error']); ?></span>
            <button class="toast-close" onclick="hideToast('errorToast')">×</button>
          </div>
        </div>
        <script>
          setTimeout(() => {
            showToast('errorToast');
          }, 500);
        </script>
      <?php endif; ?>

      <?php if ($result->num_rows > 0): ?>
        <div class="pets-grid">
          <?php while ($pet = $result->fetch_assoc()): ?>
            <?php
            // Calculate Pet Sit details if applicable
            $sitInfo = '';
            $totalDays = 0;
            $totalAmount = 0;
            $isPastSit = false;
            $isOverdue = false;
            $badgeHtml = '';

            if ($pet['PostType'] === 'Pet Sit' && !empty($pet['SitStartDate']) && !empty($pet['SitEndDate'])) {
              $startDate = new DateTime($pet['SitStartDate']);
              $endDate = new DateTime($pet['SitEndDate']);
              $today = new DateTime();

              // FIXED: Include start date in calculation (add +1)
              $totalDays = $startDate->diff($endDate)->days + 1;

              $totalAmount = $totalDays * ($pet['Price'] ?? 0);

              // Check if pet sit period has ended (past end date)
              if ($today > $startDate) {
                $isPastSit = true;

                // Check if pet has overdue status in pet table
                if (strtolower($pet['Status']) === 'overdue') {
                  $isOverdue = true;
                } else {
                  // Additional check if period has passed
                  if ($today > $endDate) {
                    $isPastSit = true;
                  }
                }
              }

              $sitInfo = "
  <div class='sit-period-info'>
    <div class='period-dates'>
      " . date('M j', strtotime($pet['SitStartDate'])) . " - " . date('M j, Y', strtotime($pet['SitEndDate'])) . "
    </div>
    <div class='period-days'>
      {$totalDays} days • Total: RM " . number_format($totalAmount, 2) . "
    </div>
    {$badgeHtml}
  </div>";
            }

            // Check if pet can be edited/deleted
            $canEdit = true;
            $canDelete = true;
            $editMessage = '';
            $deleteMessage = '';
            $isRestricted = false;

            // Check for Adoption posts
            if ($pet['PostType'] === 'Adopt' && strtolower($pet['Status']) === 'adopted') {
              $canEdit = false;
              $canDelete = false;
              $editMessage = 'Cannot edit adopted pet';
              $deleteMessage = 'Cannot delete adopted pet';
              $isRestricted = true;
            }
            // Check for Pet Sit posts
            elseif ($pet['PostType'] === 'Pet Sit') {
              // Check if pet sit has ANY requests (not cancelled)
              $petID = $pet['PetID'];
              $sitCheck = $conn->prepare("
                SELECT COUNT(*) as count, Status 
                FROM petsitrequest 
                WHERE PetID = ? 
                AND Status NOT IN ('cancelled')
                LIMIT 1
              ");
              $sitCheck->bind_param("i", $petID);
              $sitCheck->execute();
              $sitResult = $sitCheck->get_result();
              $sitRow = $sitResult->fetch_assoc();
              $sitCheck->close();

              if ($sitRow['count'] > 0) {
                // ADA requests - check status untuk edit/delete rules
                $currentStatus = $sitRow['Status'] ?? 'active';

                // DELETE RULE: Jika ada apa-apa request (selain cancelled) → Tak boleh delete
                $canDelete = false;
                $deleteMessage = 'Cannot delete pet with pet sitting requests';
                $isRestricted = true;

                // EDIT RULES berdasarkan status
                if ($isPastSit || $isOverdue) {
                  // Jika period dah lepas atau overdue → Tak boleh edit
                  $canEdit = false;
                  $editMessage = 'This pet sitting listing has ended. Cannot edit.';
                } elseif ($currentStatus === 'approved' || $currentStatus === 'pending') {
                  // Jika approved/pending → Tak boleh edit
                  $canEdit = false;
                  $editMessage = 'Cannot edit pet with ' . $currentStatus . ' sitting requests';
                } elseif ($currentStatus === 'completed') {
                  // Jika completed tapi period belum lepas → Limited edit
                  if (!$isPastSit) {
                    $editMessage = 'Limited editing available for active completed pet sitting';
                  } else {
                    $canEdit = false;
                    $editMessage = 'This pet sitting listing has ended. Cannot edit.';
                  }
                } elseif ($currentStatus === 'overdue') {
                  $canEdit = false;
                  $editMessage = 'Cannot edit pet with overdue sitting requests';
                }
              } else {
                // TAK ADA requests - check period sahaja
                if ($isPastSit || $isOverdue) {
                  // Period dah lepas tanpa requests → Tak boleh edit, boleh delete
                  $canEdit = false;
                  $editMessage = 'This pet sitting listing has ended. Cannot edit.';
                  $deleteMessage = 'Can delete - no active sitting requests';
                  $isRestricted = true;
                } else {
                  // Period belum lepas, tak ada requests → Boleh edit & delete
                  $editMessage = 'Can edit - no active sitting requests';
                  $deleteMessage = 'Can delete - no active sitting requests';
                }
              }
            }
            ?>

            <div class="pet-card <?php echo $pet['PostType'] === 'Pet Sit' ? 'pet-sit-card' : 'regular-pet-card'; ?><?php echo $isRestricted ? ' pet-card-disabled' : ''; ?>">
              <!-- Post Type Indicator -->
              <div class="post-type-indicator post-type-<?php echo strtolower(str_replace(' ', '', $pet['PostType'])); ?>">
                <?php echo htmlspecialchars($pet['PostType']); ?>
                <?php if ($pet['PostType'] === 'Pet Sit' && !empty($pet['Price'])): ?>
                  <span class="price-badge">RM<?php echo number_format($pet['Price'], 2); ?>/day</span>
                <?php endif; ?>
              </div>

              <!-- Approval Status -->
              <div class="approval-status status-<?php echo strtolower($pet['ApprovalStatus']); ?>">
                <?php echo htmlspecialchars($pet['ApprovalStatus']); ?>
              </div>

              <?php if (!empty($pet['Image'])): ?>
                <img src="<?php echo htmlspecialchars($pet['Image']); ?>" alt="<?php echo htmlspecialchars($pet['Name']); ?>" class="pet-image">
              <?php else: ?>
                <img src="assets/img/default-pet.jpg" alt="Default Pet Image" class="pet-image">
              <?php endif; ?>

              <div class="pet-name"><?php echo htmlspecialchars($pet['Name']); ?></div>

              <div class="pet-details">
                <div class="detail-row">
                  <span class="detail-label">Type:</span>
                  <span class="detail-value"><?php echo htmlspecialchars($pet['Type']); ?></span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Breed:</span>
                  <span class="detail-value"><?php echo htmlspecialchars($pet['Breed']); ?></span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Age:</span>
                  <span class="detail-value">
                    <?php
                    if (!empty($pet['Age']) && $pet['Age'] > 0) {
                      echo formatAge($pet['Age']);
                    } else {
                      echo 'Unknown';
                    }
                    ?>
                  </span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Gender:</span>
                  <span class="detail-value"><?php echo htmlspecialchars($pet['Gender']); ?></span>
                </div>
 
                <!-- Pet Sit Information -->
                <?php if ($pet['PostType'] === 'Pet Sit' && !empty($pet['Price'])): ?>
                  <div class="detail-row">
                    <span class="detail-label">Daily Rate:</span>
                    <span class="detail-value price-highlight">RM <?php echo number_format($pet['Price'], 2); ?></span>
                  </div>
                <?php endif; ?>

                <?php echo $sitInfo; ?>

                <div class="detail-row">
                  <span class="detail-label">Location:</span>
                  <span class="detail-value"><?php echo htmlspecialchars($pet['District'] . ', ' . $pet['State']); ?></span>
                </div>
              </div>

              <div class="pet-status status-<?php echo strtolower(str_replace(' ', '', $pet['Status'])); ?>">
                <?php echo htmlspecialchars($pet['Status']); ?>
              </div>

              <div class="pet-actions">
                <?php if ($canEdit): ?>
                  <a href="editPetUser.php?id=<?php echo $pet['PetID']; ?>" class="btn btn-edit">Edit</a>
                <?php else: ?>
                  <button class="btn btn-edit-disabled" title="<?php echo htmlspecialchars($editMessage); ?>">Edit</button>
                <?php endif; ?>

                <a href="petDetails.php?pet_id=<?php echo $pet['PetID']; ?>" class="btn btn-view">View</a>

                <?php if ($canDelete): ?>
                  <a href="myPets.php?delete_id=<?php echo $pet['PetID']; ?>" class="btn btn-delete"
                    onclick="return confirm('Are you sure you want to delete <?php echo htmlspecialchars(addslashes($pet['Name'])); ?>?')">
                    Delete
                  </a>
                <?php else: ?>
                  <button class="btn btn-delete-disabled" title="<?php echo htmlspecialchars($deleteMessage); ?>">Delete</button>
                <?php endif; ?>
              </div>
            </div>
          <?php endwhile; ?>
        </div>
      <?php else: ?>
        <div class="empty-state">
          <img src="assets/img/no-pets.png" alt="No Pets">
          <h3>No Pets Posted Yet</h3>
          <p>You haven't posted any pets yet. Start by adding your first pet!</p>
          <a href="addPetUser.php" class="btn-post-first">Post Your First Pet</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script>
    function toggleSidebar() {
      document.body.classList.toggle('sidebar-open');
    }

    // Toast Notification Functions
    function showToast(toastId) {
      const toast = document.getElementById(toastId);
      if (toast) {
        toast.style.display = 'block';

        // Auto hide after 5 seconds
        setTimeout(() => {
          hideToast(toastId);
        }, 5000);
      }
    }

    function hideToast(toastId) {
      const toast = document.getElementById(toastId);
      if (toast) {
        toast.classList.add('hide');
        setTimeout(() => {
          toast.style.display = 'none';
          toast.classList.remove('hide');
        }, 300);
      }
    }



    // Auto-hide all toasts on page load
    document.addEventListener('DOMContentLoaded', function() {
      // Show any toasts that should be visible
      const toasts = document.querySelectorAll('.toast');
      toasts.forEach(toast => {
        if (toast.style.display !== 'none') {
          setTimeout(() => {
            hideToast(toast.id);
          }, 5000);
        }
      });
    });

    // Close toast when clicking on close button or anywhere on toast
    document.addEventListener('click', function(e) {
      if (e.target.classList.contains('toast-close')) {
        const toast = e.target.closest('.toast');
        hideToast(toast.id);
      }
    });

    // Optional: Close toast when clicking anywhere on it
    document.addEventListener('click', function(e) {
      if (e.target.classList.contains('toast')) {
        hideToast(e.target.id);
      }
    });
  </script>
</body>

</html>