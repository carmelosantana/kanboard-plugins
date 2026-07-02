<?php

namespace Kanboard\Plugin\FeatureSync\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\FeatureSync\Model\FeatureSyncModel;

class FeatureSyncController extends BaseController
{
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
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

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
        $postValues = $this->request->getValues();  // validated POST (CSRF-checked)

        // source_project_id: from POST, or GET param on first load — default 0.
        $sourceProjectId = isset($postValues['source_project_id'])
            ? (int) $postValues['source_project_id']
            : (int) $this->request->getIntegerParam('source_project_id', 0);

        // Validate source: id=0 is the "None" landmine — reject it.
        // The controller re-renders with source=0 and the template shows no selection.
        if ($sourceProjectId < 1) {
            $sourceProjectId = 0;
        }

        // Selected feature checkboxes.
        $selectedFeatures = isset($postValues['features']) ? $postValues['features'] : array();

        // Ensure it is always an array even if a single checkbox value is posted.
        if (! is_array($selectedFeatures)) {
            $selectedFeatures = array($selectedFeatures);
        }

        // Target project ids (multi-select checkboxes in step 3).
        $targetProjectIds = isset($postValues['target_project_ids'])
            ? $postValues['target_project_ids']
            : array();

        if (! is_array($targetProjectIds)) {
            $targetProjectIds = array($targetProjectIds);
        }

        // Cast to int and strip any id=0 and the source itself.
        $targetProjectIds = array_values(array_filter(
            array_map('intval', $targetProjectIds),
            function ($id) use ($sourceProjectId) {
                return $id > 0 && $id !== $sourceProjectId;
            }
        ));

        // Sync mode: 'add_missing' (default) or 'replace'.
        $syncMode = isset($postValues['sync_mode']) ? $postValues['sync_mode'] : 'add_missing';
        if (! in_array($syncMode, array('add_missing', 'replace'), true)) {
            $syncMode = 'add_missing';
        }

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
}
