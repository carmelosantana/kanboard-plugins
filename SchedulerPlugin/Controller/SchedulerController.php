<?php

namespace Kanboard\Plugin\SchedulerPlugin\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\SchedulerPlugin\Model\SchedulerConfigModel;

class SchedulerController extends BaseController
{
    private function requireAdmin()
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
    }

    public function settings()
    {
        $this->requireAdmin();
        $c = $this->schedulerConfigModel;

        $this->response->html($this->helper->layout->config('SchedulerPlugin:config/settings', array(
            'title'          => t('Settings').' &gt; '.t('Scheduler'),
            'master'         => $c->isMasterEnabled(),
            'target_hour'    => $c->getTargetHour(),
            'working_days'   => implode(',', $c->getWorkingDays()),
            'holidays'       => implode("\n", $c->getHolidays()),
            'declump'        => $c->getDeclumpThreshold(),
            'respect_blocks' => $c->respectBlocks(),
            'post_activity'  => $c->postToActivity(),
            'badge_days'     => $c->getBadgeDays(),
            'last_run'       => $c->getLastRun(),
        )));
    }

    public function save()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();
        $v = $this->request->getValues();

        $this->configModel->save(array(
            SchedulerConfigModel::MASTER         => empty($v['master']) ? '0' : '1',
            SchedulerConfigModel::TARGET_HOUR    => (string) max(0, min(23, (int) ($v['target_hour'] ?? 2))),
            SchedulerConfigModel::WORKING_DAYS   => trim($v['working_days'] ?? '1,2,3,4,5'),
            SchedulerConfigModel::HOLIDAYS       => trim($v['holidays'] ?? ''),
            SchedulerConfigModel::DECLUMP        => (string) max(0, (int) ($v['declump'] ?? 0)),
            SchedulerConfigModel::RESPECT_BLOCKS => empty($v['respect_blocks']) ? '0' : '1',
            SchedulerConfigModel::POST_ACTIVITY  => empty($v['post_activity']) ? '0' : '1',
            SchedulerConfigModel::BADGE_DAYS     => (string) max(0, (int) ($v['badge_days'] ?? 3)),
        ));

        $this->flash->success(t('Settings saved successfully.'));
        $this->response->redirect($this->helper->url->to('SchedulerController', 'settings', array('plugin' => 'SchedulerPlugin')));
    }

    public function run()
    {
        $this->requireAdmin();
        $this->checkCSRFForm();

        $dryRun = $this->request->getStringParam('dry_run') === '1';
        $result = $this->schedulerRunner->run(array('dry_run' => $dryRun, 'trigger' => 'manual'));

        if ($dryRun) {
            $this->flash->success(t('Dry run: %d task(s) would be rescheduled.', $result['total_moved']));
        } else {
            $this->flash->success(t('Rescheduled %d task(s).', $result['total_moved']));
        }

        $this->response->redirect($this->helper->url->to('SchedulerController', 'settings', array('plugin' => 'SchedulerPlugin')));
    }

    public function log()
    {
        $this->requireAdmin();
        $this->response->html($this->helper->layout->config('SchedulerPlugin:log/index', array(
            'title' => t('Scheduler').' &gt; '.t('Log'),
            'runs'  => $this->schedulerLogModel->getRecentRuns(50),
        )));
    }

    public function runDetail()
    {
        $this->requireAdmin();
        $runId = $this->request->getIntegerParam('run_id');
        $this->response->html($this->helper->layout->config('SchedulerPlugin:log/run', array(
            'title'  => t('Scheduler').' &gt; '.t('Run #%d', $runId),
            'run_id' => $runId,
            'moves'  => $this->schedulerLogModel->getMovesForRun($runId),
        )));
    }

    public function toggleProject()
    {
        $projectId = $this->request->getIntegerParam('project_id');
        $project = $this->projectModel->getById($projectId);
        if (empty($project)) {
            throw new AccessForbiddenException();
        }

        // Manager or admin on THIS project.
        if (! $this->userSession->isAdmin() &&
            $this->projectUserRoleModel->getUserRole($projectId, $this->userSession->getId()) !== \Kanboard\Core\Security\Role::PROJECT_MANAGER) {
            throw new AccessForbiddenException();
        }

        $this->checkCSRFForm();
        $enable = ! $this->schedulerConfigModel->isProjectEnabled($projectId);
        $this->schedulerConfigModel->setProjectEnabled($projectId, $enable);

        $this->flash->success($enable ? t('Auto-reschedule enabled for this project.') : t('Auto-reschedule disabled for this project.'));
        $this->response->redirect($this->helper->url->to('ProjectViewController', 'show', array('project_id' => $projectId)));
    }
}
