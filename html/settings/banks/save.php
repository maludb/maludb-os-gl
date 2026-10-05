<?php
declare(strict_types=1);
/** Action `bank_account_save` (by the presence of `bank_account`; log `bank_account.save` before/after): settings.manage. name, kind, account (a ledger account flagged bank), institution, account_last4, currency, opening_balance, opening_date. The guard trigger says what is not a bank account in the chart; the opening balance is fixed once a reconciliation is completed. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/cash/handler.php';
gl_handler_begin();
require_right('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$id = req_id('bank_account', 'bank_account_id');
$cur = null;
if ($id !== null) {
    $cur = bank_account_state($pdo, $id) ?? refuse(404, 'Bank account not found.');
}
$errors = [];
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 120) {
    $errors['name'] = 'Give the bank account a name of up to 120 characters.';
}
$kind = $cur['kind'] ?? 'checking';
if ($cur === null || req_has('kind')) {
    $kind = (string) req_val('kind') === '' ? $kind : (string) req_val('kind');
    if (!isset(BANK_KINDS[$kind])) {
        $errors['kind'] = 'The kind is checking, savings, credit_card, cash or other.';
    }
}
$accField = req_has('account') ? 'account' : 'account_id';
$account = gl_ref($pdo, $accField, $cur['account_id'] ?? null, 'SELECT 1 FROM accounts WHERE id = :id AND archived_at IS NULL', 'ledger account', $errors, false);
$opt = fn (string $k, int $max): ?string => req_has($k) ? ((string) req_val($k) === '' ? null : mb_substr((string) req_val($k), 0, $max)) : ($cur[$k] ?? null);
$last4 = $opt('account_last4', 40);
if ($last4 !== null && !preg_match('/^[0-9A-Za-z]{1,4}$/', $last4)) {
    $errors['account_last4'] = 'The last four characters of the account number, or empty.';
}
$currency = $cur['currency'] ?? null;
if ($cur === null || req_has('currency')) {
    $currency = strtoupper((string) req_val('currency'));
    if ($currency === '' && $cur === null) {
        $currency = base_currency_code($pdo);
    }
    if (!preg_match('/^[A-Z]{3}$/', (string) $currency)) {
        $errors['currency'] = 'The currency is three letters (USD).';
    }
}
$opening = $cur['opening_balance'] ?? '0.00';
if (req_has('opening_balance')) {
    $raw = (string) req_val('opening_balance');
    $m = $raw === '' ? '0.00' : parse_money($raw);
    if ($m === null) {
        $errors['opening_balance'] = 'The opening balance is an amount, like 10,000.00 (negative when overdrawn).';
    } else {
        $opening = $m;
    }
}
$openDate = $cur['opening_date'] ?? date('Y-m-d');
if (req_has('opening_date')) {
    $d = request_date('opening_date');
    if ($d === false) {
        $errors['opening_date'] = 'The opening date is YYYY-MM-DD.';
    } elseif ($d !== null) {
        $openDate = $d;
    }
}
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$f = ['name' => $name, 'kind' => $kind, 'account_id' => $account, 'institution' => $opt('institution', 120), 'account_last4' => $last4, 'currency' => $currency, 'opening_balance' => $opening, 'opening_date' => $openDate];
try {
    $pdo->beginTransaction();
    $newId = save_bank_account($pdo, $id, $f, $me);
    $after = bank_account_log_state(bank_account_state($pdo, $newId));
    if ($cur === null) {
        log_activity($pdo, 'bank_account.save', 'bank_account', $newId, bank_keys($newId) + ['after' => $after]);
    } else {
        $d = gl_diff(bank_account_log_state($cur), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'bank_account.save', 'bank_account', $newId, bank_keys($newId) + ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
} catch (Throwable $ex) {
    cash_refused($pdo, $ex);
}
gl_done(($id === null ? 'Added the bank account ' : 'Saved the bank account ') . $f['name'], $newId, gl_land(return_path('/settings/banks/'), 'saved'), 'bankChanged', ['bank_account_id' => $newId]);
