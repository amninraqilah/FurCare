<?php
session_start();
include 'connect.php';

// Owner authentication
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];
$requestID = $_GET['request_id'] ?? 0;

// Validate request ID
if (!$requestID || !is_numeric($requestID)) {
    header("Location: ownerRequests.php?error=Invalid request ID");
    exit;
}

// Debug: Print request ID for testing
echo "<!-- Debug: Request ID = $requestID, User ID = $userID -->";

// Fetch request details and verify ownership
$stmt = $conn->prepare("SELECT psr.*, 
                               p.Name AS PetName, p.Image AS PetImage,
                               p.SitStartDate AS PetSitStartDate, 
                               p.SitEndDate AS PetSitEndDate,
                               sitter.Name AS SitterName, sitter.UserID AS SitterID,
                               sitter.Email AS SitterEmail,
                               psr.TotalDays, psr.DailyRate, psr.TotalAmount
                        FROM petsitrequest psr
                        JOIN pet p ON psr.PetID = p.PetID
                        JOIN user sitter ON psr.SitterID = sitter.UserID
                        WHERE psr.SitRequestID = ? AND psr.OwnerID = ? AND psr.Status = 'approved'");

if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param("ii", $requestID, $userID);
$stmt->execute();
$result = $stmt->get_result();
$request = $result->fetch_assoc();

// Debug: Check query result
if (!$request) {
    // Check if request exists but status not approved
    $check_stmt = $conn->prepare("SELECT Status FROM petsitrequest WHERE SitRequestID = ? AND OwnerID = ?");
    $check_stmt->bind_param("ii", $requestID, $userID);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($row = $check_result->fetch_assoc()) {
        $status = $row['Status'];
        header("Location: ownerRequests.php?error=Request is not approved. Current status: " . $status);
    } else {
        header("Location: ownerRequests.php?error=Request not found or you don't have permission");
    }
    exit;
}

// Debug: Show request data
echo "<!-- Debug: Request data found -->";
echo "<!-- Debug: Pet Name = " . ($request['PetName'] ?? 'N/A') . " -->";
echo "<!-- Debug: Total Amount = " . ($request['TotalAmount'] ?? '0') . " -->";

// Check if payment already exists
$payment_check_sql = "SELECT * FROM payment WHERE SitRequestID = ? AND PayerID = ?";
$payment_check_stmt = $conn->prepare($payment_check_sql);
$payment_check_stmt->bind_param("ii", $requestID, $userID);
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
                                  WHERE SitRequestID = ? AND PayerID = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("ssddii", $paymentMethod, $receiptNumber, $commission, $sitterEarnings, $requestID, $userID);
                    $update_stmt->execute();
                } else {
                    // Create new payment record
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
                        $userID,
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

                // AUTO-CREATE WALLET FOR SITTER
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
    alert('✅ PAYMENT SUCCESSFUL!\\\\n\\\\nReceipt: $receiptNumber\\\\nAmount: RM" . number_format($totalAmount, 2) . "\\\\n\\\\nSitter has been notified about the payment.\\\\nYou can now wait for the pet sitting service to begin.');
    window.location.href = 'ownerPetSitRequestDetails.php?request_id=$requestID&success=Payment+completed+successfully!+Receipt:+$receiptNumber';
</script>";
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to process payment: " . $e->getMessage();
            }
        }
    }
}

// Fetch user data
$user_stmt = $conn->prepare("SELECT * FROM user WHERE UserID = ?");
$user_stmt->bind_param("i", $userID);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();

