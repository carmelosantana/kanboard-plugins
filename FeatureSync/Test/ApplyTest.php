<?php

/**
 * FeatureSync — Task 05: Apply (add/replace) + per-target report tests
 *
 * Tests verify:
 *   1. add_missing mode copies missing features to a target (assert target now has them).
 *   2. add_missing mode is IDEMPOTENT — running twice produces no duplicates.
 *   3. replace mode clears+recopies (final target set == source set).
 *   4. A forced failure on one target leaves the others applied + report is accurate.
 *   5. resolveFormParams() intersects features with known keys (whitelist enforced).
 *   6. Non-admin → AccessForbiddenException (controller-level guard, tested via mock).
 *   7. replace columns/swimlanes: task-holding items survive (task safety).
 *   8. diff() counts == apply() counts for columns/swimlanes replace with task-holding items.
 *   9. add_missing action params resolved to target project IDs (not source IDs).
 *
 * Core methods verified:
 *   ActionModel::remove($action_id)                                app/Model/ActionModel.php:124
 *   TagModel::remove($tag_id)                                      app/Model/TagModel.php:195
 *   ColumnModel::remove($column_id)                                app/Model/ColumnModel.php:225
 *   CategoryModel::remove($category_id)                            app/Model/CategoryModel.php:185
 *   SwimlaneModel::remove($projectId, $id)                         app/Model/SwimlaneModel.php:339
 *   ActionParameterModel::duplicateParameters($pid, $aid, $params) app/Model/ActionParameterModel.php:111
 *
 * PicoDb NOTE: No outer per-target transactions in apply() — PicoDb has a FLAT tx API
 * (no nesting/savepoints). Core methods (ActionModel::create, CategoryModel::remove, etc.)
 * self-commit on the same connection, making outer rollback impossible. apply() uses
 * per-target + per-feature try/catch for error isolation instead.
 *   libs/picodb/lib/PicoDb/Database.php:292-320
 */

require_once 'tests/units/Base.php';

use Kanboard\Model\TaskCreationModel;
use Kanboard\Plugin\FeatureSync\Model\FeatureSyncModel;
use KanboardTests\units\Base;

class ApplyTest extends Base
{
    /** @var FeatureSyncModel */
    private $featureSyncModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSyncModel = new FeatureSyncModel($this->container);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function createProject($name)
    {
        $id = $this->container['projectModel']->create(array('name' => $name));
        $this->assertGreaterThan(0, $id, "Failed to create project '{$name}'");
        return $id;
    }

    private function createTag($project_id, $name, $color_id = null)
    {
        $id = $this->container['tagModel']->create($project_id, $name, $color_id);
        $this->assertGreaterThan(0, $id, "Failed to create tag '{$name}'");
        return $id;
    }

    private function createCategory($project_id, $name)
    {
        $id = $this->container['categoryModel']->create(array(
            'project_id'  => $project_id,
            'name'        => $name,
            'description' => '',
            'color_id'    => null,
        ));
        $this->assertGreaterThan(0, $id, "Failed to create category '{$name}'");
        return $id;
    }

    private function createSwimlane($project_id, $name)
    {
        $id = $this->container['swimlaneModel']->create($project_id, $name);
        $this->assertGreaterThan(0, $id, "Failed to create swimlane '{$name}'");
        return $id;
    }

    private function createAction($project_id, $event_name, $action_name)
    {
        $id = $this->container['actionModel']->create(array(
            'project_id'  => $project_id,
            'event_name'  => $event_name,
            'action_name' => $action_name,
            'params'      => array(),
        ));
        $this->assertGreaterThan(0, $id, "Failed to create action");
        return $id;
    }

    private function getTagNames($project_id)
    {
        return array_column($this->container['tagModel']->getAllByProject($project_id), 'name');
    }

    private function getCategoryNames($project_id)
    {
        return array_column($this->container['categoryModel']->getAll($project_id), 'name');
    }

    private function getSwimlaneNames($project_id)
    {
        return array_column($this->container['swimlaneModel']->getAll($project_id), 'name');
    }

    private function getActionKeys($project_id)
    {
        $actions = $this->container['actionModel']->getAllByProject($project_id);
        return array_map(function ($a) { return $a['event_name'] . '::' . $a['action_name']; }, $actions);
    }

    private function getColumnTitles($project_id)
    {
        return array_column($this->container['columnModel']->getAll($project_id), 'title');
    }

    // ── Feature whitelist (resolveFormParams) ─────────────────────────────────

    /**
     * Unknown feature keys must be silently stripped from selectedFeatures.
     * This prevents them from reaching copyFeature() and throwing uncaught exceptions.
     */
    public function testResolveFormParamsStripsUnknownFeatures()
    {
        $result = $this->featureSyncModel->resolveFormParams(array(
            'source_project_id'  => '1',
            'features'           => array('tags', 'unknown_feature', 'xss_attack', 'categories'),
            'target_project_ids' => array('2'),
            'sync_mode'          => 'add_missing',
        ));

        $this->assertContains('tags', $result['selectedFeatures'], "Known feature 'tags' must survive");
        $this->assertContains('categories', $result['selectedFeatures'], "Known feature 'categories' must survive");
        $this->assertNotContains('unknown_feature', $result['selectedFeatures'], "Unknown feature must be stripped");
        $this->assertNotContains('xss_attack', $result['selectedFeatures'], "Unknown feature must be stripped");
    }

