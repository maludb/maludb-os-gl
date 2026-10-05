<?php
declare(strict_types=1);
/** Action `vendor_credit_issue` (a draft; apply_to a bill; confirm; money_out for an agent; log `vendor_credit.issue` with number, amount, currency, journal_entry_number): ap.approve. gl_vendor_credit_issue() posts DR AP (the vendor) / CR each line's expense account. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.approve');
$pdo = db();
$id = req_id('vendor_credit', 'vendor_credit_id') ?? refuse(422, 'Say which vendor credit.');
require_visible($pdo, 'mcp_vendor_credits', 'vendor_credit_id', $id, 'Vendor credit');
$applyTo = req_id('apply_to');
if ($applyTo !== null) {
    require_visible($pdo, 'mcp_bills', 'bill_id', $applyTo, 'Bill');
}
try {
    $pdo->beginTransaction();
    $r = issue_vendor_credit($pdo, $id, (int) current_member_id(), $applyTo);
    log_activity($pdo, 'vendor_credit.issue', 'vendor_credit', $id, ap_keys($r['vendor_id'], $r['journal_entry_id']) + ['after' => ['number' => $r['number'], 'amount' => $r['total'], 'currency' => $r['currency'], 'journal_entry_number' => $r['journal_entry_number'], 'applied_to' => $applyTo]]);
    if ($r['applied'] !== null) {
        log_activity($pdo, 'vendor_credit.apply', 'vendor_credit', $id, ap_keys($r['vendor_id']) + ['after' => ['number' => $r['number'], 'bill_id' => $applyTo, 'bill_number' => $r['applied']['bill_number'], 'amount' => $r['applied']['amount'], 'currency' => $r['currency']]]);
    }
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Issued ' . $r['number'] . ' (' . number_format((float) $r['total'], 2) . ' ' . $r['currency'] . ')' . ($r['applied'] !== null ? ', applied to ' . $r['applied']['bill_number'] : ''), $id, gl_land(return_path('/vendor-credits/' . $id), 'issued'), 'vendorCreditChanged', ['vendor_credit_id' => $id, 'number' => $r['number']]);
