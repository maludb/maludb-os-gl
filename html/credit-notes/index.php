<?php
declare(strict_types=1);
/** /credit-notes/ — the credit notes (screen `credit-notes-list`; documents.view). Params: status, customer. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('documents.view');
$pdo = db();
$filters = ['status' => request_string('status'), 'customer' => request_integer('customer')];
$rows = find_credit_notes($pdo, $filters);
$may = ['write' => has_right('ar.write')];
log_screen_view($pdo, 'credit-notes-list');
if (wants_json()) {
    respond_screen(['credit_notes' => array_map(static fn (array $c): array => ['credit_note_id' => $c['credit_note_id'], 'number' => $c['number'], 'customer' => ['customer_id' => $c['customer_id'], 'name' => $c['customer_name']], 'invoice' => $c['invoice_id'] === null ? null : ['invoice_id' => $c['invoice_id'], 'number' => $c['invoice_number']],
        'issue_date' => $c['issue_date'], 'currency' => $c['currency'], 'status' => $c['status'], 'total' => $c['total'], 'balance' => $c['balance']], $rows), 'filters' => $filters, 'may' => $may]);
}
render_screen('Credit notes', view('credit_notes/list.php', ['rows' => $rows, 'filters' => $filters, 'may' => $may, 'here' => here_url(), 'notice' => gl_notice($_GET['notice'] ?? null, ['deleted' => ['success', 'The draft was deleted.']])]),
    ['activeNav' => 'credit-notes', 'screen' => 'credit-notes-list', 'entity' => 'creditNote']);
