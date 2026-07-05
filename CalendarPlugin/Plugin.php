<?php

namespace Kanboard\Plugin\CalendarPlugin;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize()
    {
        // Wired in later tasks.
    }

    public function getPluginName()        { return 'CalendarPlugin'; }
    public function getPluginAuthor()      { return 'Carmelo Santana'; }
    public function getPluginVersion()     { return '1.0.0'; }
    public function getPluginDescription() { return 'Drag-and-drop calendar view: tasks by due date, across all projects or per project.'; }
    public function getPluginHomepage()    { return 'https://github.com/carmelosantana/kanboard-plugins'; }
    public function getPluginLicense()     { return 'MIT'; }
    public function getCompatibleVersion() { return '>=1.2.47'; }
}
