<?php
declare(strict_types=1);
/**
 * GET /settings/general — the ledger's settings (screen `general-settings`). POST = action `settings_save` (log `settings.save`: before/after of the changed keys only;
 * "Nothing changed" logs nothing): settings.manage. The base currency is fixed once the books hold a document.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/present.php';
require_once dirname(__DIR__, 2) . '/app/views/shared/account-select.php';
require_right('settings.manage');
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_human();
    $s = find_settings($pdo);
    $fixed = books_hold_a_document($pdo);
    log_screen_view($pdo, 'general-settings');
    if (wants_json()) {
        respond_screen(['settings' => present_settings($s), 'currency_fixed' => $fixed, 'control_accounts' => array_map(static fn (array $c): array => ['type' => $c[0], 'label' => $c[1]], CONTROL_ACCOUNTS)]);
    }
    $banks = $pdo->query('SELECT id, name FROM bank_accounts WHERE archived_at IS NULL ORDER BY name')->fetchAll();
    render_screen('Ledger settings', view('settings/general.php', ['s' => $s, 'fixed' => $fixed, 'pdo' => $pdo, 'banks' => $banks, 'members' => find_members_for_select($pdo),
        'notice' => gl_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved the settings.'], 'same' => ['info', 'Nothing changed.']])]),
        ['activeNav' => 'general', 'screen' => 'general-settings', 'entity' => 'settings']);
    exit;
}
gl_handler_begin();
$cur = find_settings($pdo);
$errors = [];
$f = [];
foreach (['business_name' => 200, 'business_address' => 500, 'business_tax_id' => 60, 'invoice_footer' => 1000] as $k => $max) {
    if (req_has($k)) {
        $v = (string) req_val($k);
        $f[$k] = $v === '' ? null : mb_substr($v, 0, $max);
    }
}
if (req_has('base_currency')) {
    $f['base_currency'] = strtoupper((string) req_val('base_currency'));
    if (!preg_match('/^[A-Z]{3}$/', $f['base_currency'])) {
        $errors['base_currency'] = 'The base currency is a three-letter code (USD).';
    } elseif ($f['base_currency'] !== $cur['base_currency'] && books_hold_a_document($pdo)) {
        $errors['base_currency'] = 'The base currency is fixed once the books hold a document.';
    }
}
$f['fiscal_year_start_month'] = gl_int('fiscal_year_start_month', $cur['fiscal_year_start_month'], 1, 12, 'The fiscal year start month', $errors);
if (req_has('timezone')) {
    $f['timezone'] = (string) req_val('timezone');
    if (!in_array($f['timezone'], DateTimeZone::listIdentifiers(), true)) {
        $errors['timezone'] = 'The time zone is an IANA name like America/New_York.';
    }
}
foreach (CONTROL_ACCOUNTS as $col => [$type, $label]) {
    $short = substr($col, 0, -3);
    $field = req_has($short) ? $short : (req_has($col) ? $col : null);
    if ($field !== null) {
        $f[$col] = gl_ref($pdo, $field, $cur[$col], 'SELECT 1 FROM accounts WHERE id = :id AND archived_at IS NULL', $label . ' account', $errors, false);
        if (!isset($errors[$field]) && $f[$col] !== null && !db_bool($pdo, 'SELECT account_type = :t FROM accounts WHERE id = :id', ['t' => $type, 'id' => $f[$col]])) {
            $errors[$field] = $label . ' is a' . (in_array($type, ['asset', 'equity', 'expense', 'income'], true) ? 'n ' : ' ') . $type . ' account.';
        }
    }
}
$bankField = req_has('default_bank_account') ? 'default_bank_account' : (req_has('default_bank_account_id') ? 'default_bank_account_id' : null);
if ($bankField !== null) {
    $f['default_bank_account_id'] = gl_ref($pdo, $bankField, $cur['default_bank_account_id'], 'SELECT 1 FROM bank_accounts WHERE id = :id AND archived_at IS NULL', 'bank account', $errors);
}
$f['invoice_terms_days'] = gl_int('invoice_terms_days', $cur['invoice_terms_days'], 0, 365, 'Invoice terms', $errors);
$f['bill_terms_days'] = gl_int('bill_terms_days', $cur['bill_terms_days'], 0, 365, 'Bill terms', $errors);
$f['link_lifetime_days'] = gl_int('link_lifetime_days', $cur['link_lifetime_days'], 1, 3650, "The link's life", $errors);
$days = request_list('reminder_days');
if ($days !== null) {
    $bad = array_filter($days, static fn (string $d): bool => filter_var($d, FILTER_VALIDATE_INT) === false || (int) $d < 1 || (int) $d > 365);
    if ($bad !== []) {
        $errors['reminder_days'] = 'Reminder days are whole numbers from 1 to 365.';
    } else {
        $f['reminder_days'] = array_values(array_unique(array_map('intval', $days)));
        sort($f['reminder_days']);
    }
}
$f['ai_statement_poll'] = gl_yes('ai_statement_poll', $cur['ai_statement_poll']);
$noteField = req_has('morning_note_member') ? 'morning_note_member' : (req_has('morning_note_member_id') ? 'morning_note_member_id' : null);
if ($noteField !== null) {
    $f['morning_note_member_id'] = gl_ref($pdo, $noteField, $cur['morning_note_member_id'], "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL", 'member', $errors);
}
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$d = gl_guard($pdo, static function () use ($pdo, $f): array {
    $pdo->beginTransaction();
    $d = save_settings($pdo, $f);
    if ($d['after'] !== []) {
        log_activity($pdo, 'settings.save', 'gl_settings', 1, ['before' => $d['before'], 'after' => $d['after']]);
    }
    $pdo->commit();
    return $d;
});
gl_done($d['after'] === [] ? 'Nothing changed' : 'Saved the settings (' . implode(', ', array_keys($d['after'])) . ')', 1, gl_land(return_path('/settings/general'), $d['after'] === [] ? 'same' : 'saved'), 'settingsChanged', ['changed' => array_keys($d['after'])]);
