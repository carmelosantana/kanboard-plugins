<div class="page-header">
    <h2><?= t('Scheduler') ?></h2>
</div>

<form method="post" action="<?= $this->url->href('SchedulerController', 'save', array('plugin' => 'SchedulerPlugin')) ?>">
    <?= $this->form->csrf() ?>

    <?= $this->form->checkbox('master', t('Enable the scheduler (master switch)'), '1', $master) ?>

    <?= $this->form->label(t('Daily target hour (0–23)'), 'target_hour') ?>
    <?= $this->form->number('target_hour', array('target_hour' => $target_hour), array(), array('min="0"', 'max="23"')) ?>

    <?= $this->form->label(t('Working days (ISO: 1=Mon … 7=Sun, comma-separated)'), 'working_days') ?>
    <?= $this->form->text('working_days', array('working_days' => $working_days)) ?>

    <?= $this->form->label(t('Holidays (one YYYY-MM-DD per line)'), 'holidays') ?>
    <?= $this->form->textarea('holidays', array('holidays' => $holidays)) ?>

    <?= $this->form->label(t('De-clump threshold (0 = off; max tasks per day before spreading)'), 'declump') ?>
    <?= $this->form->number('declump', array('declump' => $declump), array(), array('min="0"')) ?>

    <?= $this->form->label(t('Calendar badge window (days)'), 'badge_days') ?>
    <?= $this->form->number('badge_days', array('badge_days' => $badge_days), array(), array('min="0"')) ?>

    <?= $this->form->checkbox('respect_blocks', t('Skip tasks that have open blockers (DependencyPlugin)'), '1', $respect_blocks) ?>
    <?= $this->form->checkbox('post_activity', t('Post a per-run summary to the project activity stream'), '1', $post_activity) ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</form>

<hr>

<h3><?= t('Run now') ?></h3>
<?php if (! empty($last_run)): ?>
    <p class="text-muted"><?= t('Last automatic run:') ?> <?= $this->text->e($last_run) ?></p>
<?php endif ?>

<div style="display:flex; gap:.5rem;">
    <form method="post" action="<?= $this->url->href('SchedulerController', 'run', array('plugin' => 'SchedulerPlugin', 'dry_run' => '1')) ?>">
        <?= $this->form->csrf() ?>
        <button type="submit" class="btn btn-default"><?= t('Preview (dry run)') ?></button>
    </form>
    <form method="post" action="<?= $this->url->href('SchedulerController', 'run', array('plugin' => 'SchedulerPlugin')) ?>">
        <?= $this->form->csrf() ?>
        <button type="submit" class="btn btn-red"><?= t('Run now') ?></button>
    </form>
</div>
