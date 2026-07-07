<div class="page-header"><h2><?= t('Run #%d', $run_id) ?></h2></div>

<?php if (empty($moves)): ?>
    <p class="alert"><?= t('No moves in this run.') ?></p>
<?php else: ?>
<table class="table-striped">
    <tr>
        <th><?= t('Task') ?></th>
        <th><?= t('Project') ?></th>
        <th><?= t('From') ?></th>
        <th><?= t('To') ?></th>
        <th><?= t('Reason') ?></th>
    </tr>
    <?php foreach ($moves as $move): ?>
    <tr>
        <td>#<?= (int) $move['task_id'] ?></td>
        <td>#<?= (int) $move['project_id'] ?></td>
        <td><?= $this->dt->date($move['old_date']) ?></td>
        <td><?= $this->dt->date($move['new_date']) ?></td>
        <td><?= $this->text->e($move['reason']) ?></td>
    </tr>
    <?php endforeach ?>
</table>
<?php endif ?>
