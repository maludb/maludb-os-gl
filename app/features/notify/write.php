<?php
declare(strict_types=1);

/**
 * Notifications and the outbox (slice 8). `notify_member()` writes the in-app row (the bell) always, an email row in the outbox when the person's choices include the kind, and a text row when they chose texts for it;
 * `notify_customer()` queues one email for a customer. `outbox_send_batch()` is the worker's send step: email through MaluMail (app/mail.php), text through the kernel's K6 (POST /api/v1/notify/sms.php) — the application
 * never holds a Twilio key. A body is never logged; `notification.send` carries channel, kind and status only. The held secure-link row (kind invoice_link, status skipped) is never sent.
 */

require_once dirname(__DIR__) . '/notify/queries.php';
require_once dirname(__DIR__) . '/settings/queries.php';
require_once dirname(__DIR__, 2) . '/mail.php';

const OUTBOX_MAX_ATTEMPTS = 5;
const SMS_MAX_CHARS = 480;

/** Has this notice been written already? $dedupe 'a:b' once ever; one ending in a day ('claim:7:2026-10-05') once that day. */
function notice_written(PDO $pdo, int $memberId, string $kind, ?string $recordType, ?int $recordId, string $dedupe): bool
{
    $e = $pdo->prepare('SELECT 1 FROM notification_outbox WHERE dedupe_key = :k');
    $e->execute(['k' => $dedupe . '#email#' . $memberId]);
    if ($e->fetchColumn() !== false) {
        return true;
    }
    $day = preg_match('/:(\d{4}-\d{2}-\d{2})$/', $dedupe, $m) ? $m[1] : null;
    $st = $pdo->prepare('SELECT 1 FROM notifications WHERE member_id = :m AND kind = :k AND record_type IS NOT DISTINCT FROM :t AND record_id IS NOT DISTINCT FROM :i' . ($day === null ? '' : ' AND created_at::date = CAST(:d AS date)') . ' LIMIT 1');
    $args = ['m' => $memberId, 'k' => $kind, 't' => $recordType, 'i' => $recordId] + ($day === null ? [] : ['d' => $day]);
    $st->execute($args);
    return $st->fetchColumn() !== false;
}

/**
 * Tell one member: the in-app row always; an email row when their choices include the kind (email enabled); a text row when texts are on for it. Answers false when the dedupe key says it was told already.
 * The title is the subject, the body the text (plain, up to 4,000 characters).
 */
function notify_member(PDO $pdo, int $memberId, string $kind, string $title, ?string $body, ?string $recordType, ?int $recordId, ?string $dedupe = null): bool
{
    if ($dedupe !== null && notice_written($pdo, $memberId, $kind, $recordType, $recordId, $dedupe)) {
        return false;
    }
    $body = $body === null ? null : mb_substr($body, 0, 4000);
    $pdo->prepare('INSERT INTO notifications (member_id, kind, record_type, record_id, title, body) VALUES (:m, :k, :t, :i, :ti, :b)')->execute(['m' => $memberId, 'k' => $kind, 't' => $recordType, 'i' => $recordId, 'ti' => mb_substr($title, 0, 300), 'b' => $body]);
    $prefs = find_prefs($pdo, $memberId);
    $text = $body === null || $body === '' ? $title : $title . ' — ' . $body;
    $ins = $pdo->prepare("INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, subject, body, status) VALUES (:c, :m, :k, :t, :i, :d, :s, :b, 'queued') ON CONFLICT DO NOTHING");
    if ($prefs['email_enabled'] && in_array($kind, $prefs['kinds'], true)) {
        $ins->execute(['c' => 'email', 'm' => $memberId, 'k' => $kind, 't' => $recordType, 'i' => $recordId, 'd' => $dedupe === null ? null : $dedupe . '#email#' . $memberId, 's' => mb_substr($title, 0, 300), 'b' => $body === null || $body === '' ? $title : $body]);
    }
    if ($prefs['text_enabled'] && in_array($kind, $prefs['text_kinds'], true)) {
        $ins->execute(['c' => 'text', 'm' => $memberId, 'k' => $kind, 't' => $recordType, 'i' => $recordId, 'd' => $dedupe === null ? null : $dedupe . '#text#' . $memberId, 's' => null, 'b' => mb_substr($text, 0, SMS_MAX_CHARS)]);
    }
    return true;
}

