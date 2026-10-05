<?php
declare(strict_types=1);
/** Action `deposit_delete` (a draft; confirm; deletion for an agent; log `deposit.delete` before the delete): ar.write. Its receipts return to undeposited. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('deposit', 'deposit_id') ?? refuse(422, 'Say which deposit.');
require_visible($pdo, 'mcp_deposits', 'deposit_id', $id, 'Deposit');
try {
    $pdo->beginTransaction();
    $d = deposit_state($pdo, $id) ?? throw new DomainException('Not found.');
    log_activity($pdo, 'deposit.delete', 'deposit', $id, audit_keys(null, null, $d['bank_account_id']) + ['before' => ['number' => $d['number'], 'amount' => $d['total'], 'currency' => $d['currency']]]);
    delete_deposit($pdo, $id);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Deleted the draft deposit', $id, gl_land(return_path('/deposits/'), 'deleted'), 'depositChanged');
