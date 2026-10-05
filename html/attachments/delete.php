<?php
declare(strict_types=1);
/** Action `attachment_delete` (attachment; confirm; deletion for an agent; log `attachment.delete`): journal.override; the record must be visible. Removes the row and the file. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/views.php';
require_once dirname(__DIR__, 2) . '/app/features/records/attachments.php';
require_once dirname(__DIR__, 2) . '/app/features/records/notes.php';
gl_handler_begin();
require_right('journal.override');
$pdo = db();
$aid = request_integer('attachment') ?? request_integer('attachment_id') ?? refuse(422, 'Say which attachment.');
$a = attachment_path($pdo, $aid) ?? refuse(404, 'Attachment not found.');
$type = (string) $a['record_type'];
$rid = (int) $a['record_id'];
$keys = $type === 'journal_entry' ? ['journal_entry_id' => $rid] : ($type === 'customer' ? ['customer_id' => $rid] : ($type === 'vendor' ? ['vendor_id' => $rid] : []));
$gone = gl_guard($pdo, static function () use ($pdo, $aid, $a, $type, $rid, $keys): array {
    $pdo->beginTransaction();
    delete_attachment($pdo, $aid);
    log_activity($pdo, 'attachment.delete', $type, $rid, $keys + ['after' => ['attachment_id' => $aid, 'record_type' => $type, 'record_id' => $rid, 'filename' => $a['filename'], 'byte_size' => (int) $a['byte_size'], 'mime_type' => $a['mime_type']]]);
    $pdo->commit();
    return $a;
});
gl_done('Removed ' . $gone['filename'], $aid, gl_land(return_path(record_url($type, $rid)), 'attachment_removed'), lcfirst(str_replace('_', '', ucwords($type, '_'))) . 'Changed');
