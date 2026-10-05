<?php
declare(strict_types=1);
/** Action `claim_dismiss` (claim, reason; confirm; log `claim.dismiss`): ar.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('claim', 'claim_id') ?? refuse(422, 'Say which claim.');
require_visible($pdo, 'mcp_receipt_claims', 'receipt_claim_id', $id, 'Claim');
$reason = req_has('reason') && (string) req_val('reason') !== '' ? mb_substr((string) req_val('reason'), 0, 300) : null;
try {
    $pdo->beginTransaction();
    $k = dismiss_claim($pdo, $id, $reason, (int) current_member_id());
    log_activity($pdo, 'claim.dismiss', 'receipt_claim', $id, audit_keys($k['customer_id']) + ['after' => ['claim_id' => $id, 'invoice_id' => $k['invoice_id'], 'reason' => $reason]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Dismissed the claim', $id, gl_land(return_path('/receipts/claims'), 'dismissed'), 'receiptChanged', ['claim_id' => $id]);
