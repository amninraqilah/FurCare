<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'connect.php';

$userID = $_SESSION['user_id'];

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Date range for reports (default: current month)
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');

// ==================== OVERALL STATISTICS ====================
// Total Users (tanpa filter tarikh)
$users_sql = "SELECT 
                COUNT(*) as total_users,
                SUM(role = 'user') as regular_users,
                SUM(role = 'admin') as admin_users
              FROM user";
$users_stats = $conn->query($users_sql)->fetch_assoc();

// ==================== PETS STATISTICS - CORRECTED VERSION ====================
// HANYA count pets yang: Status = Available DAN ApprovalStatus = approved

$pets_sql = "SELECT 
                -- Total semua pets
                COUNT(*) as total_pets,
                
                -- AVAILABLE PETS: Status Available/available DAN ApprovalStatus = 'approved'
                SUM(
                    (Status = 'Available' OR Status = 'available' OR LOWER(TRIM(Status)) = 'available')
                    AND 
                    (ApprovalStatus = 'approved')
                ) as available_pets,
                
                -- ADOPTED PETS: Status Adopted/adopted
                SUM(
                    Status = 'Adopted' OR Status = 'adopted' OR LOWER(TRIM(Status)) = 'adopted'
                ) as adopted_pets,
                
                -- PET SIT PETS: Status Pet Sit/pet sit
                SUM(
                    Status = 'Pet Sit' OR Status = 'pet sit' OR Status = 'Pet sit' OR LOWER(TRIM(Status)) = 'pet sit'
                ) as petsit_pets,
                
                -- OVERDUE PETS: Status Overdue/overdue
                SUM(
                    Status = 'Overdue' OR Status = 'overdue' OR LOWER(TRIM(Status)) = 'overdue'
                ) as overdue_pets,
                
                -- PETS PENDING APPROVAL (Status Available tapi ApprovalStatus pending)
                SUM(
                    (Status = 'Available' OR Status = 'available' OR LOWER(TRIM(Status)) = 'available')
                    AND 
                    (ApprovalStatus = 'pending' OR ApprovalStatus IS NULL)
                ) as pending_approval_pets,
                
                -- PETS REJECTED (Status Available tapi ApprovalStatus rejected)
                SUM(
                    (Status = 'Available' OR Status = 'available' OR LOWER(TRIM(Status)) = 'available')
                    AND 
                    (ApprovalStatus = 'rejected')
                ) as rejected_pets,
                
                -- OTHER STATUSES (untuk debugging)
                SUM(
                    Status NOT IN ('Available', 'available', 'Adopted', 'adopted', 'Pet Sit', 'pet sit', 'Pet sit', 'Overdue', 'overdue')
                    AND LOWER(TRIM(Status)) NOT IN ('available', 'adopted', 'pet sit', 'overdue')
                ) as other_status_pets
              FROM pet";

$pets_stats = $conn->query($pets_sql)->fetch_assoc();

// ==================== 1. TOP EARNERS QUERY (DIPERBAIKI) ====================
$top_earners_sql = "SELECT 
                    u.UserID,
                    u.Name as original_name,
                    CONCAT(LEFT(u.Name, 10), '...') as short_name,
                    COALESCE(SUM(
                        CASE 
                            WHEN p.SitterEarnings > 0 THEN p.SitterEarnings
                            ELSE p.Amount * 0.90
                        END
                    ), 0) as total_earnings,
                    COUNT(DISTINCT psr.SitRequestID) as total_jobs
                FROM user u
                LEFT JOIN petsitrequest psr ON u.UserID = psr.SitterID 
                    AND psr.Status IN ('completed', 'approved')
                LEFT JOIN payment p ON psr.SitRequestID = p.SitRequestID 
                    AND p.PaymentStatus = 'paid'
                WHERE u.Role = 'user'
                GROUP BY u.UserID, u.Name
                HAVING total_earnings > 0
                ORDER BY total_earnings DESC
                LIMIT 8";

$top_earners_result = $conn->query($top_earners_sql);
$top_earners_labels = [];
$top_earners_original_names = [];
$top_earnings_data = [];
$top_earners_jobs = [];

while ($row = $top_earners_result->fetch_assoc()) {
    $top_earners_labels[] = $row['short_name'];
    $top_earners_original_names[] = $row['original_name'];
    $top_earnings_data[] = (float)$row['total_earnings'];
    $top_earners_jobs[] = (int)$row['total_jobs'];
}

// ==================== 2. MOST ACTIVE USERS QUERY ====================
$most_active_sql = "SELECT 
                    u.UserID,
                    u.Name as original_name,
                    CONCAT(LEFT(u.Name, 10), '...') as short_name,
                    (
                        -- Count as pet sitter
                        (SELECT COUNT(*) FROM petsitrequest psr 
                         WHERE psr.SitterID = u.UserID AND psr.Status IN ('completed', 'approved'))
                        +
                        -- Count as pet owner for pet sitting
                        (SELECT COUNT(*) FROM petsitrequest psr 
                         WHERE psr.OwnerID = u.UserID AND psr.Status IN ('completed', 'approved'))
                        +
                        -- Count adoption requests made
                        (SELECT COUNT(*) FROM adoptionrequest ar 
                         WHERE ar.AdopterID = u.UserID AND ar.Status IN ('approved'))
                        +
                        -- Count pets listed for adoption
                        (SELECT COUNT(*) FROM adoptionrequest ar 
                         JOIN pet p ON ar.PetID = p.PetID 
                         WHERE p.OwnerID = u.UserID AND ar.Status IN ('approved'))
                    ) as total_transactions,
                    
                    -- Breakdown
                    (SELECT COUNT(*) FROM petsitrequest WHERE SitterID = u.UserID AND Status IN ('completed', 'approved')) as sitter_jobs,
                    (SELECT COUNT(*) FROM petsitrequest WHERE OwnerID = u.UserID AND Status IN ('completed', 'approved')) as owner_petsits,
                    (SELECT COUNT(*) FROM adoptionrequest WHERE AdopterID = u.UserID AND Status = 'approved') as adoptions_made,
                    (SELECT COUNT(*) FROM adoptionrequest ar JOIN pet p ON ar.PetID = p.PetID WHERE p.OwnerID = u.UserID AND ar.Status = 'approved') as pets_adopted_out
                    
                FROM user u
                WHERE u.Role = 'user'
                GROUP BY u.UserID, u.Name
                HAVING total_transactions > 0
                ORDER BY total_transactions DESC
                LIMIT 8";

$most_active_result = $conn->query($most_active_sql);
$most_active_labels = [];
$most_active_original_names = [];
$most_active_data = [];
$most_active_breakdown = [];

while ($row = $most_active_result->fetch_assoc()) {
    $most_active_labels[] = $row['short_name'];
    $most_active_original_names[] = $row['original_name'];
    $most_active_data[] = (int)$row['total_transactions'];

    $most_active_breakdown[] = [
        'sitter_jobs' => (int)($row['sitter_jobs'] ?? 0),
        'owner_petsits' => (int)($row['owner_petsits'] ?? 0),
        'adoptions_made' => (int)($row['adoptions_made'] ?? 0),
        'pets_adopted_out' => (int)($row['pets_adopted_out'] ?? 0)
    ];
}

