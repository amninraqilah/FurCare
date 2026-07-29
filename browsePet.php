<?php
session_start();
include 'connect.php';

// Time ago function
function timeAgo($datetime)
{
  $time = strtotime($datetime);
  $now = time();
  $diff = $now - $time;

  if ($diff < 60) return 'Just now';
  if ($diff < 3600) return floor($diff / 60) . ' mins ago';
  if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
  if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
  return date('M j, Y', $time);
}

// Function to format age from float to readable format
function formatAge($ageYears)
{
  if ($ageYears == 0 || $ageYears == '' || is_null($ageYears)) {
    return '';
  }

  $totalMonths = round($ageYears * 12);
  $years = floor($totalMonths / 12);
  $months = $totalMonths % 12;

  if ($years == 0 && $months == 0) {
    return 'Less than 1 month';
  } elseif ($years == 0) {
    return $months . ' month' . ($months > 1 ? 's' : '');
  } elseif ($months == 0) {
    return $years . ' year' . ($years > 1 ? 's' : '');
  } else {
    return $years . ' year' . ($years > 1 ? 's' : '') .
      ' ' . $months . ' month' . ($months > 1 ? 's' : '');
  }
}

// UBAH QUERY UNTUK AMBIL YANG AVAILABLE DAN APPROVED SAHAJA
$sql = "SELECT p.*, u.Name as OwnerName, u.ProfilePicture, u.UserID as OwnerID 
        FROM pet p 
        LEFT JOIN user u ON p.OwnerID = u.UserID 
        WHERE p.Status = 'Available' 
        AND p.ApprovalStatus = 'Approved'
        ORDER BY p.PostDate DESC";
$result = $conn->query($sql);

// UPDATE FILTER QUERIES JUGA
$type_result = $conn->query("SELECT DISTINCT Type FROM pet WHERE Status = 'Available' AND ApprovalStatus = 'Approved' ORDER BY Type");
$post_type_result = $conn->query("SELECT DISTINCT PostType FROM pet WHERE Status = 'Available' AND ApprovalStatus = 'Approved' ORDER BY PostType");
$state_result = $conn->query("SELECT DISTINCT State FROM pet WHERE Status = 'Available' AND ApprovalStatus = 'Approved' AND State IS NOT NULL ORDER BY State");
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Browse Pets - FurCare</title>
  <link rel="stylesheet" href="css/browsePet.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
</head>

