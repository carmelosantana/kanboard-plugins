/*! ModMenu — CSP-safe, document-delegated. */
(function () {
    'use strict';
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('form.modmenu-action');
        if (!form) { return; }
        var btn = form.querySelector('button[type="submit"], input[type="submit"]');
        if (btn) { btn.setAttribute('disabled', 'disabled'); }
    });
})();
