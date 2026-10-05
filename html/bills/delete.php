<?php
declare(strict_types=1);
/** Action `bill_delete` (a draft; confirm; deletion for an agent; log `bill.delete` BEFORE the delete): ap.write. An approved bill is never deleted (void it). Bill numbers are made at creation, so a deleted draft leaves a gap. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$id = req_id('bill', 'bill_id') ?? refuse(422, 'Say which bill.');
require_visible($pdo, 'mcp_bills', 'bill_id', $id, 'Bill');
try {
    $pdo->beginTransaction();
    $s = bill_state($pdo, $id) ?? throw new DomainException('Not found.');
    log_activity($pdo, 'bill.delete', 'bill', $id, ap_keys($s['vendor_id']) + ['before' => bill_log_state($s) + ['number' => $s['number'], 'total' => $s['total']]]);
    delete_bill($pdo, $id);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Deleted the draft bill ' . $s['number'], $id, gl_land(return_path('/bills/'), 'deleted'), 'billChanged');
