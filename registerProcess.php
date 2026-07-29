<?php
session_start();
include 'connect.php';

// Get data from form
$name = $_POST['name'];
$email = $_POST['email'];
$password = $_POST['password'];

// Validate Email Format (Basic)
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "<script>alert('Invalid email format!'); window.history.back();</script>";
    exit;
}

// Check if email already exists
$checkEmail = $conn->prepare("SELECT * FROM user WHERE email = ?");
$checkEmail->bind_param("s", $email);
$checkEmail->execute();
$result = $checkEmail->get_result();

if ($result->num_rows > 0) {
    echo "<script>alert('Email already registered!'); window.history.back();</script>";
    exit;
}

// Hash the password
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Insert user into DB
$stmt = $conn->prepare("INSERT INTO user (name, email, password) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $name, $email, $hashedPassword);

if ($stmt->execute()) {
    // Set success message in session
    $_SESSION['success_message'] = "Registration successful! Please login with your credentials.";
    
    // Redirect to login page
    header("Location: login.php");
    exit;
} else {
    echo "<script>alert('Registration failed. Please try again.'); window.history.back();</script>";
}

$stmt->close();
$conn->close();
?>