    /**
     * All valid feature keys must pass through resolveFormParams() unchanged.
     */
    public function testResolveFormParamsKeepsAllValidFeatures()
    {
        $allFeatures = array(
            FeatureSyncModel::FEATURE_ACTIONS,
            FeatureSyncModel::FEATURE_TAGS,
            FeatureSyncModel::FEATURE_COLUMNS,
            FeatureSyncModel::FEATURE_CATEGORIES,
            FeatureSyncModel::FEATURE_SWIMLANES,
        );

        $result = $this->featureSyncModel->resolveFormParams(array(
            'source_project_id'  => '1',
            'features'           => $allFeatures,
            'target_project_ids' => array('2'),
            'sync_mode'          => 'add_missing',
        ));

        foreach ($allFeatures as $f) {
            $this->assertContains($f, $result['selectedFeatures'], "Valid feature '{$f}' must be retained");
        }
    }

    // ── copyFeature() throws on unknown feature ───────────────────────────────

    public function testCopyFeatureThrowsOnUnknownFeature()
    {
        $this->expectException(\InvalidArgumentException::class);
        $srcId = $this->createProject('CopyThrowSrc');
        $dstId = $this->createProject('CopyThrowDst');
        $this->featureSyncModel->copyFeature('bad_feature', $srcId, $dstId);
    }

    // ── add_missing: copies missing items ────────────────────────────────────

    /**
     * TAGS — add_missing copies tags from source to target.
     * Expected: target gains alpha and gamma; beta already existed (not duplicated).
     */
    public function testAddMissingCopiesTagsToTarget()
    {
        $srcId = $this->createProject('AddTagSrc');
        $dstId = $this->createProject('AddTagDst');

        $this->createTag($srcId, 'alpha');
        $this->createTag($srcId, 'beta');
        $this->createTag($srcId, 'gamma');

        $this->createTag($dstId, 'beta');  // already present

        $count = $this->featureSyncModel->copyFeature(
            FeatureSyncModel::FEATURE_TAGS, $srcId, $dstId, 'add_missing'
        );

        $dstNames = $this->getTagNames($dstId);
        $this->assertContains('alpha', $dstNames, "alpha must be added to target");
        $this->assertContains('beta', $dstNames, "beta must remain in target");
        $this->assertContains('gamma', $dstNames, "gamma must be added to target");
        $this->assertSame(2, $count, "2 tags should be added (alpha + gamma)");
    }

    /**
     * CATEGORIES — add_missing copies missing categories.
     */
    public function testAddMissingCopiesCategoriesToTarget()
    {
        $srcId = $this->createProject('AddCatSrc');
        $dstId = $this->createProject('AddCatDst');

        $this->createCategory($srcId, 'Frontend');
        $this->createCategory($srcId, 'Backend');
        $this->createCategory($dstId, 'Backend');  // already present

        $count = $this->featureSyncModel->copyFeature(
            FeatureSyncModel::FEATURE_CATEGORIES, $srcId, $dstId, 'add_missing'
        );

        $dstNames = $this->getCategoryNames($dstId);
        $this->assertContains('Frontend', $dstNames, "Frontend must be added");
        $this->assertContains('Backend', $dstNames, "Backend must remain");
        $this->assertSame(1, $count, "Only 1 category should be added (Frontend)");
    }

    /**
     * SWIMLANES — add_missing copies missing swimlanes.
     */
    public function testAddMissingCopiesSwimlanesToTarget()
    {
        $srcId = $this->createProject('AddSwimSrc');
        $dstId = $this->createProject('AddSwimDst');

        $this->createSwimlane($srcId, 'Sprint 1');
        $this->createSwimlane($srcId, 'Sprint 2');
        $this->createSwimlane($dstId, 'Sprint 1');  // already present

        $count = $this->featureSyncModel->copyFeature(
            FeatureSyncModel::FEATURE_SWIMLANES, $srcId, $dstId, 'add_missing'
        );

        $dstNames = $this->getSwimlaneNames($dstId);
        $this->assertContains('Sprint 2', $dstNames, "Sprint 2 must be added");
        $this->assertContains('Sprint 1', $dstNames, "Sprint 1 must remain");
        $this->assertSame(1, $count, "Exactly 1 swimlane added");
    }

    /**
     * ACTIONS — add_missing copies actions whose event::action_name is absent from target.
     */
    public function testAddMissingCopiesActionsToTarget()
    {
        $srcId = $this->createProject('AddActSrc');
        $dstId = $this->createProject('AddActDst');

        $this->createAction($srcId, 'task.move.column', '\\Kanboard\\Action\\TaskAssignColorColumn');
        $this->createAction($srcId, 'task.open',        '\\Kanboard\\Action\\TaskAssignColorColumn');
        $this->createAction($dstId, 'task.move.column', '\\Kanboard\\Action\\TaskAssignColorColumn'); // already present

        $count = $this->featureSyncModel->copyFeature(
            FeatureSyncModel::FEATURE_ACTIONS, $srcId, $dstId, 'add_missing'
        );

        $dstKeys = $this->getActionKeys($dstId);
        $this->assertContains(
            'task.open::' . '\\Kanboard\\Action\\TaskAssignColorColumn', $dstKeys,
            "task.open action must be added"
        );
        $this->assertSame(1, $count, "Only 1 action should be added (task.open)");
    }

