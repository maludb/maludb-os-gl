<?php
declare(strict_types=1);
/** /receipts/{id} — one receipt: its applications (unapply), its entry, its deposit, Void (screen `receipt-view`; documents.view). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('documents.view');
$pdo = db();
$id = req_id('id', 'receipt') ?? refuse(404, 'Receipt not found.');
$r = find_receipt($pdo, $id) ?? refuse(404, 'Receipt not found.');
$attachments = find_attachments($pdo, 'receipt', $id);
$notes = find_notes($pdo, 'receipt', $id);
$timeline = find_record_activity($pdo, 'receipt', $id);
$open = $r['status'] === 'recorded' ? array_values(array_filter(customer_open_invoices($pdo, $r['customer_id']), static fn (array $i): bool => $i['currency'] === $r['currency'])) : [];
$may = ['write' => has_right('ar.write'), 'override' => has_right('journal.override'), 'attach' => has_right('attachments.write'), 'delete' => has_right('journal.override'), 'note' => has_right('documents.view')];
log_screen_view($pdo, 'receipt-view', ['after' => ['record_type' => 'receipt', 'record_id' => $id], 'customer_id' => $r['customer_id']]);
if (wants_json()) {
    respond_screen(['receipt' => present_receipt($r), 'open_invoices' => array_map('present_invoice_row', $open), 'attachments' => array_map('present_attachment', $attachments), 'notes' => array_map('present_note', $notes),
        'timeline' => array_map(static fn (array $x): array => ['activity_id' => (int) $x['activity_id'], 'at' => json_ts($x['occurred_at']), 'action' => $x['action'], 'sentence' => activity_sentence($x)], $timeline), 'may' => $may]);
}
$notices = ['recorded' => ['success', 'Recorded.'], 'applied' => ['success', 'Applied.'], 'unapplied' => ['success', 'Taken off the invoice.'], 'voided' => ['warning', 'This receipt is void; its entry was reversed.'], 'attached' => ['success', 'Attached.'], 'noted' => ['success', 'Added the note.'], 'attachment_removed' => ['success', 'The attachment was removed.']];
render_screen($r['number'] . ' — ' . $r['customer_name'], view('receipts/view.php', ['r' => $r, 'open' => $open, 'attachments' => $attachments, 'notes' => $notes, 'timeline' => $timeline, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(),
    'notice' => gl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'receipts', 'screen' => 'receipt-view', 'entity' => 'receipt', 'recordId' => (string) $id]);
