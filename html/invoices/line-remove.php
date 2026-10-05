<?php
declare(strict_types=1);
/** Action `invoice_line_remove` (`invoice` a draft, `line`; log `invoice.line_remove`): ar.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('invoice', 'invoice_id') ?? refuse(422, 'Say which invoice.');
require_visible($pdo, 'mcp_invoices', 'invoice_id', $id, 'Invoice');
$inv = find_invoice($pdo, $id) ?? refuse(404, 'Invoice not found.');
$lineId = req_id('line', 'line_id') ?? refuse(422, 'Say which line.');
$gone = null;
foreach ($inv['lines'] as $l) {
    if ($l['invoice_line_id'] === $lineId) {
        $gone = $l;
    }
}
$gone ?? refuse(404, 'Line not found.');
try {
    $pdo->beginTransaction();
    remove_invoice_line($pdo, $id, $lineId);
    log_activity($pdo, 'invoice.line_remove', 'invoice', $id, audit_keys($inv['customer_id']) + ['before' => ['sort_order' => $gone['sort_order'], 'description' => $gone['description'], 'line_total' => $gone['line_total']], 'after' => ['removed' => true, 'currency' => $inv['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'invoice-lines') {
    emit_action_status(true, ['did' => 'Removed the line', 'record_id' => $lineId]);
    answer_doc_region($pdo, 'invoice', $inv);
}
gl_done('Removed line ' . $gone['sort_order'], $lineId, gl_land(return_path('/invoices/' . $id), 'line_removed', 'invoice-lines'), 'invoiceChanged', ['invoice_id' => $id, 'totals' => invoice_totals_row($pdo, $id)]);
