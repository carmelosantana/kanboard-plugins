/**
 * FeatureSync — target project multi-select component.
 *
 * Loaded globally via Plugin.php (template:layout:js).
 * Guards on the presence of #fs-target-list before doing anything,
 * so it is a strict no-op on all other pages.
 *
 * Provides:
 *   - Per-row checkbox toggle
 *   - Select-all checkbox (with indeterminate state)
 *   - Live counter (#fs-count-label / #fs-count-badge)
 *
 * KB.component registers the constructor; KB.render() instantiates it by
 * finding `.js-fs-target-select` in the DOM and calling `component.render()`.
 */
KB.component('fs-target-select', function (containerElement, options) {

    'use strict';

    // ── Page guard ────────────────────────────────────────────────────────────
    // #fs-target-list is only rendered on the FeatureSync admin page (step 3).
    var targetList = document.getElementById('fs-target-list');
    if (!targetList) {
        this.render = function () {};
        return;
    }

    var CB_CLASS     = 'fs-target-cb';
    var SELECT_ALL_ID = 'fs-select-all';

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Return an array of all checked target project ids.
     * @returns {number[]}
     */
    function getSelectedIds() {
        var ids = [];
        var checkboxes = targetList.querySelectorAll('.' + CB_CLASS + ':checked');
        for (var i = 0; i < checkboxes.length; i++) {
            var val = parseInt(checkboxes[i].value, 10);
            if (val > 0) {
                ids.push(val);
            }
        }
        return ids;
    }

    // ── Counter + select-all sync ──────────────────────────────────────────────

    function updateCounter() {
        var selected   = getSelectedIds().length;
        var total      = targetList.querySelectorAll('.' + CB_CLASS).length;

        var countLbl   = document.getElementById('fs-count-label');
        var badge      = document.getElementById('fs-count-badge');
        var selectAll  = document.getElementById(SELECT_ALL_ID);

        if (countLbl) { countLbl.textContent = String(selected); }
        if (badge)    { badge.setAttribute('aria-label', selected + ' projects selected'); }

        if (selectAll) {
            selectAll.checked       = (total > 0 && selected === total);
            selectAll.indeterminate = (selected > 0 && selected < total);
        }
    }

    // ── Wire select-all ────────────────────────────────────────────────────────

    function bindSelectAll() {
        var selectAll = document.getElementById(SELECT_ALL_ID);
        if (!selectAll) { return; }

        selectAll.addEventListener('change', function () {
            var checkboxes = targetList.querySelectorAll('.' + CB_CLASS);
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = selectAll.checked;
            }
            updateCounter();
        });
    }

    // ── Wire per-row checkboxes ────────────────────────────────────────────────

    function bindRowCheckboxes() {
        var checkboxes = targetList.querySelectorAll('.' + CB_CLASS);
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].addEventListener('change', updateCounter);
        }
    }

    // ── Component entry point ──────────────────────────────────────────────────

    this.render = function () {
        bindSelectAll();
        bindRowCheckboxes();
        updateCounter(); // initialise counter from server-side pre-checked state
    };
});
