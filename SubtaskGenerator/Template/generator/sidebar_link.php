<?php
// Guard 1: AI features must be enabled (PHP >= 8.4 + API key configured).
if (empty($ai_enabled)) {
    return;
}

// Guard 2: Current user must have edit-level access to the task's project.
// Uses the same helper the core sidebar uses to gate the entire Actions section.
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