// Adoption Statistics
$adoption_sql = "SELECT 
                    COUNT(*) as total_requests,
                    SUM(Status = 'pending') as pending_adoptions,
                    SUM(Status = 'approved') as approved_adoptions,
                    SUM(Status = 'rejected') as rejected_adoptions,
                    SUM(Status = 'cancelled_by_owner') as cancelled_by_owner,
                    SUM(Status = 'cancelled_by_adopter') as cancelled_by_adopter,
                    SUM(Status = 'cancelled') as general_cancelled,
                    SUM(Status LIKE '%cancelled%') as total_cancelled
                  FROM AdoptionRequest
                  WHERE RequestDate BETWEEN ? AND ?";
$adoption_stmt = $conn->prepare($adoption_sql);
$adoption_stmt->bind_param("ss", $startDate, $endDate);
$adoption_stmt->execute();
$adoption_stats = $adoption_stmt->get_result()->fetch_assoc();

// Calculate total cancellations for adoption
$total_cancelled_adoptions = ($adoption_stats['cancelled_by_owner'] ?? 0) +
    ($adoption_stats['cancelled_by_adopter'] ?? 0) +
    ($adoption_stats['general_cancelled'] ?? 0);

// Pet Sit Statistics
$petsit_sql = "SELECT 
                  COUNT(*) as total_requests,
                  SUM(Status = 'pending') as pending_petsits,
                  SUM(Status = 'approved') as approved_petsits,
                  SUM(Status = 'rejected') as rejected_petsits,
                  SUM(Status = 'completed') as completed_petsits,
                  SUM(Status = 'overdue') as overdue_petsits,
                  SUM(Status = 'cancelled_by_owner') as cancelled_by_owner,
                  SUM(Status = 'cancelled_by_sitter') as cancelled_by_sitter,
                  SUM(Status = 'cancelled') as general_cancelled,
                  SUM(Status LIKE '%cancelled%') as total_cancelled
                FROM PetSitRequest
                WHERE RequestDate BETWEEN ? AND ?";
$petsit_stmt = $conn->prepare($petsit_sql);
$petsit_stmt->bind_param("ss", $startDate, $endDate);
$petsit_stmt->execute();
$petsit_stats = $petsit_stmt->get_result()->fetch_assoc();

// Calculate total cancellations for pet sitting
$total_cancelled_petsits = ($petsit_stats['cancelled_by_owner'] ?? 0) +
    ($petsit_stats['cancelled_by_sitter'] ?? 0) +
    ($petsit_stats['general_cancelled'] ?? 0);

// ==================== FIXED: COMPLETED TRANSACTIONS ====================
$completed_transactions_sql = "SELECT 
                                -- Completed pet sitting (both sitter & owner)
                                (SELECT COUNT(*) 
                                 FROM PetSitRequest 
                                 WHERE (RequestDate BETWEEN ? AND ?)
                                 AND Status = 'completed') as completed_petsits_count,
                                 
                                -- Approved adoptions  
                                (SELECT COUNT(*) 
                                 FROM AdoptionRequest 
                                 WHERE (RequestDate BETWEEN ? AND ?)
                                 AND Status = 'approved') as approved_adoptions_count";

$completed_stmt = $conn->prepare($completed_transactions_sql);
$completed_stmt->bind_param("ssss", $startDate, $endDate, $startDate, $endDate);
$completed_stmt->execute();
$completed_stats = $completed_stmt->get_result()->fetch_assoc();

$total_completed_transactions = ($completed_stats['completed_petsits_count'] ?? 0) +
    ($completed_stats['approved_adoptions_count'] ?? 0);

// ==================== FIXED: FINANCIAL QUERIES ====================
$finance_sql = "SELECT 
                  COUNT(*) as total_transactions,
                  SUM(Amount) as total_revenue,
                  COALESCE(SUM(Commission), 0) as total_commission,
                  COALESCE(SUM(SitterEarnings), 0) as total_sitter_earnings,
                  AVG(Amount) as avg_transaction_value
                FROM payment 
                WHERE PaymentStatus = 'paid'
                AND PaymentDate BETWEEN ? AND ?";
$finance_stmt = $conn->prepare($finance_sql);
$finance_stmt->bind_param("ss", $startDate, $endDate);
$finance_stmt->execute();
$finance_stats = $finance_stmt->get_result()->fetch_assoc();

// Jika commission atau sitter earnings masih 0
if (($finance_stats['total_commission'] == 0 && $finance_stats['total_sitter_earnings'] == 0) ||
    ($finance_stats['total_revenue'] > 0 && $finance_stats['total_commission'] == 0)
) {

    $finance_calc_sql = "SELECT 
                          COUNT(*) as total_transactions,
                          SUM(Amount) as total_revenue,
                          SUM(Amount * 0.10) as total_commission,
                          SUM(Amount * 0.90) as total_sitter_earnings,
                          AVG(Amount) as avg_transaction_value
                        FROM payment 
                        WHERE PaymentStatus = 'paid'
                        AND PaymentDate BETWEEN ? AND ?";
    $finance_calc_stmt = $conn->prepare($finance_calc_sql);
    $finance_calc_stmt->bind_param("ss", $startDate, $endDate);
    $finance_calc_stmt->execute();
    $finance_stats = $finance_calc_stmt->get_result()->fetch_assoc();
}

// ==================== IMPROVED CHARTS DATA ====================
// Monthly Trends for Charts
$monthly_trend_sql = "SELECT 
                        DATE_FORMAT(RequestDate, '%b %Y') as month_name,
                        COUNT(*) as adoption_count
                      FROM AdoptionRequest
                      WHERE RequestDate BETWEEN ? AND ?
                      GROUP BY DATE_FORMAT(RequestDate, '%Y-%m')
                      ORDER BY MIN(RequestDate) ASC";
$monthly_adoption_stmt = $conn->prepare($monthly_trend_sql);
$monthly_adoption_stmt->bind_param("ss", $startDate, $endDate);
$monthly_adoption_stmt->execute();
$monthly_adoption_result = $monthly_adoption_stmt->get_result();

$monthly_petsit_sql = "SELECT 
                        DATE_FORMAT(RequestDate, '%b %Y') as month_name,
                        COUNT(*) as petsit_count
                      FROM PetSitRequest
                      WHERE RequestDate BETWEEN ? AND ?
                      GROUP BY DATE_FORMAT(RequestDate, '%Y-%m')
                      ORDER BY MIN(RequestDate) ASC";
$monthly_petsit_stmt = $conn->prepare($monthly_petsit_sql);
$monthly_petsit_stmt->bind_param("ss", $startDate, $endDate);
$monthly_petsit_stmt->execute();
$monthly_petsit_result = $monthly_petsit_stmt->get_result();

// Prepare simplified data for charts
$monthly_labels = [];
$adoption_data = [];
$petsit_data = [];

// Get adoption data
while ($row = $monthly_adoption_result->fetch_assoc()) {
    $monthly_labels[] = $row['month_name'];
    $adoption_data[] = (int)$row['adoption_count'];
}

// Get petsit data
$temp_petsit_data = [];
while ($row = $monthly_petsit_result->fetch_assoc()) {
    $temp_petsit_data[$row['month_name']] = (int)$row['petsit_count'];
}

// Align petsit data with adoption months
foreach ($monthly_labels as $month) {
    $petsit_data[] = $temp_petsit_data[$month] ?? 0;
}

