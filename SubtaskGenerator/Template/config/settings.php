<div class="page-header">
    <h2><?= t('Subtask Generator — Settings') ?></h2>
</div>

<?php if (! $ai_enabled): ?>
    <div class="alert alert-info">
        <p>
            <strong><?= t('AI features are disabled.') ?></strong>
            <?= t('The Subtask Generator requires PHP >= 8.4. The current runtime is PHP %s.', PHP_VERSION) ?>
        </p>
    </div>
<?php else: ?>

<p class="form-help">
    <?= t('Choose the LLM provider used to automatically generate subtasks from a task description.') ?>
    <?= t('API keys are stored securely and are never displayed after saving.') ?>
</p>

<form method="post"
      action="<?= $this->url->href('SettingsController', 'save', ['plugin' => 'SubtaskGenerator']) ?>">

    <?= $this->form->csrf() ?>

    <?php /* ── Provider ─────────────────────────────────────────────────── */ ?>
    <fieldset>
        <legend><?= t('Provider') ?></legend>

        <?= $this->form->label(t('LLM Provider'), 'sg_provider') ?>
        <select name="sg_provider" id="sg_provider" class="auto-select" data-defaults='<?= htmlspecialchars(json_encode($default_models), ENT_QUOTES) ?>'>
            <?php foreach ($providers as $key => $label): ?>
                <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                    <?= ($sg_provider === $key) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES) ?>
                </option>
            <?php endforeach ?>
        </select>
        <p class="form-help"><?= t('Anthropic, OpenAI, or Grok (xAI). Default: Anthropic.') ?></p>
    </fieldset>

    <?php /* ── Model ────────────────────────────────────────────────────── */ ?>
    <fieldset>
        <legend><?= t('Model') ?></legend>

        <?= $this->form->label(t('Model name'), 'sg_model') ?>
        <input type="text"
               name="sg_model"
               id="sg_model"
               value="<?= htmlspecialchars($sg_model, ENT_QUOTES) ?>"
               class="form-text"
               placeholder="<?= htmlspecialchars($default_models[$sg_provider] ?? '', ENT_QUOTES) ?>">
        <p class="form-help">
            <?= t('Examples: %s (Anthropic), %s (OpenAI), %s (Grok)',
                'claude-sonnet-4-20250514',
                'gpt-4o',
                'grok-3') ?>
        </p>
    </fieldset>

    <?php /* ── API Key ──────────────────────────────────────────────────── */ ?>
    <fieldset>
        <legend><?= t('API Key') ?></legend>

        <?= $this->form->label(t('API Key'), 'sg_api_key') ?>

        <?php if ($sg_key_is_set): ?>
            <p class="form-help" style="color:green;">
                <?= t('An API key is stored. Leave the field below blank to keep it, or enter a new key to replace it.') ?>
                <?= t('You may also set %s / %s / %s as an environment variable fallback.', 'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'XAI_API_KEY') ?>
            </p>
            <input type="password"
                   name="sg_api_key"
                   id="sg_api_key"
                   value=""
                   class="form-text"
                   placeholder="<?= t('Leave blank to keep the current key') ?>"
                   autocomplete="new-password">
        <?php else: ?>
            <p class="form-help">
                <?= t('Enter the API key for the selected provider. If left blank, the environment variable (%s / %s / %s) will be used as a fallback.', 'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'XAI_API_KEY') ?>
            </p>
            <input type="password"
                   name="sg_api_key"
                   id="sg_api_key"
                   value=""
                   class="form-text"
                   placeholder="<?= t('Paste your API key here') ?>"
                   autocomplete="new-password">
        <?php endif ?>
    </fieldset>

    <?php /* ── Limits ───────────────────────────────────────────────────── */ ?>
    <fieldset>
        <legend><?= t('Generation Limits') ?></legend>

        <?= $this->form->label(t('Maximum subtasks to generate'), 'sg_max_subtasks') ?>
        <input type="number"
               name="sg_max_subtasks"
               id="sg_max_subtasks"
               value="<?= (int) $sg_max_subtasks ?>"
               min="1"
               max="20"
               class="form-text">
        <p class="form-help"><?= t('Maximum number of subtasks the AI may suggest (1–20). Default: 8.') ?></p>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save Settings') ?></button>
        <?= $this->url->link(t('Cancel'), 'ConfigController', 'index') ?>
    </div>

</form>

<hr>

<?php /* ── Test Connection ────────────────────────────────────────────────
     Interactivity lives in the external, CSP-safe Assets/js/subtask-generator.js
     (Kanboard's default-src 'self' CSP blocks inline <script>). The test URL —
     including a reusable CSRF token generated in the controller — and the i18n
     strings are passed via data-* attributes. Provider→model auto-fill is wired
     from the same external script using #sg_provider[data-defaults]. */ ?>
<h3><?= t('Test Connection') ?></h3>
<p class="form-help"><?= t('Verify that the saved provider settings and API key work correctly.') ?></p>

<div id="sg-test-result" style="display:none; padding:8px; border-radius:4px; margin-bottom:12px;"></div>

<button type="button"
        class="btn btn-blue"
        id="sg-test-btn"
        data-test-url="<?= $this->url->href('SettingsController', 'testConnection', ['plugin' => 'SubtaskGenerator', 'csrf_token' => $sg_test_csrf]) ?>"
        data-msg-ok="<?= $this->text->e(t('Connection successful.')) ?>"
        data-msg-fail="<?= $this->text->e(t('Connection failed:')) ?>"
        data-msg-unknown="<?= $this->text->e(t('Unknown error')) ?>"
        data-msg-request-failed="<?= $this->text->e(t('Request failed:')) ?>">
    <?= t('Test Connection') ?>
</button>

<?php endif ?>
