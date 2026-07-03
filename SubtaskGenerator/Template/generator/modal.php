<div class="page-header">
    <h2><?= t('Generate subtasks') ?></h2>
</div>

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

    <?= $this->modal->submitButtons(['submitLabel' => t('Generate'), 'color' => 'green']) ?>
</form>
