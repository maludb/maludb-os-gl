<?php
declare(strict_types=1);
/**
 * Actions `payment_draft` (no `payment`: vendor, amount, payment_date, method, cheque_number, bank_account, reference, memo, bills[] — applied oldest due first up to the amount, payment_run — a draft run to join; log `payment.draft`) and
 * `payment_update` (`payment` a draft or approved: any of those; an approved payment's vendor, amount and bank account are fixed; log `payment.update` before/after): ap.write. The currency is the bank account's.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$me = (int) current_member_id();
$id = req_id('payment', 'payment_id');
$cur = null;
if ($id !== null) {
    require_visible($pdo, 'mcp_payments', 'payment_id', $id, 'Payment');
    $cur = payment_state($pdo, $id) ?? refuse(404, 'Payment not found.');
}
$errors = [];
$vendorId = $cur['vendor_id'] ?? null;
if ($cur === null || req_has('vendor') || req_has('vendor_id')) {
    $vendorId = gl_ref($pdo, req_has('vendor') ? 'vendor' : 'vendor_id', $vendorId, 'SELECT 1 FROM vendors WHERE id = :id', 'vendor', $errors, false);
}
$bankField = req_has('bank_account') ? 'bank_account' : 'bank_account_id';
$defaultBank = $cur['bank_account_id'] ?? ($cur === null ? ($pdo->query('SELECT default_bank_account_id FROM gl_settings WHERE id = 1')->fetchColumn() ?: null) : null);
$bank = gl_ref($pdo, $bankField, $defaultBank === null ? null : (int) $defaultBank, 'SELECT 1 FROM bank_accounts WHERE id = :id AND archived_at IS NULL', 'bank account', $errors, false);
$date = $cur['payment_date'] ?? date('Y-m-d');
if (req_has('payment_date')) {
    $d = request_date('payment_date');
    if ($d === false) {
        $errors['payment_date'] = 'The date is YYYY-MM-DD.';
    } else {
        $date = $d ?? date('Y-m-d');
    }
}
$method = $cur['method'] ?? null;
if ($cur === null || req_has('method')) {
    $method = (string) req_val('method');
    if ($method === '' && $vendorId !== null && $cur === null) {
        $method = (string) ((vendor_state($pdo, $vendorId)['pay_method'] ?? '') ?: '');
    }
    if (!isset(PAY_METHODS[$method])) {
        $errors['method'] = 'The method is cheque, ach, card, cash or other.';
    }
}
$billIds = array_values(array_unique(array_map('intval', array_filter(request_list('bills') ?? [], static fn (string $v): bool => ctype_digit($v)))));
$amount = req_money('amount', $errors, 'The amount', true, $cur['amount'] ?? null);
if ($amount === null && !isset($errors['amount'])) {
    if ($billIds !== []) {
        $amount = '0';
        foreach ($billIds as $b) {
            $amount = m_add($amount, bill_payable($pdo, $b));
        }
    }
    if ($amount === null || m_cmp($amount, '0') <= 0) {
        $errors['amount'] = 'The amount is required (or name bills with something still to pay).';
    }
}
$runField = req_has('payment_run') ? 'payment_run' : 'payment_run_id';
$run = gl_ref($pdo, $runField, $cur['payment_run_id'] ?? null, 'SELECT 1 FROM payment_runs WHERE id = :id', 'payment run', $errors);
$opt = fn (string $k, int $max): ?string => req_has($k) ? ((string) req_val($k) === '' ? null : mb_substr((string) req_val($k), 0, $max)) : ($cur[$k] ?? null);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$currency = trim((string) $pdo->query('SELECT currency FROM bank_accounts WHERE id = ' . (int) $bank)->fetchColumn());
$f = ['vendor_id' => $vendorId, 'payment_date' => $date, 'amount' => $amount, 'currency' => $currency, 'method' => $method, 'cheque_number' => $opt('cheque_number', 40), 'bank_account_id' => $bank, 'reference' => $opt('reference', 120), 'memo' => $opt('memo', 500), 'payment_run_id' => $run];
try {
    $pdo->beginTransaction();
    foreach ($billIds as $b) {
        require_visible($pdo, 'mcp_bills', 'bill_id', $b, 'Bill');
    }
    if ($cur === null) {
        $newId = draft_payment($pdo, $f, $billIds, $me);
        $p = payment_state($pdo, $newId);
        log_activity($pdo, 'payment.draft', 'payment', $newId, ap_keys($vendorId, null, $bank) + ['after' => ['number' => $p['number'], 'amount' => $amount, 'currency' => $currency, 'method' => $method, 'payment_date' => $date, 'applied' => payment_applied_list($pdo, $newId), 'payment_run_id' => $run]]);
        if ($run === null) {
            notify_payment_awaiting('payment', $newId, $p['number'], $amount, $currency);
        }
        $pdo->commit();
        gl_done('Prepared ' . $p['number'] . ' (' . number_format((float) $amount, 2) . ' ' . $currency . ')', $newId, gl_land(return_path('/payments/' . $newId), 'saved'), 'paymentChanged', ['payment_id' => $newId, 'number' => $p['number'], 'applied' => payment_applied_list($pdo, $newId)]);
    }
    $d = update_payment($pdo, $id, $f, $me);
    if ($d['after'] !== []) {
        log_activity($pdo, 'payment.update', 'payment', $id, ap_keys($cur['vendor_id'], null, $cur['bank_account_id']) + ['before' => $d['before'], 'after' => $d['after']]);
    }
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done($d['after'] === [] ? 'Nothing changed' : 'Saved the payment (' . implode(', ', array_keys($d['after'])) . ')', $id, gl_land(return_path('/payments/' . $id), 'saved'), 'paymentChanged', ['payment_id' => $id, 'changed' => array_keys($d['after'])]);
