<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\DependencyPlugin\Model\DependencyModel;
use Kanboard\Plugin\DependencyPlugin\Plugin;
use KanboardTests\units\Base;

class DependencyLinkSubscriberTest extends Base
{
    public function testCyclicLinkIsRemoved()
    {
        $p = new \Kanboard\Model\ProjectModel($this->container);
        $tc = new \Kanboard\Model\TaskCreationModel($this->container);
        $tl = new \Kanboard\Model\TaskLinkModel($this->container);
        $linkModel = new \Kanboard\Model\LinkModel($this->container);

        $blockedById = $linkModel->getByLabel('is blocked by');
        $this->assertNotEmpty($blockedById);
        $blockedById = $blockedById['id'];

        // Register the plugin the way Kanboard does at runtime: container
        // service + dispatcher listener.
        (new Plugin($this->container))->initialize();

        $pid = $p->create(array('name' => 'CycleGuard'));
        $a = $tc->create(array('project_id' => $pid, 'title' => 'A'));
        $b = $tc->create(array('project_id' => $pid, 'title' => 'B'));

        $tl->create($a, $b, $blockedById); // A is blocked by B — fine, acyclic
        $tl->create($b, $a, $blockedById); // B is blocked by A — closes the cycle

        $model = new DependencyModel($this->container);
        $this->assertFalse($model->wouldCreateCycle($a, $b) === null); // sanity

        // The B-blocked-by-A pair must have been removed by the listener.
        $links = $tl->getAll($b);
        $labels = array_map(function ($l) {
            return $l['label'].':'.$l['task_id'];
        }, $links);
        $this->assertNotContains('is blocked by:'.$a, $labels);

        // The original A-blocked-by-B link must still be intact.
        $linksA = $tl->getAll($a);
        $labelsA = array_map(function ($l) {
            return $l['label'].':'.$l['task_id'];
        }, $linksA);
        $this->assertContains('is blocked by:'.$b, $labelsA);
    }

    public function testValidLinkIsKept()
    {
        $p = new \Kanboard\Model\ProjectModel($this->container);
        $tc = new \Kanboard\Model\TaskCreationModel($this->container);
        $tl = new \Kanboard\Model\TaskLinkModel($this->container);
        $linkModel = new \Kanboard\Model\LinkModel($this->container);

        $blockedById = $linkModel->getByLabel('is blocked by');
        $this->assertNotEmpty($blockedById);
        $blockedById = $blockedById['id'];

        (new Plugin($this->container))->initialize();

        $pid = $p->create(array('name' => 'CycleGuardValid'));
        $a = $tc->create(array('project_id' => $pid, 'title' => 'A'));
        $b = $tc->create(array('project_id' => $pid, 'title' => 'B'));

        $tl->create($a, $b, $blockedById); // A is blocked by B — acyclic, survives

        $linksA = $tl->getAll($a);
        $labelsA = array_map(function ($l) {
            return $l['label'].':'.$l['task_id'];
        }, $linksA);
        $this->assertContains('is blocked by:'.$b, $labelsA);

        $linksB = $tl->getAll($b);
        $labelsB = array_map(function ($l) {
            return $l['label'].':'.$l['task_id'];
        }, $linksB);
        $this->assertContains('blocks:'.$a, $labelsB);
    }
}