/** Tell every active person who holds a right (their choices decide the email and the text; the bell row is for all). Answers how many were told. */
function notify_members_with_right(string $right, string $kind, string $recordType, int $recordId, string $title, ?string $body, ?string $dedupe = null): int
{
    $pdo = db();
    $n = 0;
    foreach (members_with_right($pdo, $right) as $m) {
        if (notify_member($pdo, $m, $kind, $title, $body, $recordType, $recordId, $dedupe)) {
            $n++;
        }
    }
    return $n;
}

/** Queue one email for a customer (the address at send time; text and HTML bodies). Answers the outbox id, or null when the dedupe key says it is queued already. */
function notify_customer(PDO $pdo, int $customerId, string $kind, string $toEmail, string $subject, string $body, ?string $html, ?string $dedupe = null, ?string $recordType = null, ?int $recordId = null): ?int
{
    $st = $pdo->prepare("INSERT INTO notification_outbox (channel, customer_id, to_email, kind, record_type, record_id, dedupe_key, subject, body, body_html, status) VALUES ('email', :c, :to, :k, :t, :i, :d, :s, :b, :h, 'queued') ON CONFLICT DO NOTHING RETURNING id");
    $st->execute(['c' => $customerId, 'to' => $toEmail, 'k' => $kind, 't' => $recordType, 'i' => $recordId, 'd' => $dedupe, 's' => $subject, 'b' => $body, 'h' => $html]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int) $id;
}

/** The address an email row goes to: a customer's `to_email`, a member's mirror email. Null when there is none. */
function outbox_address(PDO $pdo, array $row): ?string
{
    if ($row['customer_id'] !== null) {
        return $row['to_email'] === null || $row['to_email'] === '' ? null : (string) $row['to_email'];
    }
    $st = $pdo->prepare('SELECT email::text FROM members WHERE id = :m');
    $st->execute(['m' => $row['member_id']]);
    $e = $st->fetchColumn();
    return $e === false || $e === null || $e === '' ? null : (string) $e;
}

/** Send one email row through MaluMail. Answers ['status' => sent | failed | retry, 'detail' => ?string, 'provider_ref' => ?string]: a transport error or a 5xx retries; a rejected recipient fails at once. */
function send_email_row(PDO $pdo, array $row): array
{
    $to = outbox_address($pdo, $row);
    if ($to === null) {
        return ['status' => 'failed', 'detail' => 'There is no address to send to.', 'provider_ref' => null];
    }
    try {
        $r = malumail_send(['from' => (string) env('MAIL_FROM', 'noreply@example.com'), 'from_name' => (string) env('MAIL_FROM_NAME', env('APP_NAME', 'General Ledger')), 'to' => $to, 'subject' => (string) ($row['subject'] ?? ''),
                            'text' => (string) $row['body'], 'html' => $row['body_html'] ?? ('<pre>' . htmlspecialchars((string) $row['body'], ENT_QUOTES) . '</pre>')]);
    } catch (Throwable $e) {
        return ['status' => 'retry', 'detail' => $e->getMessage(), 'provider_ref' => null];
    }
    if ($r['status'] === 200 && ($r['body']['accepted'] ?? []) !== []) {
        return ['status' => 'sent', 'detail' => null, 'provider_ref' => $r['message_id']];
    }
    if ($r['status'] >= 500) {
        return ['status' => 'retry', 'detail' => 'MaluMail answered HTTP ' . $r['status'] . ': ' . (string) ($r['body']['error'] ?? 'no reason'), 'provider_ref' => null];
    }
    $why = (string) ($r['body']['rejected'][0]['reason'] ?? $r['body']['error'] ?? 'the recipient was refused');
    return ['status' => 'failed', 'detail' => 'MaluMail refused it: ' . $why, 'provider_ref' => null];
}

