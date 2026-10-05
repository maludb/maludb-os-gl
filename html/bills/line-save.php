<?php
declare(strict_types=1);
/** Action `bill_line_save` (`bill` a draft; `line` present = change; item fills description, price, account and tax; expense_account; log `bill.line_save`, before of a changed line): ap.write. The trigger computes the money. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$id = req_id('bill', 'bill_id') ?? refuse(422, 'Say which bill.');
require_visible($pdo, 'mcp_bills', 'bill_id', $id, 'Bill');
$bill = find_bill($pdo, $id) ?? refuse(404, 'Bill not found.');
$lineId = req_id('line', 'line_id');
$before = null;
if ($lineId !== null) {
    foreach ($bill['lines'] as $l) {
        if ($l['bill_line_id'] === $lineId) {
            $before = $l;
        }
    }
    $before ?? refuse(404, 'Line not found.');
}
try {
    $defaults = bill_defaults($pdo, $bill['vendor_id'], $bill['bill_date']);
    $f = normalize_doc_line($pdo, doc_line_request($before, 'expense'), $before === null ? count($bill['lines']) + 1 : (int) $before['sort_order'], $defaults, 'expense');
    $pdo->beginTransaction();
    $newLine = save_bill_line($pdo, $id, $lineId, $f);
    $after = null;
    foreach (bill_lines($pdo, $id) as $l) {
        if ($l['bill_line_id'] === $newLine) {
            $after = $l;
        }
    }
    $pack = static fn (array $l): array => ['sort_order' => $l['sort_order'], 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'line_total' => $l['line_total'], 'expense_account_code' => $l['expense_account_code']];
    log_activity($pdo, 'bill.line_save', 'bill', $id, ap_keys($bill['vendor_id']) + ['before' => $before === null ? null : $pack($before), 'after' => $pack($after) + ['currency' => $bill['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'bill-lines') {
    emit_action_status(true, ['did' => 'Saved the line', 'record_id' => $newLine]);
    answer_doc_region($pdo, 'bill', $bill);
}
gl_done(($lineId === null ? 'Added line ' : 'Saved line ') . $after['sort_order'], $newLine, gl_land(return_path('/bills/' . $id), 'line_saved', 'bill-lines'), 'billChanged', ['bill_id' => $id, 'bill_line_id' => $newLine, 'totals' => bill_totals_row($pdo, $id)]);
