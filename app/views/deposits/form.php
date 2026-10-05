<?php /** Group undeposited receipts into a deposit (screen `deposit-add`). Data: banks, bank, receipts, here */ ?>
<?= view('shared/header.php', ['id' => 'deposit-add', 'title' => 'New deposit', 'crumbs' => [['Home', '/'], ['Deposits', '/deposits/'], ['New', null]], 'back' => back_link() ?? ['/deposits/', 'deposits']]) ?>
<div class="main-content" id="deposit-add-content">
<?php if ($bank === null): ?>
    <form method="get" action="/deposits/new" hx-get="/deposits/new" hx-target="#page-content" hx-push-url="true" id="deposit-bank-form" class="card"><div class="card-body">
        <label class="form-label fs-12 text-muted" for="deposit-bank-field">Deposit to which bank account?</label>
        <select name="bank_account" id="deposit-bank-field" class="form-select btn-touch mb-3" required><option value="">Choose a bank account…</option><?php foreach ($banks as $b): ?><option value="<?= $b['bank_account_id'] ?>"><?= e($b['name']) ?> (<?= e($b['currency']) ?>)</option><?php endforeach; ?></select>
        <?php if ($banks === []): ?><div class="fs-12 text-muted mb-3" id="deposit-no-banks">No bank account yet — slice 6 adds them in the settings.</div><?php endif; ?>
        <button type="submit" class="btn btn-primary btn-touch w-100" id="deposit-bank-btn">Continue</button>
    </div></form>
<?php else: ?>
    <form method="post" action="/deposits/save.php" hx-post="/deposits/save.php" hx-target="#flash" id="deposit-form">
        <?= csrf_field() ?><input type="hidden" name="bank_account" value="<?= $bank['bank_account_id'] ?>">
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">To <?= e($bank['name']) ?></h5><span class="badge bg-soft-secondary text-secondary"><?= e($bank['currency']) ?></span></div><div class="card-body">
            <div class="row g-2"><div class="col-6"><label class="form-label fs-12 text-muted" for="deposit-form-field-date">Deposit date</label><input type="date" name="deposit_date" id="deposit-form-field-date" class="form-control btn-touch" value="<?= e(date('Y-m-d')) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="deposit-form-field-memo">Memo</label><input type="text" name="memo" id="deposit-form-field-memo" class="form-control btn-touch" maxlength="500"></div></div>
        </div></div>
        <div class="card mb-3" id="deposit-receipts"><div class="card-header"><h5 class="card-title mb-0">Undeposited receipts</h5><span class="fs-12" id="deposit-total">Total <span id="deposit-total-amount">0.00</span> <?= e($bank['currency']) ?></span></div>
            <?php if ($receipts === []): ?><div class="card-body text-muted" id="deposit-receipts-empty">No undeposited receipt in <?= e($bank['currency']) ?>.</div><?php else: ?>
            <div class="list-group list-group-flush"><?php foreach ($receipts as $r): ?>
                <label class="list-group-item d-flex align-items-center gap-2 btn-touch" for="deposit-receipt-<?= $r['receipt_id'] ?>"><input type="checkbox" class="form-check-input mt-0 deposit-receipt" name="receipts[]" value="<?= $r['receipt_id'] ?>" id="deposit-receipt-<?= $r['receipt_id'] ?>" data-amount="<?= e($r['amount']) ?>">
                    <span class="flex-grow-1"><?= e($r['number']) ?> · <?= e($r['customer_name']) ?><span class="fs-12 text-muted d-block"><?= e(format_date($r['received_on'])) ?> · <?= e($r['method']) ?><?= $r['reference'] ? ' · ' . e($r['reference']) : '' ?></span></span><span><?= e(fmt_money($r['amount'], $r['currency'], false)) ?></span></label>
            <?php endforeach; ?></div><?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="deposit-form-save-btn">Save the draft</button>
        <?= hx_link('/deposits/', 'Cancel', 'btn btn-light btn-touch w-100', 'id="deposit-form-cancel-link"') ?>
    </form>
    <script>(function () { var t = document.getElementById('deposit-total-amount'); function sum() { var c = 0; document.querySelectorAll('.deposit-receipt:checked').forEach(function (b) { c += Math.round(parseFloat(b.dataset.amount) * 100); }); t.textContent = (c / 100).toFixed(2); } document.querySelectorAll('.deposit-receipt').forEach(function (b) { b.addEventListener('change', sum); }); })();</script>
<?php endif; ?>
</div>
