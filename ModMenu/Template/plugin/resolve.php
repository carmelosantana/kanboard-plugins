<div class="page-header"><h2><?= t('Resolve dependencies') ?></h2></div>
<div class="confirm">
    <p class="alert alert-info">
        <?= t('"%s" needs other plugins. ModMenu will set them up first:', $this->text->e($name)) ?>
    </p>
    <ul class="modmenu-plan">
        <?php foreach ($plan as $step): ?>
            <li>
                <?php if ($step['action'] === 'install'): ?>
                    <?= t('Install %s', $this->text->e($step['plugin'])) ?>
                <?php elseif ($step['action'] === 'update'): ?>
                    <?= t('Update %s', $this->text->e($step['plugin'])) ?>
                <?php else: ?>
                    <?= t('Enable %s', $this->text->e($step['plugin'])) ?>
                <?php endif ?>
                <?php if (! empty($step['min_version'])): ?> (&ge; <?= $this->text->e($step['min_version']) ?>)<?php endif ?>
            </li>
        <?php endforeach ?>
        <li><strong><?= $action === 'install' ? t('Install %s', $this->text->e($name)) : t('Enable %s', $this->text->e($name)) ?></strong></li>
    </ul>
    <form method="post" action="<?= $this->url->href('ModMenuController', 'resolve', ['plugin' => 'ModMenu']) ?>" class="modmenu-action">
        <?= $this->form->csrf() ?>
        <input type="hidden" name="name" value="<?= $this->text->e($name) ?>">
        <input type="hidden" name="action" value="<?= $this->text->e($action) ?>">
        <div class="form-actions">
            <button type="submit" class="btn btn-blue"><?= t('Confirm') ?></button>
            <?= t('or') ?> <?= $this->url->link(t('cancel'), 'ModMenuController', 'show', ['plugin' => 'ModMenu']) ?>
        </div>
    </form>
</div>
