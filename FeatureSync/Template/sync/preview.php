<?php
/**
 * FeatureSync — Step 4: Dry-run preview
 *
 * Renders a per-target table showing what will change (add / replace / skip) for
 * each selected feature. No writes occur during preview.
 *
 * Variables provided by FeatureSyncController::preview():
 *   string   $sourceProjectName   Human-readable source project name.
 *   int      $sourceProjectId
 *   string[] $selectedFeatures    Feature keys selected by the user.
 *   int[]    $targetProjectIds
 *   string   $syncMode            'add_missing' or 'replace'
 *   string[] $featureList         [feature_key => label]
 *   array[]  $previewRows         [targetId => [project_id, project_name, features[], column_risks[]]]
 *   array    $postValues          Original POST values (passed through to the apply form).
 */
?>

<div class="page-header">
    <h2><?= t('Feature Sync') ?> &mdash; <?= t('Step 4: Preview Changes') ?></h2>
</div>

<p class="form-help">
    <?= t('This is a read-only preview. Nothing has been written to the database.') ?>
    <?= t('Review the changes below, then click "Confirm & Apply" to proceed (or "Back" to adjust your selection).') ?>
</p>

<?php /* ── Summary banner ───────────────────────────────────────────── */ ?>
<div class="fs-preview-summary listing">
    <dl class="fs-preview-meta">
        <dt><?= t('Source project') ?></dt>
        <dd><strong><?= $this->text->e($sourceProjectName) ?></strong></dd>

        <dt><?= t('Sync mode') ?></dt>
        <dd>
            <?php if ($syncMode === 'replace'): ?>
                <span class="fs-destructive-label">&#9888; <?= t('Replace (destructive)') ?></span>
            <?php else: ?>
                <?= t('Add missing (non-destructive)') ?>
            <?php endif ?>
        </dd>

        <dt><?= t('Features') ?></dt>
        <dd>
            <?php foreach ($selectedFeatures as $f): ?>
                <span class="fs-badge"><?= $this->text->e(isset($featureList[$f]) ? $featureList[$f] : $f) ?></span>
            <?php endforeach ?>
        </dd>

        <dt><?= t('Target projects') ?></dt>
        <dd><?= count($targetProjectIds) ?></dd>
    </dl>
</div>

