<?php /** Open a fiscal year (screen `fiscal-year-add`). Data: start, years */ ?>
<?= view('shared/header.php', ['id' => 'fiscal-year-add', 'title' => 'Open a fiscal year', 'crumbs' => [['Home', '/'], ['Periods', '/periods/'], ['Open a fiscal year', null]], 'back' => back_link() ?? ['/periods/', 'Periods']]) ?>
<div class="main-content" id="fiscal-year-add-content">
    <form method="post" action="/periods/save.php" hx-post="/periods/save.php" hx-target="#flash" id="fiscal-year-form">
        <?= csrf_field() ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The year</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="fiscal-year-form-field-start">Starts on (the first of a month; twelve monthly periods follow)</label>
            <input type="date" name="start" id="fiscal-year-form-field-start" class="form-control btn-touch mb-3" required value="<?= e($start) ?>">
            <label class="form-label fs-12 text-muted" for="fiscal-year-form-field-name">Name (left blank: FY and the year it ends in)</label>
            <input type="text" name="name" id="fiscal-year-form-field-name" class="form-control btn-touch" maxlength="40" placeholder="FY<?= e(date('Y', strtotime($start . ' +1 year -1 day'))) ?>">
            <?php if ($years !== []): ?><div class="fs-12 text-muted mt-3" id="fiscal-year-form-existing">Two fiscal years never overlap. The latest, <?= e($years[0]['name']) ?>, ends <?= e(format_date($years[0]['end_date'])) ?>.</div><?php endif; ?>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="fiscal-year-form-save-btn">Open the year</button>
        <?= hx_link('/periods/', 'Cancel', 'btn btn-light btn-touch w-100', 'id="fiscal-year-form-cancel-link"') ?>
    </form>
</div>