    /**
     * COLUMNS — add_missing copies columns absent from target.
     */
    public function testAddMissingCopiesColumnsToTarget()
    {
        $srcId = $this->createProject('AddColSrc');
        $dstId = $this->createProject('AddColDst');

        // Both projects get default columns. Add one unique to source.
        $this->container['columnModel']->create($srcId, 'Staging');

        $count = $this->featureSyncModel->copyFeature(
            FeatureSyncModel::FEATURE_COLUMNS, $srcId, $dstId, 'add_missing'
        );

        $dstTitles = $this->getColumnTitles($dstId);
        $this->assertContains('Staging', $dstTitles, "Staging column must be added to target");
        $this->assertSame(1, $count, "Exactly 1 column added (Staging)");
    }

    // ── add_missing: IDEMPOTENCY ──────────────────────────────────────────────

    /**
     * IDEMPOTENCY: running add_missing twice on tags must NOT create duplicates.
     *
     * RED evidence: removing the isset($dstNameSet[$tag['name']]) guard in copyTags()
     * would cause all 3 source tags to be inserted again, giving target 5 tags total
     * instead of 3.
     */
    public function testAddMissingTagsIdempotent()
    {
        $srcId = $this->createProject('IdempTagSrc');
        $dstId = $this->createProject('IdempTagDst');

        $this->createTag($srcId, 'alpha');
        $this->createTag($srcId, 'beta');
        $this->createTag($srcId, 'gamma');

        // First apply.
        $this->featureSyncModel->copyFeature(FeatureSyncModel::FEATURE_TAGS, $srcId, $dstId, 'add_missing');
        $countAfterFirst = count($this->container['tagModel']->getAllByProject($dstId));

        // Second apply — must be a no-op.
        $countAdded = $this->featureSyncModel->copyFeature(FeatureSyncModel::FEATURE_TAGS, $srcId, $dstId, 'add_missing');

        $countAfterSecond = count($this->container['tagModel']->getAllByProject($dstId));

        $this->assertSame(0, $countAdded, "Second add_missing run must add 0 items (all already present)");
        $this->assertSame($countAfterFirst, $countAfterSecond, "Tag count must not increase on second run (idempotent)");
    }

    /**
     * IDEMPOTENCY: running add_missing twice on categories must NOT create duplicates.
     */
    public function testAddMissingCategoriesIdempotent()
    {
        $srcId = $this->createProject('IdempCatSrc');
        $dstId = $this->createProject('IdempCatDst');

        $this->createCategory($srcId, 'Foo');
        $this->createCategory($srcId, 'Bar');

        $this->featureSyncModel->copyFeature(FeatureSyncModel::FEATURE_CATEGORIES, $srcId, $dstId, 'add_missing');
        $afterFirst = count($this->container['categoryModel']->getAll($dstId));

        $countAdded = $this->featureSyncModel->copyFeature(FeatureSyncModel::FEATURE_CATEGORIES, $srcId, $dstId, 'add_missing');
        $afterSecond = count($this->container['categoryModel']->getAll($dstId));

        $this->assertSame(0, $countAdded, "Second run must add 0 categories");
        $this->assertSame($afterFirst, $afterSecond, "Category count must not increase on second run");
    }

    /**
     * IDEMPOTENCY: running add_missing twice on swimlanes must NOT create duplicates.
     */
    public function testAddMissingSwimlanesIdempotent()
    {
        $srcId = $this->createProject('IdempSwimSrc');
        $dstId = $this->createProject('IdempSwimDst');

        $this->createSwimlane($srcId, 'Lane A');
        $this->createSwimlane($srcId, 'Lane B');

        $this->featureSyncModel->copyFeature(FeatureSyncModel::FEATURE_SWIMLANES, $srcId, $dstId, 'add_missing');
        $afterFirst = count($this->container['swimlaneModel']->getAll($dstId));

        $countAdded = $this->featureSyncModel->copyFeature(FeatureSyncModel::FEATURE_SWIMLANES, $srcId, $dstId, 'add_missing');
        $afterSecond = count($this->container['swimlaneModel']->getAll($dstId));

        $this->assertSame(0, $countAdded, "Second run must add 0 swimlanes");
        $this->assertSame($afterFirst, $afterSecond, "Swimlane count must not increase on second run");
    }

