<?php
declare(strict_types=1);
/** /cash/ — the bank accounts as cards (screen `banks`; documents.view): ledger balance, cleared balance, the difference, unmatched lines, last reconciled, a reconciliation in progress; Import, Match, Reconcile. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/cash/handler.php';
require_right('documents.view');
$pdo = db();
$banks = find_bank_accounts($pdo, !empty($_GET['include_archived']));
$may = ['write' => has_right('bank.write'), 'reconcile' => has_right('bank.reconcile'), 'settings' => has_right('settings.manage')];
log_screen_view($pdo, 'banks');
if (wants_json()) {
    respond_screen(['bank_accounts' => array_map('present_bank_account', $banks), 'totals' => cash_totals($banks), 'may' => $may]);
}
render_screen('Bank accounts', view('cash/index.php', ['banks' => $banks, 'totals' => cash_totals($banks), 'may' => $may, 'here' => here_url(), 'notice' => gl_notice($_GET['notice'] ?? null, ['imported' => ['success', 'Imported.'], 'deleted' => ['success', 'The import was deleted.']])]),
    ['activeNav' => 'banks', 'screen' => 'banks', 'entity' => 'bankAccount']);
