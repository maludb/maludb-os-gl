<?php
declare(strict_types=1);
/** /cash/reconciliations/{id} — one reconciliation: its figures, its lines, who completed it, Reopen (bank.reconcile, a reason; the latest only) (screen `reconciliation-view`; documents.view). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/cash/handler.php';
require_right('documents.view');
$pdo = db();
$id = req_id('id', 'reconciliation') ?? refuse(404, 'Reconciliation not found.');
$rec = find_reconciliation($pdo, $id) ?? refuse(404, 'Reconciliation not found.');
$lines = reconciliation_lines($pdo, $rec);
$latest = latest_completed_reconciliation($pdo, $rec['bank_account_id']);
$may = ['reconcile' => has_right('bank.reconcile'), 'write' => has_right('bank.write')];
$timeline = find_record_activity($pdo, 'reconciliation', $id);
log_screen_view($pdo, 'reconciliation-view', ['after' => ['record_type' => 'reconciliation', 'record_id' => $id], 'bank_account_id' => $rec['bank_account_id']]);
if (wants_json()) {
    respond_screen(['reconciliation' => present_reconciliation($rec), 'lines' => array_map(static fn (array $l): array => present_bank_line($l), $lines), 'can_reopen' => $rec['status'] === 'completed' && $latest === $id, 'may' => $may]);
}
render_screen('Reconciliation to ' . $rec['statement_date'], view('cash/reconciliation.php', ['rec' => $rec, 'lines' => $lines, 'latest' => $latest === $id, 'currency' => (find_bank_account($pdo, $rec['bank_account_id'])['currency'] ?? 'USD'), 'may' => $may, 'timeline' => $timeline, 'here' => here_url(), 'tz' => member_timezone(),
    'notice' => gl_notice($_GET['notice'] ?? null, ['completed' => ['success', 'Reconciled. The lines are stamped and the account is reconciled through the statement date.'], 'reopened' => ['warning', 'Reopened: the lines are free again and the account is back at the previous statement.']])]),
    ['activeNav' => 'reconcile', 'screen' => 'reconciliation-view', 'entity' => 'reconciliation', 'recordId' => (string) $id]);
