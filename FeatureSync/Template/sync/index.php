<div class="page-header">
    <h2><?= t('Feature Sync') ?></h2>
</div>

<p class="form-help">
    <?= t('Bulk-copy project features — automated actions, tags, and columns — from a source project to many target projects in one operation.') ?>
</p>

<form method="post" action="<?= $this->url->href('FeatureSyncController', 'index', [], 'FeatureSync') ?>">
    <?= $this->form->csrf() ?>

    <div class="listing">

        <?php /* ── Step 1: Source Project ──────────────────────────────────────── */ ?>
        <section class="accordion">
            <div class="accordion-title">
                <strong><?= t('Step 1') ?></strong> &mdash; <?= t('Select Source Project') ?>
            </div>
            <div class="accordion-content">
                <p class="form-help">
                    <?= t('Choose the project whose features you want to copy to other projects.') ?>
                </p>

                <div class="form-column">
                    <label for="source_project_id"><?= t('Source Project') ?></label>
                    <select id="fs-source-project" name="source_project_id" class="form-input">
                        <option value="0"><?= t('— choose a project —') ?></option>
                        <?php foreach ($projects as $projectId => $projectName): ?>
                            <?php if ((int)$projectId > 0): ?>
                            <option value="<?= $this->text->e($projectId) ?>"
                                <?= (int)$projectId === $sourceProjectId ? 'selected' : '' ?>>
                                <?= $this->text->e($projectName) ?>
                            </option>
                            <?php endif ?>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>
        </section>

        <?php /* ── Step 2: Features ────────────────────────────────────────────── */ ?>
        <section class="accordion">
            <div class="accordion-title">
                <strong><?= t('Step 2') ?></strong> &mdash; <?= t('Choose Features to Copy') ?>
            </div>
            <div class="accordion-content">
                <p class="form-help">
                    <?= t('Select which feature types to copy from the source project.') ?>
                </p>

                <ul class="listing">
                    <?php foreach ($featureList as $featureKey => $featureLabel): ?>
                        <li>
                            <label>
                                <input type="checkbox"
                                       name="features[]"
                                       value="<?= $this->text->e($featureKey) ?>"
                                       <?= in_array($featureKey, $selectedFeatures, true) ? 'checked' : '' ?>>
                                <?= $this->text->e($featureLabel) ?>
                            </label>
                        </li>
                    <?php endforeach ?>
                </ul>
            </div>
        </section>

        <?php /* ── Step 3: Target Projects + Sync Mode ───────────────────────────── */ ?>
        <section class="accordion" id="fs-step3">
            <div class="accordion-title">
                <strong><?= t('Step 3') ?></strong> &mdash; <?= t('Select Target Projects &amp; Sync Mode') ?>
            </div>
            <div class="accordion-content">

                <?php /* Mode selector */ ?>
                <div class="fs-mode-selector" role="group" aria-labelledby="fs-mode-label">
                    <p id="fs-mode-label" class="form-help"><strong><?= t('Sync Mode') ?></strong></p>

                    <label class="fs-mode-option">
                        <input type="radio"
                               name="sync_mode"
                               value="add_missing"
                               <?= $syncMode === 'add_missing' ? 'checked' : '' ?>>
                        <strong><?= t('Add missing') ?></strong>
                        &mdash; <?= t('Only add items that do not already exist in the target project. Existing items are never removed.') ?>
                    </label>

                    <label class="fs-mode-option fs-mode-option--destructive">
                        <input type="radio"
                               name="sync_mode"
                               value="replace"
                               <?= $syncMode === 'replace' ? 'checked' : '' ?>>
                        <strong class="fs-destructive-label"><?= t('Replace') ?> &#9888;</strong>
                        &mdash; <span class="fs-destructive-warning"><?= t('DESTRUCTIVE: existing items in the target project will be removed and replaced with the source project\'s items.') ?></span>
                    </label>
                </div>

                <hr class="fs-divider">

                <?php /* Target project list */ ?>
                <p class="form-help">
                    <?= t('Select one or more target projects to receive the features. The source project is excluded from this list.') ?>
                </p>

                <?php if (empty($targetProjects)): ?>
                    <p class="alert alert-info">
                        <?= t('No other projects available. Create additional projects first, then return here to sync features.') ?>
                    </p>
                <?php else: ?>

                    <!-- Action bar: select-all + counter -->
                    <div class="fs-target-toolbar" id="fs-target-toolbar">
                        <label class="fs-select-all-label">
                            <input type="checkbox" id="fs-select-all" aria-label="<?= t('Select all target projects') ?>">
                            <?= t('Select all') ?>
                        </label>
                        <span class="fs-count-badge" id="fs-count-badge" aria-live="polite">
                            <span id="fs-count-label">0</span> <?= t('selected') ?>
                        </span>
                    </div>

                    <!-- Project rows -->
                    <div class="fs-target-list" id="fs-target-list" role="group" aria-label="<?= t('Target projects') ?>">
                        <?php foreach ($targetProjects as $projectId => $projectName): ?>
                            <label class="fs-target-row" data-project-id="<?= (int)$projectId ?>">
                                <input type="checkbox"
                                       class="fs-target-cb"
                                       name="target_project_ids[]"
                                       value="<?= (int)$projectId ?>"
                                       aria-label="<?= $this->text->e($projectName) ?>"
                                       <?= in_array((int)$projectId, $targetProjectIds, true) ? 'checked' : '' ?>>
                                <?= $this->text->e($projectName) ?>
                            </label>
                        <?php endforeach ?>
                    </div>

                <?php endif ?>

            </div>
        </section>

        <?php /* ── Step 4: Preview ─────────────────────────────────────────────── */ ?>
        <section class="accordion">
            <div class="accordion-title">
                <strong><?= t('Step 4') ?></strong> &mdash; <?= t('Preview Changes') ?>
            </div>
            <div class="accordion-content">
                <p class="form-help"><?= t('Dry-run diff per target — (Coming in task-04)') ?></p>
            </div>
        </section>

        <?php /* ── Step 5: Apply ──────────────────────────────────────────────── */ ?>
        <section class="accordion">
            <div class="accordion-title">
                <strong><?= t('Step 5') ?></strong> &mdash; <?= t('Apply') ?>
            </div>
            <div class="accordion-content">
                <p class="form-help"><?= t('Apply features (add-missing or replace) — (Coming in task-05)') ?></p>
            </div>
        </section>

    </div>

    <div class="form-actions">
        <button type="submit" name="step" value="preview" class="btn btn-blue"><?= t('Continue to Preview') ?></button>
    </div>

</form>

<?= $this->app->component('fs-target-select', [
    'sourceProjectId' => $sourceProjectId,
]) ?>
