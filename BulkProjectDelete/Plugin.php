<?php

namespace Kanboard\Plugin\BulkProjectDelete;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize()
    {
        $this->hook->on('template:layout:css', ['template' => 'plugins/BulkProjectDelete/Assets/css/bulk-delete.css']);
        $this->hook->on('template:layout:js',  ['template' => 'plugins/BulkProjectDelete/Assets/js/bulk-delete.js']);
        $this->helper->hook->attach('template:project-list:menu:after', 'BulkProjectDelete:listing/toolbar');
        $this->route->addRoute('bulk-project-delete/confirm', 'BulkProjectDelete:BulkDeleteController', 'confirm');
        $this->route->addRoute('bulk-project-delete/remove',  'BulkProjectDelete:BulkDeleteController', 'remove');
    }

    public function getPluginName()        { return 'BulkProjectDelete'; }
    public function getPluginDescription() { return t('Bulk-delete multiple projects in one action.'); }
    public function getPluginAuthor()      { return 'Carmelo Santana'; }
    public function getPluginVersion()     { return '1.0.0'; }
    public function getPluginHomepage()    { return 'https://github.com/vctrs-io/kanboard-bulk-project-delete'; }
    public function getCompatibleVersion() { return '>=1.2.47'; }
}
