<?php
declare(strict_types=1);
/** GET /attachments/get.php?id= — the file, when the caller sees the record it is on (record_visible()); nosniff; inline for images and PDFs, a download otherwise. Not an action. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/views.php';
require_once dirname(__DIR__, 2) . '/app/features/records/attachments.php';
require_once dirname(__DIR__, 2) . '/app/features/records/notes.php';
require_login();
$pdo = db();
$aid = request_integer('id') ?? request_integer('attachment') ?? refuse(404, 'Attachment not found.');
$a = attachment_path($pdo, $aid) ?? refuse(404, 'Attachment not found.');
if (!is_file($a['path'])) {
    refuse(404, 'The file is missing from storage.');
}
$inline = str_starts_with((string) $a['mime_type'], 'image/') || $a['mime_type'] === 'application/pdf';
header('Content-Type: ' . $a['mime_type']);
header('Content-Length: ' . (string) filesize($a['path']));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', (string) $a['filename']) . '"');
header('Cache-Control: private, max-age=0');
readfile($a['path']);
exit;
