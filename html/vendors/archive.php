<?php
declare(strict_types=1);
/** Action `vendor_archive` (log `vendor.archive`; confirm): ap.write. `archived` yes archives (refused with an open bill), no restores. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$id = req_id('vendor', 'vendor_id') ?? refuse(422, 'Say which vendor.');
require_visible($pdo, 'mcp_vendors', 'vendor_id', $id, 'Vendor');
$archived = gl_yes('archived', true);
try {
    $pdo->beginTransaction();
    archive_vendor($pdo, $id, $archived);
    $v = vendor_state($pdo, $id);
    log_activity($pdo, 'vendor.archive', 'vendor', $id, ap_keys($id) + ['before' => ['archived' => !$archived], 'after' => ['archived' => $archived, 'name' => $v['name']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done(($archived ? 'Archived ' : 'Restored ') . $v['name'], $id, gl_land(return_path($archived ? '/vendors/' : '/vendors/' . $id), $archived ? 'archived' : 'restored'), 'vendorChanged');
