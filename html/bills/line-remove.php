<?php
declare(strict_types=1);
/** Action `bill_line_remove` (`bill` a draft, `line`; log `bill.line_remove`): ap.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$id = req_id('bill', 'bill_id') ?? refuse(422, 'Say which bill.');
require_visible($pdo, 'mcp_bills', 'bill_id', $id, 'Bill');
$bill = find_bill($pdo, $id) ?? refuse(404, 'Bill not found.');
$lineId = req_id('line', 'line_id') ?? refuse(422, 'Say which line.');
$gone = null;
foreach ($bill['lines'] as $l) {
    if ($l['bill_line_id'] === $lineId) {
        $gone = $l;
    }
}
$gone ?? refuse(404, 'Line not found.');
try {
    $pdo->beginTransaction();
    remove_bill_line($pdo, $id, $lineId);
    log_activity($pdo, 'bill.line_remove', 'bill', $id, ap_keys($bill['vendor_id']) + ['before' => ['sort_order' => $gone['sort_order'], 'description' => $gone['description'], 'line_total' => $gone['line_total']], 'after' => ['removed' => true, 'currency' => $bill['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'bill-lines') {
    emit_action_status(true, ['did' => 'Removed the line', 'record_id' => $lineId]);
    answer_doc_region($pdo, 'bill', $bill);
}
gl_done('Removed line ' . $gone['sort_order'], $lineId, gl_land(return_path('/bills/' . $id), 'line_removed', 'bill-lines'), 'billChanged', ['bill_id' => $id, 'totals' => bill_totals_row($pdo, $id)]);
