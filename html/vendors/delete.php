<?php
declare(strict_types=1);
/** Action `vendor_delete` (log `vendor.delete`; confirm; deletion for an agent): journal.override. Refused in words when any bill, vendor credit, payment, posted line or attached document exists. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('journal.override');
$pdo = db();
$id = req_id('vendor', 'vendor_id') ?? refuse(422, 'Say which vendor.');
require_visible($pdo, 'mcp_vendors', 'vendor_id', $id, 'Vendor');
try {
    $pdo->beginTransaction();
    $v = delete_vendor($pdo, $id);
    log_activity($pdo, 'vendor.delete', 'vendor', $id, ['before' => vendor_log_state($v)]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Deleted ' . $v['name'], $id, gl_land(return_path('/vendors/'), 'archived'), 'vendorChanged');
