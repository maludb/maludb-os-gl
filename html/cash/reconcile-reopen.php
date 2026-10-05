<?php
declare(strict_types=1);
/** Action `reconciliation_reopen` (reconciliation — the latest completed, reason; confirm; other for an agent; log `reconciliation.reopen` with the reason): bank.reconcile. The stamped lines are freed and the account is back at the previous statement. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/cash/handler.php';
gl_handler_begin();
require_right('bank.reconcile');
$pdo = db();
$id = req_id('reconciliation', 'reconciliation_id', 'bank_reconciliation_id') ?? refuse(422, 'Say which reconciliation.');
$rec = find_reconciliation($pdo, $id) ?? refuse(404, 'Reconciliation not found.');
$reason = mb_substr(trim((string) req_val('reason')), 0, 500);
try {
    $pdo->beginTransaction();
    $r = reopen_reconciliation($pdo, $id, (int) current_member_id(), $reason);
    log_activity($pdo, 'reconciliation.reopen', 'reconciliation', $id, bank_keys($r['bank_account_id']) + ['after' => ['statement_date' => $r['statement_date'], 'reason' => $reason, 'lines_freed' => $rec['line_count']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    cash_refused($pdo, $ex);
}
gl_done('Reopened the reconciliation of ' . $r['bank_account_name'] . ' to ' . $r['statement_date'], $id, gl_land('/cash/reconciliations/' . $id, 'reopened'), 'bankChanged', ['reconciliation_id' => $id]);
