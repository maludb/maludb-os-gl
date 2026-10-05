<?php
declare(strict_types=1);
/** Action `credit_note_issue` (a draft; apply_to an invoice; confirm; money_out for an agent; log `credit_note.issue` with number, amount, currency, journal_entry_number): ar.write. gl_credit_note_issue() numbers and posts it (DR revenue / DR tax / CR AR). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('credit_note', 'credit_note_id') ?? refuse(422, 'Say which credit note.');
require_visible($pdo, 'mcp_credit_notes', 'credit_note_id', $id, 'Credit note');
$applyTo = req_id('apply_to');
if ($applyTo !== null) {
    require_visible($pdo, 'mcp_invoices', 'invoice_id', $applyTo, 'Invoice');
}
try {
    $pdo->beginTransaction();
    $r = issue_credit_note($pdo, $id, (int) current_member_id(), $applyTo);
    log_activity($pdo, 'credit_note.issue', 'credit_note', $id, audit_keys($r['customer_id'], $r['journal_entry_id']) + ['after' => ['number' => $r['number'], 'amount' => $r['total'], 'currency' => $r['currency'], 'journal_entry_number' => $r['journal_entry_number'], 'applied_to' => $applyTo]]);
    if ($r['applied'] !== null) {
        log_activity($pdo, 'credit_note.apply', 'credit_note', $id, audit_keys($r['customer_id']) + ['after' => ['number' => $r['number'], 'invoice_id' => $applyTo, 'invoice_number' => $r['applied']['invoice_number'], 'amount' => $r['applied']['amount'], 'currency' => $r['currency']]]);
    }
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Issued ' . $r['number'] . ' (' . number_format((float) $r['total'], 2) . ' ' . $r['currency'] . ')' . ($r['applied'] !== null ? ', applied to ' . $r['applied']['invoice_number'] : ''), $id, gl_land(return_path('/credit-notes/' . $id), 'issued'), 'creditNoteChanged', ['credit_note_id' => $id, 'number' => $r['number']]);
