<?php
/**
 * Task-page dependencies panel.
 *
 * Attached to the `template:task:show:before-internal-links` hook, which
 * renders this template with `$task` and `$project` in scope.
 *
 * Renders two status-aware lists: "Blocked by" (tasks this task is blocked
 * by) and "Blocks" (tasks this task blocks) — each row shows the linked
 * task's title as a link plus an open/done status pill. The whole panel is
 * skipped when both lists are empty.
 *
 * No inline <script>: only links/spans are emitted here, which is CSP-safe.
 */
$dep_blockers = $this->dependency->blockers($task);
$dep_blocking = $this->dependency->blocking($task);

if (empty($dep_blockers) && empty($dep_blocking)) {
    return;
}
?>
<details class="accordion-section" open>
    <summary class="accordion-title"><?= t('Dependencies') ?></summary>
    <div class="accordion-content">
        <?php if (! empty($dep_blockers)): ?>
        <div class="dep-panel-section">
            <h4><?= t('Blocked by') ?></h4>
            <ul class="dep-list">
                <?php foreach ($dep_blockers as $row): ?>
                <li class="dep-list-item">
                    <?= $this->url->link(
                        $this->text->e($row['title']),
                        'TaskViewController',
                        'show',
                        array('task_id' => (int) $row['task_id'], 'project_id' => (int) $row['project_id'])
                    ) ?>
                    <?php if ((int) $row['is_active'] === 1): ?>
                        <span class="dep-status dep-open"><?= t('open') ?></span>
                    <?php else: ?>
                        <span class="dep-status dep-done"><?= t('done') ?></span>
                    <?php endif ?>
                </li>
                <?php endforeach ?>
            </ul>
        </div>
        <?php endif ?>

        <?php if (! empty($dep_blocking)): ?>
        <div class="dep-panel-section">
            <h4><?= t('Blocks') ?></h4>
            <ul class="dep-list">
                <?php foreach ($dep_blocking as $row): ?>
                <li class="dep-list-item">
                    <?= $this->url->link(
                        $this->text->e($row['title']),
                        'TaskViewController',
                        'show',
                        array('task_id' => (int) $row['task_id'], 'project_id' => (int) $row['project_id'])
                    ) ?>
                    <?php if ((int) $row['is_active'] === 1): ?>
                        <span class="dep-status dep-open"><?= t('open') ?></span>
                    <?php else: ?>
                        <span class="dep-status dep-done"><?= t('done') ?></span>
                    <?php endif ?>
                </li>
                <?php endforeach ?>
            </ul>
        </div>
        <?php endif ?>
    </div>
</details>
