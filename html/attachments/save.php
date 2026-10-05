<?php
declare(strict_types=1);
/** Action `attachment_add` (record_type, record_id, file as multipart; log `attachment.add` with filename, byte_size, mime_type and the record keys): attachments.write; the record must be visible through its view. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/views.php';
require_once dirname(__DIR__, 2) . '/app/features/records/attachments.php';
require_once dirname(__DIR__, 2) . '/app/features/records/notes.php';
gl_handler_begin();
require_right('attachments.write');
$pdo = db();
$type = (string) req_val('record_type');
$rid = request_integer('record_id');
if (!record_known($type) || !in_array($type, ATTACHABLE_TYPES, true)) {
    refuse(422, 'No record of that kind takes an attachment: ' . ($type === '' ? '(none)' : $type) . '.');
}
if ($rid === null || !record_visible($pdo, $type, $rid)) {
    refuse(404, ucfirst(record_words($type)) . ' not found.');
}
$keys = $type === 'journal_entry' ? ['journal_entry_id' => $rid] : ($type === 'customer' ? ['customer_id' => $rid] : ($type === 'vendor' ? ['vendor_id' => $rid] : []));
try {
    $pdo->beginTransaction();
    $aid = store_attachment($pdo, $type, $rid, $_FILES['file'] ?? [], (int) current_member_id());
    $a = $pdo->query('SELECT filename, byte_size, mime_type FROM attachments WHERE id = ' . $aid)->fetch();
    log_activity($pdo, 'attachment.add', $type, $rid, $keys + ['after' => ['attachment_id' => $aid, 'record_type' => $type, 'record_id' => $rid, 'filename' => $a['filename'], 'byte_size' => (int) $a['byte_size'], 'mime_type' => $a['mime_type']]]);
    $pdo->commit();
} catch (DomainException $ex) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    gl_refuse_fields(['file' => $ex->getMessage()]);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('attachment failed: ' . $ex->getMessage());
    refuse(500, 'The file could not be stored.');
}
gl_done('Attached ' . $a['filename'], $aid, gl_land(return_path(record_url($type, $rid)), 'attached', 'attachment-chip-' . $aid), lcfirst(str_replace('_', '', ucwords($type, '_'))) . 'Changed', ['attachment_id' => $aid, 'url' => '/attachments/get.php?id=' . $aid]);
