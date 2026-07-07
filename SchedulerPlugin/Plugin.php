<?php

namespace Kanboard\Plugin\SchedulerPlugin;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\SchedulerPlugin\Console\SchedulerRunCommand;

class Plugin extends Base
{
    public function initialize()
    {
        $this->container['schedulerConfigModel'] = function ($c) {
            return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel($c);
        };
        $this->container['schedulerLogModel'] = function ($c) {
            return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerLogModel($c);
        };
        $this->container['cli']->add(new SchedulerRunCommand($this->container));
    }

    public function getPluginName()        { return 'SchedulerPlugin'; }
    public function getPluginAuthor()      { return 'Carmelo Santana'; }
    public function getPluginVersion()     { return '1.0.0'; }
    public function getPluginDescription() { return 'Automatically roll overdue tasks forward: per-project opt-in, working-days and de-clump policies, audit log, calendar badge.'; }
    public function getPluginHomepage()    { return 'https://github.com/carmelosantana/kanboard-plugins'; }
    public function getPluginLicense()     { return 'MIT'; }
    public function getCompatibleVersion() { return '>=1.2.47'; }
}
