<?php
/**
 * BulkProjectDelete — admin-only selection toolbar.
 *
 * Attached to: template:project-list:menu:after
 * Renders nothing for non-admin users (server-side gate).
 */
if (! $this->user->isAdmin()) {
    return;
}
?>
<li class="bpd-toolbar-item">
    <button
        id="bpd-toggle"
        type="button"
        class="bpd-btn bpd-btn--toggle"
        aria-pressed="false"
    ><?= t('Select projects') ?></button>
</li>
<li class="bpd-toolbar-item">
    <div id="bpd-action-bar" class="bpd-action-bar bpd-hidden" role="region" aria-label="<?= t('Bulk delete') ?>">
        <span id="bpd-count-badge" class="bpd-count-badge">0 <?= t('selected') ?></span>
        <button
            id="bpd-delete-btn"
            type="button"
            class="bpd-btn bpd-btn--destructive"
            disabled
            aria-disabled="true"
        ><?= t('Delete') ?> <span id="bpd-count-label">0</span> <?= t('projects') ?></button>
    </div>
</li>

<?= $this->app->component('bpd-bulk-select', [
    'confirmUrl' => $this->url->to('BulkDeleteController', 'confirm', ['plugin' => 'BulkProjectDelete']),
]) ?>
