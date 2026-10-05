<?php
declare(strict_types=1);
/** /bills/new and /bills/{id}/edit — a new bill's head, first lines and its document, or a draft's head (screens `bill-add`, `bill-edit`; ap.write). Params on add: vendor, copy_of. The lines editor is on the bill page. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
require_right('ap.write');
require_human();
$pdo = db();
$id = req_id('id', 'bill');
$cur = null;
if ($id !== null) {
    require_visible($pdo, 'mcp_bills', 'bill_id', $id, 'Bill');
    $cur = find_bill($pdo, $id) ?? refuse(404, 'Bill not found.');
    if ($cur['status'] !== 'draft') {
        refuse(422, 'Bill ' . $cur['number'] . ' is approved: its vendor, date and totals cannot change (credit it, or void it).');
    }
}
$vendorId = $cur['vendor_id'] ?? request_integer('vendor');
$copy = null;
if ($cur === null && ($copyId = request_integer('copy_of')) !== null) {
    require_visible($pdo, 'mcp_bills', 'bill_id', $copyId, 'Bill');
    $copy = copy_bill($pdo, $copyId);
    $vendorId ??= $copy['vendor_id'];
}
$defaults = $vendorId === null ? null : bill_defaults($pdo, (int) $vendorId);
$screen = $cur === null ? 'bill-add' : 'bill-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['bill' => $cur === null ? null : present_bill($cur), 'defaults' => $defaults === null ? null : ['currency' => $defaults['currency'], 'bill_date' => $defaults['bill_date'], 'due_date' => $defaults['due_date'], 'expense_account_id' => $defaults['expense_account_id']], 'copy' => $copy, 'vendors' => vendors_for_select($pdo), 'items' => items_for_lines($pdo)]);
}
render_screen($cur === null ? 'New bill' : 'Change ' . $cur['number'], view('bills/form.php', ['cur' => $cur, 'copy' => $copy, 'vendorId' => $vendorId, 'defaults' => $defaults, 'pdo' => $pdo, 'items' => items_for_lines($pdo), 'taxRates' => find_tax_rates($pdo),
    'departments' => find_live_departments($pdo), 'sites' => find_live_sites($pdo), 'may' => ['attach' => has_right('attachments.write')], 'here' => here_url()]), ['activeNav' => 'bills', 'screen' => $screen, 'entity' => 'bill', 'recordId' => $id === null ? '' : (string) $id]);
