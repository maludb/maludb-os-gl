<?php /** Record a transfer (screen `transfer-add`). Data: banks, from, to, here */ ?>
<?= view('shared/header.php', ['id' => 'transfer-add', 'title' => 'Record a transfer', 'crumbs' => [['Home', '/'], ['Bank accounts', '/cash/'], ['Transfers', '/cash/transfers/'], ['New', null]], 'back' => back_link() ?? ['/cash/transfers/', 'transfers']]) ?>
<div class="main-content" id="transfer-add-content">
    <form method="post" action="/cash/transfers/save.php" hx-post="/cash/transfers/save.php" hx-target="#flash" id="transfer-form"><?= csrf_field() ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The transfer</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="transfer-form-field-from">From (the sending account)</label>
            <select name="from_bank_account" id="transfer-form-field-from" class="form-select btn-touch mb-3" required><option value="">Choose…</option><?php foreach ($banks as $b): ?><option value="<?= $b['bank_account_id'] ?>" <?= $from === $b['bank_account_id'] ? 'selected' : '' ?>><?= e($b['name']) ?> (<?= e($b['currency']) ?>)</option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="transfer-form-field-to">To (the receiving account, the same currency)</label>
            <select name="to_bank_account" id="transfer-form-field-to" class="form-select btn-touch mb-3" required><option value="">Choose…</option><?php foreach ($banks as $b): ?><option value="<?= $b['bank_account_id'] ?>" <?= $to === $b['bank_account_id'] ? 'selected' : '' ?>><?= e($b['name']) ?> (<?= e($b['currency']) ?>)</option><?php endforeach; ?></select>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="transfer-form-field-amount">Amount</label><input type="text" inputmode="decimal" name="amount" id="transfer-form-field-amount" class="form-control btn-touch" required></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="transfer-form-field-date">Date</label><input type="date" name="transfer_date" id="transfer-form-field-date" class="form-control btn-touch" value="<?= e(date('Y-m-d')) ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="transfer-form-field-memo">Memo</label>
            <input type="text" name="memo" id="transfer-form-field-memo" class="form-control btn-touch" maxlength="500">
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="transfer-form-save-btn">Record the transfer</button>
        <?= hx_link('/cash/transfers/', 'Cancel', 'btn btn-light btn-touch w-100', 'id="transfer-form-cancel-link"') ?>
    </form>
</div>
