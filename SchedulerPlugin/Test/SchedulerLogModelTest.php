<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerLogModel;
use KanboardTests\units\Base;

class SchedulerLogModelTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\SchedulerPlugin\Schema\version_1($this->container['db']->getConnection());
    }

    public function testCreateRecordFinishAndRead()
    {
        $m = new SchedulerLogModel($this->container);

        $runId = $m->createRun('cli', false);
        $this->assertGreaterThan(0, $runId);

        $m->recordMove($runId, 7, 42, 1000, 2000, 'roll-forward');
        $m->recordMove($runId, 7, 43, 1000, 3000, 'de-clump');
        $m->finishRun($runId, 2);

        $runs = $m->getRecentRuns(10);
        $this->assertCount(1, $runs);
        $this->assertSame(2, (int) $runs[0]['moved_count']);
        $this->assertSame('cli', $runs[0]['trigger']);
        $this->assertSame(0, (int) $runs[0]['is_dry_run']);
        $this->assertGreaterThan(0, (int) $runs[0]['finished_at']);

        $moves = $m->getMovesForRun($runId);
        $this->assertCount(2, $moves);
        $this->assertSame(42, (int) $moves[0]['task_id']);
        $this->assertSame('de-clump', $moves[1]['reason']);
    }

    public function testRecentRunsDescendingLimited()
    {
        $m = new SchedulerLogModel($this->container);
        $first = $m->createRun('web', false);
        $second = $m->createRun('manual', true);

        $runs = $m->getRecentRuns(1);
        $this->assertCount(1, $runs);
        $this->assertSame($second, (int) $runs[0]['id']);
    }
}
