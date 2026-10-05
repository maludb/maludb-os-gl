<?php /** The vendor's payable bills with a checkbox each, oldest due first (payment form). Data: bills, billId, here */ ?>
<?php if ($bills === []): ?><div class="card-body text-muted" id="payment-apply-empty">This vendor has no approved bill with something left to pay in that currency. The payment can still be saved; apply it to a bill later.</div><?php else: ?>
<div class="list-group list-group-flush"><?php foreach ($bills as $b): $bid = $b['bill_id']; ?>
    <label class="list-group-item d-flex align-items-center gap-2 btn-touch" for="payment-bill-<?= $bid ?>"><input type="checkbox" class="form-check-input mt-0 payment-bill" name="bills[]" value="<?= $bid ?>" id="payment-bill-<?= $bid ?>" data-payable="<?= e($b['payable']) ?>" <?= $billId === null || $billId === $bid ? 'checked' : '' ?>>
        <span class="flex-grow-1"><?= e($b['number']) ?><?= $b['vendor_ref'] ? ' · ' . e($b['vendor_ref']) : '' ?><span class="fs-12 text-muted d-block">due <?= e(format_date($b['due_date'])) ?><?= $b['overdue'] ? ' <span class="badge bg-danger">' . (int) $b['days_overdue'] . ' days</span>' : '' ?></span></span><span class="text-end"><?= e(fmt_money($b['payable'], $b['currency'], false)) ?></span></label>
<?php endforeach; ?></div>
<div class="card-body fs-12 text-muted border-top">The amount is applied to the checked bills, oldest due first.</div><?php endif; ?>
