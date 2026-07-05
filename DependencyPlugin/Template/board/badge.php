<?php
/**
 * Board card badge partial.
 *
 * Attached to the `template:board:private:task:before-title` hook, which
 * renders this template with `$task` (full task row) in scope.
 *
 * No inline <script>: only text/class/title are emitted here, which is
 * CSP-safe (inline style/markup is allowed; inline scripts are not).
 */
$open = $this->dependency->blockedOpenCount($task);
if ($open > 0): ?>
<span class="dep-badge dep-blocked" title="<?= t('Blocked by %d open task(s)', $open) ?>">🔒 <?= $open ?></span>
<?php endif ?>
