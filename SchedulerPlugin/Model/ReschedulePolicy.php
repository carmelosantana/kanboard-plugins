<?php

namespace Kanboard\Plugin\SchedulerPlugin\Model;

/**
 * Pure due-date planning. No DB, no container — every input is passed in, so
 * the whole policy is deterministic and unit-testable in isolation.
 */
class ReschedulePolicy
{
    private $workingDays;
    private $holidays;
    private $declumpThreshold;
    private $respectBlocks;

    public function __construct(array $workingDays, array $holidays, $declumpThreshold, $respectBlocks)
    {
        $this->workingDays = ! empty($workingDays) ? $workingDays : array(1, 2, 3, 4, 5);
        $this->holidays = array_flip($holidays); // Y-m-d => idx, for O(1) lookup
        $this->declumpThreshold = (int) $declumpThreshold;
        $this->respectBlocks = (bool) $respectBlocks;
    }

    public function isWorkingDay($ts)
    {
        $iso = (int) date('N', $ts); // 1=Mon..7=Sun
        if (! in_array($iso, $this->workingDays, true)) {
            return false;
        }
        return ! isset($this->holidays[date('Y-m-d', $ts)]);
    }

    public function nextWorkingDay($ts)
    {
        $guard = 0;
        while (! $this->isWorkingDay($ts) && $guard < 366) {
            $ts += 86400;
            $guard++;
        }
        return $ts;
    }

    /**
     * @return array list of ['task_id','old_date','new_date','reason','move']
     */
    public function plan(array $tasks, $todayMidnight, array $blockedMap, array $dayLoad)
    {
        // Deterministic: by due date ascending, then id ascending.
        usort($tasks, function ($a, $b) {
            if ($a['date_due'] === $b['date_due']) {
                return $a['id'] <=> $b['id'];
            }
            return $a['date_due'] <=> $b['date_due'];
        });

        $load = $dayLoad; // local mutable copy
        $moves = array();

        foreach ($tasks as $task) {
            $taskId = (int) $task['id'];
            $oldDate = (int) $task['date_due'];

            if ($this->respectBlocks && ! empty($blockedMap[$taskId]['open_blockers'])) {
                $moves[] = $this->result($taskId, $oldDate, $oldDate, 'skipped-blocked', false);
                continue;
            }

            // Preserve original time-of-day across the day change.
            $oldMidnight = (int) (new \DateTime('@'.$oldDate))
                ->setTime(0, 0, 0)->getTimestamp();
            $timeOfDay = $oldDate - $oldMidnight;

            $reason = 'roll-forward';
            $targetDay = $todayMidnight; // snap-to-today

            $shifted = $this->nextWorkingDay($targetDay);
            if ($shifted !== $targetDay) {
                $reason = 'working-day';
                $targetDay = $shifted;
            }

            if ($this->declumpThreshold >= 1) {
                $guard = 0;
                while ((isset($load[date('Y-m-d', $targetDay)]) ? $load[date('Y-m-d', $targetDay)] : 0) >= ($this->declumpThreshold - 1) && $guard < 366) {
                    $targetDay = $this->nextWorkingDay($targetDay + 86400);
                    $reason = 'de-clump';
                    $guard++;
                }
            }

            $newDate = $targetDay + $timeOfDay;

            if (date('Y-m-d', $newDate) === date('Y-m-d', $oldDate)) {
                $moves[] = $this->result($taskId, $oldDate, $oldDate, 'noop', false);
                continue;
            }

            $key = date('Y-m-d', $targetDay);
            $load[$key] = (isset($load[$key]) ? $load[$key] : 0) + 1;
            $moves[] = $this->result($taskId, $oldDate, $newDate, $reason, true);
        }

        return $moves;
    }

    private function result($taskId, $old, $new, $reason, $move)
    {
        return array(
            'task_id'  => $taskId,
            'old_date' => $old,
            'new_date' => $new,
            'reason'   => $reason,
            'move'     => $move,
        );
    }
}
