// js/petMap.js - COMPLETE FIXED VERSION
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM Content Loaded - Starting initialization...');
    
    // Global variables
    const MAP_CENTER = [4.2105, 101.9758];
    const MAP_ZOOM = 6;
    let map = null;
    let postData = [];
    let currentMarkerCluster = null;

    // ==============================
    // Helper Functions
    // ==============================
    function escapeHtml(s) {
        if (!s) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ==============================
    // NEW: Standardization Functions
    // ==============================
    
    /**
     * Standardize pet type (Cat, Dog, etc.)
     */
    function standardizeType(type) {
        if (!type || typeof type !== 'string') return '';
        return type.charAt(0).toUpperCase() + type.slice(1).toLowerCase();
    }
    
    /**
     * Standardize status (Available, Adopted, etc.)
     */
    function standardizeStatus(status) {
        if (!status || typeof status !== 'string') return '';
        return status.charAt(0).toUpperCase() + status.slice(1).toLowerCase();
    }
    
    /**
     * Standardize state (Kuala Lumpur, Selangor, etc.)
     */
    function standardizeState(state) {
        if (!state || typeof state !== 'string') return '';
        return state.split(' ')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
            .join(' ');
    }

    /**
     * Get unique pet types with standardized case
     */
    function getUniqueTypesStandardized(posts) {
        const typeMap = {};
        
        posts.forEach(post => {
            if (post.Type && post.Type.trim() !== '') {
                const standardized = standardizeType(post.Type);
                if (standardized) {
                    typeMap[standardized] = true;
                }
            }
        });
        
        const uniqueTypes = Object.keys(typeMap);
        return uniqueTypes.sort((a, b) => a.localeCompare(b));
    }

    /**
     * Get unique statuses with standardized case
     */
    function getUniqueStatusesStandardized(posts) {
        const statusMap = {};
        
        posts.forEach(post => {
            if (post.Status && post.Status.trim() !== '') {
                const standardized = standardizeStatus(post.Status);
                if (standardized) {
                    statusMap[standardized] = true;
                }
            }
        });
        
        const uniqueStatuses = Object.keys(statusMap);
        return uniqueStatuses.sort((a, b) => a.localeCompare(b));
    }

    /**
     * Get unique states with standardized case
     */
    function getUniqueStatesStandardized(posts) {
        const stateMap = {};
        
        posts.forEach(post => {
            if (post.State && post.State.trim() !== '') {
                const standardized = standardizeState(post.State);
                if (standardized) {
                    stateMap[standardized] = true;
                }
            }
        });
        
        const uniqueStates = Object.keys(stateMap);
        return uniqueStates.sort((a, b) => a.localeCompare(b));
    }

    // ==============================
    // Initialize Map
    // ==============================
    function initializeMap() {
        console.log('Initializing map...');
        const mapElement = document.getElementById('map');
        
        if (!mapElement) {
            console.error('Map element with id "map" not found!');
            return false;
        }
        
        try {
            // Initialize map
            map = L.map('map', {
                center: MAP_CENTER,
                zoom: MAP_ZOOM
            });
            
            // Add tile layer
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
                maxZoom: 19
            }).addTo(map);
            
            console.log('Map initialized successfully');
            return true;
        } catch (error) {
            console.error('Error initializing map:', error);
            mapElement.innerHTML = `<div style="text-align:center;padding:40px;color:#666">
                <h3>Error Loading Map</h3>
                <p>Please refresh the page or try again later.</p>
                <p>Error details: ${error.message}</p>
            </div>`;
            return false;
        }
    }

    // ==============================
    // Create Colored Icon based on Post Type
    // ==============================
    function createColoredIcon(postType, status) {
        let color = '#6DBE81'; // Default green
        
        if (postType === 'Adoption') {
            if (status === 'Adopted') color = '#e74c3c'; // Red untuk adopted
            else color = '#3B7A57'; // Green untuk adoption posts
        } else if (postType === 'Pet Sitting') {
            color = '#FF6F91'; // Pink untuk pet sitting
        }

        return L.divIcon({
            html: `<span style="display:inline-block;width:16px;height:16px;background:${color};border-radius:50%;box-shadow:0 0 6px rgba(0,0,0,0.2);border:2px solid white;"></span>`,
            className: '',
            iconSize: [20, 20]
        });
    }

    // ==============================
    // Get Status Class for Styling
    // ==============================
    function getStatusClass(postType, status) {
        if (postType === 'Adoption') {
            if (status === 'Adopted') return 'status-adopted';
            return 'status-adoption';
        } else if (postType === 'Pet Sitting') {
            return 'status-pet-sitting';
        }
        return '';
    }

    // ==============================
    // Format Age Function
    // ==============================
    function formatAge(ageString) {
        if (!ageString && ageString !== 0) return 'Age not specified';
        
        try {
            const ageStr = String(ageString).trim();
            let years = 0;
            
            const match = ageStr.match(/(\d+\.?\d*)/);
            if (match) {
                years = parseFloat(match[1]);
            } else if (!isNaN(parseFloat(ageStr))) {
                years = parseFloat(ageStr);
            } else {
                return ageStr;
            }
            
            const totalMonths = Math.round(years * 12);
            
            if (years < 1) {
                const months = Math.round(years * 12);
                return `${months} month${months !== 1 ? 's' : ''}`;
            }
            
            const yearsInt = Math.floor(years);
            const months = Math.round((years - yearsInt) * 12);
            
            let result = '';
            
            if (yearsInt > 0) {
                result += `${yearsInt} year${yearsInt > 1 ? 's' : ''}`;
            }
            
            if (months > 0) {
                if (result.length > 0) result += ' ';
                result += `${months} month${months > 1 ? 's' : ''}`;
            }
            
            if (result === '') {
                return `${totalMonths} month${totalMonths !== 1 ? 's' : ''}`;
            }
            
            return result;
        } catch (error) {
            console.error('Error formatting age:', error, 'Age string:', ageString);
            return ageString;
        }
    }

    // ==============================
    // Fetch Data & Populate Dropdown
    // ==============================
    async function loadPostsAndPopulate() {
        try {
            console.log('Fetching posts data...');
            
            // Show loading overlay
            const mapDiv = document.getElementById('map');
            if (mapDiv) {
                const loadingOverlay = document.createElement('div');
                loadingOverlay.id = 'loading-overlay';
                loadingOverlay.style.cssText = `
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(255, 255, 255, 0.9);
                    display: flex;
                    flex-direction: column;
                    justify-content: center;
                    align-items: center;
                    z-index: 1000;
                `;
                loadingOverlay.innerHTML = `
                    <div style="width:40px;height:40px;border:4px solid #f3f3f3;border-top:4px solid #3B7A57;border-radius:50%;animation:spin 1s linear infinite;"></div>
                    <p style="margin-top:10px;color:#666;">Loading posts...</p>
                `;
                mapDiv.style.position = 'relative';
                mapDiv.appendChild(loadingOverlay);
                
                // Add spinner animation
                const style = document.createElement('style');
                style.textContent = `
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                `;
                document.head.appendChild(style);
            }
            
            const res = await fetch('get_pets.php');
            
            if (!res.ok) {
                throw new Error(`HTTP error! status: ${res.status}`);
            }
            
            const data = await res.json();
            console.log('Data received:', data.length, 'posts');
            
            // Remove loading overlay
            const overlay = document.getElementById('loading-overlay');
            if (overlay) {
                overlay.remove();
            }
            
            if (!Array.isArray(data)) {
                console.error('Expected array but got:', typeof data);
                postData = [];
            } else {
                postData = data;
            }

            // Format age untuk semua posts
            postData.forEach(post => {
                post.FormattedAge = formatAge(post.Age);
                
                // Standardize data for consistency
                if (post.Type) post.Type = standardizeType(post.Type);
                if (post.Status) post.Status = standardizeStatus(post.Status);
                if (post.State) post.State = standardizeState(post.State);
            });

            // Collect unique values dengan standardisasi
            const uniquePetTypes = getUniqueTypesStandardized(postData);
            const uniqueStatuses = getUniqueStatusesStandardized(postData);
            const uniqueStates = getUniqueStatesStandardized(postData);

            console.log('Unique Types:', uniquePetTypes);
            console.log('Unique Statuses:', uniqueStatuses);
            console.log('Unique States:', uniqueStates);

            // Populate dropdowns
            populateSelect('typeSelect', uniquePetTypes, true);
            populateSelect('statusSelect', uniqueStatuses, true);
            populateSelect('stateSelect', uniqueStates, true);

            updateStats(postData);
            renderMarkers(postData);
            
        } catch (error) {
            console.error('Error loading posts:', error);
            
            // Remove loading overlay jika ada
            const overlay = document.getElementById('loading-overlay');
            if (overlay) {
                overlay.remove();
            }
            
            // Show error dalam popup
            if (map) {
                L.popup()
                    .setLatLng(MAP_CENTER)
                    .setContent(`
                        <div style="text-align:center;padding:20px;min-width:200px;">
                            <h3 style="color:#e74c3c;">Error Loading Posts</h3>
                            <p>${escapeHtml(error.message)}</p>
                            <p>Please refresh the page</p>
                        </div>
                    `)
                    .openOn(map);
            }
        }
    }

    // ==============================
    // Populate Select Dropdown
    // ==============================
    function populateSelect(id, items = [], includeAll = false) {
        const sel = document.getElementById(id);
        if (!sel) {
            console.error(`Select element with id "${id}" not found`);
            return;
        }
        
        // Clear existing options
        sel.innerHTML = '';
        
        if (includeAll) {
            const opt = document.createElement('option');
            opt.value = 'all';
            opt.textContent = 'All';
            sel.appendChild(opt);
        }
        
        if (items && items.length > 0) {
            items.forEach(item => {
                if (item) {
                    const opt = document.createElement('option');
                    opt.value = item;
                    opt.textContent = item;
                    sel.appendChild(opt);
                }
            });
        }
    }

    // ==============================
    // Render Markers on Map
    // ==============================
    function renderMarkers(list) {
        console.log('renderMarkers called with', list.length, 'items');
        
        // Clear existing markers
        if (currentMarkerCluster && map.hasLayer(currentMarkerCluster)) {
            console.log('Removing existing marker cluster');
            try {
                currentMarkerCluster.clearLayers();
                map.removeLayer(currentMarkerCluster);
                currentMarkerCluster = null;
            } catch (error) {
                console.warn('Error removing marker cluster:', error);
                currentMarkerCluster = null;
            }
        }
        
        if (!list || list.length === 0) {
            console.log('Empty list, showing message');
            if (map) {
                L.popup()
                    .setLatLng(MAP_CENTER)
                    .setContent(`
                        <div style="text-align:center;padding:20px;min-width:200px;">
                            <h3>No Posts Found</h3>
                            <p>Try adjusting your filters</p>
                        </div>
                    `)
                    .openOn(map);
            }
            return;
        }
        
        console.log('Creating markers for', list.length, 'pets');
        
        // Create new marker cluster
        currentMarkerCluster = L.markerClusterGroup({
            spiderfyOnMaxZoom: false,
            showCoverageOnHover: false,
            zoomToBoundsOnClick: true,
            maxClusterRadius: 50,
            disableClusteringAtZoom: 15,
            chunkedLoading: true,
            chunkInterval: 100
        });
        
        let validMarkers = 0;
        const markerPromises = [];
        
        list.forEach((p, index) => {
            // Validate coordinates
            if (!p.Latitude || !p.Longitude || 
                isNaN(parseFloat(p.Latitude)) || 
                isNaN(parseFloat(p.Longitude))) {
                console.warn(`Invalid coordinates for ${p.Name}: [${p.Latitude}, ${p.Longitude}]`);
                return;
            }
            
            const lat = parseFloat(p.Latitude);
            const lng = parseFloat(p.Longitude);
            
            // Validate range (Malaysia coordinates)
            if (lat < 0.85 || lat > 7.35 || lng < 99.64 || lng > 119.27) {
                console.warn(`Coordinates out of Malaysia range for ${p.Name}: [${lat}, ${lng}]`);
                return;
            }
            
            // Create marker
            markerPromises.push(new Promise(resolve => {
                setTimeout(() => {
                    try {
                        const icon = createColoredIcon(p.PostType, p.Status);
                        const marker = L.marker([lat, lng], { icon });
                        
                        // Gunakan default image jika tidak ada
                        const img = (p.Image && p.Image !== '' && p.Image !== 'null') 
                            ? p.Image 
                            : 'assets/img/no-image.png';
                        
                        const statusClass = getStatusClass(p.PostType, p.Status);
                        
                        // Build popup content
                        let popupContent = `
                            <div style="text-align:center;min-width:220px;max-width:280px;">
                                <div style="margin-bottom:8px;padding:4px 8px;background:#f0f0f0;border-radius:4px;font-size:12px;font-weight:600;display:inline-block;">
                                    ${escapeHtml(p.PostType)}
                                </div>
                                <img src="${escapeHtml(img)}" 
                                     style="width:100%;height:150px;object-fit:cover;border-radius:8px;margin-bottom:10px;" 
                                     alt="${escapeHtml(p.Name)}"
                                     onerror="this.onerror=null; this.src='assets/img/no-image.png'">
                                <h4 style="margin:5px 0;color:#333;">${escapeHtml(p.Name)}</h4>
                                <div style="color:#666;margin-bottom:8px;">
                                    ${escapeHtml(p.Type)} • ${escapeHtml(p.Breed || 'Mixed')}
                                </div>
                                <div style="margin-bottom:5px;">
                                    Age: ${escapeHtml(p.FormattedAge)} • ${escapeHtml(p.Gender || 'Unknown')}
                                </div>`;
                        
                        // Add price jika ada
                        if (p.DisplayPrice) {
                            popupContent += `<div style="margin-bottom:5px;color:#2c3e50;font-weight:600;">
                                ${escapeHtml(p.DisplayPrice)}
                            </div>`;
                        }
                        
                        // Add date range untuk pet sitting
                        if (p.DateRange) {
                            popupContent += `<div style="margin-bottom:5px;color:#7f8c8d;">
                                ${escapeHtml(p.DateRange)}
                            </div>`;
                        }
                        
                        // Add status
                        popupContent += `
                            <div style="margin-bottom:10px;">
                                Status: <span class="pet-status ${statusClass}" style="font-weight:600;">
                                    ${escapeHtml(p.Status)}
                                </span>
                            </div>
                            <div style="color:#888;font-size:12px;border-top:1px solid #eee;padding-top:8px;">
                                ${escapeHtml(p.State || '')} • ${escapeHtml(p.District || '')}
                            </div>
                        </div>`;
                        
                        marker.bindPopup(popupContent);
                        currentMarkerCluster.addLayer(marker);
                        validMarkers++;
                        
                    } catch (error) {
                        console.error(`Error creating marker for ${p.Name}:`, error);
                    }
                    resolve();
                }, 0);
            }));
        });
        
        // Tunggu semua markers selesai dibuat
        Promise.all(markerPromises).then(() => {
            console.log(`Successfully created ${validMarkers} markers`);
            
            if (validMarkers > 0 && currentMarkerCluster && map) {
                try {
                    map.addLayer(currentMarkerCluster);
                    
                    // Fit bounds to show all markers
                    const bounds = currentMarkerCluster.getBounds();
                    if (bounds.isValid()) {
                        map.fitBounds(bounds, { 
                            padding: [50, 50], 
                            maxZoom: 12,
                            animate: true,
                            duration: 1
                        });
                        console.log('Map fitted to bounds');
                    } else {
                        // Fallback to default view
                        map.setView(MAP_CENTER, MAP_ZOOM);
                        console.log('Using default map view');
                    }
                } catch (error) {
                    console.error('Error adding markers to map:', error);
                }
            } else if (validMarkers === 0 && map) {
                console.log('No valid markers to display');
                L.popup()
                    .setLatLng(MAP_CENTER)
                    .setContent(`
                        <div style="text-align:center;padding:20px;min-width:200px;">
                            <h3>No Valid Markers</h3>
                            <p>All pets have invalid coordinates</p>
                        </div>
                    `)
                    .openOn(map);
            }
        });
    }

    // ==============================
    // Filter Function
    // ==============================
    let isFiltering = false;
    let filterTimeout = null;

    async function applyFilters() {
        // Debounce untuk prevent multiple rapid calls
        if (isFiltering) {
            console.log('Filtering already in progress, skipping...');
            return;
        }
        
        if (filterTimeout) {
            clearTimeout(filterTimeout);
        }
        
        filterTimeout = setTimeout(async () => {
            try {
                isFiltering = true;
                
                const postType = document.getElementById('postTypeSelect').value;
                const petType = document.getElementById('typeSelect').value;
                const status = document.getElementById('statusSelect').value;
                const state = document.getElementById('stateSelect').value;

                console.log('Filters:', {postType, petType, status, state});

                const params = new URLSearchParams();
                if (postType && postType !== 'all') params.append('postType', postType);
                if (petType && petType !== 'all') params.append('type', petType);
                if (status && status !== 'all') params.append('status', status);
                if (state && state !== 'all') params.append('state', state);

                console.log('Applying filters:', params.toString());
                
                // Show loading overlay
                const mapDiv = document.getElementById('map');
                let loadingOverlay = null;
                if (mapDiv) {
                    loadingOverlay = document.createElement('div');
                    loadingOverlay.className = 'filter-loading';
                    loadingOverlay.innerHTML = `
                        <div style="width:30px;height:30px;border:3px solid #f3f3f3;border-top:3px solid #3B7A57;border-radius:50%;animation:spin 1s linear infinite;"></div>
                        <p>Applying filters...</p>
                    `;
                    loadingOverlay.style.cssText = `
                        position: absolute;
                        top: 50%;
                        left: 50%;
                        transform: translate(-50%, -50%);
                        background: rgba(255, 255, 255, 0.95);
                        padding: 20px;
                        border-radius: 8px;
                        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                        z-index: 1000;
                        min-width: 150px;
                    `;
                    mapDiv.appendChild(loadingOverlay);
                }
                
                const res = await fetch('get_pets.php?' + params.toString());
                if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
                
                let data = await res.json();
                
                // Remove loading overlay
                if (loadingOverlay && loadingOverlay.parentNode) {
                    loadingOverlay.remove();
                }
                
                // Format age untuk filtered data
                data.forEach(post => {
                    post.FormattedAge = formatAge(post.Age);
                    // Standardize data
                    if (post.Type) post.Type = standardizeType(post.Type);
                    if (post.Status) post.Status = standardizeStatus(post.Status);
                    if (post.State) post.State = standardizeState(post.State);
                });

                renderMarkers(data);
                updateStats(data);
                
            } catch (error) {
                console.error('Error applying filters:', error);
                
                // Remove loading overlay jika ada
                const mapDiv = document.getElementById('map');
                const overlay = mapDiv.querySelector('.filter-loading');
                if (overlay) {
                    overlay.remove();
                }
                
                alert('Error applying filters. Please try again.');
                
                // Reload original data
                renderMarkers(postData);
                updateStats(postData);
            } finally {
                isFiltering = false;
            }
        }, 300);
    }

    // ==============================
    // Update Statistics
    // ==============================
    function updateStats(list) {
        const totalEl = document.getElementById('totalCount');
        const availableEl = document.getElementById('availableCount');
        const adoptedEl = document.getElementById('adoptedCount');
        const petSitEl = document.getElementById('petSitCount');

        if (!list || !Array.isArray(list)) {
            list = [];
        }

        if (totalEl) totalEl.textContent = list.length;

        // Count berdasarkan status
        const available = list.filter(p => p.Status === 'Available').length;
        const adopted = list.filter(p => p.Status === 'Adopted').length;
        const petSit = list.filter(p => {
        const status = p.Status ? p.Status.toLowerCase() : '';
        // Check untuk berbagai kemungkinan
        return status === 'pet sit' || 
               status === 'petsit' ||
               status === 'pet sitting' ||
               status === 'petsitting';
    }).length;

        if (availableEl) availableEl.textContent = available;
        if (adoptedEl) adoptedEl.textContent = adopted;
        if (petSitEl) petSitEl.textContent = petSit;
    }

    // ==============================
    // Setup Event Listeners
    // ==============================
    let eventListenersSetup = false;

    function setupEventListeners() {
        if (eventListenersSetup) {
            console.log('Event listeners already setup');
            return;
        }
        
        const applyBtn = document.getElementById('applyBtn');
        const resetBtn = document.getElementById('resetBtn');
        
        if (applyBtn) {
            applyBtn.removeEventListener('click', applyFilters);
            applyBtn.addEventListener('click', applyFilters);
        } else {
            console.error('Apply button not found');
        }
        
        if (resetBtn) {
            // Define reset handler
            function handleReset() {
                console.log('Resetting filters...');
                
                // Reset dropdowns
                document.getElementById('postTypeSelect').value = 'all';
                document.getElementById('typeSelect').value = 'all';
                document.getElementById('statusSelect').value = 'all';
                document.getElementById('stateSelect').value = 'all';
                
                // Reset ke data asal
                renderMarkers(postData);
                updateStats(postData);
                if (map) {
                    map.setView(MAP_CENTER, MAP_ZOOM);
                }
            }
            
            resetBtn.removeEventListener('click', handleReset);
            resetBtn.addEventListener('click', handleReset);
        } else {
            console.error('Reset button not found');
        }
        
        // Auto-apply filters when dropdown changes dengan debounce
        const filterSelects = ['postTypeSelect', 'typeSelect', 'statusSelect', 'stateSelect'];
        filterSelects.forEach(id => {
            const select = document.getElementById(id);
            if (select) {
                select.removeEventListener('change', applyFilters);
                select.addEventListener('change', applyFilters);
            }
        });
        
        eventListenersSetup = true;
        console.log('Event listeners setup complete');
    }

    // ==============================
    // MAIN INITIALIZATION
    // ==============================
    async function initialize() {
        console.log('Starting pet map initialization...');
        
        // Step 1: Initialize map
        if (!initializeMap()) {
            console.error('Failed to initialize map');
            return;
        }
        
        // Step 2: Setup event listeners
        setupEventListeners();
        
        // Step 3: Load data
        await loadPostsAndPopulate();
        
        console.log('Pet map initialization complete');
    }

    // Start the initialization
    initialize();
});