// Jika tiada data, buat array kosong
if (empty($monthly_labels)) {
    $monthly_labels = [date('M Y', strtotime($startDate))];
    $adoption_data = [0];
    $petsit_data = [0];
}

// ==================== PET STATUS DATA UNTUK PIE CHART ====================
$pet_status_labels = ['Available', 'Adopted', 'Pet Sit', 'Overdue'];
$pet_status_values = [
    (int)($pets_stats['available_pets'] ?? 0),
    (int)($pets_stats['adopted_pets'] ?? 0),
    (int)($pets_stats['petsit_pets'] ?? 0),
    (int)($pets_stats['overdue_pets'] ?? 0)
];

// ==================== FIXED FINANCIAL DATA FOR CHARTS ====================
$financial_data_sql = "SELECT 
                        DATE_FORMAT(PaymentDate, '%b %Y') as month_name,
                        COALESCE(SUM(Amount), 0) as revenue,
                        COALESCE(SUM(
                            CASE 
                                WHEN Commission > 0 THEN Commission
                                ELSE Amount * 0.10
                            END
                        ), 0) as commission,
                        COALESCE(SUM(
                            CASE 
                                WHEN SitterEarnings > 0 THEN SitterEarnings
                                ELSE Amount * 0.90
                            END
                        ), 0) as sitter_earnings
                      FROM payment
                      WHERE PaymentStatus = 'paid'
                      AND PaymentDate BETWEEN ? AND ?
                      GROUP BY DATE_FORMAT(PaymentDate, '%Y-%m')
                      ORDER BY MIN(PaymentDate) ASC";
$financial_data_stmt = $conn->prepare($financial_data_sql);
$financial_data_stmt->bind_param("ss", $startDate, $endDate);
$financial_data_stmt->execute();
$financial_data_result = $financial_data_stmt->get_result();

$financial_data = [];
if ($financial_data_result) {
    while ($row = $financial_data_result->fetch_assoc()) {
        $financial_data[] = [
            'month_name' => $row['month_name'],
            'revenue' => (float)$row['revenue'],
            'commission' => (float)$row['commission'],
            'sitter_earnings' => (float)$row['sitter_earnings']
        ];
    }
}

if (empty($financial_data)) {
    $financial_data = [[
        'month_name' => date('M Y', strtotime($startDate)),
        'revenue' => 0,
        'commission' => 0,
        'sitter_earnings' => 0
    ]];
}

// ==================== OVERDUE PETS QUERY ====================
$overdue_pets_sql = "SELECT 
                      p.PetID,
                      p.Name,
                      p.Type,
                      p.SitStartDate,
                      p.SitEndDate,
                      DATEDIFF(CURDATE(), p.SitStartDate) as days_overdue,
                      u.Name as owner_name,
                      u.Phone as owner_phone
                    FROM pet p
                    JOIN user u ON p.OwnerID = u.UserID
                    WHERE p.Status = 'Overdue'
                    ORDER BY days_overdue DESC";
