<?php

namespace Kanboard\Plugin\DependencyPlugin\Subscriber;

use Kanboard\Core\Base;
use Kanboard\Plugin\DependencyPlugin\Model\DependencyModel;

/**
 * Dependency link cycle-guard subscriber
 *
 * Listens on TaskLinkModel::EVENT_CREATE_UPDATE. Every "is blocked by" /
 * "blocks" link is created as a pair of rows (one per direction), and each
 * row fires its own EVENT_CREATE_UPDATE. We only need to act once per pair,
 * so we key off the "is blocked by" row: task_id is the subject, and
 * opposite_task_id is the blocker ("subject is blocked by blocker").
 *
 * If adding that edge would close a dependency cycle, the whole pair is
 * removed (core's TaskLinkModel::remove() removes both directions) and a
 * failure flash message is queued for the user.
 *
 * @package Kanboard\Plugin\DependencyPlugin\Subscriber
 */
class DependencyLinkSubscriber extends Base
{
    /**
     * Handle TaskLinkModel::EVENT_CREATE_UPDATE.
     *
     * @access public
     * @param  \Kanboard\Event\TaskLinkEvent $event
     * @return void
     */
    public function onLinkCreateUpdate($event)
    {
        $taskLink = $event['task_link'];

        if (empty($taskLink)) {
            return;
        }

        if (! isset($taskLink['label']) || $taskLink['label'] !== 'is blocked by') {
            return;
        }

        $subject = (int) $taskLink['task_id'];
        $blocker = (int) $taskLink['opposite_task_id'];

        $dependencyModel = isset($this->container['dependencyModel'])
            ? $this->container['dependencyModel']
            : new DependencyModel($this->container);

        if ($dependencyModel->wouldCreateCycle($subject, $blocker)) {
            $this->taskLinkModel->remove((int) $taskLink['id']);
            $this->flash->failure(t('This dependency link was removed because it would create a cycle.'));
        }
    }
}
