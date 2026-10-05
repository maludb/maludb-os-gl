<?php /** The bill list region (swapped by the filters; listens to billChanged). Data: rows, total, page, totals, filters, may, here */
$qs = http_build_query(array_filter($filters, static fn ($v) => $v !== '' && $v !== null && $v !== false)); $pages = (int) ceil($total / BILL_PAGE); ?>
<div id="bill-list" hx-get="/bills/?<?= e($qs) ?>" hx-trigger="billChanged from:body" hx-target="#bill-list" hx-swap="outerHTML">
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-center text-muted py-4" id="bill-list-empty">No bills match.<?= $may['write'] ? '<div class="mt-2">' . hx_link('/bills/new', 'New bill', 'btn btn-primary btn-touch', 'id="bill-list-new-btn"') . '</div>' : '' ?></div></div>
    <?php else: ?>
        <div class="d-md-none" id="bill-list-cards"><?php foreach ($rows as $b): ?><?= view('bills/partials/bill-card.php', ['b' => $b, 'here' => $here]) ?><?php endforeach; ?></div>
        <div class="card d-none d-md-block"><div class="table-responsive"><table class="table table-hover mb-0" id="bill-list-table">
            <thead><tr><th>Number</th><th>Vendor</th><th>Their ref</th><th>Dated</th><th>Due</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th></tr></thead>
            <tbody><?php foreach ($rows as $b): ?><?= view('bills/partials/bill-row.php', ['b' => $b, 'here' => $here]) ?><?php endforeach; ?></tbody>
            <tfoot id="bill-list-totals"><?php foreach ($totals as $t): ?><tr><td colspan="6" class="text-muted fs-12">Approved, <?= e($t['currency']) ?> (<?= $t['bills'] ?>)</td><?= fmt_money_cell($t['total'], $t['currency'], 'bill-list-total-' . $t['currency']) ?><?= fmt_money_cell($t['paid'], $t['currency'], 'bill-list-paid-' . $t['currency']) ?><?= fmt_money_cell($t['balance'], $t['currency'], 'bill-list-balance-' . $t['currency']) ?></tr><?php endforeach; ?></tfoot>
        </table></div></div>
        <div class="d-md-none fs-12 text-muted mt-2"><?php foreach ($totals as $t): ?><div>Approved, <?= e($t['currency']) ?>: <?= e(fmt_money($t['total'], $t['currency'])) ?> · owed <?= e(fmt_money($t['balance'], $t['currency'])) ?></div><?php endforeach; ?></div>
        <div class="d-flex justify-content-between align-items-center mt-2 fs-12" id="bill-list-pager"><span class="text-muted"><?= $total ?> bill<?= $total === 1 ? '' : 's' ?><?= $pages > 1 ? ', page ' . $page . ' of ' . $pages : '' ?></span>
            <span><?php if ($page > 1): ?><?= hx_link('/bills/?' . $qs . '&page=' . ($page - 1), 'Earlier', 'btn btn-sm btn-light') ?> <?php endif; ?><?php if ($page < $pages): ?><?= hx_link('/bills/?' . $qs . '&page=' . ($page + 1), 'Later', 'btn btn-sm btn-light') ?><?php endif; ?>
            <?php if ($may['export']): ?> <a href="/bills/?<?= e($qs) ?>&amp;format=csv" class="btn btn-sm btn-light" id="bill-list-csv-link"><i class="feather-download me-1"></i>CSV</a><?php endif; ?></span></div>
    <?php endif; ?>
</div>
