<?php
/**
 * BulkProjectDelete — impact pre-flight confirmation (partial fragment).
 *
 * Shows per-project counts of tasks, subtasks, comments, files, and total file bytes,
 * plus a totals row and an irreversibility warning.
 *
 * This is a partial fragment injected into the shared modal by the JS layer —
 * it must NOT contain DOCTYPE/html/head/body. The plugin CSS is injected globally
 * via template:layout:css, so the fragment's classes remain styled.
 *
 * This form POSTs to the remove() endpoint (task-05) with CSRF protection.
 */

/**
 * Format bytes as a human-readable string.
 *
 * @param int $bytes
 * @return string
 */
function bpd_format_bytes($bytes) {
    if ($bytes <= 0) { return '0 B'; }
    $units = ['B', 'KB', 'MB', 'GB'];
    $exp   = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / pow(1024, $exp), 1) . ' ' . $units[$exp];
}
?>
<div class="bpd-confirm-wrap">

    <h1 class="bpd-confirm-title"><?= t('Confirm project deletion') ?></h1>

    <div class="bpd-confirm-warning" role="alert">
        <strong><?= t('Warning — this action cannot be undone.') ?></strong>
        <?= t('All tasks, subtasks, comments, and files for the selected projects will be permanently deleted.') ?>
    </div>

    <?php if (empty($rows)): ?>
        <p><?= t('No valid projects were selected.') ?></p>
        <p><a href="<?= $this->url->href('ProjectListController', 'show') ?>"><?= t('Back to project list') ?></a></p>
    <?php else: ?>

    <table class="bpd-impact-table table table-striped">
        <thead>
            <tr>
                <th><?= t('Project') ?></th>
                <th class="bpd-col-num"><?= t('Tasks') ?></th>
                <th class="bpd-col-num"><?= t('Subtasks') ?></th>
                <th class="bpd-col-num"><?= t('Comments') ?></th>
                <th class="bpd-col-num"><?= t('Files') ?></th>
                <th class="bpd-col-num"><?= t('Size') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= $this->text->e($row['name']) ?></td>
                <td class="bpd-col-num"><?= $row['tasks'] ?></td>
                <td class="bpd-col-num"><?= $row['subtasks'] ?></td>
                <td class="bpd-col-num"><?= $row['comments'] ?></td>
                <td class="bpd-col-num"><?= $row['files'] ?></td>
                <td class="bpd-col-num"><?= bpd_format_bytes($row['bytes']) ?></td>
            </tr>
            <?php endforeach ?>
        </tbody>
        <tfoot>
            <tr class="bpd-totals-row">
                <th><?= t('Totals (%d project(s))', count($rows)) ?></th>
                <th class="bpd-col-num"><?= $totals['tasks'] ?></th>
                <th class="bpd-col-num"><?= $totals['subtasks'] ?></th>
                <th class="bpd-col-num"><?= $totals['comments'] ?></th>
                <th class="bpd-col-num"><?= $totals['files'] ?></th>
                <th class="bpd-col-num"><?= bpd_format_bytes($totals['bytes']) ?></th>
            </tr>
        </tfoot>
    </table>

    <form method="post" action="<?= $this->url->href('BulkDeleteController', 'remove', ['plugin' => 'BulkProjectDelete']) ?>">
        <?= $this->form->csrf() ?>

        <?php foreach ($rows as $row): ?>
        <input type="hidden" name="project_ids[]" value="<?= (int) $row['id'] ?>">
        <?php endforeach ?>

        <div class="bpd-confirm-actions">
            <button type="submit" class="bpd-btn bpd-btn--destructive">
                <?= t('Delete %d project(s) permanently', count($rows)) ?>
            </button>
            <a href="<?= $this->url->href('ProjectListController', 'show') ?>" class="bpd-btn bpd-btn--cancel">
                <?= t('Cancel') ?>
            </a>
        </div>
    </form>

    <?php endif ?>

</div>
