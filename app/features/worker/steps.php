<?php
declare(strict_types=1);

/**
 * The worker's eight steps (slice 8). `worker_pass()` runs the named ones under the caller's advisory lock, each in its own try (one failing never stops the next), and answers the report; bin/worker.php logs it once.
 * $now is a DateTimeImmutable: GL_WORKER_NOW fakes it outside production (the database's own now() is the database's).
 */

const WORKER_STEPS = ['send', 'ai', 'recurring', 'reminders', 'links', 'claims', 'dispatch', 'housekeeping'];

function worker_step_send(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return outbox_send_batch($pdo, $limit, $now);
}

/** Once an hour (the newest worker.pass that ran ai is an hour old), when the poll is on. */
function worker_step_ai(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $last = $pdo->query("SELECT max(occurred_at) FROM activity_log WHERE action = 'worker.pass' AND jsonb_exists(after->'ran', 'ai')")->fetchColumn();
    if ($last !== false && $last !== null && strtotime((string) $last) > $now->getTimestamp() - 3600 && $now->getTimestamp() >= strtotime((string) $last)) {
        return ['skipped' => 'ran within the hour'];
    }
    $r = ai_poll_pass($pdo);
    return $r['skipped'] ? ['skipped' => 'the poll is off'] : ['pulled' => count($r['pulled']), 'refused' => count($r['refused']), 'error' => $r['error']];
}

function worker_step_recurring(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $out = ['drafted' => 0, 'failed' => 0];
    foreach (array_slice(templates_due($pdo, $now->format('Y-m-d')), 0, $limit) as $t) {
        $r = run_template($pdo, (int) $t['id'], null, 0, 'cron');
        $out[$r['status']]++;
    }
    return $out;
}

