<?php
session_start();
include 'connect.php';

$userID = $_SESSION['user_id'];

// Admin authentication
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];
$requestID = $_GET['request_id'] ?? 0;

// Validate request ID
if (!$requestID || !is_numeric($requestID)) {
    header("Location: adminPetSitRequests.php?error=Invalid request ID");
    exit;
}

// Fetch request details (admin can view any request)
$stmt = $conn->prepare("SELECT psr.*, 
                               p.Name AS PetName, p.Image AS PetImage,
                               p.SitStartDate AS PetSitStartDate, 
                               p.SitEndDate AS PetSitEndDate,
                               sitter.Name AS SitterName, sitter.UserID AS SitterID,
                               sitter.Email AS SitterEmail,
                               owner.Name AS OwnerName, owner.UserID AS OwnerID,
                               psr.TotalDays, psr.DailyRate, psr.TotalAmount
                        FROM petsitrequest psr
                        JOIN pet p ON psr.PetID = p.PetID
                        JOIN user sitter ON psr.SitterID = sitter.UserID
                        JOIN user owner ON psr.OwnerID = owner.UserID
                        WHERE psr.SitRequestID = ? AND psr.Status = 'approved'");
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param("i", $requestID);
$stmt->execute();
$result = $stmt->get_result();
$request = $result->fetch_assoc();

if (!$request) {
    header("Location: adminPetSitRequests.php?error=Request not found or not approved");
    exit;
}

// Debug: Check if we got the data
if (!$request) {
    die("Error: Could not fetch request details. Request ID: " . $requestID);
}

// Check if payment already exists
$payment_check_sql = "SELECT * FROM payment WHERE SitRequestID = ?";
$payment_check_stmt = $conn->prepare($payment_check_sql);
$payment_check_stmt->bind_param("i", $requestID);
$payment_check_stmt->execute();
$existing_payment = $payment_check_stmt->get_result()->fetch_assoc();

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paymentMethod = $_POST['payment_method'] ?? '';
    $cardNumber = $_POST['card_number'] ?? '';
    $cardExpiry = $_POST['card_expiry'] ?? '';
    $cardCVC = $_POST['card_cvc'] ?? '';
    $cardName = $_POST['card_name'] ?? '';
    $bankName = $_POST['bank_name'] ?? '';
    $ewalletProvider = $_POST['ewallet_provider'] ?? '';

    // Validate payment method
    if (!in_array($paymentMethod, ['card', 'online_banking', 'ewallet'])) {
        $error = "Please select a valid payment method";
    } else {
        // Validate based on payment method
        if ($paymentMethod === 'card') {
            if (empty($cardNumber) || empty($cardExpiry) || empty($cardCVC) || empty($cardName)) {
                $error = "Please fill all card details";
            }
        } elseif ($paymentMethod === 'online_banking') {
            if (empty($bankName)) {
                $error = "Please select a bank";
            }
        } elseif ($paymentMethod === 'ewallet') {
            if (empty($ewalletProvider)) {
                $error = "Please select an e-wallet provider";
            }
        }

        if (!isset($error)) {
            // Start transaction for safety
            $conn->begin_transaction();

            try {
                // Generate receipt number
                $receiptNumber = 'RCP' . date('Ymd') . str_pad($requestID, 6, '0', STR_PAD_LEFT);

                // Calculate commission (10%) and sitter earnings (90%)
                $totalAmount = $request['TotalAmount'];
                $commission = $totalAmount * 0.10;
                $sitterEarnings = $totalAmount * 0.90;

                if ($existing_payment) {
                    // Update existing payment
                    $update_sql = "UPDATE payment SET 
                                  PaymentMethod = ?, 
                                  PaymentStatus = 'paid',
                                  ReceiptNumber = ?,
                                  PaymentDate = NOW(),
                                  Commission = ?,
                                  SitterEarnings = ?
                                  WHERE SitRequestID = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("ssddi", $paymentMethod, $receiptNumber, $commission, $sitterEarnings, $requestID);
                    $update_stmt->execute();
                } else {
                    // Create new payment record (admin acting as payer)
                    $insert_sql = "INSERT INTO payment (
                                  SitRequestID, PayerID, SitterID, Amount, 
                                  PaymentMethod, PaymentStatus, ReceiptNumber,
                                  ServiceDescription, ServicePeriod,
                                  Commission, SitterEarnings, PaymentDate
                                  ) VALUES (?, ?, ?, ?, ?, 'paid', ?, ?, ?, ?, ?, NOW())";

                    $serviceDescription = "Pet sitting for " . htmlspecialchars($request['PetName']);
                    $servicePeriod = date('M j, Y', strtotime($request['PetSitStartDate'])) . " to " .
                        date('M j, Y', strtotime($request['PetSitEndDate']));

                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param(
                        "iiidssssdd",
                        $requestID,
                        $userID, // Admin sebagai payer
                        $request['SitterID'],
                        $totalAmount,
                        $paymentMethod,
                        $receiptNumber,
                        $serviceDescription,
                        $servicePeriod,
                        $commission,
                        $sitterEarnings
                    );
                    $insert_stmt->execute();
                }

                // AUTO-CREATE WALLET FOR SITTER (if doesn't exist)
                $wallet_check = "SELECT * FROM user_wallet WHERE UserID = ?";
                $wallet_stmt = $conn->prepare($wallet_check);
                $wallet_stmt->bind_param("i", $request['SitterID']);
                $wallet_stmt->execute();

                if (!$wallet_stmt->get_result()->fetch_assoc()) {
                    $create_wallet = "INSERT INTO user_wallet (UserID, Balance, LastUpdated) VALUES (?, 0, NOW())";
                    $wallet_create_stmt = $conn->prepare($create_wallet);
                    $wallet_create_stmt->bind_param("i", $request['SitterID']);
                    $wallet_create_stmt->execute();
                }

                $conn->commit();

                echo "
    <script>
        alert('✅ PAYMENT SUCCESSFUL!\\\\n\\\\nReceipt: $receiptNumber\\\\nAmount: RM" . number_format($totalAmount, 2) . "\\\\n\\\\nSitter has been notified about the payment.');
        window.location.href = 'adminPetSitRequestDetails.php?request_id=$requestID&success=Payment+completed+successfully!+Receipt:+$receiptNumber';
    </script>";
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to process payment: " . $e->getMessage();
            }
        }
    }
}

