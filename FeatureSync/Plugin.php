<?php

namespace Kanboard\Plugin\FeatureSync;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\FeatureSync\Model\FeatureSyncModel;

class Plugin extends Base
{
    public function initialize()
    {
        // Register FeatureSyncModel in the DI container.
        $this->container['featureSyncModel'] = function ($c) {
            return new FeatureSyncModel($c);
        };

        // Inject plugin CSS + JS on every page (guarded inside the JS to only
        // activate on the FeatureSync admin page).
        $this->hook->on('template:layout:css', [
            'template' => 'plugins/FeatureSync/Assets/css/feature-sync.css',
        ]);
        $this->hook->on('template:layout:js', [
            'template' => 'plugins/FeatureSync/Assets/js/feature-sync.js',
        ]);

        // Sidebar link in Settings (admin context)
        $this->hook->on('template:config:sidebar', [
            'template' => 'FeatureSync:config/sidebar',
        ]);

        // Route to the admin page.
        // addRoute($path, $controller, $action, $plugin)  — see Core/Http/Route.php:61
        $this->route->addRoute('feature-sync', 'FeatureSyncController', 'index', 'FeatureSync');

        // Route to the preview page (Step 4 — dry-run diff, POST from index).
        $this->route->addRoute('feature-sync/preview', 'FeatureSyncController', 'preview', 'FeatureSync');
    }

    public function getPluginName()        { return 'FeatureSync'; }
    public function getPluginDescription() { return t('Bulk-copy project features (actions, tags, columns) from a source project to many target projects.'); }
    public function getPluginAuthor()      { return 'Carmelo Santana'; }
    public function getPluginVersion()     { return '0.1.0'; }
    public function getPluginHomepage()    { return 'https://github.com/vctrs-io/kanboard-feature-sync'; }
    public function getCompatibleVersion() { return '>=1.2.47'; }
}
