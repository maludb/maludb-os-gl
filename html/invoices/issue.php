<?php
declare(strict_types=1);
/** Action `invoice_issue` (a draft with lines; confirm; money_out for an agent; log `invoice.issue` with number, amount, currency, journal_entry_number, journal_entry_id, customer_id): ar.write. gl_invoice_issue() numbers it (gapless) and posts it; the link is minted and held, nothing is sent. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('invoice', 'invoice_id') ?? refuse(422, 'Say which invoice.');
require_visible($pdo, 'mcp_invoices', 'invoice_id', $id, 'Invoice');
try {
    $pdo->beginTransaction();
    $r = issue_invoice($pdo, $id, (int) current_member_id());
    log_activity($pdo, 'invoice.issue', 'invoice', $id, audit_keys($r['customer_id'], $r['journal_entry_id']) + ['after' => ['number' => $r['number'], 'amount' => $r['total'], 'currency' => $r['currency'], 'journal_entry_number' => $r['journal_entry_number']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Issued ' . $r['number'] . ' (' . number_format((float) $r['total'], 2) . ' ' . $r['currency'] . ')', $id, gl_land(return_path('/invoices/' . $id), 'issued'), 'invoiceChanged', ['invoice_id' => $id, 'number' => $r['number'], 'amount' => $r['total'], 'currency' => $r['currency']]);
