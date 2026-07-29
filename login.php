<?php
session_start();

// Check for success message
if (isset($_SESSION['success_message'])) {
  $success_message = $_SESSION['success_message'];
  unset($_SESSION['success_message']); // Clear message after displaying
}

// Check for error message
if (isset($_SESSION['error_message'])) {
  $error_message = $_SESSION['error_message'];
  unset($_SESSION['error_message']); // Clear message after displaying
}

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
  header("Location: userDashboard.php");
  exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Login - FurCare</title>
  <link rel="stylesheet" href="css/login.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
</head>

<body>
  <div class="login-container">
    <h2>Login to FurCare</h2>
    <!-- Message Display Area -->
    <div class="message-container">
      <?php if (isset($success_message)): ?>
        <div class="success-message">
          <?php echo htmlspecialchars($success_message); ?>
        </div>
      <?php endif; ?>

      <?php if (isset($error_message)): ?>
        <div class="error-message">
          <?php echo htmlspecialchars($error_message); ?>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['error'])): ?>
        <div class="error-message">
          <?php
          $error_types = [
            'invalid' => 'Invalid email or password!',
            'required' => 'Please fill in all fields!',
            'inactive' => 'Account is inactive. Please contact support.',
            'session_expired' => 'Your session has expired. Please login again.'
          ];
          echo htmlspecialchars($error_types[$_GET['error']] ?? 'An error occurred. Please try again.');
          ?>
        </div>
      <?php endif; ?>
    </div>
    <form action="loginProcess.php" method="POST" class="login-form">
      <label for="email">Email</label>
      <input type="email" name="email" required placeholder="example@email.com" value="<?php echo isset($_GET['email']) ? htmlspecialchars($_GET['email']) : ''; ?>">

      <label for="password">Password</label>
      <input type="password" name="password" required placeholder="********">

      <div style="text-align:left; margin:5px 0 15px 0; font-size:0.85em;">
        <a href="forgot-password.php" style="text-decoration:underline;">
          Forgot Password?
        </a>
      </div>

      <button type="submit" class="btn full">Login</button>

      <p class="small-text">Don't have an account? <a href="register.php">Register here</a></p>
    </form>
  </div>

</body>

</html>