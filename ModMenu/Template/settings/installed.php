<div class="page-header"><h2><?= t('ModMenu') ?></h2></div>
<?= $this->render('ModMenu:settings/nav', ['tab' => $tab]) ?>

<?php if (! $is_configured): ?>
    <?= $this->render('ModMenu:settings/not_configured', ['reason' => $not_configured_reason]) ?>
<?php endif ?>

<?php if (empty($plugins)): ?>
    <p class="alert"><?= t('No plugins found.') ?></p>
<?php else: ?>
    <?php foreach ($plugins as $p): ?>
        <div class="modmenu-card">
            <strong><?= $this->text->e($p['title']) ?></strong>
            <span class="modmenu-badge modmenu-badge--<?= $p['status'] === 'disabled' ? 'disabled' : 'installed' ?>">
                <?= $p['status'] === 'disabled' ? t('Disabled') : t('Active') ?>
            </span>
            <div class="modmenu-card__status">
                <?= $this->text->e($p['name']) ?> · v<?= $this->text->e($p['version']) ?>
                <?php if (! empty($p['author'])): ?> · <?= $this->text->e($p['author']) ?><?php endif ?>
            </div>
            <?php if (! empty($p['description'])): ?>
                <p><?= $this->text->e($p['description']) ?></p>
            <?php endif ?>

            <?php if ($p['name'] !== $self_name): ?>
                <div class="modmenu-actions">
                    <?php if ($p['status'] === 'active'): ?>
                        <form method="post" class="modmenu-action" style="display:inline"
                              action="<?= $this->url->href('ModMenuController', 'disable', ['plugin' => 'ModMenu']) ?>">
                            <?= $this->form->csrf() ?>
                            <input type="hidden" name="name" value="<?= $this->text->e($p['name']) ?>">
                            <button type="submit" class="btn"><?= t('Disable') ?></button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="modmenu-action" style="display:inline"
                              action="<?= $this->url->href('ModMenuController', 'enable', ['plugin' => 'ModMenu']) ?>">
                            <?= $this->form->csrf() ?>
                            <input type="hidden" name="name" value="<?= $this->text->e($p['name']) ?>">
                            <button type="submit" class="btn"><?= t('Enable') ?></button>
                        </form>
                    <?php endif ?>

                    <?= $this->url->link(t('Remove'), 'ModMenuController', 'confirm',
                        ['plugin' => 'ModMenu', 'name' => $this->text->e($p['name'])], false, 'js-modal-confirm btn btn-red') ?>
                </div>
            <?php else: ?>
                <div class="modmenu-card__status"><em><?= t('This is ModMenu itself and cannot be disabled or removed here.') ?></em></div>
            <?php endif ?>
        </div>
    <?php endforeach ?>
<?php endif ?>
