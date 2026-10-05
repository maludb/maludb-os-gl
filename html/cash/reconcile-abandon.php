<?php
declare(strict_types=1);
/** /cash/reconcile-abandon.php — abandon the reconciliation in progress (not an action: it is a person's undo of a start; log `reconciliation.abandon`): bank.write. The in-progress row is deleted; nothing was stamped. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/cash/handler.php';
gl_handler_begin();
require_right('bank.write');
$pdo = db();
$id = req_id('reconciliation', 'reconciliation_id', 'bank_reconciliation_id') ?? refuse(422, 'Say which reconciliation.');
try {
    $pdo->beginTransaction();
    $r = abandon_reconciliation($pdo, $id);
    log_activity($pdo, 'reconciliation.abandon', 'reconciliation', $id, bank_keys($r['bank_account_id']) + ['before' => ['statement_date' => $r['statement_date'], 'statement_balance' => $r['statement_balance'], 'status' => 'in_progress']]);
    $pdo->commit();
} catch (Throwable $ex) {
    cash_refused($pdo, $ex);
}
gl_done('Abandoned the reconciliation of ' . $r['bank_account_name'] . ' to ' . $r['statement_date'], $id, gl_land('/cash/reconcile?bank_account=' . $r['bank_account_id'], 'abandoned'), 'bankChanged');
