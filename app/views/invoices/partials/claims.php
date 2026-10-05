<?php /** The customer's claims on an invoice (Confirm → the receipt form prefilled; Dismiss with a reason). Data: inv, may, here */ if ($inv['claims'] === []) { return; } ?>
<div class="card mb-3" id="invoice-claims"><div class="card-header"><h5 class="card-title mb-0">Said paid</h5></div>
    <div class="list-group list-group-flush">
        <?php foreach ($inv['claims'] as $k): $kid = $k['receipt_claim_id']; ?>
            <div class="list-group-item" id="invoice-claim-<?= $kid ?>">
                <div class="d-flex justify-content-between"><span class="fw-semibold"><?= $k['amount'] !== null ? e(fmt_money($k['amount'], $inv['currency'])) : 'An amount not stated' ?> by <?= e($k['method']) ?> on <?= e(format_date($k['claimed_on'])) ?></span>
                    <span class="badge bg-<?= ['submitted' => 'warning text-dark', 'confirmed' => 'success', 'dismissed' => 'secondary'][$k['status']] ?>"><?= e($k['status']) ?></span></div>
                <?php if ($k['reference']): ?><div class="fs-12 text-muted">Reference <?= e($k['reference']) ?></div><?php endif; ?>
                <?php if ($k['note']): ?><div class="fs-12">“<?= e($k['note']) ?>”</div><?php endif; ?>
                <?php if ($may['write'] && $k['status'] === 'submitted'): ?>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <?= hx_link(with_back('/receipts/new?claim=' . $kid, $here), 'Confirm', 'btn btn-sm btn-primary', 'id="invoice-claim-' . $kid . '-confirm-btn"') ?>
                        <form method="post" action="/receipts/claim-dismiss.php" hx-post="/receipts/claim-dismiss.php" hx-target="#flash" class="d-flex gap-2" hx-confirm="Dismiss this claim?"><?= csrf_field() ?><input type="hidden" name="claim" value="<?= $kid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <input type="text" name="reason" class="form-control form-control-sm" placeholder="Why" maxlength="300" id="invoice-claim-<?= $kid ?>-reason"><button type="submit" class="btn btn-sm btn-light" id="invoice-claim-<?= $kid ?>-dismiss-btn">Dismiss</button></form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
