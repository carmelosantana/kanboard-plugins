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

        // Sidebar link in Settings (admin context)
        $this->hook->on('template:config:sidebar', [
            'template' => 'FeatureSync:config/sidebar',
        ]);

        // Route to the admin page.
        // addRoute($path, $controller, $action, $plugin)  — see Core/Http/Route.php:61
        $this->route->addRoute('feature-sync', 'FeatureSyncController', 'index', 'FeatureSync');
    }

    public function getPluginName()        { return 'FeatureSync'; }
    public function getPluginDescription() { return t('Bulk-copy project features (actions, tags, columns) from a source project to many target projects.'); }
    public function getPluginAuthor()      { return 'Carmelo Santana'; }
    public function getPluginVersion()     { return '0.1.0'; }
    public function getPluginHomepage()    { return 'https://github.com/vctrs-io/kanboard-feature-sync'; }
    public function getCompatibleVersion() { return '>=1.2.47'; }
}