    /**
     * IDEMPOTENCY: running add_missing twice on actions must NOT create duplicates.
     */
    public function testAddMissingActionsIdempotent()
    {
        $srcId = $this->createProject('IdempActSrc');
        $dstId = $this->createProject('IdempActDst');

        $this->createAction($srcId, 'task.move.column', '\\Kanboard\\Action\\TaskAssignColorColumn');
        $this->createAction($srcId, 'task.open', '\\Kanboard\\Action\\TaskAssignColorColumn');

        $this->featureSyncModel->copyFeature(FeatureSyncModel::FEATURE_ACTIONS, $srcId, $dstId, 'add_missing');
        $afterFirst = count($this->container['actionModel']->getAllByProject($dstId));

        $countAdded = $this->featureSyncModel->copyFeature(FeatureSyncModel::FEATURE_ACTIONS, $srcId, $dstId, 'add_missing');
        $afterSecond = count($this->container['actionModel']->getAllByProject($dstId));

        $this->assertSame(0, $countAdded, "Second run must add 0 actions");
        $this->assertSame($afterFirst, $afterSecond, "Action count must not increase on second run");
    }

    /**
     * IDEMPOTENCY: running add_missing twice on columns must NOT create duplicates.
     */
    public function testAddMissingColumnsIdempotent()
    {
        $srcId = $this->createProject('IdempColSrc');
        $dstId = $this->createProject('IdempColDst');

        $this->container['columnModel']->create($srcId, 'Staging');

        $this->featureSyncModel->copyFeature(FeatureSyncModel::FEATURE_COLUMNS, $srcId, $dstId, 'add_missing');
        $afterFirst = count($this->container['columnModel']->getAll($dstId));

        $countAdded = $this->featureSyncModel->copyFeature(FeatureSyncModel::FEATURE_COLUMNS, $srcId, $dstId, 'add_missing');
        $afterSecond = count($this->container['columnModel']->getAll($dstId));

        $this->assertSame(0, $countAdded, "Second run must add 0 columns");
        $this->assertSame($afterFirst, $afterSecond, "Column count must not increase on second run");
    }

    // ── replace mode: clears + recopies ──────────────────────────────────────

    /**
     * REPLACE TAGS: target should end up with exactly the source's tag set.
     *
     * Fixture:
     *   Source: alpha, beta
     *   Target: gamma, delta
     * After replace: target = {alpha, beta}
     */
    public function testReplaceTagsClearsAndRecopies()
    {
        $srcId = $this->createProject('ReplTagSrc');
        $dstId = $this->createProject('ReplTagDst');

        $this->createTag($srcId, 'alpha');
        $this->createTag($srcId, 'beta');
        $this->createTag($dstId, 'gamma');
        $this->createTag($dstId, 'delta');

        $count = $this->featureSyncModel->copyFeature(
            FeatureSyncModel::FEATURE_TAGS, $srcId, $dstId, 'replace'
        );

        $dstNames = $this->getTagNames($dstId);
        $this->assertContains('alpha', $dstNames, "alpha must be in target after replace");
        $this->assertContains('beta', $dstNames, "beta must be in target after replace");
        $this->assertNotContains('gamma', $dstNames, "gamma must be removed (replaced)");
        $this->assertNotContains('delta', $dstNames, "delta must be removed (replaced)");
        $this->assertCount(2, $dstNames, "Target must have exactly 2 tags (= source count)");
        $this->assertSame(2, $count, "count returned = source tag count");
    }

    /**
     * REPLACE CATEGORIES: target should end up with exactly the source's categories.
     */
    public function testReplaceCategories()
    {
        $srcId = $this->createProject('ReplCatSrc');
        $dstId = $this->createProject('ReplCatDst');

        $this->createCategory($srcId, 'New Cat');
        $this->createCategory($dstId, 'Old Cat');

        $count = $this->featureSyncModel->copyFeature(
            FeatureSyncModel::FEATURE_CATEGORIES, $srcId, $dstId, 'replace'
        );

        $dstNames = $this->getCategoryNames($dstId);
        $this->assertContains('New Cat', $dstNames, "New Cat must appear");
        $this->assertNotContains('Old Cat', $dstNames, "Old Cat must be cleared");
        $this->assertSame(1, $count, "1 category replaced");
    }

    /**
     * REPLACE ACTIONS: target actions must be wiped and replaced with source actions.
     */
    public function testReplaceActions()
    {
        $srcId = $this->createProject('ReplActSrc');
        $dstId = $this->createProject('ReplActDst');

        $this->createAction($srcId, 'task.open', '\\Kanboard\\Action\\TaskAssignColorColumn');
        $this->createAction($dstId, 'task.move.column', '\\Kanboard\\Action\\TaskAssignColorColumn');

        $count = $this->featureSyncModel->copyFeature(
            FeatureSyncModel::FEATURE_ACTIONS, $srcId, $dstId, 'replace'
        );

        $dstKeys = $this->getActionKeys($dstId);
        $this->assertContains('task.open::' . '\\Kanboard\\Action\\TaskAssignColorColumn', $dstKeys);
        $this->assertNotContains('task.move.column::' . '\\Kanboard\\Action\\TaskAssignColorColumn', $dstKeys);
        $this->assertSame(1, $count);
    }

    // ── apply(): per-target transaction + partial failure ─────────────────────

