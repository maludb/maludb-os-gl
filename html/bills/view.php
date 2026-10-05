<?php
declare(strict_types=1);
/** /bills/{id} — one bill: head, the lines editor (a draft), the document, paid, the entry link, notes, the timeline, the actions by status (screen `bill-view`; documents.view). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
require_right('documents.view');
$pdo = db();
$id = req_id('id', 'bill') ?? refuse(404, 'Bill not found.');
$bill = find_bill($pdo, $id) ?? refuse(404, 'Bill not found.');
$attachments = find_attachments($pdo, 'bill', $id);
$notes = find_notes($pdo, 'bill', $id);
$timeline = find_record_activity($pdo, 'bill', $id);
$may = ['write' => has_right('ap.write'), 'approve' => has_right('ap.approve'), 'override' => has_right('journal.override'), 'attach' => has_right('attachments.write'), 'delete' => has_right('journal.override'), 'note' => has_right('documents.view')];
log_screen_view($pdo, 'bill-view', ['after' => ['record_type' => 'bill', 'record_id' => $id], 'vendor_id' => $bill['vendor_id']]);
if (wants_json()) {
    respond_screen(['bill' => present_bill($bill), 'attachments' => array_map('present_attachment', $attachments), 'notes' => array_map('present_note', $notes),
        'timeline' => array_map(static fn (array $r): array => ['activity_id' => (int) $r['activity_id'], 'at' => json_ts($r['occurred_at']), 'action' => $r['action'], 'sentence' => activity_sentence($r)], $timeline), 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved.'], 'asked' => ['success', 'The Bookkeeper has been asked.'], 'approved' => ['success', 'Approved ' . ($bill['number'] ?? '') . '.'], 'approved_no_document' => ['warning', 'Approved ' . ($bill['number'] ?? '') . ' — but no document is attached to it.'], 'voided' => ['warning', 'This bill is void; its entry was reversed.'],
            'line_saved' => ['success', 'Saved the line.'], 'line_removed' => ['success', 'Removed the line.'], 'attached' => ['success', 'Attached.'], 'noted' => ['success', 'Added the note.'], 'attachment_removed' => ['success', 'The attachment was removed.'], 'paid' => ['success', 'Recorded.']];
$data = ['bill' => $bill, 'attachments' => $attachments, 'notes' => $notes, 'timeline' => $timeline, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => gl_notice($_GET['notice'] ?? null, $notices)] + doc_lines_data($pdo, 'bill', $bill);
if (is_htmx_request() && !is_htmx_boosted() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'bill-lines') {
    answer_doc_region($pdo, 'bill', $bill);
}
render_screen($bill['number'] . ' — ' . $bill['vendor_name'], view('bills/view.php', $data), ['activeNav' => 'bills', 'screen' => 'bill-view', 'entity' => 'bill', 'recordId' => (string) $id]);