<body>

  <div class="container">
    <h1 class="title">Browse Pets for Adoption & Pet Sitting</h1>
    <p class="subtitle">Discover lovely animals looking for a forever home or temporary care.</p>

    <!-- FILTER SECTION -->
    <div class="filters">
      <div class="search-container">
        <input id="searchBox" type="text" placeholder="Search by name, breed, location..." onkeyup="filterPets()">
        <button class="clear-btn" onclick="clearFilters()">Clear Filters</button>
      </div>

      <div class="filter-group">
        <select id="typeSelect" onchange="filterPets()">
          <option value="all">All Types</option>
          <?php
          $type_result->data_seek(0);
          while ($type_row = mysqli_fetch_assoc($type_result)): ?>
            <option value="<?= htmlspecialchars($type_row['Type']) ?>">
              <?= htmlspecialchars($type_row['Type']) ?>
            </option>
          <?php endwhile; ?>
        </select>

        <select id="postTypeSelect" onchange="filterPets()">
          <option value="all">All Post Types</option>
          <?php
          $post_type_result->data_seek(0);
          while ($post_type_row = mysqli_fetch_assoc($post_type_result)): ?>
            <option value="<?= htmlspecialchars($post_type_row['PostType']) ?>">
              <?= htmlspecialchars($post_type_row['PostType']) ?>
            </option>
          <?php endwhile; ?>
        </select>

        <select id="stateSelect" onchange="filterPets()">
          <option value="all">All States</option>
          <?php
          $state_result->data_seek(0);
          while ($state_row = mysqli_fetch_assoc($state_result)): ?>
            <option value="<?= htmlspecialchars($state_row['State']) ?>">
              <?= htmlspecialchars($state_row['State']) ?>
            </option>
          <?php endwhile; ?>
        </select>

        <select id="sortSelect" onchange="filterPets()">
          <option value="newest">Newest First</option>
          <option value="oldest">Oldest First</option>
          <option value="name_asc">Name A-Z</option>
          <option value="name_desc">Name Z-A</option>
        </select>
      </div>
    </div>

    <div class="pet-grid">
      <?php if ($result && $result->num_rows > 0): ?>
        <!-- ADD NO-RESULTS MESSAGE -->
        <div class="no-results" style="display: none;">
          No pets found matching your filters.
        </div>

        <?php while ($row = $result->fetch_assoc()): ?>
          <?php
          $isNew = (time() - strtotime($row['PostDate'])) < (24 * 3600);
          ?>

          <div class="pet-card" data-type="<?= htmlspecialchars(strtolower($row['Type'])) ?>"
            data-posttype="<?= htmlspecialchars(strtolower($row['PostType'])) ?>"
            data-state="<?= htmlspecialchars(strtolower($row['State'])) ?>"
            data-name="<?= htmlspecialchars(strtolower($row['Name'])) ?>"
            data-breed="<?= htmlspecialchars(strtolower($row['Breed'])) ?>"
            data-location="<?= htmlspecialchars(strtolower($row['District'] . ' ' . $row['State'])) ?>"
            data-date="<?= htmlspecialchars($row['PostDate']) ?>">

            <!-- Dalam bahagian post-meta -->
            <div class="post-meta">
              <!-- Profile Picture yang boleh click -->
              <a href="profile.php?user_id=<?= $row['OwnerID'] ?>" class="owner-avatar-link">
                <img src="<?php echo !empty($row['ProfilePicture']) ? htmlspecialchars($row['ProfilePicture']) : 'uploads/profile_icon.png'; ?>"
                  alt="<?php echo htmlspecialchars($row['OwnerName']); ?>"
                  class="owner-avatar">
              </a>

              <div class="owner-info">
                <!-- Nama yang boleh click -->
                <a href="profile.php?user_id=<?= $row['OwnerID'] ?>" class="owner-name" style="text-decoration: none;">
                  <?php echo htmlspecialchars($row['OwnerName'] ?? 'Unknown User'); ?>
                </a>
                <span class="post-date">
                  <?php echo date('M j, Y', strtotime($row['PostDate'])); ?>
                  <span class="time-ago">• <?php echo timeAgo($row['PostDate']); ?></span>
                  <?php if ($isNew): ?>
                    <span class="new-badge">NEW</span>
                  <?php endif; ?>
                </span>
              </div>
            </div>

            <!-- Pet Image -->
            <img src="<?php echo htmlspecialchars($row['Image']); ?>"
              alt="<?php echo htmlspecialchars($row['Name']); ?>"
              class="pet-image">

            <!-- Pet Name -->
            <h3 class="pet-name"><?php echo htmlspecialchars($row['Name']); ?></h3>

            <!-- Post Type Badge -->
            <div class="post-type-badge <?php echo strtolower(str_replace(' ', '-', $row['PostType'])); ?>">
              <?php echo htmlspecialchars($row['PostType']); ?>
              <?php if ($row['PostType'] === 'Pet Sit' && !empty($row['Price'])): ?>
                • RM<?php echo number_format($row['Price'], 2); ?> / day
              <?php endif; ?>
            </div>

            <!-- Pet Info -->
            <p class="pet-info">
              <?php
              echo htmlspecialchars($row['Type']);
              if (!empty($row['Breed'])) {
                echo ' • ' . htmlspecialchars($row['Breed']);
              }
              if (!empty($row['Age'])) {
                echo ' • ' . formatAge($row['Age']);
              }
              if (!empty($row['Gender'])) {
                echo ' • ' . htmlspecialchars($row['Gender']);
              }
              ?>
            </p>

            <!-- Location -->
            <p class="pet-location">📍 <?php echo htmlspecialchars($row['District'] . ', ' . $row['State']); ?></p>

            <!-- Pet Description -->
            <p class="pet-desc">
              <?php echo !empty(trim($row['Description'] ?? ''))
                ? nl2br(htmlspecialchars($row['Description']))
                : '<span style="color: #999; font-style: italic;">No description available</span>'; ?>
            </p>

            <!-- Action Buttons - Ikut PostType -->
            <div class="btn-group">
              <?php if ($row['PostType'] === 'Adopt'): ?>
                <!-- CHECK SESSION FOR LOGIN -->
                <a href="<?php echo isset($_SESSION['user_id']) ? 'adopt.php?pet_id=' . $row['PetID'] : 'login.php'; ?>" class="btn adopt">
                  Adopt Me
                </a>
              <?php elseif ($row['PostType'] === 'Pet Sit'): ?>
                <!-- CHECK SESSION FOR LOGIN -->
                <a href="<?php echo isset($_SESSION['user_id']) ? 'petSit.php?pet_id=' . $row['PetID'] : 'login.php'; ?>" class="btn sit">
                  Pet Sit
                </a>
              <?php endif; ?>
              <!-- CHECK SESSION FOR LOGIN -->
              <a href="<?php echo isset($_SESSION['user_id']) ? 'petDetails.php?pet_id=' . $row['PetID'] : 'login.php'; ?>" class="btn details">
                View Details
              </a>
            </div>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <!-- This shows when NO PETS AT ALL in database -->
        <div class="no-pets">
          No approved pets available at the moment.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script>
    function filterPets() {
      const searchTerm = document.getElementById('searchBox').value.toLowerCase();
      const typeFilter = document.getElementById('typeSelect').value.toLowerCase();
      const postTypeFilter = document.getElementById('postTypeSelect').value.toLowerCase();
      const stateFilter = document.getElementById('stateSelect').value.toLowerCase();
      const sortFilter = document.getElementById('sortSelect').value;

      const petCards = document.querySelectorAll('.pet-card');
      let visibleCount = 0;

      petCards.forEach(card => {
        const petType = card.getAttribute('data-type');
        const petPostType = card.getAttribute('data-posttype');
        const petState = card.getAttribute('data-state');
        const petName = card.getAttribute('data-name');
        const petBreed = card.getAttribute('data-breed');
        const petLocation = card.getAttribute('data-location');
        const petDate = card.getAttribute('data-date');

        const matchesSearch = !searchTerm ||
          petName.includes(searchTerm) ||
          petBreed.includes(searchTerm) ||
          petLocation.includes(searchTerm);

        const matchesType = typeFilter === 'all' || petType === typeFilter;
        const matchesPostType = postTypeFilter === 'all' || petPostType === postTypeFilter;
        const matchesState = stateFilter === 'all' || petState === stateFilter;

        if (matchesSearch && matchesType && matchesPostType && matchesState) {
          card.style.display = 'block';
          visibleCount++;
        } else {
          card.style.display = 'none';
        }
      });

      // Show/hide no-results message
      const noResults = document.querySelector('.no-results');
      const noPets = document.querySelector('.no-pets');

      if (noResults) {
        noResults.style.display = visibleCount === 0 ? 'block' : 'none';
      }

      // Juga hide no-pets message jika ada results
      if (noPets && visibleCount > 0) {
        noPets.style.display = 'none';
      }

      // PASTI sort function dipanggil di sini
      sortPetCards(sortFilter);
    }

    function sortPetCards(sortBy) {
      const petGrid = document.querySelector('.pet-grid');
      const cards = Array.from(petGrid.querySelectorAll('.pet-card'));

      // FIX: Filter hanya cards yang visible
      const visibleCards = cards.filter(card => {
        const isVisible = card.style.display !== 'none';
        return isVisible;
      });

      console.log(`Sorting ${visibleCards.length} visible cards by ${sortBy}`);

      if (visibleCards.length === 0) {
        console.log('No visible cards to sort');
        return;
      }

      visibleCards.sort((a, b) => {
        const aDate = new Date(a.getAttribute('data-date'));
        const bDate = new Date(b.getAttribute('data-date'));
        const aName = a.getAttribute('data-name').toLowerCase();
        const bName = b.getAttribute('data-name').toLowerCase();

        switch (sortBy) {
          case 'newest':
            return bDate - aDate;
          case 'oldest':
            return aDate - bDate;
          case 'name_asc':
            return aName.localeCompare(bName);
          case 'name_desc':
            return bName.localeCompare(aName);
          default:
            return 0;
        }
      });

      // FIX: Jangan remove semua cards, hanya reorder visible ones
      // Simpan current order dulu
      const allCards = Array.from(petGrid.querySelectorAll('.pet-card'));

      // Create array dengan visible cards di depan mengikut sort order
      const hiddenCards = allCards.filter(card => card.style.display === 'none');
      const finalOrder = [...visibleCards, ...hiddenCards];

      // Clear dan re-add dalam order baru
      petGrid.innerHTML = '';
      finalOrder.forEach(card => petGrid.appendChild(card));

      console.log('Sorting completed - visible cards moved to front');
    }

    function clearFilters() {
      console.log('Clearing filters...');

      // Reset inputs
      document.getElementById('searchBox').value = '';
      document.getElementById('typeSelect').value = 'all';
      document.getElementById('postTypeSelect').value = 'all';
      document.getElementById('stateSelect').value = 'all';
      document.getElementById('sortSelect').value = 'newest';

      // Force show ALL cards
      const petCards = document.querySelectorAll('.pet-card');
      console.log('Total cards found in DOM:', petCards.length);

      petCards.forEach((card, index) => {
        card.style.display = 'block';
        console.log(`Showing card ${index}:`, card.getAttribute('data-name'));
      });

      // Hide no-results message
      const noResults = document.querySelector('.no-results');
      if (noResults) noResults.style.display = 'none';

      sortPetCards('newest');
    }

    // Debug on page load
    document.addEventListener('DOMContentLoaded', function() {
      const petCards = document.querySelectorAll('.pet-card');
      console.log('Page loaded - Total pet cards:', petCards.length);
      petCards.forEach((card, index) => {
        console.log(`Card ${index}:`, {
          name: card.getAttribute('data-name'),
          type: card.getAttribute('data-type'),
          state: card.getAttribute('data-state')
        });
      });
      filterPets();
    });
    document.addEventListener('DOMContentLoaded', filterPets);
  </script>
</body>

</html>

<?php
$conn->close();
?>