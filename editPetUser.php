<?php
include 'connect.php';
session_start();

if (!isset($_SESSION['user_id'])) {
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

// Fetch pet data - USER ONLY dengan tambahan validation untuk overdue
$stmt = $conn->prepare("SELECT * FROM pet WHERE PetID = ? AND OwnerID = ?");
$stmt->bind_param("ii", $petID, $userID);
$stmt->execute();
$pet = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pet) {
    header("Location: myPets.php?error=Pet not found or access denied");
    exit;
}

// CHECK JIKA PET SIT SUDAH OVERDUE (FIXED)
$isOverdue = false;
$overdueMessage = '';

// Cek jika ini adalah listing Pet Sit dan ada tarikh akhir
if ($pet['PostType'] === 'Pet Sit' && !empty($pet['SitEndDate'])) {
    $currentDate = date('Y-m-d');
    $endDate = $pet['SitEndDate'];

    // Jika tarikh semasa sudah lepas tarikh akhir, maka overdue
    if ($currentDate > $endDate) {
        $isOverdue = true;
        $overdueMessage = "This pet sitting listing has ended. You cannot edit overdue listings.";
    }
}

// BLOCK ACCESS JIKA OVERDUE
if ($isOverdue) {
    header("Location: myPets.php?error=" . urlencode($overdueMessage));
    exit;
}

// Calculate years and months from decimal age untuk pre-fill form
$currentAge = floatval($pet['Age']);
$totalMonths = round($currentAge * 12);
$currentYears = floor($totalMonths / 12);
$currentMonths = $totalMonths % 12;

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // DOUBLE CHECK OVERDUE SEBELUM UPDATE (security measure)
    $checkOverdue = $conn->prepare("SELECT SitEndDate FROM pet WHERE PetID = ? AND OwnerID = ?");
    $checkOverdue->bind_param("ii", $petID, $userID);
    $checkOverdue->execute();
    $petCheck = $checkOverdue->get_result()->fetch_assoc();
    $checkOverdue->close();

    if ($petCheck && !empty($petCheck['SitEndDate']) && date('Y-m-d') > $petCheck['SitEndDate']) {
        header("Location: myPets.php?error=Cannot edit overdue pet sitting listing");
        exit;
    }

    $name = $_POST['name'] ?? '';
    
    // Handle type - jika "Other" dipilih, gunakan input baru
    $type_input = $_POST['type'] ?? '';
    $type_other = $_POST['type_other'] ?? '';
    
    // Determine which type to use
    if ($type_input === 'Other' && !empty($type_other)) {
        $type = $type_other;
    } else {
        $type = $type_input;
    }
    
    $breed = $_POST['breed'] ?? '';

    // Calculate age from years and months
    $years = intval($_POST['years'] ?? 0);
    $months = intval($_POST['months'] ?? 0);

    // Ensure months is between 0-11
    if ($months > 11) $months = 11;
    if ($months < 0) $months = 0;

    $age = round($years + ($months / 12), 2);

    $gender = $_POST['gender'] ?? '';
    $description = $_POST['description'] ?? '';
    $status = $_POST['status'] ?? '';
    $postType = $_POST['postType'] ?? 'Adopt';
    $price = $_POST['price'] ?? null;
    $state = $_POST['state'] ?? '';
    $district = $_POST['district'] ?? '';
    $latitude = $_POST['latitude'] !== '' ? floatval($_POST['latitude']) : null;
    $longitude = $_POST['longitude'] !== '' ? floatval($_POST['longitude']) : null;

    // Pet Sit fields
    $sitStartDate = $_POST['sit_start_date'] ?? null;
    $sitEndDate = $_POST['sit_end_date'] ?? null;

    // Convert empty strings to null
    if ($sitStartDate === '') $sitStartDate = null;
    if ($sitEndDate === '') $sitEndDate = null;

    // VALIDATE PET SIT DATES TIDAK OVERDUE
    if ($postType === 'Pet Sit' && $sitEndDate) {
        $currentDate = date('Y-m-d');
        if ($sitEndDate < $currentDate) {
            $errorMsg = "End date cannot be in the past for pet sitting listings.";
        }
    }

    if (empty($errorMsg)) {
        // Handle image upload
        $imagePath = $pet['Image'];

        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === 0) {
            $targetDir = 'uploads/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_FILES['image']['name']));
            $imagePath = $targetDir . $filename;
            move_uploaded_file($_FILES['image']['tmp_name'], $imagePath);

            if (!empty($pet['Image']) && $pet['Image'] !== 'assets/img/default-pet.jpg' && file_exists($pet['Image'])) {
                unlink($pet['Image']);
            }
        }

        // Update query
        $sql = "UPDATE pet SET 
            Name = '" . $conn->real_escape_string($name) . "', 
            Type = '" . $conn->real_escape_string($type) . "', 
            Breed = '" . $conn->real_escape_string($breed) . "', 
            Age = " . floatval($age) . ", 
            Gender = '" . $conn->real_escape_string($gender) . "', 
            Description = '" . $conn->real_escape_string($description) . "', 
            Image = '" . $conn->real_escape_string($imagePath) . "', 
            Status = '" . $conn->real_escape_string($status) . "', 
            PostType = '" . $conn->real_escape_string($postType) . "', 
            Price = " . ($price ? floatval($price) : "NULL") . ", 
            State = '" . $conn->real_escape_string($state) . "', 
            District = '" . $conn->real_escape_string($district) . "', 
            Latitude = " . ($latitude ? floatval($latitude) : "NULL") . ", 
            Longitude = " . ($longitude ? floatval($longitude) : "NULL") . ", 
            SitStartDate = " . ($sitStartDate ? "'" . $conn->real_escape_string($sitStartDate) . "'" : "NULL") . ", 
            SitEndDate = " . ($sitEndDate ? "'" . $conn->real_escape_string($sitEndDate) . "'" : "NULL") . ",
            ApprovalStatus = 'Pending' 
            WHERE PetID = " . intval($petID) . " AND OwnerID = " . intval($userID);

        if ($conn->query($sql)) {
            header("Location: myPets.php?success=Pet updated successfully - Waiting for admin approval");
            exit;
        } else {
            $errorMsg = "Database error: " . $conn->error;
        }
    }
}

