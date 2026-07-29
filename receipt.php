<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];
$paymentID = $_GET['payment_id'] ?? 0;

// Fetch payment details with related information
$receipt_sql = "SELECT p.*, 
                       psr.SitRequestID, psr.TotalDays, psr.DailyRate,
                       pet.Name AS PetName, pet.Image AS PetImage,
                       pet.SitStartDate, pet.SitEndDate,
                       owner.Name AS OwnerName, owner.Email AS OwnerEmail, owner.Phone AS OwnerPhone,
                       sitter.Name AS SitterName, sitter.Email AS SitterEmail, sitter.Phone AS SitterPhone
                FROM payment p
                JOIN petsitrequest psr ON p.SitRequestID = psr.SitRequestID
                JOIN pet ON psr.PetID = pet.PetID
                JOIN user owner ON p.PayerID = owner.UserID
                JOIN user sitter ON p.SitterID = sitter.UserID
                WHERE p.PaymentID = ? AND (p.PayerID = ? OR p.SitterID = ?)";
$receipt_stmt = $conn->prepare($receipt_sql);
$receipt_stmt->bind_param("iii", $paymentID, $userID, $userID);
$receipt_stmt->execute();
$receipt = $receipt_stmt->get_result()->fetch_assoc();

if (!$receipt) {
    header("Location: userDashboard.php?error=Receipt not found");
    exit;
}

// Check if user is owner or sitter
$isOwner = ($receipt['PayerID'] == $userID);
$isSitter = ($receipt['SitterID'] == $userID);

