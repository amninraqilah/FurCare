<?php
// set-password.php
session_start();
include 'connect.php';

if(!isset($_SESSION['reset_user_id']) || !isset($_POST['token'])) {
    header("Location: forgot-password.php");
    exit();
}

$user_id = $_SESSION['reset_user_id'];
$token = mysqli_real_escape_string($conn, $_POST['token']);
$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];
    
    // Verify token one more time before resetting
    $current_time = date('Y-m-d H:i:s');
    $check_token = mysqli_query($conn, 
        "SELECT UserID FROM user 
         WHERE UserID = '$user_id' 
         AND reset_token = '$token' 
         AND reset_expires > '$current_time'"
    );
    
    if(mysqli_num_rows($check_token) == 0) {
        $error = "Token validation failed. Please request a new reset link.";
    } elseif($password !== $confirm) {
        $error = "Passwords do not match!";
    } elseif(strlen($password) < 6) {
        $error = "Password must be at least 6 characters!";
    } else {
        // Hash new password
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        
        // Update password and CLEAR reset token (make it one-time use)
        $sql = "UPDATE user 
                SET Password = '$hashed', 
                    reset_token = NULL,
                    reset_expires = NULL 
                WHERE UserID = '$user_id'";
        
        if(mysqli_query($conn, $sql)) {
            // Clear all sessions
            session_unset();
            session_destroy();
            
            $success = "Password reset successfully! Redirecting to login...";
            header("refresh:3;url=login.php");
        } else {
            $error = "Failed to update password. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Set New Password - FurCare</title>
  <link rel="stylesheet" href="css/login.css">
</head>
<body>

<div class="login-container">
  <h2>Set New Password</h2>
  
  <?php if($error): ?>
    <div style="background:#f8d7da; color:#721c24; padding:10px; border-radius:5px; margin-bottom:20px;">
      <?php echo $error; ?>
    </div>
  <?php endif; ?>
  
  <?php if($success): ?>
    <div style="background:#d4edda; color:#155724; padding:15px; border-radius:5px; margin-bottom:20px;">
      <?php echo $success; ?>
    </div>
  <?php else: ?>
    <form method="POST" class="login-form">
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
      
      <label>New Password</label>
      <input type="password" name="password" required minlength="6">
      
      <label>Confirm Password</label>
      <input type="password" name="confirm_password" required minlength="6">
      
      <button type="submit" class="btn full">Reset Password</button>
    </form>
  <?php endif; ?>
</div>

</body>
</html>