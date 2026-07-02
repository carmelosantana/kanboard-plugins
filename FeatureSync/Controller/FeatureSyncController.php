<?php

namespace Kanboard\Plugin\FeatureSync\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\FeatureSync\Model\FeatureSyncModel;

class FeatureSyncController extends BaseController
{
    /**
     * Guard: throw AccessForbiddenException if not admin. Call at top of every action.
     *
     * @throws AccessForbiddenException
     */
    private function requireAdmin()
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
    }

    /**
     * GET/POST — admin-only Feature Sync page.
     *
     * Renders the 5-step workflow. Steps 1 (source project), 2 (feature types),
     * and 3 (target projects + mode) are wired here; steps 4-5 come in later tasks.
     *
     * The form posts back to this same action so the controller can re-render with
     * the user's selections retained (source project + feature checkboxes + targets + mode).
     *
     * @throws AccessForbiddenException when the current user is not an app admin
     */
    public function index()
    {
        $this->requireAdmin();

        /** @var FeatureSyncModel $featureSyncModel */
        $featureSyncModel = $this->container['featureSyncModel'];

        // All non-private projects for the source dropdown.
        // ProjectModel::getList($prependNone, $noPrivateProjects)
        //   app/Model/ProjectModel.php:233
        // Use prependNone=false to avoid the id=0 "None" landmine.
        $projects = $this->projectModel->getList(false, false);

        // Feature options from the model (keys = checkbox names, values = labels).
        $featureList = $featureSyncModel->getFeatureList();

        // Retain form state across POST round-trips.
        // Delegate all resolution logic to the model helper (also unit-tested there).
        $postValues = $this->request->getValues();  // validated POST (CSRF-checked)
        $sourceFromGet = $this->request->getIntegerParam('source_project_id', 0);

        $resolved = $featureSyncModel->resolveFormParams($postValues, $sourceFromGet);

        $sourceProjectId  = $resolved['sourceProjectId'];
        $selectedFeatures = $resolved['selectedFeatures'];
        $targetProjectIds = $resolved['targetProjectIds'];
        $syncMode         = $resolved['syncMode'];

        // Target project list = all projects except the source.
        $targetProjects = array();
        foreach ($projects as $id => $name) {
            $id = (int) $id;
            if ($id > 0 && $id !== $sourceProjectId) {
                $targetProjects[$id] = $name;
            }
        }

        $this->response->html($this->helper->layout->config('FeatureSync:sync/index', array(
            'title'             => t('Settings') . ' &gt; ' . t('Feature Sync'),
            'projects'          => $projects,
            'featureList'       => $featureList,
            'sourceProjectId'   => $sourceProjectId,
            'selectedFeatures'  => $selectedFeatures,
            'targetProjects'    => $targetProjects,
            'targetProjectIds'  => $targetProjectIds,
            'syncMode'          => $syncMode,
        )));
    }

    /**
     * POST — admin-only dry-run preview page (Step 4).
     *
     * Reads the resolved params from POST, computes a read-only diff per target,
     * detects risky replace-columns situations (columns with open tasks that would be
     * removed), and renders the preview table.
     *
     * ABSOLUTELY NO WRITES — this action only SELECTs from the database.
     *
     * @throws AccessForbiddenException when the current user is not an app admin
     */
    public function preview()
    {
        $this->requireAdmin();

        /** @var FeatureSyncModel $featureSyncModel */
        $featureSyncModel = $this->container['featureSyncModel'];

        // Resolve and validate all form params from POST.
        $postValues       = $this->request->getValues();
        $sourceFromGet    = $this->request->getIntegerParam('source_project_id', 0);
        $resolved         = $featureSyncModel->resolveFormParams($postValues, $sourceFromGet);

        $sourceProjectId  = $resolved['sourceProjectId'];
        $selectedFeatures = $resolved['selectedFeatures'];
        $targetProjectIds = $resolved['targetProjectIds'];
        $syncMode         = $resolved['syncMode'];

        // Guard: need a source and at least one target to preview.
        if ($sourceProjectId < 1 || empty($targetProjectIds) || empty($selectedFeatures)) {
            $this->flash->failure(t('Please select a source project, at least one feature, and at least one target project.'));
            $this->response->redirect($this->helper->url->href('FeatureSyncController', 'index', array(), 'FeatureSync'));
            return;
        }

        // Compute the read-only diff for all features × all targets.
        // FeatureSyncModel::diff() only SELECTs — no writes.
        $diffs = $featureSyncModel->diff($sourceProjectId, $targetProjectIds, $selectedFeatures, $syncMode);

        // Detect risky replace-columns: target columns with open tasks that would be removed.
        // Only relevant when columns is selected and mode is replace.
        $columnRisks = array();  // [targetProjectId => risky columns map]
        if ($syncMode === 'replace' && in_array(FeatureSyncModel::FEATURE_COLUMNS, $selectedFeatures, true)) {
            foreach ($targetProjectIds as $targetId) {
                $targetId = (int) $targetId;
                if (isset($diffs[$targetId][FeatureSyncModel::FEATURE_COLUMNS])) {
                    $featureDiff   = $diffs[$targetId][FeatureSyncModel::FEATURE_COLUMNS];
                    $targetColumns = $featureDiff['replace_remove'];  // columns that would be cleared

                    if (! empty($targetColumns)) {
                        $risky = $featureSyncModel->getColumnsWithTasks($targetId, $targetColumns);
                        if (! empty($risky)) {
                            $columnRisks[$targetId] = $risky;
                        }
                    }
                }
            }
        }

        // Project name map for display.
        $projects         = $this->projectModel->getList(false, false);
        $featureList      = $featureSyncModel->getFeatureList();
        $sourceProjectName = isset($projects[$sourceProjectId]) ? $projects[$sourceProjectId] : "#{$sourceProjectId}";

        // Build display rows: per-target summary.
        $previewRows = array();
        foreach ($targetProjectIds as $targetId) {
            $targetId = (int) $targetId;
            $featureSummaries = array();

            foreach ($selectedFeatures as $feature) {
                if (isset($diffs[$targetId][$feature])) {
                    $featureSummaries[$feature] = $diffs[$targetId][$feature];
                }
            }

            $previewRows[$targetId] = array(
                'project_id'   => $targetId,
                'project_name' => isset($projects[$targetId]) ? $projects[$targetId] : "#{$targetId}",
                'features'     => $featureSummaries,
                'column_risks' => isset($columnRisks[$targetId]) ? $columnRisks[$targetId] : array(),
            );
        }

        $this->response->html($this->helper->layout->config('FeatureSync:sync/preview', array(
            'title'             => t('Settings') . ' &gt; ' . t('Feature Sync') . ' &gt; ' . t('Preview'),
            'sourceProjectId'   => $sourceProjectId,
            'sourceProjectName' => $sourceProjectName,
            'selectedFeatures'  => $selectedFeatures,
            'targetProjectIds'  => $targetProjectIds,
            'syncMode'          => $syncMode,
            'featureList'       => $featureList,
            'previewRows'       => $previewRows,
            'postValues'        => $postValues,
        )));
    }

    /**
     * POST — admin-only apply action (Step 5).
     *
     * Reads the resolved params from POST, validates CSRF + admin, applies the
     * selected features from source to each target project in its own transaction,
     * and renders a per-target success/failure report.
     *
     * WRITES to the database. A failure on one target does NOT abort the batch.
     *
     * @throws AccessForbiddenException when the current user is not an app admin
     */
    public function apply()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();

        /** @var FeatureSyncModel $featureSyncModel */
        $featureSyncModel = $this->container['featureSyncModel'];

        // Resolve and validate all form params from POST.
        $postValues       = $this->request->getValues();
        $sourceFromGet    = $this->request->getIntegerParam('source_project_id', 0);
        $resolved         = $featureSyncModel->resolveFormParams($postValues, $sourceFromGet);

        $sourceProjectId  = $resolved['sourceProjectId'];
        $selectedFeatures = $resolved['selectedFeatures'];
        $targetProjectIds = $resolved['targetProjectIds'];
        $syncMode         = $resolved['syncMode'];

        // Guard: must have a source, at least one feature, and at least one target.
        if ($sourceProjectId < 1 || empty($targetProjectIds) || empty($selectedFeatures)) {
            $this->flash->failure(t('Please select a source project, at least one feature, and at least one target project.'));
            $this->response->redirect($this->helper->url->href('FeatureSyncController', 'index', array(), 'FeatureSync'));
            return;
        }

        // Apply: per-target transaction, per-feature copy, collect report.
        $applyReport = $featureSyncModel->apply(
            $sourceProjectId,
            $targetProjectIds,
            $selectedFeatures,
            $syncMode
        );

        // Build flash summary.
        $successCount = 0;
        $failureCount = 0;
        foreach ($applyReport as $result) {
            if ($result['status'] === 'ok') {
                $successCount++;
            } elseif ($result['status'] === 'error') {
                $failureCount++;
            } else {
                // 'partial': at least one feature failed but others succeeded.
                $failureCount++;
            }
        }

        if ($failureCount === 0) {
            $this->flash->success(t(
                'Feature Sync complete: %d target project(s) updated successfully.',
                $successCount
            ));
        } else {
            $this->flash->failure(t(
                'Feature Sync partial: %d target(s) succeeded, %d had errors. See report below.',
                $successCount,
                $failureCount
            ));
        }

        // Project name map for display.
        $projects          = $this->projectModel->getList(false, false);
        $featureList       = $featureSyncModel->getFeatureList();
        $sourceProjectName = isset($projects[$sourceProjectId]) ? $projects[$sourceProjectId] : "#{$sourceProjectId}";

        $this->response->html($this->helper->layout->config('FeatureSync:sync/report', array(
            'title'             => t('Settings') . ' &gt; ' . t('Feature Sync') . ' &gt; ' . t('Report'),
            'sourceProjectId'   => $sourceProjectId,
            'sourceProjectName' => $sourceProjectName,
            'selectedFeatures'  => $selectedFeatures,
            'targetProjectIds'  => $targetProjectIds,
            'syncMode'          => $syncMode,
            'featureList'       => $featureList,
            'applyReport'       => $applyReport,
            'projects'          => $projects,
            'successCount'      => $successCount,
            'failureCount'      => $failureCount,
        )));
    }
}
