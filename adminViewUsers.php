<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$current_user_id = $_SESSION['user_id'];

// Dapatkan user ID dari URL
if (isset($_GET['user_id'])) {
    $profile_user_id = $_GET['user_id'];
} else {
    // Jika tidak ada user_id, redirect ke manageUsers.php
    header("Location: manageUsers.php");
    exit();
}

// Ambil data admin yang sedang login untuk navbar
$admin_stmt = $conn->prepare("SELECT Name, ProfilePicture FROM user WHERE UserID = ?");
$admin_stmt->bind_param("i", $current_user_id);
$admin_stmt->execute();
$admin_result = $admin_stmt->get_result();
$admin_data = $admin_result->fetch_assoc();

// Dapatkan user data dengan trust level dan rating purata - UPDATED
$user_sql = "SELECT u.*, 
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
                        -- Unique pet sitting transactions (COMPREHENSIVE)
                        (SELECT COUNT(DISTINCT SitRequestID) FROM petsitrequest 
                         WHERE (SitterID = u.UserID OR OwnerID = u.UserID) 
                         AND (
                             Status = 'completed' OR
                             CompletionStatus = 'completed' OR
                             (SitterCompleted = 1 AND OwnerConfirmed = 1)
                         ))
                        +
                        -- Unique adoption transactions
                        (SELECT COUNT(DISTINCT RequestID) FROM adoptionrequest 
                         WHERE (OwnerID = u.UserID OR AdopterID = u.UserID) 
                         AND Status = 'approved')
                    ) as total_unique_transactions,
                    
                    -- RATING PURATA DARIPADA REVIEWS - NEW
                    (SELECT COALESCE(AVG(Rating), 0) 
                     FROM review 
                     WHERE SitterID = u.UserID) as average_rating,
                    
                    -- BILANGAN REVIEWS - NEW
                    (SELECT COUNT(*) 
                     FROM review 
                     WHERE SitterID = u.UserID) as total_reviews,
                    
                    -- TRUST LEVEL BERDASARKAN TRANSAKSI - COMPREHENSIVE
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
             WHERE u.UserID = ?";
