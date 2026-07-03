<div class="page-header"><h2><?= t('ModMenu') ?></h2></div>
<?= $this->render('ModMenu:settings/nav', ['tab' => $tab]) ?>

<?php if (! $is_configured): ?>
    <div class="modmenu-banner"><?= t('Uploads are disabled because ModMenu cannot write to the plugins directory here.') ?></div>
<?php endif ?>

<form method="post" enctype="multipart/form-data" class="modmenu-action"
      action="<?= $this->url->href('UploadController', 'upload', ['plugin' => 'ModMenu']) ?>">
    <?= $this->form->csrf() ?>
    <?= $this->form->label(t('Plugin archive (.zip)'), 'plugin') ?>
    <input type="file" name="plugin" accept=".zip" required>
    <div class="form-actions">
        <button type="submit" class="btn btn-blue"<?= $is_configured ? '' : ' disabled' ?>><?= t('Upload and install') ?></button>
    </div>
</form>
<p class="modmenu-card__status"><?= t('The archive must contain a single top-level folder with a Plugin.php inside.') ?></p>
