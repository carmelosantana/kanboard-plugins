/*! CalendarPlugin — external, CSP-safe. */
(function () {
    'use strict';

    function buildFilterQuery(root) { return ''; }

    function init() {
        var root = document.getElementById('cal-root');
        var host = document.getElementById('calendar');
        if (!root || !host || typeof FullCalendar === 'undefined') { return; }
        if (host.dataset.calReady) { return; }
        host.dataset.calReady = '1';

        var eventsUrl = root.getAttribute('data-events-url');
        var calendar = new FullCalendar.Calendar(host, {
            initialView: 'dayGridMonth',
            height: 'auto',
            firstDay: 1,
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth' },
            events: function (info, success, failure) {
                var url = eventsUrl + (eventsUrl.indexOf('?') >= 0 ? '&' : '?') +
                    'start=' + encodeURIComponent(info.startStr) + '&end=' + encodeURIComponent(info.endStr) +
                    buildFilterQuery(root);
                fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) { success(data); })
                    .catch(function (e) { failure(e); });
            }
        });
        window.__calInstance = calendar; // handy for E2E assertions
        calendar.render();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
