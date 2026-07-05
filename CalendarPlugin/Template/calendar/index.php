<div class="page-header"><h2><?= $this->text->e($title) ?></h2></div>

<div id="cal-root"
     class="cal-root"
     data-project-id="<?= (int) $project_id ?>"
     data-events-url="<?= $this->text->e($events_url) ?>"
     data-update-url="<?= $this->text->e($update_url) ?>"
     data-unscheduled-url="<?= $this->text->e($unscheduled_url) ?>"
     data-csrf="<?= $this->text->e($csrf) ?>">
    <div id="calendar" class="cal-calendar"></div>
</div>
