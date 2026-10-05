<?php
declare(strict_types=1);
/** Action `claim_confirm` (claim, amount: empty takes the claim's, deposit_to; confirm; money_out for an agent; log `claim.confirm`): ar.write. Records the claim as a receipt and applies it to its invoice in one step. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('claim', 'claim_id') ?? refuse(422, 'Say which claim.');
require_visible($pdo, 'mcp_receipt_claims', 'receipt_claim_id', $id, 'Claim');
$errors = [];
$amount = req_money('amount', $errors, 'The amount');
$bank = gl_ref($pdo, req_has('deposit_to') ? 'deposit_to' : 'deposit_to_bank_account_id', null, 'SELECT 1 FROM bank_accounts WHERE id = :id AND archived_at IS NULL', 'bank account', $errors);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
try {
    $pdo->beginTransaction();
    $r = confirm_claim($pdo, $id, array_filter(['amount' => $amount, 'deposit_to' => $bank], static fn ($v) => $v !== null), (int) current_member_id());
    $rs = receipt_state($pdo, $r['receipt_id']);
    log_activity($pdo, 'claim.confirm', 'receipt_claim', $id, audit_keys($r['customer_id'], $rs['journal_entry_id'], $bank) + ['after' => ['claim_id' => $id, 'receipt_id' => $r['receipt_id'], 'number' => $rs['number'], 'amount' => $r['amount'], 'currency' => $r['currency'], 'invoice_id' => $r['claim']['invoice_id']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Confirmed the claim as ' . $rs['number'], $r['receipt_id'], gl_land(return_path('/receipts/' . $r['receipt_id']), 'recorded'), 'receiptChanged', ['receipt_id' => $r['receipt_id'], 'claim_id' => $id]);