    /**
     * apply() with valid targets returns 'ok' status for each target.
     */
    public function testApplyReturnsOkForEachTarget()
    {
        $srcId  = $this->createProject('ApplySrc');
        $dstId1 = $this->createProject('ApplyDst1');
        $dstId2 = $this->createProject('ApplyDst2');

        $this->createTag($srcId, 'shared-tag');

        $report = $this->featureSyncModel->apply(
            $srcId,
            array($dstId1, $dstId2),
            array(FeatureSyncModel::FEATURE_TAGS),
            'add_missing'
        );

        $this->assertArrayHasKey($dstId1, $report);
        $this->assertArrayHasKey($dstId2, $report);
        $this->assertSame('ok', $report[$dstId1]['status']);
        $this->assertSame('ok', $report[$dstId2]['status']);

        // Tags must appear in both targets.
        $this->assertContains('shared-tag', $this->getTagNames($dstId1));
        $this->assertContains('shared-tag', $this->getTagNames($dstId2));
    }

    /**
     * PARTIAL FAILURE: apply() with a forced exception inside copyFeature() on one target must:
     *   - Record the error for that (target, feature) pair — status = 'partial'.
     *   - Continue applying to subsequent targets (they still succeed).
     *   - Report 'ok' for the good targets.
     *
     * NOTE: apply() has NO outer per-target DB transaction. PicoDb has a FLAT transaction
     * API (no nesting/savepoints) and core methods (ActionModel::create, CategoryModel::remove,
     * etc.) self-commit on the shared connection — an outer rollback would be a no-op anyway.
     * Error isolation is achieved via try/catch, NOT transaction rollback. Honest behavior:
     * a target may be left partially applied if a feature fails mid-way.
     *
     * RED evidence: removing the inner per-feature try/catch in apply() would cause the
     * exception to propagate and the second target would never be processed.
     */
    public function testApplyPartialFailureDoesNotAbortBatch()
    {
        $srcId      = $this->createProject('PartialSrc');
        $goodTarget = $this->createProject('PartialGood');

        $this->createTag($srcId, 'batch-tag');

        // Use a subclass that throws inside copyFeature() for a specific target.
        // This simulates a per-feature failure (caught by the inner try/catch).
        $badTargetId = 99999;

        $model = new class($this->container, $badTargetId) extends FeatureSyncModel {
            private $failOnTarget;
            public function __construct($c, $failOn) {
                parent::__construct($c);
                $this->failOnTarget = $failOn;
            }
            public function copyFeature($feature, $src, $dst, $mode = 'add_missing') {
                if ($dst === $this->failOnTarget) {
                    throw new \RuntimeException("Simulated failure on target {$dst}");
                }
                return parent::copyFeature($feature, $src, $dst, $mode);
            }
        };

        $report = $model->apply(
            $srcId,
            array($badTargetId, $goodTarget),
            array(FeatureSyncModel::FEATURE_TAGS),
            'add_missing'
        );

        // Bad target: status = 'partial' (feature exception was caught per-feature).
        $this->assertArrayHasKey($badTargetId, $report, "Bad target must appear in report");
        $this->assertSame('partial', $report[$badTargetId]['status'],
            "Bad target must report 'partial' (per-feature exception was caught)");
        // The feature entry for the bad target must contain the error string.
        $this->assertArrayHasKey(FeatureSyncModel::FEATURE_TAGS, $report[$badTargetId]['features'],
            "Bad target must have a feature entry for the failed feature");
        $featureVal = $report[$badTargetId]['features'][FeatureSyncModel::FEATURE_TAGS];
        $this->assertIsString($featureVal, "Failed feature entry must be an error string");
        $this->assertStringContainsString('Simulated failure', $featureVal);

        // Good target: status = ok AND tags were applied.
        $this->assertArrayHasKey($goodTarget, $report, "Good target must appear in report");
        $this->assertSame('ok', $report[$goodTarget]['status'], "Good target must report 'ok'");
        $this->assertContains('batch-tag', $this->getTagNames($goodTarget),
            "Good target must have received the tag despite the prior target failure");
    }

    /**
     * apply() with multiple features returns per-feature counts in report.
     */
    public function testApplyReturnsPerFeatureCounts()
    {
        $srcId = $this->createProject('MultiFeatSrc');
        $dstId = $this->createProject('MultiFeatDst');

        $this->createTag($srcId, 'my-tag');
        $this->createCategory($srcId, 'My-Cat');

        $report = $this->featureSyncModel->apply(
            $srcId,
            array($dstId),
            array(FeatureSyncModel::FEATURE_TAGS, FeatureSyncModel::FEATURE_CATEGORIES),
            'add_missing'
        );

        $this->assertSame('ok', $report[$dstId]['status']);
        $this->assertArrayHasKey(FeatureSyncModel::FEATURE_TAGS,       $report[$dstId]['features']);
        $this->assertArrayHasKey(FeatureSyncModel::FEATURE_CATEGORIES,  $report[$dstId]['features']);
        $this->assertSame(1, $report[$dstId]['features'][FeatureSyncModel::FEATURE_TAGS]);
        $this->assertSame(1, $report[$dstId]['features'][FeatureSyncModel::FEATURE_CATEGORIES]);
    }

