<?php /** One invoice as a card (the phone). Data: i, here */ $id = (int) $i['invoice_id']; $url = with_back('/invoices/' . $id, $here); ?>
<div class="card mb-2" id="invoice-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0"><?= hx_link($url, $i['number'] !== null ? e($i['number']) : 'Draft #' . $id, 'fw-bold text-dark') ?><div class="fs-12 text-muted text-truncate"><?= e($i['customer_name']) ?></div></div>
        <div class="text-end text-nowrap fw-semibold"><?= e(fmt_money($i['balance_due'], $i['currency'])) ?><div class="fs-12 text-muted fw-normal">of <?= e(fmt_money($i['total'], $i['currency'], false)) ?></div></div>
    </div>
    <div class="mt-1 fs-12 text-muted">Due <?= e(format_date($i['due_date'])) ?><?= $i['overdue'] ? ' <span class="badge bg-danger">' . (int) $i['days_overdue'] . ' days</span>' : '' ?></div>
    <div class="mt-1"><span class="badge bg-<?= INVOICE_STATUS_BADGE[$i['status']] ?>"><?= e(INVOICE_STATUSES[$i['status']] ?? $i['status']) ?></span><?= (int) $i['open_claims'] > 0 ? ' <span class="badge bg-soft-warning text-warning">said paid</span>' : '' ?></div>
    <div class="mt-2"><?= hx_link($url, 'Open', 'btn btn-light btn-touch w-100', 'id="invoice-card-' . $id . '-open-btn"') ?></div>
</div></div>
