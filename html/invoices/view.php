<?php
declare(strict_types=1);
/** /invoices/{id} — one invoice: head, the lines editor (a draft), received, claims, the link, reminders, attachments, notes, the timeline, the actions by status (screen `invoice-view`; documents.view). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('documents.view');
$pdo = db();
$id = req_id('id', 'invoice') ?? refuse(404, 'Invoice not found.');
$inv = find_invoice($pdo, $id) ?? refuse(404, 'Invoice not found.');
$attachments = find_attachments($pdo, 'invoice', $id);
$notes = find_notes($pdo, 'invoice', $id);
$timeline = find_record_activity($pdo, 'invoice', $id);
$may = ['write' => has_right('ar.write'), 'override' => has_right('journal.override'), 'attach' => has_right('attachments.write'), 'delete' => has_right('journal.override'), 'note' => has_right('documents.view')];
log_screen_view($pdo, 'invoice-view', ['after' => ['record_type' => 'invoice', 'record_id' => $id], 'customer_id' => $inv['customer_id']]);
if (wants_json()) {
    respond_screen(['invoice' => present_invoice($inv), 'attachments' => array_map('present_attachment', $attachments), 'notes' => array_map('present_note', $notes),
        'timeline' => array_map(static fn (array $r): array => ['activity_id' => (int) $r['activity_id'], 'at' => json_ts($r['occurred_at']), 'action' => $r['action'], 'sentence' => activity_sentence($r)], $timeline), 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved.'], 'issued' => ['success', 'Issued ' . ($inv['number'] ?? '') . '. The link is ready; Send mails it.'], 'sent' => ['success', 'Queued for the customer.'], 'reminded' => ['success', 'The reminder is queued.'],
            'voided' => ['warning', 'This invoice is void; its entry was reversed.'], 'written_off' => ['warning', 'Written off.'], 'link_rotated' => ['success', 'The new link is mailed; the old one is dead.'], 'line_saved' => ['success', 'Saved the line.'],
            'line_removed' => ['success', 'Removed the line.'], 'attached' => ['success', 'Attached.'], 'noted' => ['success', 'Added the note.'], 'attachment_removed' => ['success', 'The attachment was removed.'], 'received' => ['success', 'Recorded.']];
$data = ['inv' => $inv, 'attachments' => $attachments, 'notes' => $notes, 'timeline' => $timeline, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => gl_notice($_GET['notice'] ?? null, $notices)] + doc_lines_data($pdo, 'invoice', $inv);
if (is_htmx_request() && !is_htmx_boosted() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'invoice-lines') {
    answer_doc_region($pdo, 'invoice', $inv);
}
render_screen(($inv['number'] ?? 'Draft invoice') . ' — ' . $inv['customer_name'], view('invoices/view.php', $data), ['activeNav' => 'invoices', 'screen' => 'invoice-view', 'entity' => 'invoice', 'recordId' => (string) $id]);
