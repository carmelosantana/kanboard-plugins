<?php
namespace Kanboard\Plugin\BulkProjectDelete;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize()
    {
        // Placeholder — implementation coming in later tasks.
    }

    public function getPluginName()        { return "BulkProjectDelete"; }
    public function getPluginDescription() { return "Bulk-delete projects (coming soon)."; }
    public function getPluginAuthor()      { return "Carmelo Santana"; }
    public function getPluginVersion()     { return "0.1.0"; }
    public function getPluginHomepage()    { return "https://github.com/vctrs-io/kanboard-bulk-project-delete"; }
    public function getCompatibleVersion() { return ">=1.2.47"; }
}