// Ambil data user untuk profile picture
$user_query = "SELECT * FROM user WHERE UserID = '$userID'";
$user_result = mysqli_query($conn, $user_query);
$user = mysqli_fetch_assoc($user_result);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Edit Pet - FurCare</title>
    <link rel="stylesheet" href="css/userDashboard.css">
    <link rel="stylesheet" href="css/editPetUser.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <style>
        .main-content {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .title {
            text-align: center;
            color: #3B7A57;
            margin-bottom: 30px;
            font-size: 2em;
            font-weight: 700;
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

        .form-container {
            display: flex;
            gap: 30px;
            margin-top: 20px;
        }

        .left-col {
            flex: 1;
            max-width: 600px;
            background: #fff;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .right-col {
            flex: 1;
            max-width: 400px;
            position: sticky;
            top: 30px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

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

        .location-section {
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
        }

        .location-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 15px;
        }

        .map-card {
            background: #fff;
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        #map {
            height: 300px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
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

        /* Age Field Styles */
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

        /* Pet Sit Fields */
        .pet-sit-fields {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border: 2px solid #e2e8f0;
            grid-column: span 2;
        }

        .pet-sit-fields h4 {
            margin: 0 0 15px 0;
            color: #3B7A57;
            font-size: 1.1em;
        }

        .date-fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
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

        @media (max-width: 1024px) {
            .form-container {
                flex-direction: column;
            }

            .right-col {
                max-width: 100%;
                position: static;
            }
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .age-container {
                grid-column: span 1;
            }

            .pet-sit-fields {
                grid-column: span 1;
            }
        }

        .overdue-warning {
            background: linear-gradient(135deg, #fed7d7, #feb2b2);
            border: 2px solid #fc8181;
            color: #c53030;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            text-align: center;
            font-weight: 600;
            font-size: 1.1em;
        }

        .disabled-form {
            opacity: 0.6;
            pointer-events: none;
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <div class="navbar">
        <h2 class="logo">FurCare</h2>
        <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
            alt="Profile" class="profile-icon" onclick="toggleSidebar()">
    </div>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <span class="close-btn" onclick="toggleSidebar()">&times;</span>
        </div>
        <a href="userDashboard.php" class="active">🐾 Browse Pet</a>
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
        <h1 class="title">Edit Pet - <?php echo htmlspecialchars($pet['Name']); ?></h1>

        <?php if (!empty($errorMsg)): ?>
            <div class="error-box">Error: <?php echo htmlspecialchars($errorMsg); ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="success-message">✅ <?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>

        <!-- TAMBAH OVERDUE WARNING JIKA ADA -->
        <?php if ($isOverdue): ?>
            <div class="overdue-warning">
                ⚠️ <?php echo htmlspecialchars($overdueMessage); ?><br>
                <small>Please create a new listing if you want to continue offering pet sitting services.</small>
            </div>
        <?php endif; ?>

        <form class="add-pet-form <?php echo $isOverdue ? 'disabled-form' : ''; ?>" method="POST" enctype="multipart/form-data">
            <div class="form-container">
                <div class="left-col">
                    <div class="current-image">
                        <h4>Current Image:</h4>
                        <img src="<?php echo htmlspecialchars($pet['Image']); ?>"
                            alt="<?php echo htmlspecialchars($pet['Name']); ?>">
                        <p>Upload new image to replace current one</p>
                    </div>

                    <div class="form-grid">
                        <!-- Name Field -->
                        <div class="form-field">
                            <label for="name" class="required">Pet Name</label>
                            <input type="text" name="name" id="name" placeholder="Enter pet name"
                                value="<?php echo htmlspecialchars($pet['Name']); ?>" required>
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
                                value="<?php echo htmlspecialchars($pet['Breed']); ?>" required>
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
                                <option value="Male" <?php echo $pet['Gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo $pet['Gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>

                        <!-- Status Field -->
                        <div class="form-field">
                            <label for="status" class="required">Status</label>
                            <select name="status" id="status" required>
                                <option value="">-- Select Status --</option>
                                <option value="Available" <?php echo $pet['Status'] === 'Available' ? 'selected' : ''; ?>>Available</option>
                                <option value="Adopted" <?php echo $pet['Status'] === 'Adopted' ? 'selected' : ''; ?>>Adopted</option>
                                <option value="Pet Sit" <?php echo $pet['Status'] === 'Pet Sit' ? 'selected' : ''; ?>>Pet Sit</option>
                            </select>
                        </div>

                        <!-- Post Type Field -->
                        <div class="form-field">
                            <label for="postType" class="required">Post Type</label>
                            <select name="postType" id="postType" required>
                                <option value="Adopt" <?php echo $pet['PostType'] === 'Adopt' ? 'selected' : ''; ?>>Adopt</option>
                                <option value="Pet Sit" <?php echo $pet['PostType'] === 'Pet Sit' ? 'selected' : ''; ?>>Pet Sit</option>
                            </select>
                        </div>

                        <!-- Price Field -->
                        <div class="form-field" id="priceField" style="display:<?php echo ($pet['PostType'] === 'Pet Sit') ? 'block' : 'none'; ?>;">
                            <label for="priceInput" class="required">Price (RM/Day)</label>
                            <input type="number" name="price" id="priceInput" placeholder="0.00" min="0" step="0.01"
                                   value="<?php echo !empty($pet['Price']) ? number_format($pet['Price'], 2) : ''; ?>">
                        </div>

                        <!-- Image Field -->
                        <div class="form-field">
                            <label for="image">Pet Image (Optional)</label>
                            <input type="file" name="image" id="image" accept="image/*">
                            <small>Leave empty to keep current image</small>
                        </div>

                        <!-- Pet Sit Fields -->
                        <div id="petSitFields" class="pet-sit-fields"
                            style="display:<?php echo ($pet['PostType'] === 'Pet Sit') ? 'block' : 'none'; ?>;">
                            <h4>🐾 Pet Sitting Details</h4>
                            <div class="date-fields">
                                <div class="form-field">
                                    <label for="sitStartDate" class="required">Start Date</label>
                                    <input type="date" name="sit_start_date" id="sitStartDate"
                                        value="<?php echo htmlspecialchars($pet['SitStartDate'] ?? ''); ?>"
                                        min="<?php echo date('Y-m-d'); ?>" placeholder="Start Date">
                                </div>
                                <div class="form-field">
                                    <label for="sitEndDate" class="required">End Date</label>
                                    <input type="date" name="sit_end_date" id="sitEndDate"
                                        value="<?php echo htmlspecialchars($pet['SitEndDate'] ?? ''); ?>"
                                        min="<?php echo date('Y-m-d'); ?>" placeholder="End Date">
                                </div>
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
                        <input type="hidden" id="latitude" name="latitude" value="<?php echo !empty($pet['Latitude']) ? htmlspecialchars($pet['Latitude']) : ''; ?>" required>
                        <input type="hidden" id="longitude" name="longitude" value="<?php echo !empty($pet['Longitude']) ? htmlspecialchars($pet['Longitude']) : ''; ?>" required>
                    </div>

                    <!-- Description Field -->
                    <div class="form-field">
                        <label for="description">Description</label>
                        <textarea name="description" id="description" rows="4" placeholder="Tell us about your pet..."><?php echo htmlspecialchars($pet['Description']); ?></textarea>
                    </div>

                    <div class="form-actions">
                        <a href="myPets.php" class="btn cancel">Cancel</a>
                        <button type="submit" class="btn primary" <?php echo $isOverdue ? 'disabled' : ''; ?>>
                            <?php echo $isOverdue ? 'Editing Disabled' : 'Update Pet'; ?>
                        </button>
                    </div>
                </div>

                <div class="right-col">
                    <div class="map-card">
                        <h3>Location Preview</h3>
                        <div id="map"></div>
                        <p class="map-hint">📍 Select a district or click on the map to set coordinates</p>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ========== TYPE DROPDOWN HANDLER ==========
            const typeSelect = document.getElementById('type');
            const typeOtherField = document.getElementById('typeOtherField');
            const typeOtherInput = document.getElementById('type_other');

            if (typeSelect) {
                typeSelect.addEventListener('change', function() {
                    if (this.value === 'Other') {
                        typeOtherField.style.display = 'block';
                        typeOtherInput.required = true;
                    } else {
                        typeOtherField.style.display = 'none';
                        typeOtherInput.required = false;
                        typeOtherInput.value = '';
                    }
                });
            }

            // ========== FIXED DATE VALIDATION ==========

            // Fungsi untuk dapatkan hari ini dalam format YYYY-MM-DD (LOCAL TIME)
            function getTodayDate() {
                const today = new Date();
                const year = today.getFullYear();
                const month = String(today.getMonth() + 1).padStart(2, '0');
                const day = String(today.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            }

            // Fungsi untuk bandingkan tarikh (mengatasi masalah timezone)
            function isDateBefore(dateStr1, dateStr2) {
                if (!dateStr1 || !dateStr2) return false;

                // Create dates at MIDNIGHT (00:00:00) local time
                const date1 = new Date(dateStr1 + 'T00:00:00');
                const date2 = new Date(dateStr2 + 'T00:00:00');

                return date1 < date2;
            }

            // Fungsi untuk check jika tarikh adalah hari ini atau selepas hari ini
            function isDateTodayOrLater(dateStr) {
                if (!dateStr) return false;

                const today = new Date();
                const selectedDate = new Date(dateStr + 'T00:00:00');

                // Set both to midnight for fair comparison
                today.setHours(0, 0, 0, 0);
                selectedDate.setHours(0, 0, 0, 0);

                return selectedDate >= today;
            }

            // ========== POST TYPE TOGGLE ==========
            const postTypeSelect = document.getElementById('postType');
            const priceField = document.getElementById('priceField');
            const priceInput = document.getElementById('priceInput');
            const petSitFields = document.getElementById('petSitFields');
            const sitStartDate = document.getElementById('sitStartDate');
            const sitEndDate = document.getElementById('sitEndDate');

            // Post Type Toggle
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

                    // Initialize date validation when switching to Pet Sit
                    if (isPetSit) {
                        initializeDateValidation();
                    }

                    // Clear pet sit fields if switching away from Pet Sit
                    if (!isPetSit) {
                        if (sitStartDate) sitStartDate.value = '';
                        if (sitEndDate) sitEndDate.value = '';
                    }
                });
            }

            // Initialize date validation
            function initializeDateValidation() {
                if (!sitStartDate || !sitEndDate) return;

                const today = getTodayDate();

                // Set min dates for both inputs
                sitStartDate.min = today;
                sitEndDate.min = today;

                // If start date already has a value, update end date min
                if (sitStartDate.value) {
                    // Validate existing start date is not in the past
                    if (!isDateTodayOrLater(sitStartDate.value)) {
                        alert('⚠️ Start date cannot be in the past! Please select a valid date.');
                        sitStartDate.value = '';
                    } else {
                        sitEndDate.min = sitStartDate.value;

                        // Clear end date if it's now invalid
                        if (sitEndDate.value && isDateBefore(sitEndDate.value, sitStartDate.value)) {
                            sitEndDate.value = '';
                            alert('End date has been cleared because it was before the start date.');
                        }
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
                    alert('⚠️ Start date cannot be in the past!');
                    this.value = '';
                    return;
                }

                // Update end date's min attribute
                sitEndDate.min = startDateValue;

                // Clear end date if it's now invalid
                if (sitEndDate.value && isDateBefore(sitEndDate.value, startDateValue)) {
                    sitEndDate.value = '';
                    alert('End date has been cleared because it was before the new start date.');
                }
            }

            // Handle end date change
            function handleEndDateChange() {
                const endDateValue = this.value;

                if (!sitStartDate || !sitStartDate.value) return;

                // Validate end date is not in the past
                if (!isDateTodayOrLater(endDateValue)) {
                    alert('⚠️ End date cannot be in the past!');
                    this.value = '';
                    return;
                }

                // Validate end date is not before start
                if (isDateBefore(endDateValue, sitStartDate.value)) {
                    alert('⚠️ End date cannot be before start date!');
                    this.value = '';
                }
            }

            // Initialize date validation on page load (if Pet Sit is selected)
            const isPetSitSelected = '<?php echo $pet['PostType']; ?>' === 'Pet Sit';
            if (isPetSitSelected) {
                initializeDateValidation();
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

            // ========== MAP AND LOCATION ==========
            const existingLat = <?php echo !empty($pet['Latitude']) ? $pet['Latitude'] : '4.2105'; ?>;
            const existingLng = <?php echo !empty($pet['Longitude']) ? $pet['Longitude'] : '101.9758'; ?>;

            const map = L.map('map').setView([existingLat, existingLng], <?php echo !empty($pet['Latitude']) ? '11' : '6'; ?>);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            let previewMarker = null;

            <?php if (!empty($pet['Latitude']) && !empty($pet['Longitude'])): ?>
                previewMarker = L.circleMarker([existingLat, existingLng], {
                    radius: 8,
                    fillColor: '#ff7a2d',
                    color: '#ff7a2d',
                    weight: 1,
                    opacity: 1,
                    fillOpacity: 0.9
                }).addTo(map);
            <?php endif; ?>

            function setPreviewMarker(lat, lng) {
                if (previewMarker) map.removeLayer(previewMarker);
                previewMarker = L.circleMarker([lat, lng], {
                    radius: 8,
                    fillColor: '#ff7a2d',
                    color: '#ff7a2d',
                    weight: 1,
                    opacity: 1,
                    fillOpacity: 0.9
                }).addTo(map);
                document.getElementById('latitude').value = lat;
                document.getElementById('longitude').value = lng;
            }

            map.on('click', e => {
                const lat = e.latlng.lat.toFixed(6);
                const lng = e.latlng.lng.toFixed(6);
                setPreviewMarker(lat, lng);
            });

            // Load Malaysia districts data
            fetch('js/malaysia-daerah.json')
                .then(res => res.json())
                .then(json => {
                    const features = json.features || [];
                    const statesMap = {};

                    features.forEach(f => {
                        const props = f.properties || {};
                        const stateName = (props.state || props.STATE || props.State || '').trim();
                        const districtName = (props.name || props.daerah || props.NAME || '').trim();
                        if (!stateName || !districtName || !f.geometry) return;

                        const coord = f.geometry.coordinates?.[0]?.[0]?.[0];
                        if (!coord) return;

                        const [lng, lat] = coord;
                        if (!statesMap[stateName]) statesMap[stateName] = [];
                        statesMap[stateName].push({
                            name: districtName,
                            lat,
                            lng
                        });
                    });

                    const stateSelect = document.getElementById('state');
                    const districtSelect = document.getElementById('district');

                    Object.keys(statesMap).sort().forEach(state => {
                        const opt = document.createElement('option');
                        opt.value = state;
                        opt.textContent = state;
                        opt.selected = state === '<?php echo htmlspecialchars($pet['State']); ?>';
                        stateSelect.appendChild(opt);
                    });

                    const currentState = '<?php echo htmlspecialchars($pet['State']); ?>';
                    if (currentState && statesMap[currentState]) {
                        statesMap[currentState].forEach(d => {
                            const opt = document.createElement('option');
                            opt.value = d.name;
                            opt.textContent = d.name;
                            opt.selected = d.name === '<?php echo htmlspecialchars($pet['District']); ?>';
                            districtSelect.appendChild(opt);
                        });
                    }

                    stateSelect.addEventListener('change', () => {
                        districtSelect.innerHTML = '<option value="">-- Select District --</option>';
                        const selectedState = statesMap[stateSelect.value] || [];
                        selectedState.forEach(d => {
                            const opt = document.createElement('option');
                            opt.value = d.name;
                            opt.textContent = d.name;
                            districtSelect.appendChild(opt);
                        });
                    });

                    districtSelect.addEventListener('change', () => {
                        const s = stateSelect.value;
                        const d = districtSelect.value;
                        if (!s || !d) return;
                        const districtData = statesMap[s].find(x => x.name === d);
                        if (districtData) {
                            setPreviewMarker(districtData.lat, districtData.lng);
                        }
                    });
                });

            // ========== FORM VALIDATION ==========

            // Format price while typing
            function formatPriceInput(input) {
                let value = input.value.trim();
                value = value.replace(/[^\d.]/g, '');
                const parts = value.split('.');
                if (parts.length > 2) {
                    value = parts[0] + '.' + parts.slice(1).join('');
                }
                if (parts.length > 1 && parts[1].length > 2) {
                    value = parts[0] + '.' + parts[1].substring(0, 2);
                }
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

                return {
                    isValid: true,
                    value: numValue.toFixed(2)
                };
            }

            // Apply price validation
            if (priceInput) {
                priceInput.addEventListener('input', function() {
                    formatPriceInput(this);
                });

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
                    // If form is disabled (overdue), prevent submission
                    if (this.classList.contains('disabled-form')) {
                        e.preventDefault();
                        alert('Cannot edit overdue pet sitting listing');
                        return false;
                    }

                    const postType = document.getElementById('postType').value;
                    const typeSelect = document.getElementById('type');
                    const typeOtherInput = document.getElementById('type_other');

                    // Validate type if "Other" is selected
                    if (typeSelect && typeOtherInput) {
                        if (typeSelect.value === 'Other' && (!typeOtherInput.value || typeOtherInput.value.trim() === '')) {
                            e.preventDefault();
                            alert('Please enter a pet type when selecting "Other"');
                            typeOtherInput.focus();
                            return false;
                        }
                    }

                    // Validate for Pet Sit
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
                        }

                        // Validate dates
                        if (sitStartDate && sitEndDate) {
                            const today = new Date();
                            today.setHours(0, 0, 0, 0);

                            // Validate start date
                            if (!sitStartDate.value) {
                                e.preventDefault();
                                alert('Please select a start date for pet sitting');
                                sitStartDate.focus();
                                return false;
                            }

                            const startDate = new Date(sitStartDate.value + 'T00:00:00');
                            if (startDate < today) {
                                e.preventDefault();
                                alert('⚠️ Start date cannot be in the past!');
                                sitStartDate.focus();
                                return false;
                            }

                            // Validate end date
                            if (!sitEndDate.value) {
                                e.preventDefault();
                                alert('Please select an end date for pet sitting');
                                sitEndDate.focus();
                                return false;
                            }

                            const endDate = new Date(sitEndDate.value + 'T00:00:00');
                            if (endDate < today) {
                                e.preventDefault();
                                alert('⚠️ End date cannot be in the past!');
                                sitEndDate.focus();
                                return false;
                            }

                            // Validate end date is after start date
                            if (endDate <= startDate) {
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

            // ========== SIDEBAR FUNCTION ==========
            function toggleSidebar() {
                document.body.classList.toggle('sidebar-open');
            }
        });
    </script>
</body>

</html>