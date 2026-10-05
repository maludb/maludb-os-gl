<?php /** The ledger's settings (screen `general-settings`). Data: s, fixed, pdo, banks, members, notice */ ?>
<?= view('shared/header.php', ['id' => 'general-settings', 'title' => 'Ledger settings', 'crumbs' => [['Home', '/'], ['Ledger settings', null]],
    'action' => hx_link('/settings/numbering', '<i class="feather-hash me-1"></i>Numbering', 'btn btn-light btn-touch', 'id="general-settings-numbering-link"')]) ?>
<div class="main-content" id="general-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/settings/general.php" hx-post="/settings/general.php" hx-target="#flash" id="settings-form">
        <?= csrf_field() ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The business (on the invoice)</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="settings-form-field-name">Name</label>
            <input type="text" name="business_name" id="settings-form-field-name" class="form-control btn-touch mb-3" maxlength="200" value="<?= e($s['business_name'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="settings-form-field-address">Address</label>
            <textarea name="business_address" id="settings-form-field-address" class="form-control mb-3" rows="2" maxlength="500"><?= e($s['business_address'] ?? '') ?></textarea>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="settings-form-field-taxid">Tax id</label><input type="text" name="business_tax_id" id="settings-form-field-taxid" class="form-control btn-touch" maxlength="60" value="<?= e($s['business_tax_id'] ?? '') ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="settings-form-field-currency">Base currency<?= $fixed ? ' (fixed: the books hold a document)' : '' ?></label><input type="text" name="base_currency" id="settings-form-field-currency" class="form-control btn-touch text-uppercase" maxlength="3" pattern="[A-Za-z]{3}" value="<?= e($s['base_currency']) ?>" <?= $fixed ? 'readonly' : '' ?>></div>
            </div>
            <label class="form-label fs-12 text-muted" for="settings-form-field-footer">Invoice footer</label>
            <textarea name="invoice_footer" id="settings-form-field-footer" class="form-control" rows="2" maxlength="1000"><?= e($s['invoice_footer'] ?? '') ?></textarea>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Time</h5></div><div class="card-body">
            <div class="row g-2">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="settings-form-field-fymonth">Fiscal year starts in</label><select name="fiscal_year_start_month" id="settings-form-field-fymonth" class="form-select btn-touch"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $s['fiscal_year_start_month'] === $m ? 'selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $m, 1))) ?></option><?php endfor; ?></select></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="settings-form-field-timezone">Time zone</label><select name="timezone" id="settings-form-field-timezone" class="form-select btn-touch"><?php foreach (DateTimeZone::listIdentifiers() as $z): ?><option value="<?= e($z) ?>" <?= $s['timezone'] === $z ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select></div>
            </div>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Control accounts</h5></div><div class="card-body">
            <?php foreach (CONTROL_ACCOUNTS as $col => [$type, $label]): $short = substr($col, 0, -3); ?>
                <label class="form-label fs-12 text-muted" for="settings-form-field-<?= e($short) ?>"><?= e($label) ?> (<?= e($type) ?>)</label>
                <div class="mb-3"><?= account_select($pdo, $short, $s[$col], [$type], [], '', null, 'settings-form-field-' . $short) ?></div>
            <?php endforeach; ?>
            <label class="form-label fs-12 text-muted" for="settings-form-field-bank">Default bank account (for receipts and payments)</label>
            <select name="default_bank_account" id="settings-form-field-bank" class="form-select btn-touch"><option value="">None yet<?= $banks === [] ? ' (slice 6 adds bank accounts)' : '' ?></option><?php foreach ($banks as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $s['default_bank_account_id'] === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Terms, links and reminders</h5></div><div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-4"><label class="form-label fs-12 text-muted" for="settings-form-field-invterms">Invoice terms (days)</label><input type="number" name="invoice_terms_days" id="settings-form-field-invterms" class="form-control btn-touch" min="0" max="365" value="<?= $s['invoice_terms_days'] ?>"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="settings-form-field-billterms">Bill terms (days)</label><input type="number" name="bill_terms_days" id="settings-form-field-billterms" class="form-control btn-touch" min="0" max="365" value="<?= $s['bill_terms_days'] ?>"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="settings-form-field-link">The link's life (days)</label><input type="number" name="link_lifetime_days" id="settings-form-field-link" class="form-control btn-touch" min="1" max="3650" value="<?= $s['link_lifetime_days'] ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="settings-form-field-reminders">Reminder days past due (comma-separated)</label>
            <input type="text" name="reminder_days" id="settings-form-field-reminders" class="form-control btn-touch mb-3" value="<?= e(implode(', ', $s['reminder_days'])) ?>" placeholder="7, 21, 45">
            <input type="hidden" name="ai_statement_poll" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-3 btn-touch" for="settings-form-field-aipoll"><input type="checkbox" class="form-check-input mt-0" name="ai_statement_poll" value="yes" id="settings-form-field-aipoll" <?= $s['ai_statement_poll'] ? 'checked' : '' ?>>Pull the OS's AI statement each closed month</label>
            <label class="form-label fs-12 text-muted" for="settings-form-field-note">Who receives the Bookkeeper's morning note</label>
            <select name="morning_note_member" id="settings-form-field-note" class="form-select btn-touch"><option value="">Nobody</option><?php foreach ($members as $m): ?><option value="<?= (int) $m['member_id'] ?>" <?= $s['morning_note_member_id'] === (int) $m['member_id'] ? 'selected' : '' ?>><?= e($m['display_name']) ?> (<?= $m['member_kind'] === 'agent' ? 'agent' : 'person' ?>)</option><?php endforeach; ?></select>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100" id="settings-form-save-btn">Save</button>
    </form>
</div>
