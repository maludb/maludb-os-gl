<?php /** What was received on an invoice: receipts applied, credits applied. Data: inv, may, here */ $cur = $inv['currency']; ?>
<div class="card mb-3" id="invoice-received"><div class="card-header"><h5 class="card-title mb-0">Received</h5></div>
    <?php if ($inv['receipts'] === [] && $inv['credits'] === []): ?><div class="card-body text-muted" id="invoice-received-empty">Nothing received yet.</div><?php else: ?>
    <div class="list-group list-group-flush">
        <?php foreach ($inv['receipts'] as $r): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center gap-2" id="invoice-received-receipt-<?= $r['receipt_id'] ?>">
                <span><?= hx_link(with_back('/receipts/' . $r['receipt_id'], $here), e($r['receipt_number'])) ?> <span class="fs-12 text-muted"><?= e(format_date($r['received_on'])) ?> · <?= e($r['method']) ?></span></span>
                <span class="d-flex align-items-center gap-2"><span><?= e(fmt_money($r['amount'], $cur)) ?></span>
                <?php if ($may['write'] && $r['deposit_id'] === null): ?><form method="post" action="/receipts/unapply.php" hx-post="/receipts/unapply.php" hx-target="#flash" hx-confirm="Take this receipt off the invoice?"><?= csrf_field() ?><input type="hidden" name="receipt" value="<?= $r['receipt_id'] ?>"><input type="hidden" name="invoice" value="<?= (int) $inv['invoice_id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-sm btn-light" id="invoice-received-receipt-<?= $r['receipt_id'] ?>-unapply-btn">Unapply</button></form>
                <?php elseif ($r['deposit_id'] !== null): ?><span class="fs-12 text-muted" title="Take it out of the deposit first.">in a deposit</span><?php endif; ?></span>
            </div>
        <?php endforeach; ?>
        <?php foreach ($inv['credits'] as $c): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center gap-2" id="invoice-received-credit-<?= $c['credit_note_id'] ?>">
                <span><?= hx_link(with_back('/credit-notes/' . $c['credit_note_id'], $here), e((string) $c['credit_note_number'])) ?> <span class="fs-12 text-muted">credit</span></span><span><?= e(fmt_money($c['amount'], $cur)) ?></span>
            </div>
        <?php endforeach; ?>
    </div><?php endif; ?>
</div>
