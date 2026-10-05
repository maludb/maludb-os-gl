<?php
declare(strict_types=1);
/** Action `vendor_credit_line_save` (`vendor_credit` a draft; `line` to change one; log `vendor_credit.line_save`): ap.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$id = req_id('vendor_credit', 'vendor_credit_id') ?? refuse(422, 'Say which vendor credit.');
require_visible($pdo, 'mcp_vendor_credits', 'vendor_credit_id', $id, 'Vendor credit');
$vc = find_vendor_credit($pdo, $id) ?? refuse(404, 'Vendor credit not found.');
$lineId = req_id('line', 'line_id');
$before = null;
if ($lineId !== null) {
    foreach ($vc['lines'] as $l) {
        if ($l['vendor_credit_line_id'] === $lineId) {
            $before = $l;
        }
    }
    $before ?? refuse(404, 'Line not found.');
}
try {
    $defaults = bill_defaults($pdo, $vc['vendor_id'], $vc['credit_date']);
    $f = normalize_doc_line($pdo, doc_line_request($before, 'expense'), $before === null ? count($vc['lines']) + 1 : (int) $before['sort_order'], $defaults, 'expense');
    $pdo->beginTransaction();
    $newLine = save_vendor_credit_line($pdo, $id, $lineId, $f);
    $after = null;
    foreach (vendor_credit_lines($pdo, $id) as $l) {
        if ($l['vendor_credit_line_id'] === $newLine) {
            $after = $l;
        }
    }
    log_activity($pdo, 'vendor_credit.line_save', 'vendor_credit', $id, ap_keys($vc['vendor_id']) + ['before' => $before === null ? null : ['description' => $before['description'], 'line_total' => $before['line_total']], 'after' => ['description' => $after['description'], 'line_total' => $after['line_total'], 'currency' => $vc['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'vendor-credit-lines') {
    emit_action_status(true, ['did' => 'Saved the line', 'record_id' => $newLine]);
    answer_doc_region($pdo, 'vendor_credit', $vc);
}
gl_done(($lineId === null ? 'Added a line' : 'Saved the line'), $newLine, gl_land(return_path('/vendor-credits/' . $id), 'line_saved', 'vendor-credit-lines'), 'vendorCreditChanged', ['vendor_credit_id' => $id, 'vendor_credit_line_id' => $newLine]);
