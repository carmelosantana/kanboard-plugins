<?php

namespace Kanboard\Plugin\FeatureSync;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize()
    {
        // Sidebar link in Settings (admin context)
        $this->hook->on('template:config:sidebar', [
            'template' => 'FeatureSync:config/sidebar',
        ]);

        // Route to the admin page
        $this->route->addRoute('feature-sync', 'FeatureSync:FeatureSyncController', 'index');
    }

    public function getPluginName()        { return 'FeatureSync'; }
    public function getPluginDescription() { return t('Bulk-copy project features (actions, tags, columns) from a source project to many target projects.'); }
    public function getPluginAuthor()      { return 'Carmelo Santana'; }
    public function getPluginVersion()     { return '0.1.0'; }
    public function getPluginHomepage()    { return 'https://github.com/vctrs-io/kanboard-feature-sync'; }
    public function getCompatibleVersion() { return '>=1.2.47'; }
}
