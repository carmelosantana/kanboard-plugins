<?php
// Guard 1: AI features must be enabled (PHP >= 8.4 + AiConnector present + provider configured).
// $ai_enabled is set by Plugin::initialize() via AiGate::isReady() — the same
// gate that GeneratorController::isAiEnabled() uses — so the link is visible if and only
// if the controller will honour the request.
if (empty($ai_enabled)) {
    return;
}

// Guard 2: Current user must have edit-level access to the task's project.
// NOTE: the outer sidebar section (app/Template/task/sidebar.php) already gates the
// entire Actions block on hasProjectAccess, so this check is redundant with that outer
// gate. It is kept here as an independent defence-in-depth parity check — it does NOT
// mean this partial is responsible for performing the outer sidebar's access control.
if (! $this->user->hasProjectAccess('TaskModificationController', 'edit', $task['project_id'])) {
    return;
}
?>
<li>
    <?= $this->modal->medium(
        'magic',
        t('Generate subtasks'),
        'GeneratorController',
        'show',
        ['plugin' => 'SubtaskGenerator', 'task_id' => $task['id']]
    ) ?>
</li>
