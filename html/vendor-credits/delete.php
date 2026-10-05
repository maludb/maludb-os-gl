<?php
declare(strict_types=1);
/** Action `vendor_credit_delete` (a draft; confirm; deletion for an agent; log `vendor_credit.delete` BEFORE the delete): ap.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$id = req_id('vendor_credit', 'vendor_credit_id') ?? refuse(422, 'Say which vendor credit.');
require_visible($pdo, 'mcp_vendor_credits', 'vendor_credit_id', $id, 'Vendor credit');
try {
    $pdo->beginTransaction();
    $c = vendor_credit_state($pdo, $id) ?? throw new DomainException('Not found.');
    log_activity($pdo, 'vendor_credit.delete', 'vendor_credit', $id, ap_keys($c['vendor_id']) + ['before' => vendor_credit_log_state($c) + ['number' => $c['number'], 'total' => $c['total']]]);
    delete_vendor_credit($pdo, $id);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Deleted the draft ' . $c['number'], $id, gl_land(return_path('/vendor-credits/'), 'deleted'), 'vendorCreditChanged');
