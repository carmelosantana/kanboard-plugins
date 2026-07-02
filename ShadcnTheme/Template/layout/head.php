<script>
(function () {
    var theme = localStorage.getItem('shadcn-theme-mode') || 'dark';
    var cls = 'theme-' + (theme === 'system'
        ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
        : theme);
    document.documentElement.className = (document.documentElement.className || '').replace(/\btheme-\S+/g, '').trim();
    document.documentElement.className += (document.documentElement.className ? ' ' : '') + cls;
}());
</script>
<?php
$shadcnFaviconPath = $this->app->config('shadcn_favicon_path');
if (! empty($shadcnFaviconPath)):
?>
<link rel="icon" href="<?= $this->url->href('SettingsController', 'serveAsset', ['plugin' => 'ShadcnTheme', 'slot' => 'favicon']) ?>">
<?php endif ?>
