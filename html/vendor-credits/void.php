<?php
declare(strict_types=1);
/** Action `vendor_credit_void` (issued and unapplied; reason; confirm; money_out for an agent; log `vendor_credit.void`): journal.override. The guard refuses an applied one ("unapply it first"); its entry is reversed. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('journal.override');
$pdo = db();
$id = req_id('vendor_credit', 'vendor_credit_id') ?? refuse(422, 'Say which vendor credit.');
require_visible($pdo, 'mcp_vendor_credits', 'vendor_credit_id', $id, 'Vendor credit');
$reason = mb_substr((string) req_val('reason'), 0, 500);
try {
    $pdo->beginTransaction();
    $c = void_vendor_credit($pdo, $id, (int) current_member_id(), $reason);
    log_activity($pdo, 'vendor_credit.void', 'vendor_credit', $id, ap_keys($c['vendor_id'], $c['journal_entry_id']) + ['after' => ['number' => $c['number'], 'reason' => $reason, 'amount' => $c['total'], 'currency' => $c['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Voided ' . $c['number'], $id, gl_land(return_path('/vendor-credits/' . $id), 'voided'), 'vendorCreditChanged', ['vendor_credit_id' => $id]);
