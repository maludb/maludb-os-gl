<?php
declare(strict_types=1);

/**
 * Attachments on any record (slice 2): stored under storage/attachments/<record_type>/<record_id>/<id>-<sha256 prefix>, the mime from finfo
 * against an allow-list, at most ATTACHMENT_MAX_BYTES (default 5 MB). Reads through mcp_attachments. Requires records/views.php.
 */

const ATTACHMENT_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/csv',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/msword', 'application/vnd.ms-excel',
    'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet'];

function attachment_max_bytes(): int
{
    return (int) (env('ATTACHMENT_MAX_BYTES') ?: 5242880);
}

function attachment_root(): string
{
    return rtrim((string) (env('ATTACHMENTS_DIR') ?: dirname(__DIR__, 3) . '/storage/attachments'), '/');
}

/** The attachments of a record, newest first (the view decides who sees). */
function find_attachments(PDO $pdo, string $type, int $id): array
{
    $st = $pdo->prepare('SELECT attachment_id, record_type, record_id, filename, mime_type, byte_size, sha256, uploaded_by, uploaded_by_name, created_at FROM mcp_attachments WHERE record_type = :t AND record_id = :i ORDER BY attachment_id DESC');
    $st->execute(['t' => $type, 'i' => $id]);
    return array_map(static function (array $a): array { $a['attachment_id'] = (int) $a['attachment_id']; $a['record_id'] = (int) $a['record_id']; $a['byte_size'] = (int) $a['byte_size']; return $a; }, $st->fetchAll());
}

/**
 * Store an uploaded file ($_FILES['file']) on a record. Throws DomainException in words for a refusal (size, type, an upload that did not arrive).
 * Answers the attachment id. The caller holds the transaction.
 */
function store_attachment(PDO $pdo, string $type, int $id, array $file, int $by): int
{
    if (!in_array($type, ATTACHABLE_TYPES, true)) {
        throw new DomainException('A ' . record_words($type) . ' takes no attachment.');
    }
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        throw new DomainException('Choose a file to attach.');
    }
    $max = attachment_max_bytes();
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > $max) {
        throw new DomainException('The file is larger than ' . round($max / 1048576, 1) . ' MB.');
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new DomainException('The upload did not arrive whole; try again.');
    }
    $tmp = (string) $file['tmp_name'];
    $mime = (string) (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp) ?: 'application/octet-stream');
    if (!in_array($mime, ATTACHMENT_MIMES, true)) {
        throw new DomainException('That kind of file (' . $mime . ') is not accepted: images, PDF, text, CSV and office documents are.');
    }
    $name = preg_replace('/[^\w .()\-]+/u', '_', basename((string) ($file['name'] ?? 'file')));
    $name = $name === '' || $name === '.' ? 'file' : mb_substr($name, 0, 200);
    $sha = hash_file('sha256', $tmp);
    $size = (int) filesize($tmp);
    $st = $pdo->prepare('INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES (:t, :i, :n, :m, :s, :h, :p, :by) RETURNING id');
    $st->execute(['t' => $type, 'i' => $id, 'n' => $name, 'm' => $mime, 's' => $size, 'h' => $sha, 'p' => 'pending', 'by' => $by]);
    $aid = (int) $st->fetchColumn();
    $rel = $type . '/' . $id . '/' . $aid . '-' . substr($sha, 0, 12);
    $dir = attachment_root() . '/' . $type . '/' . $id;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('The attachments directory cannot be made: ' . $dir);
    }
    if (!move_uploaded_file($tmp, attachment_root() . '/' . $rel)) {
        throw new RuntimeException('The file could not be stored.');
    }
    $pdo->prepare('UPDATE attachments SET storage_path = :p WHERE id = :id')->execute(['p' => $rel, 'id' => $aid]);
    return $aid;
}

/** The attachment's row with its absolute path, when the caller sees the record it is on; else null. */
function attachment_path(PDO $pdo, int $attachmentId): ?array
{
    $st = $pdo->prepare('SELECT a.id, a.record_type, a.record_id, a.filename, a.mime_type, a.byte_size, a.storage_path FROM attachments a WHERE a.id = :id');
    $st->execute(['id' => $attachmentId]);
    $a = $st->fetch();
    if ($a === false || !record_visible($pdo, (string) $a['record_type'], (int) $a['record_id'])) {
        return null;
    }
    $a['path'] = attachment_root() . '/' . $a['storage_path'];
    $a['id'] = (int) $a['id'];
    $a['record_id'] = (int) $a['record_id'];
    return $a;
}

/** Remove the row and the file. */
function delete_attachment(PDO $pdo, int $attachmentId): void
{
    $st = $pdo->prepare('SELECT storage_path FROM attachments WHERE id = :id');
    $st->execute(['id' => $attachmentId]);
    $path = $st->fetchColumn();
    if ($path === false) {
        throw new DomainException('Not found.');
    }
    $pdo->prepare('DELETE FROM attachments WHERE id = :id')->execute(['id' => $attachmentId]);
    $abs = attachment_root() . '/' . $path;
    if ($path !== 'pending' && is_file($abs)) {
        @unlink($abs);
    }
}

function present_attachment(array $a): array
{
    return ['attachment_id' => $a['attachment_id'], 'record_type' => $a['record_type'], 'record_id' => $a['record_id'], 'filename' => $a['filename'], 'mime_type' => $a['mime_type'], 'byte_size' => $a['byte_size'],
            'url' => '/attachments/get.php?id=' . $a['attachment_id'], 'uploaded_by' => $a['uploaded_by'] === null ? null : ['member_id' => (int) $a['uploaded_by'], 'name' => $a['uploaded_by_name']], 'created_at' => json_ts($a['created_at'])];
}

function fmt_bytes(int $n): string
{
    return $n >= 1048576 ? round($n / 1048576, 1) . ' MB' : ($n >= 1024 ? round($n / 1024) . ' KB' : $n . ' B');
}