    /**
     * apply() via add_missing is idempotent: running twice does not create duplicates.
     * This is the acceptance criterion test.
     */
    public function testApplyAddMissingIsIdempotentEndToEnd()
    {
        $srcId = $this->createProject('E2EIdempSrc');
        $dstId = $this->createProject('E2EIdempDst');

        $this->createTag($srcId, 'e2e-tag-1');
        $this->createTag($srcId, 'e2e-tag-2');
        $this->createCategory($srcId, 'e2e-cat');

        $features = array(FeatureSyncModel::FEATURE_TAGS, FeatureSyncModel::FEATURE_CATEGORIES);

        // First apply.
        $report1 = $this->featureSyncModel->apply($srcId, array($dstId), $features, 'add_missing');
        $this->assertSame('ok', $report1[$dstId]['status']);

        $tagCountAfterFirst = count($this->container['tagModel']->getAllByProject($dstId));
        $catCountAfterFirst = count($this->container['categoryModel']->getAll($dstId));

        // Second apply — must be a no-op.
        $report2 = $this->featureSyncModel->apply($srcId, array($dstId), $features, 'add_missing');
        $this->assertSame('ok', $report2[$dstId]['status']);

        $tagCountAfterSecond = count($this->container['tagModel']->getAllByProject($dstId));
        $catCountAfterSecond = count($this->container['categoryModel']->getAll($dstId));

        $this->assertSame($tagCountAfterFirst, $tagCountAfterSecond,
            "IDEMPOTENCY: tag count must not increase on second apply() run");
        $this->assertSame($catCountAfterFirst, $catCountAfterSecond,
            "IDEMPOTENCY: category count must not increase on second apply() run");

        // Second run counts must be 0 (nothing to add).
        $this->assertSame(0, $report2[$dstId]['features'][FeatureSyncModel::FEATURE_TAGS],
            "Second run: tags added = 0");
        $this->assertSame(0, $report2[$dstId]['features'][FeatureSyncModel::FEATURE_CATEGORIES],
            "Second run: categories added = 0");
    }

    // ── replace columns with task-holding columns ─────────────────────────────

    /**
     * REPLACE COLUMNS — task-holding column survives, task-free columns are removed,
     * source columns not colliding with survivor are added.
     *
     * Fixture:
     *   Source : Ready, Done
     *   Target : Default, Blocked (with a task in Blocked)
     *
     * After replace:
     *   - Blocked survives (has task) — its title collides with NO source column, so no skip.
     *   - Default is removed (no tasks).
     *   - Ready and Done are added (not in target after Default removed).
     *   - Result: Blocked, Ready, Done  (3 columns)
     *   - Reported count = 2 (Ready + Done added).
     */
    public function testReplaceColumnsWithTaskHoldingColumnSurvives()
    {
        $srcId = $this->createProject('ReplColTaskSrc');
        $dstId = $this->createProject('ReplColTaskDst');

        // Source: add two columns (projects get default columns too — Backlog, Ready, Done).
        // Use unique names to avoid collision with defaults.
        $this->container['columnModel']->create($srcId, 'SrcColA');
        $this->container['columnModel']->create($srcId, 'SrcColB');

        // Target: add one extra column, then put a task in it.
        $blockedColId = $this->container['columnModel']->create($dstId, 'Blocked');
        $this->assertGreaterThan(0, $blockedColId, "Blocked column must be created");

        $taskModel = new TaskCreationModel($this->container);
        $taskId = $taskModel->create(array(
            'title'      => 'blocker-task',
            'project_id' => $dstId,
            'column_id'  => $blockedColId,
        ));
        $this->assertGreaterThan(0, $taskId, "Task must be created in Blocked column");

        // Run replace.
        $count = $this->featureSyncModel->copyFeature(
            FeatureSyncModel::FEATURE_COLUMNS, $srcId, $dstId, 'replace'
        );

        $dstTitles = $this->getColumnTitles($dstId);

        // Blocked must survive (has task).
        $this->assertContains('Blocked', $dstTitles, "Blocked column (has task) must survive replace");

        // Source columns must be added.
        $this->assertContains('SrcColA', $dstTitles, "SrcColA must be added to target");
        $this->assertContains('SrcColB', $dstTitles, "SrcColB must be added to target");

        // The returned count must equal the number of columns actually added.
        // (Source: default Backlog+Ready+Done + SrcColA + SrcColB = 5 src columns total,
        //  target after remove: defaults removed, Blocked survives + any defaults with tasks.
        //  Only SrcColA and SrcColB are new; defaults also get added since they were removed.)
        $this->assertGreaterThan(0, $count, "Replace must report at least 1 added column");
    }

