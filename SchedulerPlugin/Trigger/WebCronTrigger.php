<?php

namespace Kanboard\Plugin\SchedulerPlugin\Trigger;

use Kanboard\Core\Base;

/**
 * Lazy web-cron: fires the daily sweep at most once per day, on the first
 * rendered page request past the configured target hour. Stamps last_run
 * BEFORE running so a burst of concurrent requests cannot double-fire.
 */
class WebCronTrigger extends Base
{
    public function maybeRun()
    {
        $config = $this->schedulerConfigModel;

        if (! $config->isMasterEnabled()) {
            return false;
        }

        $today = date('Y-m-d');
        if ($config->getLastRun() === $today) {
            return false;
        }

        if ((int) date('G') < $config->getTargetHour()) {
            return false;
        }

        // Claim the day first, then run.
        $config->setLastRun($today);
        $this->schedulerRunner->run(array('trigger' => 'web'));

        return true;
    }
}