if (!$user) {
    die("Error: Could not fetch user data");
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Make Payment - FurCare</title>
    <link rel="stylesheet" href="css/userDashboard.css">
    <style>
        /* PAYMENT PAGE STYLES */
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
            text-align: center;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 600;
            color: #3B7A57;
            margin-bottom: 8px;
        }

        .page-subtitle {
            color: #7f8c8d;
            font-size: 1.1rem;
        }

        /* HORIZONTAL LAYOUT */
        .payment-grid {
            display: flex;
            gap: 25px;
            align-items: stretch;
            min-height: 650px;
        }

        .order-summary {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .payment-form {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        @media (max-width: 1200px) {
            .payment-grid {
                flex-direction: column;
                gap: 25px;
            }
        }

        /* CARD STYLES */
        .card {
            background: white;
            border: 1px solid #e1e8ed;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f3f4;
        }

        /* ORDER SUMMARY - HORIZONTAL TABLE STYLE */
        .order-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
            padding-bottom: 25px;
            border-bottom: 1px solid #f0f3f4;
        }

        .pet-image {
            width: 100px;
            height: 100px;
            border-radius: 8px;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .order-header-content h4 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
            margin: 0 0 5px 0;
        }

        .order-header-content p {
            color: #7f8c8d;
            margin: 0;
            font-size: 0.95rem;
        }

        /* SUMMARY ITEMS - PROFESSIONAL STYLE */
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f5f7f9;
        }

        .summary-item:last-child {
            border-bottom: none;
        }

        .summary-item span:first-child {
            color: #5d6d7e;
            font-weight: 500;
        }

        .summary-item span:last-child {
            color: #2c3e50;
            font-weight: 500;
        }

        /* EARNINGS BREAKDOWN */
        .earnings-breakdown {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #a8eeb8ff;
        }

        /* TOTAL AMOUNT */
        .summary-total {
            background: #618e68c2;
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .summary-total span:first-child {
            color: rgba(255, 255, 255, 0.9);
            font-size: 1.1rem;
            font-weight: 500;
        }

        .summary-total span:last-child {
            color: white;
            font-size: 1.4rem;
            font-weight: 600;
        }

        /* PAYMENT FORM STYLES */
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

        .payment-methods {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .payment-method {
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

        .payment-method:hover {
            border-color: #3498db;
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .payment-method.selected {
            border-color: #2980b9;
            background: #e8f4fc;
            box-shadow: 0 4px 12px rgba(41, 128, 185, 0.15);
        }

        .payment-method input[type="radio"] {
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

        .card-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .card-details .form-group:last-child {
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

        .btn {
            display: block;
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 8px;
            font-size: smaller;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            text-decoration: none;
            margin-bottom: 15px;
        }

        .btn-pay {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            color: white;
        }

        .btn-pay:hover {
            background: linear-gradient(135deg, #218838 0%, #1e7e34 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(40, 167, 69, 0.2);
            color: white;
            text-decoration: none;
        }

        .btn-pay:disabled {
            background: #95a5a6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
            width: 95%;
        }

        .btn-back {
            background: #6c757d;
            color: white;
            width: 95%;
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

        /* PAYMENT INFO NOTICE */
        .payment-info {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border: 1px solid #90caf9;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;

        }

        .payment-info strong {
            color: #1565c0;
            margin-right: 5px;
        }

        /* RESPONSIVE DESIGN */
        @media (max-width: 1200px) {
            .payment-methods {
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

            .payment-methods {
                grid-template-columns: 1fr;
            }

            .card-details {
                grid-template-columns: 1fr;
            }

            .card-details .form-group:last-child {
                grid-column: span 1;
            }

            .bank-grid {
                grid-template-columns: 1fr;
            }

            .order-header {
                flex-direction: column;
                text-align: center;
            }

            .pet-image {
                width: 120px;
                height: 120px;
            }

            .card {
                padding: 20px;
            }
        }

        @media (max-width: 480px) {
            .payment-container {
                padding: 0 15px;
            }

            .page-title {
                font-size: 1.8rem;
            }

            .section-title {
                font-size: 1.1rem;
            }
        }

        /* UTILITY CLASSES */
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
    <!-- Navbar -->
    <div class="navbar">
        <h2 class="logo">FurCare</h2>
        <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
            alt="Profile"
            class="profile-icon"
            onclick="toggleSidebar()">
    </div>

    <div class="payment-container">
        <div class="page-header">
            <h1 class="page-title">Complete Your Payment</h1>
            <p class="page-subtitle">Secure payment for your pet sitting service - Request #<?php echo htmlspecialchars($requestID); ?></p>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Payment Info Notice -->
        <div class="payment-info">
            <strong>Important:</strong> Payment will be held securely and released to the sitter after you confirm job completion.
        </div>

        <!-- HORIZONTAL LAYOUT -->
        <div class="payment-grid">
            <!-- LEFT PANEL - Order Summary -->
            <div class="order-summary card">
                <h3 class="section-title">Order Summary</h3>

                <div class="order-header">
                    <?php if (isset($request['PetImage']) && !empty($request['PetImage'])): ?>
                        <img src="<?php echo htmlspecialchars($request['PetImage']); ?>"
                            alt="<?php echo isset($request['PetName']) ? htmlspecialchars($request['PetName']) : 'Pet'; ?>"
                            class="pet-image">
                    <?php endif; ?>
                    <div class="order-header-content">
                        <h4><?php echo isset($request['PetName']) ? htmlspecialchars($request['PetName']) : 'N/A'; ?></h4>
                        <p>Pet Sitting Service</p>
                        <p class="text-muted">Request ID: #<?php echo htmlspecialchars($requestID); ?></p>
                    </div>
                </div>

                <div class="summary-item">
                    <span>Sitter:</span>
                    <span class="font-weight-bold"><?php echo isset($request['SitterName']) ? htmlspecialchars($request['SitterName']) : 'N/A'; ?></span>
                </div>

                <div class="summary-item">
                    <span>Service Period:</span>
                    <span>
                        <?php if (isset($request['PetSitStartDate']) && isset($request['PetSitEndDate'])): ?>
                            <?php echo date('M j, Y', strtotime($request['PetSitStartDate'])); ?> -
                            <?php echo date('M j, Y', strtotime($request['PetSitEndDate'])); ?>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </span>
                </div>

                <div class="summary-item">
                    <span>Duration:</span>
                    <span><?php echo isset($request['TotalDays']) ? $request['TotalDays'] : '0'; ?> days</span>
                </div>

                <div class="summary-item">
                    <span>Daily Rate:</span>
                    <span>RM<?php echo isset($request['DailyRate']) ? number_format($request['DailyRate'], 2) : '0.00'; ?></span>
                </div>

                <div class="summary-item">
                    <span>Subtotal:</span>
                    <span>RM<?php echo isset($request['TotalAmount']) ? number_format($request['TotalAmount'], 2) : '0.00'; ?></span>
                </div>

                <!-- Earnings Breakdown -->
                <div class="earnings-breakdown">
                    <div class="summary-item">
                        <span>Platform Fee (10%):</span>
                        <span class="text-primary">
                            RM<?php
                                if (isset($request['TotalAmount'])) {
                                    echo number_format($request['TotalAmount'] * 0.10, 2);
                                } else {
                                    echo '0.00';
                                }
                                ?>
                        </span>
                    </div>
                    <div class="summary-item">
                        <span>Sitter Earnings (90%):</span>
                        <span class="text-success">
                            RM<?php
                                if (isset($request['TotalAmount'])) {
                                    echo number_format($request['TotalAmount'] * 0.90, 2);
                                } else {
                                    echo '0.00';
                                }
                                ?>
                        </span>
                    </div>
                </div>

                <!-- Total Amount -->
                <div class="summary-item summary-total">
                    <span>TOTAL AMOUNT</span>
                    <span>
                        RM<?php
                            if (isset($request['TotalAmount'])) {
                                echo number_format($request['TotalAmount'], 2);
                            } else {
                                echo '0.00';
                            }
                            ?>
                    </span>
                </div>
            </div>

            <!-- RIGHT PANEL - Payment Form -->
            <div class="payment-form card">
                <h3 class="section-title">Payment Details</h3>

                <div class="form-content">
                    <form method="POST" id="paymentForm">
                        <!-- Payment Method Selection -->
                        <div class="payment-methods-container">
                            <label class="payment-methods-label">Select Payment Method</label>
                            <div class="payment-methods">
                                <label class="payment-method" onclick="selectPaymentMethod('card')">
                                    <input type="radio" name="payment_method" value="card" required>
                                    <div class="method-icon">CC</div>
                                    <div class="method-name">Credit Card</div>
                                </label>
                                <label class="payment-method" onclick="selectPaymentMethod('online_banking')">
                                    <input type="radio" name="payment_method" value="online_banking" required>
                                    <div class="method-icon">OB</div>
                                    <div class="method-name">Online Banking</div>
                                </label>
                                <label class="payment-method" onclick="selectPaymentMethod('ewallet')">
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

                            <div class="card-details">
                                <div class="form-group">
                                    <label>Expiry Date</label>
                                    <input type="text" name="card_expiry" placeholder="MM/YY" maxlength="5">
                                </div>
                                <div class="form-group">
                                    <label>Security Code (CVC)</label>
                                    <input type="text" name="card_cvc" placeholder="123" maxlength="4">
                                </div>
                                <!-- Update card holder name input field: -->
                                <div class="form-group">
                                    <label>Cardholder Name</label>
                                    <input type="text"
                                        name="card_name"
                                        placeholder="John Doe"
                                        pattern="[A-Za-z\s\-\'\.]+"
                                        title="Only letters, spaces, hyphens, and apostrophes are allowed">
                                </div>
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
                                <select name="ewallet_provider">
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
                            <button type="submit" class="btn btn-pay" id="payButton">
                                Process Payment: RM<?php echo isset($request['TotalAmount']) ? number_format($request['TotalAmount'], 2) : '0.00'; ?>
                            </button>

                            <a href="ownerPetSitRequestDetails.php?request_id=<?php echo $requestID; ?>" class="btn btn-back">
                                Back to Request Details
                            </a>
                        </div>
                    </form>

                    <div class="security-notice">
                        <strong>Secure Payment Processing</strong>
                        Your payment is secure and encrypted. Funds will be released to sitter after job completion confirmation.
                    </div>
                </div>
            </div>
        </div>
    </div>

   <script>
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
        document.querySelectorAll('.payment-method').forEach(el => {
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

    // Function to validate card holder name
    function validateCardName(name) {
        // Only allow letters, spaces, hyphens, and apostrophes
        const regex = /^[A-Za-z\s\-\'\.]+$/;
        return regex.test(name.trim());
    }

    // Auto-select first payment method on load
    document.addEventListener('DOMContentLoaded', function() {
        const firstMethod = document.querySelector('.payment-method');
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

        // CARD HOLDER NAME VALIDATION - BLOCK NUMBERS
        if (cardNameInput) {
            // Block numbers while typing
            cardNameInput.addEventListener('keydown', function(e) {
                const key = e.key;
                
                // Allow control keys
                const allowedKeys = [
                    'Backspace', 'Delete', 'Tab', 
                    'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
                    'Home', 'End', 'Shift', 'Control', 'Alt', 'Meta'
                ];
                
                // Allow letters and spaces
                const allowedChars = /^[A-Za-z\s\-\'\.]$/;
                
                // If key is a number, block it
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

            // Card holder name validation
            if (cardName && !validateCardName(cardName)) {
                errorMessage = 'Card holder name can only contain letters, spaces, hyphens, and apostrophes';
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

    // Toggle sidebar function (if needed)
    function toggleSidebar() {
        // Your sidebar toggle logic here
        console.log('Toggle sidebar clicked');
    }
</script>
</body>
</html>