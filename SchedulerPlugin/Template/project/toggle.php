<li>
    <form method="post" action="<?= $this->url->href('SchedulerController', 'toggleProject', array('plugin' => 'SchedulerPlugin', 'project_id' => $project['id'])) ?>" style="display:inline;">
        <?= $this->form->csrf() ?>
        <button type="submit" class="btn btn-link" style="padding:0;">
            <?= $enabled ? t('Disable auto-reschedule') : t('Enable auto-reschedule') ?>
        </button>
    </form>
</li>
