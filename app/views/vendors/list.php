<?php /** The vendors (screen `vendors-list`). Data: rows, filters, may, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'vendors-list', 'title' => 'Vendors', 'crumbs' => [['Home', '/'], ['Vendors', null]],
    'action' => $may['write'] ? hx_link('/vendors/new', '<i class="feather-plus me-1"></i>Add vendor', 'btn btn-primary btn-touch', 'id="vendors-list-add-btn"') : '']) ?>
<div class="main-content" id="vendors-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/vendors/" hx-get="/vendors/" hx-target="#vendors-results" hx-swap="outerHTML" hx-trigger="change, submit, keyup changed delay:400ms from:#vendors-filter-q" hx-push-url="true" id="vendors-filters" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-5"><label class="form-label fs-12 text-muted" for="vendors-filter-q">Name</label><input type="search" name="q" id="vendors-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="vendors-filter-kind">Kind</label><select name="kind" id="vendors-filter-kind" class="form-select btn-touch"><option value="">Any</option><?php foreach (VENDOR_KINDS as $k => $label): ?><option value="<?= e($k) ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="vendors-filter-archived"><input type="checkbox" class="form-check-input mt-0" name="include_archived" value="1" id="vendors-filter-archived" <?= $filters['include_archived'] ? 'checked' : '' ?>>Archived too</label></div>
        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
    </div></form>
    <?= view('vendors/partials/cards.php', ['rows' => $rows, 'may' => $may, 'here' => $here]) ?>
</div>
