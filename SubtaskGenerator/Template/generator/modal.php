<?php
/**
 * SubtaskGenerator — Generate-subtasks modal
 *
 * Phase 1: "Generate" form — editable prompt textarea + Generate button.
 * Phase 2: Results — candidate checklist (checkbox + editable title per row)
 *           with Regenerate and Create buttons.
 *
 * The JS below drives both phases inline; no build step needed.
 */
?>
<div class="page-header">
    <h2><?= t('Generate subtasks') ?></h2>
</div>

<?php /* ── Phase 1: prompt form ───────────────────────────────────────────── */ ?>
<form method="post"
      id="sg-generate-form"
      action="<?= $this->url->href('GeneratorController', 'generate', ['plugin' => 'SubtaskGenerator', 'task_id' => $task['id']]) ?>">

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
        <?php /* Checked title inputs are appended here dynamically by the JS below */ ?>
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

<script>
(function () {
    'use strict';

    var generateForm  = document.getElementById('sg-generate-form');
    var generateBtn   = document.getElementById('sg-generate-btn');
    var regenerateBtn = document.getElementById('sg-regenerate-btn');
    var createForm    = document.getElementById('sg-create-form');
    var resultsDiv    = document.getElementById('sg-results');
    var candidateList = document.getElementById('sg-candidate-list');
    var hiddenTitles  = document.getElementById('sg-hidden-titles');
    var errorDiv      = document.getElementById('sg-error');
    var loadingDiv    = document.getElementById('sg-loading');

    /**
     * Show / hide helpers.
     */
    function show(el) { el.style.display = ''; }
    function hide(el) { el.style.display = 'none'; }

    /**
     * Render a row for one candidate title.
     * Each row: [ checkbox ] [ editable text input ]
     */
    function renderRow(title, index) {
        var row   = document.createElement('div');
        row.className = 'sg-candidate-row';
        row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:6px;';

        var chk   = document.createElement('input');
        chk.type  = 'checkbox';
        chk.id    = 'sg-chk-' + index;
        chk.checked = true;
        chk.setAttribute('data-index', index);

        var inp   = document.createElement('input');
        inp.type  = 'text';
        inp.className = 'form-control';
        inp.id    = 'sg-title-' + index;
        inp.value = title;
        inp.style.cssText = 'flex:1;';
        inp.setAttribute('data-index', index);

        row.appendChild(chk);
        row.appendChild(inp);
        candidateList.appendChild(row);
    }

    /**
     * Call generate() endpoint; on success render the candidate list.
     */
    function runGenerate() {
        hide(errorDiv);
        hide(resultsDiv);
        show(loadingDiv);

        var formEl = generateForm;
        var data   = new FormData(formEl);

        fetch(formEl.action, {
            method  : 'POST',
            body    : data,
            headers : { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (resp) { return resp.json(); })
        .then(function (json) {
            hide(loadingDiv);

            if (json.error) {
                errorDiv.textContent = json.error;
                show(errorDiv);
                return;
            }

            var subtasks = json.subtasks || [];
            if (subtasks.length === 0) {
                errorDiv.textContent = <?= json_encode(t('No subtasks were generated. Try refining your prompt.')) ?>;
                show(errorDiv);
                return;
            }

            // Render candidate rows.
            candidateList.innerHTML = '';
            subtasks.forEach(function (title, idx) {
                renderRow(title, idx);
            });

            show(resultsDiv);
        })
        .catch(function (err) {
            hide(loadingDiv);
            // Also hide stale results from a prior successful generate so the
            // user does not see outdated candidates after a network error.
            hide(resultsDiv);
            errorDiv.textContent = <?= json_encode(t('Network error. Please try again.')) ?>;
            show(errorDiv);
        });
    }

    /**
     * Before the create form submits, build hidden inputs from checked+edited rows.
     */
    createForm.addEventListener('submit', function (e) {
        // Remove any previously built hidden inputs.
        hiddenTitles.innerHTML = '';

        var rows = candidateList.querySelectorAll('.sg-candidate-row');
        rows.forEach(function (row) {
            var chk = row.querySelector('input[type="checkbox"]');
            var inp = row.querySelector('input[type="text"]');

            if (chk && chk.checked && inp) {
                var title = inp.value.trim();
                if (title !== '') {
                    var hidden   = document.createElement('input');
                    hidden.type  = 'hidden';
                    hidden.name  = 'titles[]';
                    hidden.value = title;
                    hiddenTitles.appendChild(hidden);
                }
            }
        });

        // If nothing is selected / all blank, abort the submit.
        if (hiddenTitles.children.length === 0) {
            e.preventDefault();
            errorDiv.textContent = <?= json_encode(t('Please select at least one subtask to create.')) ?>;
            show(errorDiv);
        }
    });

    // Wire buttons.
    generateBtn.addEventListener('click', function () {
        runGenerate();
    });

    regenerateBtn.addEventListener('click', function () {
        runGenerate();
    });
}());
</script>
