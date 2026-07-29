<?php
// reset-password.php
session_start();
include 'connect.php';

// Check database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$message = '';
$error = '';

// Step 2: Verify Token & Reset Password
if (isset($_GET['token'])) {
    $token = mysqli_real_escape_string($conn, $_GET['token']);
    
    echo "<!-- DEBUG: Token received: $token -->";
    
    // Cari token dalam database
    $result = mysqli_query($conn, "SELECT * FROM user WHERE reset_token='$token'");
    
    echo "<!-- DEBUG: Query executed -->";
    
    if (mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        echo "<!-- DEBUG: User found: " . $user['Email'] . " -->";
        
        $expires = strtotime($user['reset_expires']);
        $now = time();
        
        echo "<!-- DEBUG: Expires: " . $user['reset_expires'] . " ($expires) -->";
        echo "<!-- DEBUG: Now: $now -->";
        
        // Check if token expired
        if ($now > $expires) {
            $error = "Reset link has expired. Please request a new one.";
            echo "<!-- DEBUG: Token expired -->";
        } else {
            // Token valid, show reset form
            echo "<!-- DEBUG: Token valid -->";
            
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reset_password'])) {
                $new_password = $_POST['password'];
                $confirm_password = $_POST['confirm_password'];
                
                if ($new_password === $confirm_password) {
                    // Hash new password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    // Update password and clear reset token
                    $update_query = "UPDATE user 
                                   SET Password='$hashed_password', 
                                       reset_token=NULL, 
                                       reset_expires=NULL 
                                   WHERE reset_token='$token'";
                    
                    echo "<!-- DEBUG: Update query: $update_query -->";
                    
                    if(mysqli_query($conn, $update_query)) {
                        $_SESSION['message'] = "Password updated successfully! You can now login.";
                        header("Location: login.php");
                        exit();
                    } else {
                        $error = "❌ Database error: " . mysqli_error($conn);
                    }
                } else {
                    $error = "❌ Passwords do not match!";
                }
            }
            
            // Show reset form
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>Reset Password - FurCare</title>
                <link rel="stylesheet" href="css/login.css">
            </head>
            <body>
                <div class="login-container">
                    <h2>Reset Your Password</h2>
                    
                    <?php if($error): ?>
                        <div style="background:#f8d7da; color:#721c24; padding:10px; border-radius:5px; margin-bottom:20px;">
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" class="login-form">
                        <input type="password" name="password" required placeholder="New Password" minlength="6">
                        <input type="password" name="confirm_password" required placeholder="Confirm New Password" minlength="6">
                        <input type="hidden" name="reset_password" value="1">
                        <button type="submit" class="btn full">Reset Password</button>
                    </form>
                </div>
            </body>
            </html>
            <?php
            exit();
        }
    } else {
        $error = "Invalid reset link. Please request a new one.";
        echo "<!-- DEBUG: Token not found in DB -->";
        
        // Debug: Show all tokens in DB
        $all_tokens = mysqli_query($conn, "SELECT Email, reset_token FROM user WHERE reset_token IS NOT NULL");
        echo "<!-- DEBUG: Tokens in DB: ";
        while($row = mysqli_fetch_assoc($all_tokens)) {
            echo $row['Email'] . ": " . $row['reset_token'] . " | ";
        }
        echo " -->";
    }
} else {
    $error = "No reset token provided.";
}

// If error or no token
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password - FurCare</title>
    <link rel="stylesheet" href="css/login.css">
</head>
<body>
    <div class="login-container">
        <h2>Reset Password</h2>
        
        <?php if($error): ?>
            <div style="background:#f8d7da; color:#721c24; padding:15px; border-radius:5px; margin-bottom:20px;">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <p><a href="forgot-password.php">Back to Forgot Password</a></p>
    </div>
</body>
</html>