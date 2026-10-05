<?php /** The invoice list region (swapped by the filters; listens to invoiceChanged). Data: rows, total, page, totals, filters, may, here */
$qs = http_build_query(array_filter($filters, static fn ($v) => $v !== '' && $v !== null)); $pages = (int) ceil($total / INVOICE_PAGE); ?>
<div id="invoice-list" hx-get="/invoices/?<?= e($qs) ?>" hx-trigger="invoiceChanged from:body" hx-target="#invoice-list" hx-swap="outerHTML">
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-center text-muted py-4" id="invoice-list-empty">No invoices match.<?= $may['write'] ? '<div class="mt-2">' . hx_link('/invoices/new', 'New invoice', 'btn btn-primary btn-touch', 'id="invoice-list-new-btn"') . '</div>' : '' ?></div></div>
    <?php else: ?>
        <div class="d-md-none" id="invoice-list-cards"><?php foreach ($rows as $i): ?><?= view('invoices/partials/invoice-card.php', ['i' => $i, 'here' => $here]) ?><?php endforeach; ?></div>
        <div class="card d-none d-md-block"><div class="table-responsive"><table class="table table-hover mb-0" id="invoice-list-table">
            <thead><tr><th>Number</th><th>Customer</th><th>Issued</th><th>Due</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Balance</th></tr></thead>
            <tbody><?php foreach ($rows as $i): ?><?= view('invoices/partials/invoice-row.php', ['i' => $i, 'here' => $here]) ?><?php endforeach; ?></tbody>
            <tfoot id="invoice-list-totals"><?php foreach ($totals as $t): ?><tr><td colspan="5" class="text-muted fs-12">Issued, <?= e($t['currency']) ?> (<?= $t['invoices'] ?>)</td><?= fmt_money_cell($t['total'], $t['currency'], 'invoice-list-total-' . $t['currency']) ?><?= fmt_money_cell($t['balance'], $t['currency'], 'invoice-list-balance-' . $t['currency']) ?></tr><?php endforeach; ?></tfoot>
        </table></div></div>
        <div class="d-md-none fs-12 text-muted mt-2"><?php foreach ($totals as $t): ?><div>Issued, <?= e($t['currency']) ?>: <?= e(fmt_money($t['total'], $t['currency'])) ?> · owed <?= e(fmt_money($t['balance'], $t['currency'])) ?></div><?php endforeach; ?></div>
        <div class="d-flex justify-content-between align-items-center mt-2 fs-12" id="invoice-list-pager"><span class="text-muted"><?= $total ?> invoice<?= $total === 1 ? '' : 's' ?><?= $pages > 1 ? ', page ' . $page . ' of ' . $pages : '' ?></span>
            <span><?php if ($page > 1): ?><?= hx_link('/invoices/?' . $qs . '&page=' . ($page - 1), 'Newer', 'btn btn-sm btn-light') ?> <?php endif; ?><?php if ($page < $pages): ?><?= hx_link('/invoices/?' . $qs . '&page=' . ($page + 1), 'Older', 'btn btn-sm btn-light') ?><?php endif; ?>
            <?php if ($may['export']): ?> <a href="/invoices/?<?= e($qs) ?>&amp;format=csv" class="btn btn-sm btn-light" id="invoice-list-csv-link"><i class="feather-download me-1"></i>CSV</a><?php endif; ?></span></div>
    <?php endif; ?>
</div>
