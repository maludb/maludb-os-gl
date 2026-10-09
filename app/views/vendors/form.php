<?php /** Add or change a vendor (screens `vendor-add`, `vendor-edit`). Data: cur, locked, pdo, members, base */
$id = $cur['vendor_id'] ?? null; $screen = $cur === null ? 'vendor-add' : 'vendor-edit'; $back = $cur === null ? ['/vendors/', 'vendors'] : ['/vendors/' . (int) $id, 'the vendor']; $ai = ($cur['kind'] ?? '') === 'ai_provider'; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a vendor' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Vendors', '/vendors/'], [$cur === null ? 'Add' : $cur['name'], $cur === null ? null : '/vendors/' . (int) $id], $cur === null ? ['', null] : ['Change', null]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/vendors/save.php" hx-post="/vendors/save.php" hx-target="#flash" id="vendor-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="vendor" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The vendor</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="vendor-form-field-name">Name</label>
            <input type="text" name="name" id="vendor-form-field-name" class="form-control btn-touch mb-3" maxlength="120" required value="<?= e($cur['name'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="vendor-form-field-legal">Legal name</label>
            <input type="text" name="legal_name" id="vendor-form-field-legal" class="form-control btn-touch mb-3" maxlength="200" value="<?= e($cur['legal_name'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="vendor-form-field-kind">Kind<?= $ai ? ' (an AI provider is made by the AI statement; it stays one)' : '' ?></label>
            <select name="kind" id="vendor-form-field-kind" class="form-select btn-touch mb-3" <?= $ai ? 'disabled' : '' ?>><?php foreach (($ai ? VENDOR_KINDS : VENDOR_FORM_KINDS) as $k => $label): ?><option value="<?= e($k) ?>" <?= ($cur['kind'] ?? 'vendor') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
            <?php if ($ai): ?><input type="hidden" name="kind" value="ai_provider"><?php endif; ?>
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="vendor-form-field-email">Email</label><input type="email" name="email" id="vendor-form-field-email" class="form-control btn-touch" maxlength="200" value="<?= e($cur['email'] ?? '') ?>"></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="vendor-form-field-phone">Phone</label><input type="text" name="phone" id="vendor-form-field-phone" class="form-control btn-touch" maxlength="60" value="<?= e($cur['phone'] ?? '') ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="vendor-form-field-remit">Remit address (where payments go)</label>
            <textarea name="remit_address" id="vendor-form-field-remit" class="form-control mb-3" rows="2" maxlength="500"><?= e($cur['remit_address'] ?? '') ?></textarea>
            <label class="form-label fs-12 text-muted" for="vendor-form-field-taxid">Tax id</label>
            <input type="text" name="tax_id" id="vendor-form-field-taxid" class="form-control btn-touch" maxlength="60" value="<?= e($cur['tax_id'] ?? '') ?>">
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">How they are paid</h5></div><div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="vendor-form-field-terms">Terms (days; empty: the settings')</label><input type="number" name="terms_days" id="vendor-form-field-terms" class="form-control btn-touch" min="0" max="365" value="<?= e((string) ($cur['terms_days'] ?? '')) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="vendor-form-field-currency">Currency<?= $locked ? ' (fixed: it has documents)' : ' (empty: ' . e($base) . ')' ?></label><input type="text" name="currency" id="vendor-form-field-currency" class="form-control btn-touch text-uppercase" maxlength="3" value="<?= e($cur['currency'] ?? '') ?>" <?= $locked ? 'readonly' : '' ?>></div>
            </div>
            <label class="form-label fs-12 text-muted" for="vendor-form-field-expense">Expense account (the default for their lines)</label>
            <div class="mb-3"><?= account_select($pdo, 'expense_account', $cur['expense_account_id'] ?? null, ['expense', 'asset'], [], '', 'The item\'s, else 6900', 'vendor-form-field-expense') ?></div>
            <label class="form-label fs-12 text-muted" for="vendor-form-field-pay">Usual pay method</label>
            <select name="pay_method" id="vendor-form-field-pay" class="form-select btn-touch mb-3"><option value="">None</option><?php foreach (PAY_METHODS as $k => $label): ?><option value="<?= e($k) ?>" <?= ($cur['pay_method'] ?? null) === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="vendor-form-field-member">Is this a member? (an employee reimbursed through bills)</label>
            <?= member_select($pdo, 'member', $cur['member_id'] ?? null, '', 'No', 'vendor-form-field-member') ?>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div><div class="card-body"><textarea name="notes" id="vendor-form-field-notes" class="form-control" rows="3" maxlength="2000"><?= e($cur['notes'] ?? '') ?></textarea></div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="vendor-form-save-btn">Save</button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="vendor-form-cancel-link"') ?>
    </form>
</div>
