<div class="page-header">
    <h2><?= t('Subtask Generator — Settings') ?></h2>
</div>

<div class="alert alert-info">
    <?= t('Provider setup (Anthropic, OpenAI, Grok, Gemini, Mistral, Ollama, …) now lives in the AI Connector plugin.') ?>
    <?= $this->url->link(t('Open AI Connector settings'), 'SettingsController', 'show', ['plugin' => 'AiConnector']) ?>
</div>

<form method="post"
      action="<?= $this->url->href('SettingsController', 'save', ['plugin' => 'SubtaskGenerator']) ?>">
    <?= $this->form->csrf() ?>

    <fieldset>
        <legend><?= t('Generation Limits') ?></legend>
        <?= $this->form->label(t('Maximum subtasks to generate'), 'sg_max_subtasks') ?>
        <input type="number" name="sg_max_subtasks" id="sg_max_subtasks"
               value="<?= (int) $sg_max_subtasks ?>" min="1" max="20" class="form-text">
        <p class="form-help"><?= t('Maximum number of subtasks the AI may suggest (1–20). Default: 8.') ?></p>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save Settings') ?></button>
        <?= $this->url->link(t('Cancel'), 'ConfigController', 'index') ?>
    </div>
</form>
