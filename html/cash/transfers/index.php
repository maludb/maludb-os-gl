<?php
declare(strict_types=1);
/** /cash/transfers/ — the transfers between bank accounts (screen `transfers-list`; documents.view). Params: bank_account, status. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/cash/handler.php';
require_right('documents.view');
$pdo = db();
$filters = ['bank_account' => request_integer('bank_account'), 'status' => request_string('status')];
$rows = find_transfers($pdo, $filters);
$may = ['write' => has_right('bank.write')];
log_screen_view($pdo, 'transfers-list');
if (wants_json()) {
    respond_screen(['transfers' => array_map(static fn (array $t): array => present_transfer($t + ['matched_lines' => []]), $rows), 'filters' => $filters, 'may' => $may]);
}
render_screen('Transfers', view('cash/transfers.php', ['rows' => $rows, 'filters' => $filters, 'banks' => bank_accounts_for_select($pdo), 'may' => $may, 'here' => here_url(), 'notice' => gl_notice($_GET['notice'] ?? null, ['recorded' => ['success', 'Recorded.']])]), ['activeNav' => 'transfers', 'screen' => 'transfers-list', 'entity' => 'transfer']);