// Fetch admin user data
$user_stmt = $conn->prepare("SELECT * FROM user WHERE UserID = ?");
$user_stmt->bind_param("i", $userID);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();

// Debug: Check user data
if (!$user) {
    die("Error: Could not fetch admin user data");
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Make Payment - Admin - FurCare</title>
    <link rel="stylesheet" href="css/adminDashboard.css">
    <style>
        /* HORIZONTAL LAYOUT STYLES */
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            background-color: #FFF9F5;
            color: #333;
            transition: 0.3s;
        }

        .payment-container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 30px;
        }

        .page-header {
            margin-bottom: 30px;
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: #3B7A57;
            margin-bottom: 8px;
        }

        .page-subtitle {
            color: #7f8c8d;
            font-size: 1rem;
        }

        /* HORIZONTAL LAYOUT */
        .horizontal-layout {
            display: flex;
            gap: 25px;
            align-items: stretch;
            min-height: 650px;
        }

        .left-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .right-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        @media (max-width: 1200px) {
            .horizontal-layout {
                flex-direction: column;
                gap: 25px;
            }
        }

        /* CARD STYLES */
        .summary-card {
            background: white;
            border: 1px solid #e1e8ed;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .payment-card {
            background: white;
            border: 1px solid #e1e8ed;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f3f4;
        }

        /* ORDER SUMMARY - HORIZONTAL TABLE STYLE */
        .summary-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
            padding-bottom: 25px;
            border-bottom: 1px solid #f0f3f4;
        }

        .pet-image-container {
            width: 100px;
            height: 100px;
            border-radius: 8px;
            overflow: hidden;
            border: 3px solid white;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .pet-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .summary-header-content h3 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
            margin: 0 0 5px 0;
        }

        .summary-header-content p {
            color: #7f8c8d;
            margin: 0;
            font-size: 0.95rem;
        }

        /* SUMMARY TABLE - PROFESSIONAL STYLE */
        .summary-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 20px;
        }

        .summary-table tr {
            border-bottom: 1px solid #f5f7f9;
        }

        .summary-table tr:last-child {
            border-bottom: none;
        }

        .summary-table td {
            padding: 12px 0;
            vertical-align: middle;
        }

        .summary-table td:first-child {
            color: #5d6d7e;
            font-weight: 500;
            width: 60%;
        }

        .summary-table td:last-child {
            color: #2c3e50;
            font-weight: 500;
            text-align: right;
            width: 40%;
        }

        /* EARNINGS BREAKDOWN */
        .earnings-breakdown {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #a8eeb8ff;
        }

        .breakdown-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 1rem;
        }

        .breakdown-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .breakdown-table td {
            padding: 8px 0;
            vertical-align: middle;
        }

        .breakdown-table td:first-child {
            color: #5d6d7e;
            font-size: 0.95rem;
        }

        .breakdown-table td:last-child {
            color: #2c3e50;
            font-weight: 500;
            text-align: right;
            font-size: 0.95rem;
        }

        /* TOTAL AMOUNT */
        .summary-total {
            background: #618e68c2;
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .total-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .total-table td {
            padding: 10px 0;
            vertical-align: middle;
        }

        .total-table td:first-child {
            color: rgba(255, 255, 255, 0.9);
            font-size: 1.1rem;
            font-weight: 500;
        }

        .total-table td:last-child {
            color: white;
            font-size: 1.4rem;
            font-weight: 600;
            text-align: right;
        }

        /* PAYMENT FORM STYLES */
        .payment-form-container {
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .form-content {
            flex: 1;
            overflow-y: auto;
            padding-right: 10px;
        }

        /* PAYMENT METHODS - PROFESSIONAL HORIZONTAL */
        .payment-methods-container {
            margin-bottom: 30px;
        }

        .payment-methods-label {
            display: block;
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 15px;
            font-size: 1rem;
        }

        .payment-methods-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .payment-method-option {
            border: 2px solid #e1e8ed;
            border-radius: 8px;
            padding: 20px 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            background: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100px;
        }

        .payment-method-option:hover {
            border-color: #3498db;
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .payment-method-option.selected {
            border-color: #2980b9;
            background: #e8f4fc;
            box-shadow: 0 4px 12px rgba(41, 128, 185, 0.15);
        }

        .payment-method-option input[type="radio"] {
            display: none;
        }

        .method-icon {
            font-size: 24px;
            margin-bottom: 10px;
            color: #3498db;
            font-weight: bold;
        }

        .method-name {
            font-weight: 500;
            font-size: 0.95rem;
            color: #2c3e50;
        }

        /* PAYMENT DETAILS SECTIONS */
        .payment-details-section {
            display: none;
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            margin-top: 20px;
            border: 1px solid #e1e8ed;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: #2c3e50;
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        input[type="text"],
        select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #dce1e6;
            border-radius: 6px;
            font-size: 0.95rem;
            color: #2c3e50;
            background: white;
            box-sizing: border-box;
            transition: all 0.3s ease;
        }

        input[type="text"]:focus,
        select:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        /* BANK SELECTION - PROFESSIONAL */
        .bank-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 10px;
        }

        .bank-option {
            border: 1px solid #e1e8ed;
            border-radius: 6px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .bank-option:hover {
            border-color: #3498db;
            background: #f8f9fa;
        }

        .bank-option.selected {
            border-color: #2980b9;
            background: #e8f4fc;
        }

        .bank-logo {
            width: 40px;
            height: 40px;
            background: #f8f9fa;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #2c3e50;
            border: 1px solid #e1e8ed;
        }

        .bank-name {
            font-weight: 500;
            color: #2c3e50;
            flex: 1;
        }

        /* BUTTONS */
        .payment-actions {
            margin-top: auto;
            padding-top: 25px;
            border-top: 1px solid #f0f3f4;
        }

        .btn-pay-admin {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: smaller;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 15px;
            letter-spacing: 0.5px;
        }

        .btn-pay-admin:hover {
            background: linear-gradient(135deg, #218838 0%, #1e7e34 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(40, 167, 69, 0.2);
        }

        .btn-pay-admin:disabled {
            background: #95a5a6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-back {
            display: block;
            width: 95%;
            padding: 14px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: smaller;
            font-weight: 500;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .btn-back:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.2);
            color: white;
            text-decoration: none;
        }

        /* SECURITY NOTICE */
        .security-notice {
            background: #f8f9fa;
            border: 1px solid #e1e8ed;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            font-size: 0.9rem;
            color: #5d6d7e;
            border-left: 4px solid #ff5c5cff;
        }

        .security-notice strong {
            color: #2c3e50;
            display: block;
            margin-bottom: 5px;
        }

        /* ERROR AND SUCCESS MESSAGES */
        .error-message {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            color: #721c24;
            font-size: 0.95rem;
            border-left: 4px solid #dc3545;
        }

        .success-message {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            color: #155724;
            font-size: 0.95rem;
            border-left: 4px solid #28a745;
        }

        /* ADMIN NOTICE */
        .admin-notice {
            background: #e3f2fd;
            border: 1px solid #90caf9;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            border-left: 4px solid #b6d1ecff;
        }

        .admin-notice strong {
            color: #5f646aff;
            margin-right: 5px;
        }

        /* RESPONSIVE DESIGN */
        @media (max-width: 1200px) {
            .payment-methods-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .bank-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .payment-container {
                padding: 0 20px;
            }

            .payment-methods-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .bank-grid {
                grid-template-columns: 1fr;
            }

            .summary-header {
                flex-direction: column;
                text-align: center;
            }

            .pet-image-container {
                width: 120px;
                height: 120px;
            }

            .summary-card,
            .payment-card {
                padding: 20px;
            }
        }

        @media (max-width: 480px) {
            .payment-container {
                padding: 0 15px;
            }

            .page-title {
                font-size: 1.6rem;
            }

            .card-title {
                font-size: 1.1rem;
            }
        }

        /* SCROLLBAR STYLING */
        .form-content::-webkit-scrollbar {
            width: 6px;
        }

        .form-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .form-content::-webkit-scrollbar-thumb {
            background: #3498db;
            border-radius: 10px;
        }

        .text-success {
            color: #28a745;
        }

        .text-primary {
            color: #3498db;
        }

        .text-muted {
            color: #7f8c8d;
        }

        .font-weight-bold {
            font-weight: 600;
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
    <a href="manageUsers.php">👥 Manage Users</a>
    <a href="adminAdoptionRequests.php">📋 Adoption Request</a>
    <a href="adminPetSitRequests.php">🏠 Pet Sit Request</a>
    <a href="reports.php">📑 Reports</a>
    <a href="adminSetting.php">⚙️ Settings</a>
    <a href="logout.php" class="logout">🚪 Logout</a>
  </div>

  <div class="overlay" onclick="toggleSidebar()"></div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="navbar">
            <h1>Make Payment</h1>
            <div class="admin-profile">
                <span>Admin: <?php echo isset($user['Name']) ? htmlspecialchars($user['Name']) : 'Admin'; ?></span>
                <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
                alt="Profile"
                class="profile-icon">
            </div>
        </div>

        <div class="payment-container">
            <div class="page-header">
                <h1 class="page-title">Complete Payment</h1>
                <p class="page-subtitle">Processing payment for Request #<?php echo htmlspecialchars($requestID); ?></p>
            </div>

            <?php if (isset($error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Admin Notice -->
            <div class="admin-notice">
                <strong>Admin Processing:</strong> You are processing payment on behalf of
                <strong><?php echo isset($request['OwnerName']) ? htmlspecialchars($request['OwnerName']) : 'N/A'; ?></strong>
                for sitter <strong><?php echo isset($request['SitterName']) ? htmlspecialchars($request['SitterName']) : 'N/A'; ?></strong>
            </div>

            <!-- HORIZONTAL LAYOUT -->
            <div class="horizontal-layout">
                <!-- LEFT PANEL - Order Summary -->
                <div class="left-panel">
                    <div class="summary-card">
                        <h3 class="card-title">Order Summary</h3>

                        <div class="summary-header">
                            <?php if (isset($request['PetImage']) && !empty($request['PetImage'])): ?>
                                <div class="pet-image-container">
                                    <img src="<?php echo htmlspecialchars($request['PetImage']); ?>"
                                        alt="<?php echo isset($request['PetName']) ? htmlspecialchars($request['PetName']) : 'Pet'; ?>"
                                        class="pet-image">
                                </div>
                            <?php endif; ?>
                            <div class="summary-header-content">
                                <h3><?php echo isset($request['PetName']) ? htmlspecialchars($request['PetName']) : 'N/A'; ?></h3>
                                <p>Pet Sitting Service</p>
                                <p class="text-muted">Request ID: #<?php echo htmlspecialchars($requestID); ?></p>
                            </div>
                        </div>

                        <table class="summary-table">
                            <tr>
                                <td>Pet Owner</td>
                                <td class="font-weight-bold"><?php echo isset($request['OwnerName']) ? htmlspecialchars($request['OwnerName']) : 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <td>Sitter</td>
                                <td class="font-weight-bold"><?php echo isset($request['SitterName']) ? htmlspecialchars($request['SitterName']) : 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <td>Service Period</td>
                                <td>
                                    <?php if (isset($request['PetSitStartDate']) && isset($request['PetSitEndDate'])): ?>
                                        <?php echo date('M j, Y', strtotime($request['PetSitStartDate'])); ?> -
                                        <?php echo date('M j, Y', strtotime($request['PetSitEndDate'])); ?>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Duration</td>
                                <td><?php echo isset($request['TotalDays']) ? $request['TotalDays'] : '0'; ?> days</td>
                            </tr>
                            <tr>
                                <td>Daily Rate</td>
                                <td>RM<?php echo isset($request['DailyRate']) ? number_format($request['DailyRate'], 2) : '0.00'; ?></td>
                            </tr>
                            <tr>
                                <td>Subtotal</td>
                                <td>RM<?php echo isset($request['TotalAmount']) ? number_format($request['TotalAmount'], 2) : '0.00'; ?></td>
                            </tr>
                        </table>

                        <!-- Earnings Breakdown -->
                        <div class="earnings-breakdown">
                            <div class="breakdown-title">Payment Distribution</div>
                            <table class="breakdown-table">
                                <tr>
                                    <td>Platform Fee (10%)</td>
                                    <td class="text-primary">
                                        RM<?php
                                            if (isset($request['TotalAmount'])) {
                                                echo number_format($request['TotalAmount'] * 0.10, 2);
                                            } else {
                                                echo '0.00';
                                            }
                                            ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Sitter Earnings (90%)</td>
                                    <td class="text-success">
                                        RM<?php
                                            if (isset($request['TotalAmount'])) {
                                                echo number_format($request['TotalAmount'] * 0.90, 2);
                                            } else {
                                                echo '0.00';
                                            }
                                            ?>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- Total Amount -->
                        <div class="summary-total">
                            <table class="total-table">
                                <tr>
                                    <td>TOTAL AMOUNT</td>
                                    <td>
                                        RM<?php
                                            if (isset($request['TotalAmount'])) {
                                                echo number_format($request['TotalAmount'], 2);
                                            } else {
                                                echo '0.00';
                                            }
                                            ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- RIGHT PANEL - Payment Form -->
                <div class="right-panel">
                    <div class="payment-card">
                        <h3 class="card-title">Payment Details</h3>

                        <div class="form-content">
                            <form method="POST" id="paymentForm">
                                <!-- Payment Method Selection -->
                                <div class="payment-methods-container">
                                    <label class="payment-methods-label">Select Payment Method</label>
                                    <div class="payment-methods-grid">
                                        <label class="payment-method-option" onclick="selectPaymentMethod('card')">
                                            <input type="radio" name="payment_method" value="card" required>
                                            <div class="method-icon">CC</div>
                                            <div class="method-name">Credit Card</div>
                                        </label>
                                        <label class="payment-method-option" onclick="selectPaymentMethod('online_banking')">
                                            <input type="radio" name="payment_method" value="online_banking" required>
                                            <div class="method-icon">OB</div>
                                            <div class="method-name">Online Banking</div>
                                        </label>
                                        <label class="payment-method-option" onclick="selectPaymentMethod('ewallet')">
                                            <input type="radio" name="payment_method" value="ewallet" required>
                                            <div class="method-icon">EW</div>
                                            <div class="method-name">E-Wallet</div>
                                        </label>
                                    </div>
                                </div>

                                <!-- Card Details -->
                                <div id="cardDetails" class="payment-details-section">
                                    <div class="form-group">
                                        <label>Card Number</label>
                                        <input type="text" name="card_number" placeholder="1234 5678 9012 3456" maxlength="19">
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Expiry Date</label>
                                            <input type="text" name="card_expiry" placeholder="MM/YY" maxlength="5">
                                        </div>
                                        <div class="form-group">
                                            <label>Security Code (CVC)</label>
                                            <input type="text" name="card_cvc" placeholder="123" maxlength="4">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                    <label>Cardholder Name</label>
                                    <input type="text"
                                        name="card_name"
                                        placeholder="John Doe"
                                        pattern="[A-Za-z\s\-\'\.]+"
                                        title="Only letters, spaces, hyphens, and apostrophes are allowed">
                                </div>
                                </div>

                                <!-- Online Banking Details -->
                                <div id="onlineBankingDetails" class="payment-details-section">
                                    <div class="form-group">
                                        <label>Select Bank</label>
                                        <div class="bank-grid">
                                            <?php
                                            $banks = [
                                                'maybank' => 'Maybank',
                                                'cimb' => 'CIMB Bank',
                                                'public' => 'Public Bank',
                                                'rhb' => 'RHB Bank',
                                                'hongleong' => 'Hong Leong Bank',
                                                'ambank' => 'AmBank',
                                                'bankislam' => 'Bank Islam',
                                                'bankrakyat' => 'Bank Rakyat',
                                                'bsn' => 'Bank Simpanan Nasional',
                                                'affin' => 'Affin Bank',
                                                'alliance' => 'Alliance Bank',
                                                'muamalat' => 'Bank Muamalat',
                                                'ocbc' => 'OCBC Bank',
                                                'standard' => 'Standard Chartered',
                                                'uob' => 'United Overseas Bank',
                                            ];

                                            foreach ($banks as $value => $name):
                                                $initials = strtoupper(substr($name, 0, 2));
                                            ?>
                                                <div class="bank-option" onclick="selectBank('<?php echo $value; ?>')">
                                                    <div class="bank-logo"><?php echo $initials; ?></div>
                                                    <div class="bank-name"><?php echo $name; ?></div>
                                                    <input type="radio" name="bank_name" value="<?php echo $value; ?>" style="display: none;">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- E-Wallet Details -->
                                <div id="ewalletDetails" class="payment-details-section">
                                    <div class="form-group">
                                        <label>Select E-Wallet Provider</label>
                                        <select name="ewallet_provider" class="full-width">
                                            <option value="">Choose e-wallet provider</option>
                                            <option value="touchngo">Touch 'n Go eWallet</option>
                                            <option value="grabpay">GrabPay</option>
                                            <option value="boost">Boost</option>
                                            <option value="shopee">ShopeePay</option>
                                            <option value="paynet">PayNet FPX</option>
                                            <option value="bigpay">BigPay</option>
                                            <option value="wechat">WeChat Pay MY</option>
                                            <option value="alipay">Alipay</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="payment-actions">
                                    <button type="submit" class="btn-pay-admin" id="payButton">
                                        Process Payment: RM<?php echo isset($request['TotalAmount']) ? number_format($request['TotalAmount'], 2) : '0.00'; ?>
                                    </button>

                                    <a href="adminPetSitRequestDetails.php?request_id=<?php echo $requestID; ?>" class="btn-back">
                                        Back to Request Details
                                    </a>
                                </div>
                            </form>

                            <div class="security-notice">
                                <strong>Secure Payment Processing</strong>
                                Funds will be held securely and released to sitter after job completion. All transactions are encrypted and protected.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Function to validate card holder name (no numbers allowed)
    function validateCardName(name) {
        // Only allow letters, spaces, hyphens, apostrophes, and periods
        const regex = /^[A-Za-z\s\-\'\.]+$/;
        return regex.test(name.trim());
    }

    function selectPaymentMethod(method) {
        // Hide all payment detail sections
        document.querySelectorAll('.payment-details-section').forEach(section => {
            section.style.display = 'none';
        });

        // Show selected method's details
        if (method === 'card') {
            document.getElementById('cardDetails').style.display = 'block';
        } else if (method === 'online_banking') {
            document.getElementById('onlineBankingDetails').style.display = 'block';
        } else if (method === 'ewallet') {
            document.getElementById('ewalletDetails').style.display = 'block';
        }

        // Update UI selection for payment methods
        document.querySelectorAll('.payment-method-option').forEach(el => {
            el.classList.remove('selected');
        });
        event.currentTarget.classList.add('selected');
    }

    function selectBank(bankValue) {
        // Update bank selection UI
        document.querySelectorAll('.bank-option').forEach(el => {
            el.classList.remove('selected');
            const radio = el.querySelector('input[type="radio"]');
            if (radio.value === bankValue) {
                radio.checked = true;
                el.classList.add('selected');
            } else {
                radio.checked = false;
            }
        });
    }

    // Auto-select first payment method on load
    document.addEventListener('DOMContentLoaded', function() {
        const firstMethod = document.querySelector('.payment-method-option');
        if (firstMethod) {
            firstMethod.click();
        }

        // Initialize card formatting
        const cardNumberInput = document.querySelector('input[name="card_number"]');
        const cardExpiryInput = document.querySelector('input[name="card_expiry"]');
        const cardCVCInput = document.querySelector('input[name="card_cvc"]');
        const cardNameInput = document.querySelector('input[name="card_name"]');

        // Card number formatting
        if (cardNumberInput) {
            cardNumberInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                value = value.replace(/(\d{4})(?=\d)/g, '$1 ');
                e.target.value = value.trim();
            });
        }

        // Expiry date formatting
        if (cardExpiryInput) {
            cardExpiryInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length >= 2) {
                    value = value.substring(0, 2) + '/' + value.substring(2, 4);
                }
                e.target.value = value;
            });
        }

        // CVC formatting
        if (cardCVCInput) {
            cardCVCInput.addEventListener('input', function(e) {
                e.target.value = e.target.value.replace(/\D/g, '');
            });
        }

        // CARD HOLDER NAME VALIDATION - BLOCK NUMBERS (MOST IMPORTANT PART)
        if (cardNameInput) {
            // Block numbers while typing
            cardNameInput.addEventListener('keydown', function(e) {
                const key = e.key;
                
                // Allow control keys
                const allowedKeys = [
                    'Backspace', 'Delete', 'Tab', 
                    'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
                    'Home', 'End', 'Shift', 'Control', 'Alt', 'Meta',
                    'CapsLock', 'Escape', 'Enter'
                ];
                
                // Allow letters, spaces, hyphens, apostrophes, periods
                const allowedChars = /^[A-Za-z\s\-\'\.]$/;
                
                // If key is a number (0-9), block it
                if (!isNaN(parseInt(key)) && key !== ' ') {
                    e.preventDefault();
                    return false;
                }
                
                // If key is not allowed character or control key, block it
                if (!allowedChars.test(key) && !allowedKeys.includes(key)) {
                    e.preventDefault();
                    return false;
                }
                
                return true;
            });
            
            // Clean input on paste
            cardNameInput.addEventListener('paste', function(e) {
                e.preventDefault();
                const pastedText = (e.clipboardData || window.clipboardData).getData('text');
                
                // Remove numbers and special characters
                let cleanedText = pastedText.replace(/[^A-Za-z\s\-\'\.]/g, '');
                
                // Insert cleaned text
                const start = this.selectionStart;
                const end = this.selectionEnd;
                const currentValue = this.value;
                this.value = currentValue.substring(0, start) + cleanedText + currentValue.substring(end);
                
                // Move cursor to the end of inserted text
                this.selectionStart = this.selectionEnd = start + cleanedText.length;
            });
            
            // Clean input on input event (additional safety)
            cardNameInput.addEventListener('input', function(e) {
                let value = e.target.value;
                
                // Remove any numbers or disallowed characters
                value = value.replace(/[^A-Za-z\s\-\'\.]/g, '');
                
                // Update the input value
                e.target.value = value;
            });
            
            // Visual feedback on focus
            cardNameInput.addEventListener('focus', function() {
                this.style.borderColor = '#3498db';
                this.style.boxShadow = '0 0 0 3px rgba(52, 152, 219, 0.1)';
            });
            
            // Visual feedback on blur
            cardNameInput.addEventListener('blur', function() {
                if (this.value.trim() && !validateCardName(this.value)) {
                    this.style.borderColor = '#ff6b6b';
                    this.style.backgroundColor = '#fff5f5';
                } else {
                    this.style.borderColor = '#dce1e6';
                    this.style.backgroundColor = 'white';
                }
            });
        }
    });

    // Form validation
    document.getElementById('paymentForm').addEventListener('submit', function(e) {
        const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
        if (!paymentMethod) {
            e.preventDefault();
            alert('Please select a payment method');
            return false;
        }

        let isValid = true;
        let errorMessage = '';

        // Validate based on payment method
        if (paymentMethod.value === 'card') {
            const cardNumber = document.querySelector('input[name="card_number"]').value.replace(/\s/g, '');
            const cardExpiry = document.querySelector('input[name="card_expiry"]').value;
            const cardCVC = document.querySelector('input[name="card_cvc"]').value;
            const cardName = document.querySelector('input[name="card_name"]').value.trim();

            // Card holder name validation (NO NUMBERS ALLOWED)
            if (cardName && !validateCardName(cardName)) {
                errorMessage = 'Card holder name can only contain letters, spaces, hyphens, and apostrophes (no numbers allowed)';
                isValid = false;
            } else if (!cardNumber || !cardExpiry || !cardCVC || !cardName) {
                errorMessage = 'Please fill all card details';
                isValid = false;
            } else if (cardNumber.length !== 16) {
                errorMessage = 'Please enter a valid 16-digit card number';
                isValid = false;
            } else if (!/^\d{2}\/\d{2}$/.test(cardExpiry)) {
                errorMessage = 'Please enter expiry date in MM/YY format';
                isValid = false;
            } else if (cardCVC.length < 3 || cardCVC.length > 4) {
                errorMessage = 'Please enter a valid CVC (3 or 4 digits)';
                isValid = false;
            }
        } else if (paymentMethod.value === 'online_banking') {
            const bankName = document.querySelector('input[name="bank_name"]:checked');
            if (!bankName) {
                errorMessage = 'Please select a bank';
                isValid = false;
            }
        } else if (paymentMethod.value === 'ewallet') {
            const ewalletProvider = document.querySelector('select[name="ewallet_provider"]').value;
            if (!ewalletProvider) {
                errorMessage = 'Please select an e-wallet provider';
                isValid = false;
            }
        }

        if (!isValid) {
            e.preventDefault();
            alert(errorMessage);
            return false;
        }

        // Add loading state
        const payButton = document.getElementById('payButton');
        const originalText = payButton.innerHTML;
        payButton.innerHTML = 'Processing Payment...';
        payButton.disabled = true;

        // Re-enable button if form submission fails (for demo purposes)
        setTimeout(() => {
            payButton.innerHTML = originalText;
            payButton.disabled = false;
        }, 3000);

        return true;
    });
</script>
</body>

</html>