$overdue_pets_result = $conn->query($overdue_pets_sql);
$overdue_pets = [];
while ($row = $overdue_pets_result->fetch_assoc()) {
    $overdue_pets[] = $row;
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
    <title>Overall Reports - Admin | FurCare</title>
    <link rel="stylesheet" href="css/adminDashboard.css">
    <link rel="stylesheet" href="css/reports.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        .overdue-section {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .overdue-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .overdue-table th,
        .overdue-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .overdue-table th {
            background-color: #f8d7da;
        }

        .overdue-badge {
            background: #dc3545;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
        }

        .summary-details {
            display: flex;
            flex-direction: column;
            gap: 3px;
            margin-top: 10px;
        }

        .summary-details span {
            font-size: 12px;
            color: #666;
        }

        .filter-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .filter-form {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-group input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .btn-filter {
            background: #3B7A57;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
        }

        .btn-filter:hover {
            background: #2D6047;
        }

        .date-range-display {
            background: #e9f7ef;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            font-size: 14px;
            color: #3B7A57;
        }

        .note-text {
            font-size: 12px;
            color: #666;
            font-style: italic;
            margin-top: 5px;
        }

        .no-filter-text {
            background: #fff3cd;
            padding: 8px;
            border-radius: 5px;
            font-size: 12px;
            margin-top: 5px;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        .chart-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .chart-card h3 {
            margin: 0 0 15px 0;
            font-size: 16px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .summary-card h3 {
            margin: 0 0 10px 0;
            font-size: 16px;
            color: #333;
        }

        .summary-number {
            font-size: 32px;
            font-weight: bold;
            color: #3B7A57;
            margin: 10px 0;
        }

        /* Badge untuk status */
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
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

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 25px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: slideIn 0.3s ease;
            position: relative;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .close-modal {
            position: absolute;
            right: 20px;
            top: 15px;
            font-size: 28px;
            font-weight: bold;
            color: #666;
            cursor: pointer;
            transition: color 0.3s;
        }

        .close-modal:hover {
            color: #333;
        }

        .modal-body {
            margin-top: 20px;
        }

        .earner-info h4 {
            color: #3B7A57;
            margin-bottom: 20px;
            font-size: 20px;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }

        .earner-stats {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #3B7A57;
        }

        .stat-label {
            font-weight: 500;
            color: #555;
        }

        .stat-value {
            font-weight: bold;
            font-size: 18px;
            color: #3B7A57;
        }

        .stat-item:nth-child(2) .stat-value {
            color: #4299e1;
        }

        .stat-item:nth-child(3) .stat-value {
            color: #ed8936;
        }

        .stat-item:nth-child(4) .stat-value {
            color: #e53e3e;
        }

        /* Cursor pointer untuk bar chart */
        .chart-card canvas {
            cursor: pointer;
        }

        /* Alert untuk pending/rejected pets */
        .status-alert {
            background: #fffdf0;
            border: 1px solid #ffc107;
            padding: 10px 15px;
            margin: 10px 0;
            border-radius: 5px;
            font-size: 13px;
        }

        /* CHART CONTAINER IMPROVEMENT */
        .chart-container-wrapper {
            position: relative;
            height: 320px;
            width: 90%;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 12px;
            padding: 15px;
            border: 1px solid rgba(59, 122, 87, 0.1);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .chart-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
        }

        .chart-title i {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .chart-stats {
            display: flex;
            gap: 15px;
            font-size: 14px;
            color: #666;
        }

        .chart-stat-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .chart-stat-value {
            font-weight: 600;
            color: #3B7A57;
        }

        /* CHART LEGEND */
        .chart-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: rgba(59, 122, 87, 0.05);
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .legend-color {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        /* NO DATA STATE */
        .no-data-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #999;
            text-align: center;
            padding: 40px 20px;
        }

        .no-data-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .no-data-text {
            font-size: 14px;
            line-height: 1.5;
            max-width: 250px;
        }

        /* CHART ACTION BUTTONS */
        .chart-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
        }

        .chart-action-btn {
            padding: 6px 12px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .chart-action-btn:hover {
            background: #f8f9fa;
            border-color: #3B7A57;
            color: #3B7A57;
        }

        /* CHART CARD ENHANCEMENT */
        .chart-card-enhanced {
            background: white;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid rgba(59, 122, 87, 0.08);
        }

        .chart-card-enhanced:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.12);
        }

        /* CHART FILTERS */
        .chart-filters {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .chart-filter-btn {
            padding: 8px 16px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .chart-filter-btn.active {
            background: #3B7A57;
            color: white;
            border-color: #3B7A57;
        }

        .chart-filter-btn:hover:not(.active) {
            background: #e9ecef;
            border-color: #adb5bd;
        }

        /* CHART TOOLTIP */
        .custom-tooltip {
            background: rgba(17, 17, 17, 0.95) !important;
            backdrop-filter: blur(10px);
            border-radius: 10px !important;
            border: 1px solid #3B7A57 !important;
            padding: 15px !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2) !important;
        }

        /* GRID LINES STYLING */
        .chart-grid-line {
            border-color: rgba(0, 0, 0, 0.05) !important;
        }

        /* RESPONSIVE CHART */
        @media (max-width: 768px) {
            .chart-container-wrapper {
                height: 280px;
            }

            .chart-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .chart-stats {
                width: 100%;
                justify-content: space-between;
            }
        }

        /* ANIMATION FOR CHARTS */
        @keyframes chartLoad {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .chart-card-enhanced {
            animation: chartLoad 0.6s ease-out;
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
        <a href="reports.php" class="active">📑 Reports</a>
        <a href="adminSetting.php">⚙️ Settings</a>
        <a href="logout.php" class="logout">🚪 Logout</a>
    </div>

    <div class="main-content">
        <div class="navbar">
            <h1>📊 Overall System Reports</h1>
            <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
                alt="Profile"
                class="profile-icon">
        </div>
        <div class="reports-container">

            <!-- Date Filter -->
            <div class="filter-section">
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Start Date:</label>
                        <input type="date" name="start_date" value="<?php echo $startDate; ?>" required>
                    </div>
                    <div class="form-group">
                        <label>End Date:</label>
                        <input type="date" name="end_date" value="<?php echo $endDate; ?>" required>
                    </div>
                    <button type="submit" class="btn-filter">Apply Filter</button>
                </form>
                <div class="date-range-display">
                    Showing filtered data from <strong><?php echo date('d M Y', strtotime($startDate)); ?></strong> to <strong><?php echo date('d M Y', strtotime($endDate)); ?></strong>
                    <div class="note-text">Note: Users & Pets statistics show all-time data (no date filter applied)</div>
                </div>
            </div>

            <!-- Overdue Pets Section -->
            <?php if (!empty($overdue_pets)): ?>
                <div class="overdue-section">
                    <h3>⚠️ Overdue Pets (<?php echo count($overdue_pets); ?>)</h3>
                    <table class="overdue-table">
                        <thead>
                            <tr>
                                <th>Pet Name</th>
                                <th>Type</th>
                                <th>Sit Start Date</th>
                                <th>Days Overdue</th>
                                <th>Owner</th>
                                <th>Contact</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overdue_pets as $pet): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($pet['Name']); ?></td>
                                    <td><?php echo htmlspecialchars($pet['Type']); ?></td>
                                    <td><?php echo date('d M Y', strtotime($pet['SitStartDate'])); ?></td>
                                    <td><span class="overdue-badge"><?php echo $pet['days_overdue']; ?> days</span></td>
                                    <td><?php echo htmlspecialchars($pet['owner_name']); ?></td>
                                    <td><?php echo htmlspecialchars($pet['owner_phone']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Summary Cards -->
            <div class="summary-grid">
                <!-- Users Card (tanpa filter tarikh) -->
                <div class="summary-card">
                    <h3>👥 Users</h3>
                    <div class="summary-number"><?php echo $users_stats['total_users']; ?></div>
                    <div class="summary-details">
                        <span>Regular: <?php echo $users_stats['regular_users']; ?></span>
                        <span>Admin: <?php echo $users_stats['admin_users']; ?></span>
                    </div>
                    <div class="no-filter-text">All-time data</div>
                </div>

                <!-- Pets Card - DIPERBAIKI -->
                <div class="summary-card">
                    <h3>🐾 Pets</h3>
                    <div class="summary-number"><?php echo $pets_stats['total_pets']; ?></div>
                    <div class="summary-details">
                        <span>Available: <?php echo $pets_stats['available_pets']; ?></span>
                        <span>Adopted: <?php echo $pets_stats['adopted_pets']; ?></span>
                        <span>Pet Sitting: <?php echo $pets_stats['petsit_pets']; ?></span>
                        <span>Overdue: <?php echo $pets_stats['overdue_pets']; ?></span>
                    </div>

                    <!-- Tampilkan info pending/rejected jika ada -->
                    <?php if (($pets_stats['pending_approval_pets'] ?? 0) > 0 || ($pets_stats['rejected_pets'] ?? 0) > 0): ?>
                        <div class="status-alert">
                            <small>
                                ℹ️ <strong>Available pets count only includes approved pets.</strong><br>
                                <?php if (($pets_stats['pending_approval_pets'] ?? 0) > 0): ?>
                                    • <?php echo $pets_stats['pending_approval_pets']; ?> pet(s) pending admin approval<br>
                                <?php endif; ?>
                                <?php if (($pets_stats['rejected_pets'] ?? 0) > 0): ?>
                                    • <?php echo $pets_stats['rejected_pets']; ?> pet(s) rejected by admin
                                <?php endif; ?>
                            </small>
                        </div>
                    <?php endif; ?>

                    <div class="no-filter-text">All-time data</div>
                </div>

                <!-- Adoptions Card (DENGAN FILTER TARIKH) -->
                <div class="summary-card">
                    <h3>🏠 Adoptions</h3>
                    <div class="summary-number"><?php echo $adoption_stats['total_requests'] ?? 0; ?></div>
                    <div class="summary-details">
                        <span>Approved: <?php echo $adoption_stats['approved_adoptions'] ?? 0; ?></span>
                        <span>Pending: <?php echo $adoption_stats['pending_adoptions'] ?? 0; ?></span>
                        <span>Rejected: <?php echo $adoption_stats['rejected_adoptions'] ?? 0; ?></span>
                        <span>Cancelled: <?php echo $total_cancelled_adoptions; ?></span>
                    </div>
                    <div class="note-text">Date filtered</div>
                </div>

                <!-- Pet Sitting Card (DENGAN FILTER TARIKH) -->
                <div class="summary-card">
                    <h3>🐶 Pet Sitting</h3>
                    <div class="summary-number"><?php echo $petsit_stats['total_requests'] ?? 0; ?></div>
                    <div class="summary-details">
                        <span>Completed: <?php echo $petsit_stats['completed_petsits'] ?? 0; ?></span>
                        <span>Approved: <?php echo $petsit_stats['approved_petsits'] ?? 0; ?></span>
                        <span>Pending: <?php echo $petsit_stats['pending_petsits'] ?? 0; ?></span>
                        <span>Rejected: <?php echo $petsit_stats['rejected_petsits'] ?? 0; ?></span>
                        <span>Overdue: <?php echo $petsit_stats['overdue_petsits'] ?? 0; ?></span>
                        <span>Cancelled: <?php echo $total_cancelled_petsits; ?></span>
                    </div>
                    <div class="note-text">Date filtered</div>
                </div>

                <!-- Financial Card (DENGAN FILTER TARIKH) -->
                <div class="summary-card financial">
                    <h3>💰 Revenue</h3>
                    <div class="summary-number">RM<?php echo number_format($finance_stats['total_revenue'] ?? 0, 2); ?></div>
                    <div class="summary-details">
                        <span>Commission: RM<?php echo number_format($finance_stats['total_commission'] ?? 0, 2); ?></span>
                        <span>Sitters: RM<?php echo number_format($finance_stats['total_sitter_earnings'] ?? 0, 2); ?></span>
                        <span>Transactions: <?php echo $finance_stats['total_transactions'] ?? 0; ?></span>
                    </div>
                    <div class="note-text">Date filtered (Pet Sitting only)</div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-grid">
                <!-- Top Earners Card -->
                <div class="chart-card-enhanced">
                    <div class="chart-header">
                        <div class="chart-title">
                            💰
                            <span>Top Earners</span>
                        </div>
                        <div class="chart-stats">
                            <div class="chart-stat-item">
                                <span>Total:</span>
                                <span class="chart-stat-value">RM <?php
                                                                    $totalEarnings = array_sum(array_map('floatval', $top_earnings_data));
                                                                    echo number_format($totalEarnings, 2);
                                                                    ?></span>
                            </div>
                            <div class="chart-stat-item">
                                <span>Top Sitters:</span>
                                <span class="chart-stat-value"><?php echo count($top_earners_labels); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="chart-container-wrapper">
                        <canvas id="topEarnersChart"></canvas>
                    </div>
                    <div class="chart-legend">
                        <?php
                        $rankColors = ['#3B7A57', '#4299e1', '#ed8936', '#9f7aea', '#e53e3e', '#48bb78', '#f56565', '#ecc94b'];
                        for ($i = 0; $i < min(4, count($top_earners_labels)); $i++):
                            $rank = $i + 1;
                            $rankEmoji = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : ''));
                        ?>
                            <div class="legend-item">
                                <div class="legend-color" style="background: <?php echo $rankColors[$i]; ?>"></div>
                                <span><?php echo $rankEmoji; ?> Rank #<?php echo $rank; ?></span>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <div class="chart-actions">
                        
                    </div>
                    <div class="note-text" style="margin-top: 15px;">
                        Top 8 pet sitters by earnings | Click on bars for details
                    </div>
                </div>

                <!-- Most Active Users Card -->
                <div class="chart-card-enhanced">
                    <div class="chart-header">
                        <div class="chart-title">
                            👥
                            <span>Most Active Users</span>
                        </div>
                        <div class="chart-stats">
                            <div class="chart-stat-item">
                                <span>Total Activities:</span>
                                <span class="chart-stat-value"><?php
                                                                $totalActivities = array_sum(array_map('intval', $most_active_data));
                                                                echo number_format($totalActivities);
                                                                ?></span>
                            </div>
                            <div class="chart-stat-item">
                                <span>Active Users:</span>
                                <span class="chart-stat-value"><?php echo count($most_active_labels); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="chart-container-wrapper">
                        <canvas id="mostActiveChart"></canvas>
                    </div>
                    <div class="chart-legend">
                        <div class="legend-item">
                            <div class="legend-color" style="background: rgba(59, 122, 87, 0.9)"></div>
                            <span>Total Activities</span>
                        </div>
                    </div>
                    <div class="chart-actions">
                        
                    </div>
                    <div class="note-text" style="margin-top: 15px;">
                        Top 8 users by total transactions (pet sitting & adoptions)
                    </div>
                </div>

                <!-- Other charts tetap seperti sebelumnya -->
                <div class="chart-card-enhanced">
                    <h3>📈 Monthly Requests Trend</h3>
                    <div class="chart-container">
                        <canvas id="requestsChart"></canvas>
                    </div>
                    <div class="note-text">Date filtered (<?php echo date('d M Y', strtotime($startDate)); ?> - <?php echo date('d M Y', strtotime($endDate)); ?>)</div>
                </div>

                <div class="chart-card-enhanced">
                    <h3>🐾 Pet Status Distribution</h3>
                    <div class="chart-container">
                        <canvas id="petStatusChart"></canvas>
                    </div>
                    <div class="note-text">All-time data</div>
                </div>

                <div class="chart-card-enhanced">
                    <h3>💰 Financial Overview</h3>
                    <div class="chart-container">
                        <canvas id="financialChart"></canvas>
                    </div>
                    <div class="note-text">Date filtered (Pet Sitting only)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal untuk Top Earner Details -->
    <div id="earnerModal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h3>💰 Top Earner Details</h3>
            <div class="modal-body">
                <div class="earner-info">
                    <h4 id="earnerName"></h4>
                    <div class="earner-stats">
                        <div class="stat-item">
                            <span class="stat-label">Total Earnings:</span>
                            <span class="stat-value" id="earnerTotal">RM 0.00</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Total Jobs:</span>
                            <span class="stat-value" id="earnerJobs">0</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Average per Job:</span>
                            <span class="stat-value" id="earnerAvg">RM 0.00</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Platform Commission (10%):</span>
                            <span class="stat-value" id="earnerCommission">RM 0.00</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Prepare data untuk charts
        const monthlyLabels = <?php echo json_encode($monthly_labels); ?>;
        const adoptionData = <?php echo json_encode(array_map('intval', $adoption_data)); ?>;
        const petsitData = <?php echo json_encode(array_map('intval', $petsit_data)); ?>;
        const petStatusLabels = <?php echo json_encode($pet_status_labels); ?>;
        const petStatusValues = <?php echo json_encode(array_map('intval', $pet_status_values)); ?>;
        const financialData = <?php echo json_encode($financial_data); ?>;
        const topEarnersLabels = <?php echo json_encode($top_earners_labels); ?>;
        const topEarnersOriginalNames = <?php echo json_encode($top_earners_original_names); ?>;
        const topEarningsData = <?php echo json_encode(array_map('floatval', $top_earnings_data)); ?>;
        const topEarnersJobs = <?php echo json_encode(array_map('intval', $top_earners_jobs)); ?>;
        const mostActiveLabels = <?php echo json_encode($most_active_labels); ?>;
        const mostActiveOriginalNames = <?php echo json_encode($most_active_original_names); ?>;
        const mostActiveData = <?php echo json_encode(array_map('intval', $most_active_data)); ?>;
        const mostActiveBreakdown = <?php echo json_encode($most_active_breakdown); ?>;

        // Function untuk convert ke number
        function convertToNumber(value) {
            if (value === null || value === undefined) return 0;
            if (typeof value === 'number') return value;
            if (typeof value === 'string') {
                const cleanValue = value.toString().replace(/[^\d.-]/g, '').replace(/,/g, '');
                const num = parseFloat(cleanValue);
                return isNaN(num) ? 0 : num;
            }
            return Number(value) || 0;
        }

        // Function untuk format currency
        function formatCurrency(amount) {
            return 'RM' + parseFloat(amount).toLocaleString('en-MY', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // Function untuk format number dengan koma
        function formatNumber(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }

        // Function untuk open modal
        function openEarnerModal(index) {
            const modal = document.getElementById('earnerModal');
            const name = topEarnersOriginalNames[index] || topEarnersLabels[index];
            const earnings = convertToNumber(topEarningsData[index]);
            const jobs = convertToNumber(topEarnersJobs[index]);
            const avgPerJob = jobs > 0 ? (earnings / jobs) : 0;
            const commission = earnings * 0.1111;

            document.getElementById('earnerName').textContent = name;
            document.getElementById('earnerTotal').textContent = 'RM ' + earnings.toFixed(2);
            document.getElementById('earnerJobs').textContent = jobs;
            document.getElementById('earnerAvg').textContent = 'RM ' + avgPerJob.toFixed(2);
            document.getElementById('earnerCommission').textContent = 'RM ' + commission.toFixed(2);

            modal.style.display = 'block';
        }

        // Function untuk close modal
        function closeModal() {
            document.getElementById('earnerModal').style.display = 'none';
        }

        // Event listeners untuk modal
        document.addEventListener('DOMContentLoaded', function() {
            // Close modal ketika klik close button
            document.querySelector('.close-modal').addEventListener('click', closeModal);

            // Close modal ketika klik di luar modal
            window.addEventListener('click', function(event) {
                const modal = document.getElementById('earnerModal');
                if (event.target === modal) {
                    closeModal();
                }
            });
        });

        // ==================== OTHER CHARTS ====================
        // 1. Monthly Requests Trend Chart
        if (document.getElementById('requestsChart')) {
            new Chart(document.getElementById('requestsChart'), {
                type: 'bar',
                data: {
                    labels: monthlyLabels,
                    datasets: [{
                            label: 'Adoption Requests',
                            data: adoptionData,
                            backgroundColor: '#3B7A57',
                            borderColor: '#2D6047',
                            borderWidth: 1
                        },
                        {
                            label: 'Pet Sit Requests',
                            data: petsitData,
                            backgroundColor: '#4299e1',
                            borderColor: '#3182ce',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }

        // 2. Pet Status Pie Chart (APPROVED PETS ONLY)
        if (document.getElementById('petStatusChart')) {
            // Filter out zero values untuk pie chart
            const filteredLabels = [];
            const filteredValues = [];
            const colors = ['#48bb78', '#ed8936', '#4299e1', '#e53e3e'];
            const filteredColors = [];

            petStatusValues.forEach((value, index) => {
                if (value > 0) {
                    filteredLabels.push(petStatusLabels[index]);
                    filteredValues.push(value);
                    filteredColors.push(colors[index]);
                }
            });

            // Jika semua nilai 0, tampilkan placeholder
            if (filteredValues.length === 0) {
                filteredLabels.push('No Data');
                filteredValues.push(1);
                filteredColors.push('#cccccc');
            }

            new Chart(document.getElementById('petStatusChart'), {
                type: 'doughnut',
                data: {
                    labels: filteredLabels,
                    datasets: [{
                        data: filteredValues,
                        backgroundColor: filteredColors,
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 15
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    cutout: '50%'
                }
            });
        }

        // 3. Financial Bar Chart
        if (document.getElementById('financialChart')) {
            const financialMonths = financialData.map(item => item.month_name);
            const revenueData = financialData.map(item => convertToNumber(item.revenue));
            const commissionData = financialData.map(item => convertToNumber(item.commission));
            const sitterData = financialData.map(item => convertToNumber(item.sitter_earnings));

            new Chart(document.getElementById('financialChart'), {
                type: 'bar',
                data: {
                    labels: financialMonths,
                    datasets: [{
                            label: 'Total Revenue',
                            data: revenueData,
                            backgroundColor: '#3B7A57'
                        },
                        {
                            label: 'Commission (10%)',
                            data: commissionData,
                            backgroundColor: '#ed8936'
                        },
                        {
                            label: 'Sitter Earnings (90%)',
                            data: sitterData,
                            backgroundColor: '#4299e1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    },
                    scales: {
                        x: {
                            stacked: false
                        },
                        y: {
                            stacked: false,
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'RM' + convertToNumber(value);
                                }
                            }
                        }
                    }
                }
            });
        }

        // ==================== TOP EARNERS CHART - FIXED VERSION ====================
        if (document.getElementById('topEarnersChart')) {
            console.log('Initializing Top Earners Chart...');

            const topEarnersCtx = document.getElementById('topEarnersChart').getContext('2d');

            // Data preparation
            const topEarningsDataNumeric = topEarningsData.map(value => convertToNumber(value));
            const topEarnersJobsNumeric = topEarnersJobs.map(value => convertToNumber(value));

            // Debug: Check data
            console.log('Top Earnings Data:', topEarningsDataNumeric);
            console.log('Top Earners Labels:', topEarnersLabels);

            // Jika data kosong, tampilkan no data state
            if (topEarningsDataNumeric.length === 0 || topEarningsDataNumeric.every(val => val === 0)) {
                console.log('No earnings data available');
                const topEarnersContainer = document.querySelector('#topEarnersChart')?.parentElement;
                if (topEarnersContainer) {
                    topEarnersContainer.innerHTML = `
                <div class="no-data-state">
                    <div class="no-data-icon">💰</div>
                    <div class="no-data-text">
                        <strong>No Earnings Data Yet</strong>
                        <p style="margin-top: 5px; font-size: 13px; color: #888;">
                            Pet sitters haven't completed any jobs yet
                        </p>
                    </div>
                </div>
            `;
                }
            }

            // Gunakan solid colors untuk mudah (bukan gradient)
            const rankColors = [
                'rgba(59, 122, 87, 0.9)', // 1st place
                'rgba(66, 153, 225, 0.9)', // 2nd place
                'rgba(237, 137, 54, 0.9)', // 3rd place
                'rgba(159, 122, 234, 0.9)', // 4th place
                'rgba(229, 62, 62, 0.9)', // 5th place
                'rgba(72, 187, 120, 0.9)', // 6th place
                'rgba(245, 101, 101, 0.9)', // 7th place
                'rgba(236, 201, 75, 0.9)' // 8th place
            ];

            const hoverColors = rankColors.map(color =>
                color.replace('0.9)', '1)') // Lighten opacity
            );

            // Create chart dengan SIMPLE VERSION tanpa gradient
            try {
                const topEarnersChart = new Chart(topEarnersCtx, {
                    type: 'bar',
                    data: {
                        labels: topEarnersLabels,
                        datasets: [{
                            label: 'Total Earnings',
                            data: topEarningsDataNumeric,
                            backgroundColor: rankColors.slice(0, topEarnersLabels.length),
                            borderColor: 'rgba(255, 255, 255, 0.8)',
                            borderWidth: 2,
                            borderRadius: {
                                topLeft: 8,
                                topRight: 8,
                                bottomLeft: 8,
                                bottomRight: 8
                            },
                            borderSkipped: false,
                            hoverBackgroundColor: hoverColors.slice(0, topEarnersLabels.length), // FIXED: Use array of colors
                            hoverBorderWidth: 3,
                            hoverBorderColor: '#ffffff',
                            barPercentage: 0.85,
                            categoryPercentage: 0.9
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        layout: {
                            padding: {
                                left: 10,
                                right: 10,
                                top: 20,
                                bottom: 20
                            }
                        },
                        onClick: (evt, activeElements) => {
                            if (activeElements.length > 0) {
                                const index = activeElements[0].index;
                                openEarnerModal(index);
                            }
                        },
                        onHover: (event, chartElement) => {
                            event.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                enabled: true,
                                backgroundColor: 'rgba(17, 24, 39, 0.95)',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                borderColor: '#3B7A57',
                                borderWidth: 2,
                                borderRadius: 12,
                                padding: 16,
                                displayColors: false,
                                usePointStyle: true,
                                caretSize: 8,
                                titleFont: {
                                    size: 14,
                                    weight: '600',
                                    family: "'Inter', 'Segoe UI', sans-serif"
                                },
                                bodyFont: {
                                    size: 13,
                                    family: "'Inter', 'Segoe UI', sans-serif"
                                },
                                footerFont: {
                                    size: 12,
                                    family: "'Inter', 'Segoe UI', sans-serif"
                                },
                                callbacks: {
                                    title: function(tooltipItems) {
                                        const index = tooltipItems[0].dataIndex;
                                        const name = topEarnersOriginalNames[index] || topEarnersLabels[index];
                                        const rank = index + 1;
                                        return `🏆 #${rank} - ${name}`;
                                    },
                                    label: function(context) {
                                        const value = convertToNumber(context.raw);
                                        const jobs = convertToNumber(topEarnersJobsNumeric[context.dataIndex]);
                                        const avgPerJob = jobs > 0 ? (value / jobs) : 0;

                                        return [
                                            `💰 Total Earnings: ${formatCurrency(value)}`,
                                            `📊 Jobs Completed: ${formatNumber(jobs)}`,
                                            `📈 Average per Job: ${formatCurrency(avgPerJob)}`,
                                            '',
                                            `👑 Rank: #${context.dataIndex + 1}`
                                        ];
                                    },
                                    afterLabel: function(context) {
                                        const value = convertToNumber(context.raw);
                                        const commission = value * 0.1111;
                                        const sitterEarnings = value - commission;

                                        return [
                                            '',
                                            '📋 Breakdown:',
                                            `• Platform (10%): ${formatCurrency(commission)}`,
                                            `• Sitter (90%): ${formatCurrency(sitterEarnings)}`
                                        ];
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)',
                                    drawBorder: false,
                                    drawTicks: false
                                },
                                ticks: {
                                    callback: function(value) {
                                        return formatCurrency(value);
                                    },
                                    font: {
                                        size: 12,
                                        family: "'Inter', 'Segoe UI', sans-serif",
                                        weight: '500'
                                    },
                                    color: '#666',
                                    padding: 10,
                                    maxTicksLimit: 8
                                },
                                title: {
                                    display: true,
                                    text: 'Total Earnings (RM)',
                                    font: {
                                        size: 13,
                                        weight: '600',
                                        family: "'Inter', 'Segoe UI', sans-serif"
                                    },
                                    color: '#2c3e50',
                                    padding: {
                                        top: 15,
                                        bottom: 10
                                    }
                                }
                            },
                            y: {
                                grid: {
                                    display: false,
                                    drawBorder: false
                                },
                                ticks: {
                                    font: {
                                        size: 13,
                                        weight: '600',
                                        family: "'Inter', 'Segoe UI', sans-serif"
                                    },
                                    color: '#2c3e50',
                                    padding: 12,
                                    callback: function(value, index) {
                                        // Tambah ranking number
                                        const rank = index + 1;
                                        const rankEmoji = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : `${rank}.`;
                                        return `${rankEmoji} ${this.getLabelForValue(index)}`;
                                    }
                                }
                            }
                        },
                        animation: {
                            duration: 1200,
                            easing: 'easeOutQuart'
                        },
                        interaction: {
                            mode: 'nearest',
                            axis: 'y',
                            intersect: false
                        }
                    }
                });

                console.log('Top Earners Chart initialized successfully!');

                // Tambah event listener untuk hover effect
                topEarnersCtx.canvas.addEventListener('mousemove', function(event) {
                    const points = topEarnersChart.getElementsAtEventForMode(
                        event,
                        'nearest', {
                            intersect: true
                        },
                        true
                    );

                    if (points.length) {
                        this.style.cursor = 'pointer';
                    } else {
                        this.style.cursor = 'default';
                    }
                });

            } catch (error) {
                console.error('Error initializing Top Earners Chart:', error);
                const topEarnersContainer = document.querySelector('#topEarnersChart')?.parentElement;
                if (topEarnersContainer) {
                    topEarnersContainer.innerHTML = `
                <div class="no-data-state">
                    <div class="no-data-icon">❌</div>
                    <div class="no-data-text">
                        <strong>Chart Error</strong>
                        <p style="margin-top: 5px; font-size: 13px; color: #888;">
                            Failed to load chart data. Please refresh the page.
                        </p>
                        <p style="font-size: 11px; color: #999; margin-top: 5px;">
                            Error: ${error.message}
                        </p>
                    </div>
                </div>
            `;
                }
            }
        }

        // ==================== MOST ACTIVE USERS CHART - FIXED VERSION ====================
        if (document.getElementById('mostActiveChart')) {
            console.log('Initializing Most Active Users Chart...');

            const mostActiveCtx = document.getElementById('mostActiveChart').getContext('2d');

            // Data preparation
            const mostActiveDataNumeric = mostActiveData.map(value => convertToNumber(value));

            // Debug: Check data
            console.log('Most Active Data:', mostActiveDataNumeric);
            console.log('Most Active Labels:', mostActiveLabels);

            // Jika data kosong, tampilkan no data state
            if (mostActiveDataNumeric.length === 0 || mostActiveDataNumeric.every(val => val === 0)) {
                console.log('No activity data available');
                const mostActiveContainer = document.querySelector('#mostActiveChart')?.parentElement;
                if (mostActiveContainer) {
                    mostActiveContainer.innerHTML = `
                <div class="no-data-state">
                    <div class="no-data-icon">👥</div>
                    <div class="no-data-text">
                        <strong>No Activity Data Yet</strong>
                        <p style="margin-top: 5px; font-size: 13px; color: #888;">
                            Users haven't completed any transactions yet
                        </p>
                    </div>
                </div>
            `;
                }
            }

            try {
                // Create chart dengan SIMPLE VERSION
                const mostActiveChart = new Chart(mostActiveCtx, {
                    type: 'bar',
                    data: {
                        labels: mostActiveLabels,
                        datasets: [{
                            label: 'Total Activities',
                            data: mostActiveDataNumeric,
                            backgroundColor: 'rgba(59, 122, 87, 0.9)',
                            borderColor: 'rgba(255, 255, 255, 0.8)',
                            borderWidth: 2,
                            borderRadius: {
                                topLeft: 10,
                                topRight: 10,
                                bottomLeft: 10,
                                bottomRight: 10
                            },
                            borderSkipped: false,
                            hoverBackgroundColor: 'rgba(45, 96, 71, 0.9)',
                            hoverBorderWidth: 3,
                            hoverBorderColor: '#ffffff',
                            barPercentage: 0.7,
                            categoryPercentage: 0.8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        layout: {
                            padding: {
                                left: 15,
                                right: 15,
                                top: 20,
                                bottom: 20
                            }
                        },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    color: '#2c3e50',
                                    font: {
                                        size: 13,
                                        family: "'Inter', 'Segoe UI', sans-serif",
                                        weight: '500'
                                    },
                                    padding: 20,
                                    usePointStyle: true,
                                    pointStyle: 'rectRounded',
                                    boxWidth: 12,
                                    boxHeight: 12
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(17, 24, 39, 0.95)',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                borderColor: '#4299e1',
                                borderWidth: 2,
                                borderRadius: 12,
                                padding: 16,
                                displayColors: false,
                                usePointStyle: true,
                                caretSize: 8,
                                titleFont: {
                                    size: 14,
                                    weight: '600',
                                    family: "'Inter', 'Segoe UI', sans-serif"
                                },
                                bodyFont: {
                                    size: 13,
                                    family: "'Inter', 'Segoe UI', sans-serif"
                                },
                                callbacks: {
                                    title: function(tooltipItems) {
                                        const index = tooltipItems[0].dataIndex;
                                        const name = mostActiveOriginalNames[index] || mostActiveLabels[index];
                                        const rank = index + 1;
                                        const rankIcon = rank === 1 ? '👑' : rank === 2 ? '⭐' : rank === 3 ? '🔥' : '📊';
                                        return `${rankIcon} #${rank} - ${name}`;
                                    },
                                    label: function(context) {
                                        const value = convertToNumber(context.raw);
                                        return `📈 Total Activities: ${formatNumber(value)}`;
                                    },
                                    afterLabel: function(context) {
                                        const index = context.dataIndex;
                                        const breakdown = mostActiveBreakdown[index];

                                        if (!breakdown) return '';

                                        const totalSitter = convertToNumber(breakdown.sitter_jobs);
                                        const totalOwner = convertToNumber(breakdown.owner_petsits);
                                        const totalAdoptMade = convertToNumber(breakdown.adoptions_made);
                                        const totalAdoptOut = convertToNumber(breakdown.pets_adopted_out);

                                        return [
                                            '',
                                            '📋 Activity Breakdown:',
                                            `• 🐾 As Sitter: ${formatNumber(totalSitter)} jobs`,
                                            `• 🏠 As Owner: ${formatNumber(totalOwner)} pet sits`,
                                            `• 🐕 Adoptions Made: ${formatNumber(totalAdoptMade)}`,
                                            `• 📤 Pets Adopted Out: ${formatNumber(totalAdoptOut)}`,
                                            '',
                                        ];
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)',
                                    drawBorder: false,
                                    drawTicks: false,
                                    lineWidth: 1
                                },
                                ticks: {
                                    stepSize: 1,
                                    font: {
                                        size: 12,
                                        family: "'Inter', 'Segoe UI', sans-serif",
                                        weight: '500'
                                    },
                                    color: '#666',
                                    padding: 8,
                                    callback: function(value) {
                                        if (Number.isInteger(value)) {
                                            return formatNumber(value);
                                        }
                                    }
                                },
                                title: {
                                    display: true,
                                    text: 'Number of Activities',
                                    font: {
                                        size: 13,
                                        weight: '600',
                                        family: "'Inter', 'Segoe UI', sans-serif"
                                    },
                                    color: '#2c3e50',
                                    padding: {
                                        top: 5,
                                        bottom: 15
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    display: false,
                                    drawBorder: false
                                },
                                ticks: {
                                    font: {
                                        size: 13,
                                        weight: '600',
                                        family: "'Inter', 'Segoe UI', sans-serif"
                                    },
                                    color: '#2c3e50',
                                    padding: 10,
                                    maxRotation: 45,
                                    minRotation: 0,
                                    callback: function(value, index) {
                                        const label = this.getLabelForValue(index);
                                        const rank = index + 1;
                                        const rankBadge = rank <= 3 ? ['🥇', '🥈', '🥉'][rank - 1] : `${rank}.`;
                                        return `${rankBadge} ${label}`;
                                    }
                                }
                            }
                        },
                        interaction: {
                            mode: 'nearest',
                            axis: 'x',
                            intersect: false
                        }
                    }
                });

                console.log('Most Active Users Chart initialized successfully!');

            } catch (error) {
                console.error('Error initializing Most Active Users Chart:', error);
                const mostActiveContainer = document.querySelector('#mostActiveChart')?.parentElement;
                if (mostActiveContainer) {
                    mostActiveContainer.innerHTML = `
                <div class="no-data-state">
                    <div class="no-data-icon">❌</div>
                    <div class="no-data-text">
                        <strong>Chart Error</strong>
                        <p style="margin-top: 5px; font-size: 13px; color: #888;">
                            Failed to load chart data. Please refresh the page.
                        </p>
                        <p style="font-size: 11px; color: #999; margin-top: 5px;">
                            Error: ${error.message}
                        </p>
                    </div>
                </div>
            `;
                }
            }
        }

        // ==================== HELPER FUNCTIONS ====================
        function exportChart(canvasId, filename) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;

            const link = document.createElement('a');
            link.download = `FurCare_${filename}_${new Date().toISOString().split('T')[0]}.png`;
            link.href = canvas.toDataURL('image/png', 1.0);
            link.click();

            // Show success message
            showNotification('Chart exported successfully!', 'success');
        }

        function toggleFullscreen(button) {
            const chartCard = button.closest('.chart-card-enhanced');
            const canvasWrapper = chartCard.querySelector('.chart-container-wrapper');

            if (!chartCard.classList.contains('fullscreen')) {
                // Enter fullscreen
                chartCard.classList.add('fullscreen');
                chartCard.style.position = 'fixed';
                chartCard.style.top = '0';
                chartCard.style.left = '0';
                chartCard.style.width = '100vw';
                chartCard.style.height = '100vh';
                chartCard.style.zIndex = '9999';
                chartCard.style.padding = '20px';
                chartCard.style.background = 'white';
                chartCard.style.overflow = 'auto';

                canvasWrapper.style.height = 'calc(100vh - 150px)';
                button.innerHTML = '<span>✕</span> Exit Fullscreen';

                // Update chart size
                const chartId = canvasWrapper.querySelector('canvas')?.id;
                if (chartId) {
                    const chart = Chart.getChart(chartId);
                    if (chart) {
                        chart.resize();
                    }
                }
            } else {
                // Exit fullscreen
                chartCard.classList.remove('fullscreen');
                chartCard.style.position = '';
                chartCard.style.top = '';
                chartCard.style.left = '';
                chartCard.style.width = '';
                chartCard.style.height = '';
                chartCard.style.zIndex = '';
                chartCard.style.padding = '';
                chartCard.style.background = '';
                chartCard.style.overflow = '';

                canvasWrapper.style.height = '320px';
                button.innerHTML = '<span>🔍</span> Zoom';

                // Update chart size
                const chartId = canvasWrapper.querySelector('canvas')?.id;
                if (chartId) {
                    const chart = Chart.getChart(chartId);
                    if (chart) {
                        chart.resize();
                    }
                }
            }
        }

     function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div style="
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${type === 'success' ? '#d4edda' : '#f8d7da'};
            color: ${type === 'success' ? '#155724' : '#721c24'};
            padding: 12px 20px;
            border-radius: 8px;
            border: 1px solid ${type === 'success' ? '#c3e6cb' : '#f5c6cb'};
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            z-index: 10000;
            animation: slideInRight 0.3s ease;
        ">
            ${message}
        </div>
    `;

    document.body.appendChild(notification);

    // Remove after 3 seconds
    setTimeout(() => {
        notification.style.animation = 'slideOutRight 0.3s ease forwards';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

        // Add CSS animations for notifications
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
            
            .chart-card-enhanced.fullscreen {
                animation: fadeIn 0.3s ease;
            }
            
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>

</html>