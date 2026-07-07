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
        $this->container['schedulerRunner'] = function ($c) {
            return new \Kanboard\Plugin\SchedulerPlugin\Model\SchedulerRunner($c);
        };
        $this->container['cli']->add(new SchedulerRunCommand($this->container));

        $container = $this->container;
        $this->hook->on('template:layout:top', array(
            'template' => 'SchedulerPlugin:layout/webcron',
            'callable' => function () use ($container) {
                (new \Kanboard\Plugin\SchedulerPlugin\Trigger\WebCronTrigger($container))->maybeRun();
                return array();
            },
        ));

        // Admin settings routes.
        $this->route->addRoute('scheduler/settings', 'SchedulerController', 'settings', 'SchedulerPlugin');
        $this->route->addRoute('scheduler/save', 'SchedulerController', 'save', 'SchedulerPlugin');
        $this->route->addRoute('scheduler/run', 'SchedulerController', 'run', 'SchedulerPlugin');

        // Config sidebar link.
        $this->hook->on('template:config:sidebar', array('template' => 'SchedulerPlugin:config/sidebar'));

        // Activity-stream event: register the name and point its render at our template.
        $this->eventManager->register('scheduler.tasks.rescheduled', t('Automatically rescheduled tasks'));
        $this->template->setTemplateOverride('event/scheduler_tasks_rescheduled', 'SchedulerPlugin:event/tasks_rescheduled');

        // Tiny sitewide CSS (badge + activity marker) — mirrors DependencyPlugin's decision.
        $this->hook->on('template:layout:css', array('template' => 'plugins/SchedulerPlugin/Assets/css/scheduler.css'));
    }

    public function getPluginName()        { return 'SchedulerPlugin'; }
    public function getPluginAuthor()      { return 'Carmelo Santana'; }
    public function getPluginVersion()     { return '1.0.0'; }
    public function getPluginDescription() { return 'Automatically roll overdue tasks forward: per-project opt-in, working-days and de-clump policies, audit log, calendar badge.'; }
    public function getPluginHomepage()    { return 'https://github.com/carmelosantana/kanboard-plugins'; }
    public function getPluginLicense()     { return 'MIT'; }
    public function getCompatibleVersion() { return '>=1.2.47'; }
}
