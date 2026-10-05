<?php
declare(strict_types=1);
/** /cash/transfers/{id} — one transfer: its entry, the bank lines it matched, void (screen `transfer-view`; documents.view). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/cash/handler.php';
require_right('documents.view');
$pdo = db();
$id = req_id('id', 'transfer') ?? refuse(404, 'Transfer not found.');
$t = find_transfer($pdo, $id) ?? refuse(404, 'Transfer not found.');
$attachments = find_attachments($pdo, 'transfer', $id);
$notes = find_notes($pdo, 'transfer', $id);
$timeline = find_record_activity($pdo, 'transfer', $id);
$may = ['write' => has_right('bank.write'), 'override' => has_right('journal.override'), 'attach' => has_right('attachments.write'), 'delete' => false, 'note' => has_right('documents.view')];
log_screen_view($pdo, 'transfer-view', ['after' => ['record_type' => 'transfer', 'record_id' => $id], 'bank_account_id' => $t['from_bank_account_id']]);
if (wants_json()) {
    respond_screen(['transfer' => present_transfer($t), 'attachments' => array_map('present_attachment', $attachments), 'notes' => array_map('present_note', $notes), 'may' => $may]);
}
render_screen($t['number'], view('cash/transfer.php', ['t' => $t, 'attachments' => $attachments, 'notes' => $notes, 'timeline' => $timeline, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(),
    'notice' => gl_notice($_GET['notice'] ?? null, ['recorded' => ['success', 'Recorded. It will match a line on each account.'], 'voided' => ['warning', 'This transfer is void; its entry was reversed.'], 'attached' => ['success', 'Attached.'], 'noted' => ['success', 'Added the note.'], 'attachment_removed' => ['success', 'The attachment was removed.']])]),
    ['activeNav' => 'transfers', 'screen' => 'transfer-view', 'entity' => 'transfer', 'recordId' => (string) $id]);
