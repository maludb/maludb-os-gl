<?php
declare(strict_types=1);
/**
 * Action `receipt_record` (customer, amount, received_on, method, reference, memo, deposit_to, invoice, apply_oldest, claim, and the form's apply[invoice id] amounts; confirm; money_out for an agent; log `receipt.record`
 * with number, amount, currency, method, deposit_to, applied[], claim_id, customer_id, journal_entry_id, bank_account_id): ar.write. The receipt is numbered and posted (DR the bank named or Undeposited funds / CR receivable), then applied.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$claimId = req_id('claim', 'claim_id');
$claim = null;
if ($claimId !== null) {
    $claim = find_claim($pdo, $claimId) ?? refuse(404, 'Claim not found.');
}
$invoiceId = req_id('invoice', 'invoice_id') ?? ($claim['invoice_id'] ?? null);
$inv = null;
if ($invoiceId !== null) {
    require_visible($pdo, 'mcp_invoices', 'invoice_id', $invoiceId, 'Invoice');
    $inv = find_invoice($pdo, $invoiceId);
}
$customerId = null;
if (req_has('customer') || req_has('customer_id')) {
    $customerId = gl_ref($pdo, req_has('customer') ? 'customer' : 'customer_id', null, 'SELECT 1 FROM customers WHERE id = :id AND archived_at IS NULL', 'customer', $errors, false);
} elseif ($inv !== null) {
    $customerId = $inv['customer_id'];
} else {
    $errors['customer'] = 'Customer is required.';
}
$amount = req_money('amount', $errors, 'The amount');
if ($amount === null && !isset($errors['amount'])) {
    $amount = $claim['amount'] ?? ($inv['balance_due'] ?? null);
    if ($amount === null) {
        $errors['amount'] = 'The amount is required.';
    }
}
$received = request_date('received_on');
if ($received === false) {
    $errors['received_on'] = 'The date is YYYY-MM-DD.';
}
$method = req_has('method') ? (string) req_val('method') : (string) ($claim['method'] ?? '');
if (!isset(RECEIPT_METHODS[$method])) {
    $errors['method'] = 'The method is cash, cheque, transfer, card or other.';
}
$bankField = req_has('deposit_to') ? 'deposit_to' : 'deposit_to_bank_account_id';
$bank = gl_ref($pdo, $bankField, null, 'SELECT 1 FROM bank_accounts WHERE id = :id AND archived_at IS NULL', 'bank account', $errors);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$customer = customer_state($pdo, $customerId) ?? refuse(404, 'Customer not found.');
$currency = $inv['currency'] ?? $customer['currency'] ?? base_currency_code($pdo);
$apply = [];
foreach ((array) ($_POST['apply'] ?? []) as $iid => $a) {
    $m = parse_money((string) $a);
    if ($m !== null && (float) $m > 0 && ctype_digit((string) $iid)) {
        $apply[(int) $iid] = $m;
    }
}
$f = ['customer_id' => $customerId, 'received_on' => $received ?? date('Y-m-d'), 'amount' => $amount, 'currency' => $currency, 'method' => $method, 'reference' => req_has('reference') ? ((string) req_val('reference') === '' ? null : mb_substr((string) req_val('reference'), 0, 120)) : ($claim['reference'] ?? null),
      'memo' => req_has('memo') ? ((string) req_val('memo') === '' ? null : mb_substr((string) req_val('memo'), 0, 500)) : null, 'deposit_to' => $bank, 'invoice_id' => $invoiceId, 'apply_oldest' => gl_yes('apply_oldest', false)];
try {
    $pdo->beginTransaction();
    if ($bank !== null) {
        $bc = $pdo->prepare('SELECT currency, name FROM bank_accounts WHERE id = :b');
        $bc->execute(['b' => $bank]);
        $b = $bc->fetch();
        if (trim((string) $b['currency']) !== $currency) {
            throw new DomainException($b['name'] . ' holds ' . trim((string) $b['currency']) . ' and this receipt is in ' . $currency . '.');
        }
    }
    $r = record_receipt($pdo, $f, $apply, $claimId, $me);
    $rs = receipt_state($pdo, $r['receipt_id']);
    log_activity($pdo, 'receipt.record', 'receipt', $r['receipt_id'], audit_keys($customerId, $rs['journal_entry_id'], $bank) + ['after' => ['number' => $rs['number'], 'amount' => $f['amount'], 'currency' => $currency, 'method' => $method,
        'deposit_to' => $bank === null ? 'undeposited' : $bank, 'applied' => $r['applied'], 'claim_id' => $claimId]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Recorded ' . $rs['number'] . ' (' . number_format((float) $f['amount'], 2) . ' ' . $currency . ')' . ($r['applied'] !== [] ? ', applied to ' . implode(', ', array_column($r['applied'], 'number')) : ''), $r['receipt_id'], gl_land(return_path('/receipts/' . $r['receipt_id']), 'recorded'), 'receiptChanged',
    ['receipt_id' => $r['receipt_id'], 'number' => $rs['number'], 'applied' => $r['applied'], 'unapplied' => $r['unapplied']]);
