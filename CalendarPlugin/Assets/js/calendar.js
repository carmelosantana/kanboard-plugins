/*! CalendarPlugin — external, CSP-safe. */
(function () {
    'use strict';

    function init() {
        var root = document.getElementById('cal-root');
        var host = document.getElementById('calendar');
        if (!root || !host || typeof FullCalendar === 'undefined') { return; }
        if (host.dataset.calReady) { return; }
        host.dataset.calReady = '1';

        var calendar = new FullCalendar.Calendar(host, {
            initialView: 'dayGridMonth',
            height: 'auto',
            firstDay: 1,
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth' }
        });
        calendar.render();
        window.__calInstance = calendar; // handy for E2E assertions
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
