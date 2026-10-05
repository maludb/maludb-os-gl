<?php
declare(strict_types=1);
/** /credit-notes/{id} — one credit note: lines (the editor for a draft), where it is applied, the actions by status (screen `credit-note-view`; documents.view). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('documents.view');
$pdo = db();
$id = req_id('id', 'credit_note') ?? refuse(404, 'Credit note not found.');
$cn = find_credit_note($pdo, $id) ?? refuse(404, 'Credit note not found.');
$attachments = find_attachments($pdo, 'credit_note', $id);
$notes = find_notes($pdo, 'credit_note', $id);
$timeline = find_record_activity($pdo, 'credit_note', $id);
$targets = $cn['status'] === 'issued' && (float) $cn['balance'] > 0 ? creditable_invoices($pdo, $cn['customer_id'], $cn['currency']) : [];
$may = ['write' => has_right('ar.write'), 'override' => has_right('journal.override'), 'attach' => has_right('attachments.write'), 'delete' => has_right('journal.override'), 'note' => has_right('documents.view')];
log_screen_view($pdo, 'credit-note-view', ['after' => ['record_type' => 'credit_note', 'record_id' => $id], 'customer_id' => $cn['customer_id']]);
if (wants_json()) {
    respond_screen(['credit_note' => ['credit_note_id' => $cn['credit_note_id'], 'number' => $cn['number'], 'customer' => ['customer_id' => $cn['customer_id'], 'name' => $cn['customer_name']], 'invoice' => $cn['invoice_id'] === null ? null : ['invoice_id' => $cn['invoice_id'], 'number' => $cn['invoice_number']],
        'issue_date' => $cn['issue_date'], 'currency' => $cn['currency'], 'status' => $cn['status'], 'subtotal' => $cn['subtotal'], 'tax_total' => $cn['tax_total'], 'total' => $cn['total'], 'amount_applied' => $cn['amount_applied'], 'balance' => $cn['balance'], 'reason' => $cn['reason'],
        'journal_entry' => $cn['journal_entry_id'] === null ? null : ['journal_entry_id' => $cn['journal_entry_id'], 'number' => $cn['journal_entry_number']], 'lines' => array_map('present_invoice_line', array_map(static fn (array $l): array => ['invoice_line_id' => $l['credit_note_line_id']] + $l + ['income_account_code' => $l['income_account_code']], $cn['lines'])),
        'applications' => array_map(static fn (array $a): array => ['invoice_id' => $a['invoice_id'], 'number' => $a['invoice_number'], 'amount' => $a['amount']], $cn['applications'])], 'attachments' => array_map('present_attachment', $attachments), 'notes' => array_map('present_note', $notes), 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved.'], 'issued' => ['success', 'Issued.'], 'applied' => ['success', 'Applied.'], 'unapplied' => ['success', 'Taken off the invoice.'], 'voided' => ['warning', 'This credit note is void; its entry was reversed.'], 'line_saved' => ['success', 'Saved the line.'],
            'line_removed' => ['success', 'Removed the line.'], 'attached' => ['success', 'Attached.'], 'noted' => ['success', 'Added the note.'], 'attachment_removed' => ['success', 'The attachment was removed.']];
$data = ['cn' => $cn, 'targets' => $targets, 'attachments' => $attachments, 'notes' => $notes, 'timeline' => $timeline, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => gl_notice($_GET['notice'] ?? null, $notices)] + doc_lines_data($pdo, 'credit_note', $cn);
if (is_htmx_request() && !is_htmx_boosted() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'credit-note-lines') {
    answer_doc_region($pdo, 'credit_note', $cn);
}
render_screen(($cn['number'] ?? 'Draft credit note') . ' — ' . $cn['customer_name'], view('credit_notes/view.php', $data), ['activeNav' => 'credit-notes', 'screen' => 'credit-note-view', 'entity' => 'creditNote', 'recordId' => (string) $id]);