// Fetch user data for profile picture
$user_stmt = $conn->prepare("SELECT * FROM user WHERE UserID = ?");
$user_stmt->bind_param("i", $userID);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Receipt - FurCare</title>
    <link rel="stylesheet" href="css/adminDashboard.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-title {
            color: #3B7A57;
            margin-bottom: 30px;
            text-align: center;
        }

        .receipt-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .receipt-header {
            background: linear-gradient(135deg, #3B7A57, #48bb78);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .receipt-title {
            font-size: 2em;
            margin: 0 0 10px 0;
            font-weight: bold;
        }

        .receipt-subtitle {
            font-size: 1.1em;
            opacity: 0.9;
            margin: 0;
        }

        .receipt-body {
            padding: 30px;
        }

        .receipt-section {
            margin-bottom: 30px;
        }

        .section-title {
            color: #3B7A57;
            margin: 0 0 15px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #e0e0e0;
            font-size: 1.2em;
            font-weight: 600;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 0.85em;
            color: #666;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .info-value {
            font-weight: 500;
            color: #333;
            font-size: 1em;
        }

        .amount-breakdown {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .breakdown-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding: 8px 0;
        }

        .breakdown-total {
            border-top: 2px solid #e0e0e0;
            padding-top: 15px;
            margin-top: 15px;
            font-size: 1.2em;
            font-weight: bold;
            color: #3B7A57;
        }

        .pet-info {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        .pet-image {
            width: 100px;
            height: 80px;
            object-fit: cover;
            border-radius: 8px;
        }

        .pet-details h4 {
            margin: 0 0 5px 0;
            color: #3B7A57;
            font-size: 1.3em;
        }

        .receipt-footer {
            background: #f8f9fa;
            padding: 20px 30px;
            border-top: 1px solid #e0e0e0;
        }

        .footer-text {
            text-align: center;
            color: #666;
            font-size: 0.9em;
            margin: 0;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-size: 1em;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-back {
            background: #6c757d;
            color: white;
        }

        .btn-back:hover {
            background: #5a6268;
        }

        .btn-print {
            background: #3B7A57;
            color: white;
        }

        .btn-print:hover {
            background: #2d6145;
        }

        .btn-download {
            background: #4299e1;
            color: white;
        }

        .btn-download:hover {
            background: #3182ce;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-paid {
            background: #48bb78;
            color: white;
        }

        .status-pending {
            background: #ed8936;
            color: white;
        }

        .status-failed {
            background: #e53e3e;
            color: white;
        }

        .user-role-badge {
            background: #ed8936;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7em;
            margin-left: 8px;
        }

        @media print {
            .action-buttons {
                display: none;
            }

            .receipt-card {
                box-shadow: none;
            }

            body {
                background: white;
            }
        }

        @media (max-width: 768px) {
            .receipt-container {
                padding: 10px;
            }

            .receipt-header {
                padding: 20px;
            }

            .receipt-body {
                padding: 20px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                justify-content: center;
            }

            /* Tambah dalam CSS untuk PDF optimization */
            .receipt-card {
                background: white;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
                overflow: hidden;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .receipt-header {
                background: linear-gradient(135deg, #3B7A57, #48bb78) !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            /* Loading state untuk download button */
            .btn-download:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }

            .btn-download.loading {
                background: #6c757d;
            }

            /* Print optimization */
            @media print {
                .action-buttons {
                    display: none !important;
                }

                .receipt-card {
                    box-shadow: none !important;
                    margin: 0 !important;
                }

                body {
                    background: white !important;
                    margin: 0 !important;
                    padding: 0 !important;
                }

                .main-content {
                    padding: 0 !important;
                    margin: 0 !important;
                }

                .receipt-container {
                    padding: 0 !important;
                    margin: 0 !important;
                    max-width: none !important;
                }
            }
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <h2 class="logo">FurCare</h2>
        <a href="userDashboard.php">🗂️ Main Menu</a>
        <a href="addPet.php">➕ Post Pet</a>
        <a href="myPets.php">🐕 My Pets</a>
        <?php if ($isOwner): ?>
            <a href="ownerRequests.php">🏠 My Requests</a>
        <?php else: ?>
            <a href="myApplications.php">📋 My Applications</a>
        <?php endif; ?>
        <a href="logout.php" class="logout">🚪 Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="navbar">
            <h1>Payment Receipt</h1>
            <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
                alt="Profile" class="profile-icon">
        </div>

        <div class="receipt-container">
            <div class="receipt-card">
                <!-- Receipt Header -->
                <div class="receipt-header">
                    <h1 class="receipt-title">PAYMENT RECEIPT</h1>
                    <p class="receipt-subtitle">FurCare Pet Sitting Service</p>
                </div>

                <!-- Receipt Body -->
                <div class="receipt-body">
                    <!-- Receipt Info -->
                    <div class="receipt-section">
                        <h3 class="section-title">Receipt Information</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Receipt Number</span>
                                <span class="info-value"><?php echo htmlspecialchars($receipt['ReceiptNumber']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Payment Date</span>
                                <span class="info-value"><?php echo date('F j, Y g:i A', strtotime($receipt['PaymentDate'])); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Payment Status</span>
                                <span class="status-badge status-<?php echo strtolower($receipt['PaymentStatus']); ?>">
                                    <?php echo ucfirst($receipt['PaymentStatus']); ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Payment Method</span>
                                <span class="info-value"><?php echo ucfirst(str_replace('_', ' ', $receipt['PaymentMethod'])); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Service Details -->
                    <div class="receipt-section">
                        <h3 class="section-title">Service Details</h3>
                        <div class="pet-info">
                            <img src="<?php echo htmlspecialchars($receipt['PetImage']); ?>"
                                alt="<?php echo htmlspecialchars($receipt['PetName']); ?>"
                                class="pet-image">
                            <div class="pet-details">
                                <h4><?php echo htmlspecialchars($receipt['PetName']); ?></h4>
                                <p><strong>Service Period:</strong> <?php echo date('M j, Y', strtotime($receipt['SitStartDate'])); ?> to <?php echo date('M j, Y', strtotime($receipt['SitEndDate'])); ?></p>
                                <p><strong>Duration:</strong> <?php echo $receipt['TotalDays']; ?> days</p>
                                <p><strong>Daily Rate:</strong> RM<?php echo number_format($receipt['DailyRate'], 2); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- User Information -->
                    <div class="receipt-section">
                        <h3 class="section-title">User Information</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Pet Owner</span>
                                <span class="info-value">
                                    <?php echo htmlspecialchars($receipt['OwnerName']); ?>
                                    <?php if ($isOwner): ?>
                                        <span class="user-role-badge">YOU</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Pet Sitter</span>
                                <span class="info-value">
                                    <?php echo htmlspecialchars($receipt['SitterName']); ?>
                                    <?php if ($isSitter): ?>
                                        <span class="user-role-badge">YOU</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Request ID</span>
                                <span class="info-value">#<?php echo $receipt['SitRequestID']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Payment ID</span>
                                <span class="info-value">#<?php echo $receipt['PaymentID']; ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Amount Breakdown -->
                    <div class="receipt-section">
                        <h3 class="section-title">Amount Breakdown</h3>
                        <div class="amount-breakdown">
                            <div class="breakdown-item">
                                <span>Subtotal (<?php echo $receipt['TotalDays']; ?> days × RM<?php echo number_format($receipt['DailyRate'], 2); ?>)</span>
                                <span>RM<?php echo number_format($receipt['Amount'], 2); ?></span>
                            </div>
                            <div class="breakdown-item">
                                <span>Platform Commission (10%)</span>
                                <span>RM<?php echo number_format($receipt['Commission'], 2); ?></span>
                            </div>
                            <div class="breakdown-item">
                                <span>Sitter Earnings (90%)</span>
                                <span>RM<?php echo number_format($receipt['SitterEarnings'], 2); ?></span>
                            </div>
                            <div class="breakdown-item breakdown-total">
                                <span>Total Amount Paid</span>
                                <span>RM<?php echo number_format($receipt['Amount'], 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Receipt Footer -->
                <div class="receipt-footer">
                    <p class="footer-text">
                        Thank you for using FurCare Pet Sitting Services.<br>
                        For any inquiries, please contact support@furcare.com
                    </p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <?php if ($isOwner): ?>
                    <a href="ownerPetSitRequestDetails.php?request_id=<?php echo $receipt['SitRequestID']; ?>" class="btn btn-back">
                        ← Back to Request
                    </a>
                <?php else: ?>
                    <a href="sitterPetSitRequestDetails.php?request_id=<?php echo $receipt['SitRequestID']; ?>" class="btn btn-back">
                        ← Back to Request
                    </a>
                <?php endif; ?>

                <button onclick="window.print()" class="btn btn-print">
                    🖨️ Print Receipt
                </button>

                <button onclick="downloadReceipt()" class="btn btn-download">
                    📥 Download PDF
                </button>
            </div>
        </div>
    </div>

    <script>
        function downloadReceipt() {
            // Show loading state
            const downloadBtn = document.querySelector('.btn-download');
            const originalText = downloadBtn.innerHTML;
            downloadBtn.innerHTML = '⏳ Generating PDF...';
            downloadBtn.disabled = true;

            // Use html2canvas to capture the receipt
            html2canvas(document.querySelector('.receipt-card'), {
                scale: 2, // Higher quality
                useCORS: true,
                logging: false
            }).then(canvas => {
                // Create PDF
                const {
                    jsPDF
                } = window.jspdf;
                const pdf = new jsPDF('p', 'mm', 'a4');
                const imgData = canvas.toDataURL('image/png');

                // Get PDF dimensions
                const pdfWidth = pdf.internal.pageSize.getWidth();
                const pdfHeight = pdf.internal.pageSize.getHeight();
                const imgWidth = canvas.width;
                const imgHeight = canvas.height;
                const ratio = Math.min(pdfWidth / imgWidth, pdfHeight / imgHeight);
                const imgX = (pdfWidth - imgWidth * ratio) / 2;
                const imgY = 10;

                // Add image to PDF
                pdf.addImage(imgData, 'PNG', imgX, imgY, imgWidth * ratio, imgHeight * ratio);

                // Add receipt metadata
                pdf.setFontSize(8);
                pdf.setTextColor(100);
                pdf.text(`Generated on: ${new Date().toLocaleString()}`, 10, pdfHeight - 10);
                pdf.text('FurCare Pet Sitting Services - Official Receipt', pdfWidth - 80, pdfHeight - 10);

                // Save PDF
                pdf.save('FurCare_Receipt_<?php echo $receipt['ReceiptNumber']; ?>.pdf');

                // Restore button state
                downloadBtn.innerHTML = originalText;
                downloadBtn.disabled = false;

            }).catch(error => {
                console.error('PDF generation failed:', error);
                alert('Failed to generate PDF. Please try again.');

                // Restore button state
                downloadBtn.innerHTML = originalText;
                downloadBtn.disabled = false;
            });
        }

        // Auto-print option (optional)
        <?php if (isset($_GET['print']) && $_GET['print'] == 'true'): ?>
            window.onload = function() {
                window.print();
            }
        <?php endif; ?>
    </script>
</body>

</html>