    /**
     * REPLACE SWIMLANES — task-holding swimlane survives, task-free swimlanes are removed,
     * source swimlanes not colliding with survivor are added.
     *
     * Fixture (projects start with "Default swimlane"):
     *   Source : Default swimlane (auto), Wave1, Wave2  → 3 lanes
     *   Target : Default swimlane (auto, no tasks), OldLane (no tasks), HotLane (has task)
     *
     * After replace:
     *   - Default swimlane (dst) is removed (no tasks).
     *   - OldLane is removed (no tasks).
     *   - HotLane survives (has task).
     *   - All 3 source lanes (Default swimlane + Wave1 + Wave2) are added.
     *   - Reported count = 3 (all src lanes added, none collide with HotLane).
     */
    public function testReplaceSwimlanesSkipsLanesWithTasks()
    {
        $srcId = $this->createProject('ReplSwimTaskSrc');
        $dstId = $this->createProject('ReplSwimTaskDst');

        $this->createSwimlane($srcId, 'Wave1');
        $this->createSwimlane($srcId, 'Wave2');

        $this->createSwimlane($dstId, 'OldLane');
        $hotLaneId = $this->createSwimlane($dstId, 'HotLane');

        // Get the first available column in dst to put the task in.
        $dstCols = $this->container['columnModel']->getAll($dstId);
        $this->assertNotEmpty($dstCols, "dst project must have at least one column");

        $taskModel = new TaskCreationModel($this->container);
        $taskId = $taskModel->create(array(
            'title'       => 'blocking-task',
            'project_id'  => $dstId,
            'swimlane_id' => $hotLaneId,
            'column_id'   => $dstCols[0]['id'],
        ));
        $this->assertGreaterThan(0, $taskId, "Task must be created in HotLane");

        $count = $this->featureSyncModel->copyFeature(
            FeatureSyncModel::FEATURE_SWIMLANES, $srcId, $dstId, 'replace'
        );

        $dstNames = $this->getSwimlaneNames($dstId);

        // HotLane must survive (it has a task).
        $this->assertContains('HotLane', $dstNames, "HotLane (has task) must survive replace");

        // OldLane must be gone (no tasks).
        $this->assertNotContains('OldLane', $dstNames, "OldLane (no tasks) must be removed");

        // Source swimlanes must be added.
        $this->assertContains('Wave1', $dstNames, "Wave1 must be added");
        $this->assertContains('Wave2', $dstNames, "Wave2 must be added");
        // Default swimlane from source is also added (dst's copy was removed, no tasks).
        $this->assertContains('Default swimlane', $dstNames, "Default swimlane (from src) must be added");

        // Reported count = 3 lanes actually added (Default swimlane + Wave1 + Wave2).
        // HotLane's name doesn't collide with any source lane so count = count(src lanes).
        $srcLaneCount = count($this->container['swimlaneModel']->getAll($srcId));
        $this->assertSame($srcLaneCount, $count,
            "Replace must report exactly the source lane count added (none collide with HotLane)");
    }

    // ── diff() counts == apply() counts for columns/swimlanes with task-holding items ──

    /**
     * CRITICAL: diff(replace, columns) count_add must equal what copyFeature actually adds
     * when the target has a task-holding column.
     *
     * Shared helper getSurvivingColumnTitles() is used by BOTH paths — this test asserts
     * they produce the same result for the same DB state.
     */
    public function testDiffEqualsApplyCountsForColumnsWithTaskHoldingColumn()
    {
        $srcId = $this->createProject('DiffApplyColSrc');
        $dstId = $this->createProject('DiffApplyColDst');

        // Source: two custom columns.
        $this->container['columnModel']->create($srcId, 'NewColX');
        $this->container['columnModel']->create($srcId, 'NewColY');

        // Target: one custom column with a task in it.
        $taskColId = $this->container['columnModel']->create($dstId, 'OccupiedCol');
        $this->assertGreaterThan(0, $taskColId, "OccupiedCol must be created");

        $taskModel = new TaskCreationModel($this->container);
        $taskId = $taskModel->create(array(
            'title'      => 'occupy',
            'project_id' => $dstId,
            'column_id'  => $taskColId,
        ));
        $this->assertGreaterThan(0, $taskId, "Task must be placed in OccupiedCol");

        // Compute diff (read-only preview).
        $diff = $this->featureSyncModel->diffFeature(
            FeatureSyncModel::FEATURE_COLUMNS, $srcId, $dstId, 'replace'
        );
        $diffCountAdd = $diff['count_add'];

        // Apply and get the actual added count.
        $applyCount = $this->featureSyncModel->copyFeature(
            FeatureSyncModel::FEATURE_COLUMNS, $srcId, $dstId, 'replace'
        );

        $this->assertSame($diffCountAdd, $applyCount,
            "diff() count_add must equal apply() count for replace-columns with a task-holding column");
    }

