<?php /** A document's totals panel (invoice or credit note), swapped out of band with the lines. Data: kind, doc, currency, prefix, oob? */ $p = $prefix; ?>
<div id="<?= e($p) ?>-totals" <?= !empty($oob) ? 'hx-swap-oob="true"' : '' ?> class="card mb-3"><div class="card-body p-3">
    <dl class="row mb-0 fs-12">
        <dt class="col-6 text-muted">Subtotal</dt><dd class="col-6 text-end" id="<?= e($p) ?>-totals-subtotal"><?= e(fmt_money($doc['subtotal'], $currency)) ?></dd>
        <dt class="col-6 text-muted">Tax</dt><dd class="col-6 text-end" id="<?= e($p) ?>-totals-tax"><?= e(fmt_money($doc['tax_total'], $currency)) ?></dd>
        <dt class="col-6 fw-bold">Total</dt><dd class="col-6 text-end fw-bold" id="<?= e($p) ?>-totals-total"><?= e(fmt_money($doc['total'], $currency)) ?></dd>
        <?php if ($kind === 'invoice' && $doc['status'] !== 'draft'): ?>
            <dt class="col-6 text-muted">Received</dt><dd class="col-6 text-end" id="invoice-totals-paid"><?= e(fmt_money($doc['amount_paid'], $currency)) ?></dd>
            <dt class="col-6 text-muted">Credited</dt><dd class="col-6 text-end" id="invoice-totals-credited"><?= e(fmt_money($doc['amount_credited'], $currency)) ?></dd>
            <dt class="col-6 fw-bold">Balance due</dt><dd class="col-6 text-end fw-bold" id="invoice-totals-balance"><?= e(fmt_money($doc['balance_due'], $currency)) ?></dd>
        <?php elseif ($kind === 'credit_note' && $doc['status'] !== 'draft'): ?>
            <dt class="col-6 text-muted">Applied</dt><dd class="col-6 text-end" id="credit-note-totals-applied"><?= e(fmt_money($doc['amount_applied'], $currency)) ?></dd>
            <dt class="col-6 fw-bold">Balance</dt><dd class="col-6 text-end fw-bold" id="credit-note-totals-balance"><?= e(fmt_money($doc['balance'], $currency)) ?></dd>
        <?php endif; ?>
    </dl>
</div></div>
