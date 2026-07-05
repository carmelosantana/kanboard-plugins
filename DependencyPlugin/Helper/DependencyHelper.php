<?php

namespace Kanboard\Plugin\DependencyPlugin\Helper;

use Kanboard\Core\Base;

/**
 * Dependency template helper
 *
 * Thin template-facing wrapper around DependencyModel so board/task
 * templates can query blocked status without reaching into the
 * container directly.
 *
 * @package Kanboard\Plugin\DependencyPlugin\Helper
 */
class DependencyHelper extends Base
{
    /**
     * Number of still-open tasks blocking the given task.
     *
     * @access public
     * @param  array $task Full task row, must contain 'id' and 'project_id'.
     * @return int
     */
    public function blockedOpenCount(array $task)
    {
        $map = $this->dependencyModel->getProjectBlockedMap((int) $task['project_id']);

        return isset($map[(int) $task['id']]['open_blockers'])
            ? (int) $map[(int) $task['id']]['open_blockers']
            : 0;
    }
}
