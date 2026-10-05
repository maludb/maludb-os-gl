<?php
declare(strict_types=1);
/** /cash/banks/{id} — one bank account: its figures, its lines by status and date, its reconciliations, the ledger activity, its transfers (screen `bank-account-view`; documents.view). Params: status, from, to, page. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/cash/handler.php';
require_right('documents.view');
$pdo = db();
$id = req_id('id', 'bank_account') ?? refuse(404, 'Bank account not found.');
$bank = find_bank_account($pdo, $id) ?? refuse(404, 'Bank account not found.');
$from = request_date('from'); $to = request_date('to');
if ($from === false || $to === false) {
    refuse(422, 'A date is YYYY-MM-DD.');
}
$filters = ['status' => request_string('status'), 'from' => $from ?? '', 'to' => $to ?? ''];
$page = max(1, request_integer('page') ?? 1);
$lines = find_bank_lines($pdo, $id, $filters, BANK_LINE_PAGE, ($page - 1) * BANK_LINE_PAGE);
$total = count_bank_lines($pdo, $id, $filters);
$matched = [];
foreach ($lines as $l) {
    $matched[$l['bank_transaction_id']] = matched_item($pdo, $l);
}
$counts = bank_line_counts($pdo, $id);
$recons = find_reconciliations($pdo, $id);
$ledger = bank_ledger_activity($pdo, $bank, date('Y-m-d', strtotime('-90 days')), date('Y-m-d'));
$transfers = find_transfers($pdo, ['bank_account' => $id], 10);
$timeline = find_record_activity($pdo, 'bank_account', $id);
$may = ['write' => has_right('bank.write'), 'reconcile' => has_right('bank.reconcile'), 'settings' => has_right('settings.manage'), 'note' => has_right('documents.view')];
log_screen_view($pdo, 'bank-account-view', ['after' => ['record_type' => 'bank_account', 'record_id' => $id], 'bank_account_id' => $id]);
if (wants_json()) {
    respond_screen(['bank_account' => present_bank_account($bank), 'counts' => $counts, 'lines' => array_map(static fn (array $l): array => present_bank_line($l, $matched[$l['bank_transaction_id']]), $lines), 'total' => $total, 'page' => $page, 'per_page' => BANK_LINE_PAGE,
        'reconciliations' => array_map('present_reconciliation', $recons), 'transfers' => array_map('present_transfer', array_map(static fn (array $t): array => $t + ['matched_lines' => []], $transfers)), 'filters' => $filters, 'may' => $may]);
}
render_screen($bank['name'], view('cash/bank.php', ['bank' => $bank, 'lines' => $lines, 'matched' => $matched, 'counts' => $counts, 'total' => $total, 'page' => $page, 'filters' => $filters, 'recons' => $recons, 'ledger' => $ledger, 'transfers' => $transfers, 'timeline' => $timeline,
    'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => gl_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.'], 'imported' => ['success', 'Imported.']])]), ['activeNav' => 'banks', 'screen' => 'bank-account-view', 'entity' => 'bankAccount', 'recordId' => (string) $id]);
