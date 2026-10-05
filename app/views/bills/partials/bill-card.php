<?php /** One bill as a card (the phone). Data: b, here */ $id = (int) $b['bill_id']; $url = with_back('/bills/' . $id, $here); ?>
<div class="card mb-2" id="bill-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0"><?= hx_link($url, e($b['number']), 'fw-bold text-dark') ?><div class="fs-12 text-muted text-truncate"><?= e($b['vendor_name']) ?><?= $b['vendor_ref'] ? ' · ' . e($b['vendor_ref']) : '' ?></div></div>
        <div class="text-end text-nowrap fw-semibold"><?= e(fmt_money($b['status'] === 'draft' ? $b['total'] : $b['balance_due'], $b['currency'])) ?><div class="fs-12 text-muted fw-normal">of <?= e(fmt_money($b['total'], $b['currency'], false)) ?></div></div>
    </div>
    <div class="mt-1 fs-12 text-muted">Due <?= e(format_date($b['due_date'])) ?><?= $b['overdue'] ? ' <span class="badge bg-danger">' . (int) $b['days_overdue'] . ' days</span>' : '' ?></div>
    <div class="mt-1"><span class="badge bg-<?= BILL_STATUS_BADGE[$b['status']] ?>"><?= e(BILL_STATUSES[$b['status']] ?? $b['status']) ?></span><?= !$b['has_attachment'] && $b['status'] !== 'void' ? ' <span class="badge bg-soft-warning text-warning"><i class="feather-file"></i> no document</span>' : '' ?></div>
    <div class="mt-2"><?= hx_link($url, 'Open', 'btn btn-light btn-touch w-100', 'id="bill-card-' . $id . '-open-btn"') ?></div>
</div></div>
