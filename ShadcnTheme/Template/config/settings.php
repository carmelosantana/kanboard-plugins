<div class="page-header">
    <h2><?= t('Theme Settings') ?></h2>
</div>

<h3><?= t('Branding') ?></h3>
<p class="form-help"><?= t('Upload a custom logo and favicon. Files are stored in data/files/. Leave empty to use Kanboard defaults.') ?></p>

<form method="post"
      enctype="multipart/form-data"
      action="<?= $this->url->href('SettingsController', 'upload', ['plugin' => 'ShadcnTheme']) ?>">

    <?= $this->form->csrf() ?>

    <?php /* ── Logo ──────────────────────────────────────────────────────── */ ?>
    <fieldset>
        <legend><?= t('Logo') ?></legend>

        <?php if (! empty($logo_path)): ?>
            <p>
                <strong><?= t('Current logo:') ?></strong><br>
                <img src="<?= $this->url->href('SettingsController', 'serveAsset', ['plugin' => 'ShadcnTheme', 'slot' => 'logo']) ?>"
                     alt="<?= t('Current logo') ?>"
                     style="max-height:60px; max-width:200px; margin-top:6px; border:1px solid var(--border,#e2e8f0); border-radius:4px; padding:4px; background:#fff;">
            </p>
            <label>
                <input type="checkbox" name="remove_logo" value="1">
                <?= t('Remove logo (restore default)') ?>
            </label>
            <br><br>
        <?php endif ?>

        <?= $this->form->label(t('Upload new logo'), 'shadcn_logo') ?>
        <input type="file" name="shadcn_logo" id="shadcn_logo" accept="image/png,image/jpeg,image/gif,image/svg+xml,image/webp">
        <p class="form-help"><?= t('Accepted: PNG, JPG, GIF, SVG, WebP (max 2 MB). Recommended size: 160×40 px.') ?></p>
    </fieldset>

    <?php /* ── Favicon ──────────────────────────────────────────────────── */ ?>
    <fieldset>
        <legend><?= t('Favicon') ?></legend>

        <?php if (! empty($favicon_path)): ?>
            <p>
                <strong><?= t('Current favicon:') ?></strong><br>
                <img src="<?= $this->url->href('SettingsController', 'serveAsset', ['plugin' => 'ShadcnTheme', 'slot' => 'favicon']) ?>"
                     alt="<?= t('Current favicon') ?>"
                     style="width:32px; height:32px; margin-top:6px; image-rendering:pixelated;">
            </p>
            <label>
                <input type="checkbox" name="remove_favicon" value="1">
                <?= t('Remove favicon (restore default)') ?>
            </label>
            <br><br>
        <?php endif ?>

        <?= $this->form->label(t('Upload new favicon'), 'shadcn_favicon') ?>
        <input type="file" name="shadcn_favicon" id="shadcn_favicon" accept="image/x-icon,image/png,image/svg+xml,.ico">
        <p class="form-help"><?= t('Accepted: ICO, PNG, SVG (max 2 MB). Recommended size: 32×32 px.') ?></p>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
        <?= $this->url->link(t('Cancel'), 'ConfigController', 'index') ?>
    </div>

</form>
