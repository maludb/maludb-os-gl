<?php
declare(strict_types=1);
/** /customers/{id} — one customer: facts, contacts, open invoices, receipts, credits, claims, attachments, notes, the timeline (screen `customer-view`; parties.view; the address and notes need ar.write). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('parties.view');
$pdo = db();
$id = req_id('id', 'customer') ?? refuse(404, 'Customer not found.');
$c = find_customer($pdo, $id) ?? refuse(404, 'Customer not found.');
$open = has_right('documents.view') ? customer_open_invoices($pdo, $id) : [];
$receipts = has_right('documents.view') ? find_receipts($pdo, ['customer' => $id], 10) : [];
$credits = has_right('documents.view') ? find_credit_notes($pdo, ['customer' => $id, 'status' => 'issued'], 10) : [];
$claims = has_right('documents.view') ? find_claims($pdo, 'submitted', $id) : [];
$attachments = has_right('documents.view') ? find_attachments($pdo, 'customer', $id) : [];
$notes = has_right('documents.view') ? find_notes($pdo, 'customer', $id) : [];
$timeline = find_record_activity($pdo, 'customer', $id);
$may = ['write' => has_right('ar.write'), 'override' => has_right('journal.override'), 'attach' => has_right('attachments.write'), 'delete' => has_right('journal.override'), 'note' => has_right('documents.view')];
log_screen_view($pdo, 'customer-view', ['after' => ['record_type' => 'customer', 'record_id' => $id], 'customer_id' => $id]);
if (wants_json()) {
    respond_screen(['customer' => present_customer($c), 'open_invoices' => array_map('present_invoice_row', $open), 'receipts' => array_map('present_receipt_row', $receipts), 'credits' => $credits, 'claims' => $claims,
        'attachments' => array_map('present_attachment', $attachments), 'notes' => array_map('present_note', $notes), 'timeline' => array_map(static fn (array $r): array => ['activity_id' => (int) $r['activity_id'], 'at' => json_ts($r['occurred_at']), 'action' => $r['action'], 'sentence' => activity_sentence($r)], $timeline), 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved.'], 'restored' => ['success', 'The customer was restored.'], 'attached' => ['success', 'Attached.'], 'noted' => ['success', 'Added the note.'], 'attachment_removed' => ['success', 'The attachment was removed.']];
render_screen($c['name'], view('customers/view.php', ['c' => $c, 'open' => $open, 'receipts' => $receipts, 'credits' => $credits, 'claims' => $claims, 'attachments' => $attachments, 'notes' => $notes, 'timeline' => $timeline, 'may' => $may,
    'here' => here_url(), 'tz' => member_timezone(), 'notice' => gl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'customers', 'screen' => 'customer-view', 'entity' => 'customer', 'recordId' => (string) $id]);
