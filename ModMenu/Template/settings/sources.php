<div class="page-header"><h2><?= t('ModMenu') ?></h2></div>
<?= $this->render('ModMenu:settings/nav', ['tab' => $tab]) ?>

<p><?= t('ModMenu fetches plugin listings from these directory sources. Add your own to install from another repository.') ?></p>

<ul class="modmenu-sources">
    <?php foreach ($sources as $src): ?>
        <li class="modmenu-card">
            <code><?= $this->text->e($src) ?></code>
            <?php if ($src === $default_source): ?> <em>(<?= t('default') ?>)</em><?php endif ?>
            <form method="post" class="modmenu-action" style="display:inline"
                  action="<?= $this->url->href('ModMenuController', 'removeSource', ['plugin' => 'ModMenu']) ?>">
                <?= $this->form->csrf() ?>
                <input type="hidden" name="url" value="<?= $this->text->e($src) ?>">
                <button type="submit" class="btn btn-red"><?= t('Remove') ?></button>
            </form>
        </li>
    <?php endforeach ?>
</ul>

<form method="post" class="modmenu-action" action="<?= $this->url->href('ModMenuController', 'addSource', ['plugin' => 'ModMenu']) ?>">
    <?= $this->form->csrf() ?>
    <?= $this->form->label(t('Directory URL (plugins.json)'), 'url') ?>
    <?= $this->form->text('url', [], [], ['placeholder' => 'https://example.com/plugins.json']) ?>
    <button type="submit" class="btn btn-blue"><?= t('Add source') ?></button>
</form>
