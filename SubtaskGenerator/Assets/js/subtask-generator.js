/**
 * SubtaskGenerator — modal interaction script.
 *
 * Injected globally via Plugin.php (template:layout:js).
 * Guards on the presence of #sg-generate-form before doing anything,
 * so it is a strict no-op on all other pages.
 *
 * Uses EVENT DELEGATION on `document` for the Generate, Regenerate, and Create
 * button handlers. This is required because Kanboard opens the modal by fetching
 * modal.php HTML and injecting it via innerHTML — so any listeners bound at
 * page-load time would never see these elements. Delegating to `document` means
 * handlers fire correctly after the modal is dynamically inserted.
 *
 * Server data is read from data attributes on #sg-generate-form:
 *   data-generate-url — URL for the generate() endpoint
 *   data-create-url   — URL for the create() endpoint
 *   data-task-id      — task ID (integer)
 * The CSRF token is read from the existing hidden input name="csrf_token"
 * inside the form (no inline JS needed).
 */
(function () {
    'use strict';

    // ── Show / hide helpers ───────────────────────────────────────────────────

    function show(el) {
        if (el) { el.style.display = ''; }
    }

    function hide(el) {
        if (el) { el.style.display = 'none'; }
    }

    // ── Candidate row renderer ────────────────────────────────────────────────

    /**
     * Render a row for one candidate title.
     * Each row: [ checkbox ] [ editable text input ]
     *
     * Titles are set via .value (not innerHTML) for XSS safety.
     *
     * @param {HTMLElement} candidateList  — the #sg-candidate-list container
     * @param {string}      title          — candidate subtask title from the server
     * @param {number}      index          — row index (used for element ids)
     */
    function renderRow(candidateList, title, index) {
        var row = document.createElement('div');
        row.className = 'sg-candidate-row';
        row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:6px;';

        var chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.id = 'sg-chk-' + index;
        chk.checked = true;
        chk.setAttribute('data-index', index);

        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'form-control';
        inp.id = 'sg-title-' + index;
        inp.value = title;   // XSS-safe: .value, not innerHTML
        inp.style.cssText = 'flex:1;';
        inp.setAttribute('data-index', index);

        row.appendChild(chk);
        row.appendChild(inp);
        candidateList.appendChild(row);
    }

    // ── Generate logic ────────────────────────────────────────────────────────

    /**
     * POST to the generate() endpoint; on success render the candidate checklist.
     * Server data (URL, task_id, csrf) is read from the form element's data attrs
     * and existing hidden inputs — no inline JS variables.
     */
    function runGenerate() {
        var generateForm  = document.getElementById('sg-generate-form');
        var resultsDiv    = document.getElementById('sg-results');
        var candidateList = document.getElementById('sg-candidate-list');
        var errorDiv      = document.getElementById('sg-error');
        var loadingDiv    = document.getElementById('sg-loading');

        // Guard: modal may not be present on this page.
        if (!generateForm) { return; }

        hide(errorDiv);
        hide(resultsDiv);
        show(loadingDiv);

        var data = new FormData(generateForm);

        fetch(generateForm.action, {
            method: 'POST',
            body: data,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (resp) { return resp.json(); })
        .then(function (json) {
            hide(loadingDiv);

            if (json.error) {
                if (errorDiv) { errorDiv.textContent = json.error; }
                show(errorDiv);
                return;
            }

            var subtasks = json.subtasks || [];
            if (subtasks.length === 0) {
                if (errorDiv) {
                    // Fallback message; real localised string comes from data attr below if set.
                    errorDiv.textContent = generateForm.getAttribute('data-msg-empty') ||
                        'No subtasks were generated. Try refining your prompt.';
                }
                show(errorDiv);
                return;
            }

            // Render candidate rows.
            if (candidateList) {
                candidateList.innerHTML = '';
                subtasks.forEach(function (title, idx) {
                    renderRow(candidateList, title, idx);
                });
            }

            show(resultsDiv);
        })
        .catch(function () {
            hide(loadingDiv);
            // Hide stale results from a prior successful generate so the user
            // does not see outdated candidates after a network error.
            hide(resultsDiv);
            if (errorDiv) {
                errorDiv.textContent = generateForm.getAttribute('data-msg-network') ||
                    'Network error. Please try again.';
            }
            show(errorDiv);
        });
    }

    // ── Create form submit handler ────────────────────────────────────────────

    /**
     * Before the create form submits, build hidden inputs from checked+edited rows.
     * Aborts the submit if nothing is selected.
     *
     * @param {Event} e — the submit event on #sg-create-form
     */
    function handleCreateSubmit(e) {
        var createForm    = document.getElementById('sg-create-form');
        var candidateList = document.getElementById('sg-candidate-list');
        var hiddenTitles  = document.getElementById('sg-hidden-titles');
        var errorDiv      = document.getElementById('sg-error');
        var generateForm  = document.getElementById('sg-generate-form');

        if (!createForm || !candidateList || !hiddenTitles) { return; }

        // Remove any previously built hidden inputs.
        hiddenTitles.innerHTML = '';

        var rows = candidateList.querySelectorAll('.sg-candidate-row');
        rows.forEach(function (row) {
            var chk = row.querySelector('input[type="checkbox"]');
            var inp = row.querySelector('input[type="text"]');

            if (chk && chk.checked && inp) {
                var title = inp.value.trim();
                if (title !== '') {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'titles[]';
                    hidden.value = title;  // XSS-safe: .value
                    hiddenTitles.appendChild(hidden);
                }
            }
        });

        // If nothing is selected / all blank, abort the submit.
        if (hiddenTitles.children.length === 0) {
            e.preventDefault();
            if (errorDiv) {
                errorDiv.textContent = (generateForm && generateForm.getAttribute('data-msg-none-selected')) ||
                    'Please select at least one subtask to create.';
                show(errorDiv);
            }
        }
    }

    // ── Event delegation ──────────────────────────────────────────────────────
    //
    // Kanboard injects the modal HTML via innerHTML after a fetch — inline <script>
    // tags inside innerHTML are never executed. By delegating to `document` here
    // (in the externally-loaded JS that runs at page load), our handlers fire for
    // any matching element that is later inserted dynamically.

    document.addEventListener('click', function (e) {
        var target = e.target;

        // Generate button
        if (target && target.id === 'sg-generate-btn') {
            runGenerate();
            return;
        }

        // Regenerate button
        if (target && target.id === 'sg-regenerate-btn') {
            runGenerate();
            return;
        }
    });

    document.addEventListener('submit', function (e) {
        var target = e.target;

        // Create form
        if (target && target.id === 'sg-create-form') {
            handleCreateSubmit(e);
        }
    });

    // ── Settings page: Test Connection ──────────────────────────────────────
    // The inline <script> that used to live in config/settings.php is blocked by
    // Kanboard's CSP, so this runs from the external asset instead. The test URL
    // (with a reusable CSRF token) and i18n strings arrive via data-* attributes.
    function runTestConnection(btn) {
        var box = document.getElementById('sg-test-result');
        var url = btn.getAttribute('data-test-url');
        if (!box || !url) { return; }
        btn.disabled = true;
        box.style.display = 'none';
        box.textContent = '';

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                box.style.display = 'block';
                if (data && data.ok) {
                    box.style.background = '#d1fae5';
                    box.style.color = '#065f46';
                    box.textContent = btn.getAttribute('data-msg-ok') || 'OK';
                } else {
                    box.style.background = '#fee2e2';
                    box.style.color = '#991b1b';
                    box.textContent = (btn.getAttribute('data-msg-fail') || 'Failed:') + ' ' +
                        ((data && data.error) || btn.getAttribute('data-msg-unknown') || '');
                }
                btn.disabled = false;
            })
            .catch(function (err) {
                box.style.display = 'block';
                box.style.background = '#fee2e2';
                box.style.color = '#991b1b';
                box.textContent = (btn.getAttribute('data-msg-request-failed') || 'Request failed:') + ' ' + err.message;
                btn.disabled = false;
            });
    }

    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('#sg-test-btn') : null;
        if (t) {
            e.preventDefault();
            runTestConnection(t);
        }
    });

    // ── Settings page: auto-fill model name when the provider changes ────────
    document.addEventListener('change', function (e) {
        var sel = e.target;
        if (!sel || sel.id !== 'sg_provider') { return; }
        var modelInput = document.getElementById('sg_model');
        if (!modelInput) { return; }
        var defaults = {};
        try { defaults = JSON.parse(sel.getAttribute('data-defaults') || '{}'); } catch (err) {}
        var def = defaults[sel.value];
        if (def && modelInput.value === modelInput.placeholder) {
            modelInput.value = def;
        }
        modelInput.placeholder = def || '';
    });

}());
