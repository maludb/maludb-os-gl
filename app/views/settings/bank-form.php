<?php /** Add or change a bank account (screens `bank-account-add`, `bank-account-edit`). Data: cur, docs, pdo, base */
$id = $cur['bank_account_id'] ?? null; $screen = $cur === null ? 'bank-account-add' : 'bank-account-edit'; $back = ['/settings/banks/', 'the bank accounts']; $fixed = $docs !== null && $docs['completed'] > 0; $used = $docs !== null && ($docs['lines'] + $docs['receipts'] + $docs['deposits'] + $docs['payments'] + $docs['payment_runs'] + $docs['transfers']) > 0; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a bank account' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Settings', '/settings/'], ['Bank accounts', '/settings/banks/'], [$cur === null ? 'Add' : $cur['name'], null]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/settings/banks/save.php" hx-post="/settings/banks/save.php" hx-target="#flash" id="bank-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="bank_account" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The bank account</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="bank-form-field-name">Name</label>
            <input type="text" name="name" id="bank-form-field-name" class="form-control btn-touch mb-3" maxlength="120" required value="<?= e($cur['name'] ?? '') ?>">
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="bank-form-field-kind">Kind</label><select name="kind" id="bank-form-field-kind" class="form-select btn-touch"><?php foreach (BANK_KINDS as $k => $label): ?><option value="<?= e($k) ?>" <?= ($cur['kind'] ?? 'checking') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="bank-form-field-currency">Currency<?= $used ? ' (fixed: it has lines or documents)' : '' ?></label><input type="text" name="currency" id="bank-form-field-currency" class="form-control btn-touch text-uppercase" maxlength="3" value="<?= e($cur['currency'] ?? $base) ?>" <?= $used ? 'readonly' : '' ?>></div>
            </div>
            <label class="form-label fs-12 text-muted" for="bank-form-field-account">Ledger account (a bank account of the chart; a credit card stands over a liability)</label>
            <div class="mb-3"><?= account_select($pdo, 'account', $cur['account_id'] ?? null, [], ['is_bank'], 'required', 'Choose the ledger account…', 'bank-form-field-account') ?></div>
            <div class="row g-2">
                <div class="col-8"><label class="form-label fs-12 text-muted" for="bank-form-field-institution">Institution</label><input type="text" name="institution" id="bank-form-field-institution" class="form-control btn-touch" maxlength="120" value="<?= e($cur['institution'] ?? '') ?>"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="bank-form-field-last4">Last four</label><input type="text" name="account_last4" id="bank-form-field-last4" class="form-control btn-touch" maxlength="4" value="<?= e($cur['account_last4'] ?? '') ?>"></div>
            </div>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The opening balance</h5></div><div class="card-body">
            <div class="row g-2 mb-2">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="bank-form-field-opening">The bank's balance at the end of the opening day</label><input type="text" inputmode="decimal" name="opening_balance" id="bank-form-field-opening" class="form-control btn-touch" value="<?= e((string) ($cur['opening_balance'] ?? '0.00')) ?>" <?= $fixed ? 'readonly' : '' ?>></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="bank-form-field-opening-date">Opening date</label><input type="date" name="opening_date" id="bank-form-field-opening-date" class="form-control btn-touch" value="<?= e($cur['opening_date'] ?? date('Y-m-d')) ?>" <?= $fixed ? 'readonly' : '' ?>></div>
            </div>
            <?php if ($fixed): ?><div class="fs-12 text-warning" id="bank-form-opening-fixed">Reconciled through <?= e(format_date((string) $cur['last_reconciled_through'])) ?>: the opening balance is fixed.</div><?php endif; ?>
            <div class="fs-12 text-muted" id="bank-form-opening-note">Enter the opening balance in the books too — a manual entry on the account dated the opening day (DR the bank account / CR 3000 Owner equity).<?= $id !== null ? ' ' . hx_link('/journal/new?template=opening&bank_account=' . (int) $id . '&date=' . e((string) $cur['opening_date']), 'Open that entry, prefilled', '', 'id="bank-form-opening-link"') : ' Save the bank account first and the link to the prefilled entry appears here.' ?></div>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="bank-form-save-btn">Save</button>
        <?php if ($cur !== null): ?><button type="button" class="d-none"></button><?php endif; ?>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="bank-form-cancel-link"') ?>
    </form>
    <?php if ($cur !== null): ?>
    <form method="post" action="/settings/banks/archive.php" hx-post="/settings/banks/archive.php" hx-target="#flash" class="mt-3" <?= $cur['archived_at'] === null ? 'hx-confirm="Archive ' . e($cur['name']) . '?"' : '' ?>><?= csrf_field() ?><input type="hidden" name="bank_account" value="<?= (int) $id ?>"><input type="hidden" name="archived" value="<?= $cur['archived_at'] === null ? 'yes' : 'no' ?>">
        <button type="submit" class="btn btn-light btn-touch w-100" id="bank-form-archive-btn"><?= $cur['archived_at'] === null ? 'Archive' : 'Restore' ?></button></form>
    <?php endif; ?>
</div>
