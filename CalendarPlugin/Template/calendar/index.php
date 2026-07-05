<div class="page-header"><h2><?= $this->text->e($title) ?></h2></div>

<div id="cal-filterbar" class="cal-filterbar">
    <label for="cal-filter-project"><?= t('Project') ?></label>
    <select id="cal-filter-project" name="project_ids" data-cal-filter="project_ids" multiple>
        <?php foreach ($projects as $id => $name): ?>
            <option value="<?= (int) $id ?>"><?= $this->text->e($name) ?></option>
        <?php endforeach ?>
    </select>

    <label for="cal-filter-assignee"><?= t('Assignee') ?></label>
    <select id="cal-filter-assignee" name="assignee_id" data-cal-filter="assignee_id">
        <option value=""><?= t('All') ?></option>
        <option value="me"><?= t('Me') ?></option>
        <?php foreach ($users as $id => $name): ?>
            <option value="<?= (int) $id ?>"><?= $this->text->e($name) ?></option>
        <?php endforeach ?>
    </select>

    <?php if (! empty($categories)): ?>
    <label for="cal-filter-category"><?= t('Category') ?></label>
    <select id="cal-filter-category" name="category_id" data-cal-filter="category_id">
        <option value=""><?= t('All') ?></option>
        <?php foreach ($categories as $id => $name): ?>
            <option value="<?= (int) $id ?>"><?= $this->text->e($name) ?></option>
        <?php endforeach ?>
    </select>
    <?php endif ?>

    <label for="cal-filter-hide-completed">
        <input type="checkbox" id="cal-filter-hide-completed" name="hide_completed" data-cal-filter="hide_completed" value="1">
        <?= t('Hide completed') ?>
    </label>

    <label class="cal-filter-check"><input type="checkbox" id="cal-toggle-unscheduled" checked> <?= t('Show unscheduled') ?></label>
</div>

<div id="cal-root"
     class="cal-root"
     data-project-id="<?= (int) $project_id ?>"
     data-events-url="<?= $this->text->e($events_url) ?>"
     data-update-url="<?= $this->text->e($update_url) ?>"
     data-unscheduled-url="<?= $this->text->e($unscheduled_url) ?>"
     data-csrf="<?= $this->text->e($csrf) ?>">
    <div class="cal-layout" id="cal-layout">
        <aside class="cal-unscheduled" id="cal-unscheduled" aria-label="<?= t('Unscheduled tasks') ?>">
            <h3><?= t('Unscheduled') ?></h3>
            <div id="cal-unscheduled-list" class="cal-unscheduled-list"></div>
        </aside>
        <div id="calendar" class="cal-calendar"></div>
    </div>
</div>
