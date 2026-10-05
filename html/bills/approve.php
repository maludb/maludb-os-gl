<?php
declare(strict_types=1);
/** Action `bill_approve` (a draft with lines; confirm; money_out for an agent; log `bill.approve` with number, amount, currency, journal_entry_number, has_document, vendor_id, journal_entry_id): ap.approve. gl_bill_approve() posts DR each line's expense account (the gross) / CR AP. A bill with no document is allowed; the page says so. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.approve');
$pdo = db();
$id = req_id('bill', 'bill_id') ?? refuse(422, 'Say which bill.');
require_visible($pdo, 'mcp_bills', 'bill_id', $id, 'Bill');
try {
    $pdo->beginTransaction();
    $r = approve_bill($pdo, $id, (int) current_member_id());
    log_activity($pdo, 'bill.approve', 'bill', $id, ap_keys($r['vendor_id'], $r['journal_entry_id']) + ['after' => ['number' => $r['number'], 'amount' => $r['total'], 'currency' => $r['currency'], 'journal_entry_number' => $r['journal_entry_number'], 'has_document' => $r['has_document']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Approved ' . $r['number'] . ' (' . number_format((float) $r['total'], 2) . ' ' . $r['currency'] . ')', $id, gl_land(return_path('/bills/' . $id), $r['has_document'] ? 'approved' : 'approved_no_document'), 'billChanged',
    ['bill_id' => $id, 'number' => $r['number'], 'amount' => $r['total'], 'currency' => $r['currency'], 'has_document' => $r['has_document']]);
