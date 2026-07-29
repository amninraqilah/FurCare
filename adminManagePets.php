<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$userID = $_SESSION['user_id'];

// Handle approve/reject actions for pets
if (isset($_POST['action'])) {
    $petID = intval($_POST['pet_id']);
    $action = $_POST['action'];
    $admin_notes = trim($_POST['admin_notes'] ?? '');

    if ($action === 'approve') {
        $adminID = $_SESSION['user_id'];
        $stmt = $conn->prepare("UPDATE pet SET ApprovalStatus = 'approved', ApprovedBy = ?, ApprovedAt = NOW() WHERE PetID = ?");
        $stmt->bind_param("ii", $adminID, $petID);
        $stmt->execute();
        header("Location: adminManagePets.php?success=Pet approved successfully");
        exit;
    } elseif ($action === 'reject') {
        $adminID = $_SESSION['user_id'];
        $admin_notes = trim($_POST['admin_notes'] ?? '');

        $stmt = $conn->prepare("UPDATE pet SET ApprovalStatus = 'rejected', ApprovedBy = ?, ApprovedAt = NOW(), RejectionReason = ? WHERE PetID = ?");
        $stmt->bind_param("isi", $adminID, $admin_notes, $petID);
        $stmt->execute();
        header("Location: adminManagePets.php?success=Pet rejected successfully");
        exit;
    }
}

// Handle delete pet
if (isset($_POST['delete_pet'])) {
    $petID = intval($_POST['pet_id']);
    $stmt = $conn->prepare("DELETE FROM pet WHERE PetID = ?");
    $stmt->bind_param("i", $petID);
    $stmt->execute();
    header("Location: adminManagePets.php?success=Pet deleted successfully");
    exit;
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$search = $_GET['search'] ?? '';
$tab = $_GET['tab'] ?? 'all';

// Build query with filters
$where_conditions = ["1=1"];
$params = [];
$types = "";

// Logika untuk Tab
if ($tab === 'pending') {
    $where_conditions[] = "p.ApprovalStatus = 'pending'";
} elseif ($tab === 'approved') {
    $where_conditions[] = "p.ApprovalStatus = 'approved'";
} elseif ($tab === 'rejected') {
    $where_conditions[] = "p.ApprovalStatus = 'rejected'";
} elseif ($tab === 'overdue') {
    $where_conditions[] = "p.PostType = 'Pet Sit'";
    $where_conditions[] = "p.Status = 'Available'"; // HANYA Available (bukan Pet Sit)
    $where_conditions[] = "p.SitStartDate < CURDATE()";
    $where_conditions[] = "NOT EXISTS (
        SELECT 1 FROM petsitrequest psr 
        WHERE psr.PetID = p.PetID 
        AND psr.Status IN ('approved', 'pending', 'completed')
    )";
} elseif ($tab === 'admin') {
    $where_conditions[] = "p.OwnerID = ?";
    $params[] = $userID;
    $types .= "i";
}

// Filter Jenis (Type)
if ($type_filter !== 'all') {
    $where_conditions[] = "p.Type = ?";
    $params[] = $type_filter;
    $types .= "s";
}

