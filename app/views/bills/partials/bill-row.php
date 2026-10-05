<?php /** One bill as a table row. Data: b, here */ $id = (int) $b['bill_id']; $url = with_back('/bills/' . $id, $here); ?>
<tr id="bill-row-<?= $id ?>">
    <td class="text-nowrap"><?= hx_link($url, e($b['number']), 'fw-semibold') ?><?= !$b['has_attachment'] && $b['status'] !== 'void' ? ' <span class="badge bg-soft-warning text-warning" title="No document is attached"><i class="feather-file"></i> no document</span>' : '' ?></td>
    <td><?= hx_link(with_back('/vendors/' . (int) $b['vendor_id'], $here), e($b['vendor_name']), 'text-dark') ?><?= $b['source'] === 'ai_usage' ? ' <span class="badge bg-soft-info text-info"><i class="feather-cpu"></i></span>' : '' ?></td>
    <td class="text-nowrap fs-12"><?= e((string) $b['vendor_ref']) ?></td>
    <td class="text-nowrap"><?= e(format_date($b['bill_date'])) ?></td>
    <td class="text-nowrap"><?= e(format_date($b['due_date'])) ?><?= $b['overdue'] ? ' <span class="badge bg-danger">' . (int) $b['days_overdue'] . ' days</span>' : '' ?></td>
    <td><span class="badge bg-<?= BILL_STATUS_BADGE[$b['status']] ?>"><?= e(BILL_STATUSES[$b['status']] ?? $b['status']) ?></span></td>
    <?= fmt_money_cell($b['total'], $b['currency']) ?><?= fmt_money_cell($b['amount_paid'], $b['currency']) ?><?= fmt_money_cell($b['balance_due'], $b['currency']) ?>
</tr>
