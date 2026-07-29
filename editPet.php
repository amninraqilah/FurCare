<?php
include 'connect.php';
session_start();

// TAMBAH INI: Define userID
$userID = $_SESSION['user_id'];

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];
$petID = $_GET['id'] ?? 0;

// Get unique pet types from database
$type_query = "SELECT DISTINCT Type FROM pet WHERE Type IS NOT NULL AND Type != '' ORDER BY Type";
$type_result = mysqli_query($conn, $type_query);
$pet_types = [];
while ($row = mysqli_fetch_assoc($type_result)) {
    $pet_types[] = $row['Type'];
}

// Fetch existing pet data dengan owner info - DIRECT QUERY
$pet_result = $conn->query("SELECT p.*, u.Name as OwnerName FROM pet p 
                           LEFT JOIN user u ON p.OwnerID = u.UserID 
                           WHERE p.PetID = $petID");
$pet = $pet_result->fetch_assoc();

if (!$pet) {
    header("Location: adminManagePets.php?error=Pet not found");
    exit;
}

// CHECK JIKA PET ADA OVERDUE REQUESTS (UNTUK ADMIN) - DIRECT QUERY
$overdue_result = $conn->query("SELECT COUNT(*) as overdue_count 
                               FROM PetSitRequest 
                               WHERE PetID = $petID AND Status = 'overdue'");
$overdue_data = $overdue_result->fetch_assoc();

// Calculate years and months from decimal age - FIXED VERSION
$currentAge = floatval($pet['Age']);
$totalMonths = round($currentAge * 12);
$currentYears = floor($totalMonths / 12);
$currentMonths = $totalMonths % 12;

// Initialize variables untuk form values
$formData = [
    'name' => $pet['Name'],
    'type' => $pet['Type'],
    'breed' => $pet['Breed'],
    'gender' => $pet['Gender'],
    'description' => $pet['Description'],
    'status' => $pet['Status'],
    'postType' => $pet['PostType'],
    'price' => $pet['Price'],
    'state' => $pet['State'],
    'district' => $pet['District'],
    'approval_status' => $pet['ApprovalStatus'],
    'sit_start_date' => $pet['SitStartDate'],
    'sit_end_date' => $pet['SitEndDate'],
    'latitude' => $pet['Latitude'],
    'longitude' => $pet['Longitude']
];

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // CHECK OVERDUE SEBELUM PROCESS UPDATE
    if ($overdue_data['overdue_count'] > 0) {
        header("Location: adminManagePets.php?error=Cannot edit pet with overdue requests");
        exit;
    }

    // Update formData dengan POST values
    $formData['name'] = $_POST['name'] ?? '';
    
    // Handle type - jika "Other" dipilih, gunakan input baru
    $type_input = $_POST['type'] ?? '';
    $type_other = $_POST['type_other'] ?? '';
    
    // Determine which type to use
    if ($type_input === 'Other' && !empty($type_other)) {
        $formData['type'] = $type_other;
    } else {
        $formData['type'] = $type_input;
    }
    
    $formData['breed'] = $_POST['breed'] ?? '';
    $formData['gender'] = $_POST['gender'] ?? '';
    $formData['description'] = $_POST['description'] ?? '';
    $formData['status'] = $_POST['status'] ?? '';
    $formData['postType'] = $_POST['postType'] ?? 'Adopt';
    $formData['price'] = $_POST['price'] ?? null;
    $formData['state'] = $_POST['state'] ?? '';
    $formData['district'] = $_POST['district'] ?? '';
    $formData['approval_status'] = $_POST['approval_status'] ?? 'pending';
    $formData['sit_start_date'] = $_POST['sit_start_date'] ?? null;
    $formData['sit_end_date'] = $_POST['sit_end_date'] ?? null;
    $formData['latitude'] = $_POST['latitude'] ?? $pet['Latitude'];
    $formData['longitude'] = $_POST['longitude'] ?? $pet['Longitude'];

    $name = $conn->real_escape_string($formData['name']);
    $type = $conn->real_escape_string($formData['type']);
    $breed = $conn->real_escape_string($formData['breed']);

    // FIXED: Calculate age from years and months
    $years = intval($_POST['years'] ?? 0);
    $months = intval($_POST['months'] ?? 0);

    // Ensure months is between 0-11
    $months = max(0, min(11, $months));
    $age = round($years + ($months / 12), 2);

    $gender = $conn->real_escape_string($formData['gender']);
    $description = $conn->real_escape_string($formData['description']);
    $status = $conn->real_escape_string($formData['status']);
    $postType = $conn->real_escape_string($formData['postType']);
    $price = $formData['price'] !== '' && $formData['price'] !== null ? floatval($formData['price']) : "NULL";
    $state = $conn->real_escape_string($formData['state']);
    $district = $conn->real_escape_string($formData['district']);
    $latitude = $formData['latitude'] !== '' ? floatval($formData['latitude']) : "NULL";
    $longitude = $formData['longitude'] !== '' ? floatval($formData['longitude']) : "NULL";
    $approvalStatus = $conn->real_escape_string($formData['approval_status']);

    // NEW: Pet Sit fields
    $sitStartDate = $formData['sit_start_date'] ?? '';
    $sitEndDate = $formData['sit_end_date'] ?? '';

    // Convert empty strings to NULL for database
    $sitStartDate = ($sitStartDate !== '') ? "'" . $conn->real_escape_string($sitStartDate) . "'" : "NULL";
    $sitEndDate = ($sitEndDate !== '') ? "'" . $conn->real_escape_string($sitEndDate) . "'" : "NULL";

    // Handle approval timestamps
    $approvedAt = $pet['ApprovedAt'];
    $approvedBy = $pet['ApprovedBy'];

    // Jika status berubah dari bukan-approved ke approved, set timestamp baru
    if ($approvalStatus === 'approved' && $pet['ApprovalStatus'] !== 'approved') {
        $approvedAt = "'" . date('Y-m-d H:i:s') . "'";
        $approvedBy = $userID;
    }
    // Jika status berubah dari approved ke bukan-approved, clear timestamp
    elseif ($approvalStatus !== 'approved' && $pet['ApprovalStatus'] === 'approved') {
        $approvedAt = "NULL";
        $approvedBy = "NULL";
    } else {
        // Maintain existing values
        $approvedAt = $approvedAt ? "'$approvedAt'" : "NULL";
        $approvedBy = $approvedBy ? "$approvedBy" : "NULL";
    }

    // Handle image upload
    $imagePath = $pet['Image']; // Keep existing image if no new upload

    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === 0) {
        $targetDir = 'uploads/';
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_FILES['image']['name']));
        $imagePath = $targetDir . $filename;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $imagePath)) {
            // Delete old image if exists and not default
            if (!empty($pet['Image']) && $pet['Image'] !== 'assets/img/default-pet.jpg' && file_exists($pet['Image'])) {
                unlink($pet['Image']);
            }
        } else {
            $errorMsg = "Image upload failed";
            $imagePath = $pet['Image']; // Keep old image if upload fails
        }
    }

    $imagePath = $conn->real_escape_string($imagePath);

    // FIXED UPDATE QUERY - DIRECT QUERY VERSION
    $sql = "UPDATE pet SET 
        Name = '$name', 
        Type = '$type', 
        Breed = '$breed', 
        Age = $age, 
        Gender = '$gender', 
        Description = '$description', 
        Image = '$imagePath', 
        Status = '$status', 
        PostType = '$postType', 
        Price = $price, 
        State = '$state', 
        District = '$district', 
        Latitude = $latitude, 
        Longitude = $longitude, 
        ApprovalStatus = '$approvalStatus', 
        ApprovedBy = $approvedBy, 
        ApprovedAt = $approvedAt,
        SitStartDate = $sitStartDate, 
        SitEndDate = $sitEndDate 
        WHERE PetID = $petID";

    if ($conn->query($sql)) {
        header("Location: adminManagePets.php?success=Pet updated successfully");
        exit;
    } else {
        $errorMsg = "Update failed: " . $conn->error;
    }
}

