<?php
session_start();
// Simulasi login untuk testing (buang ini nanti bila dah sambung login betul)
if (!isset($_SESSION['role'])) {
  $_SESSION['role'] = ''; // kosongkan
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>FurCare - Pet Adoption & Temporary Care</title>
  <link rel="stylesheet" href="css/index.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
</head>
<body>

  <nav class="navbar">
    <div class="nav-left">
      <h1 class="logo">FurCare</h1>
    </div>
    <div class="nav-right">
      <a href="mailto:furcare.helpdesk@gmail.com" class="nav-link">Contact Us</a>
      <a href="petMap.php" class="nav-link">Map</a>
      <?php if ($_SESSION['role'] == 'user') { ?>
         <a href="userDashboard.php" class="nav-link">Browse Pets</a>
        <a href="myPets.php" class="nav-link">My Pets</a>
        <a href="addPetUser.php" class="nav-link">Post a Pet</a>
        <a href="logout.php" class="nav-link">Logout</a>
      <?php } elseif ($_SESSION['role'] == 'admin') { ?>
        <a href="adminDashboard.php" class="nav-link">Main Menu</a>
        <a href="logout.php" class="nav-link">Logout</a>
      <?php } else { ?>
         <a href="browsePet.php" class="nav-link">Browse Pets</a>
        <a href="login.php" class="nav-link">Login</a>
        <a href="register.php" class="nav-link">Register</a>
      <?php } ?>
    </div>
  </nav>

  <header class="hero">
    <div class="hero-content">
      <h1>🐾 Welcome to FurCare</h1>
      <p>Adopt, Rehome or Temporarily Care for Pets – All in One Place.</p>
    </div>
  </header>

  <section class="about">
    <h2>Why FurCare?</h2>
    <p>FurCare is a centralized platform to connect pet lovers. Whether you want to adopt a new friend, rehome your pet, or find someone to take care of them while you're away – FurCare is here to help.</p>
  </section>

  <section class="features">
    <h2>Key Features</h2>
    <div class="feature-list">
      <div class="feature">
        <h3>🐶 Adopt Pets</h3>
        <p>Choose from a variety of animals needing loving homes.</p>
      </div>
      <div class="feature">
        <h3>🏠 Rehome Pets</h3>
        <p>Let go with love – safely rehome your pets to trusted adopters.</p>
      </div>
      <div class="feature">
        <h3>🕓 Temporary Care</h3>
        <p>Get help to care for your pet while you’re away. Charges apply.</p>
      </div>
    </div>
  </section>

  <footer class="footer">
    <p>&copy; 2025 FurCare. All rights reserved.</p>
  </footer>
</body>
</html>
