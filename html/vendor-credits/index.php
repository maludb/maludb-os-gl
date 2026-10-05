<?php
declare(strict_types=1);
/** /vendor-credits/ — the vendor credits (screen `vendor-credits-list`; documents.view). Params: status, vendor. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
require_right('documents.view');
$pdo = db();
$filters = ['status' => request_string('status'), 'vendor' => request_integer('vendor')];
$rows = find_vendor_credits($pdo, $filters);
$may = ['write' => has_right('ap.write')];
log_screen_view($pdo, 'vendor-credits-list');
if (wants_json()) {
    respond_screen(['vendor_credits' => array_map(static fn (array $c): array => ['vendor_credit_id' => $c['vendor_credit_id'], 'number' => $c['number'], 'vendor' => ['vendor_id' => $c['vendor_id'], 'name' => $c['vendor_name']], 'bill' => $c['bill_id'] === null ? null : ['bill_id' => $c['bill_id'], 'number' => $c['bill_number']],
        'credit_date' => $c['credit_date'], 'currency' => $c['currency'], 'status' => $c['status'], 'total' => $c['total'], 'balance' => $c['balance']], $rows), 'filters' => $filters, 'may' => $may]);
}
render_screen('Vendor credits', view('vendor_credits/list.php', ['rows' => $rows, 'filters' => $filters, 'may' => $may, 'here' => here_url(), 'notice' => gl_notice($_GET['notice'] ?? null, ['deleted' => ['success', 'The draft was deleted.']])]),
    ['activeNav' => 'vendor-credits', 'screen' => 'vendor-credits-list', 'entity' => 'vendorCredit']);
