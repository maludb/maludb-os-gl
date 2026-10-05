<?php
declare(strict_types=1);
/** /cash/reconcile — reconcile a bank account against a statement balance (screen `bank-reconcile`; bank.write). Params: bank_account (default: the first with a reconciliation in progress, else the first account), reconciliation. No one in progress: the start form; one in progress: its figures live, the lines through the date, Complete (only at zero), Abandon. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/cash/handler.php';
require_right('bank.write');
$pdo = db();
$banks = bank_accounts_for_select($pdo);
$recId = request_integer('reconciliation');
$rec = null;
if ($recId !== null) {
    $rec = find_reconciliation($pdo, $recId) ?? refuse(404, 'Reconciliation not found.');
}
$bankId = request_integer('bank_account') ?? ($rec['bank_account_id'] ?? null);
if ($bankId === null) {
    $first = $pdo->query("SELECT bank_account_id FROM mcp_bank_reconciliations WHERE status = 'in_progress' ORDER BY bank_account_id LIMIT 1")->fetchColumn();
    $bankId = $first !== false ? (int) $first : ($banks[0]['bank_account_id'] ?? null);
}
$bank = $bankId === null ? null : (find_bank_account($pdo, $bankId) ?? refuse(404, 'Bank account not found.'));
if ($rec === null && $bank !== null) {
    $rec = reconciliation_in_progress($pdo, $bankId);
}
$lines = [];
if ($rec !== null) {
    if ($rec['status'] === 'in_progress') {
        try {
            $pdo->beginTransaction();
            $rec = recompute_reconciliation($pdo, $rec['bank_reconciliation_id']);
            $pdo->commit();
        } catch (Throwable $ex) {
            cash_refused($pdo, $ex);
        }
    }
    $lines = reconciliation_lines($pdo, $rec);
}
$may = ['write' => true, 'reconcile' => has_right('bank.reconcile')];
log_screen_view($pdo, 'bank-reconcile');
$counts = ['matched' => 0, 'ignored' => 0, 'unmatched' => 0];
foreach ($lines as $l) {
    $counts[$l['status']]++;
}
if (wants_json()) {
    respond_screen(['bank_account' => $bank === null ? null : present_bank_account($bank), 'banks' => $banks, 'reconciliation' => $rec === null ? null : present_reconciliation($rec), 'lines' => array_map(static fn (array $l): array => present_bank_line($l), $lines), 'counts' => $counts, 'may' => $may,
        'can_complete' => $rec !== null && $rec['status'] === 'in_progress' && m_cmp((string) $rec['difference'], '0') === 0]);
}
render_screen('Reconcile', view('cash/reconcile.php', ['bank' => $bank, 'banks' => $banks, 'rec' => $rec, 'lines' => $lines, 'counts' => $counts, 'may' => $may, 'here' => here_url(),
    'notice' => gl_notice($_GET['notice'] ?? null, ['started' => ['success', 'Started. Match or ignore every line through the statement date; the difference must reach zero.'], 'abandoned' => ['warning', 'The reconciliation was abandoned.'], 'matched' => ['success', 'Matched.']])]),
    ['activeNav' => 'reconcile', 'screen' => 'bank-reconcile', 'entity' => 'reconciliation', 'recordId' => $rec === null ? '' : (string) $rec['bank_reconciliation_id']]);
