<p class="activity-title">
    <span title="<?= t('Automated') ?>">&#9200;</span>
    <?= t('Scheduler rescheduled %d task(s)', isset($data['count']) ? (int) $data['count'] : 0) ?>
</p>
<p class="activity-description text-muted">
    <?= $this->dt->datetime($date_creation) ?>
</p>
