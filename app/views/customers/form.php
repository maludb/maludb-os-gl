<?php /** Add or change a customer (screens `customer-add`, `customer-edit`). Data: cur, locked, pdo, taxRates, members, base */
$id = $cur['customer_id'] ?? null; $screen = $cur === null ? 'customer-add' : 'customer-edit'; $back = $cur === null ? ['/customers/', 'customers'] : ['/customers/' . (int) $id, 'the customer']; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a customer' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Customers', '/customers/'], [$cur === null ? 'Add' : $cur['name'], $cur === null ? null : '/customers/' . (int) $id], $cur === null ? ['', null] : ['Change', null]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/customers/save.php" hx-post="/customers/save.php" hx-target="#flash" id="customer-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="customer" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The customer</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="customer-form-field-name">Name</label>
            <input type="text" name="name" id="customer-form-field-name" class="form-control btn-touch mb-3" maxlength="120" required value="<?= e($cur['name'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="customer-form-field-legal">Legal name (on the invoice, when different)</label>
            <input type="text" name="legal_name" id="customer-form-field-legal" class="form-control btn-touch mb-3" maxlength="200" value="<?= e($cur['legal_name'] ?? '') ?>">
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="customer-form-field-email">Email (invoices go here)</label><input type="email" name="email" id="customer-form-field-email" class="form-control btn-touch" maxlength="200" value="<?= e($cur['email'] ?? '') ?>"></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="customer-form-field-phone">Phone</label><input type="text" name="phone" id="customer-form-field-phone" class="form-control btn-touch" maxlength="60" value="<?= e($cur['phone'] ?? '') ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="customer-form-field-billing">Billing address</label>
            <textarea name="billing_address" id="customer-form-field-billing" class="form-control mb-3" rows="2" maxlength="500"><?= e($cur['billing_address'] ?? '') ?></textarea>
            <label class="form-label fs-12 text-muted" for="customer-form-field-shipping">Shipping address</label>
            <textarea name="shipping_address" id="customer-form-field-shipping" class="form-control mb-3" rows="2" maxlength="500"><?= e($cur['shipping_address'] ?? '') ?></textarea>
            <label class="form-label fs-12 text-muted" for="customer-form-field-taxid">Tax id</label>
            <input type="text" name="tax_id" id="customer-form-field-taxid" class="form-control btn-touch" maxlength="60" value="<?= e($cur['tax_id'] ?? '') ?>">
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">How they are invoiced</h5></div><div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="customer-form-field-terms">Terms (days; empty: the settings')</label><input type="number" name="terms_days" id="customer-form-field-terms" class="form-control btn-touch" min="0" max="365" value="<?= e((string) ($cur['terms_days'] ?? '')) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="customer-form-field-currency">Currency<?= $locked ? ' (fixed: it has documents)' : ' (empty: ' . e($base) . ')' ?></label><input type="text" name="currency" id="customer-form-field-currency" class="form-control btn-touch text-uppercase" maxlength="3" value="<?= e($cur['currency'] ?? '') ?>" <?= $locked ? 'readonly' : '' ?>></div>
            </div>
            <label class="form-label fs-12 text-muted" for="customer-form-field-income">Income account (the default for their lines)</label>
            <div class="mb-3"><?= account_select($pdo, 'income_account', $cur['income_account_id'] ?? null, ['income'], [], '', 'The item\'s, else 4100', 'customer-form-field-income') ?></div>
            <label class="form-label fs-12 text-muted" for="customer-form-field-tax">Tax rate (the default for their lines)</label>
            <select name="tax_rate" id="customer-form-field-tax" class="form-select btn-touch mb-3"><option value="">The default rate</option><?php foreach ($taxRates as $t): ?><option value="<?= (int) $t['tax_rate_id'] ?>" <?= ($cur['tax_rate_id'] ?? null) === (int) $t['tax_rate_id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="customer-form-field-member">Is this a member? (an employee or an invited external who is this customer)</label>
            <?= member_select($pdo, 'member', $cur['member_id'] ?? null, '', 'No', 'customer-form-field-member') ?>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div><div class="card-body">
            <textarea name="notes" id="customer-form-field-notes" class="form-control" rows="3" maxlength="2000"><?= e($cur['notes'] ?? '') ?></textarea>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="customer-form-save-btn">Save</button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="customer-form-cancel-link"') ?>
    </form>
</div>
