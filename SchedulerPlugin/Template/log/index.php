<div class="page-header"><h2><?= t('Scheduler log') ?></h2></div>

<?php if (empty($runs)): ?>
    <p class="alert"><?= t('No runs recorded yet.') ?></p>
<?php else: ?>
<table class="table-striped">
    <tr>
        <th><?= t('Run') ?></th>
        <th><?= t('Started') ?></th>
        <th><?= t('Trigger') ?></th>
        <th><?= t('Moved') ?></th>
        <th><?= t('Mode') ?></th>
    </tr>
    <?php foreach ($runs as $run): ?>
    <tr>
        <td><?= $this->url->link('#'.$run['id'], 'SchedulerController', 'runDetail', array('plugin' => 'SchedulerPlugin', 'run_id' => $run['id'])) ?></td>
        <td><?= $this->dt->datetime($run['started_at']) ?></td>
        <td><?= $this->text->e($run['trigger']) ?></td>
        <td><?= (int) $run['moved_count'] ?></td>
        <td><?= ((int) $run['is_dry_run']) ? t('dry-run') : t('applied') ?></td>
    </tr>
    <?php endforeach ?>
</table>
<?php endif ?>