/** Invoices past due by exactly one of reminder_days, not reminded in 7 days: remind_invoice() as the business's own clock (D7: only an agent's reminder pauses). */
function worker_step_reminders(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $days = $pdo->query('SELECT reminder_days FROM gl_settings WHERE id = 1')->fetchColumn();
    $days = array_values(array_filter(array_map('intval', explode(',', trim((string) $days, '{}'))), static fn (int $d): bool => $d > 0));
    $out = ['reminded' => 0, 'skipped' => 0];
    if ($days === []) {
        return $out;
    }
    $st = $pdo->prepare("SELECT i.id, i.number, i.customer_id FROM invoices i
        WHERE i.status IN ('issued', 'sent', 'viewed', 'partially_paid') AND i.balance_due > 0 AND (CAST(:today AS date) - i.due_date) = ANY (CAST(:days AS int[]))
          AND (i.last_reminder_at IS NULL OR i.last_reminder_at < CAST(:now AS timestamptz) - interval '7 days') ORDER BY i.id LIMIT " . (int) $limit);
    $st->execute(['today' => $now->format('Y-m-d'), 'days' => '{' . implode(',', $days) . '}', 'now' => $now->format('Y-m-d H:i:s.uP')]);
    foreach ($st->fetchAll() as $inv) {
        try {
            $pdo->beginTransaction();
            $r = remind_invoice($pdo, (int) $inv['id'], null, 0);
            log_activity($pdo, 'invoice.remind_due', 'invoice', (int) $inv['id'], ['source' => 'cron', 'actor_member_id' => null, 'customer_id' => (int) $inv['customer_id'], 'after' => ['step' => $r['step'], 'days_overdue' => $r['days_overdue'], 'outbox_id' => $r['outbox_id']]]);
            if ((int) $r['reminder_count'] === 1) {
                notify_members_with_right('ar.write', 'invoice_overdue', 'invoice', (int) $inv['id'], 'Invoice ' . $inv['number'] . ' is past due', 'A reminder went to the customer today.', 'overdue:' . $inv['id']);
            }
            $pdo->commit();
            $out['reminded']++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $out['skipped']++;
        }
    }
    return $out;
}

/** An invoice's link carries the settings' life from its minting (slice 4); once the invoice is paid, written off or void its live link is set to die link_lifetime_days after that (db/010's rule) — a no-op when it already does. A link passing its expiry is logged once (invoice.link_expired). */
function worker_step_links(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $set = $pdo->prepare("UPDATE invoice_links_secure l SET expires_at = COALESCE(i.paid_at, i.written_off_at, i.voided_at) + make_interval(days => (SELECT link_lifetime_days FROM gl_settings WHERE id = 1))
        FROM invoices i WHERE i.id = l.invoice_id AND l.rotated_at IS NULL AND i.status IN ('paid', 'written_off', 'void') AND COALESCE(i.paid_at, i.written_off_at, i.voided_at) IS NOT NULL
          AND l.expires_at IS DISTINCT FROM COALESCE(i.paid_at, i.written_off_at, i.voided_at) + make_interval(days => (SELECT link_lifetime_days FROM gl_settings WHERE id = 1))");
    $set->execute();
    $out = ['expiry_set' => $set->rowCount(), 'expired' => 0];
    $due = $pdo->prepare("SELECT l.id, l.invoice_id, i.customer_id FROM invoice_links_secure l JOIN invoices i ON i.id = l.invoice_id
        WHERE l.expires_at <= CAST(:now AS timestamptz) AND NOT EXISTS (SELECT 1 FROM activity_log a WHERE a.action = 'invoice.link_expired' AND a.entity_type = 'invoice_link' AND a.entity_id = l.id) ORDER BY l.id LIMIT " . (int) $limit);
    $due->execute(['now' => $now->format('Y-m-d H:i:s.uP')]);
    foreach ($due->fetchAll() as $l) {
        log_activity($pdo, 'invoice.link_expired', 'invoice_link', (int) $l['id'], ['source' => 'cron', 'actor_member_id' => null, 'customer_id' => (int) $l['customer_id'], 'after' => ['invoice_id' => (int) $l['invoice_id']]]);
        $out['expired']++;
    }
    return $out;
}

/** A customer's claim submitted and untouched for 3 days: the holders of ar.write are told again (once a day per claim). */
function worker_step_claims(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $st = $pdo->prepare("SELECT c.id, c.invoice_id, i.number FROM receipt_claims c JOIN invoices i ON i.id = c.invoice_id
        WHERE c.status = 'submitted' AND c.created_at <= CAST(:now AS timestamptz) - interval '3 days' ORDER BY c.id LIMIT " . (int) $limit);
    $st->execute(['now' => $now->format('Y-m-d H:i:s.uP')]);
    $out = ['told' => 0];
    foreach ($st->fetchAll() as $c) {
        $out['told'] += notify_members_with_right('ar.write', 'claim_submitted', 'invoice', (int) $c['invoice_id'], 'A payment claim on invoice ' . $c['number'] . ' is still waiting', 'A customer said they paid and no one has answered in three days.', 'claim:' . $c['id'] . ':' . $now->format('Y-m-d'));
    }
    return $out;
}

function worker_step_dispatch(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return retry_failed_dispatches($pdo, min($limit, 20));
}

function worker_step_housekeeping(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $n = static fn (string $sql): int => (int) $pdo->exec($sql);
    $iso = $now->format('Y-m-d H:i:s.uP');
    return ['sso_nonces' => $n("DELETE FROM sso_nonces WHERE expires_at < CAST('$iso' AS timestamptz)"),
            'member_sessions' => $n("DELETE FROM member_sessions WHERE ended_at IS NOT NULL AND ended_at < CAST('$iso' AS timestamptz) - interval '30 days'"),
            'outbox_sent' => $n("DELETE FROM notification_outbox WHERE status = 'sent' AND sent_at < CAST('$iso' AS timestamptz) - interval '90 days'")];
}

/** Run the named steps (default all) and answer ['ran' => [...], 'results' => [step => counts], 'errors' => [step => sentence]]. */
function worker_pass(PDO $pdo, array $only, int $limit, DateTimeImmutable $now): array
{
    $steps = $only === [] ? WORKER_STEPS : array_values(array_intersect(WORKER_STEPS, $only));
    $report = ['ran' => $steps, 'results' => [], 'errors' => []];
    foreach ($steps as $s) {
        try {
            $report['results'][$s] = ('worker_step_' . $s)($pdo, $limit, $now);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $report['errors'][$s] = mb_substr($e->getMessage(), 0, 300);
        }
    }
    return $report;
}
