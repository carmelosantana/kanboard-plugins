/*!
 * ShadcnTheme — theme preload (no-FOUC)
 *
 * Loaded as a BLOCKING external <script> in <head> (see Template/layout/head.php)
 * so it runs before the browser's first paint. It stamps the resolved theme class
 * onto <html> immediately, so every full-page navigation paints dark (or light)
 * from the first frame instead of flashing the default white page and then
 * flipping once theme-switcher.js runs at the end of <body>.
 *
 * Must stay EXTERNAL: Kanboard's CSP (default-src 'self') blocks inline <script>,
 * so an inline no-FOUC script in head.php never executes.
 */
(function () {
    try {
        var mode = localStorage.getItem('shadcn-theme-mode') || 'dark';
        var resolved = mode === 'system'
            ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : mode;
        var el = document.documentElement;
        el.className = (el.className || '').replace(/\btheme-\S+/g, '').trim();
        el.className += (el.className ? ' ' : '') + 'theme-' + resolved;
    } catch (e) {
        document.documentElement.className += ' theme-dark';
    }
}());
