<?php
declare(strict_types=1);
/** Action `invoice_line_save` (`invoice` a draft; `line` present = change; item fills description, price, account and tax; log `invoice.line_save`, before of a changed line): ar.write. The trigger computes the money. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('invoice', 'invoice_id') ?? refuse(422, 'Say which invoice.');
require_visible($pdo, 'mcp_invoices', 'invoice_id', $id, 'Invoice');
$inv = find_invoice($pdo, $id) ?? refuse(404, 'Invoice not found.');
$lineId = req_id('line', 'line_id');
$before = null;
if ($lineId !== null) {
    foreach ($inv['lines'] as $l) {
        if ($l['invoice_line_id'] === $lineId) {
            $before = $l;
        }
    }
    $before ?? refuse(404, 'Line not found.');
}
try {
    $defaults = invoice_defaults($pdo, $inv['customer_id'], $inv['issue_date']);
    $f = normalize_doc_line($pdo, doc_line_request($before), $before === null ? count($inv['lines']) + 1 : (int) $before['sort_order'], $defaults);
    $pdo->beginTransaction();
    $newLine = save_invoice_line($pdo, $id, $lineId, $f);
    $after = null;
    foreach (invoice_lines($pdo, $id) as $l) {
        if ($l['invoice_line_id'] === $newLine) {
            $after = $l;
        }
    }
    $pack = static fn (array $l): array => ['sort_order' => $l['sort_order'], 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'line_total' => $l['line_total'], 'income_account_code' => $l['income_account_code']];
    log_activity($pdo, 'invoice.line_save', 'invoice', $id, audit_keys($inv['customer_id']) + ['before' => $before === null ? null : $pack($before), 'after' => $pack($after) + ['currency' => $inv['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'invoice-lines') {
    emit_action_status(true, ['did' => 'Saved the line', 'record_id' => $newLine]);
    answer_doc_region($pdo, 'invoice', $inv);
}
gl_done(($lineId === null ? 'Added line ' : 'Saved line ') . $after['sort_order'], $newLine, gl_land(return_path('/invoices/' . $id), 'line_saved', 'invoice-lines'), 'invoiceChanged', ['invoice_id' => $id, 'invoice_line_id' => $newLine, 'totals' => invoice_totals_row($pdo, $id)]);