<?php /* ── Per-target preview tables ──────────────────────────────────── */ ?>
<?php foreach ($previewRows as $targetId => $row): ?>
    <?php $hasRisk = ! empty($row['column_risks']); ?>

    <section class="accordion fs-preview-target <?= $hasRisk ? 'fs-preview-target--risky' : '' ?>">
        <div class="accordion-title">
            <?php if ($hasRisk): ?>
                <span class="fs-risk-badge" title="<?= t('Risk: tasks exist in columns that would be removed') ?>">&#9888; <?= t('Risk') ?></span>
            <?php endif ?>
            <strong><?= $this->text->e($row['project_name']) ?></strong>
        </div>

        <div class="accordion-content">

            <?php if ($hasRisk): ?>
                <div class="alert alert-danger fs-risk-alert">
                    <p><strong><?= t('Risk: Replace Columns with Tasks') ?></strong></p>
                    <p><?= t('The following target columns contain open tasks and would be removed when columns are replaced. Tasks in removed columns will lose their column assignment.') ?></p>
                    <ul>
                        <?php foreach ($row['column_risks'] as $colId => $risk): ?>
                            <li>
                                <strong><?= $this->text->e($risk['column']['title']) ?></strong>
                                &mdash; <?= (int)$risk['task_count'] ?> <?= t('open task(s)') ?>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </div>
            <?php endif ?>

            <table class="table fs-preview-table">
                <thead>
                    <tr>
                        <th><?= t('Feature') ?></th>
                        <?php if ($syncMode === 'replace'): ?>
                            <th class="fs-col-add"><?= t('Source items (will add)') ?></th>
                            <th class="fs-col-replace"><?= t('Target items (will remove)') ?></th>
                        <?php else: ?>
                            <th class="fs-col-add"><?= t('Will add') ?></th>
                            <th class="fs-col-skip"><?= t('Already present (skip)') ?></th>
                        <?php endif ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($selectedFeatures as $feature): ?>
                        <?php if (! isset($row['features'][$feature])): ?>
                            <tr>
                                <td><?= $this->text->e(isset($featureList[$feature]) ? $featureList[$feature] : $feature) ?></td>
                                <td colspan="2"><em><?= t('n/a') ?></em></td>
                            </tr>
                        <?php continue; endif ?>

                        <?php $d = $row['features'][$feature] ?>

                        <tr>
                            <td><?= $this->text->e(isset($featureList[$feature]) ? $featureList[$feature] : $feature) ?></td>

                            <?php if ($syncMode === 'replace'): ?>
                                <td class="fs-col-add">
                                    <?php if ($d['count_add'] > 0): ?>
                                        <span class="fs-count fs-count--add"><?= $d['count_add'] ?></span>
                                    <?php else: ?>
                                        <span class="fs-count fs-count--zero">0</span>
                                    <?php endif ?>
                                </td>
                                <td class="fs-col-replace">
                                    <?php if ($d['count_replace'] > 0): ?>
                                        <span class="fs-count fs-count--replace <?= ($feature === 'columns' && $hasRisk) ? 'fs-count--risky' : '' ?>">
                                            <?= $d['count_replace'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="fs-count fs-count--zero">0</span>
                                    <?php endif ?>
                                </td>
                            <?php else: ?>
                                <td class="fs-col-add">
                                    <?php if ($d['count_add'] > 0): ?>
                                        <span class="fs-count fs-count--add"><?= $d['count_add'] ?></span>
                                    <?php else: ?>
                                        <span class="fs-count fs-count--zero">0</span>
                                    <?php endif ?>
                                </td>
                                <td class="fs-col-skip">
                                    <?php if ($d['count_skip'] > 0): ?>
                                        <span class="fs-count fs-count--skip"><?= $d['count_skip'] ?></span>
                                    <?php else: ?>
                                        <span class="fs-count fs-count--zero">0</span>
                                    <?php endif ?>
                                </td>
                            <?php endif ?>
                        </tr>

                    <?php endforeach ?>
                </tbody>
            </table>

        </div><!-- /.accordion-content -->
    </section>

<?php endforeach ?>

<?php /* ── Form actions ──────────────────────────────────────────────── */ ?>
<div class="form-actions">

    <?php /* "Confirm & Apply" — POST to apply (task-05). Passes through all params. */ ?>
    <form method="post"
          action="<?= $this->url->href('FeatureSyncController', 'apply', ['plugin' => 'FeatureSync']) ?>"
          class="fs-apply-form"
          id="fs-apply-form">
        <?= $this->form->csrf() ?>

        <input type="hidden" name="source_project_id" value="<?= (int)$sourceProjectId ?>">
        <input type="hidden" name="sync_mode" value="<?= $this->text->e($syncMode) ?>">

        <?php foreach ($selectedFeatures as $f): ?>
            <input type="hidden" name="features[]" value="<?= $this->text->e($f) ?>">
        <?php endforeach ?>

        <?php foreach ($targetProjectIds as $tid): ?>
            <input type="hidden" name="target_project_ids[]" value="<?= (int)$tid ?>">
        <?php endforeach ?>

        <button type="submit" class="btn btn-red fs-btn-apply">
            <?= t('Confirm &amp; Apply') ?>
        </button>
    </form>

    <a href="<?= $this->url->href('FeatureSyncController', 'index', ['plugin' => 'FeatureSync']) ?>"
       class="btn btn-grey fs-btn-back">
        &larr; <?= t('Back') ?>
    </a>

</div>
