<?php /** Prepare a payment (screen `payment-add`). Data: vendor, vendorId, bank, banks, bills, billId, pdo, here */ ?>
<?= view('shared/header.php', ['id' => 'payment-add', 'title' => 'Prepare a payment', 'crumbs' => [['Home', '/'], ['Payments', '/payments/'], ['Prepare', null]], 'back' => back_link() ?? ['/payments/', 'payments']]) ?>
<div class="main-content" id="payment-add-content">
<?php if ($vendor === null): ?>
    <form method="get" action="/payments/new" hx-get="/payments/new" hx-target="#page-content" hx-push-url="true" id="payment-vendor-form" class="card"><div class="card-body">
        <label class="form-label fs-12 text-muted" for="payment-vendor-field">Who is being paid?</label>
        <div class="mb-3"><?= vendor_select($pdo, 'vendor', null, 'required', 'Choose a vendor…', 'payment-vendor-field') ?></div>
        <button type="submit" class="btn btn-primary btn-touch w-100" id="payment-vendor-btn">Continue</button>
    </div></form>
<?php else: $total = '0'; foreach ($bills as $b) { if ($billId === null || $b['bill_id'] === $billId) { $total = m_add($total, $b['payable']); } } ?>
    <form method="post" action="/payments/save.php" hx-post="/payments/save.php" hx-target="#flash" id="payment-form">
        <?= csrf_field() ?><input type="hidden" name="vendor" value="<?= (int) $vendorId ?>">
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">To <?= e($vendor['name']) ?></h5><span class="badge bg-soft-secondary text-secondary"><?= e($bank['currency'] ?? '—') ?></span></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="payment-form-field-bank">Pay from</label>
            <select name="bank_account" id="payment-form-field-bank" class="form-select btn-touch mb-1" required data-vendor="<?= (int) $vendorId ?>" data-bill="<?= (int) ($billId ?? 0) ?>"><option value="">Choose a bank account…</option><?php foreach ($banks as $b): ?><option value="<?= $b['bank_account_id'] ?>" <?= ($bank['bank_account_id'] ?? null) === $b['bank_account_id'] ? 'selected' : '' ?>><?= e($b['name']) ?> (<?= e($b['currency']) ?>)</option><?php endforeach; ?></select>
            <div class="fs-12 text-muted mb-3" id="payment-form-bank-note">Changing the bank account reloads the bills in its currency.</div>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="payment-form-field-amount">Amount</label><input type="text" inputmode="decimal" name="amount" id="payment-form-field-amount" class="form-control btn-touch" required value="<?= (float) $total > 0 ? e($total) : '' ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="payment-form-field-date">Payment date</label><input type="date" name="payment_date" id="payment-form-field-date" class="form-control btn-touch" value="<?= e(date('Y-m-d')) ?>"></div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="payment-form-field-method">Method</label><select name="method" id="payment-form-field-method" class="form-select btn-touch"><?php foreach (PAY_METHODS as $k => $label): ?><option value="<?= e($k) ?>" <?= ($vendor['pay_method'] ?? 'ach') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="payment-form-field-cheque">Cheque number</label><input type="text" name="cheque_number" id="payment-form-field-cheque" class="form-control btn-touch" maxlength="40"></div>
            </div>
            <div class="row g-2"><div class="col-6"><label class="form-label fs-12 text-muted" for="payment-form-field-reference">Reference</label><input type="text" name="reference" id="payment-form-field-reference" class="form-control btn-touch" maxlength="120"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="payment-form-field-memo">Memo</label><input type="text" name="memo" id="payment-form-field-memo" class="form-control btn-touch" maxlength="500"></div></div>
        </div></div>
        <div class="card mb-3" id="payment-apply-rows"><div class="card-header"><h5 class="card-title mb-0">The bills it pays</h5><span class="fs-12" id="payment-apply-total">Chosen <span id="payment-apply-total-amount"><?= e(fmt_money($total, $bank['currency'] ?? 'USD', false)) ?></span></span></div>
            <?= view('payments/partials/apply-rows.php', ['bills' => $bills, 'billId' => $billId, 'here' => $here]) ?>
        </div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="payment-form-save-btn">Save the draft</button>
        <?= hx_link('/payments/', 'Cancel', 'btn btn-light btn-touch w-100', 'id="payment-form-cancel-link"') ?>
    </form>
    <script>(function () {
        var amt = document.getElementById('payment-form-field-amount'), tot = document.getElementById('payment-apply-total-amount'), bank = document.getElementById('payment-form-field-bank');
        function sum() { var c = 0; document.querySelectorAll('.payment-bill:checked').forEach(function (b) { c += Math.round(parseFloat(b.dataset.payable) * 100); }); tot.textContent = (c / 100).toFixed(2); if (c > 0) { amt.value = (c / 100).toFixed(2); } }
        document.querySelectorAll('.payment-bill').forEach(function (b) { b.addEventListener('change', sum); });
        bank.addEventListener('change', function () { if (bank.value) { location.href = '/payments/new?vendor=' + bank.dataset.vendor + '&bank_account=' + bank.value + (bank.dataset.bill !== '0' ? '&bill=' + bank.dataset.bill : ''); } });
    })();</script>
<?php endif; ?>
</div>
