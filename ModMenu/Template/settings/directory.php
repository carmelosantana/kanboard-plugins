<div class="page-header"><h2><?= t('ModMenu') ?></h2></div>
<?= $this->render('ModMenu:settings/nav', ['tab' => $tab]) ?>

<?php foreach ($errors as $err): ?>
    <div class="modmenu-banner"><?= t('Could not load source: %s', $this->text->e($err['url'])) ?> — <?= $this->text->e($err['message']) ?></div>
<?php endforeach ?>

<?php if (empty($plugins)): ?>
    <p class="alert"><?= t('No plugins available from the configured sources.') ?></p>
<?php else: ?>
    <?php foreach ($plugins as $p): ?>
        <div class="modmenu-card">
            <strong><?= $this->text->e($p['title'] ?? $p['name']) ?></strong>
            <?php if ($p['status'] === 'update'): ?>
                <span class="modmenu-badge modmenu-badge--update"><?= t('Update to %s', $this->text->e($p['version'])) ?></span>
            <?php elseif ($p['status'] === 'installed'): ?>
                <span class="modmenu-badge modmenu-badge--installed"><?= t('Installed') ?></span>
            <?php elseif ($p['status'] === 'disabled'): ?>
                <span class="modmenu-badge modmenu-badge--disabled"><?= t('Disabled') ?></span>
            <?php endif ?>

            <div class="modmenu-card__status">v<?= $this->text->e($p['version']) ?><?php if (! empty($p['author'])): ?> · <?= $this->text->e($p['author']) ?><?php endif ?></div>
            <?php if (! empty($p['description'])): ?><p><?= $this->text->e($p['description']) ?></p><?php endif ?>

            <?php if (! empty($p['screenshots'])): ?>
                <div class="modmenu-shots">
                    <?php foreach ($p['screenshots'] as $shot): ?>
                        <img class="modmenu-shot" src="<?= $this->text->e($shot) ?>" alt="">
                    <?php endforeach ?>
                </div>
            <?php endif ?>

            <?php if (! empty($p['download'])): ?>
                <?php if ($p['status'] === 'available'): ?>
                    <form method="post" class="modmenu-action" action="<?= $this->url->href('ModMenuController', 'install', ['plugin' => 'ModMenu']) ?>">
                        <?= $this->form->csrf() ?>
                        <input type="hidden" name="archive_url" value="<?= $this->text->e($p['download']) ?>">
                        <button type="submit" class="btn btn-blue"><?= t('Install') ?></button>
                    </form>
                <?php elseif ($p['status'] === 'update'): ?>
                    <form method="post" class="modmenu-action" action="<?= $this->url->href('ModMenuController', 'update', ['plugin' => 'ModMenu']) ?>">
                        <?= $this->form->csrf() ?>
                        <input type="hidden" name="archive_url" value="<?= $this->text->e($p['download']) ?>">
                        <button type="submit" class="btn btn-blue"><?= t('Update') ?></button>
                    </form>
                <?php endif ?>
            <?php endif ?>
        </div>
    <?php endforeach ?>
<?php endif ?>
