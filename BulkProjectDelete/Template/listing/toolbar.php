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

<!-- BPD confirm modal — hidden until onDeleteClick() fetches the confirm partial and injects it.
     Lives here (on the project-list page) so the plugin CSS (template:layout:css) is already loaded.
     JS clears bpd-hidden to show, adds it back to close. -->
<div
    id="bpd-modal"
    class="bpd-modal bpd-hidden"
    role="dialog"
    aria-modal="true"
    aria-labelledby="bpd-modal-title"
>
    <div class="bpd-modal__backdrop" id="bpd-modal-backdrop" aria-hidden="true"></div>
    <div class="bpd-modal__card">
        <button
            id="bpd-modal-close"
            type="button"
            class="bpd-btn bpd-modal__close"
            aria-label="<?= t('Close') ?>"
        >&times;</button>
        <div id="bpd-modal-content">
            <!-- confirm partial injected here by JS -->
        </div>
    </div>
</div>

<?= $this->app->component('bpd-bulk-select', [
    'confirmUrl' => $this->url->to('BulkDeleteController', 'confirm', ['plugin' => 'BulkProjectDelete']),
]) ?>
