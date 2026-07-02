<?php
/**
 * BulkProjectDelete — impact pre-flight confirmation (partial fragment).
 *
 * Shows per-project counts of tasks, subtasks, comments, files, and total file bytes,
 * plus a totals row, a prominent irreversibility warning, and a typed confirmation
 * gate (user must type DELETE to arm the submit button).
 *
 * This is a partial fragment injected into the shared modal by the JS layer —
 * it must NOT contain DOCTYPE/html/head/body. The plugin CSS is injected globally
 * via template:layout:css, so the fragment's classes remain styled.
 *
 * This form POSTs to the remove() endpoint (task-05) with CSRF protection.
 *
 * Safety UX (task-06):
 *   - #bpd-confirm-form     — the POST form; JS submits it when armed.
 *   - #bpd-confirm-input    — typed-confirm input; JS arms/disarms the submit button.
 *   - #bpd-submit-btn       — submit button; starts disabled; armed by JS when input === 'DELETE'.
 *   - data-project-count    — project count for JS/aria messaging.
 *
 * TODO(kbx-tokens): when the ShadcnTheme kbx-* design-token layer ships (plugin #2),
 * replace the self-contained destructive colours on .bpd-btn--destructive with
 * var(--kbx-color-danger), var(--kbx-color-danger-dark), var(--kbx-color-on-danger).
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

$projectCount = count($rows);
?>
<div class="bpd-confirm-wrap">

    <h1 class="bpd-confirm-title"><?= t('Confirm project deletion') ?></h1>

    <?php if (empty($rows)): ?>
        <p><?= t('No valid projects were selected.') ?></p>
        <p><a href="<?= $this->url->href('ProjectListController', 'show') ?>"><?= t('Back to project list') ?></a></p>
    <?php else: ?>

    <!-- Prominent irreversibility warning — unmissable, role=alert announces on focus entry -->
    <div class="bpd-confirm-warning bpd-confirm-warning--critical" role="alert" aria-live="assertive">
        <strong class="bpd-warning-headline">
            <?= t('This permanently deletes %d project(s) and all their data. This cannot be undone.', $projectCount) ?>
        </strong>
        <span class="bpd-warning-detail">
            <?= t('All tasks, subtasks, comments, and files will be permanently destroyed.') ?>
        </span>
    </div>

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
                <th><?= t('Totals (%d project(s))', $projectCount) ?></th>
                <th class="bpd-col-num"><?= $totals['tasks'] ?></th>
                <th class="bpd-col-num"><?= $totals['subtasks'] ?></th>
                <th class="bpd-col-num"><?= $totals['comments'] ?></th>
                <th class="bpd-col-num"><?= $totals['files'] ?></th>
                <th class="bpd-col-num"><?= bpd_format_bytes($totals['bytes']) ?></th>
            </tr>
        </tfoot>
    </table>

    <form
        id="bpd-confirm-form"
        method="post"
        action="<?= $this->url->href('BulkDeleteController', 'remove', ['plugin' => 'BulkProjectDelete']) ?>"
        data-project-count="<?= $projectCount ?>"
    >
        <?= $this->form->csrf() ?>

        <?php foreach ($rows as $row): ?>
        <input type="hidden" name="project_ids[]" value="<?= (int) $row['id'] ?>">
        <?php endforeach ?>

        <!-- Typed confirmation gate (task-06).
             The submit button (#bpd-submit-btn) starts disabled and is armed only when
             the user types DELETE (exact, case-sensitive) into this input.
             JS in bulk-delete.js wires the input → arm/disarm logic. -->
        <div class="bpd-typed-confirm">
            <label for="bpd-confirm-input" class="bpd-typed-confirm__label">
                <?= t('Type') ?> <strong>DELETE</strong> <?= t('to confirm deletion:') ?>
            </label>
            <input
                id="bpd-confirm-input"
                type="text"
                name="confirm_word"
                class="bpd-typed-confirm__input"
                autocomplete="off"
                autocorrect="off"
                autocapitalize="off"
                spellcheck="false"
                placeholder="DELETE"
                aria-required="true"
                aria-describedby="bpd-confirm-hint"
            >
            <span id="bpd-confirm-hint" class="bpd-typed-confirm__hint">
                <?= t('The delete button will activate once you type DELETE exactly.') ?>
            </span>
        </div>

        <div class="bpd-confirm-actions">
            <!-- TODO(kbx-tokens): apply kbx- destructive token class alongside bpd-btn--destructive
                 once plugin #2 exports var(--kbx-color-danger) et al. -->
            <button
                id="bpd-submit-btn"
                type="submit"
                class="bpd-btn bpd-btn--destructive"
                disabled
                aria-disabled="true"
            >
                <?= t('Delete %d project(s) permanently', $projectCount) ?>
            </button>
            <a
                href="<?= $this->url->href('ProjectListController', 'show') ?>"
                class="bpd-btn bpd-btn--cancel"
            ><?= t('Cancel') ?></a>
        </div>
    </form>

    <?php endif ?>

</div>
