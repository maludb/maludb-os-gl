<?php
declare(strict_types=1);
/** Action `deposit_remove_receipt` (deposit a draft, receipt; log `deposit.remove_receipt`): ar.write. A receipt cannot leave a recorded deposit (the guard). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('deposit', 'deposit_id') ?? refuse(422, 'Say which deposit.');
$rid = req_id('receipt', 'receipt_id') ?? refuse(422, 'Say which receipt.');
require_visible($pdo, 'mcp_deposits', 'deposit_id', $id, 'Deposit');
try {
    $pdo->beginTransaction();
    $d = deposit_state($pdo, $id);
    $r = deposit_remove($pdo, $id, $rid);
    log_activity($pdo, 'deposit.remove_receipt', 'deposit', $id, audit_keys(null, null, $d['bank_account_id']) + ['after' => ['number' => $d['number'], 'receipt_number' => $r['receipt_number'], 'amount' => $r['amount'], 'currency' => $d['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Took ' . $r['receipt_number'] . ' out of ' . $d['number'], $id, gl_land(return_path('/deposits/' . $id), 'saved'), 'depositChanged', ['deposit_id' => $id]);
