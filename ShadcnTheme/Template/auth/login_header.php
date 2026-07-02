<?php $shadcnLogoPath = $this->app->config('shadcn_logo_path'); ?>
<div class="auth-card-header">
<?php if (! empty($shadcnLogoPath)): ?>
    <img src="<?= $this->url->href('SettingsController', 'serveAsset', ['plugin' => 'ShadcnTheme', 'slot' => 'logo']) ?>"
         alt="<?= $this->text->e($this->app->config('application_name', 'Kanboard')) ?>"
         class="auth-card-logo">
<?php else: ?>
    <h1 class="auth-card-title">Kanboard</h1>
<?php endif ?>
    <p class="auth-card-description"><?= t('Sign in to your account') ?></p>
</div>
