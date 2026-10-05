<?php
declare(strict_types=1);
/** Action `deposit_add_receipt` (deposit a draft, receipt undeposited; log `deposit.add_receipt`): ar.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('deposit', 'deposit_id') ?? refuse(422, 'Say which deposit.');
$rid = req_id('receipt', 'receipt_id') ?? refuse(422, 'Say which receipt.');
require_visible($pdo, 'mcp_deposits', 'deposit_id', $id, 'Deposit');
require_visible($pdo, 'mcp_receipts', 'receipt_id', $rid, 'Receipt');
try {
    $pdo->beginTransaction();
    $d = deposit_state($pdo, $id);
    $r = deposit_add($pdo, $id, $rid);
    log_activity($pdo, 'deposit.add_receipt', 'deposit', $id, audit_keys(null, null, $d['bank_account_id']) + ['after' => ['number' => $d['number'], 'receipt_number' => $r['receipt_number'], 'amount' => $r['amount'], 'currency' => $d['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Added ' . $r['receipt_number'] . ' to ' . $d['number'], $id, gl_land(return_path('/deposits/' . $id), 'saved'), 'depositChanged', ['deposit_id' => $id]);