// Fetch admin name for approved by
$approvedByName = 'Not set';
if (!empty($pet['ApprovedBy'])) {
    $admin_result = $conn->query("SELECT Name FROM user WHERE UserID = " . $pet['ApprovedBy']);
    $admin_data = $admin_result->fetch_assoc();
    $approvedByName = $admin_data['Name'] ?? 'Unknown Admin';
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
    <title>Edit Pet - FurCare</title>
    <link rel="stylesheet" href="css/adminDashboard.css">
    <link rel="stylesheet" href="css/editPet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <style>
        .form-container {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }

        .left-col {
            flex: 1;
            max-width: 600px;
        }

        .right-col {
            flex: 1;
            max-width: 400px;
        }

        .map-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            height: fit-content;
        }

        #map {
            height: 300px;
            width: 100%;
            border-radius: 4px;
            margin-bottom: 10px;
        }

        .location-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }

        .location-inputs select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .auto-coordinates {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 12px;
            color: #666;
        }

        /* NEW STYLES FOR PET SIT FIELDS */
        .pet-sit-fields {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border: 2px solid #e2e8f0;
        }

        .pet-sit-fields h4 {
            margin: 0 0 15px 0;
            color: #3B7A57;
            font-size: 16px;
        }

        .date-fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .date-fields input {
            width: 90%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .calculation-display {
            background: white;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 14px;
            border: 1px solid #e0e0e0;
        }

        .calculation-display span {
            font-weight: bold;
            color: #3B7A57;
        }

        /* OVERDUE WARNING STYLES */
        .overdue-warning-box {
            background: #fff3cd;
            border: 2px solid #ffeaa7;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            color: #856404;
            text-align: center;
        }

        .overdue-warning-box h3 {
            margin: 0 0 10px 0;
            color: #856404;
        }

        .overdue-warning-box p {
            margin: 5px 0;
            font-size: 14px;
        }

        .btn-disabled {
            background: #95a5a6 !important;
            color: white !important;
            cursor: not-allowed !important;
            opacity: 0.6;
        }

        .form-disabled {
            opacity: 0.6;
            pointer-events: none;
        }

        .age-container {
            grid-column: span 2;
        }

        .age-input-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 8px;
        }

        .age-input-wrapper {
            display: flex;
            flex-direction: column;
        }

        .age-input-wrapper small {
            color: #718096;
            font-size: 0.8em;
            margin-top: 4px;
        }

        .age-display {
            background: #f0fff4;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #6DBE81;
            font-size: 0.9em;
        }

        .age-display strong {
            color: #3B7A57;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        /* Form Field Styles */
        .form-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-field label {
            font-weight: 600;
            color: #4a5568;
            font-size: 14px;
            margin-bottom: 2px;
        }

        .form-field label.required::after {
            content: " *";
            color: #ff7a2d;
        }

        .form-field input[type="text"],
        .form-field input[type="number"],
        .form-field input[type="file"],
        .form-field input[type="date"],
        .form-field select,
        .form-field textarea {
            padding: 12px 15px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            font-size: 14px;
            width: 100%;
            box-sizing: border-box;
            transition: all 0.3s ease;
            background: #f8fafc;
        }

        .form-field input[type="text"]::placeholder,
        .form-field textarea::placeholder {
            color: #a0aec0;
        }

        .form-field input:focus,
        .form-field select:focus,
        .form-field textarea:focus {
            outline: none;
            border-color: #6DBE81;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(109, 190, 129, 0.1);
        }

        /* Other type field */
        .type-other-field {
            margin-top: 8px;
            display: none;
        }

        .type-other-field input {
            padding: 12px 15px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            font-size: 14px;
            width: 100%;
            box-sizing: border-box;
            transition: all 0.3s ease;
            background: #f8fafc;
        }

        .type-other-field input:focus {
            outline: none;
            border-color: #6DBE81;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(109, 190, 129, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            justify-content: flex-end;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            min-width: 100px;
        }

        .btn.primary {
            background: linear-gradient(135deg, #3B7A57, #6DBE81);
            color: #fff;
        }

        .btn.primary:hover {
            background: linear-gradient(135deg, #2E5D43, #5CAF75);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 122, 87, 0.3);
        }

        .btn.cancel {
            background: #f8f9fa;
            color: #4a5568;
            border: 2px solid #e2e8f0;
        }

        .btn.cancel:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        .error-box {
            background: #fed7d7;
            border: 2px solid #feb2b2;
            color: #c53030;
            padding: 15px 20px;
            border-radius: 8px;
            margin: 0 0 25px 0;
            font-weight: 500;
        }

        .success-message {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            border: 1px solid #c3e6cb;
            font-weight: 500;
        }

        .info-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-top: 20px;
        }

        .info-card h3 {
            margin-top: 0;
            color: #3B7A57;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-approved {
            background: #d4edda;
            color: #155724;
        }

        .badge-pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .current-image {
            text-align: center;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 12px;
            border: 2px dashed #e2e8f0;
        }

        .current-image img {
            max-width: 200px;
            max-height: 200px;
            border-radius: 8px;
            border: 3px solid #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .approval-section {
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            margin-bottom: 20px;
        }

        .approval-section h4 {
            margin-top: 0;
            color: #3B7A57;
        }

        .current-approval {
            margin-bottom: 10px;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .form-container {
                flex-direction: column;
            }

            .left-col,
            .right-col {
                max-width: 100%;
            }

            .location-inputs,
            .date-fields,
            .age-input-group {
                grid-template-columns: 1fr;
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
        <a href="adminManagePets.php" class="active">🐾 Manage Pets</a>
        <a href="manageUsers.php">👥 Manage Users</a>
        <a href="adminAdoptionRequests.php">📋 Adoption Request</a>
        <a href="adminPetSitRequests.php">🏠 Pet Sit Request</a>
        <a href="reports.php">📑 Reports</a>
        <a href="logout.php" class="logout">🚪 Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="navbar">
            <h1>Edit Pet - <?php echo htmlspecialchars($pet['Name']); ?></h1>
            <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
                alt="Profile"
                class="profile-icon">
        </div>

        <?php if (!empty($errorMsg)): ?>
            <div class="error-box">Error: <?php echo htmlspecialchars($errorMsg); ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="success-message">✅ <?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>

        <!-- OVERDUE WARNING MESSAGE -->
        <?php if ($overdue_data['overdue_count'] > 0): ?>
            <div class="overdue-warning-box">
                <h3>⚠️ Edit Disabled - Overdue Requests Detected</h3>
                <p>This pet has <strong><?php echo $overdue_data['overdue_count']; ?> overdue pet sitting request(s)</strong>.</p>
                <p>You cannot edit this pet until all overdue requests are resolved.</p>
                <p><strong>Solution:</strong> Ask the owner to cancel the overdue requests or post a new pet listing.</p>
                <div style="margin-top: 15px;">
                    <a href="adminManagePets.php" class="btn primary">Back to Manage Pets</a>
                    <a href="manageUsers.php" class="btn" style="background: #3498db; color: white;">Contact Owner</a>
                </div>
            </div>
        <?php endif; ?>

        <form class="add-pet-form <?php if ($overdue_data['overdue_count'] > 0) echo 'form-disabled'; ?>"
            method="POST" enctype="multipart/form-data"
            <?php if ($overdue_data['overdue_count'] > 0) echo 'onsubmit="return false;"'; ?>>
            <div class="form-container">
                <div class="left-col">
                    <!-- Approval Status Section -->
                    <div class="approval-section">
                        <h4>📋 Approval Management</h4>
                        <div class="current-approval">
                            Current Status:
                            <span class="status-badge 
                                <?php
                                if ($pet['ApprovalStatus'] === 'approved') echo 'badge-approved';
                                elseif ($pet['ApprovalStatus'] === 'pending') echo 'badge-pending';
                                else echo 'badge-rejected';
                                ?>">
                                <?php echo htmlspecialchars($pet['ApprovalStatus']); ?>
                            </span>
                        </div>
                        <select name="approval_status" class="approval-status-select" required>
                            <option value="pending" <?= $formData['approval_status'] === 'pending' ? 'selected' : '' ?>>⏳ Pending</option>
                            <option value="approved" <?= $formData['approval_status'] === 'approved' ? 'selected' : '' ?>>✅ Approved</option>
                            <option value="rejected" <?= $formData['approval_status'] === 'rejected' ? 'selected' : '' ?>>❌ Rejected</option>
                        </select>
                        <small style="color: #666; display: block; margin-top: 5px;">
                            Change approval status as needed. Timestamps will be auto-updated.
                        </small>
                    </div>

                    <div class="current-image">
                        <h4>Current Image:</h4>
                        <img src="<?php echo htmlspecialchars($pet['Image']); ?>"
                            alt="<?php echo htmlspecialchars($pet['Name']); ?>"
                            class="main-pet-image">
                        <p class="image-note">Upload new image to replace current one</p>
                    </div>

                    <div class="form-grid">
                        <!-- Name Field -->
                        <div class="form-field">
                            <label for="name" class="required">Pet Name</label>
                            <input type="text" name="name" id="name" placeholder="Enter pet name"
                                value="<?php echo htmlspecialchars($formData['name']); ?>" required>
                        </div>

                        <!-- Type Field -->
                        <div class="form-field">
                            <label for="type" class="required">Pet Type</label>
                            <select name="type" id="type" required>
                                <option value="">-- Select Type --</option>
                                <?php foreach ($pet_types as $type_option): ?>
                                    <option value="<?php echo htmlspecialchars($type_option); ?>"
                                        <?php echo $pet['Type'] === $type_option ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type_option); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="Other" <?php echo !in_array($pet['Type'], $pet_types) ? 'selected' : ''; ?>>
                                    Other (Specify below)
                                </option>
                            </select>
                            <div class="type-other-field" id="typeOtherField"
                                style="display:<?php echo !in_array($pet['Type'], $pet_types) ? 'block' : 'none'; ?>;">
                                <input type="text" name="type_other" id="type_other" placeholder="Enter new pet type"
                                    value="<?php echo !in_array($pet['Type'], $pet_types) ? htmlspecialchars($pet['Type']) : ''; ?>">
                            </div>
                        </div>

                        <!-- Breed Field -->
                        <div class="form-field">
                            <label for="breed" class="required">Breed</label>
                            <input type="text" name="breed" id="breed" placeholder="Enter breed"
                                value="<?php echo htmlspecialchars($formData['breed']); ?>" required>
                        </div>

                        <!-- Age Field -->
                        <div class="form-field age-container">
                            <label class="required">Age</label>
                            <div class="age-input-group">
                                <div class="age-input-wrapper">
                                    <input type="number" name="years" id="yearsInput" placeholder="Years" min="0" max="50"
                                        value="<?php echo $currentYears; ?>" required>
                                    <small>Years (0-50)</small>
                                </div>
                                <div class="age-input-wrapper">
                                    <input type="number" name="months" id="monthsInput" placeholder="Months" min="0" max="11"
                                        value="<?php echo $currentMonths; ?>" required>
                                    <small>Months (0-11)</small>
                                </div>
                            </div>
                            <div class="age-display">
                                <strong>Current Age:</strong>
                                <span id="ageDisplay">
                                    <?php
                                    if ($currentYears > 0 && $currentMonths > 0) {
                                        echo "{$currentYears} years {$currentMonths} months";
                                    } elseif ($currentYears > 0) {
                                        echo "{$currentYears} years";
                                    } elseif ($currentMonths > 0) {
                                        echo "{$currentMonths} months";
                                    } else {
                                        echo "0 years";
                                    }
                                    ?>
                                </span>
                                <br>
                                <small style="color: #718096;">(Stored as: <span id="ageDecimal"><?php echo number_format($currentAge, 2); ?></span> years)</small>
                            </div>
                        </div>

                        <!-- Gender Field -->
                        <div class="form-field">
                            <label for="gender" class="required">Gender</label>
                            <select name="gender" id="gender" required>
                                <option value="">-- Select Gender --</option>
                                <option value="Male" <?php echo $formData['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo $formData['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>

                        <!-- Status Field -->
                        <div class="form-field">
                            <label for="status" class="required">Status</label>
                            <select name="status" id="status" required>
                                <option value="">-- Select Status --</option>
                                <option value="Available" <?php echo $formData['status'] === 'Available' ? 'selected' : ''; ?>>Available</option>
                                <option value="Adopted" <?php echo $formData['status'] === 'Adopted' ? 'selected' : ''; ?>>Adopted</option>
                                <option value="Pet Sit" <?php echo $formData['status'] === 'Pet Sit' ? 'selected' : ''; ?>>Pet Sit</option>
                            </select>
                        </div>

                        <!-- Post Type Field -->
                        <div class="form-field">
                            <label for="postType" class="required">Post Type</label>
                            <select name="postType" id="postType" required>
                                <option value="Adopt" <?php echo $formData['postType'] === 'Adopt' ? 'selected' : ''; ?>>Adoption</option>
                                <option value="Pet Sit" <?php echo $formData['postType'] === 'Pet Sit' ? 'selected' : ''; ?>>Pet Sitting</option>
                            </select>
                        </div>

                        <!-- Price Field -->
                        <div class="form-field" id="priceField" style="display:<?php echo ($formData['postType'] === 'Pet Sit') ? 'block' : 'none'; ?>;">
                            <label for="priceInput" class="required">Price (RM/Day)</label>
                            <input type="number" name="price" id="priceInput" placeholder="0.00" min="0" step="0.01"
                                   value="<?php echo !empty($formData['price']) ? number_format($formData['price'], 2) : ''; ?>">
                        </div>

                        <!-- Image Field -->
                        <div class="form-field">
                            <label for="image">Pet Image (Optional)</label>
                            <input type="file" name="image" id="image" accept="image/*">
                            <small>Leave empty to keep current image</small>
                        </div>

                        <!-- Pet Sit Fields -->
                        <div id="petSitFields" class="pet-sit-fields"
                            style="display:<?php echo ($formData['postType'] === 'Pet Sit') ? 'block' : 'none'; ?>;">
                            <h4>🐾 Pet Sitting Details</h4>
                            <div class="date-fields">
                                <div class="form-field">
                                    <label for="sitStartDate" class="required">Start Date</label>
                                    <input type="date" name="sit_start_date" id="sitStartDate"
                                        value="<?php echo htmlspecialchars($formData['sit_start_date'] ?? ''); ?>"
                                        min="<?php echo date('Y-m-d'); ?>" placeholder="Start Date">
                                </div>
                                <div class="form-field">
                                    <label for="sitEndDate" class="required">End Date</label>
                                    <input type="date" name="sit_end_date" id="sitEndDate"
                                        value="<?php echo htmlspecialchars($formData['sit_end_date'] ?? ''); ?>"
                                        min="<?php echo date('Y-m-d'); ?>" placeholder="End Date">
                                </div>
                            </div>

                            <div class="calculation-display" id="calculationDisplay"
                                style="display:<?php echo (!empty($formData['sit_start_date']) && !empty($formData['sit_end_date'])) ? 'block' : 'none'; ?>;">
                                <?php
                                if (!empty($formData['sit_start_date']) && !empty($formData['sit_end_date'])) {
                                    $start = new DateTime($formData['sit_start_date']);
                                    $end = new DateTime($formData['sit_end_date']);
                                    $days = $start->diff($end)->days + 1;
                                    $total = $days * ($formData['price'] ?? 0);
                                    echo "Duration: <span id='durationDisplay'>$days</span> days | Total: RM <span id='totalDisplay'>" . number_format($total, 2) . "</span>";
                                }
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- Location Section -->
                    <div class="location-section">
                        <h3>Location</h3>
                        <div class="location-inputs">
                            <div class="form-field">
                                <label for="state" class="required">State</label>
                                <select id="state" name="state" required>
                                    <option value="">-- Select State --</option>
                                </select>
                            </div>
                            <div class="form-field">
                                <label for="district" class="required">District</label>
                                <select id="district" name="district" required>
                                    <option value="">-- Select District --</option>
                                </select>
                            </div>
                        </div>
                        <div class="auto-coordinates">
                            <span>📍 Coordinates will be auto-filled when you select a district</span>
                            <div>
                                <strong>Latitude:</strong> <span id="latDisplay"><?php echo !empty($formData['latitude']) ? htmlspecialchars($formData['latitude']) : 'Not set'; ?></span> |
                                <strong>Longitude:</strong> <span id="lngDisplay"><?php echo !empty($formData['longitude']) ? htmlspecialchars($formData['longitude']) : 'Not set'; ?></span>
                            </div>
                        </div>
                        <input type="hidden" id="latitude" name="latitude" value="<?php echo !empty($formData['latitude']) ? htmlspecialchars($formData['latitude']) : ''; ?>" required>
                        <input type="hidden" id="longitude" name="longitude" value="<?php echo !empty($formData['longitude']) ? htmlspecialchars($formData['longitude']) : ''; ?>" required>
                    </div>

                    <!-- Description Field -->
                    <div class="form-field">
                        <label for="description">Description</label>
                        <textarea name="description" id="description" rows="4" placeholder="Tell us about your pet..."><?php echo htmlspecialchars($formData['description']); ?></textarea>
                    </div>

                    <div class="form-actions">
                        <a href="adminManagePets.php" class="btn cancel">Cancel</a>
                        <?php if ($overdue_data['overdue_count'] > 0): ?>
                            <button type="button" class="btn btn-disabled" disabled>Edit Disabled - Overdue Requests</button>
                        <?php else: ?>
                            <button type="submit" class="btn primary">Update Pet</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="right-col">
                    <div class="map-card">
                        <h3>Location preview</h3>
                        <div id="map"></div>
                        <p class="map-hint">📍 Select a district or click on the map to set coordinates</p>
                    </div>

                    <!-- Pet Information Card -->
                    <div class="info-card">
                        <h3>📋 Pet Information</h3>
                        <div>
                            <p><strong>Pet ID:</strong> <?php echo htmlspecialchars($pet['PetID']); ?></p>
                            <p><strong>Owner:</strong> <?php echo htmlspecialchars($pet['OwnerName']); ?> (ID: <?php echo htmlspecialchars($pet['OwnerID']); ?>)</p>
                            <p><strong>Posted:</strong> <?php echo date('M j, Y g:i A', strtotime($pet['PostDate'])); ?></p>
                            <p><strong>Post Type:</strong>
                                <span style="color: #3B7A57; font-weight: bold;">
                                    <?php echo htmlspecialchars($pet['PostType']); ?>
                                </span>
                            </p>
                            <p><strong>Current Approval:</strong>
                                <span class="status-badge 
                                    <?php
                                    if ($pet['ApprovalStatus'] === 'approved') echo 'badge-approved';
                                    elseif ($pet['ApprovalStatus'] === 'pending') echo 'badge-pending';
                                    else echo 'badge-rejected';
                                    ?>">
                                    <?php echo htmlspecialchars($pet['ApprovalStatus']); ?>
                                </span>
                            </p>
                            <?php if (!empty($pet['ApprovedAt'])): ?>
                                <p><strong>Approved Date:</strong> <?php echo date('M j, Y g:i A', strtotime($pet['ApprovedAt'])); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($pet['ApprovedBy'])): ?>
                                <p><strong>Approved By:</strong> <?php echo htmlspecialchars($approvedByName); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($pet['RejectionReason'])): ?>
                                <p><strong>Rejection Reason:</strong> <?php echo htmlspecialchars($pet['RejectionReason']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script>
        // FULL SCRIPT dengan FIXED DATE VALIDATION
        document.addEventListener('DOMContentLoaded', function() {
            // ========== TYPE DROPDOWN HANDLER ==========
            const typeSelect = document.getElementById('type');
            const typeOtherField = document.getElementById('typeOtherField');
            const typeOtherInput = document.getElementById('type_other');

            if (typeSelect) {
                typeSelect.addEventListener('change', function() {
                    if (this.value === 'Other') {
                        typeOtherField.style.display = 'block';
                        if (typeOtherInput) typeOtherInput.required = true;
                    } else {
                        typeOtherField.style.display = 'none';
                        if (typeOtherInput) typeOtherInput.required = false;
                        if (typeOtherInput) typeOtherInput.value = '';
                    }
                });
            }

            // ========== AGE CALCULATION ==========
            const yearsInput = document.getElementById('yearsInput');
            const monthsInput = document.getElementById('monthsInput');
            const ageDisplay = document.getElementById('ageDisplay');
            const ageDecimal = document.getElementById('ageDecimal');

            function updateAgeDisplay() {
                const years = parseInt(yearsInput.value) || 0;
                const months = parseInt(monthsInput.value) || 0;

                // Calculate total years (decimal)
                const totalYears = years + (months / 12);

                // Display text
                let displayText = '';
                if (years > 0 && months > 0) {
                    displayText = `${years} years ${months} months`;
                } else if (years > 0) {
                    displayText = `${years} years`;
                } else if (months > 0) {
                    displayText = `${months} months`;
                } else {
                    displayText = '0 years';
                }

                if (ageDisplay) ageDisplay.textContent = displayText;
                if (ageDecimal) ageDecimal.textContent = totalYears.toFixed(2);
            }

            if (yearsInput && monthsInput) {
                yearsInput.addEventListener('input', function() {
                    if (this.value > 50) this.value = 50;
                    if (this.value < 0) this.value = 0;
                    updateAgeDisplay();
                });

                monthsInput.addEventListener('input', function() {
                    if (this.value > 11) this.value = 11;
                    if (this.value < 0) this.value = 0;
                    updateAgeDisplay();
                });

                // Initialize
                updateAgeDisplay();
            }

            // ========== POST TYPE TOGGLE ==========
            const postTypeSelect = document.getElementById('postType');
            const priceField = document.getElementById('priceField');
            const priceInput = document.getElementById('priceInput');
            const petSitFields = document.getElementById('petSitFields');
            const sitStartDate = document.getElementById('sitStartDate');
            const sitEndDate = document.getElementById('sitEndDate');

            if (postTypeSelect) {
                postTypeSelect.addEventListener('change', function() {
                    const isPetSit = this.value === 'Pet Sit';

                    // Show/hide price field
                    if (priceField) {
                        priceField.style.display = isPetSit ? 'block' : 'none';
                        if (priceInput) priceInput.required = isPetSit;
                    }

                    // Show/hide pet sit fields
                    if (petSitFields) {
                        petSitFields.style.display = isPetSit ? 'block' : 'none';
                    }

                    // Show/hide calculation display
                    const calculationDisplay = document.getElementById('calculationDisplay');
                    if (calculationDisplay && sitStartDate && sitEndDate) {
                        const hasValidDates = sitStartDate.value && sitEndDate.value;
                        calculationDisplay.style.display = (isPetSit && hasValidDates) ? 'block' : 'none';
                    }

                    // Clear pet sit fields if switching away from Pet Sit
                    if (!isPetSit && sitStartDate && sitEndDate) {
                        sitStartDate.value = '';
                        sitEndDate.value = '';
                        if (calculationDisplay) calculationDisplay.style.display = 'none';
                        if (priceInput) priceInput.value = '';
                    }

                    // Reset date validation
                    initializeDateValidation();
                });
            }

            // ========== FIXED DATE VALIDATION FUNCTIONS ==========

            // Function to get today's date in YYYY-MM-DD format (LOCAL TIME)
            function getTodayDate() {
                const today = new Date();
                const year = today.getFullYear();
                const month = String(today.getMonth() + 1).padStart(2, '0');
                const day = String(today.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            }

            // Function to compare dates (handles timezone issues)
            function isDateBefore(dateStr1, dateStr2) {
                if (!dateStr1 || !dateStr2) return false;
                
                // Create dates at MIDNIGHT (00:00:00) local time
                const date1 = new Date(dateStr1 + 'T00:00:00');
                const date2 = new Date(dateStr2 + 'T00:00:00');
                
                return date1 < date2;
            }

            // Function to check if date is today or later
            function isDateTodayOrLater(dateStr) {
                if (!dateStr) return false;
                
                const today = new Date();
                const selectedDate = new Date(dateStr + 'T00:00:00');
                
                // Set both to midnight for fair comparison
                today.setHours(0, 0, 0, 0);
                selectedDate.setHours(0, 0, 0, 0);
                
                return selectedDate >= today;
            }

            // Hide calculation display
            function hideCalculationDisplay() {
                const calculationDisplay = document.getElementById('calculationDisplay');
                if (calculationDisplay) {
                    calculationDisplay.style.display = 'none';
                }
            }

            // Show alert
            function showAlert(message) {
                alert(message);
            }

            // Initialize date validation
            function initializeDateValidation() {
                if (!sitStartDate || !sitEndDate) return;

                const today = getTodayDate();
                
                // Set min dates
                sitStartDate.min = today;
                sitEndDate.min = today;
                
                // If start date already has a value, update end date min
                if (sitStartDate.value) {
                    // Check if start date is in the past
                    if (!isDateTodayOrLater(sitStartDate.value)) {
                        showAlert('⚠️ Start date cannot be in the past!');
                        sitStartDate.value = '';
                        hideCalculationDisplay();
                        return;
                    }
                    
                    sitEndDate.min = sitStartDate.value;
                    
                    // Clear end date if it's before new min
                    if (sitEndDate.value && isDateBefore(sitEndDate.value, sitStartDate.value)) {
                        sitEndDate.value = '';
                        hideCalculationDisplay();
                    }
                }
                
                // Remove old event listeners (prevent duplicates)
                sitStartDate.removeEventListener('change', handleStartDateChange);
                sitEndDate.removeEventListener('change', handleEndDateChange);
                
                // Add event listeners for date changes
                sitStartDate.addEventListener('change', handleStartDateChange);
                sitEndDate.addEventListener('change', handleEndDateChange);
            }

            // Handle start date change
            function handleStartDateChange() {
                const startDateValue = this.value;
                
                if (!sitEndDate) return;
                
                // Validate start date is not in the past
                if (!isDateTodayOrLater(startDateValue)) {
                    showAlert('⚠️ Start date cannot be in the past!');
                    this.value = '';
                    hideCalculationDisplay();
                    return;
                }
                
                // Update end date's min attribute
                sitEndDate.min = startDateValue;
                
                // Clear end date if it's now invalid
                if (sitEndDate.value && isDateBefore(sitEndDate.value, startDateValue)) {
                    sitEndDate.value = '';
                    hideCalculationDisplay();
                    showAlert('End date has been cleared because it was before the new start date.');
                }
                
                // Recalculate duration
                calculatePetSitDuration();
            }

            // Handle end date change
            function handleEndDateChange() {
                const endDateValue = this.value;
                
                if (!sitStartDate || !sitStartDate.value) return;
                
                // Validate end date is not in the past
                if (!isDateTodayOrLater(endDateValue)) {
                    showAlert('⚠️ End date cannot be in the past!');
                    this.value = '';
                    return;
                }
                
                // Validate end date is not before start
                if (isDateBefore(endDateValue, sitStartDate.value)) {
                    showAlert('⚠️ End date cannot be before start date!');
                    this.value = '';
                    return;
                }
                
                // Recalculate duration
                calculatePetSitDuration();
            }

            // ========== CALCULATE PET SIT DURATION ==========
            function calculatePetSitDuration() {
                const startDate = sitStartDate ? sitStartDate.value : '';
                const endDate = sitEndDate ? sitEndDate.value : '';
                const calculationDisplay = document.getElementById('calculationDisplay');
                
                if (!calculationDisplay) return;

                // Validate dates
                if (startDate && endDate) {
                    // Double-check date validity
                    if (isDateBefore(endDate, startDate)) {
                        calculationDisplay.style.display = 'none';
                        return;
                    }

                    const start = new Date(startDate + 'T00:00:00');
                    const end = new Date(endDate + 'T00:00:00');
                    
                    // FIXED: Include start date in calculation (add +1)
                    const timeDiff = end.getTime() - start.getTime();
                    const days = Math.floor(timeDiff / (1000 * 60 * 60 * 24)) + 1;

                    if (days > 0) {
                        // Get price
                        let price = 0;
                        if (priceInput && priceInput.value.trim() !== '') {
                            const priceResult = validatePriceInput(priceInput);
                            if (priceResult.isValid) {
                                price = parseFloat(priceResult.value) || 0;
                            }
                        }
                        
                        const total = days * price;
                        if (document.getElementById('durationDisplay')) {
                            document.getElementById('durationDisplay').textContent = days;
                        }
                        if (document.getElementById('totalDisplay')) {
                            document.getElementById('totalDisplay').textContent = total.toFixed(2);
                        }
                        calculationDisplay.style.display = 'block';
                    } else {
                        calculationDisplay.style.display = 'none';
                    }
                } else {
                    calculationDisplay.style.display = 'none';
                }
            }

            // Attach event listeners for calculation
            if (sitStartDate) sitStartDate.addEventListener('change', calculatePetSitDuration);
            if (sitEndDate) sitEndDate.addEventListener('change', calculatePetSitDuration);
            if (priceInput) priceInput.addEventListener('input', calculatePetSitDuration);

            // Initialize date validation
            initializeDateValidation();

            // ========== MAP AND LOCATION ==========
            console.log('Initializing map...');

            const mapElement = document.getElementById('map');
            if (!mapElement) {
                console.error('❌ Map container #map not found!');
                return;
            }

            // Existing coordinates or default
            const existingLat = <?php echo !empty($formData['latitude']) ? floatval($formData['latitude']) : '3.1390'; ?>;
            const existingLng = <?php echo !empty($formData['longitude']) ? floatval($formData['longitude']) : '101.6869'; ?>;

            // Initialize map
            const map = L.map('map').setView([existingLat, existingLng], 12);

            // Add tile layer
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            // Add marker
            const marker = L.marker([existingLat, existingLng])
                .addTo(map)
                .bindPopup('<?php echo htmlspecialchars($pet['Name']); ?> Location')
                .openPopup();

            console.log('✅ Map initialized at:', existingLat, existingLng);

            // ========== STATE & DISTRICT DATA ==========
            const malaysiaLocations = {
                'Johor': ['Johor Bahru', 'Batu Pahat', 'Kluang', 'Kota Tinggi', 'Mersing', 'Muar', 'Pontian', 'Segamat', 'Kulai', 'Tangkak'],
                'Kedah': ['Alor Setar', 'Baling', 'Bandar Baharu', 'Kota Setar', 'Kuala Muda', 'Kubang Pasu', 'Kulim', 'Langkawi', 'Padang Terap', 'Pendang', 'Pokok Sena', 'Sik', 'Yan'],
                'Kelantan': ['Kota Bharu', 'Bachok', 'Pasir Mas', 'Pasir Puteh', 'Tanah Merah', 'Tumpat', 'Gua Musang', 'Kuala Krai', 'Jeli', 'Machang'],
                'Melaka': ['Alor Gajah', 'Jasin', 'Melaka Tengah'],
                'Negeri Sembilan': ['Seremban', 'Jelebu', 'Kuala Pilah', 'Port Dickson', 'Rembau', 'Tampin'],
                'Pahang': ['Kuantan', 'Bentong', 'Cameron Highlands', 'Jerantut', 'Lipis', 'Pekan', 'Raub', 'Temerloh', 'Rompin', 'Maran', 'Bera'],
                'Perak': ['Ipoh', 'Batang Padang', 'Hilir Perak', 'Hulu Perak', 'Kampar', 'Kerian', 'Kinta', 'Kuala Kangsar', 'Larut, Matang & Selama', 'Manjung', 'Muallim', 'Perak Tengah', 'Selama'],
                'Perlis': ['Kangar', 'Padang Besar'],
                'Pulau Pinang': ['George Town', 'Timur Laut', 'Barat Daya', 'Seberang Perai Selatan', 'Seberang Perai Tengah', 'Seberang Perai Utara'],
                'Sabah': ['Kota Kinabalu', 'Beaufort', 'Beluran', 'Keningau', 'Kinabatangan', 'Kota Belud', 'Kota Marudu', 'Kuala Penyu', 'Kudat', 'Kunak', 'Lahad Datu', 'Nabawan', 'Papar', 'Penampang', 'Pitas', 'Putatan', 'Ranau', 'Sandakan', 'Semporna', 'Sipitang', 'Tambunan', 'Tawau', 'Telupid', 'Tenom', 'Tongod', 'Tuaran'],
                'Sarawak': ['Kuching', 'Bau', 'Belaga', 'Beluru', 'Betong', 'Bintulu', 'Bukit Mabong', 'Dalat', 'Daro', 'Julau', 'Kabong', 'Kanowit', 'Kapit', 'Kuching', 'Lawas', 'Limbang', 'Lubok Antu', 'Lundu', 'Marudi', 'Matu', 'Meradong', 'Miri', 'Mukah', 'Pakan', 'Pusa', 'Samarahan', 'Saratok', 'Sarikei', 'Sebauh', 'Selangau', 'Serian', 'Sibu', 'Simunjan', 'Song', 'Sri Aman', 'Subis', 'Tanjung Manis', 'Tatau', 'Tebedu'],
                'Selangor': ['Shah Alam', 'Gombak', 'Hulu Langat', 'Hulu Selangor', 'Klang', 'Kuala Langat', 'Kuala Selangor', 'Petaling', 'Sabak Bernam', 'Sepang'],
                'Terengganu': ['Kuala Terengganu', 'Besut', 'Dungun', 'Hulu Terengganu', 'Kemaman', 'Kuala Nerus', 'Marang', 'Setiu'],
                'Kuala Lumpur': ['Kuala Lumpur'],
                'Labuan': ['Labuan'],
                'Putrajaya': ['Putrajaya']
            };

            // Populate states
            const stateSelect = document.getElementById('state');
            const districtSelect = document.getElementById('district');

            // Clear existing options
            stateSelect.innerHTML = '<option value="">-- Select State --</option>';
            districtSelect.innerHTML = '<option value="">-- Select District --</option>';

            // Add states to dropdown
            Object.keys(malaysiaLocations).forEach(state => {
                const option = document.createElement('option');
                option.value = state;
                option.textContent = state;
                stateSelect.appendChild(option);
            });

            // FIXED: AUTO-SET EXISTING VALUES
            const existingState = "<?php echo htmlspecialchars($formData['state']); ?>";
            const existingDistrict = "<?php echo htmlspecialchars($formData['district']); ?>";

            console.log('Existing State:', existingState);
            console.log('Existing District:', existingDistrict);

            // Function to set existing values
            function setExistingValues() {
                if (existingState && existingState !== '') {
                    console.log('Setting state to:', existingState);
                    stateSelect.value = existingState;

                    // Populate districts for the existing state
                    populateDistricts(existingState);

                    // Set district after districts are populated
                    setTimeout(() => {
                        if (existingDistrict && existingDistrict !== '') {
                            console.log('Setting district to:', existingDistrict);
                            districtSelect.value = existingDistrict;
                        }
                    }, 100);
                }
            }

            // Call the function to set existing values
            setExistingValues();

            // State change event
            stateSelect.addEventListener('change', function() {
                const selectedState = this.value;
                console.log('State changed to:', selectedState);
                populateDistricts(selectedState);

                // Clear coordinates when state changes
                if (!selectedState) {
                    clearCoordinates();
                }
            });

            // District change event - update coordinates
            districtSelect.addEventListener('change', function() {
                const state = stateSelect.value;
                const district = this.value;
                console.log('District changed to:', district);

                if (state && district) {
                    updateCoordinates(state, district);
                } else {
                    clearCoordinates();
                }
            });

            function populateDistricts(state) {
                console.log('Populating districts for state:', state);
                districtSelect.innerHTML = '<option value="">-- Select District --</option>';

                if (state && malaysiaLocations[state]) {
                    malaysiaLocations[state].forEach(district => {
                        const option = document.createElement('option');
                        option.value = district;
                        option.textContent = district;
                        districtSelect.appendChild(option);
                    });

                    console.log('Districts populated:', malaysiaLocations[state].length);
                }
            }

            function updateCoordinates(state, district) {
                console.log('Updating coordinates for:', state, district);

                // Simple coordinate mapping for demo
                const stateCoords = {
                    'Johor': [1.4927, 103.7414],
                    'Kedah': [6.1184, 100.3685],
                    'Kelantan': [6.1254, 102.2381],
                    'Melaka': [2.1896, 102.2501],
                    'Negeri Sembilan': [2.7250, 101.9424],
                    'Pahang': [3.8126, 103.3256],
                    'Perak': [4.5921, 101.0901],
                    'Perlis': [6.4444, 100.1986],
                    'Pulau Pinang': [5.4141, 100.3288],
                    'Sabah': [5.9804, 116.0735],
                    'Sarawak': [2.5000, 112.5000],
                    'Selangor': [3.0738, 101.5183],
                    'Terengganu': [5.3117, 103.1324],
                    'Kuala Lumpur': [3.1390, 101.6869],
                    'Labuan': [5.2831, 115.2308],
                    'Putrajaya': [2.9264, 101.6964]
                };

                // Use state coordinates as default, add small random offset for district
                const baseCoords = stateCoords[state] || [3.1390, 101.6869];
                const lat = baseCoords[0] + (Math.random() * 0.1 - 0.05);
                const lng = baseCoords[1] + (Math.random() * 0.1 - 0.05);

                // Update form fields
                document.getElementById('latitude').value = lat.toFixed(6);
                document.getElementById('longitude').value = lng.toFixed(6);
                document.getElementById('latDisplay').textContent = lat.toFixed(6);
                document.getElementById('lngDisplay').textContent = lng.toFixed(6);

                // Update map
                map.setView([lat, lng], 12);
                marker.setLatLng([lat, lng])
                    .bindPopup(`${district}, ${state}`)
                    .openPopup();

                console.log('Coordinates updated to:', lat, lng);
            }

            function clearCoordinates() {
                document.getElementById('latitude').value = '';
                document.getElementById('longitude').value = '';
                document.getElementById('latDisplay').textContent = 'Not set';
                document.getElementById('lngDisplay').textContent = 'Not set';
            }

            console.log('✅ Location system initialized');

            // ========== PRICE VALIDATION FUNCTIONS ==========

            // Format price while typing
            function formatPriceInput(input) {
                let value = input.value.trim();

                // Remove all non-numeric characters except dot
                value = value.replace(/[^\d.]/g, '');

                // Remove extra dots (allow only one)
                const parts = value.split('.');
                if (parts.length > 2) {
                    value = parts[0] + '.' + parts.slice(1).join('');
                }

                // Limit to 2 decimal places
                if (parts.length > 1 && parts[1].length > 2) {
                    value = parts[0] + '.' + parts[1].substring(0, 2);
                }

                // Update the input value
                input.value = value;
            }

            // Validate price input
            function validatePriceInput(input) {
                const value = input.value.trim();

                if (value === '') {
                    return {
                        isValid: true,
                        value: ''
                    };
                }

                // Check if it's a valid number with max 2 decimal places
                const regex = /^\d+(\.\d{1,2})?$/;

                if (!regex.test(value)) {
                    return {
                        isValid: false,
                        message: 'Please enter a valid amount with maximum 2 decimal places (e.g., 25.50)'
                    };
                }

                const numValue = parseFloat(value);
                if (isNaN(numValue) || numValue < 0) {
                    return {
                        isValid: false,
                        message: 'Price must be a positive number'
                    };
                }

                // Format to 2 decimal places
                const formattedValue = numValue.toFixed(2);
                return {
                    isValid: true,
                    value: formattedValue
                };
            }

            // Apply price validation to input
            if (priceInput) {
                // Format while typing
                priceInput.addEventListener('input', function(e) {
                    formatPriceInput(this);
                });

                // Validate on blur
                priceInput.addEventListener('blur', function() {
                    const result = validatePriceInput(this);

                    if (!result.isValid) {
                        alert(result.message);
                        this.value = '';
                        this.focus();
                    } else if (result.value !== '' && result.value !== this.value) {
                        this.value = result.value;
                    }
                });
            }

            // Form submission validation
            const form = document.querySelector('.add-pet-form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    // Check if form is disabled (overdue requests)
                    if (this.classList.contains('form-disabled')) {
                        e.preventDefault();
                        alert('Cannot edit pet with overdue requests');
                        return false;
                    }

                    // Validate type if "Other" is selected
                    const typeSelect = document.getElementById('type');
                    const typeOtherInput = document.getElementById('type_other');
                    
                    if (typeSelect && typeSelect.value === 'Other' && 
                        typeOtherInput && (!typeOtherInput.value || typeOtherInput.value.trim() === '')) {
                        e.preventDefault();
                        alert('Please enter a pet type when selecting "Other"');
                        typeOtherInput.focus();
                        return false;
                    }

                    // Validate pet sit dates
                    const postType = document.getElementById('postType').value;

                    if (postType === 'Pet Sit') {
                        // Validate price
                        if (priceInput) {
                            const priceResult = validatePriceInput(priceInput);

                            if (!priceResult.isValid && priceInput.value.trim() !== '') {
                                e.preventDefault();
                                alert(priceResult.message);
                                priceInput.focus();
                                return false;
                            }

                            // Format price to 2 decimal places sebelum submit
                            if (priceResult.value && priceResult.value !== priceInput.value) {
                                priceInput.value = priceResult.value;
                            }
                        }

                        // Validate dates
                        if (sitStartDate && sitEndDate && sitStartDate.value && sitEndDate.value) {
                            const start = new Date(sitStartDate.value + 'T00:00:00');
                            const end = new Date(sitEndDate.value + 'T00:00:00');
                            const today = new Date();
                            today.setHours(0, 0, 0, 0);

                            // Check if dates are not in the past
                            if (start < today) {
                                e.preventDefault();
                                alert('⚠️ Start date cannot be in the past!');
                                sitStartDate.focus();
                                return false;
                            }

                            if (end < today) {
                                e.preventDefault();
                                alert('⚠️ End date cannot be in the past!');
                                sitEndDate.focus();
                                return false;
                            }

                            // Check if end date is before start date
                            if (end < start) {
                                e.preventDefault();
                                alert('⚠️ End date cannot be before start date!');
                                sitEndDate.focus();
                                return false;
                            }

                            // Validate that end date is at least 1 day after start date
                            const timeDiff = end.getTime() - start.getTime();
                            const days = Math.floor(timeDiff / (1000 * 60 * 60 * 24)) + 1;

                            if (days <= 0) {
                                e.preventDefault();
                                alert('⚠️ End date must be after start date!');
                                sitEndDate.focus();
                                return false;
                            }
                        }
                    }

                    return true;
                });
            }
        });
    </script>
</body>

</html>