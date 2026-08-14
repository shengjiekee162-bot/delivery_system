let gpsInterval = null;
let isOnline = false;

document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.getElementById('toggle-online-btn');
    if (!toggleBtn) return;

    toggleBtn.addEventListener('click', () => {
        isOnline = !isOnline;
        if (isOnline) {
            toggleBtn.textContent = 'Set Status: OFFLINE';
            toggleBtn.className = 'btn btn-danger';
            document.getElementById('online-badge').textContent = 'Online';
            document.getElementById('online-badge').className = 'badge badge-success';
            startTracking();
        } else {
            toggleBtn.textContent = 'Set Status: ONLINE';
            toggleBtn.className = 'btn btn-success';
            document.getElementById('online-badge').textContent = 'Offline';
            document.getElementById('online-badge').className = 'badge badge-secondary';
            stopTracking();
        }
    });
});

function startTracking() {
    sendLocationUpdate();
    gpsInterval = setInterval(sendLocationUpdate, 15000); // 15 Seconds
}

function stopTracking() {
    if (gpsInterval) clearInterval(gpsInterval);
    sendLocationPayload(0, 0, 0); // Mark offline on server
}

function sendLocationUpdate() {
    if (!navigator.geolocation) {
        alert('Geolocation is not supported by your browser');
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (position) => {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            sendLocationPayload(lat, lng, 1);
        },
        (error) => {
            console.error('GPS Error:', error.message);
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}

function sendLocationPayload(lat, lng, status) {
    fetch('../api/update_location.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ latitude: lat, longitude: lng, is_online: status })
    })
    .then(res => res.json())
    .then(data => {
        const timestampEl = document.getElementById('last-sync-time');
        if (timestampEl) {
            timestampEl.textContent = new Date().toLocaleTimeString();
        }
    })
    .catch(err => console.error('Tracking Ping Failed:', err));
}