<?php
// petMap.php
// Public Pet Map Page (no login required)
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Pet Map - FurCare</title>
  
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  
  <!-- Leaflet CSS -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster/dist/MarkerCluster.css" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster/dist/MarkerCluster.Default.css" />
  
  <!-- Custom CSS -->
  <link rel="stylesheet" href="css/petMap.css" />
</head>
<body>

  <!-- Header -->
  <header class="map-header">
    <div class="header-content">
      <div class="logo">
        <span class="logo-icon">🐾</span>
        <h1>FurCare Pet Map</h1>
      </div>
      <p class="header-subtitle">Find pets available for adoption and pet sitting services near you</p>
    </div>
  </header>

  <!-- Main Container -->
  <div class="map-container">
    
    <!-- Controls Section -->
    <div class="controls-section">
      
      <!-- Filter Controls -->
      <div class="filter-card">
        <h3>Filter Pets</h3>
        <div class="filter-grid">
          <div class="filter-group">
            <label for="typeSelect">Pet Type</label>
            <select id="typeSelect" class="filter-select">
              <option value="all">All Types</option>
            </select>
          </div>

          <div class="filter-group">
            <label for="postTypeSelect">Post Type</label>
            <select id="postTypeSelect" class="filter-select">
              <option value="all">All Post Types</option>
              <option value="Adoption">Adoption Posts</option>
              <option value="Pet Sitting">Pet Sitting Posts</option>
            </select>
          </div>
          
          <div class="filter-group">
            <label for="statusSelect">Status</label>
            <select id="statusSelect" class="filter-select">
              <option value="all">All Status</option>
              <option value="Available">Available</option>
              <option value="Adopted">Adopted</option>
              <option value="Pet Sit">Pet Sit</option>
            </select>
          </div>
          
          <div class="filter-group">
            <label for="stateSelect">State</label>
            <select id="stateSelect" class="filter-select">
              <option value="all">All States</option>
            </select>
          </div>
          
          <div class="filter-actions">
            <button id="applyBtn" class="btn btn-primary">
              Apply Filters
            </button>
            <button id="resetBtn" class="btn btn-secondary">
              Reset
            </button>
          </div>
        </div>
      </div>

      Stats Overview
      <div class="stats-card">
        <h3>Quick Stats</h3>
        <div class="stats-grid">
          <div class="stat-item">
            <div class="stat-icon">🐕</div>
            <div class="stat-info">
              <span class="stat-value" id="totalCount">0</span>
              <span class="stat-label">Total Pets</span>
            </div>
          </div>
          
          <div class="stat-item">
            <div class="stat-icon">✅</div>
            <div class="stat-info">
              <span class="stat-value" id="availableCount">0</span>
              <span class="stat-label">Available</span>
            </div>
          </div>
          
          <div class="stat-item">
            <div class="stat-icon">🏠</div>
            <div class="stat-info">
              <span class="stat-value" id="adoptedCount">0</span>
              <span class="stat-label">Adopted</span>
            </div>
          </div>
          
          <div class="stat-item">
            <div class="stat-icon">🛋️</div>
            <div class="stat-info">
              <span class="stat-value" id="petSitCount">0</span>
              <span class="stat-label">Pet Sit</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Map Section -->
    <div class="map-section">
      <div class="map-header-bar">
        <h3>Interactive Pet Map</h3>
      </div>
      
      <!-- Map Container -->
      <div id="map"></div>
      
      <!-- Map Legend -->
      <div class="map-legend">
        <div class="legend-title">Map Legend</div>
        <div class="legend-items">
          <div class="legend-item">
            <span class="legend-color available"></span>
            <span>Available for Adoption and Pet Sitting</span>
          </div>
          <div class="legend-item">
            <span class="legend-color adopted"></span>
            <span>Already Adopted</span>
          </div>
          <div class="legend-item">
            <span class="legend-color pet-sit"></span>
            <span>Pet Sit Service</span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <footer class="map-footer">
    <div class="footer-content">
      <p>&copy; 2025 FurCare. All rights reserved.</p>
    </div>
  </footer>

  <!-- Scripts -->
  <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
  <script src="https://unpkg.com/leaflet.markercluster/dist/leaflet.markercluster.js"></script>
  <script src="js/petMap.js"></script>

</body>
</html>