<?php
namespace Kanboard\Plugin\FeatureSync;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize()
    {
        // Placeholder — implementation coming in later tasks.
    }

    public function getPluginName()        { return "FeatureSync"; }
    public function getPluginDescription() { return "Sync features across projects (coming soon)."; }
    public function getPluginAuthor()      { return "Carmelo Santana"; }
    public function getPluginVersion()     { return "0.1.0"; }
    public function getPluginHomepage()    { return "https://github.com/vctrs-io/kanboard-feature-sync"; }
    public function getCompatibleVersion() { return ">=1.2.47"; }
}
