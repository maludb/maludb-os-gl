<?php /** What was paid on a bill: payments applied, credits applied. Data: bill, may, here */ $cur = $bill['currency']; ?>
<div class="card mb-3" id="bill-paid"><div class="card-header"><h5 class="card-title mb-0">Paid</h5></div>
    <?php if ($bill['payments'] === [] && $bill['credits'] === []): ?><div class="card-body text-muted" id="bill-paid-empty">Nothing paid yet.</div><?php else: ?>
    <div class="list-group list-group-flush">
        <?php foreach ($bill['payments'] as $p): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center gap-2" id="bill-paid-payment-<?= $p['payment_id'] ?>">
                <span><?= hx_link(with_back('/payments/' . $p['payment_id'], $here), e($p['payment_number'])) ?> <span class="fs-12 text-muted"><?= e(format_date($p['payment_date'])) ?> · <?= e($p['method']) ?><?= $p['cheque_number'] ? ' #' . e($p['cheque_number']) : '' ?></span> <span class="badge bg-<?= PAYMENT_STATUS_BADGE[$p['payment_status']] ?>"><?= e($p['payment_status']) ?></span></span>
                <span><?= e(fmt_money($p['amount'], $cur)) ?></span>
            </div>
        <?php endforeach; ?>
        <?php foreach ($bill['credits'] as $c): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center gap-2" id="bill-paid-credit-<?= $c['vendor_credit_id'] ?>">
                <span><?= hx_link(with_back('/vendor-credits/' . $c['vendor_credit_id'], $here), e((string) $c['vendor_credit_number'])) ?> <span class="fs-12 text-muted">credit</span></span><span><?= e(fmt_money($c['amount'], $cur)) ?></span>
            </div>
        <?php endforeach; ?>
    </div><?php endif; ?>
</div>
