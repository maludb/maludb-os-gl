<?php
declare(strict_types=1);
/** Action `deposit_record` (a draft with receipts; confirm; money_out for an agent; log `deposit.record` with amount, currency, receipts, bank_account_id, journal_entry_id): ar.write. gl_deposit_record() posts DR the bank / CR Undeposited funds. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('deposit', 'deposit_id') ?? refuse(422, 'Say which deposit.');
require_visible($pdo, 'mcp_deposits', 'deposit_id', $id, 'Deposit');
try {
    $pdo->beginTransaction();
    $r = record_deposit($pdo, $id, (int) current_member_id());
    log_activity($pdo, 'deposit.record', 'deposit', $id, audit_keys(null, $r['journal_entry_id'], $r['bank_account_id']) + ['after' => ['number' => $r['number'], 'amount' => $r['amount'], 'currency' => $r['currency'], 'receipts' => $r['receipts']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Recorded ' . $r['number'] . ' (' . number_format((float) $r['amount'], 2) . ' ' . $r['currency'] . ')', $id, gl_land(return_path('/deposits/' . $id), 'recorded'), 'depositChanged', ['deposit_id' => $id, 'number' => $r['number']]);
