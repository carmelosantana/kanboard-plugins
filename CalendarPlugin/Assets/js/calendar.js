/*! CalendarPlugin — external, CSP-safe. */
(function () {
    'use strict';

    /**
     * Build query-string fragment from #cal-filterbar controls.
     * Each control must have data-cal-filter="<param_name>".
     *
     * Supported control types:
     *   <select multiple> data-cal-filter="project_ids"  → &project_ids=1,2,3
     *   <select>          data-cal-filter="assignee_id"  → &assignee_id=5
     *   <select>          data-cal-filter="category_id"  → &category_id=3
     *   <input[checkbox]> data-cal-filter="hide_completed" → &hide_completed=1
     */
    function buildFilterQuery() {
        var bar = document.getElementById('cal-filterbar');
        if (!bar) { return ''; }

        var parts = [];
        var controls = bar.querySelectorAll('[data-cal-filter]');
        for (var i = 0; i < controls.length; i++) {
            var el = controls[i];
            var param = el.getAttribute('data-cal-filter');

            if (el.tagName === 'SELECT' && el.multiple) {
                // Multi-select: collect all selected values as comma-separated list.
                var selected = [];
                for (var j = 0; j < el.options.length; j++) {
                    if (el.options[j].selected) {
                        selected.push(encodeURIComponent(el.options[j].value));
                    }
                }
                if (selected.length > 0) {
                    parts.push(encodeURIComponent(param) + '=' + selected.join(','));
                }
            } else if (el.tagName === 'SELECT') {
                if (el.value !== '') {
                    parts.push(encodeURIComponent(param) + '=' + encodeURIComponent(el.value));
                }
            } else if (el.type === 'checkbox') {
                if (el.checked) {
                    parts.push(encodeURIComponent(param) + '=' + encodeURIComponent(el.value));
                }
            }
        }

        return parts.length > 0 ? '&' + parts.join('&') : '';
    }

    function postDate(root, taskId, dateStr, done) {
        var body = new URLSearchParams();
        body.set('task_id', taskId);
        body.set('date_due', dateStr);
        body.set('csrf_token', root.getAttribute('data-csrf'));
        fetch(root.getAttribute('data-update-url'), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
        }).then(function (r) { return r.json().catch(function () { return { result: false }; }); })
          .then(function (d) { done(!!(d && d.result)); })
          .catch(function () { done(false); });
    }

    function loadUnscheduled(root) {
        var list = document.getElementById('cal-unscheduled-list');
        if (!list) { return; }
        fetch(root.getAttribute('data-unscheduled-url'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (items) {
                list.textContent = '';
                items.forEach(function (it) {
                    var el = document.createElement('div');
                    el.className = 'cal-unscheduled-item';
                    el.setAttribute('data-task-id', it.id);
                    el.style.borderLeft = '4px solid ' + it.color;
                    el.textContent = it.title;
                    list.appendChild(el);
                });
                new FullCalendar.Draggable(list, {
                    itemSelector: '.cal-unscheduled-item',
                    eventData: function (el) { return { id: el.getAttribute('data-task-id'), title: el.textContent, allDay: true }; }
                });
            });
    }

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
            editable: true,
            droppable: true,
            eventReceive: function (info) {
                var el = document.querySelector('.cal-unscheduled-item[data-task-id="' + info.event.id + '"]');
                postDate(root, info.event.id, info.event.startStr, function (ok) {
                    if (ok) { if (el) { el.parentNode.removeChild(el); } }
                    else { info.event.remove(); }
                });
            },
            eventDrop: function (info) {
                postDate(root, info.event.id, info.event.startStr, function (ok) { if (!ok) { info.revert(); } });
            },
            events: function (info, success, failure) {
                var url = eventsUrl + (eventsUrl.indexOf('?') >= 0 ? '&' : '?') +
                    'start=' + encodeURIComponent(info.startStr) + '&end=' + encodeURIComponent(info.endStr) +
                    buildFilterQuery();
                fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) { success(data); })
                    .catch(function (e) { failure(e); });
            }
        });
        window.__calInstance = calendar; // handy for E2E assertions
        calendar.render();
        loadUnscheduled(root);

        // Delegated change listener: filter controls refetch events;
        // unscheduled toggle toggles sidebar visibility.
        document.addEventListener('change', function (e) {
            var el = e.target;
            if (el && el.id === 'cal-toggle-unscheduled') {
                var layout = document.getElementById('cal-layout');
                if (layout) { layout.classList.toggle('cal-hide-unscheduled', !el.checked); }
            } else if (el && el.closest && el.closest('#cal-filterbar')) {
                calendar.refetchEvents();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
