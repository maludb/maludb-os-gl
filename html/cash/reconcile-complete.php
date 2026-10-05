<?php
declare(strict_types=1);
/** Action `reconciliation_complete` (reconciliation, note; confirm; other for an agent; log `reconciliation.complete` with statement_date, statement_balance, cleared_balance, lines and bank_account_id): bank.reconcile. Only at a zero difference — the RAISE says the figures. The lines through the date are stamped. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/cash/handler.php';
gl_handler_begin();
require_right('bank.reconcile');
$pdo = db();
$id = req_id('reconciliation', 'reconciliation_id', 'bank_reconciliation_id') ?? refuse(422, 'Say which reconciliation.');
$rec = find_reconciliation($pdo, $id) ?? refuse(404, 'Reconciliation not found.');
$note = req_has('note') && trim((string) req_val('note')) !== '' ? mb_substr(trim((string) req_val('note')), 0, 500) : null;
try {
    $pdo->beginTransaction();
    $r = complete_reconciliation($pdo, $id, (int) current_member_id(), $note);
    log_activity($pdo, 'reconciliation.complete', 'reconciliation', $id, bank_keys($r['bank_account_id']) + ['after' => ['statement_date' => $r['statement_date'], 'statement_balance' => $r['statement_balance'], 'cleared_balance' => $r['cleared_balance'], 'lines' => $r['line_count'], 'opening_balance' => $r['opening_balance']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    cash_refused($pdo, $ex);
}
gl_done('Reconciled ' . $r['bank_account_name'] . ' to ' . $r['statement_date'] . ' (' . $r['line_count'] . ' line' . ($r['line_count'] === 1 ? '' : 's') . ')', $id, gl_land('/cash/reconciliations/' . $id, 'completed'), 'bankChanged', ['reconciliation_id' => $id, 'lines' => $r['line_count'], 'statement_balance' => $r['statement_balance']]);
