<?php

/**
 * FeatureSync — Task 04: Dry-run preview diff tests
 *
 * Tests verify:
 *   1. diffFeature() / diff() return correct add/replace/skip sets per feature.
 *   2. All diffing is READ-ONLY — row counts in every touched table are identical
 *      before and after calling diff() (NO WRITES).
 *   3. getColumnsWithTasks() correctly identifies risky columns.
 *   4. Core read-method contracts are confirmed.
 *
 * Core read methods used (verified against kanboard-1.2.47):
 *   ActionModel::getAllByProject($project_id)    app/Model/ActionModel.php:50
 *   TagModel::getAllByProject($project_id)       app/Model/TagModel.php:40
 *   ColumnModel::getAll($project_id)            app/Model/ColumnModel.php:118
 *   CategoryModel::getAll($project_id)          app/Model/CategoryModel.php:122
 *   SwimlaneModel::getAll($projectId)           app/Model/SwimlaneModel.php:133
 *   TaskFinderModel::countByColumnId(...)       app/Model/TaskFinderModel.php:358
 */

require_once 'tests/units/Base.php';

use Kanboard\Plugin\FeatureSync\Model\FeatureSyncModel;
use KanboardTests\units\Base;

class DiffTest extends Base
{
    /** @var FeatureSyncModel */
    private $featureSyncModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSyncModel = new FeatureSyncModel($this->container);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Create a project and return its id.
     */
    private function createProject($name)
    {
        $id = $this->container['projectModel']->create(array('name' => $name));
        $this->assertGreaterThan(0, $id, "Failed to create project '{$name}'");
        return $id;
    }

    /**
     * Add a tag to a project by name and return the tag id.
     */
    private function createTag($project_id, $name)
    {
        $id = $this->container['tagModel']->create($project_id, $name);
        $this->assertGreaterThan(0, $id, "Failed to create tag '{$name}'");
        return $id;
    }

    /**
     * Add a category to a project and return its id.
     */
    private function createCategory($project_id, $name)
    {
        $id = $this->container['categoryModel']->create(array(
            'project_id' => $project_id,
            'name'       => $name,
        ));
        $this->assertGreaterThan(0, $id, "Failed to create category '{$name}'");
        return $id;
    }

    /**
     * Add a swimlane to a project and return its id.
     * SwimlaneModel::create($projectId, $name, $description = '', $task_limit = 0)
     * app/Model/SwimlaneModel.php:234
     */
    private function createSwimlane($project_id, $name)
    {
        $id = $this->container['swimlaneModel']->create($project_id, $name);
        $this->assertGreaterThan(0, $id, "Failed to create swimlane '{$name}'");
        return $id;
    }

    /**
     * Add an automated action to a project and return its id.
     * ActionModel::create() requires 'params' key (can be empty array).
     * app/Model/ActionModel.php:136, app/Model/ActionParameterModel.php:85
     */
    private function createAction($project_id, $event_name, $action_name)
    {
        $id = $this->container['actionModel']->create(array(
            'project_id'  => $project_id,
            'event_name'  => $event_name,
            'action_name' => $action_name,
            'params'      => array(),   // required by ActionParameterModel::create()
        ));
        $this->assertGreaterThan(0, $id, "Failed to create action '{$event_name}::{$action_name}'");
        return $id;
    }

    /**
     * Add a column to a project (appending after defaults) and return its id.
     * ColumnModel::create($project_id, $title, $task_limit = 0, $description = '', $hide_in_dashboard = 0)
     * app/Model/ColumnModel.php:183
     */
    private function createColumn($project_id, $title)
    {
        $id = $this->container['columnModel']->create($project_id, $title);
        $this->assertGreaterThan(0, $id, "Failed to create column '{$title}'");
        return $id;
    }

    /**
     * Count rows in a table for snapshot (read-only-proof).
     */
    private function countTable($table)
    {
        return $this->container['db']->table($table)->count();
    }

    /**
     * Snapshot row counts for all tables touched by diff/preview.
     * Returns an associative array table → count.
     */
    private function snapshotCounts()
    {
        return array(
            'actions'              => $this->countTable('actions'),
            'action_has_params'    => $this->countTable('action_has_params'),
            'tags'                 => $this->countTable('tags'),
            'columns'              => $this->countTable('columns'),
            'project_has_categories' => $this->countTable('project_has_categories'),
            'swimlanes'            => $this->countTable('swimlanes'),
            'tasks'                => $this->countTable('tasks'),
        );
    }

