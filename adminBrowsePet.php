<?php
session_start(); 
include 'connect.php';

$userID = $_SESSION['user_id'];

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

// Hanya pet yang APPROVED
$sql = "SELECT p.*, u.Name as OwnerName, u.ProfilePicture, u.UserID as OwnerID 
        FROM pet p 
        LEFT JOIN user u ON p.OwnerID = u.UserID 
        WHERE p.Status = 'Available' 
        AND p.ApprovalStatus = 'Approved'  -- UBAH KE HANYA 'Approved'
        ORDER BY p.PostDate DESC";
$result = $conn->query($sql);

// Semua query filter hanya untuk yang Approved
$type_result = $conn->query("SELECT DISTINCT Type FROM pet WHERE Status = 'Available' AND ApprovalStatus = 'Approved' ORDER BY Type");
$post_type_result = $conn->query("SELECT DISTINCT PostType FROM pet WHERE Status = 'Available' AND ApprovalStatus = 'Approved' ORDER BY PostType");
$state_result = $conn->query("SELECT DISTINCT State FROM pet WHERE Status = 'Available' AND State IS NOT NULL AND ApprovalStatus = 'Approved' ORDER BY State");

// Fetch admin data untuk profile picture
$user_stmt = $conn->prepare("SELECT * FROM user WHERE UserID = ?");
$user_stmt->bind_param("i", $userID);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin Manage Pets - FurCare</title>
    <link rel="stylesheet" href="css/adminDashboard.css">
    <link rel="stylesheet" href="css/adminManagePets.css">
    <link rel="stylesheet" href="css/browsePet.css">
    <style>
        .approval-status {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .status-approved {
            background: #48bb78;
            color: white;
        }

        .filter-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <h2 class="logo">FurCare</h2>
        <a href="adminDashboard.php">🗂️ Main Menu</a>
        <a href="index.php">🏠 Home</a>
        <a href="adminBrowsePet.php" class="active">🔍 Browse Pets</a>
        <a href="adminManagePets.php">🐾 Manage Pets</a>
        <a href="manageUsers.php">👥 Manage Users</a>
        <a href="adminAdoptionRequests.php">📋 Adoption Request</a>
        <a href="adminPetSitRequests.php">🏠 Pet Sit Request</a>
        <a href="reports.php">📑 Reports</a>
        <a href="adminSetting.php">⚙️ Settings</a>
        <a href="logout.php" class="logout">🚪 Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="navbar">
            <h1>Browse Pet - Admin View</h1>
            <img src="<?php echo !empty($user['ProfilePicture']) ? $user['ProfilePicture'] : 'uploads/profile_icon.png'; ?>"
                alt="Profile"
                class="profile-icon">
        </div>
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

                            <!-- Post Meta - Owner Info & Date -->
                            <div class="post-meta">
                                <a href="adminViewUsers.php?user_id=<?= $row['OwnerID'] ?>" class="owner-avatar-link">
                                    <img src="<?php echo !empty($row['ProfilePicture']) ? htmlspecialchars($row['ProfilePicture']) : 'uploads/profile_icon.png'; ?>"
                                        alt="<?php echo htmlspecialchars($row['OwnerName']); ?>"
                                        class="owner-avatar">
                                </a>
                                <div class="owner-info">
                                    <a href="adminViewUsers.php?user_id=<?= $row['OwnerID'] ?>" class="owner-name-link" style="text-decoration: none;">
                                        <span class="owner-name">
                                            <?php echo htmlspecialchars($row['OwnerName'] ?? 'Unknown User'); ?>
                                            <?php
                                            $isAdmin = ($row['OwnerID'] == 1 || $row['OwnerName'] == 'Admin');
                                            if ($isAdmin): ?>
                                                <span class="admin-badge">ADMIN</span>
                                            <?php endif; ?>
                                        </span>
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

                            <!-- Approval Status (hanya approved) -->
                            <div class="approval-status status-approved">
                                Approved
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

                            <p class="pet-desc">
                                <?php echo !empty(trim($row['Description'] ?? ''))
                                    ? nl2br(htmlspecialchars($row['Description']))
                                    : '<span style="color: #999; font-style: italic;">No description available</span>'; ?>
                            </p>
                            <!-- Action Buttons - Untuk Admin -->
                            <div class="btn-group">
                                <a href="editPet.php?id=<?php echo $row['PetID']; ?>" class="btn edit">
                                    Edit Pet
                                </a>
                                <a href="adminPetDetails.php?pet_id=<?php echo $row['PetID']; ?>" class="btn details">
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
                if (noResults) {
                    noResults.style.display = visibleCount === 0 ? 'block' : 'none';
                }

                sortPetCards(sortFilter);
            }

            function sortPetCards(sortBy) {
                const petGrid = document.querySelector('.pet-grid');
                const cards = Array.from(petGrid.querySelectorAll('.pet-card'));

                // Filter hanya cards yang visible
                const visibleCards = cards.filter(card => card.style.display !== 'none');

                console.log('Sorting', visibleCards.length, 'visible cards by', sortBy);

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

                // Remove all cards from grid
                cards.forEach(card => card.remove());

                // Add back in sorted order
                visibleCards.forEach(card => petGrid.appendChild(card));

                console.log('Sorting completed');
            }

            function clearFilters() {
                document.getElementById('searchBox').value = '';
                document.getElementById('typeSelect').value = 'all';
                document.getElementById('postTypeSelect').value = 'all';
                document.getElementById('stateSelect').value = 'all';
                document.getElementById('sortSelect').value = 'newest';
                filterPets();
            }

            document.addEventListener('DOMContentLoaded', filterPets);
        </script>
</body>

</html>

<?php
$conn->close();
?>