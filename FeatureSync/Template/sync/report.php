<?php
/**
 * FeatureSync — Step 5: Apply Report
 *
 * Renders a per-target result table after apply() has run.
 * Shows success/failure per target and per-feature item counts.
 *
 * Variables provided by FeatureSyncController::apply():
 *   string   $sourceProjectName   Human-readable source project name.
 *   int      $sourceProjectId
 *   string[] $selectedFeatures    Feature keys that were applied.
 *   int[]    $targetProjectIds
 *   string   $syncMode            'add_missing' or 'replace'
 *   string[] $featureList         [feature_key => label]
 *   array[]  $applyReport         [targetId => ['status', 'features', 'error']]
 *   array    $projects            [projectId => projectName]
 *   int      $successCount
 *   int      $failureCount
 */
?>

<div class="page-header">
    <h2><?= t('Feature Sync') ?> &mdash; <?= t('Step 5: Apply Report') ?></h2>
</div>

<?php /* ── Flash messages are rendered by the layout automatically ── */ ?>

<?php /* ── Summary banner ──────────────────────────────────────────────────── */ ?>
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

        <dt><?= t('Features applied') ?></dt>
        <dd>
            <?php foreach ($selectedFeatures as $f): ?>
                <span class="fs-badge"><?= $this->text->e(isset($featureList[$f]) ? $featureList[$f] : $f) ?></span>
            <?php endforeach ?>
        </dd>

        <dt><?= t('Results') ?></dt>
        <dd>
            <?php if ($successCount > 0): ?>
                <span class="fs-count fs-count--add"><?= $successCount ?> <?= t('succeeded') ?></span>
            <?php endif ?>
            <?php if ($failureCount > 0): ?>
                <span class="fs-count fs-count--replace"><?= $failureCount ?> <?= t('failed') ?></span>
            <?php endif ?>
        </dd>
    </dl>
</div>

<?php /* ── Per-target result tables ──────────────────────────────────────────── */ ?>
<?php foreach ($applyReport as $targetId => $result): ?>
    <?php
        $targetId   = (int) $targetId;
        $isError    = $result['status'] === 'error';
        $targetName = isset($projects[$targetId]) ? $projects[$targetId] : "#{$targetId}";
    ?>

    <section class="accordion fs-preview-target <?= $isError ? 'fs-preview-target--risky' : '' ?>">
        <div class="accordion-title">
            <?php if ($isError): ?>
                <span class="fs-risk-badge">&#10007; <?= t('Failed') ?></span>
            <?php elseif ($result['status'] === 'partial'): ?>
                <span class="fs-risk-badge">&#9888; <?= t('Partial') ?></span>
            <?php else: ?>
                <span class="fs-count fs-count--add" style="margin-right:6px">&#10003;</span>
            <?php endif ?>
            <strong><?= $this->text->e($targetName) ?></strong>
        </div>

        <div class="accordion-content">

            <?php if ($isError): ?>
                <div class="alert alert-danger fs-risk-alert">
                    <p><strong><?= t('Error') ?>:</strong> <?= $this->text->e($result['error']) ?></p>
                    <p><?= t('This target failed before any features could be applied. Other targets were not affected.') ?></p>
                </div>
            <?php elseif ($result['status'] === 'partial'): ?>
                <div class="alert alert-warning fs-risk-alert">
                    <p><?= t('One or more features failed for this target (see table below). Other features that succeeded were written and cannot be automatically undone.') ?></p>
                </div>
            <?php endif ?>

            <?php if (! empty($result['features'])): ?>
                <table class="table fs-preview-table">
                    <thead>
                        <tr>
                            <th><?= t('Feature') ?></th>
                            <th class="fs-col-add">
                                <?php if ($syncMode === 'replace'): ?>
                                    <?= t('Items added (after replace clear)') ?>
                                <?php else: ?>
                                    <?= t('Items added (missing only)') ?>
                                <?php endif ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($selectedFeatures as $feature): ?>
                            <?php if (! isset($result['features'][$feature])): ?>
                                <tr>
                                    <td><?= $this->text->e(isset($featureList[$feature]) ? $featureList[$feature] : $feature) ?></td>
                                    <td><em><?= t('not reached (error above)') ?></em></td>
                                </tr>
                            <?php continue; endif ?>

                            <?php $count = $result['features'][$feature] ?>
                            <tr>
                                <td><?= $this->text->e(isset($featureList[$feature]) ? $featureList[$feature] : $feature) ?></td>
                                <td class="fs-col-add">
                                    <?php if ($count > 0): ?>
                                        <span class="fs-count fs-count--add"><?= (int)$count ?></span>
                                    <?php else: ?>
                                        <span class="fs-count fs-count--zero">0</span>
                                    <?php endif ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            <?php endif ?>

        </div><!-- /.accordion-content -->
    </section>

<?php endforeach ?>

<?php /* ── Navigation ──────────────────────────────────────────────────────── */ ?>
<div class="form-actions">
    <a href="<?= $this->url->href('FeatureSyncController', 'index', ['plugin' => 'FeatureSync']) ?>"
       class="btn btn-blue">
        &larr; <?= t('Back to Feature Sync') ?>
    </a>
</div>