    // ── getItemKey() tests ───────────────────────────────────────────────────

    public function testGetItemKeyForActions()
    {
        $key = $this->featureSyncModel->getItemKey(
            FeatureSyncModel::FEATURE_ACTIONS,
            array('event_name' => 'task.move.column', 'action_name' => '\\Kanboard\\Action\\TaskAssignColorColumn')
        );
        $this->assertSame('task.move.column::' . '\\Kanboard\\Action\\TaskAssignColorColumn', $key);
    }

    public function testGetItemKeyForTags()
    {
        $key = $this->featureSyncModel->getItemKey(
            FeatureSyncModel::FEATURE_TAGS,
            array('name' => 'bug')
        );
        $this->assertSame('bug', $key);
    }

    public function testGetItemKeyForColumns()
    {
        $key = $this->featureSyncModel->getItemKey(
            FeatureSyncModel::FEATURE_COLUMNS,
            array('title' => 'In Progress')
        );
        $this->assertSame('In Progress', $key);
    }

    public function testGetItemKeyForCategories()
    {
        $key = $this->featureSyncModel->getItemKey(
            FeatureSyncModel::FEATURE_CATEGORIES,
            array('name' => 'Backend')
        );
        $this->assertSame('Backend', $key);
    }

    public function testGetItemKeyForSwimlanes()
    {
        $key = $this->featureSyncModel->getItemKey(
            FeatureSyncModel::FEATURE_SWIMLANES,
            array('name' => 'Team A')
        );
        $this->assertSame('Team A', $key);
    }

