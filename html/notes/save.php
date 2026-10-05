<?php
declare(strict_types=1);
/** Action `note_add` (record_type, record_id, body; log `note.add` with an excerpt): documents.view; the record must be visible through its view. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/views.php';
require_once dirname(__DIR__, 2) . '/app/features/records/attachments.php';
require_once dirname(__DIR__, 2) . '/app/features/records/notes.php';
gl_handler_begin();
require_right('documents.view');
$pdo = db();
$type = (string) req_val('record_type');
$rid = request_integer('record_id');
if (!record_known($type)) {
    refuse(422, 'No record of that kind: ' . ($type === '' ? '(none)' : $type) . '.');
}
if ($rid === null || !record_visible($pdo, $type, $rid)) {
    refuse(404, ucfirst(record_words($type)) . ' not found.');
}
$body = (string) req_val('body');
$keys = $type === 'journal_entry' ? ['journal_entry_id' => $rid] : ($type === 'customer' ? ['customer_id' => $rid] : ($type === 'vendor' ? ['vendor_id' => $rid] : []));
$nid = gl_guard($pdo, static function () use ($pdo, $type, $rid, $body, $keys): int {
    $pdo->beginTransaction();
    $nid = add_note($pdo, $type, $rid, $body, (int) current_member_id());
    log_activity($pdo, 'note.add', $type, $rid, $keys + ['after' => ['note_id' => $nid, 'record_type' => $type, 'record_id' => $rid, 'excerpt' => mb_substr(trim($body), 0, 120)]]);
    $pdo->commit();
    return $nid;
});
gl_done('Added a note', $nid, gl_land(return_path(record_url($type, $rid)), 'noted', 'note-' . $nid), lcfirst(str_replace('_', '', ucwords($type, '_'))) . 'Changed', ['note_id' => $nid]);