// Filter Status
if ($status_filter !== 'all' && $tab !== 'overdue') {
    $where_conditions[] = "p.Status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

// Search Filter
if (!empty($search)) {
    $where_conditions[] = "(p.Name LIKE ? OR p.Breed LIKE ? OR u.Name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "sss";
}

$where_sql = implode(' AND ', $where_conditions);

// Get pets with owner information
$query = "SELECT p.*, 
          u.Name as OwnerName, u.Email as OwnerEmail, u.Phone as OwnerPhone, u.Role as OwnerRole
          FROM pet p
          JOIN user u ON p.OwnerID = u.UserID
          WHERE $where_sql
          ORDER BY p.CreatedAt DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$pets = $stmt->get_result();

// Get stats for tabs
$total_pets = $conn->query("SELECT COUNT(*) as count FROM pet")->fetch_assoc()['count'];
$pending_pets = $conn->query("SELECT COUNT(*) as count FROM pet WHERE ApprovalStatus = 'pending'")->fetch_assoc()['count'];
$approved_pets = $conn->query("SELECT COUNT(*) as count FROM pet WHERE ApprovalStatus = 'approved'")->fetch_assoc()['count'];
$rejected_pets = $conn->query("SELECT COUNT(*) as count FROM pet WHERE ApprovalStatus = 'rejected'")->fetch_assoc()['count'];

// OVERDUE: Hanya Available pet sit, tarikh lepas, tiada requests
$overdue_pets = $conn->query("
    SELECT COUNT(*) as count 
    FROM pet p
    WHERE p.PostType = 'Pet Sit' 
    AND p.Status = 'Available'  -- HANYA Available
    AND p.SitStartDate < CURDATE()
    AND NOT EXISTS (
        SELECT 1 
        FROM petsitrequest psr 
        WHERE psr.PetID = p.PetID 
        AND psr.Status IN ('approved', 'pending', 'completed')
    )
")->fetch_assoc()['count'];

$admin_pets = $conn->query("SELECT COUNT(*) as count FROM pet WHERE OwnerID = $userID")->fetch_assoc()['count'];

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
    <title>Manage Pets - FurCare</title>
    <link rel="stylesheet" href="css/adminDashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            background-color: #FFF9F5;
            color: #333;
            display: flex;
        }

        .sidebar {
            width: 240px;
            height: 100vh;
            background: linear-gradient(180deg, #FFDFC8 0%, #FFD4EC 100%);
            padding: 22px;
            box-sizing: border-box;
            color: #5A4A42;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1000;
            border-right: 2px solid #F2C9C1;
            /* soft pastel border */
            box-shadow: 4px 0 12px rgba(0, 0, 0, 0.08);
        }

        .sidebar .logo {
            margin: 0 0 28px;
            font-size: 1.9em;
            font-weight: 700;
            text-align: center;
            color: #fff;
            letter-spacing: 1px;
        }

        /* Menu items */
        .sidebar a {
            display: block;
            padding: 12px 15px;
            margin: 6px 0;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            color: #5A4A42;
            background: rgba(255, 255, 255, 0.25);
            transition: 0.25s ease;
        }

        /* Hover */
        .sidebar a:hover {
            background: rgba(255, 255, 255, 0.45);
            transform: translateX(4px);
        }

        /* Active */
        .sidebar a.active {
            background: rgba(255, 255, 255, 0.6);
            border: 1px solid #f6cae6;
            padding-left: 10px;
        }

        /* Logout */
        .sidebar a.logout {
            margin-top: auto;
            background: rgba(255, 140, 100, 0.25);
            color: #A13F22;
        }

        .sidebar a.logout:hover {
            background: rgba(255, 140, 100, 0.4);
        }

        /* ===== Main Content ===== */
        .main-content {
            flex: 1;
            padding: 30px;
            margin-left: 280px;
            min-height: 100vh;
            box-sizing: border-box;
        }

        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .navbar h1 {
            color: #3B7A57;
            margin: 0;
            font-weight: 600;
        }

        .profile-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid #C57BAA;
        }

        /* ===== Tabs Styling ===== */
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
            flex-wrap: wrap;
        }

        .tab {
            padding: 12px 20px;
            background: #f8f9fa;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            flex-grow: 1;
            text-align: center;
            min-width: 120px;
        }

        .tab.active {
            background: white;
            border-bottom-color: #6DBE81;
            color: #6DBE81;
        }

        .tab:hover {
            background: #e9ecef;
        }

        .tab-badge {
            background: #6c757d;
            color: white;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 0.8em;
            margin-left: 6px;
        }

        .tab-admin .tab-badge {
            background: #6c757d;
        }

        /* ===== Buttons ===== */
        .add-btn {
            background: linear-gradient(45deg, #3B7A57, #6DBE81);
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            transition: 0.3s;
            margin: 20px 0;
            text-decoration: none;
            display: inline-block;
        }

        .add-btn:hover {
            background: linear-gradient(45deg, #FF6F91, #FFB6C1);
            transform: scale(1.05);
        }

        /* ===== Table ===== */
        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            font-size: 13px;
        }

        table th,
        table td {
            padding: 10px 8px;
            text-align: left;
        }

        table th {
            background: #3B7A57;
            color: #fff;
            font-weight: 600;
            font-size: 14px;
        }

        table tr:nth-child(even) {
            background: #F9F9F9;
        }

        table tr:hover {
            background: #FFEEF4;
        }

        /* ===== Action Buttons Styling ===== */
        .action-buttons {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 8px;
            padding: 5px 0;
            justify-content: center;
        }

        .action-btn {
            text-decoration: none;
            width: 35px;
            height: 35px;
            text-align: center;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            position: relative;
        }

        .action-btn:hover {
            transform: translateY(-2px) scale(1.1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        /* View Button */
        .btn-view {
            background: linear-gradient(45deg, #2196F3, #42A5F5);
        }

        .btn-view:hover {
            background: linear-gradient(45deg, #1976D2, #2196F3);
        }

        /* Edit Button */
        .btn-edit {
            background: linear-gradient(45deg, #FFB74D, #FFA726);
        }

        .btn-edit:hover {
            background: linear-gradient(45deg, #FF9800, #FB8C00);
        }

        /* Approve Button */
        .btn-approve {
            background: linear-gradient(45deg, #4CAF50, #66BB6A);
        }

        .btn-approve:hover {
            background: linear-gradient(45deg, #388E3C, #4CAF50);
        }

        /* Reject Button */
        .btn-reject {
            background: linear-gradient(45deg, #FF6F91, #FF8FA3);
        }

        .btn-reject:hover {
            background: linear-gradient(45deg, #EC407A, #FF6F91);
        }

        /* Delete Button */
        .btn-delete {
            background: linear-gradient(45deg, #F44336, #EF5350);
        }

        .btn-delete:hover {
            background: linear-gradient(45deg, #D32F2F, #F44336);
        }

        /* Disabled Delete Button */
        .btn-disabled {
            background: linear-gradient(45deg, #cccccc, #aaaaaa) !important;
            color: #888888 !important;
            cursor: not-allowed !important;
            opacity: 0.6 !important;
            pointer-events: none !important;
        }

        .btn-disabled:hover {
            transform: none !important;
            box-shadow: none !important;
        }

        /* Tooltip for disabled buttons */
        .action-btn[disabled] {
            position: relative;
        }

        .action-btn[disabled]::before {
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

        .action-btn[disabled]:hover::before {
            opacity: 1;
        }

        /* ===== Approval Status Styling ===== */
        .approval-status {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-approved {
            background: #48bb78;
            color: white;
        }

        .status-pending {
            background: #ed8936;
            color: white;
        }

        .status-rejected {
            background: #e53e3e;
            color: white;
        }

        /* ===== Status Badges ===== */
        .status {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            text-align: center;
            display: inline-block;
            min-width: 70px;
            text-transform: capitalize;
        }

        /* Adoption Status */
        .status.available {
            background: linear-gradient(45deg, #4CAF50, #66BB6A);
            color: white;
        }

        .status.adopted {
            background: linear-gradient(45deg, #FF6F91, #FF8FA3);
            color: white;
        }

        .status.pending {
            background: linear-gradient(45deg, #FFB74D, #FFA726);
            color: white;
        }

        /* Pet Sit Status */
        .status.pet-sit {
            background: linear-gradient(45deg, #2196F3, #42A5F5);
            color: white;
        }

        .status.sitting {
            background: linear-gradient(45deg, #9C27B0, #BA68C8);
            color: white;
        }

        .status.completed {
            background: linear-gradient(45deg, #4CAF50, #66BB6A);
            color: white;
        }

        .status.overdue {
            background: linear-gradient(45deg, #F44336, #EF5350);
            color: white;
            animation: pulse 2s infinite;
        }

        .status.cancelled {
            background: linear-gradient(45deg, #757575, #9E9E9E);
            color: white;
        }

        @keyframes pulse {
            0% {
                opacity: 1;
            }

            50% {
                opacity: 0.7;
            }

            100% {
                opacity: 1;
            }
        }

        /* ===== Image Styling ===== */
        .pet-image {
            width: 50px;
            height: 50px;
            border-radius: 6px;
            object-fit: cover;
            border: 2px solid #e0e0e0;
        }

        /* ===== Success/Error Messages ===== */
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
            border: 1px solid #c3e6cb;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
            border: 1px solid #f5c6cb;
        }

        /* ===== Filters ===== */
        .filters {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .filter-group {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 15px;
            align-items: end;
        }

        .search-container input {
            width: 95%;
            padding: 8px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }

        .filter-container select {
            width: 100%;
            padding: 8px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            background: white;
        }

        /* ===== Post Type Badges ===== */
        .post-type {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            text-align: center;
            display: inline-block;
            min-width: 60px;
        }

        .post-type.adopt {
            background: linear-gradient(45deg, #4CAF50, #66BB6A);
            color: white;
        }

        .post-type.pet-sit {
            background: linear-gradient(45deg, #2196F3, #42A5F5);
            color: white;
        }

        /* ===== MODAL STYLING ===== */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(3px);
            animation: modalFadeIn 0.3s ease;
            overflow-y: auto;
            padding: 20px 0;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .modal-content {
            background-color: white;
            margin: 8% auto;
            padding: 0;
            border-radius: 16px;
            width: 480px;
            max-width: 90%;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            animation: modalSlideIn 0.3s ease;
            overflow: hidden;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-30px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 22px 24px;
            border-bottom: 1px solid #eaeaea;
            background: #f9f9f9;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.3em;
            font-weight: 600;
            color: #2c3e50;
        }

        .modal-body {
            padding: 28px 24px;
        }

        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid #eaeaea;
            background: #f9f9f9;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .close-modal {
            font-size: 28px;
            cursor: pointer;
            color: #7f8c8d;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
            font-weight: 300;
        }

        .close-modal:hover {
            background-color: #eee;
            color: #e74c3c;
        }

        /* Form Elements in Modal */
        .modal-form {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-weight: 500;
            color: #34495e;
            font-size: 0.95em;
        }

        .form-group textarea {
            width: 95%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 100px;
            transition: all 0.3s;
        }

        .form-group textarea:focus {
            outline: none;
            border-color: #6DBE81;
            box-shadow: 0 0 0 3px rgba(109, 190, 129, 0.1);
        }

        .modal-message {
            text-align: center;
            padding: 15px 0;
            color: #2c3e50;
            font-size: 1.05em;
            line-height: 1.6;
        }

        /* Modal Action Buttons */
        .modal-btn {
            padding: 11px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95em;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 140px;
            font-family: inherit;
        }

        .modal-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .modal-btn:active {
            transform: translateY(0);
        }

        .modal-btn-cancel {
            background: #95a5a6;
            color: white;
        }

        .modal-btn-cancel:hover {
            background: #7f8c8d;
        }

        .modal-btn-approve {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
        }

        .modal-btn-approve:hover {
            background: linear-gradient(135deg, #219653, #27ae60);
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
        }

        .modal-btn-reject {
            background: linear-gradient(135deg, #e74c3c, #ff6b6b);
            color: white;
        }

        .modal-btn-reject:hover {
            background: linear-gradient(135deg, #c0392b, #e74c3c);
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.3);
        }

        .modal-btn-delete {
            background: linear-gradient(135deg, #e74c3c, #ff6b6b);
            color: white;
        }

        .modal-btn-delete:hover {
            background: linear-gradient(135deg, #c0392b, #e74c3c);
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.3);
        }

        /* Modal Specific Styles */
        #approveModal .modal-header {
            border-bottom-color: #2ecc71;
        }

        #approveModal .modal-header h3 {
            color: #27ae60;
        }

        #rejectModal .modal-header {
            border-bottom-color: #e74c3c;
        }

        #rejectModal .modal-header h3 {
            color: #c0392b;
        }

        #deleteModal .modal-header {
            border-bottom-color: #e74c3c;
        }

        #deleteModal .modal-header h3 {
            color: #c0392b;
        }

        /* Alert/Notice in Modal */
        .modal-notice {
            background: #fff8e1;
            border-left: 4px solid #ffb300;
            padding: 14px 16px;
            margin: 20px 0;
            border-radius: 8px;
            font-size: 0.9em;
            color: #5d4037;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .modal-notice i {
            color: #ffb300;
            margin-top: 2px;
            font-size: 1.1em;
        }

        /* Warning for Delete Modal */
        .delete-warning {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 18px;
            background: linear-gradient(135deg, #ffebee, #ffcdd2);
            border-radius: 10px;
            margin: 20px 0;
            border-left: 4px solid #e74c3c;
        }

        .delete-warning i {
            color: #e74c3c;
            font-size: 1.8em;
            min-width: 40px;
        }

        .delete-warning p {
            margin: 0;
            color: #c62828;
            font-weight: 500;
            line-height: 1.5;
        }

        .warning-text {
            color: #e74c3c;
            font-weight: 500;
            text-align: center;
            padding: 15px;
            background: #ffebee;
            border-radius: 8px;
            border: 2px dashed #ef9a9a;
            margin: 20px 0;
        }

        /* Icon in Modal Header */
        .modal-icon {
            font-size: 1.8em;
            margin-right: 12px;
            vertical-align: middle;
        }

        /* Tooltip for action buttons */
        .action-btn::after {
            content: attr(title);
            position: absolute;
            bottom: -30px;
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

        .action-btn:hover::after {
            opacity: 1;
        }

        /* ===== Responsive Design ===== */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .tabs {
                flex-direction: column;
            }

            .tab {
                width: 100%;
                text-align: left;
            }

            table {
                font-size: 11px;
            }

            table th,
            table td {
                padding: 6px 4px;
            }

            .action-buttons {
                flex-direction: column;
                gap: 3px;
            }

            .action-btn {
                width: 30px;
                height: 30px;
                font-size: 12px;
            }

            .filter-group {
                grid-template-columns: 1fr;
            }

            /* Responsive Modal */
            .modal-content {
                width: 95%;
                margin: 10% auto;
                max-height: 90vh;
                overflow-y: auto;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-btn {
                width: 100%;
                margin-bottom: 8px;
            }

            .modal-header {
                padding: 18px 20px;
            }

            .modal-body {
                padding: 20px;
            }

            .modal-footer {
                padding: 18px 20px;
            }

            .delete-warning {
                flex-direction: column;
                text-align: center;
                padding: 15px;
            }

            .delete-warning i {
                font-size: 2em;
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
        <a href="adminSetting.php">⚙️ Settings</a>
        <a href="logout.php" class="logout">🚪 Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="navbar">
            <h1>Manage Pets</h1>
            <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
                alt="Profile"
                class="profile-icon">
        </div>

        <!-- Success Message -->
        <?php if (isset($_GET['success'])): ?>
            <div class="success-message">
                ✅ <?php echo htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <div class="tab <?php echo $tab === 'all' ? 'active' : ''; ?>"
                onclick="window.location.href='adminManagePets.php?tab=all&type=<?php echo $type_filter; ?>&search=<?php echo urlencode($search); ?>'">
                All Pets
                <span class="tab-badge"><?php echo $total_pets; ?></span>
            </div>
            <div class="tab tab-admin <?php echo $tab === 'admin' ? 'active' : ''; ?>"
                onclick="window.location.href='adminManagePets.php?tab=admin&type=<?php echo $type_filter; ?>&search=<?php echo urlencode($search); ?>'">
                My Pet
                <span class="tab-badge"><?php echo $admin_pets; ?></span>
            </div>
            <div class="tab <?php echo $tab === 'pending' ? 'active' : ''; ?>"
                onclick="window.location.href='adminManagePets.php?tab=pending&type=<?php echo $type_filter; ?>&search=<?php echo urlencode($search); ?>'">
                Pending Approval
                <span class="tab-badge"><?php echo $pending_pets; ?></span>
            </div>
            <div class="tab <?php echo $tab === 'approved' ? 'active' : ''; ?>"
                onclick="window.location.href='adminManagePets.php?tab=approved&type=<?php echo $type_filter; ?>&search=<?php echo urlencode($search); ?>'">
                Approved
                <span class="tab-badge"><?php echo $approved_pets; ?></span>
            </div>
            <div class="tab <?php echo $tab === 'rejected' ? 'active' : ''; ?>"
                onclick="window.location.href='adminManagePets.php?tab=rejected&type=<?php echo $type_filter; ?>&search=<?php echo urlencode($search); ?>'">
                Rejected
                <span class="tab-badge"><?php echo $rejected_pets; ?></span>
            </div>
        </div>

        <!-- Add Pet Button -->
        <a href="addPet.php" class="add-btn">+ Add New Pet</a>

        <!-- Filters -->
        <div class="filters">
            <form method="GET">
                <input type="hidden" name="tab" value="<?php echo $tab; ?>">
                <div class="filter-group">
                    <div class="search-container">
                        <input type="text" name="search" placeholder="Search by pet name, breed, or owner..."
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="filter-container">
                        <select name="type">
                            <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All Types</option>
                            <?php
                            $types_result = $conn->query("SELECT DISTINCT Type FROM pet WHERE Type IS NOT NULL AND Type != '' ORDER BY Type");
                            while ($type_row = $types_result->fetch_assoc()): ?>
                                <option value="<?php echo $type_row['Type']; ?>"
                                    <?= $type_filter === $type_row['Type'] ? 'selected' : '' ?>>
                                    <?php echo $type_row['Type']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-container">
                        <select name="status">
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                            <?php
                            $statuses_result = $conn->query("SELECT DISTINCT Status FROM pet WHERE Status IS NOT NULL AND Status != '' ORDER BY Status");
                            while ($status_row = $statuses_result->fetch_assoc()): ?>
                                <option value="<?php echo $status_row['Status']; ?>"
                                    <?= $status_filter === $status_row['Status'] ? 'selected' : '' ?>>
                                    <?php echo $status_row['Status']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn" style="background: #3B7A57; color: white; padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer;">Search</button>
                        <a href="adminManagePets.php?tab=<?php echo $tab; ?>" class="btn" style="background: #6c757d; color: white; padding: 8px 16px; text-decoration: none; border-radius: 6px; display: inline-block;">Clear</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Pets Table -->
        <table>
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Pet Name</th>
                    <th>Type</th>
                    <th>Breed</th>
                    <th>Owner</th>
                    <th>Post Type</th>
                    <th>Status</th>
                    <th>Approval</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($pets->num_rows > 0): ?>
                    <?php while ($pet = $pets->fetch_assoc()):
                        $petID = $pet['PetID'];

                        // Check if this pet is overdue
                        $is_overdue = false;

                        if ($pet['PostType'] === 'Pet Sit' && $pet['Status'] === 'Available') {
                            // Check date
                            if (!empty($pet['SitEndDate']) && strtotime($pet['SitEndDate']) < time()) {
                                // Check if there are active pet sit requests
                                $request_check = $conn->prepare("
                                    SELECT 1 
                                    FROM petsitrequest 
                                    WHERE PetID = ? 
                                    AND Status IN ('approved', 'pending')
                                    LIMIT 1
                                ");
                                $request_check->bind_param("i", $petID);
                                $request_check->execute();
                                $request_check->store_result();

                                // Only overdue if NO active requests
                                $is_overdue = ($request_check->num_rows === 0);
                                $request_check->close();
                            }
                        }

                        $status_class = strtolower(str_replace(' ', '-', $pet['Status']));

                        // Check if pet can be deleted
                        $canDelete = false;
                        $disabledTitle = '';

                        if ($pet['PostType'] === 'Adopt') {
                            // For adopt: only can delete if not adopted
                            $canDelete = ($pet['Status'] !== 'Adopted');
                            if (!$canDelete) {
                                $disabledTitle = 'Cannot delete adopted pet';
                            }
                        } elseif ($pet['PostType'] === 'Pet Sit') {
                            // Rules based on pet status in pet table
                            if ($pet['Status'] === 'Available' || $pet['Status'] === 'Overdue') {
                                // Check if there are ACTIVE pet sit requests (not completed/cancelled/rejected)
                                $activeCheck = $conn->prepare("
            SELECT 1 
            FROM petsitrequest 
            WHERE PetID = ? 
            AND Status NOT IN ('completed', 'cancelled', 'rejected')
            LIMIT 1
        ");
                                $activeCheck->bind_param("i", $petID);
                                $activeCheck->execute();
                                $activeCheck->store_result();

                                // Can delete if NO active requests
                                $hasActiveRequests = ($activeCheck->num_rows > 0);
                                $activeCheck->close();

                                if ($hasActiveRequests) {
                                    // Ada active requests → Tak boleh delete
                                    $canDelete = false;

                                    // Determine message based on pet status
                                    if ($pet['Status'] === 'Available') {
                                        $disabledTitle = 'Cannot delete pet with active pet sitting requests';
                                    } else { // Overdue
                                        $disabledTitle = 'Cannot delete overdue pet with active sitting requests';
                                    }
                                } else {
                                    // TAK ADA active requests → Boleh delete
                                    $canDelete = true;

                                    // Set appropriate message
                                    if ($pet['Status'] === 'Overdue') {
                                        // Check if ada completed/cancelled requests untuk info
                                        $historyCheck = $conn->prepare("
                    SELECT 1 
                    FROM petsitrequest 
                    WHERE PetID = ? 
                    AND Status IN ('completed', 'cancelled')
                    LIMIT 1
                ");
                                        $historyCheck->bind_param("i", $petID);
                                        $historyCheck->execute();
                                        $historyCheck->store_result();
                                        $hasHistory = ($historyCheck->num_rows > 0);
                                        $historyCheck->close();

                                        if ($hasHistory) {
                                            $disabledTitle = 'Can delete - overdue pet with completed/cancelled requests';
                                        } else {
                                            $disabledTitle = 'Can delete - overdue pet with no sitting requests';
                                        }
                                    } else { // Available
                                        $disabledTitle = 'Can delete - available pet with no active requests';
                                    }
                                }
                            } else {
                                // Status lain: 'Pet Sit', 'Sitting', 'Completed', etc.
                                $canDelete = false;

                                // Determine message based on status
                                switch ($pet['Status']) {
                                    case 'Pet Sit':
                                    case 'Sitting':
                                        $disabledTitle = 'Cannot delete pet that is currently being pet sat';
                                        break;
                                    case 'Completed':
                                        $disabledTitle = 'Cannot delete pet that has completed pet sitting';
                                        break;
                                    default:
                                        $disabledTitle = 'Cannot delete pet with current status: ' . $pet['Status'];
                                }
                            }
                        }

                        // Check if pet is owned by admin
                        $isOwnedByAdmin = ($pet['OwnerID'] == $userID);
                    ?>
                        <tr>
                            <td>
                                <img src="<?php echo htmlspecialchars($pet['Image']); ?>"
                                    alt="<?php echo htmlspecialchars($pet['Name']); ?>"
                                    class="pet-image">
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($pet['Name']); ?></strong>
                            </td>
                            <td><?php echo htmlspecialchars($pet['Type']); ?></td>
                            <td><?php echo htmlspecialchars($pet['Breed']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($pet['OwnerName']); ?></strong>
                                <div style="font-size: 0.8em; color: #666;"><?php echo htmlspecialchars($pet['OwnerEmail']); ?></div>
                            </td>
                            <td>
                                <span class="post-type <?php echo strtolower(str_replace(' ', '-', htmlspecialchars($pet['PostType']))); ?>">
                                    <?php echo htmlspecialchars($pet['PostType']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($is_overdue): ?>
                                    <span class="status overdue">Overdue</span>
                                <?php else: ?>
                                    <span class="status <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($pet['Status']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="approval-status status-<?php echo htmlspecialchars($pet['ApprovalStatus']); ?>">
                                    <?php echo ucfirst(htmlspecialchars($pet['ApprovalStatus'])); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="adminPetDetails.php?pet_id=<?php echo $pet['PetID']; ?>"
                                        class="action-btn btn-view"
                                        title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="editPet.php?id=<?php echo $pet['PetID']; ?>"
                                        class="action-btn btn-edit"
                                        title="Edit Pet">
                                        <i class="fas fa-edit"></i>
                                    </a>

                                    <?php if ($pet['ApprovalStatus'] === 'pending' && !$isOwnedByAdmin): ?>
                                        <button class="action-btn btn-approve"
                                            onclick="openApproveModal(<?php echo $pet['PetID']; ?>)"
                                            title="Approve Pet">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="action-btn btn-reject"
                                            onclick="openRejectModal(<?php echo $pet['PetID']; ?>)"
                                            title="Reject Pet">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    <?php endif; ?>

                                    <!-- DELETE BUTTON -->
                                    <?php if ($canDelete): ?>
                                        <button class="action-btn btn-delete"
                                            onclick="openDeleteModal(<?php echo $pet['PetID']; ?>)"
                                            title="Delete Pet">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="action-btn btn-disabled"
                                            title="<?php echo htmlspecialchars($disabledTitle); ?>"
                                            disabled>
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: #666;">
                            <h3>No Pets Found</h3>
                            <p>
                                <?php if ($tab === 'overdue'): ?>
                                    No overdue pet sits found.
                                <?php elseif ($tab === 'admin'): ?>
                                    You don't have any pets listed.
                                    <br>
                                    <a href="addPet.php" style="color: #3B7A57; text-decoration: underline; font-weight: bold;">Add your first pet!</a>
                                <?php else: ?>
                                    No pets match your search criteria.
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Approve Modal -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle modal-icon" style="color: #27ae60;"></i> Approve Pet</h3>
                <span class="close-modal" onclick="closeApproveModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" class="modal-form" id="approveForm">
                    <input type="hidden" name="pet_id" id="approvePetID">
                    <input type="hidden" name="action" value="approve">

                    <div class="modal-message">
                        <p>Are you sure you want to approve this pet?</p>
                        <div class="modal-notice">
                            <i class="fas fa-info-circle"></i>
                            <span>Once approved, the pet will be visible to all users in the platform.</span>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-cancel" onclick="closeApproveModal()">
                    Cancel
                </button>
                <button type="submit" class="modal-btn modal-btn-approve" onclick="document.getElementById('approveForm').submit()">
                    Approve Pet
                </button>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle modal-icon" style="color: #e74c3c;"></i> Reject Pet</h3>
                <span class="close-modal" onclick="closeRejectModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" class="modal-form" id="rejectForm">
                    <input type="hidden" name="pet_id" id="rejectPetID">
                    <input type="hidden" name="action" value="reject">

                    <div class="modal-message">
                        <p>Are you sure you want to reject this pet?</p>
                    </div>

                    <div class="form-group">
                        <label for="rejectReason">
                            <i class="fas fa-comment-alt"></i> Reason for Rejection (Optional):
                        </label>
                        <textarea name="admin_notes" id="rejectReason"
                            placeholder="Please provide a reason for rejecting this pet. This will help the owner understand why their pet listing was not approved..."></textarea>
                    </div>

                    <div class="modal-notice">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>The owner will be notified about this rejection. Please provide constructive feedback if possible.</span>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-cancel" onclick="closeRejectModal()">
                    Cancel
                </button>
                <button type="submit" class="modal-btn modal-btn-reject" onclick="document.getElementById('rejectForm').submit()">
                    Reject Pet
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle modal-icon" style="color: #e74c3c;"></i> Delete Pet</h3>
                <span class="close-modal" onclick="closeDeleteModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" class="modal-form" id="deleteForm">
                    <input type="hidden" name="pet_id" id="deletePetID">
                    <input type="hidden" name="delete_pet" value="1">

                    <div class="delete-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <p><strong>Warning: This action cannot be undone!</strong></p>
                            <p style="font-size: 0.9em; color: #b71c1c; margin-top: 5px;">
                                Deleting this pet will permanently remove it from the system, along with all associated data including adoption requests and pet sit bookings.
                            </p>
                        </div>
                    </div>

                    <div class="modal-message">
                        <p>Are you absolutely sure you want to delete this pet?</p>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-cancel" onclick="closeDeleteModal()">
                    Cancel
                </button>
                <button type="submit" class="modal-btn modal-btn-delete" onclick="document.getElementById('deleteForm').submit()">
                    Delete Permanently
                </button>
            </div>
        </div>
    </div>

    <script>
        // Modal Functions
        function openApproveModal(petID) {
            document.getElementById('approvePetID').value = petID;
            document.getElementById('approveModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function openRejectModal(petID) {
            document.getElementById('rejectPetID').value = petID;
            document.getElementById('rejectModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
            document.getElementById('rejectReason').focus();
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function openDeleteModal(petID) {
            document.getElementById('deletePetID').value = petID;
            document.getElementById('deleteModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const approveModal = document.getElementById('approveModal');
            const rejectModal = document.getElementById('rejectModal');
            const deleteModal = document.getElementById('deleteModal');

            if (event.target == approveModal) closeApproveModal();
            if (event.target == rejectModal) closeRejectModal();
            if (event.target == deleteModal) closeDeleteModal();
        }

        // Close modals with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeApproveModal();
                closeRejectModal();
                closeDeleteModal();
            }
        });

        // Auto-hide success message after 5 seconds
        setTimeout(() => {
            const successMsg = document.querySelector('.success-message');
            if (successMsg) {
                successMsg.style.transition = 'opacity 0.5s ease';
                successMsg.style.opacity = '0';
                setTimeout(() => {
                    successMsg.style.display = 'none';
                }, 500);
            }
        }, 5000);

        // Prevent form submission on Enter key in textarea
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' && event.target.tagName === 'TEXTAREA') {
                if (!event.shiftKey) {
                    event.preventDefault();
                }
            }
        });
    </script>
</body>

</html>

<?php
$conn->close();
?>