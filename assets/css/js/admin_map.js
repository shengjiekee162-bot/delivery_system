let map = null;
let markers = {};

/**
 * Initializes the Leaflet map in the targeted container.
 * @param {string} elementId - The ID of the HTML element where the map will render.
 */
function initAdminMap(elementId) {
    const container = document.getElementById(elementId);
    if (!container) {
        console.error("Map container element not found:", elementId);
        return;
    }

    // Verify that the Leaflet script was loaded successfully
    if (typeof L === 'undefined') {
        console.error("Leaflet JS library is missing. Ensure leaflet.js CDN or local file is loaded.");
        container.innerHTML = '<p style="color:#d9534f; padding:20px; text-align:center;">Leaflet map library failed to load. Please check your connection or CDN link.</p>';
        return;
    }

    // Initialize Leaflet Map (Default center: Kuala Lumpur [3.1390, 101.6869])
    map = L.map(elementId).setView([3.1390, 101.6869], 12);

    // Load OpenStreetMap Tile Layer
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    // Recalculate container dimensions after render to fix blank/grey canvas issues
    setTimeout(() => {
        if (map) {
            map.invalidateSize();
        }
    }, 300);

    // Fetch initial rider locations and set up polling every 10 seconds
    fetchRiderLocations();
    setInterval(fetchRiderLocations, 10000);
}

/**
 * Fetches current rider coordinates from the backend API.
 */
function fetchRiderLocations() {
    fetch('../api/get_rider_locations.php')
        .then(res => {
            if (!res.ok) throw new Error("HTTP error " + res.status);
            return res.json();
        })
        .then(response => {
            if (response.status === 'success' && Array.isArray(response.data)) {
                updateMapMarkers(response.data);
            }
        })
        .catch(err => console.error('Error fetching live positions:', err));
}

/**
 * Updates existing markers or places new ones on the map based on incoming API data.
 * @param {Array} riders - Array of rider location objects.
 */
function updateMapMarkers(riders) {
    if (!Array.isArray(riders) || !map) return;

    riders.forEach(rider => {
        if (!rider || !rider.latitude || !rider.longitude) return;

        const lat = parseFloat(rider.latitude);
        const lng = parseFloat(rider.longitude);

        if (isNaN(lat) || isNaN(lng)) return;

        const latLng = [lat, lng];
        const popupText = `
            <div style="font-family: sans-serif; font-size: 13px;">
                <strong>${escapeHtml(rider.name || 'Rider')}</strong><br>
                Vehicle: ${escapeHtml(rider.vehicle_number || 'N/A')}<br>
                Status: ${rider.is_online == 1 ? '<span style="color:#28a745; font-weight:bold;">Online</span>' : '<span style="color:#dc3545;">Offline</span>'}<br>
                <small style="color:#666;">Last Ping: ${escapeHtml(rider.recorded_at || 'N/A')}</small>
            </div>
        `;

        if (markers[rider.id]) {
            markers[rider.id].setLatLng(latLng).setPopupContent(popupText);
        } else {
            markers[rider.id] = L.marker(latLng).addTo(map).bindPopup(popupText);
        }
    });
}

/**
 * Utility to prevent XSS in popup contents.
 */
function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}