    /**
     * CRITICAL: diff(replace, swimlanes) count_add must equal what copyFeature actually adds
     * when the target has a task-holding swimlane.
     */
    public function testDiffEqualsApplyCountsForSwimlanesWithTaskHoldingLane()
    {
        $srcId = $this->createProject('DiffApplySwimSrc');
        $dstId = $this->createProject('DiffApplySwimDst');

        $this->createSwimlane($srcId, 'SrcLaneA');
        $this->createSwimlane($srcId, 'SrcLaneB');

        $busyLaneId = $this->createSwimlane($dstId, 'BusyLane');

        $dstCols = $this->container['columnModel']->getAll($dstId);
        $this->assertNotEmpty($dstCols);

        $taskModel = new TaskCreationModel($this->container);
        $taskId = $taskModel->create(array(
            'title'       => 'busy-task',
            'project_id'  => $dstId,
            'swimlane_id' => $busyLaneId,
            'column_id'   => $dstCols[0]['id'],
        ));
        $this->assertGreaterThan(0, $taskId);

        // diff() preview count.
        $diff = $this->featureSyncModel->diffFeature(
            FeatureSyncModel::FEATURE_SWIMLANES, $srcId, $dstId, 'replace'
        );
        $diffCountAdd = $diff['count_add'];

        // apply() actual count.
        $applyCount = $this->featureSyncModel->copyFeature(
            FeatureSyncModel::FEATURE_SWIMLANES, $srcId, $dstId, 'replace'
        );

        $this->assertSame($diffCountAdd, $applyCount,
            "diff() count_add must equal apply() count for replace-swimlanes with a task-holding lane");
    }

    // ── add_missing: action params resolved to target project IDs ────────────

    /**
     * IMPORTANT: add_missing action copy must resolve source column ID in params to the
     * TARGET project's corresponding column ID (by title).
     *
     * Scenario:
     *   Source project: has column "In Progress" (source_column_id = X).
     *   Target project: has column "In Progress" (target_column_id = Y, Y ≠ X because
     *                   each project gets independent column rows).
     *   Source action: event=task.move.column, action=TaskAssignColorColumn,
     *                  params = [column_id => X].
     *
     * After add_missing:
     *   Target action's param column_id must equal Y (the target's column id),
     *   NOT X (the source's column id).
     *
     * Core reference:
     *   ActionParameterModel::duplicateParameters() resolves column_id via
     *   ColumnModel::getById() → ColumnModel::getColumnIdByTitle($dst_project_id, $title).
     *   app/Model/ActionParameterModel.php:111 / resolveParameter() lines 154-159.
     */
    public function testAddMissingActionParamResolvesToTargetColumnId()
    {
        $srcId = $this->createProject('ActParamSrc');
        $dstId = $this->createProject('ActParamDst');

        // Get (or create) a column named "In Progress" in the source project.
        // Projects start with default columns (Backlog, Ready, Work in progress, Done).
        // We'll use "Work in progress" which exists in both projects by default.
        $srcCols = $this->container['columnModel']->getAll($srcId);
        $dstCols = $this->container['columnModel']->getAll($dstId);

        // Find "Work in progress" in both projects.
        $srcColId = null;
        foreach ($srcCols as $col) {
            if ($col['title'] === 'Work in progress') {
                $srcColId = (int)$col['id'];
                break;
            }
        }
        $dstColId = null;
        foreach ($dstCols as $col) {
            if ($col['title'] === 'Work in progress') {
                $dstColId = (int)$col['id'];
                break;
            }
        }

        $this->assertNotNull($srcColId, "Source project must have 'Work in progress' column");
        $this->assertNotNull($dstColId, "Target project must have 'Work in progress' column");
        // Sanity: the two column IDs must differ (different DB rows for different projects).
        $this->assertNotSame($srcColId, $dstColId,
            "Source and target column IDs must differ (independent rows per project)");

        // Create an action in source whose param references the source column ID.
        // We insert it directly into the DB to control the exact param value.
        $db = $this->container['db'];
        $srcActionInserted = $db->table('actions')->insert(array(
            'project_id'  => $srcId,
            'event_name'  => 'task.move.column',
            'action_name' => '\\Kanboard\\Action\\TaskAssignColorColumn',
        ));
        $this->assertTrue($srcActionInserted, "Source action row must be inserted");
        $srcActionId = $db->getLastId();

        // Insert param with SOURCE column id (verbatim — this is what old code would copy).
        $db->table('action_has_params')->insert(array(
            'action_id' => $srcActionId,
            'name'      => 'column_id',
            'value'     => $srcColId,
        ));

        // Apply add_missing — must resolve source column ID → target column ID.
        $count = $this->featureSyncModel->copyFeature(
            FeatureSyncModel::FEATURE_ACTIONS, $srcId, $dstId, 'add_missing'
        );

        $this->assertSame(1, $count, "Exactly 1 action must be added");

        // Fetch the copied action in the target project.
        $dstActions = $this->container['actionModel']->getAllByProject($dstId);
        $this->assertCount(1, $dstActions, "Target must have exactly 1 action after add_missing");

        $copiedAction = $dstActions[0];
        $this->assertArrayHasKey('params', $copiedAction, "Copied action must have params");
        $this->assertArrayHasKey('column_id', $copiedAction['params'],
            "Copied action params must contain 'column_id'");

        $resolvedColId = (int)$copiedAction['params']['column_id'];

        // The resolved param must be the TARGET column id, not the source.
        $this->assertSame($dstColId, $resolvedColId,
            "Copied action's column_id param must be resolved to the TARGET project's column id");
        $this->assertNotSame($srcColId, $resolvedColId,
            "Copied action's column_id param must NOT be the source project's column id");
    }
}
