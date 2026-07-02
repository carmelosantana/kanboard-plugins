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
                    <select id="source_project_id" name="source_project_id" class="form-input">
                        <?php foreach ($projects as $projectId => $projectName): ?>
                            <option value="<?= $this->text->e($projectId) ?>"
                                <?= (int)$projectId === $sourceProjectId ? 'selected' : '' ?>>
                                <?= $this->text->e($projectName) ?>
                            </option>
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

        <?php /* ── Step 3: Target Projects ──────────────────────────────────────── */ ?>
        <section class="accordion">
            <div class="accordion-title">
                <strong><?= t('Step 3') ?></strong> &mdash; <?= t('Select Target Projects') ?>
            </div>
            <div class="accordion-content">
                <p class="form-help"><?= t('(Coming in task-03)') ?></p>
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
        <button type="submit" class="btn btn-blue"><?= t('Continue') ?></button>
    </div>

</form>