/** Send one text row through the kernel's K6. Answers ['status' => sent | skipped | retry, 'detail', 'provider_ref']: every refusal the kernel names is a skip with its code (the email row stands); a 500 or no answer retries. */
function send_text_row(array $row): array
{
    $answer = kernel_call('POST', '/api/v1/notify/sms.php', ['member_id' => (int) $row['member_id'], 'text' => mb_substr((string) $row['body'], 0, SMS_MAX_CHARS), 'reference' => 'gl-outbox-' . $row['id']]);
    if ($answer === null) {
        return ['status' => 'retry', 'detail' => 'The OS did not answer the text.', 'provider_ref' => null];
    }
    if ($answer['status'] === 202 || $answer['status'] === 200) {
        return ['status' => 'sent', 'detail' => null, 'provider_ref' => isset($answer['body']['notification']['id']) ? (string) $answer['body']['notification']['id'] : null];
    }
    if ($answer['status'] >= 500 && $answer['status'] !== 503) {
        return ['status' => 'retry', 'detail' => 'The OS answered the text with HTTP ' . $answer['status'] . '.', 'provider_ref' => null];
    }
    $code = (string) ($answer['body']['error']['code'] ?? 'refused');
    return ['status' => 'skipped', 'detail' => $code, 'provider_ref' => null];
}

/**
 * The send step: queued rows whose send_after passed, oldest first, one at a time (each locked while it is sent). A retry bumps attempts and waits a minute per attempt; the fifth failed attempt leaves it failed with the sentence.
 * The held link row is never sent. Logs notification.send per row (channel, kind, status — never the body or the address). Answers counts.
 */
function outbox_send_batch(PDO $pdo, int $limit = 200, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable();
    $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'retried' => 0];
    $ids = $pdo->prepare("SELECT id FROM notification_outbox WHERE status = 'queued' AND kind <> 'invoice_link' AND send_after <= :now ORDER BY id LIMIT " . (int) $limit);
    $ids->execute(['now' => $now->format('Y-m-d H:i:s.uP')]);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT * FROM notification_outbox WHERE id = :id AND status = 'queued' FOR UPDATE SKIP LOCKED");
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        if ($row === false) {
            $pdo->rollBack();
            continue;
        }
        $r = $row['channel'] === 'text' ? send_text_row($row) : send_email_row($pdo, $row);
        $attempts = (int) $row['attempts'] + 1;
        $status = $r['status'];
        if ($status === 'retry') {
            $status = $attempts >= OUTBOX_MAX_ATTEMPTS ? 'failed' : 'queued';
        }
        $sendAfter = $status === 'queued' ? $now->modify('+' . $attempts . ' minutes')->format('Y-m-d H:i:s.uP') : $row['send_after'];
        $pdo->prepare('UPDATE notification_outbox SET status = :s, attempts = :a, detail = :d, provider_ref = :p, send_after = :sa, sent_at = CASE WHEN :s2 = \'sent\' THEN CAST(:now AS timestamptz) ELSE sent_at END WHERE id = :id')
            ->execute(['s' => $status, 'a' => $attempts, 'd' => $r['detail'], 'p' => $r['provider_ref'], 'sa' => $sendAfter, 's2' => $status, 'now' => $now->format('Y-m-d H:i:s.uP'), 'id' => $id]);
        if ($status !== 'queued') {
            log_activity($pdo, 'notification.send', 'notification', (int) $id, ['actor_member_id' => null, 'source' => 'cron', 'customer_id' => $row['customer_id'] === null ? null : (int) $row['customer_id'], 'after' => ['channel' => $row['channel'], 'kind' => $row['kind'], 'status' => $status, 'attempts' => $attempts, 'detail' => $r['detail']]]);
        }
        $pdo->commit();
        $out[$status === 'queued' ? 'retried' : $status]++;
    }
    return $out;
}
