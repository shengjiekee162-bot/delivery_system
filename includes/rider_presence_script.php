<?php
$presence_api_url = '../api/rider_presence.php';
?>
<script>
(function () {
    const presenceUrl = <?= json_encode($presence_api_url) ?>;
    const internalNavKey = 'riderInternalNav';

    function markInternalNavigation(event) {
        const link = event.target.closest('a[href]');
        if (!link) return;

        const href = link.getAttribute('href') || '';
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;

        try {
            const target = new URL(link.href, window.location.href);
            if (target.origin === window.location.origin && target.pathname.includes('/rider/')) {
                sessionStorage.setItem(internalNavKey, '1');
            }
        } catch (error) {
            // Ignore invalid URLs
        }
    }

    function sendPresence(status) {
        const body = new URLSearchParams({ status });

        if (navigator.sendBeacon) {
            navigator.sendBeacon(presenceUrl, body);
            return;
        }

        fetch(presenceUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin',
            keepalive: true
        }).catch(() => {});
    }

    document.addEventListener('click', markInternalNavigation, true);
    document.addEventListener('submit', () => sessionStorage.setItem(internalNavKey, '1'), true);

    window.addEventListener('pageshow', () => {
        sessionStorage.removeItem(internalNavKey);
        sendPresence('online');
    });

    window.addEventListener('pagehide', () => {
        if (sessionStorage.getItem(internalNavKey) === '1') {
            return;
        }
        sendPresence('offline');
    });
})();
</script>
