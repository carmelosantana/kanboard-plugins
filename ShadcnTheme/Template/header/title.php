<?php
/**
 * Header title override — shows the uploaded logo when one is configured,
 * otherwise falls back to the Kanboard default K<span>B</span> text logo.
 *
 * Reproduces the core Template/header/title.php structure exactly and adds
 * a conditional image in the .logo span when shadcn_logo_path is set.
 */
$shadcnLogoPath = $this->app->config('shadcn_logo_path');
?>
<h1>
    <span class="logo">
        <?php if (! empty($shadcnLogoPath)): ?>
            <a href="<?= $this->url->href('DashboardController', 'show') ?>" title="<?= t('Dashboard') ?>">
                <img src="<?= $this->url->href('SettingsController', 'serveAsset', ['plugin' => 'ShadcnTheme', 'slot' => 'logo']) ?>"
                     alt="<?= $this->text->e($this->app->config('application_name', 'Kanboard')) ?>"
                     class="shadcn-header-logo">
            </a>
        <?php else: ?>
            <?= $this->url->link('K<span>B</span>', 'DashboardController', 'show', [], false, '', t('Dashboard')) ?>
        <?php endif ?>
    </span>
    <span class="title">
        <?php if (! empty($project) && ! empty($task)): ?>
            <?= $this->url->link($this->text->e($project['name']), 'BoardViewController', 'show', ['project_id' => $project['id']]) ?>
        <?php else: ?>
            <?= $this->text->e($title) ?>
            <?php if (! empty($project) && $project['task_limit'] && array_key_exists('nb_active_tasks', $project)): ?>
              (<span><?= intval($project['nb_active_tasks']) ?></span> / <span title="<?= t('Task limit') ?>"><span class="ui-helper-hidden-accessible"><?= t('Task limit') ?> </span><?= $this->text->e($project['task_limit']) ?></span>)
            <?php endif ?>
        <?php endif ?>
    </span>
    <?php if (! empty($description)): ?>
        <?= $this->app->tooltipHTML($description) ?>
    <?php endif ?>
</h1>
