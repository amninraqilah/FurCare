<?php
// forgot-password.php dengan debug
session_start();
include 'connect.php';
require_once 'mail_config.php';

// TAMBAH INI DI AWAL - Assign session ke variable ***
$message = isset($_SESSION['message']) ? $_SESSION['message'] : '';
$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
$debug_link = isset($_SESSION['debug_link']) ? $_SESSION['debug_link'] : '';

// Clear session messages setelah assign
unset($_SESSION['message'], $_SESSION['error'], $_SESSION['debug_link']);

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['request_token'])) {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    
    echo "<!-- DEBUG: Email searched: $email -->";
    
    $result = mysqli_query($conn, "SELECT * FROM user WHERE Email='$email'");
    
    if (mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        echo "<!-- DEBUG: User found - " . $user['Name'] . " -->";
        
        // Generate secure token
        $token = bin2hex(random_bytes(32)); // 64-character token
        $expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        
        echo "<!-- DEBUG: Token: $token -->";
        echo "<!-- DEBUG: Expires: $expires -->";
        
        // Save to database
        $update_query = "UPDATE user SET reset_token='$token', reset_expires='$expires' WHERE Email='$email'";
        echo "<!-- DEBUG: Query: $update_query -->";
        
        if(mysqli_query($conn, $update_query)) {
            echo "<!-- DEBUG: Token saved to DB -->";
            
            // Check if token actually saved
            $check = mysqli_query($conn, "SELECT reset_token FROM user WHERE Email='$email'");
            $row = mysqli_fetch_assoc($check);
            echo "<!-- DEBUG: Token in DB: " . ($row['reset_token'] ?? 'NULL') . " -->";
            echo "<!-- DEBUG: Token length in DB: " . strlen($row['reset_token'] ?? '') . " -->";
            
            // SEND EMAIL
            if(sendResetEmail($email, $token)) {
                $_SESSION['message'] = "Password reset link has been sent to your email!";
                
                // FOR DEBUGGING - Show the link
                $_SESSION['debug_link'] = "http://localhost:8080/furcare/reset-password.php?token=" . $token;
            } else {
                $_SESSION['error'] = "Failed to send email. Please try again later.";
            }
        } else {
            $_SESSION['error'] = "Database error: " . mysqli_error($conn);
        }
    } else {
        // Security: Don't reveal if email exists or not
        $_SESSION['message'] = "If an account exists with that email, you will receive a reset link shortly.";
    }
    
    header("Location: forgot-password.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Forgot Password - FurCare</title>
  <link rel="stylesheet" href="css/login.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
  <style>
    .debug-info {
        background: #e3f2fd;
        border-left: 4px solid #2196F3;
        padding: 10px;
        margin: 10px 0;
        font-family: monospace;
        font-size: 12px;
    }
  </style>
</head>
<body>

<div class="login-container">
  <h2>Forgot Password</h2>
  
  <?php if($message): ?>
    <div style="background:#d4edda; color:#155724; padding:15px; border-radius:5px; margin-bottom:20px;">
      <?php echo $message; ?>
    </div>
  <?php endif; ?>
  
  <?php if($error): ?>
    <div style="background:#f8d7da; color:#721c24; padding:10px; border-radius:5px; margin-bottom:20px;">
      <?php echo $error; ?>
    </div>
  <?php endif; ?>
  
  <p>Enter your email to receive a password reset link:</p>
  
  <form method="POST" class="login-form">
    <input type="email" name="email" required placeholder="your@email.com">
    <input type="hidden" name="request_token" value="1">
    <button type="submit" class="btn full">Send Reset Link</button>
  </form>
  
  <p class="small-text" style="margin-top: 20px;">
    <a href="login.php">Back to Login</a>
  </p>
</div>

</body>
</html>