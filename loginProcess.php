<?php
session_start();
include 'connect.php';

$email = $_POST['email'];
$password = $_POST['password'];

$sql = "SELECT * FROM user WHERE Email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();

    if (password_verify($password, $user['Password'])) {
        $_SESSION['user_id'] = $user['UserID'];
        $_SESSION['user_name'] = $user['Name'];
        $_SESSION['role'] = $user['Role']; // Simpan role

        if ($user['Role'] === 'admin') {
            header("Location: adminDashboard.php"); // Redirect admin
        } else {
            header("Location: userDashboard.php"); // Redirect user biasa
        }
        exit;
    } else {
        echo "<script>alert('Invalid password!'); window.history.back();</script>";
    }
} else {
    echo "<script>alert('Email not registered!'); window.history.back();</script>";
}

$stmt->close();
$conn->close();
?>