    public function testGetItemKeyThrowsOnUnknownFeature()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->featureSyncModel->getItemKey('unknown_feature', array('name' => 'x'));
    }

    // ── getFeatureItems() tests ───────────────────────────────────────────────

    public function testGetFeatureItemsReturnsTagsForProject()
    {
        $srcId = $this->createProject('SrcTags');
        $this->createTag($srcId, 'alpha');
        $this->createTag($srcId, 'beta');

        $items = $this->featureSyncModel->getFeatureItems(FeatureSyncModel::FEATURE_TAGS, $srcId);
        $names = array_column($items, 'name');

        $this->assertContains('alpha', $names);
        $this->assertContains('beta', $names);
    }

    public function testGetFeatureItemsReturnsColumnsForProject()
    {
        $srcId = $this->createProject('SrcCols');
        // Projects get default columns (Backlog, Ready, Work in progress, Done) on creation.
        $items = $this->featureSyncModel->getFeatureItems(FeatureSyncModel::FEATURE_COLUMNS, $srcId);
        $this->assertNotEmpty($items, 'ColumnModel::getAll() must return at least the default columns');
        $this->assertArrayHasKey('title', $items[0]);
    }

    public function testGetFeatureItemsReturnsCategoriesForProject()
    {
        $srcId = $this->createProject('SrcCats');
        $this->createCategory($srcId, 'Frontend');

        $items = $this->featureSyncModel->getFeatureItems(FeatureSyncModel::FEATURE_CATEGORIES, $srcId);
        $names = array_column($items, 'name');
        $this->assertContains('Frontend', $names);
    }

    public function testGetFeatureItemsReturnsSwimlanesForProject()
    {
        $srcId = $this->createProject('SrcSwim');
        $this->createSwimlane($srcId, 'Sprint 1');

        $items = $this->featureSyncModel->getFeatureItems(FeatureSyncModel::FEATURE_SWIMLANES, $srcId);
        $names = array_column($items, 'name');
        $this->assertContains('Sprint 1', $names);
    }

    public function testGetFeatureItemsThrowsOnUnknownFeature()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->featureSyncModel->getFeatureItems('ghost', 1);
    }

    // ── diffFeature(): add_missing mode ──────────────────────────────────────

    /**
     * Fixture:
     *   Source tags: alpha, beta, gamma
     *   Target tags: beta, delta
     *
     * Expected add_missing result:
     *   add  = [alpha, gamma]   (in source, missing from target)
     *   skip = [beta]           (already in target)
     */
    public function testDiffFeatureTagsAddMissingMode()
    {
        $srcId = $this->createProject('DiffSrc-Tags');
        $dstId = $this->createProject('DiffDst-Tags');

        $this->createTag($srcId, 'alpha');
        $this->createTag($srcId, 'beta');
        $this->createTag($srcId, 'gamma');

        $this->createTag($dstId, 'beta');   // already present → skip
        $this->createTag($dstId, 'delta');  // only in target, not in source → irrelevant to diff

        $diff = $this->featureSyncModel->diffFeature(
            FeatureSyncModel::FEATURE_TAGS,
            $srcId,
            $dstId,
            'add_missing'
        );

        $addNames  = array_column($diff['add'],  'name');
        $skipNames = array_column($diff['skip'], 'name');

        // add: alpha, gamma (2 items)
        $this->assertCount(2, $diff['add'],  "add_missing: 2 tags should be added (alpha, gamma)");
        $this->assertContains('alpha', $addNames);
        $this->assertContains('gamma', $addNames);

        // skip: beta (1 item)
        $this->assertCount(1, $diff['skip'], "add_missing: 1 tag already present (beta)");
        $this->assertContains('beta', $skipNames);

        // replace sets must be empty in add_missing mode
        $this->assertEmpty($diff['replace_add'],    "replace_add must be empty in add_missing mode");
        $this->assertEmpty($diff['replace_remove'], "replace_remove must be empty in add_missing mode");

        // counts
        $this->assertSame(2, $diff['count_add']);
        $this->assertSame(1, $diff['count_skip']);
        $this->assertSame(0, $diff['count_replace']);
    }

    /**
     * Edge case: source has no tags → add=0, skip=0.
     */
    public function testDiffFeatureTagsAddMissingSourceEmpty()
    {
        $srcId = $this->createProject('EmptySrc-Tags');
        $dstId = $this->createProject('EmptyDst-Tags');
        $this->createTag($dstId, 'existing');

        $diff = $this->featureSyncModel->diffFeature(
            FeatureSyncModel::FEATURE_TAGS,
            $srcId,
            $dstId,
            'add_missing'
        );

        $this->assertSame(0, $diff['count_add']);
        $this->assertSame(0, $diff['count_skip']);
    }

    /**
     * Edge case: target has no tags → all source tags go into add, skip=0.
     */
    public function testDiffFeatureTagsAddMissingTargetEmpty()
    {
        $srcId = $this->createProject('FullSrc-Tags');
        $dstId = $this->createProject('FullDst-Tags');

        $this->createTag($srcId, 'alpha');
        $this->createTag($srcId, 'beta');

        $diff = $this->featureSyncModel->diffFeature(
            FeatureSyncModel::FEATURE_TAGS,
            $srcId,
            $dstId,
            'add_missing'
        );

        $this->assertSame(2, $diff['count_add']);
        $this->assertSame(0, $diff['count_skip']);
    }

    /**
     * Categories diff — add_missing mode.
     *
     * Fixture:
     *   Source: Frontend, Backend, DevOps
     *   Target: Backend, QA
     *
     * Expected: add=[Frontend, DevOps], skip=[Backend]
     */
    public function testDiffFeatureCategoriesAddMissing()
    {
        $srcId = $this->createProject('DiffSrc-Cats');
        $dstId = $this->createProject('DiffDst-Cats');

        $this->createCategory($srcId, 'Frontend');
        $this->createCategory($srcId, 'Backend');
        $this->createCategory($srcId, 'DevOps');

        $this->createCategory($dstId, 'Backend');  // already present → skip
        $this->createCategory($dstId, 'QA');       // target-only → irrelevant

        $diff = $this->featureSyncModel->diffFeature(
            FeatureSyncModel::FEATURE_CATEGORIES,
            $srcId,
            $dstId,
            'add_missing'
        );

        $addNames  = array_column($diff['add'],  'name');
        $skipNames = array_column($diff['skip'], 'name');

        $this->assertCount(2, $diff['add']);
        $this->assertContains('Frontend', $addNames);
        $this->assertContains('DevOps',   $addNames);

        $this->assertCount(1, $diff['skip']);
        $this->assertContains('Backend', $skipNames);
    }

    /**
     * Swimlanes diff — add_missing mode.
     */
    public function testDiffFeatureSwimlanesAddMissing()
    {
        $srcId = $this->createProject('DiffSrc-Swim');
        $dstId = $this->createProject('DiffDst-Swim');

        $this->createSwimlane($srcId, 'Sprint 1');
        $this->createSwimlane($srcId, 'Sprint 2');

        $this->createSwimlane($dstId, 'Sprint 1');  // already present → skip

        $diff = $this->featureSyncModel->diffFeature(
            FeatureSyncModel::FEATURE_SWIMLANES,
            $srcId,
            $dstId,
            'add_missing'
        );

        $addNames = array_column($diff['add'], 'name');

        $this->assertCount(1, $diff['add']);
        $this->assertContains('Sprint 2', $addNames);

        // skip = Sprint 1 + Default swimlane (projects get a default swimlane on creation)
        $this->assertGreaterThanOrEqual(1, $diff['count_skip']);
        $skipNames = array_column($diff['skip'], 'name');
        $this->assertContains('Sprint 1', $skipNames, "'Sprint 1' must be in skip (already in target)");
    }

    /**
     * Columns diff — add_missing mode.
     * Projects get default columns on creation; we compare titles.
     */
    public function testDiffFeatureColumnsAddMissing()
    {
        $srcId = $this->createProject('DiffSrc-Cols');
        $dstId = $this->createProject('DiffDst-Cols');

        // Add an extra column only to source.
        $this->createColumn($srcId, 'Staging');

        $srcCols = array_column(
            $this->featureSyncModel->getFeatureItems(FeatureSyncModel::FEATURE_COLUMNS, $srcId),
            'title'
        );
        $dstCols = array_column(
            $this->featureSyncModel->getFeatureItems(FeatureSyncModel::FEATURE_COLUMNS, $dstId),
            'title'
        );

        // Source has one extra column not in target.
        $onlyInSource = array_diff($srcCols, $dstCols);
        $this->assertContains('Staging', $onlyInSource, "'Staging' should only be in source");

        $diff = $this->featureSyncModel->diffFeature(
            FeatureSyncModel::FEATURE_COLUMNS,
            $srcId,
            $dstId,
            'add_missing'
        );

        $addTitles = array_column($diff['add'], 'title');
        $this->assertContains('Staging', $addTitles, "'Staging' should appear in the add set");
        $this->assertGreaterThan(0, $diff['count_skip'], "Default columns common to both projects should be in skip");
    }

    // ── diffFeature(): replace mode ───────────────────────────────────────────

    /**
     * In replace mode: add = all source items, replace = all target items, skip = 0.
     *
     * Fixture:
     *   Source tags: alpha, beta
     *   Target tags: gamma, delta
     *
     * Expected: count_add=2 (all source), count_replace=2 (all target), count_skip=0
     */
    public function testDiffFeatureTagsReplaceMode()
    {
        $srcId = $this->createProject('ReplaceSrc-Tags');
        $dstId = $this->createProject('ReplaceDst-Tags');

        $this->createTag($srcId, 'alpha');
        $this->createTag($srcId, 'beta');

        $this->createTag($dstId, 'gamma');
        $this->createTag($dstId, 'delta');

        $diff = $this->featureSyncModel->diffFeature(
            FeatureSyncModel::FEATURE_TAGS,
            $srcId,
            $dstId,
            'replace'
        );

        // replace mode: full source set is added, full target set would be removed
        $this->assertSame(2, $diff['count_add'],     "replace: count_add = count of source items");
        $this->assertSame(0, $diff['count_skip'],    "replace: count_skip must always be 0");
        $this->assertSame(2, $diff['count_replace'], "replace: count_replace = count of target items");

        $this->assertCount(2, $diff['replace_add'],    "replace_add = full source set");
        $this->assertCount(2, $diff['replace_remove'], "replace_remove = full target set");

        // add/skip arrays must be empty in replace mode
        $this->assertEmpty($diff['add'],  "add array must be empty in replace mode");
        $this->assertEmpty($diff['skip'], "skip array must be empty in replace mode");
    }

    /**
     * Replace mode with source and target having overlapping tags:
     * count_replace still equals the TARGET count, not the diff.
     */
    public function testDiffFeatureTagsReplaceModeWithOverlap()
    {
        $srcId = $this->createProject('ReplaceOverlapSrc');
        $dstId = $this->createProject('ReplaceOverlapDst');

        $this->createTag($srcId, 'alpha');
        $this->createTag($srcId, 'shared');

        $this->createTag($dstId, 'shared');
        $this->createTag($dstId, 'gamma');
        $this->createTag($dstId, 'delta');

        $diff = $this->featureSyncModel->diffFeature(
            FeatureSyncModel::FEATURE_TAGS,
            $srcId,
            $dstId,
            'replace'
        );

        $this->assertSame(2, $diff['count_add'],     "count_add = source item count (2)");
        $this->assertSame(3, $diff['count_replace'], "count_replace = target item count (3)");
        $this->assertSame(0, $diff['count_skip']);
    }

    // ── diff() (multi-target) ─────────────────────────────────────────────────

    /**
     * diff() returns a map keyed by target project id.
     */
    public function testDiffReturnsMapKeyedByTargetId()
    {
        $srcId  = $this->createProject('MultiSrc');
        $dstId1 = $this->createProject('MultiDst1');
        $dstId2 = $this->createProject('MultiDst2');

        $this->createTag($srcId, 'tag-x');

        $result = $this->featureSyncModel->diff(
            $srcId,
            array($dstId1, $dstId2),
            array(FeatureSyncModel::FEATURE_TAGS),
            'add_missing'
        );

        $this->assertArrayHasKey($dstId1, $result);
        $this->assertArrayHasKey($dstId2, $result);
        $this->assertArrayHasKey(FeatureSyncModel::FEATURE_TAGS, $result[$dstId1]);
        $this->assertArrayHasKey(FeatureSyncModel::FEATURE_TAGS, $result[$dstId2]);
    }

    /**
     * diff() with multiple features returns diff for each feature per target.
     */
    public function testDiffReturnsAllRequestedFeatures()
    {
        $srcId = $this->createProject('MultiFeatureSrc');
        $dstId = $this->createProject('MultiFeatureDst');

        $this->createTag($srcId, 'my-tag');
        $this->createCategory($srcId, 'My-Cat');

        $result = $this->featureSyncModel->diff(
            $srcId,
            array($dstId),
            array(FeatureSyncModel::FEATURE_TAGS, FeatureSyncModel::FEATURE_CATEGORIES),
            'add_missing'
        );

        $this->assertArrayHasKey(FeatureSyncModel::FEATURE_TAGS,       $result[$dstId]);
        $this->assertArrayHasKey(FeatureSyncModel::FEATURE_CATEGORIES,  $result[$dstId]);

        $tagDiff = $result[$dstId][FeatureSyncModel::FEATURE_TAGS];
        $this->assertSame(1, $tagDiff['count_add']);
    }

    // ── NO WRITES PROOF ──────────────────────────────────────────────────────

    /**
     * PROVE NO WRITES: row counts in all affected tables must be IDENTICAL before
     * and after calling diff() for both add_missing and replace modes.
     *
     * This test creates a known fixture, snapshots table counts, calls diff() with
     * both modes, then snapshots again and asserts counts are unchanged.
     */
    public function testDiffDoesNotWriteToDatabase()
    {
        $srcId = $this->createProject('NoWriteSrc');
        $dstId = $this->createProject('NoWriteDst');

        // Populate source with items across all features.
        $this->createTag($srcId, 'rw-tag-1');
        $this->createTag($srcId, 'rw-tag-2');
        $this->createTag($dstId, 'rw-tag-1');   // partial overlap

        $this->createCategory($srcId, 'rw-cat-A');
        $this->createCategory($srcId, 'rw-cat-B');

        $this->createSwimlane($srcId, 'rw-swim-1');

        $this->createAction($srcId, 'task.move.column', '\\Kanboard\\Action\\TaskAssignColorColumn');

        // Snapshot before.
        $before = $this->snapshotCounts();

        // Call diff() for all features, add_missing mode.
        $this->featureSyncModel->diff(
            $srcId,
            array($dstId),
            array(
                FeatureSyncModel::FEATURE_TAGS,
                FeatureSyncModel::FEATURE_CATEGORIES,
                FeatureSyncModel::FEATURE_COLUMNS,
                FeatureSyncModel::FEATURE_SWIMLANES,
                FeatureSyncModel::FEATURE_ACTIONS,
            ),
            'add_missing'
        );

        // Call diff() again for replace mode.
        $this->featureSyncModel->diff(
            $srcId,
            array($dstId),
            array(
                FeatureSyncModel::FEATURE_TAGS,
                FeatureSyncModel::FEATURE_CATEGORIES,
                FeatureSyncModel::FEATURE_COLUMNS,
                FeatureSyncModel::FEATURE_SWIMLANES,
                FeatureSyncModel::FEATURE_ACTIONS,
            ),
            'replace'
        );

        // Snapshot after.
        $after = $this->snapshotCounts();

        // Assert every table row count is identical (PROVE NO WRITES).
        foreach ($before as $table => $countBefore) {
            $this->assertSame(
                $countBefore,
                $after[$table],
                "NO-WRITE VIOLATION: table '{$table}' had {$countBefore} rows before diff() but {$after[$table]} after — diff() must be read-only"
            );
        }
    }

    /**
     * PROVE NO WRITES for diffFeature() individually on all five features.
     */
    public function testDiffFeatureDoesNotWriteToDatabase()
    {
        $srcId = $this->createProject('NoWriteSingle-Src');
        $dstId = $this->createProject('NoWriteSingle-Dst');

        $this->createTag($srcId, 'nw-tag');
        $this->createCategory($srcId, 'nw-cat');
        $this->createSwimlane($srcId, 'nw-swim');
        $this->createAction($srcId, 'task.open', '\\Kanboard\\Action\\TaskAssignColorColumn');

        $before = $this->snapshotCounts();

        foreach (array('add_missing', 'replace') as $mode) {
            foreach (array(
                FeatureSyncModel::FEATURE_TAGS,
                FeatureSyncModel::FEATURE_CATEGORIES,
                FeatureSyncModel::FEATURE_COLUMNS,
                FeatureSyncModel::FEATURE_SWIMLANES,
                FeatureSyncModel::FEATURE_ACTIONS,
            ) as $feature) {
                $this->featureSyncModel->diffFeature($feature, $srcId, $dstId, $mode);
            }
        }

        $after = $this->snapshotCounts();

        foreach ($before as $table => $countBefore) {
            $this->assertSame(
                $countBefore,
                $after[$table],
                "NO-WRITE VIOLATION (diffFeature): table '{$table}' count changed from {$countBefore} to {$after[$table]}"
            );
        }
    }

    // ── getColumnsWithTasks() — risk detection ───────────────────────────────

    /**
     * getColumnsWithTasks() returns empty array when no columns have tasks.
     */
    public function testGetColumnsWithTasksNoTasks()
    {
        $projectId = $this->createProject('ColRisk-NoTasks');
        $columns   = $this->featureSyncModel->getFeatureItems(FeatureSyncModel::FEATURE_COLUMNS, $projectId);

        $risky = $this->featureSyncModel->getColumnsWithTasks($projectId, $columns);
        $this->assertEmpty($risky, "No tasks in any column → risky map should be empty");
    }

    /**
     * getColumnsWithTasks() identifies columns that have open tasks.
     *
     * Uses TaskFinderModel::countByColumnId() — verified read-only.
     * app/Model/TaskFinderModel.php:358
     */
    public function testGetColumnsWithTasksDetectsRiskyColumns()
    {
        $projectId = $this->createProject('ColRisk-WithTasks');
        $columns   = $this->featureSyncModel->getFeatureItems(FeatureSyncModel::FEATURE_COLUMNS, $projectId);

        // Get first column id to place a task in.
        $this->assertNotEmpty($columns, "Project must have columns after creation");
        $firstColumn = $columns[0];
        $columnId    = (int) $firstColumn['id'];

        // Create a task in the first column.
        $swimlane = $this->container['swimlaneModel']->getFirstActiveSwimlane($projectId);
        $swimlaneId = $swimlane ? (int)$swimlane['id'] : 0;

        $taskId = $this->container['taskCreationModel']->create(array(
            'project_id'  => $projectId,
            'title'       => 'Risk test task',
            'column_id'   => $columnId,
            'swimlane_id' => $swimlaneId,
        ));
        $this->assertGreaterThan(0, $taskId, "Failed to create task for risk test");

        // Verify countByColumnId() reads the task correctly (READ-ONLY).
        $count = $this->container['taskFinderModel']->countByColumnId($projectId, $columnId);
        $this->assertSame(1, $count, "TaskFinderModel::countByColumnId() must return 1 for this column");

        // Now test getColumnsWithTasks().
        $risky = $this->featureSyncModel->getColumnsWithTasks($projectId, $columns);

        $this->assertArrayHasKey($columnId, $risky, "Column with a task must appear in risky map");
        $this->assertSame(1, $risky[$columnId]['task_count']);
        $this->assertSame($firstColumn['title'], $risky[$columnId]['column']['title']);
    }

    /**
     * PROVE NO WRITES: getColumnsWithTasks() must not write to the database.
     */
    public function testGetColumnsWithTasksDoesNotWrite()
    {
        $projectId = $this->createProject('ColRisk-NoWrite');
        $columns   = $this->featureSyncModel->getFeatureItems(FeatureSyncModel::FEATURE_COLUMNS, $projectId);

        $before = $this->snapshotCounts();

        $this->featureSyncModel->getColumnsWithTasks($projectId, $columns);

        $after = $this->snapshotCounts();

        foreach ($before as $table => $countBefore) {
            $this->assertSame(
                $countBefore,
                $after[$table],
                "NO-WRITE VIOLATION (getColumnsWithTasks): table '{$table}' count changed"
            );
        }
    }

    // ── Core read method contracts ────────────────────────────────────────────

    /**
     * Confirm ActionModel::getAllByProject() exists and returns an array.
     * Core: app/Model/ActionModel.php:50
     */
    public function testCoreActionModelGetAllByProject()
    {
        $projectId = $this->createProject('CoreAction-Test');
        $result    = $this->container['actionModel']->getAllByProject($projectId);
        $this->assertIsArray($result, 'ActionModel::getAllByProject() must return an array');
    }

    /**
     * Confirm TagModel::getAllByProject() exists and returns an array.
     * Core: app/Model/TagModel.php:40
     */
    public function testCoreTagModelGetAllByProject()
    {
        $projectId = $this->createProject('CoreTag-Test');
        $result    = $this->container['tagModel']->getAllByProject($projectId);
        $this->assertIsArray($result, 'TagModel::getAllByProject() must return an array');
    }

    /**
     * Confirm ColumnModel::getAll() exists and returns columns with 'title' key.
     * Core: app/Model/ColumnModel.php:118
     */
    public function testCoreColumnModelGetAll()
    {
        $projectId = $this->createProject('CoreCol-Test');
        $result    = $this->container['columnModel']->getAll($projectId);
        $this->assertIsArray($result, 'ColumnModel::getAll() must return an array');
        $this->assertNotEmpty($result, 'ColumnModel::getAll() must return at least default columns');
        $this->assertArrayHasKey('title', $result[0], "Each column row must have a 'title' key");
    }

    /**
     * Confirm CategoryModel::getAll() exists and returns an array.
     * Core: app/Model/CategoryModel.php:122
     */
    public function testCoreCategoryModelGetAll()
    {
        $projectId = $this->createProject('CoreCat-Test');
        $result    = $this->container['categoryModel']->getAll($projectId);
        $this->assertIsArray($result, 'CategoryModel::getAll() must return an array');
    }

    /**
     * Confirm SwimlaneModel::getAll() exists and returns rows with 'name' key.
     * Core: app/Model/SwimlaneModel.php:133
     */
    public function testCoreSwimlaneModelGetAll()
    {
        $projectId = $this->createProject('CoreSwim-Test');
        $this->createSwimlane($projectId, 'Lane-X');
        $result = $this->container['swimlaneModel']->getAll($projectId);
        $this->assertIsArray($result, 'SwimlaneModel::getAll() must return an array');
        $names = array_column($result, 'name');
        $this->assertContains('Lane-X', $names);
    }

    /**
     * Confirm TaskFinderModel::countByColumnId() exists and returns an integer.
     * Core: app/Model/TaskFinderModel.php:358
     */
    public function testCoreTaskFinderModelCountByColumnId()
    {
        $projectId = $this->createProject('CoreTaskFinder-Test');
        $columns   = $this->container['columnModel']->getAll($projectId);
        $this->assertNotEmpty($columns);

        $count = $this->container['taskFinderModel']->countByColumnId($projectId, (int)$columns[0]['id']);
        $this->assertIsInt($count, 'TaskFinderModel::countByColumnId() must return an integer');
        $this->assertSame(0, $count, 'A fresh project column must have 0 tasks');
    }
}
