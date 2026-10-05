<?php
declare(strict_types=1);
/** Action `vendor_credit_unapply` (vendor_credit, bill; confirm; money_out for an agent; log `vendor_credit.unapply`): ap.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$cid = req_id('vendor_credit', 'vendor_credit_id') ?? refuse(422, 'Say which vendor credit.');
$bid = req_id('bill', 'bill_id') ?? refuse(422, 'Say which bill.');
require_visible($pdo, 'mcp_vendor_credits', 'vendor_credit_id', $cid, 'Vendor credit');
try {
    $pdo->beginTransaction();
    $vc = vendor_credit_state($pdo, $cid);
    $a = unapply_vendor_credit($pdo, $cid, $bid);
    log_activity($pdo, 'vendor_credit.unapply', 'vendor_credit', $cid, ap_keys($vc['vendor_id']) + ['after' => ['number' => $vc['number'], 'bill_id' => $bid, 'bill_number' => $a['bill_number'], 'amount' => $a['amount'], 'currency' => $vc['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Took ' . $vc['number'] . ' off ' . $a['bill_number'], $cid, gl_land(return_path('/vendor-credits/' . $cid), 'unapplied'), 'vendorCreditChanged', ['vendor_credit_id' => $cid, 'bill_id' => $bid]);
