<?php /** The customers (screen `customers-list`). Data: rows, filters, may, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'customers-list', 'title' => 'Customers', 'crumbs' => [['Home', '/'], ['Customers', null]],
    'action' => $may['write'] ? hx_link('/customers/new', '<i class="feather-plus me-1"></i>Add customer', 'btn btn-primary btn-touch', 'id="customers-list-add-btn"') : '']) ?>
<div class="main-content" id="customers-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/customers/" hx-get="/customers/" hx-target="#customers-results" hx-swap="outerHTML" hx-trigger="change, submit, keyup changed delay:400ms from:#customers-filter-q" hx-push-url="true" id="customers-filters" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-8"><label class="form-label fs-12 text-muted" for="customers-filter-q">Name</label><input type="search" name="q" id="customers-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>"></div>
        <div class="col-12 col-md-4"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="customers-filter-archived"><input type="checkbox" class="form-check-input mt-0" name="include_archived" value="1" id="customers-filter-archived" <?= $filters['include_archived'] ? 'checked' : '' ?>>Archived too</label></div>
        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
    </div></form>
    <?= view('customers/partials/cards.php', ['rows' => $rows, 'may' => $may, 'here' => $here]) ?>
</div>
