<?php /** The receipts (screen `receipts-list`). Data: rows, total, page, filters, may, pdo, claims, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'receipts-list', 'title' => 'Receipts', 'crumbs' => [['Home', '/'], ['Receipts', null]],
    'action' => $may['write'] ? hx_link('/receipts/new', '<i class="feather-plus me-1"></i>Record a receipt', 'btn btn-primary btn-touch', 'id="receipts-list-add-btn"') : '']) ?>
<div class="main-content" id="receipts-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($claims > 0): ?><div class="alert alert-warning fs-12" id="receipts-claims-banner"><?= hx_link('/receipts/claims', $claims . ' customer' . ($claims === 1 ? '' : 's') . ' say' . ($claims === 1 ? 's' : '') . ' they paid — confirm or dismiss', 'alert-link') ?></div><?php endif; ?>
    <form method="get" action="/receipts/" hx-get="/receipts/" hx-target="#receipt-list" hx-swap="outerHTML" hx-trigger="change, submit" hx-push-url="true" id="receipts-filters" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="receipts-filter-customer">Customer</label><?= customer_select($pdo, 'customer', $filters['customer'], '', 'Every customer', 'receipts-filter-customer') ?></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="receipts-filter-from">From</label><input type="date" name="from" id="receipts-filter-from" class="form-control btn-touch" value="<?= e($filters['from']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="receipts-filter-to">To</label><input type="date" name="to" id="receipts-filter-to" class="form-control btn-touch" value="<?= e($filters['to']) ?>"></div>
        <div class="col-6 col-md-2"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="receipts-filter-unapplied"><input type="checkbox" class="form-check-input mt-0" name="unapplied" value="1" id="receipts-filter-unapplied" <?= $filters['unapplied'] ? 'checked' : '' ?>>Unapplied</label></div>
        <div class="col-6 col-md-2"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="receipts-filter-undeposited"><input type="checkbox" class="form-check-input mt-0" name="undeposited" value="1" id="receipts-filter-undeposited" <?= $filters['undeposited'] ? 'checked' : '' ?>>Undeposited</label></div>
        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
    </div></form>
    <?= view('receipts/partials/list-region.php', ['rows' => $rows, 'total' => $total, 'page' => $page, 'filters' => $filters, 'may' => $may, 'here' => $here]) ?>
</div>
