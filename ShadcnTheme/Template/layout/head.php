<?php
/* No-FOUC theme preload. MUST be an external, BLOCKING script in <head> (no
 * defer/async) so it runs before first paint — Kanboard's CSP blocks inline
 * <script>, and $this->asset->js() adds `defer` (too late). filemtime busts the
 * cache when the file changes. */
$shadcnPreloadFile = dirname(__DIR__, 2) . '/Assets/js/theme-preload.js';
?>
<script src="<?= $this->url->dir() ?>plugins/ShadcnTheme/Assets/js/theme-preload.js?<?= @filemtime($shadcnPreloadFile) ?>"></script>
<?php
$shadcnFaviconPath = $this->app->config('shadcn_favicon_path');
if (! empty($shadcnFaviconPath)):
?>
<link rel="icon" href="<?= $this->url->href('SettingsController', 'serveAsset', ['plugin' => 'ShadcnTheme', 'slot' => 'favicon']) ?>">
<?php endif ?>
