<?php
declare(strict_types=1);
/** /deposits/ — the deposits (screen `deposits-list`; documents.view). Params: bank_account, status. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('documents.view');
$pdo = db();
$filters = ['bank_account' => request_integer('bank_account'), 'status' => request_string('status')];
$rows = find_deposits($pdo, $filters);
$may = ['write' => has_right('ar.write')];
log_screen_view($pdo, 'deposits-list');
if (wants_json()) {
    respond_screen(['deposits' => array_map(static fn (array $d): array => ['deposit_id' => $d['deposit_id'], 'number' => $d['number'], 'bank_account' => ['bank_account_id' => $d['bank_account_id'], 'name' => $d['bank_account_name']], 'deposit_date' => $d['deposit_date'],
        'currency' => $d['currency'], 'total' => $d['total'], 'status' => $d['status'], 'receipts' => $d['receipt_count']], $rows), 'filters' => $filters, 'may' => $may]);
}
render_screen('Deposits', view('deposits/list.php', ['rows' => $rows, 'filters' => $filters, 'banks' => banks_for_select($pdo), 'may' => $may, 'here' => here_url(), 'undeposited' => count(undeposited_receipts($pdo)),
    'notice' => gl_notice($_GET['notice'] ?? null, ['deleted' => ['success', 'The draft deposit was deleted.']])]), ['activeNav' => 'deposits', 'screen' => 'deposits-list', 'entity' => 'deposit']);
