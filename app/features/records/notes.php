<?php
declare(strict_types=1);

/** Notes on any record (slice 2): plain text, newest first, through mcp_notes. Requires records/views.php. */

function find_notes(PDO $pdo, string $type, int $id): array
{
    $st = $pdo->prepare('SELECT note_id, record_type, record_id, member_id, member_name, member_kind, body, created_at FROM mcp_notes WHERE record_type = :t AND record_id = :i ORDER BY note_id DESC');
    $st->execute(['t' => $type, 'i' => $id]);
    return array_map(static function (array $n): array { $n['note_id'] = (int) $n['note_id']; $n['record_id'] = (int) $n['record_id']; $n['member_id'] = $n['member_id'] === null ? null : (int) $n['member_id']; return $n; }, $st->fetchAll());
}

function add_note(PDO $pdo, string $type, int $id, string $body, int $by): int
{
    $body = trim($body);
    if ($body === '') {
        throw new DomainException('Write the note first.');
    }
    if (mb_strlen($body) > 4000) {
        throw new DomainException('A note is up to 4,000 characters.');
    }
    $st = $pdo->prepare('INSERT INTO notes (record_type, record_id, member_id, body) VALUES (:t, :i, :m, :b) RETURNING id');
    $st->execute(['t' => $type, 'i' => $id, 'm' => $by, 'b' => $body]);
    return (int) $st->fetchColumn();
}

function present_note(array $n): array
{
    return ['note_id' => $n['note_id'], 'record_type' => $n['record_type'], 'record_id' => $n['record_id'], 'body' => $n['body'],
            'by' => $n['member_id'] === null ? null : ['member_id' => $n['member_id'], 'name' => $n['member_name'], 'kind' => $n['member_kind']], 'created_at' => json_ts($n['created_at'])];
}
