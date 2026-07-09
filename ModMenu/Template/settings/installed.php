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

            <?php if (! empty($p['unmet_deps'])): ?>
                <div class="modmenu-deps">
                    <?php foreach ($p['unmet_deps'] as $dep): ?>
                        <?php $hard = $dep['kind'] === 'requires'; ?>
                        <div class="modmenu-dep modmenu-dep--<?= $hard ? 'required' : 'recommended' ?>">
                            <span class="modmenu-dep__label">
                                <?php if ($hard): ?>
                                    <?= t('Missing requirement: %s', $this->text->e($dep['plugin'])) ?>
                                <?php else: ?>
                                    <?= t('Works better with %s', $this->text->e($dep['plugin'])) ?>
                                <?php endif ?>
                                <?php if (! empty($dep['min_version'])): ?> (&ge; <?= $this->text->e($dep['min_version']) ?>)<?php endif ?>
                                <?php if (! empty($dep['reason'])): ?> — <?= $this->text->e($dep['reason']) ?><?php endif ?>
                            </span>
                            <?php if ($dep['status'] === 'disabled'): ?>
                                <form method="post" style="display:inline"
                                      action="<?= $this->url->href('ModMenuController', 'enable', ['plugin' => 'ModMenu']) ?>">
                                    <?= $this->form->csrf() ?>
                                    <input type="hidden" name="name" value="<?= $this->text->e($dep['plugin']) ?>">
                                    <button type="submit" class="btn btn-blue"><?= t('Enable %s', $this->text->e($dep['plugin'])) ?></button>
                                </form>
                            <?php elseif ($dep['status'] === 'missing'): ?>
                                <form method="post" style="display:inline"
                                      action="<?= $this->url->href('ModMenuController', 'install', ['plugin' => 'ModMenu']) ?>">
                                    <?= $this->form->csrf() ?>
                                    <input type="hidden" name="name" value="<?= $this->text->e($dep['plugin']) ?>">
                                    <button type="submit" class="btn btn-blue"><?= t('Install %s', $this->text->e($dep['plugin'])) ?></button>
                                </form>
                            <?php else: /* outdated */ ?>
                                <?= $this->url->link(t('Update via Browse'), 'ModMenuController', 'directory', ['plugin' => 'ModMenu'], false, 'btn') ?>
                            <?php endif ?>
                        </div>
                    <?php endforeach ?>
                </div>
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
