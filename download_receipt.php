<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];
$userRole = $_SESSION['role']; // 🆕 DEFINE DULU
$isAdmin = ($userRole === 'admin'); // 🆕 BARU GUNA
$paymentID = $_GET['payment_id'] ?? 0;

// Fetch payment details
$payment_sql = "SELECT p.*, psr.SitRequestID, psr.TotalDays, psr.DailyRate,
                       pet.Name as PetName,
                       owner.Name as OwnerName, 
                       sitter.Name as SitterName
                FROM payment p
                JOIN petsitrequest psr ON p.SitRequestID = psr.SitRequestID
                JOIN pet ON psr.PetID = pet.PetID
                JOIN user owner ON p.PayerID = owner.UserID
                JOIN user sitter ON p.SitterID = sitter.UserID
                WHERE p.PaymentID = ?";

if ($userRole !== 'admin') {
    $payment_sql .= " AND (p.PayerID = ? OR p.SitterID = ?)";
    $payment_stmt = $conn->prepare($payment_sql);
    $payment_stmt->bind_param("iii", $paymentID, $userID, $userID);
} else {
    $payment_stmt = $conn->prepare($payment_sql);
    $payment_stmt->bind_param("i", $paymentID);
}

$payment_stmt->execute();
$payment = $payment_stmt->get_result()->fetch_assoc();

if (!$payment) {
    die("Receipt not found or access denied");
}

