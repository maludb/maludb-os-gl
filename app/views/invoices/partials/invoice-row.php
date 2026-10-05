<?php /** One invoice as a table row. Data: i, here */ $id = (int) $i['invoice_id']; $url = with_back('/invoices/' . $id, $here); ?>
<tr id="invoice-row-<?= $id ?>">
    <td class="text-nowrap"><?= hx_link($url, $i['number'] !== null ? e($i['number']) : '<span class="badge bg-soft-secondary text-secondary">Draft #' . $id . '</span>', 'fw-semibold') ?></td>
    <td><?= hx_link(with_back('/customers/' . (int) $i['customer_id'], $here), e($i['customer_name']), 'text-dark') ?></td>
    <td class="text-nowrap"><?= e(format_date($i['issue_date'])) ?></td>
    <td class="text-nowrap"><?= e(format_date($i['due_date'])) ?><?= $i['overdue'] ? ' <span class="badge bg-danger">' . (int) $i['days_overdue'] . ' days</span>' : '' ?></td>
    <td><span class="badge bg-<?= INVOICE_STATUS_BADGE[$i['status']] ?>"><?= e(INVOICE_STATUSES[$i['status']] ?? $i['status']) ?></span><?= (int) $i['open_claims'] > 0 ? ' <span class="badge bg-soft-warning text-warning">said paid</span>' : '' ?></td>
    <?= fmt_money_cell($i['total'], $i['currency']) ?><?= fmt_money_cell($i['balance_due'], $i['currency']) ?>
</tr>
