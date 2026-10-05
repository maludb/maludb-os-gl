<?php
declare(strict_types=1);
/** /invoices/new and /invoices/{id}/edit — a new invoice's head and first lines, or a draft's head (screens `invoice-add`, `invoice-edit`; ar.write). Params on add: customer, copy_of. The lines editor is on the invoice page. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('ar.write');
require_human();
$pdo = db();
$id = req_id('id', 'invoice');
$cur = null;
if ($id !== null) {
    require_visible($pdo, 'mcp_invoices', 'invoice_id', $id, 'Invoice');
    $cur = find_invoice($pdo, $id) ?? refuse(404, 'Invoice not found.');
    if ($cur['status'] !== 'draft') {
        refuse(422, 'Invoice ' . $cur['number'] . ' is issued: its customer, dates, number and totals cannot change (credit it, or void it).');
    }
}
$customerId = $cur['customer_id'] ?? request_integer('customer');
$copy = null;
if ($cur === null && ($copyId = request_integer('copy_of')) !== null) {
    require_visible($pdo, 'mcp_invoices', 'invoice_id', $copyId, 'Invoice');
    $copy = find_invoice($pdo, $copyId);
    $customerId ??= $copy['customer_id'] ?? null;
}
$defaults = $customerId === null ? null : invoice_defaults($pdo, (int) $customerId);
$screen = $cur === null ? 'invoice-add' : 'invoice-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['invoice' => $cur === null ? null : present_invoice($cur), 'defaults' => $defaults === null ? null : ['currency' => $defaults['currency'], 'issue_date' => $defaults['issue_date'], 'due_date' => $defaults['due_date']], 'customers' => customers_for_select($pdo), 'items' => items_for_lines($pdo)]);
}
render_screen($cur === null ? 'New invoice' : 'Change ' . ($cur['number'] ?? 'the draft'), view('invoices/form.php', ['cur' => $cur, 'copy' => $copy, 'customerId' => $customerId, 'defaults' => $defaults, 'pdo' => $pdo, 'items' => items_for_lines($pdo), 'taxRates' => find_tax_rates($pdo),
    'departments' => find_live_departments($pdo), 'sites' => find_live_sites($pdo), 'here' => here_url()]), ['activeNav' => 'invoices', 'screen' => $screen, 'entity' => 'invoice', 'recordId' => $id === null ? '' : (string) $id]);
