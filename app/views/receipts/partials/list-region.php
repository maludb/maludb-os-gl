<?php /** The receipts list region (listens to receiptChanged). Data: rows, total, page, filters, may, here */
$qs = http_build_query(array_filter($filters, static fn ($v) => $v !== '' && $v !== null && $v !== false)); $pages = (int) ceil($total / RECEIPT_PAGE); ?>
<div id="receipt-list" hx-get="/receipts/?<?= e($qs) ?>" hx-trigger="receiptChanged from:body" hx-target="#receipt-list" hx-swap="outerHTML">
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-center text-muted py-4" id="receipt-list-empty">No receipts match.</div></div><?php else: ?>
        <div class="card"><div class="table-responsive"><table class="table table-hover mb-0" id="receipt-list-table">
            <thead><tr><th>Received</th><th>Customer</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th><th>Where</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $id = (int) $r['receipt_id']; ?>
                <tr id="receipt-row-<?= $id ?>"<?= $r['status'] === 'void' ? ' class="text-muted"' : '' ?>>
                    <td class="text-nowrap"><?= hx_link(with_back('/receipts/' . $id, $here), e($r['number']), 'fw-semibold') ?><div class="fs-12 text-muted"><?= e(format_date($r['received_on'])) ?></div></td>
                    <td><?= hx_link(with_back('/customers/' . (int) $r['customer_id'], $here), e($r['customer_name']), 'text-dark') ?></td>
                    <td><?= e(RECEIPT_METHODS[$r['method']] ?? $r['method']) ?></td><td class="fs-12"><?= e($r['reference'] ?? '') ?></td>
                    <?= fmt_money_cell($r['amount'], $r['currency']) ?>
                    <td><?php if ($r['status'] === 'void'): ?><span class="badge bg-dark">void</span><?php else: ?>
                        <?= (float) $r['unapplied'] > 0 ? '<span class="badge bg-soft-warning text-warning" id="receipt-row-' . $id . '-unapplied">unapplied ' . e(fmt_money($r['unapplied'], $r['currency'], false)) . '</span> ' : '' ?>
                        <?= $r['deposit_id'] !== null ? '<span class="badge bg-soft-success text-success">' . e((string) $r['deposit_number']) . '</span>' : ($r['deposit_to_bank_account_id'] !== null ? '<span class="badge bg-soft-secondary text-secondary">' . e((string) $r['deposit_to_bank_name']) . '</span>' : '<span class="badge bg-soft-info text-info" id="receipt-row-' . $id . '-undeposited">undeposited</span>') ?><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
        <div class="d-flex justify-content-between align-items-center mt-2 fs-12"><span class="text-muted"><?= $total ?> receipt<?= $total === 1 ? '' : 's' ?><?= $pages > 1 ? ', page ' . $page . ' of ' . $pages : '' ?></span>
            <span><?php if ($page > 1): ?><?= hx_link('/receipts/?' . $qs . '&page=' . ($page - 1), 'Newer', 'btn btn-sm btn-light') ?> <?php endif; ?><?php if ($page < $pages): ?><?= hx_link('/receipts/?' . $qs . '&page=' . ($page + 1), 'Older', 'btn btn-sm btn-light') ?><?php endif; ?></span></div>
    <?php endif; ?>
</div>
