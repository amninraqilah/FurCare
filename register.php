<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Register - FurCare</title>
  <link rel="stylesheet" href="css/register.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
</head>
<body>

  <div class="register-container">
    <h2>Create Your FurCare Account</h2>
    <form action="registerProcess.php" method="POST" class="register-form">
      <label for="name">Full Name</label>
      <input type="text" name="name" required placeholder="e.g. Amni Aqilah">

      <label for="email">Email</label>
      <input type="email" name="email" required placeholder="example@email.com">

      <label for="password">Password</label>
      <input type="password" name="password" required minlength="8" placeholder="At least 8 characters">

      <button type="submit" class="btn full">Register</button>

      <p class="small-text">Already have an account? <a href="login.php">Login here</a></p>
    </form>
  </div>

</body>
</html>
