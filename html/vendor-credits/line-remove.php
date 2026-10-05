<?php
declare(strict_types=1);
/** Action `vendor_credit_line_remove` (`vendor_credit` a draft, `line`; log `vendor_credit.line_remove`): ap.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$id = req_id('vendor_credit', 'vendor_credit_id') ?? refuse(422, 'Say which vendor credit.');
require_visible($pdo, 'mcp_vendor_credits', 'vendor_credit_id', $id, 'Vendor credit');
$vc = find_vendor_credit($pdo, $id) ?? refuse(404, 'Vendor credit not found.');
$lineId = req_id('line', 'line_id') ?? refuse(422, 'Say which line.');
$gone = null;
foreach ($vc['lines'] as $l) {
    if ($l['vendor_credit_line_id'] === $lineId) {
        $gone = $l;
    }
}
$gone ?? refuse(404, 'Line not found.');
try {
    $pdo->beginTransaction();
    remove_vendor_credit_line($pdo, $id, $lineId);
    log_activity($pdo, 'vendor_credit.line_remove', 'vendor_credit', $id, ap_keys($vc['vendor_id']) + ['before' => ['sort_order' => $gone['sort_order'], 'description' => $gone['description'], 'line_total' => $gone['line_total']], 'after' => ['removed' => true, 'currency' => $vc['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'vendor-credit-lines') {
    emit_action_status(true, ['did' => 'Removed the line', 'record_id' => $lineId]);
    answer_doc_region($pdo, 'vendor_credit', $vc);
}
gl_done('Removed line ' . $gone['sort_order'], $lineId, gl_land(return_path('/vendor-credits/' . $id), 'line_removed', 'vendor-credit-lines'), 'vendorCreditChanged', ['vendor_credit_id' => $id]);
