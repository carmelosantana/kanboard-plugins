<?php
/**
 * SubtaskGenerator — Generate-subtasks modal
 *
 * Phase 1: "Generate" form — editable prompt textarea + Generate button.
 * Phase 2: Results — candidate checklist (checkbox + editable title per row)
 *           with Regenerate and Create buttons.
 *
 * All interaction is handled by the external CSP-safe script injected via
 * template:layout:js in Plugin.php (Assets/js/subtask-generator.js).
 * It uses event delegation on `document` so it fires even though this modal
 * HTML is injected dynamically via innerHTML by Kanboard's popover/modal system.
 *
 * Server data is passed to the JS via data attributes on #sg-generate-form
 * (generate/create URLs and localised error messages). The CSRF token is read
 * from the hidden csrf_token input already present in the form.
 */
?>
<div class="page-header">
    <h2><?= t('Generate subtasks') ?></h2>
</div>

<?php /* ── Phase 1: prompt form ───────────────────────────────────────────── */ ?>
<form method="post"
      id="sg-generate-form"
      action="<?= $this->url->href('GeneratorController', 'generate', ['plugin' => 'SubtaskGenerator', 'task_id' => $task['id']]) ?>"
      data-task-id="<?= $this->text->e($task['id']) ?>"
      data-msg-empty="<?= $this->text->e(t('No subtasks were generated. Try refining your prompt.')) ?>"
      data-msg-network="<?= $this->text->e(t('Network error. Please try again.')) ?>"
      data-msg-none-selected="<?= $this->text->e(t('Please select at least one subtask to create.')) ?>">

    <?= $this->form->csrf() ?>

    <input type="hidden" name="task_id" value="<?= $this->text->e($task['id']) ?>">

    <div class="form-group">
        <?= $this->form->label(t('Prompt'), 'sg_prompt') ?>
        <textarea id="sg_prompt"
                  name="sg_prompt"
                  class="form-control"
                  rows="8"
                  placeholder="<?= t('Describe what subtasks to generate...') ?>"
        ><?= $this->text->e($sg_prompt) ?></textarea>
        <p class="form-help"><?= t('Edit the prompt above before generating subtasks.') ?></p>
    </div>

    <div class="form-actions">
        <button type="button" id="sg-generate-btn" class="btn btn-blue">
            <?= t('Generate') ?>
        </button>
        <a href="#" class="close-popover"><?= t('Cancel') ?></a>
    </div>
</form>

<?php /* ── Phase 2: results checklist (hidden until generate returns) ──────── */ ?>
<div id="sg-results" style="display:none; margin-top:16px;">
    <p class="form-help" id="sg-results-label">
        <?= t('Review the suggested subtasks. Uncheck any you do not want, then click Create.') ?>
    </p>

    <div id="sg-candidate-list" style="margin-bottom:12px;"></div>

    <?php /* Create form — posts selected/edited titles to create() */ ?>
    <form method="post"
          id="sg-create-form"
          action="<?= $this->url->href('GeneratorController', 'create', ['plugin' => 'SubtaskGenerator', 'task_id' => $task['id']]) ?>">

        <?= $this->form->csrf() ?>

        <input type="hidden" name="task_id" value="<?= $this->text->e($task['id']) ?>">
        <?php /* titles[] hidden inputs are appended here dynamically by Assets/js/subtask-generator.js */ ?>
        <div id="sg-hidden-titles"></div>

        <div class="form-actions" style="margin-top:8px;">
            <button type="button" id="sg-regenerate-btn" class="btn btn-grey">
                <?= t('Regenerate') ?>
            </button>
            <button type="submit" id="sg-create-btn" class="btn btn-blue">
                <?= t('Create') ?>
            </button>
            <a href="#" class="close-popover"><?= t('Cancel') ?></a>
        </div>
    </form>
</div>

<div id="sg-error" style="display:none;" class="alert alert-error"></div>
<div id="sg-loading" style="display:none;" class="alert alert-info">
    <?= t('Generating subtasks, please wait…') ?>
</div>
