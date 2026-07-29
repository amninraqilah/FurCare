<?php
// mail_config.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; // Pastikan path betul

function sendResetEmail($email, $token) {
    $mail = new PHPMailer(true);
    
    try {
        // Gmail SMTP Configuration
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'furcare.helpdesk@gmail.com'; // Your email
        $mail->Password = 'wjqi uwgu jymb cmix'; // Your App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Sender
        $mail->setFrom('furcare.helpdesk@gmail.com', 'FurCare');
        // Recipient
        $mail->addAddress($email);
        
        // Email content
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request - FurCare';
        
        // Create reset link - PASTIKAN URL BETUL!
        $resetLink = "http://localhost:8080/furcare/reset-password.php?token=" . $token;
        // Jika guna port 8080: "http://localhost:8080/furcare/reset-password.php?token=" . $token
        
        $mail->Body = "
            <h2>Password Reset Request</h2>
            <p>You requested to reset your password. Click the link below:</p>
            <p><a href='$resetLink' style='background:#4CAF50; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Reset Password</a></p>
            <p>Or copy this link:<br>$resetLink</p>
            <p><strong>This link expires in 30 minutes.</strong></p>
            <hr>
            <p>If you didn't request this, please ignore this email.</p>
        ";
        
        $mail->AltBody = "Password Reset Link: $resetLink\nThis link expires in 30 minutes.";
        
        return $mail->send();
        
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}
?>