$isOwner = ($payment['PayerID'] == $userID);
$isSitter = ($payment['SitterID'] == $userID);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Receipt <?php echo $payment['ReceiptNumber']; ?> - FurCare</title>
    <style>
        body, button, input, select, textarea { 
            font-family: Arial, sans-serif !important; 
        }
        
        body { 
            margin: 40px; 
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
            background: white;
        }
        .header { 
            text-align: center; 
            border-bottom: 2px solid #3B7A57; 
            padding-bottom: 20px; 
            margin-bottom: 30px; 
        }
        .company { 
            font-size: 28px; 
            font-weight: bold; 
            color: #3B7A57; 
            margin-bottom: 10px;
        }
        .receipt-title { 
            font-size: 22px; 
            margin: 10px 0; 
            color: #333;
        }
        .details { 
            margin: 25px 0; 
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .detail-row { 
            display: flex; 
            justify-content: space-between; 
            margin: 12px 0; 
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .total { 
            font-size: 18px; 
            font-weight: bold; 
            border-top: 2px solid #3B7A57; 
            padding-top: 15px; 
            margin-top: 15px;
        }
        .footer { 
            margin-top: 40px; 
            text-align: center; 
            color: #666; 
            font-size: 14px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin: 30px 0;
            flex-wrap: wrap;
        }
        .btn {
            padding: 15px 60px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-size: smaller;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: Arial, sans-serif;
            font-weight: 500;
            transition: background-color 0.2s;
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
        @media print {
            .action-buttons { display: none; }
            body { margin: 0; }
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
</head>
<body>
    <div class="header">
        <div class="company">FurCare</div>
        <div class="receipt-title">PAYMENT RECEIPT</div>
        <div style="font-size: 16px; color: #666;">Receipt No: <?php echo $payment['ReceiptNumber']; ?></div>
    </div>

    <div class="details">
        <div class="detail-row">
            <span><strong>Date:</strong></span>
            <span><?php echo date('F j, Y', strtotime($payment['PaymentDate'])); ?></span>
        </div>
        <div class="detail-row">
            <span><strong>Time:</strong></span>
            <span><?php echo date('g:i A', strtotime($payment['PaymentDate'])); ?></span>
        </div>
        <div class="detail-row">
            <span><strong>From:</strong></span>
            <span><?php echo htmlspecialchars($payment['OwnerName']); ?> (Owner)</span>
        </div>
        <div class="detail-row">
            <span><strong>To:</strong></span>
            <span><?php echo htmlspecialchars($payment['SitterName']); ?> (Sitter)</span>
        </div>
        <div class="detail-row">
            <span><strong>Pet:</strong></span>
            <span><?php echo htmlspecialchars($payment['PetName']); ?></span>
        </div>
        <div class="detail-row">
            <span><strong>Duration:</strong></span>
            <span><?php echo $payment['TotalDays']; ?> days</span>
        </div>
    </div>

    <div class="details">
        <h3 style="text-align: center; color: #3B7A57; margin-bottom: 20px;">Payment Breakdown</h3>
        <div class="detail-row">
            <span>Daily Rate:</span>
            <span>RM<?php echo number_format($payment['DailyRate'], 2); ?></span>
        </div>
        <div class="detail-row">
            <span>Duration:</span>
            <span><?php echo $payment['TotalDays']; ?> days</span>
        </div>
        <div class="detail-row">
            <span>Subtotal:</span>
            <span>RM<?php echo number_format($payment['Amount'], 2); ?></span>
        </div>
        <div class="detail-row">
            <span>Platform Fee (10%):</span>
            <span>RM<?php echo number_format($payment['Commission'], 2); ?></span>
        </div>
        <div class="detail-row total">
            <span>Sitter Earnings (90%):</span>
            <span style="color: #3B7A57; font-weight: bold;">RM<?php echo number_format($payment['SitterEarnings'], 2); ?></span>
        </div>
    </div>

    <div class="details">
        <div class="detail-row">
            <span><strong>Payment Method:</strong></span>
            <span><?php echo ucfirst(str_replace('_', ' ', $payment['PaymentMethod'])); ?></span>
        </div>
        <div class="detail-row">
            <span><strong>Status:</strong></span>
            <span style="color: green; font-weight: bold;"><?php echo ucfirst($payment['PaymentStatus']); ?></span>
        </div>
    </div>

    <div class="action-buttons">
        <button onclick="window.print()" class="btn btn-print">
            Print Receipt
        </button>

        <button onclick="downloadReceipt()" class="btn btn-download">
            Download PDF
        </button>
    </div>

    <div class="footer">
        <p><strong>Thank you for using FurCare Pet Sitting Services</strong></p>
        <p>This is a computer-generated receipt. No signature required.</p>
        <p>For inquiries, please contact: furcare.helpdesk@gmail.com</p>
    </div>

    <script>
        function downloadReceipt() {
            const downloadBtn = document.querySelector('.btn-download');
            const originalText = downloadBtn.innerHTML;
            downloadBtn.innerHTML = '⏳ Generating PDF...';
            downloadBtn.disabled = true;

            html2canvas(document.body).then(canvas => {
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF('p', 'mm', 'a4');
                const imgData = canvas.toDataURL('image/png');

                const pdfWidth = pdf.internal.pageSize.getWidth();
                const pdfHeight = pdf.internal.pageSize.getHeight();
                const imgWidth = canvas.width;
                const imgHeight = canvas.height;
                const ratio = Math.min(pdfWidth / imgWidth, pdfHeight / imgHeight);
                const imgX = (pdfWidth - imgWidth * ratio) / 2;
                const imgY = 10;

                pdf.addImage(imgData, 'PNG', imgX, imgY, imgWidth * ratio, imgHeight * ratio);
                pdf.save('FurCare_Receipt_<?php echo $payment['ReceiptNumber']; ?>.pdf');

                downloadBtn.innerHTML = originalText;
                downloadBtn.disabled = false;

            }).catch(error => {
                console.error('PDF generation failed:', error);
                alert('Failed to generate PDF. Please try again.');
                downloadBtn.innerHTML = originalText;
                downloadBtn.disabled = false;
            });
        }

        // Auto-print jika ada parameter print
        <?php if (isset($_GET['print']) && $_GET['print'] == 'true'): ?>
            window.onload = function() {
                window.print();
            }
        <?php endif; ?>
    </script>
</body>
</html>