$stmt = $conn->prepare($user_sql);
$stmt->bind_param("i", $profile_user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user_data = $user_result->fetch_assoc();

if (!$user_data) {
    die("User not found");
}

// Dapatkan pets yang dimiliki oleh user ini
$pets_sql = "SELECT * FROM pet WHERE OwnerID = ? AND ApprovalStatus = 'Approved' ORDER BY PostDate DESC";
$stmt = $conn->prepare($pets_sql);
$stmt->bind_param("i", $profile_user_id);
$stmt->execute();
$pets_result = $stmt->get_result();

// Dapatkan reviews TENTANG user ini (orang lain review dia)
$reviews_sql = "SELECT r.*, 
                       u.Name as reviewer_name,
                       u.ProfilePicture as reviewer_image
                FROM review r 
                JOIN user u ON r.ReviewerID = u.UserID 
                WHERE r.SitterID = ?   -- User ini sebagai Sitter (yang direview)
                AND r.ReviewerID != ?  -- Pastikan reviewer bukan user ini sendiri
                ORDER BY r.CreatedAt DESC 
                LIMIT 10";
$stmt = $conn->prepare($reviews_sql);
$stmt->bind_param("ii", $profile_user_id, $profile_user_id);
$stmt->execute();
$reviews_result = $stmt->get_result();

// Get current logged in user's profile picture for navbar
$currentUserPicture = 'uploads/profile_icon.png'; // default
if (isset($admin_data['ProfilePicture']) && !empty($admin_data['ProfilePicture'])) {
    $currentUserPicture = $admin_data['ProfilePicture'];
}

$admin_name = isset($admin_data['Name']) ? $admin_data['Name'] : 'Admin';

// Calculate transaction summary
$total_pet_sitting = $user_data['sitting_as_sitter'] + $user_data['sitting_as_owner'];
$total_adoption = $user_data['adoption_as_owner'] + $user_data['adoption_as_adopter'];
$total_all_transactions = $user_data['total_unique_transactions'];

// Format rating untuk display
$average_rating = number_format($user_data['average_rating'], 1);
$total_reviews = $user_data['total_reviews'];

// Function untuk generate star rating
function generateStarRating($rating) {
    $output = '';
    $fullStars = floor($rating);
    $halfStar = ($rating - $fullStars) >= 0.5;
    $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
    
    // Full stars
    for ($i = 0; $i < $fullStars; $i++) {
        $output .= '<i class="fas fa-star filled"></i>';
    }
    
    // Half star
    if ($halfStar) {
        $output .= '<i class="fas fa-star-half-alt filled"></i>';
    }
    
    // Empty stars
    for ($i = 0; $i < $emptyStars; $i++) {
        $output .= '<i class="far fa-star"></i>';
    }
    
    return $output;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>View User - <?= htmlspecialchars($user_data['Name']) ?> • FurCare</title>
  <link rel="stylesheet" href="css/adminDashboard.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    /* Additional styles for the profile view */
    .back-button {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: linear-gradient(135deg, #81c784, #a5d6a7);
      color: white;
      padding: 8px 16px;
      border-radius: 20px;
      text-decoration: none;
      font-weight: 500;
      margin-bottom: 20px;
      box-shadow: 0 2px 8px rgba(129, 199, 132, 0.3);
      transition: all 0.3s ease;
      border: 2px solid transparent;
      font-size: 0.9em;
    }

    .back-button:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(129, 199, 132, 0.4);
      background: linear-gradient(135deg, #66bb6a, #81c784);
    }

    /* Profile Header */
    .profile-header {
      background: linear-gradient(135deg, #ffffff 0%, #f8fff8 100%);
      border-radius: 16px;
      padding: 25px;
      box-shadow: 0 10px 25px rgba(139, 195, 74, 0.1), 0 4px 15px rgba(236, 64, 122, 0.1);
      display: flex;
      align-items: center;
      gap: 25px;
      margin-bottom: 25px;
      border: 2px solid #e8f5e8;
      position: relative;
      overflow: hidden;
    }

    .profile-header::before {
      content: '';
      position: absolute;
      top: 0;
      right: 0;
      width: 120px;
      height: 120px;
      background: linear-gradient(135deg, #ec407a 0%, #ffcdd2 100%);
      border-radius: 0 0 0 100px;
      opacity: 0.08;
    }

    .profile-header::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0;
      width: 100px;
      height: 100px;
      background: linear-gradient(135deg, #81c784 0%, #c8e6c9 100%);
      border-radius: 0 100px 0 0;
      opacity: 0.08;
    }

    .profile-picture {
      width: 120px;
      height: 120px;
      border-radius: 50%;
      object-fit: cover;
      border: 4px solid white;
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
      position: relative;
      z-index: 2;
      transition: transform 0.3s ease;
    }

    .profile-picture:hover {
      transform: scale(1.02);
    }

    .profile-info {
      flex: 1;
      position: relative;
      z-index: 2;
    }

    .profile-name {
      font-size: 1.6em;
      font-weight: 600;
      background: linear-gradient(135deg, #2e7d32, #ec407a);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
    }

    .profile-contact {
      display: flex;
      gap: 20px;
      margin-bottom: 15px;
      font-size: 0.9em;
    }

    .profile-email,
    .profile-phone {
      color: #4a5568;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .profile-email i,
    .profile-phone i {
      color: #ec407a;
      font-size: 1em;
    }

    /* User Rating - NEW */
    .user-rating-container {
      margin: 15px 0;
      padding: 12px 16px;
      background: linear-gradient(135deg, #fff8e1 0%, #fff 100%);
      border-radius: 12px;
      border: 2px solid #ffecb3;
      box-shadow: 0 4px 12px rgba(255, 193, 7, 0.1);
    }

    .user-rating {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .user-rating-stars {
      display: flex;
      gap: 3px;
    }

    .user-rating-stars .fas {
      color: #ffb400;
      font-size: 1.1em;
    }

    .user-rating-stars .far {
      color: #e2e8f0;
      font-size: 1.1em;
    }

    .user-rating-stars .fa-star-half-alt {
      color: #ffb400;
      font-size: 1.1em;
    }

    .user-rating-number {
      font-size: 1.1em;
      font-weight: 700;
      color: #2d3748;
      background: linear-gradient(135deg, #ffb400, #ffd54f);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .user-rating-count {
      font-size: 0.85em;
      color: #718096;
      margin-left: 5px;
    }

    .user-rating-count strong {
      color: #2d3748;
      font-weight: 600;
    }

    .no-rating {
      color: #a0aec0;
      font-style: italic;
      font-size: 0.9em;
    }

    /* Trust Badge */
    .trust-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 5px 12px;
      border-radius: 50px;
      font-size: 0.75em;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.2px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
      border: 1px solid transparent;
      color: white !important;
      -webkit-text-fill-color: white !important;
    }

    .trust-badge.highly-trusted {
      background: linear-gradient(135deg, #ffeb3b, #ffd54f);
      border-color: #ffd54f;
    }

    .trust-badge.trusted {
      background: linear-gradient(135deg, #81c784, #a5d6a7);
      border-color: #81c784;
    }

    .trust-badge.verified {
      background: linear-gradient(135deg, #4fc3f7, #81d4fa);
      border-color: #4fc3f7;
    }

    .trust-badge.new-user {
      background: linear-gradient(135deg, #bdbdbd, #e0e0e0);
      border-color: #bdbdbd;
    }

    .trust-badge.administrator {
      background: linear-gradient(135deg, #d4a5a5, #e8c4c4);
      border-color: #c99595;
    }

    /* Transaction Stats */
    .transaction-stats {
      background: linear-gradient(135deg, #e8f5e8 0%, #c8e6c9 100%);
      padding: 16px;
      border-radius: 12px;
      margin: 16px 0;
      border: 2px solid #a5d6a7;
      box-shadow: 0 4px 12px rgba(129, 199, 132, 0.12);
    }

    .transaction-stats h3 {
      margin-bottom: 12px;
      font-size: 1.1em;
      color: #2e7d32;
      text-align: center;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      font-weight: 500;
    }

    .transaction-stats h3 i {
      color: #ec407a;
      font-size: 1em;
    }

    .stat-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin: 8px 0;
      padding: 6px 0;
      border-bottom: 1px dashed #a5d6a7;
      font-size: 0.85em;
    }

    .stat-label {
      font-weight: 500;
      color: #388e3c;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .stat-value {
      font-weight: 600;
      background: linear-gradient(135deg, #ec407a, #ff80ab);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    /* Pets Section */
    .pets-section {
      background: linear-gradient(135deg, #fff5f5 0%, #fff 100%);
      border-radius: 16px;
      padding: 25px;
      box-shadow: 0 10px 25px rgba(236, 64, 122, 0.06);
      border: 2px solid #ffcdd2;
      position: relative;
      overflow: hidden;
      margin-bottom: 25px;
    }

    .pets-section h2 {
      font-size: 1.5em;
      font-weight: 600;
      background: linear-gradient(135deg, #ec407a, #ff80ab);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-bottom: 20px;
      text-align: center;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }

    .pets-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
      gap: 16px;
    }

    .pet-card {
      background: linear-gradient(135deg, #ffffff 0%, #f8fff8 100%);
      border-radius: 12px;
      padding: 16px;
      text-align: center;
      transition: all 0.3s ease;
      border: 2px solid #e8f5e8;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
      position: relative;
      overflow: hidden;
    }

    .pet-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 2px;
    }

    .pet-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 18px rgba(236, 64, 122, 0.1);
    }

    .pet-image {
      width: 100%;
      height: 150px;
      object-fit: cover;
      border-radius: 10px;
      margin-bottom: 12px;
      border: 2px solid #e8f5e8;
      transition: border-color 0.3s ease;
    }

    .pet-card h3 {
      font-size: 1.1em;
      color: #2d3748;
      margin-bottom: 8px;
      font-weight: 500;
    }

    .pet-type {
      color: #718096;
      margin-bottom: 10px;
      font-size: 0.85em;
      font-weight: 400;
    }

    .pet-status {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 4px 10px;
      border-radius: 12px;
      font-size: 0.7em;
      font-weight: 500;
      margin-bottom: 12px;
      text-transform: uppercase;
      letter-spacing: 0.2px;
    }

    .pet-status.available {
      background: linear-gradient(135deg, #c8e6c9, #e8f5e8);
      color: #2e7d32;
      border: 1px solid #a5d6a7;
    }

    .pet-status.adopted {
      background: linear-gradient(135deg, #ffcdd2, #fce4ec);
      color: #c2185b;
      border: 1px solid #f48fb1;
    }

    .pet-status.pending {
      background: linear-gradient(135deg, #fff9c4, #fffde7);
      color: #f57f17;
      border: 1px solid #ffd54f;
    }

    .view-details-btn {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      background: linear-gradient(135deg, #ec407a, #ff80ab);
      color: white;
      padding: 8px 16px;
      border-radius: 18px;
      text-decoration: none;
      font-weight: 500;
      transition: all 0.3s ease;
      box-shadow: 0 2px 8px rgba(236, 64, 122, 0.2);
      font-size: 0.8em;
    }

    .view-details-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(236, 64, 122, 0.3);
      background: linear-gradient(135deg, #d81b60, #ec407a);
    }

    .no-pets {
      text-align: center;
      color: #718096;
      font-size: 0.95em;
      padding: 30px 16px;
      background: linear-gradient(135deg, #f8fff8, #fff5f5);
      border-radius: 12px;
      border: 2px dashed #c8e6c9;
    }

    .no-pets i {
      font-size: 1.8em;
      color: #ec407a;
      margin-bottom: 8px;
      display: block;
    }

    /* Reviews Section */
    .reviews-section {
      background: linear-gradient(135deg, #f0f8ff 0%, #fff 100%);
      border-radius: 16px;
      padding: 25px;
      box-shadow: 0 10px 25px rgba(64, 164, 236, 0.06);
      border: 2px solid #b3e5fc;
      position: relative;
      overflow: hidden;
    }

    .reviews-section h2 {
      font-size: 1.5em;
      font-weight: 600;
      background: linear-gradient(135deg, #4fc3f7, #81d4fa);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-bottom: 20px;
      text-align: center;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }

    .reviews-container {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .review-card {
      background: linear-gradient(135deg, #ffffff 0%, #f8fdff 100%);
      border-radius: 12px;
      padding: 16px;
      border: 2px solid #e1f5fe;
      box-shadow: 0 3px 10px rgba(79, 195, 247, 0.08);
      transition: all 0.3s ease;
    }

    .review-card:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(79, 195, 247, 0.12);
      border-color: #4fc3f7;
    }

    .reviewer-info {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 12px;
    }

    .reviewer-avatar {
      width: 45px;
      height: 45px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid #b3e5fc;
    }

    .reviewer-details {
      flex: 1;
    }

    .reviewer-details h4 {
      font-size: 1em;
      color: #2d3748;
      margin-bottom: 4px;
      font-weight: 500;
    }

    .review-rating {
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .review-rating .fa-star {
      color: #e2e8f0;
      font-size: 0.85em;
    }

    .review-rating .fa-star.active {
      color: #ffb400;
    }

    .rating-number {
      font-size: 0.75em;
      color: #718096;
      margin-left: 4px;
    }

    .review-date {
      font-size: 0.75em;
      color: #a0aec0;
      white-space: nowrap;
    }

    .review-comment {
      color: #4a5568;
      line-height: 1.5;
      font-style: italic;
      padding-left: 8px;
      border-left: 2px solid #4fc3f7;
      font-size: 0.9em;
    }

    .review-comment.no-comment {
      color: #a0aec0;
      font-style: normal;
      border-left: 2px solid #e2e8f0;
    }

    .no-reviews {
      text-align: center;
      color: #718096;
      font-size: 0.95em;
      padding: 30px 16px;
      background: linear-gradient(135deg, #f8fdff, #f0f8ff);
      border-radius: 12px;
      border: 2px dashed #b3e5fc;
    }

    .no-reviews i {
      font-size: 1.8em;
      color: #4fc3f7;
      margin-bottom: 8px;
      display: block;
    }

    .show-more-container {
      text-align: center;
      margin-top: 16px;
    }

    .show-more-btn {
      background: linear-gradient(135deg, #4fc3f7, #81d4fa);
      color: white;
      border: none;
      padding: 10px 20px;
      border-radius: 20px;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 2px 8px rgba(79, 195, 247, 0.2);
      font-size: 0.85em;
    }

    .show-more-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(79, 195, 247, 0.3);
      background: linear-gradient(135deg, #29b6f6, #4fc3f7);
    }

    .profile-phone.no-phone {
      color: #a0aec0 !important;
      font-style: italic;
    }

    .profile-phone.no-phone i {
      color: #a0aec0 !important;
    }

    /* Content wrapper */
    .content-wrapper {
      padding: 20px;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .profile-header {
        flex-direction: column;
        text-align: center;
        padding: 20px 16px;
        gap: 16px;
      }

      .profile-name {
        font-size: 1.4em;
        justify-content: center;
      }

      .profile-picture {
        width: 100px;
        height: 100px;
      }

      .profile-contact {
        flex-direction: column;
        gap: 10px;
        align-items: center;
      }

      .pets-grid {
        grid-template-columns: 1fr;
      }

      .trust-badge {
        padding: 4px 10px;
        font-size: 0.65em;
      }

      .reviewer-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
      }

      .review-date {
        align-self: flex-end;
      }

      .reviews-section,
      .pets-section {
        padding: 20px 16px;
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
    <a href="manageUsers.php" class="active">👥 Manage Users</a>
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
      <h1><?= htmlspecialchars($user_data['Name']) ?>'s Profile</h1>
      <div class="profile-info-nav">
        <img src="<?= htmlspecialchars($currentUserPicture) ?>" 
             alt="Profile" 
             class="profile-icon">
      </div>
    </div>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
      <!-- Back Button -->
      <a href="manageUsers.php" class="back-button">
        <i class="fas fa-arrow-left"></i> Back to Manage Users
      </a>

      <!-- Profile Header -->
      <div class="profile-header">
        <img src="<?= htmlspecialchars($user_data['ProfilePicture']) ?>"
            alt="<?= htmlspecialchars($user_data['Name']) ?>"
            class="profile-picture">

        <div class="profile-info">
          <h1 class="profile-name">
            <?= htmlspecialchars($user_data['Name']) ?>

            <!-- TRUST BADGE -->
            <span class="trust-badge <?= strtolower(str_replace(' ', '-', $user_data['trust_level'])) ?>">
              <?= $user_data['trust_level'] ?>
            </span>
          </h1>

          <div class="profile-contact">
            <p class="profile-email">
              <i class="fas fa-envelope"></i>
              <?= htmlspecialchars($user_data['Email']) ?>
            </p>
            <p class="profile-phone <?= empty($user_data['Phone']) ? 'no-phone' : '' ?>">
              <i class="fas fa-phone"></i>
              <?= !empty($user_data['Phone']) ? htmlspecialchars($user_data['Phone']) : 'No phone number' ?>
            </p>
          </div>

          <!-- User Rating Section - NEW -->
          <div class="user-rating-container">
            <div class="user-rating">
              <?php if ($total_reviews > 0): ?>
                <div class="user-rating-stars">
                  <?= generateStarRating($user_data['average_rating']) ?>
                </div>
                <span class="user-rating-number"><?= $average_rating ?></span>
                <span class="user-rating-count">(<strong><?= $total_reviews ?></strong> review<?= $total_reviews != 1 ? 's' : '' ?>)</span>
              <?php else: ?>
                <span class="no-rating">
                  <i class="far fa-star"></i> No ratings yet
                </span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Transaction Statistics -->
          <div class="transaction-stats">
            <h3><i class="fas fa-chart-line"></i> Transaction History</h3>
            
            <!-- Total All Transactions -->
            <div class="stat-item">
              <span class="stat-label"><i class="fas fa-exchange-alt"></i> Total Completed Transactions:</span>
              <span class="stat-value"><?= $total_all_transactions ?></span>
            </div>
            
            <!-- Pet Sitting Breakdown -->
            <div class="stat-item">
              <span class="stat-label"><i class="fas fa-home"></i> Pet Sitting Jobs:</span>
              <span class="stat-value"><?= $total_pet_sitting ?> jobs</span>
            </div>
            
            <div class="stat-item" style="padding-left: 20px;">
              <span class="stat-label"><i class="fas fa-user-tie"></i> As Sitter:</span>
              <span class="stat-value"><?= $user_data['sitting_as_sitter'] ?></span>
            </div>
            
            <div class="stat-item" style="padding-left: 20px;">
              <span class="stat-label"><i class="fas fa-paw"></i> As Owner:</span>
              <span class="stat-value"><?= $user_data['sitting_as_owner'] ?></span>
            </div>
            
            <!-- Adoption Breakdown -->
            <div class="stat-item">
              <span class="stat-label"><i class="fas fa-heart"></i> Adoption Transactions:</span>
              <span class="stat-value"><?= $total_adoption ?> adoptions</span>
            </div>
            
            <div class="stat-item" style="padding-left: 20px;">
              <span class="stat-label"><i class="fas fa-gift"></i> Giving Pet:</span>
              <span class="stat-value"><?= $user_data['adoption_as_owner'] ?></span>
            </div>
            
            <div class="stat-item" style="padding-left: 20px;">
              <span class="stat-label"><i class="fas fa-hand-holding-heart"></i> Receiving Pet:</span>
              <span class="stat-value"><?= $user_data['adoption_as_adopter'] ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- User's Pets Section -->
      <div class="pets-section">
        <h2><i class="fas fa-paw"></i> User's Pets</h2>

        <?php if ($pets_result->num_rows > 0): ?>
          <div class="pets-grid">
            <?php while ($pet = $pets_result->fetch_assoc()): ?>
              <div class="pet-card">
                <img src="<?= htmlspecialchars($pet['Image']) ?>"
                    alt="<?= htmlspecialchars($pet['Name']) ?>"
                    class="pet-image">
                <h3><?= htmlspecialchars($pet['Name']) ?></h3>
                <p class="pet-type">
                  <i class="fas fa-<?= strtolower($pet['Type']) == 'cat' ? 'cat' : (strtolower($pet['Type']) == 'dog' ? 'dog' : 'paw') ?>"></i>
                  <?= htmlspecialchars($pet['Type']) ?> •
                  <?= htmlspecialchars($pet['Breed']) ?>
                </p>
                <p class="pet-status <?= strtolower($pet['Status']) ?>">
                  <i class="fas fa-<?= strtolower($pet['Status']) == 'available' ? 'check' : (strtolower($pet['Status']) == 'adopted' ? 'home' : 'clock') ?>"></i>
                  <?= htmlspecialchars($pet['Status']) ?>
                </p>
                <a href="adminPetDetails.php?pet_id=<?= $pet['PetID'] ?>"
                    class="view-details-btn">
                  <i class="fas fa-eye"></i> View Details
                </a>
              </div>
            <?php endwhile; ?>
          </div>
        <?php else: ?>
          <div class="no-pets">
            <i class="fas fa-paw"></i>
            <p>No pets listed yet.</p>
          </div>
        <?php endif; ?>
      </div>

      <!-- Reviews Section -->
      <div class="reviews-section">
        <h2><i class="fas fa-comment-dots"></i> User Reviews</h2>

        <?php if ($reviews_result->num_rows > 0): ?>
          <div class="reviews-container">
            <?php while ($review = $reviews_result->fetch_assoc()): ?>
              <div class="review-card">
                <div class="reviewer-info">
                  <img src="<?= htmlspecialchars($review['reviewer_image']) ?>"
                      alt="<?= htmlspecialchars($review['reviewer_name']) ?>"
                      class="reviewer-avatar">
                  <div class="reviewer-details">
                    <h4><?= htmlspecialchars($review['reviewer_name']) ?></h4>
                    <div class="review-rating">
                      <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fas fa-star <?= $i <= $review['Rating'] ? 'active' : '' ?>"></i>
                      <?php endfor; ?>
                      <span class="rating-number">(<?= $review['Rating'] ?>.0)</span>
                    </div>
                  </div>
                  <span class="review-date">
                    <?= date('M j, Y', strtotime($review['CreatedAt'])) ?>
                  </span>
                </div>
                <?php if (!empty($review['Comment'])): ?>
                  <p class="review-comment">"<?= htmlspecialchars($review['Comment']) ?>"</p>
                <?php else: ?>
                  <p class="review-comment no-comment">No comment provided</p>
                <?php endif; ?>
              </div>
            <?php endwhile; ?>
          </div>

          <!-- Show More Button jika banyak reviews -->
          <?php if ($reviews_result->num_rows >= 10): ?>
            <div class="show-more-container">
              <button class="show-more-btn">
                <i class="fas fa-chevron-down"></i> Show More Reviews
              </button>
            </div>
          <?php endif; ?>

        <?php else: ?>
          <div class="no-reviews">
            <i class="fas fa-comment-slash"></i>
            <p>No reviews yet.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- JavaScript for Show More Reviews -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const showMoreBtn = document.querySelector('.show-more-btn');
      if (showMoreBtn) {
        showMoreBtn.addEventListener('click', function() {
          alert('Show more reviews functionality would load more reviews here.');
          // You can implement AJAX to load more reviews
        });
      }
    });
  </script>
</body>
</html>

<?php
$conn->close();
?>