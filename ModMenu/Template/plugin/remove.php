<div class="page-header"><h2><?= t('Remove plugin') ?></h2></div>
<div class="confirm">
    <p class="alert alert-info">
        <?= t('Do you really want to remove "%s"? Its files will be deleted from the server.', $name) ?>
    </p>
    <form method="post" action="<?= $this->url->href('ModMenuController', 'uninstall', ['plugin' => 'ModMenu']) ?>" class="modmenu-action">
        <?= $this->form->csrf() ?>
        <input type="hidden" name="name" value="<?= $this->text->e($name) ?>">
        <div class="form-actions">
            <button type="submit" class="btn btn-red"><?= t('Yes, remove it') ?></button>
            <?= t('or') ?> <?= $this->url->link(t('cancel'), 'ModMenuController', 'show', ['plugin' => 'ModMenu'], false, 'close-popover') ?>
        </div>
    </form>
</div>
