/**
 * BulkProjectDelete — selection UI component.
 *
 * Injected globally via template:layout:js (loads on every page).
 * Guards on the presence of the project-list table body before doing anything,
 * so it is a strict no-op on all other pages.
 *
 * Project-id source: each `.table-list-row` on the project list contains a project
 * link rendered by project_list/project_title.php whose href has the query string
 * `?project_id=N` (or `&project_id=N`). We parse that parameter from the first
 * matching anchor inside the row to get the id.
 *
 * KB.component registers the constructor; KB.render() instantiates it by finding
 * `.js-bpd-bulk-select` in the DOM and calling `component.render()`.
 */
KB.component('bpd-bulk-select', function (containerElement, options) {

    'use strict';

    // ── Page guard ─────────────────────────────────────────────────────────────
    // #bpd-toggle is injected by toolbar.php exclusively on the project list page.
    // Other list pages (task list, user list, etc.) also have .table-list, so we
    // key off this button instead — it is a reliable project-list-only signal.
    var TABLE_LIST_SELECTOR  = '.table-list';
    var ROW_SELECTOR         = '.table-list-row';
    var CB_CLASS             = 'bpd-cb';
    var SELECT_ALL_ID        = 'bpd-select-all';

    if (!document.getElementById('bpd-toggle')) { this.render = function () {}; return; }

    var tableList = document.querySelector(TABLE_LIST_SELECTOR);

    // ── State ──────────────────────────────────────────────────────────────────
    var active = false;   // whether selection mode is on
    var confirmUrl = options && options.confirmUrl ? options.confirmUrl : '';

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Extract the project id from a `.table-list-row`.
     *
     * Strategy: find the first anchor inside the row whose href contains
     * `project_id=<N>` and return the integer value of that parameter.
     * This mirrors what project_list/project_title.php renders:
     *   $this->url->link(..., 'BoardViewController', 'show', ['project_id' => $project['id']])
     * as well as the dropdown links that all carry `project_id=N`.
     *
     * Falls back to null if no such link is found (should never happen on a
     * well-formed project-list page).
     *
     * @param {HTMLElement} row
     * @returns {number|null}
     */
    function getProjectId(row) {
        var anchors = row.querySelectorAll('a[href]');
        for (var i = 0; i < anchors.length; i++) {
            var href = anchors[i].getAttribute('href');
            var match = href.match(/[?&]project_id=(\d+)/);
            if (match) {
                return parseInt(match[1], 10);
            }
        }
        return null;
    }

    /**
     * Return all project-list rows currently in the DOM.
     * @returns {NodeList}
     */
    function getRows() {
        return tableList.querySelectorAll(ROW_SELECTOR);
    }

    /**
     * Return an array of the ids of all currently checked rows.
     * @returns {number[]}
     */
    function getSelectedIds() {
        var ids = [];
        var checkboxes = tableList.querySelectorAll('.' + CB_CLASS + ':checked');
        for (var i = 0; i < checkboxes.length; i++) {
            var id = parseInt(checkboxes[i].getAttribute('data-project-id'), 10);
            if (id) {
                ids.push(id);
            }
        }
        return ids;
    }

    // ── Counter + button state ─────────────────────────────────────────────────

    function updateCounter() {
        var n = getSelectedIds().length;
        var badge     = document.getElementById('bpd-count-badge');
        var countLbl  = document.getElementById('bpd-count-label');
        var deleteBtn = document.getElementById('bpd-delete-btn');

        if (badge)     { badge.textContent    = n + ' selected'; }
        if (countLbl)  { countLbl.textContent = n; }
        if (deleteBtn) {
            deleteBtn.disabled = (n === 0);
            deleteBtn.setAttribute('aria-disabled', String(n === 0));
        }

        // Keep select-all in sync
        var selectAll = document.getElementById(SELECT_ALL_ID);
        if (selectAll) {
            var total = tableList.querySelectorAll('.' + CB_CLASS).length;
            selectAll.checked       = (total > 0 && n === total);
            selectAll.indeterminate = (n > 0 && n < total);
        }
    }

    // ── Checkbox injection ─────────────────────────────────────────────────────

    /**
     * Add a per-row checkbox to a single `.table-list-row`.
     * @param {HTMLElement} row
     */
    function addRowCheckbox(row) {
        if (row.querySelector('.' + CB_CLASS)) {
            return; // already injected
        }
        var id = getProjectId(row);
        if (id === null) {
            return; // defensive — skip rows without a project id
        }

        var cb = document.createElement('input');
        cb.type  = 'checkbox';
        cb.className = CB_CLASS;
        cb.setAttribute('data-project-id', String(id));
        var nameEl = row.querySelector('a.board-selector, .table-list-title a, a[href*="project_id"]');
        var projectName = nameEl && nameEl.textContent ? nameEl.textContent.trim() : null;
        cb.setAttribute('aria-label', 'Select project ' + (projectName || id));
        cb.addEventListener('change', updateCounter);

        // Prepend the checkbox as the first child of the row so it sits to the left.
        row.insertBefore(cb, row.firstChild);
    }

    /**
     * Add the select-all checkbox to the table header (first sibling before the rows).
     */
    function addSelectAllCheckbox() {
        if (document.getElementById(SELECT_ALL_ID)) {
            return;
        }

        // The header row is rendered by project_list/header.php and sits directly
        // inside .table-list, before the .table-list-row divs.
        var header = tableList.querySelector('.table-list-row-header');
        if (!header) {
            return;
        }

        var cb = document.createElement('input');
        cb.type  = 'checkbox';
        cb.id    = SELECT_ALL_ID;
        cb.setAttribute('aria-label', 'Select all projects');
        cb.addEventListener('change', function () {
            var checkboxes = tableList.querySelectorAll('.' + CB_CLASS);
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = cb.checked;
            }
            updateCounter();
        });

        header.insertBefore(cb, header.firstChild);
    }

    // ── Remove checkboxes ──────────────────────────────────────────────────────

    function removeCheckboxes() {
        var checkboxes = document.querySelectorAll('.' + CB_CLASS + ', #' + SELECT_ALL_ID);
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].parentNode.removeChild(checkboxes[i]);
        }
    }

    // ── Toggle ─────────────────────────────────────────────────────────────────

    function enableSelectionMode() {
        active = true;
        var rows = getRows();
        for (var i = 0; i < rows.length; i++) {
            addRowCheckbox(rows[i]);
        }
        addSelectAllCheckbox();

        var bar    = document.getElementById('bpd-action-bar');
        var toggle = document.getElementById('bpd-toggle');
        if (bar)    { bar.classList.remove('bpd-hidden'); }
        if (toggle) { toggle.setAttribute('aria-pressed', 'true'); }
        updateCounter();
    }

    function disableSelectionMode() {
        active = false;
        removeCheckboxes();

        var bar    = document.getElementById('bpd-action-bar');
        var toggle = document.getElementById('bpd-toggle');
        if (bar)    { bar.classList.add('bpd-hidden'); }
        if (toggle) { toggle.setAttribute('aria-pressed', 'false'); }
    }

    // ── Delete button ──────────────────────────────────────────────────────────

    function onDeleteClick() {
        var ids = getSelectedIds();
        if (!ids.length || !confirmUrl) {
            return;
        }

        // Build a form and POST the selected ids to the confirm URL (task-04/05).
        // Using a POST form avoids exposing ids in the URL and lets task-04 render
        // a full confirmation page with CSRF protection.
        var form = document.createElement('form');
        form.method  = 'POST';
        form.action  = confirmUrl;

        for (var i = 0; i < ids.length; i++) {
            var input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'project_ids[]';
            input.value = String(ids[i]);
            form.appendChild(input);
        }

        document.body.appendChild(form);
        form.submit();
    }

    // ── Wire up static buttons (rendered server-side in toolbar.php) ──────────

    function bindStaticButtons() {
        var toggle    = document.getElementById('bpd-toggle');
        var deleteBtn = document.getElementById('bpd-delete-btn');

        if (toggle) {
            toggle.addEventListener('click', function () {
                if (active) {
                    disableSelectionMode();
                } else {
                    enableSelectionMode();
                }
            });
        }

        if (deleteBtn) {
            deleteBtn.addEventListener('click', onDeleteClick);
        }
    }

    // ── Typed-confirmation arm/disarm (task-06) ────────────────────────────────
    //
    // The confirm modal (Template/remove/confirm.php) contains:
    //   #bpd-confirm-form  — POST form with csrf + hidden project_ids[]
    //   #bpd-confirm-input — text input the user must type DELETE into
    //   #bpd-submit-btn    — submit button; disabled until armed
    //
    // Strategy: on every `input` event, compare the trimmed value to 'DELETE'
    // (exact, case-sensitive).  Toggle disabled + aria-disabled accordingly.
    // We do NOT prevent native form submission — when the button is enabled the
    // browser submits #bpd-confirm-form normally (action, method, csrf, ids are
    // all already in the form markup).
    //
    // bindConfirmGate() is called once, after the modal's HTML has been injected
    // into the DOM (see onDeleteClick → form.submit() navigates away, so the
    // gate lives on the confirm page itself; but if a future modal injection
    // approach is used, call bindConfirmGate() after innerHTML is set).

    function bindConfirmGate() {
        var confirmInput = document.getElementById('bpd-confirm-input');
        var submitBtn    = document.getElementById('bpd-submit-btn');

        if (!confirmInput || !submitBtn) {
            return; // not on the confirm page — nothing to wire
        }

        function arm() {
            submitBtn.disabled = false;
            submitBtn.removeAttribute('aria-disabled');
        }

        function disarm() {
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-disabled', 'true');
        }

        function onConfirmInput() {
            // Exact match, case-sensitive: user must type DELETE.
            if (confirmInput.value === 'DELETE') {
                arm();
            } else {
                disarm();
            }
        }

        confirmInput.addEventListener('input', onConfirmInput);

        // Guard: if the form is somehow submitted while the button is still
        // disabled (e.g. via keyboard Enter on the input), abort.
        var form = document.getElementById('bpd-confirm-form');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (confirmInput.value !== 'DELETE') {
                    e.preventDefault();
                    confirmInput.focus();
                }
            });
        }
    }

    // ── Component entry point ──────────────────────────────────────────────────

    this.render = function () {
        bindStaticButtons();
        // Wire typed-confirm gate if we are already on the confirm page
        // (e.g. the page was loaded as a full page rather than via modal injection).
        bindConfirmGate();
    };
});
