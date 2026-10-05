<?php /** The payments (screen `payments-list`). Data: rows, total, page, filters, may, vendors, banks, notice, here */
$tabs = [['all', 'All', ''], ['draft', 'Draft', 'draft'], ['approved', 'Approved', 'approved'], ['recorded', 'Recorded', 'recorded']];
$qs = http_build_query(array_filter($filters, static fn ($v) => $v !== '' && $v !== null)); $pages = (int) ceil($total / PAYMENT_PAGE); ?>
<?= view('shared/header.php', ['id' => 'payments-list', 'title' => 'Payments', 'crumbs' => [['Home', '/'], ['Payments', null]],
    'action' => $may['write'] ? hx_link('/payments/new', '<i class="feather-plus me-1"></i>Prepare a payment', 'btn btn-primary btn-touch', 'id="payments-list-add-btn"') : '']) ?>
<div class="main-content" id="payments-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="mb-3 d-flex flex-wrap gap-2" id="payments-tabs"><?php foreach ($tabs as [$tk, $tl, $ts]): ?><?= hx_link('/payments/' . ($ts === '' ? '' : '?status=' . $ts), $tl, 'btn btn-sm ' . ($filters['status'] === $ts ? 'btn-primary' : 'btn-light'), 'id="payments-tab-' . $tk . '"') ?><?php endforeach; ?></div>
    <form method="get" action="/payments/" id="payments-filters" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
        <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="payments-filter-vendor">Vendor</label><?= vendor_select($pdo ?? db(), 'vendor', $filters['vendor'], '', 'Every vendor', 'payments-filter-vendor') ?></div>
        <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="payments-filter-bank">Bank account</label><select name="bank_account" id="payments-filter-bank" class="form-select btn-touch"><option value="">Every account</option><?php foreach ($banks as $b): ?><option value="<?= $b['bank_account_id'] ?>" <?= $filters['bank_account'] === $b['bank_account_id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="payments-filter-from">From</label><input type="date" name="from" id="payments-filter-from" class="form-control btn-touch" value="<?= e($filters['from']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="payments-filter-to">To</label><input type="date" name="to" id="payments-filter-to" class="form-control btn-touch" value="<?= e($filters['to']) ?>"></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="payments-filter-btn">Apply</button></div>
    </div></form>
    <div id="payment-list" hx-get="/payments/?<?= e($qs) ?>" hx-trigger="paymentChanged from:body" hx-target="#payment-list" hx-swap="outerHTML">
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-center text-muted py-4" id="payment-list-empty">No payments match.</div></div>
    <?php else: ?>
        <div class="d-md-none" id="payment-list-cards"><?php foreach ($rows as $p): $pid = (int) $p['payment_id']; ?>
            <div class="card mb-2" id="payment-card-<?= $pid ?>"><div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2"><div class="min-w-0"><?= hx_link(with_back('/payments/' . $pid, $here), e($p['number']), 'fw-bold text-dark') ?><div class="fs-12 text-muted text-truncate"><?= e($p['vendor_name']) ?></div></div><div class="fw-semibold text-nowrap"><?= e(fmt_money($p['amount'], $p['currency'])) ?></div></div>
                <div class="mt-1 fs-12 text-muted"><?= e(format_date($p['payment_date'])) ?> · <?= e(PAY_METHODS[$p['method']] ?? $p['method']) ?><?= $p['cheque_number'] ? ' #' . e($p['cheque_number']) : '' ?> · <?= e($p['bank_account_name']) ?></div>
                <div class="mt-1"><span class="badge bg-<?= PAYMENT_STATUS_BADGE[$p['status']] ?>"><?= e(PAYMENT_STATUSES[$p['status']]) ?></span><?= $p['payment_run_number'] ? ' <span class="badge bg-soft-secondary text-secondary">' . e($p['payment_run_number']) . '</span>' : '' ?></div>
                <div class="mt-2"><?= hx_link(with_back('/payments/' . $pid, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="payment-card-' . $pid . '-open-btn"') ?></div>
            </div></div>
        <?php endforeach; ?></div>
        <div class="card d-none d-md-block"><div class="table-responsive"><table class="table table-hover mb-0" id="payment-list-table">
            <thead><tr><th>Number</th><th>Vendor</th><th>Date</th><th>Method</th><th>Bank account</th><th>Status</th><th>Run</th><th class="text-end">Amount</th><th class="text-end">Applied</th></tr></thead>
            <tbody><?php foreach ($rows as $p): $pid = (int) $p['payment_id']; ?><tr id="payment-row-<?= $pid ?>">
                <td class="text-nowrap"><?= hx_link(with_back('/payments/' . $pid, $here), e($p['number']), 'fw-semibold') ?></td>
                <td><?= hx_link(with_back('/vendors/' . (int) $p['vendor_id'], $here), e($p['vendor_name']), 'text-dark') ?></td>
                <td class="text-nowrap"><?= e(format_date($p['payment_date'])) ?></td>
                <td class="text-nowrap"><?= e(PAY_METHODS[$p['method']] ?? $p['method']) ?><?= $p['cheque_number'] ? ' #' . e($p['cheque_number']) : '' ?></td>
                <td><?= e($p['bank_account_name']) ?></td>
                <td><span class="badge bg-<?= PAYMENT_STATUS_BADGE[$p['status']] ?>"><?= e(PAYMENT_STATUSES[$p['status']]) ?></span></td>
                <td><?= $p['payment_run_id'] !== null ? hx_link(with_back('/payment-runs/' . (int) $p['payment_run_id'], $here), e((string) $p['payment_run_number'])) : '' ?></td>
                <?= fmt_money_cell($p['amount'], $p['currency']) ?><?= fmt_money_cell($p['amount_applied'], $p['currency']) ?></tr><?php endforeach; ?></tbody>
        </table></div></div>
        <div class="d-flex justify-content-between align-items-center mt-2 fs-12" id="payment-list-pager"><span class="text-muted"><?= $total ?> payment<?= $total === 1 ? '' : 's' ?><?= $pages > 1 ? ', page ' . $page . ' of ' . $pages : '' ?></span>
            <span><?php if ($page > 1): ?><?= hx_link('/payments/?' . $qs . '&page=' . ($page - 1), 'Newer', 'btn btn-sm btn-light') ?> <?php endif; ?><?php if ($page < $pages): ?><?= hx_link('/payments/?' . $qs . '&page=' . ($page + 1), 'Older', 'btn btn-sm btn-light') ?><?php endif; ?></span></div>
    <?php endif; ?>
    </div>
</div>
