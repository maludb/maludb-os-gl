<?php /** Select the approved bills due by a date and make a run (screen `payment-run-add`). Data: banks, bank, dueBy, bills, here */ ?>
<?= view('shared/header.php', ['id' => 'payment-run-add', 'title' => 'New payment run', 'crumbs' => [['Home', '/'], ['Payment runs', '/payment-runs/'], ['New', null]], 'back' => back_link() ?? ['/payment-runs/', 'payment runs']]) ?>
<div class="main-content" id="payment-run-add-content">
    <form method="get" action="/payment-runs/new" hx-get="/payment-runs/new" hx-target="#page-content" hx-push-url="true" id="payment-run-pick-form" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="payment-run-bank-field">Pay from</label><select name="bank_account" id="payment-run-bank-field" class="form-select btn-touch" required><option value="">Choose a bank account…</option><?php foreach ($banks as $b): ?><option value="<?= $b['bank_account_id'] ?>" <?= ($bank['bank_account_id'] ?? null) === $b['bank_account_id'] ? 'selected' : '' ?>><?= e($b['name']) ?> (<?= e($b['currency']) ?>)</option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="payment-run-dueby-field">Bills due by</label><input type="date" name="due_by" id="payment-run-dueby-field" class="form-control btn-touch" value="<?= e($dueBy) ?>"></div>
        <div class="col-6 col-md-3"><button type="submit" class="btn btn-light btn-touch w-100" id="payment-run-pick-btn">Show the bills</button></div>
    </div></form>
<?php if ($bank !== null): $by = []; foreach ($bills as $b) { $by[$b['vendor_name']][] = $b; } ?>
    <form method="post" action="/payment-runs/save.php" hx-post="/payment-runs/save.php" hx-target="#flash" id="payment-run-form">
        <?= csrf_field() ?><input type="hidden" name="bank_account" value="<?= $bank['bank_account_id'] ?>">
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">From <?= e($bank['name']) ?></h5><span class="badge bg-soft-secondary text-secondary"><?= e($bank['currency']) ?></span></div><div class="card-body"><div class="row g-2">
            <div class="col-6"><label class="form-label fs-12 text-muted" for="payment-run-form-field-date">Pay date</label><input type="date" name="pay_date" id="payment-run-form-field-date" class="form-control btn-touch" value="<?= e(date('Y-m-d')) ?>"></div>
            <div class="col-6"><label class="form-label fs-12 text-muted" for="payment-run-form-field-memo">Memo</label><input type="text" name="memo" id="payment-run-form-field-memo" class="form-control btn-touch" maxlength="500"></div></div></div></div>
        <div class="card mb-3" id="run-bills"><div class="card-header"><h5 class="card-title mb-0">Approved bills due by <?= e(format_date($dueBy)) ?></h5><span class="fs-12" id="run-bills-total">Total <span id="run-bills-total-amount">0.00</span> <?= e($bank['currency']) ?></span></div>
            <?php if ($bills === []): ?><div class="card-body text-muted" id="run-bills-empty">No approved bill in <?= e($bank['currency']) ?> is due by then with something left to pay.</div><?php else: ?>
            <?php foreach ($by as $vendorName => $list): ?>
                <div class="px-3 pt-2 fw-semibold fs-12 text-muted"><?= e($vendorName) ?></div>
                <div class="list-group list-group-flush"><?php foreach ($list as $b): ?>
                    <label class="list-group-item d-flex align-items-center gap-2 btn-touch" for="run-bill-<?= $b['bill_id'] ?>"><input type="checkbox" class="form-check-input mt-0 run-bill" name="bills[]" value="<?= $b['bill_id'] ?>" id="run-bill-<?= $b['bill_id'] ?>" data-payable="<?= e($b['payable']) ?>" checked>
                        <span class="flex-grow-1"><?= e($b['number']) ?><?= $b['vendor_ref'] ? ' · ' . e($b['vendor_ref']) : '' ?><span class="fs-12 text-muted d-block">due <?= e(format_date($b['due_date'])) ?><?= !$b['has_attachment'] ? ' · <span class="text-warning">no document</span>' : '' ?></span></span><span><?= e(fmt_money($b['payable'], $b['currency'], false)) ?></span></label>
                <?php endforeach; ?></div>
            <?php endforeach; ?><?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="payment-run-form-save-btn" <?= $bills === [] ? 'disabled' : '' ?>>Make the run</button>
        <?= hx_link('/payment-runs/', 'Cancel', 'btn btn-light btn-touch w-100', 'id="payment-run-form-cancel-link"') ?>
    </form>
    <script>(function () { var t = document.getElementById('run-bills-total-amount'); function sum() { var c = 0; document.querySelectorAll('.run-bill:checked').forEach(function (b) { c += Math.round(parseFloat(b.dataset.payable) * 100); }); t.textContent = (c / 100).toFixed(2); } document.querySelectorAll('.run-bill').forEach(function (b) { b.addEventListener('change', sum); }); sum(); })();</script>
<?php endif; ?>
</div>
