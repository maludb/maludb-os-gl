<?php /** The invoices (screen `invoices-list`). Data: rows, total, page, totals, filters, may, customers, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'invoices-list', 'title' => 'Invoices', 'crumbs' => [['Home', '/'], ['Invoices', null]],
    'action' => $may['write'] ? hx_link('/invoices/new', '<i class="feather-plus me-1"></i>New invoice', 'btn btn-primary btn-touch', 'id="invoices-list-add-btn"') : '']) ?>
<div class="main-content" id="invoices-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/invoices/" hx-get="/invoices/" hx-target="#invoice-list" hx-swap="outerHTML" hx-trigger="change, submit, keyup changed delay:400ms from:#invoices-filter-q" hx-push-url="true" id="invoices-filters" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="invoices-filter-q">Number, customer or reference</label><input type="search" name="q" id="invoices-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="invoices-filter-status">Status</label><select name="status" id="invoices-filter-status" class="form-select btn-touch"><option value="">Any</option><?php foreach (INVOICE_STATUSES as $k => $label): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="invoices-filter-overdue">Overdue</label><select name="overdue" id="invoices-filter-overdue" class="form-select btn-touch"><option value="">All</option><?php foreach (OVERDUE_BUCKETS as $k => $label): ?><option value="<?= e($k) ?>" <?= $filters['overdue'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="invoices-filter-customer">Customer</label><?= customer_select($pdo ?? db(), 'customer', $filters['customer'], '', 'Every customer', 'invoices-filter-customer') ?></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="invoices-filter-from">From</label><input type="date" name="from" id="invoices-filter-from" class="form-control btn-touch" value="<?= e($filters['from']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="invoices-filter-to">To</label><input type="date" name="to" id="invoices-filter-to" class="form-control btn-touch" value="<?= e($filters['to']) ?>"></div>
        <input type="hidden" name="as_of" value="<?= e($filters['as_of']) ?>">
        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
    </div></form>
    <?= view('invoices/partials/list-region.php', ['rows' => $rows, 'total' => $total, 'page' => $page, 'totals' => $totals, 'filters' => $filters, 'may' => $may, 'here' => $here]) ?>
</div>
