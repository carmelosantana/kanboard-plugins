<?php
namespace Kanboard\Plugin\SubtaskGenerator;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize()
    {
        // Placeholder — implementation coming in later tasks.
    }

    public function getPluginName()        { return "SubtaskGenerator"; }
    public function getPluginDescription() { return "AI-powered subtask generation (coming soon)."; }
    public function getPluginAuthor()      { return "Carmelo Santana"; }
    public function getPluginVersion()     { return "0.1.0"; }
    public function getPluginHomepage()    { return "https://github.com/vctrs-io/kanboard-subtask-generator"; }
    public function getCompatibleVersion() { return ">=1.2.47"; }
}
