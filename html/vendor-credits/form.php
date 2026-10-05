<?php
declare(strict_types=1);
/** /vendor-credits/new and /{id}/edit — a vendor credit's head and first lines, or a draft's head (screens `vendor-credit-add`, `vendor-credit-edit`; ap.write). Params on add: vendor, bill (its lines are prefilled). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
require_right('ap.write');
require_human();
$pdo = db();
$id = req_id('id', 'vendor_credit');
$cur = null;
if ($id !== null) {
    require_visible($pdo, 'mcp_vendor_credits', 'vendor_credit_id', $id, 'Vendor credit');
    $cur = find_vendor_credit($pdo, $id) ?? refuse(404, 'Vendor credit not found.');
    if ($cur['status'] !== 'draft') {
        refuse(422, 'Vendor credit ' . $cur['number'] . ' is ' . $cur['status'] . ' and cannot change.');
    }
}
$bill = null;
if ($cur === null && ($bid = request_integer('bill')) !== null) {
    require_visible($pdo, 'mcp_bills', 'bill_id', $bid, 'Bill');
    $bill = find_bill($pdo, $bid);
}
$vendorId = $cur['vendor_id'] ?? request_integer('vendor') ?? ($bill['vendor_id'] ?? null);
$prefill = $bill === null ? [] : copy_bill($pdo, $bill['bill_id'])['lines'];
$screen = $cur === null ? 'vendor-credit-add' : 'vendor-credit-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['vendor_credit' => $cur === null ? null : ['vendor_credit_id' => $cur['vendor_credit_id'], 'vendor_id' => $cur['vendor_id'], 'bill_id' => $cur['bill_id'], 'vendor_ref' => $cur['vendor_ref'], 'reason' => $cur['reason']], 'prefill' => $prefill]);
}
render_screen($cur === null ? 'New vendor credit' : 'Change the vendor credit', view('vendor_credits/form.php', ['cur' => $cur, 'bill' => $bill, 'vendorId' => $vendorId, 'prefill' => $prefill, 'pdo' => $pdo, 'items' => items_for_lines($pdo), 'departments' => find_live_departments($pdo), 'sites' => find_live_sites($pdo)]),
    ['activeNav' => 'vendor-credits', 'screen' => $screen, 'entity' => 'vendorCredit', 'recordId' => $id === null ? '' : (string) $id]);
