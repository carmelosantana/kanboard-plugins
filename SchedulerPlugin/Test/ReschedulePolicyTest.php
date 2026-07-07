<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Model\ReschedulePolicy;
use KanboardTests\units\Base;

class ReschedulePolicyTest extends Base
{
    private function midnight($ymd)
    {
        return (int) (new \DateTime($ymd.' 00:00:00'))->getTimestamp();
    }

    public function testRollsOverdueToToday()
    {
        // Wed 2026-07-08 is a working day; a task overdue from 2026-07-01 rolls to today.
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], [], 0, true);
        $today = $this->midnight('2026-07-08');
        $tasks = [['id' => 10, 'date_due' => $this->midnight('2026-07-01')]];

        $moves = $policy->plan($tasks, $today, [], []);
        $this->assertCount(1, $moves);
        $this->assertTrue($moves[0]['move']);
        $this->assertSame('roll-forward', $moves[0]['reason']);
        $this->assertSame($today, $moves[0]['new_date']);
    }

    public function testPreservesTimeOfDay()
    {
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], [], 0, true);
        $today = $this->midnight('2026-07-08');
        $due = $this->midnight('2026-07-01') + 14 * 3600; // 14:00
        $moves = $policy->plan([['id' => 10, 'date_due' => $due]], $today, [], []);
        $this->assertSame($today + 14 * 3600, $moves[0]['new_date']);
    }

    public function testSkipsWeekendToMonday()
    {
        // Today is Sat 2026-07-11; next working day is Mon 2026-07-13.
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], [], 0, true);
        $today = $this->midnight('2026-07-11');
        $moves = $policy->plan([['id' => 10, 'date_due' => $this->midnight('2026-07-01')]], $today, [], []);
        $this->assertTrue($moves[0]['move']);
        $this->assertSame('working-day', $moves[0]['reason']);
        $this->assertSame($this->midnight('2026-07-13'), $moves[0]['new_date']);
    }

    public function testSkipsHoliday()
    {
        // Today Wed 2026-07-08 is a holiday; next working day Thu 2026-07-09.
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], ['2026-07-08'], 0, true);
        $today = $this->midnight('2026-07-08');
        $moves = $policy->plan([['id' => 10, 'date_due' => $this->midnight('2026-07-01')]], $today, [], []);
        $this->assertSame('working-day', $moves[0]['reason']);
        $this->assertSame($this->midnight('2026-07-09'), $moves[0]['new_date']);
    }

    public function testSkipsBlockedTask()
    {
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], [], 0, true);
        $today = $this->midnight('2026-07-08');
        $tasks = [['id' => 10, 'date_due' => $this->midnight('2026-07-01')]];
        $moves = $policy->plan($tasks, $today, [10 => ['open_blockers' => 1]], []);
        $this->assertFalse($moves[0]['move']);
        $this->assertSame('skipped-blocked', $moves[0]['reason']);
    }

    public function testRespectBlocksOffMovesBlockedTask()
    {
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], [], 0, false);
        $today = $this->midnight('2026-07-08');
        $tasks = [['id' => 10, 'date_due' => $this->midnight('2026-07-01')]];
        $moves = $policy->plan($tasks, $today, [10 => ['open_blockers' => 1]], []);
        $this->assertTrue($moves[0]['move']);
    }

    public function testDeclumpFillsToThresholdThenSpills()
    {
        // Every day is a working day, so only de-clump changes dates. Threshold 2 =
        // at most 2 tasks may land on a day; the 3rd overdue task spills forward.
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5, 6, 7], [], 2, true);
        $today = $this->midnight('2026-07-08');
        $tasks = [
            ['id' => 1, 'date_due' => $this->midnight('2026-07-01')],
            ['id' => 2, 'date_due' => $this->midnight('2026-07-02')],
            ['id' => 3, 'date_due' => $this->midnight('2026-07-03')],
        ];
        $moves = $policy->plan($tasks, $today, [], []);
        $this->assertSame($today, $moves[0]['new_date']);                        // 1st -> today
        $this->assertSame($today, $moves[1]['new_date']);                        // 2nd -> today (2 <= threshold)
        $this->assertSame($this->midnight('2026-07-09'), $moves[2]['new_date']); // 3rd spills forward
        $this->assertSame('de-clump', $moves[2]['reason']);
    }

    public function testDeclumpRespectsExistingDayLoad()
    {
        // Today already holds 2 scheduled tasks; threshold 2 -> today is full, so an
        // overdue task spills to the next day.
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5, 6, 7], [], 2, true);
        $today = $this->midnight('2026-07-08');
        $dayLoad = [date('Y-m-d', $today) => 2];
        $moves = $policy->plan([['id' => 1, 'date_due' => $this->midnight('2026-07-01')]], $today, [], $dayLoad);
        $this->assertSame($this->midnight('2026-07-09'), $moves[0]['new_date']);
        $this->assertSame('de-clump', $moves[0]['reason']);
    }

    public function testDeclumpDisabledWhenThresholdZero()
    {
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], [], 0, true);
        $today = $this->midnight('2026-07-08');
        $dayLoad = [date('Y-m-d', $today) => 999];
        $moves = $policy->plan([['id' => 1, 'date_due' => $this->midnight('2026-07-01')]], $today, [], $dayLoad);
        $this->assertSame($today, $moves[0]['new_date']); // no spreading
    }

    public function testDeterministicOrderByDateThenId()
    {
        $policy = new ReschedulePolicy([1, 2, 3, 4, 5], [], 1, true);
        $today = $this->midnight('2026-07-08');
        // Same due date, ids out of order — should be planned id-ascending.
        $tasks = [
            ['id' => 20, 'date_due' => $this->midnight('2026-07-01')],
            ['id' => 10, 'date_due' => $this->midnight('2026-07-01')],
        ];
        $moves = $policy->plan($tasks, $today, [], []);
        $this->assertSame(10, $moves[0]['task_id']);
        $this->assertSame(20, $moves[1]['task_id']);
    }
}
