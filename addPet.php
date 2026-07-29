<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  header("Location: login.php");
  exit;
}

include 'connect.php';

// Define userID
$userID = $_SESSION['user_id'];

// Get unique pet types from database
$type_query = "SELECT DISTINCT Type FROM pet WHERE Type IS NOT NULL AND Type != '' ORDER BY Type";
$type_result = mysqli_query($conn, $type_query);
$pet_types = [];
while ($row = mysqli_fetch_assoc($type_result)) {
    $pet_types[] = $row['Type'];
}

// Ambil data admin dari database
$user_query = "SELECT * FROM user WHERE UserID = '$userID'";
$user_result = mysqli_query($conn, $user_query);
$user = mysqli_fetch_assoc($user_result);

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $ownerID = intval($_SESSION['user_id']);
  $name = $conn->real_escape_string($_POST['name'] ?? '');
  
  // Handle type - jika "Other" dipilih, gunakan input baru
  $type_input = $conn->real_escape_string($_POST['type'] ?? '');
  $type_other = $conn->real_escape_string($_POST['type_other'] ?? '');
  
  // Determine which type to use
  if ($type_input === 'Other' && !empty($type_other)) {
    $type = $type_other;
  } else {
    $type = $type_input;
  }
  
  $breed = $conn->real_escape_string($_POST['breed'] ?? '');

  // Calculate age from years and months
  $years = intval($_POST['years'] ?? 0);
  $months = intval($_POST['months'] ?? 0);

  // Ensure months is between 0-11
  $months = max(0, min(11, $months));
  $age = round($years + ($months / 12), 2); // Store as decimal

  $gender = $conn->real_escape_string($_POST['gender'] ?? '');
  $description = $conn->real_escape_string($_POST['description'] ?? '');
  $status = $conn->real_escape_string($_POST['status'] ?? '');
  $postType = $conn->real_escape_string($_POST['postType'] ?? 'Adopt');

  // Handle price properly dengan validation
  if ($postType === 'Pet Sit') {
    if (!isset($_POST['price']) || floatval($_POST['price']) <= 0) {
      $errorMsg = "Price is required for Pet Sitting service and must be greater than 0";
    } else {
      $price = $_POST['price'] !== '' ? floatval($_POST['price']) : 0.00;
    }

    // Validate dates untuk Pet Sit
    if (empty($_POST['sit_start_date'])) {
      $errorMsg = "Start date is required for Pet Sitting";
    } elseif (empty($_POST['sit_end_date'])) {
      $errorMsg = "End date is required for Pet Sitting";
    } elseif (strtotime($_POST['sit_end_date']) <= strtotime($_POST['sit_start_date'])) {
      $errorMsg = "End date must be after start date";
    }
  } else {
    $price = 0.00; // Adoption posts have price 0.00
  }

  $state = $conn->real_escape_string($_POST['state'] ?? '');
  $district = $conn->real_escape_string($_POST['district'] ?? '');
  $latitude = $_POST['latitude'] !== '' ? floatval($_POST['latitude']) : "NULL";
  $longitude = $_POST['longitude'] !== '' ? floatval($_POST['longitude']) : "NULL";

  // Pet Sit fields
  $sitStartDate = $_POST['sit_start_date'] ?? '';
  $sitEndDate = $_POST['sit_end_date'] ?? '';

  // Convert empty strings to NULL for database
  $sitStartDate = ($sitStartDate !== '') ? "'" . $conn->real_escape_string($sitStartDate) . "'" : "NULL";
  $sitEndDate = ($sitEndDate !== '') ? "'" . $conn->real_escape_string($sitEndDate) . "'" : "NULL";

  $postDate = date('Y-m-d H:i:s');

  // Admin posts are automatically approved
  $approvalStatus = 'approved';
  $approvedAt = "'" . date('Y-m-d H:i:s') . "'";
  $approvedBy = $userID;

  // handle image upload
  $imagePath = '';
  if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === 0) {
    $targetDir = 'uploads/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_FILES['image']['name']));
    $imagePath = $targetDir . $filename;
    move_uploaded_file($_FILES['image']['tmp_name'], $imagePath);
  } else {
    // Default image jika tidak upload
    $imagePath = 'assets/img/default-pet.jpg';
  }

  $imagePath = $conn->real_escape_string($imagePath);

  // INSERT QUERY dengan DIRECT QUERY (jika tiada error)
  if (!isset($errorMsg)) {
    $sql = "INSERT INTO pet 
          (OwnerID, Name, Type, Breed, Age, Gender, Description, Image, Status, 
           PostType, Price, State, District, Latitude, Longitude, PostDate, 
           ApprovalStatus, ApprovedBy, ApprovedAt, SitStartDate, SitEndDate)
          VALUES (
            $ownerID, 
            '$name', 
            '$type', 
            '$breed', 
            $age, 
            '$gender', 
            '$description', 
            '$imagePath', 
            '$status', 
            '$postType', 
            $price, 
            '$state', 
            '$district', 
            $latitude, 
            $longitude, 
            '$postDate', 
            '$approvalStatus', 
            $approvedBy, 
            $approvedAt, 
            $sitStartDate, 
            $sitEndDate
          )";

    if ($conn->query($sql)) {
      header("Location: adminManagePets.php?added=1");
      exit;
    } else {
      $errorMsg = "Database error: " . $conn->error;
    }
  }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <title>Add New Pet — FurCare Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="css/adminDashboard.css">
  <link rel="stylesheet" href="css/addPet.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
  <style>
    .form-container {
      display: flex;
      gap: 30px;
      align-items: flex-start;
      justify-content: center;
      width: 100%;
      max-width: 1100px;
      margin-top: 30px;
    }

    .left-col {
      flex: 1;
      background: #fff;
      padding: 25px;
      border-radius: 16px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
      display: flex;
      flex-direction: column;
      gap: 20px;
      max-width: 600px;
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

    /* Required field styling */
    input:required,
    select:required {
      border: 1px solid #ff7a2d;
      background-repeat: no-repeat;
      background-position: right 10px center;
      background-size: 8px 8px;
      padding-right: 25px !important;
    }

    /* Highlight untuk field yang invalid */
    input:invalid,
    select:invalid {
      border-color: #fc8181;
      background-color: #fff5f5;
    }

    .form-field input:focus,
    .form-field select:focus,
    .form-field textarea:focus {
      outline: none;
      border-color: #6DBE81;
      background: #fff;
      box-shadow: 0 0 0 3px rgba(109, 190, 129, 0.1);
    }

    .form-field textarea {
      resize: vertical;
      min-height: 100px;
    }

    .location-section {
      background: #f8fafc;
      padding: 20px;
      border-radius: 12px;
      border: 2px solid #e2e8f0;
    }

    .location-section h3 {
      margin: 0 0 15px 0;
      color: #3B7A57;
      font-size: 1.1em;
    }

    .location-inputs {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-bottom: 15px;
    }

    .auto-coordinates {
      background: rgba(109, 190, 129, 0.1);
      padding: 12px 15px;
      border-radius: 8px;
      font-size: 0.85em;
      color: #4a5568;
      border: 1px solid #6DBE81;
    }

    .right-col {
      flex: 1;
      max-width: 400px;
      position: sticky;
      top: 30px;
    }

    .map-card {
      background: #fff;
      padding: 20px;
      border-radius: 16px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    }

    .map-card h3 {
      margin: 0 0 15px 0;
      color: #3B7A57;
      font-size: 1.2em;
      font-weight: 600;
    }

    #map {
      height: 300px;
      border-radius: 12px;
      border: 2px solid #e2e8f0;
    }

    .map-hint {
      font-size: 0.85em;
      color: #718096;
      margin-top: 12px;
      line-height: 1.4;
    }

    .form-actions {
      display: flex;
      gap: 12px;
      margin-top: 20px;
      justify-content: center;
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
      width: 100%;
      max-width: 1100px;
      text-align: center;
    }

    /* Pet Sit Fields */
    .pet-sit-fields {
      background: linear-gradient(135deg, #fff9f5 0%, #fff0eb 100%);
      padding: 20px;
      border-radius: 12px;
      margin: 20px 0;
      border: 2px solid #FFB6C1;
    }

    .pet-sit-fields h4 {
      margin: 0 0 15px 0;
      color: #FF7A2D;
      font-size: 1.2em;
    }

    .date-fields {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 15px;
      margin-bottom: 15px;
    }

    .calculation-display {
      background: white;
      padding: 15px;
      border-radius: 8px;
      font-size: 1em;
      border: 2px solid #FF7A2D;
      text-align: center;
    }

    .calculation-display span {
      font-weight: bold;
      color: #3B7A57;
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

    /* Pet Sit required fields styling */
    .pet-sit-fields input:required {
      border-color: #FF7A2D;
      background-color: #fff9f5;
    }

    /* Responsive */
    @media (max-width: 1024px) {
      .form-container {
        flex-direction: column;
        align-items: center;
      }

      .right-col {
        max-width: 100%;
        position: static;
        width: 100%;
      }

      .left-col {
        max-width: 100%;
        width: 100%;
      }
    }

    @media (max-width: 768px) {
      .main-content {
        padding: 15px;
      }

      .form-grid {
        grid-template-columns: 1fr;
      }

      .location-inputs {
        grid-template-columns: 1fr;
      }

      .date-fields {
        grid-template-columns: 1fr;
      }

      .age-input-group {
        grid-template-columns: 1fr;
      }

      .form-actions {
        flex-direction: column;
        align-items: center;
      }

      .btn {
        width: 100%;
        max-width: 200px;
      }
    }

    @media (max-width: 480px) {

      .left-col,
      .map-card {
        padding: 15px;
      }

      .title {
        font-size: 1.8em;
      }

      .subtitle {
        font-size: 1em;
      }

      .container {
        padding: 10px;
      }
    }
  </style>
</head>

<body>
  <!-- SIDEBAR (Admin Only) -->
  <div class="sidebar">
    <h2 class="logo">FurCare</h2>
    <a href="adminDashboard.php">🗂️ Main Menu</a>
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

  <div class="main-content">
    <div class="navbar">
      <h1>Add New Pet</h1>
      <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
        alt="Profile"
        class="profile-icon">
    </div>

    <?php if (!empty($errorMsg)): ?>
      <div class="error-box">Error: <?php echo htmlspecialchars($errorMsg); ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
      <div class="error-box">Error: <?php echo htmlspecialchars($_GET['error']); ?></div>
    <?php endif; ?>

    <form class="add-pet-form" method="POST" enctype="multipart/form-data">
      <div class="form-container">
        <div class="left-col">
          <div class="form-grid">
            <!-- Name Field -->
            <div class="form-field">
              <label for="name" class="required">Pet Name</label>
              <input type="text" name="name" id="name" placeholder="Enter pet name" required>
            </div>

            <!-- Type Field -->
            <div class="form-field">
              <label for="type" class="required">Pet Type</label>
              <select name="type" id="type" required>
                <option value="">-- Select Type --</option>
                <?php foreach ($pet_types as $type_option): ?>
                  <option value="<?php echo htmlspecialchars($type_option); ?>">
                    <?php echo htmlspecialchars($type_option); ?>
                  </option>
                <?php endforeach; ?>
                <option value="Other">Other (Specify below)</option>
              </select>
              <div class="type-other-field" id="typeOtherField">
                <input type="text" name="type_other" id="type_other" placeholder="Enter new pet type">
              </div>
            </div>

            <!-- Breed Field -->
            <div class="form-field">
              <label for="breed" class="required">Breed</label>
              <input type="text" name="breed" id="breed" placeholder="Enter breed" required>
            </div>

            <!-- Age Field -->
            <div class="form-field age-container">
              <label class="required">Age</label>
              <div class="age-input-group">
                <div class="age-input-wrapper">
                  <input type="number" name="years" id="yearsInput" placeholder="Years" min="0" max="50" value="0" required>
                  <small>Years (0-50)</small>
                </div>
                <div class="age-input-wrapper">
                  <input type="number" name="months" id="monthsInput" placeholder="Months" min="0" max="11" value="0" required>
                  <small>Months (0-11)</small>
                </div>
              </div>
              <div class="age-display">
                <strong>Age:</strong> <span id="ageDisplay">0 years 0 months</span>
                <br>
                <small style="color: #718096;">(Will be stored as: <span id="ageDecimal">0.00</span> years in database)</small>
              </div>
            </div>

            <!-- Gender Field -->
            <div class="form-field">
              <label for="gender" class="required">Gender</label>
              <select name="gender" id="gender" required>
                <option value="">-- Select Gender --</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
              </select>
            </div>

            <!-- Status Field -->
            <div class="form-field">
              <label for="status" class="required">Status</label>
              <select name="status" id="status" required>
                <option value="">-- Select Status --</option>
                <option value="Available">Available</option>
                <option value="Adopted">Adopted</option>
                <option value="Pet Sit">Pet Sit</option>
              </select>
            </div>

            <!-- Post Type Field -->
            <div class="form-field">
              <label for="postType" class="required">Post Type</label>
              <select name="postType" id="postType" required>
                <option value="Adopt">Adoption</option>
                <option value="Pet Sit">Pet Sitting</option>
              </select>
            </div>

            <!-- Price Field (hidden by default) -->
            <div class="form-field" id="priceField" style="display: none;">
              <label for="priceInput" class="required">Price (RM/Day)</label>
              <input type="number" name="price" id="priceInput" placeholder="0.00" min="0" step="0.01" pattern="^\d+(\.\d{1,2})?$"
                     oninvalid="this.setCustomValidity('Please enter price for pet sitting service')"
                     oninput="this.setCustomValidity('')">
            </div>

            <!-- Image Field -->
            <div class="form-field">
              <label for="image" class="required">Pet Image</label>
              <input type="file" name="image" id="image" accept="image/*" required>
            </div>
          </div>

          <!-- Pet Sit Fields Section -->
          <div id="petSitFields" class="pet-sit-fields" style="display: none;">
            <h4>🐾 Pet Sitting Details</h4>
            
            <div class="date-fields">
              <div class="form-field">
                <label for="sitStartDate" class="required">Start Date</label>
                <input type="date" name="sit_start_date" id="sitStartDate" placeholder="Start Date"
                  min="<?php echo date('Y-m-d'); ?>"
                  oninvalid="this.setCustomValidity('Please select start date for pet sitting')"
                  oninput="this.setCustomValidity('')">
              </div>
              
              <div class="form-field">
                <label for="sitEndDate" class="required">End Date</label>
                <input type="date" name="sit_end_date" id="sitEndDate" placeholder="End Date"
                  min="<?php echo date('Y-m-d'); ?>"
                  oninvalid="this.setCustomValidity('Please select end date for pet sitting')"
                  oninput="this.setCustomValidity('')">
              </div>
            </div>

            <div class="calculation-display" id="calculationDisplay" style="display: none;">
              Duration: <span id="durationDisplay">0</span> days |
              Total: RM <span id="totalDisplay">0.00</span>
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
              <span>📍 Coordinates will be auto-filled when you select a district or click on the map</span>
              <div>
                <strong>Latitude:</strong> <span id="latDisplay">Not set</span> |
                <strong>Longitude:</strong> <span id="lngDisplay">Not set</span>
              </div>
            </div>

            <input type="hidden" id="latitude" name="latitude" required>
            <input type="hidden" id="longitude" name="longitude" required>
          </div>

          <!-- Description Field -->
          <div class="form-field">
            <label for="description">Description</label>
            <textarea name="description" id="description" rows="4" placeholder="Tell us about your pet's personality, habits, and any special needs..."></textarea>
          </div>

          <div class="form-actions">
            <a href="adminManagePets.php" class="btn cancel">Cancel</a>
            <button type="submit" class="btn primary">Save Pet</button>
          </div>
        </div>

        <div class="right-col">
          <div class="map-card">
            <h3>Location Preview</h3>
            <div id="map"></div>
            <p class="map-hint">📍 Select a district or click on the map to set coordinates automatically</p>
          </div>
        </div>
      </div>
    </form>
  </div>

  <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
  <script>
    // Set min dates untuk start dan end date - DYNAMIC (FIXED)
    function setMinDates() {
      const today = new Date();
      // Format: YYYY-MM-DD (LOCAL TIME, bukan UTC)
      const todayStr = today.getFullYear() + '-' +
        String(today.getMonth() + 1).padStart(2, '0') + '-' +
        String(today.getDate()).padStart(2, '0');

      const startDateInput = document.getElementById('sitStartDate');
      const endDateInput = document.getElementById('sitEndDate');

      if (startDateInput) {
        startDateInput.min = todayStr;
        // Jika value sebelumnya sudah outdated, reset
        if (startDateInput.value && startDateInput.value < todayStr) {
          startDateInput.value = '';
        }
      }
      if (endDateInput) {
        endDateInput.min = todayStr;
        // Jika value sebelumnya sudah outdated, reset
        if (endDateInput.value && endDateInput.value < todayStr) {
          endDateInput.value = '';
        }
      }
    }

    // Handle Type dropdown with "Other" option
    document.getElementById('type').addEventListener('change', function() {
      const typeOtherField = document.getElementById('typeOtherField');
      const typeOtherInput = document.getElementById('type_other');
      
      if (this.value === 'Other') {
        typeOtherField.style.display = 'block';
        typeOtherInput.required = true;
      } else {
        typeOtherField.style.display = 'none';
        typeOtherInput.required = false;
        typeOtherInput.value = '';
      }
    });

    // Age Calculation - Tahun dan Bulan Separate
    document.addEventListener('DOMContentLoaded', function() {
      const yearsInput = document.getElementById('yearsInput');
      const monthsInput = document.getElementById('monthsInput');
      const ageDisplay = document.getElementById('ageDisplay');
      const ageDecimal = document.getElementById('ageDecimal');

      function updateAgeDisplay() {
        const years = parseInt(yearsInput.value) || 0;
        const months = parseInt(monthsInput.value) || 0;

        // Calculate total years (decimal) - FIXED CALCULATION
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

        ageDisplay.textContent = displayText;
        ageDecimal.textContent = totalYears.toFixed(2); // 2 decimal places
      }

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

      // Initialize dengan nilai default
      updateAgeDisplay();

      // Panggil setMinDates() pada page load
      setMinDates();
    });

    // Toggle price field and pet sit fields based on post type
    document.getElementById('postType').addEventListener('change', function() {
      const isPetSit = this.value === 'Pet Sit';
      const priceField = document.getElementById('priceField');
      const priceInput = document.getElementById('priceInput');
      const petSitFields = document.getElementById('petSitFields');
      const startDateInput = document.getElementById('sitStartDate');
      const endDateInput = document.getElementById('sitEndDate');

      // Toggle display
      priceField.style.display = isPetSit ? 'block' : 'none';
      petSitFields.style.display = isPetSit ? 'block' : 'none';

      // Toggle required attributes
      if (priceInput) priceInput.required = isPetSit;
      if (startDateInput) startDateInput.required = isPetSit;
      if (endDateInput) endDateInput.required = isPetSit;

      // Reset pet sit fields jika switch balik ke adoption
      if (!isPetSit) {
        if (startDateInput) {
          startDateInput.value = '';
          startDateInput.required = false;
        }
        if (endDateInput) {
          endDateInput.value = '';
          endDateInput.required = false;
        }
        document.getElementById('calculationDisplay').style.display = 'none';
        if (priceInput) priceInput.value = ''; // Clear price value
      } else {
        // Pastikan setMinDates() dipanggil di sini juga
        setMinDates(); // Set min dates apabila pilih Pet Sit
      }

      // Trigger validation untuk update required state
      if (priceInput) priceInput.reportValidity();
      if (startDateInput) startDateInput.reportValidity();
      if (endDateInput) endDateInput.reportValidity();
    });

    // Calculate duration and total price for pet sitting - FIXED: INCLUDE START DATE
    function calculatePetSitTotal() {
      const startDateInput = document.getElementById('sitStartDate');
      const endDateInput = document.getElementById('sitEndDate');
      const priceInput = document.getElementById('priceInput');

      const startDate = startDateInput ? startDateInput.value : '';
      const endDate = endDateInput ? endDateInput.value : '';
      const dailyRate = priceInput ? parseFloat(priceInput.value) || 0 : 0;

      if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const timeDiff = end.getTime() - start.getTime();

        // Include start date in calculation (add +1)
        const daysDiff = Math.floor(timeDiff / (1000 * 3600 * 24)) + 1;

        if (daysDiff > 0) {
          const totalAmount = daysDiff * dailyRate;
          document.getElementById('durationDisplay').textContent = daysDiff;
          document.getElementById('totalDisplay').textContent = totalAmount.toFixed(2);
          document.getElementById('calculationDisplay').style.display = 'block';
        } else {
          document.getElementById('calculationDisplay').style.display = 'none';
          alert('End date must be after start date');
          if (endDateInput) endDateInput.value = '';
        }
      } else {
        document.getElementById('calculationDisplay').style.display = 'none';
      }
    }

    // Add event listeners untuk date dan price changes
    const startDateInput = document.getElementById('sitStartDate');
    const endDateInput = document.getElementById('sitEndDate');
    const priceInput = document.getElementById('priceInput');

    if (startDateInput) {
      startDateInput.addEventListener('change', function() {
        const startDate = this.value;

        // Get today's date in YYYY-MM-DD format
        const today = new Date();
        const todayStr = today.getFullYear() + '-' +
          String(today.getMonth() + 1).padStart(2, '0') + '-' +
          String(today.getDate()).padStart(2, '0');

        // Set min untuk end date
        if (endDateInput) {
          // Gunakan yang lebih besar antara start date dan today
          const minDate = startDate > todayStr ? startDate : todayStr;
          endDateInput.min = minDate;

          // Reset end date jika start date lebih baru
          const endDate = endDateInput.value;
          if (endDate && endDate < minDate) {
            endDateInput.value = '';
            document.getElementById('calculationDisplay').style.display = 'none';
          }
        }

        calculatePetSitTotal();
      });
    }

    if (endDateInput) {
      endDateInput.addEventListener('change', calculatePetSitTotal);
    }

    if (priceInput) {
      priceInput.addEventListener('input', calculatePetSitTotal);
    }

    // Initialize map
    const map = L.map('map').setView([4.2105, 101.9758], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    let previewMarker = null;

    function updateCoordinateDisplay(lat, lng) {
      document.getElementById('latDisplay').textContent = lat || 'Not set';
      document.getElementById('lngDisplay').textContent = lng || 'Not set';
    }

    function setPreviewMarker(lat, lng) {
      if (!isFinite(lat) || !isFinite(lng)) return;
      if (previewMarker) map.removeLayer(previewMarker);
      previewMarker = L.circleMarker([lat, lng], {
        radius: 8,
        fillColor: '#ff7a2d',
        color: '#ff7a2d',
        weight: 1,
        opacity: 1,
        fillOpacity: 0.9
      }).addTo(map);
      map.setView([lat, lng], 11);

      // Update hidden inputs and display
      document.getElementById('latitude').value = lat;
      document.getElementById('longitude').value = lng;
      updateCoordinateDisplay(lat, lng);
    }

    // Handle map click
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
          stateSelect.appendChild(opt);
        });

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
      })
      .catch(err => {
        console.error('Error loading districts data:', err);
        document.getElementById('state').innerHTML = '<option value="">Error loading states</option>';
      });

    // Initialize coordinate display
    updateCoordinateDisplay();

    // Function to validate price input
    function validatePriceInput() {
      const priceInput = document.getElementById('priceInput');
      if (!priceInput) return true;

      const priceValue = priceInput.value;

      // Allow empty (will be treated as 0 later)
      if (priceValue === '') return true;

      // Check if it's a valid number with max 2 decimal places
      const regex = /^\d+(\.\d{1,2})?$/;

      if (!regex.test(priceValue)) {
        alert('Price must be a number with maximum 2 decimal places (e.g., 25.50)');
        priceInput.value = '';
        priceInput.focus();
        return false;
      }

      // Ensure it's not negative
      if (parseFloat(priceValue) < 0) {
        alert('Price cannot be negative');
        priceInput.value = '';
        priceInput.focus();
        return false;
      }

      // Ensure it's greater than 0 for Pet Sit
      const postType = document.getElementById('postType').value;
      if (postType === 'Pet Sit' && parseFloat(priceValue) <= 0) {
        alert('Price must be greater than 0 for Pet Sitting service');
        priceInput.value = '';
        priceInput.focus();
        return false;
      }

      // Round to 2 decimal places
      const roundedValue = parseFloat(priceValue).toFixed(2);
      if (priceValue !== roundedValue) {
        priceInput.value = roundedValue;
      }

      return true;
    }

    // Add event listener for price input
    const priceInputEl = document.getElementById('priceInput');
    if (priceInputEl) {
      priceInputEl.addEventListener('blur', validatePriceInput);
      priceInputEl.addEventListener('input', function(e) {
        // Prevent more than 2 decimal places while typing
        const value = e.target.value;
        const decimalParts = value.split('.');

        if (decimalParts.length > 1 && decimalParts[1].length > 2) {
          e.target.value = decimalParts[0] + '.' + decimalParts[1].substring(0, 2);
        }
      });
    }

    // Validate form sebelum submission (DENGAN PAST DATE VALIDATION)
    document.querySelector('.add-pet-form').addEventListener('submit', function(e) {
      const postType = document.getElementById('postType').value;
      const priceInput = document.getElementById('priceInput');
      const startDateInput = document.getElementById('sitStartDate');
      const endDateInput = document.getElementById('sitEndDate');
      const typeSelect = document.getElementById('type');
      const typeOtherInput = document.getElementById('type_other');

      // Validate type if "Other" is selected
      if (typeSelect && typeSelect.value === 'Other' && (!typeOtherInput || !typeOtherInput.value || typeOtherInput.value.trim() === '')) {
        e.preventDefault();
        alert('Please enter a pet type when selecting "Other"');
        if (typeOtherInput) typeOtherInput.focus();
        return false;
      }

      // Validation untuk Pet Sit
      if (postType === 'Pet Sit') {
        // Validate price
        if (priceInput && (!priceInput.value || parseFloat(priceInput.value) <= 0)) {
          e.preventDefault();
          alert('Please enter a valid price for pet sitting service (must be greater than 0)');
          priceInput.focus();
          return false;
        }

        // Validate price format (2 decimal places)
        if (priceInput && !validatePriceInput()) {
          e.preventDefault();
          return false;
        }

        // Validate dates
        if (startDateInput && endDateInput) {
          if (!startDateInput.value || !endDateInput.value) {
            e.preventDefault();
            alert('Please select both start and end dates for pet sitting');
            if (!startDateInput.value) startDateInput.focus();
            else endDateInput.focus();
            return false;
          }

          // Get today's date at midnight (local time)
          const today = new Date();
          today.setHours(0, 0, 0, 0);

          // Get selected dates
          const selectedStartDate = new Date(startDateInput.value);
          const selectedEndDate = new Date(endDateInput.value);

          // Set time to midnight for fair comparison
          selectedStartDate.setHours(0, 0, 0, 0);
          selectedEndDate.setHours(0, 0, 0, 0);

          // Validate dates are not in the past (backup validation)
          if (selectedStartDate < today) {
            e.preventDefault();
            alert('Start date cannot be in the past. Please select a valid date.');
            startDateInput.focus();
            return false;
          }

          if (selectedEndDate < today) {
            e.preventDefault();
            alert('End date cannot be in the past. Please select a valid date.');
            endDateInput.focus();
            return false;
          }

          // Validate end date is after start date
          if (selectedEndDate <= selectedStartDate) {
            e.preventDefault();
            alert('End date must be after start date');
            endDateInput.focus();
            return false;
          }
        }
      }

      // Ensure price has 2 decimal places
      if (priceInput && priceInput.value !== '' && postType === 'Pet Sit') {
        priceInput.value = parseFloat(priceInput.value).toFixed(2);
      }

      return true;
    });

    // Force min dates setiap 10 saat (untuk handle jika page dibuka lama)
    setInterval(setMinDates, 10000); // Update setiap 10 saat
  </script>
</body>

</html>