<?php
declare(strict_types=1);

/** Who is told, and what is waiting to be sent (slice 8). The members come from the mirror; a person's choices from notification_prefs (settings/queries.php). */

/** The ids of the active people (not agents) holding a right, by the role they hold in this ledger: [member_id, …]. */
function members_with_right(PDO $pdo, string $right): array
{
    $st = $pdo->prepare("SELECT m.id FROM members m
        WHERE m.status = 'active' AND m.capability IS NOT NULL AND m.member_kind = 'human'
          AND EXISTS (SELECT 1 FROM gl_role_rights rr WHERE rr.right_key = :r AND (rr.role_key = ANY (m.roles) OR (cardinality(m.roles) = 0 AND rr.role_key = CASE m.capability WHEN 'admin' THEN 'admin' ELSE 'user' END) OR m.business_role = 'super_admin'))
        ORDER BY m.id");
    $st->execute(['r' => $right]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** What the outbox holds by status (for the worker's report and the proofs). */
function outbox_counts(PDO $pdo): array
{
    $out = ['queued' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
    foreach ($pdo->query('SELECT status, count(*) AS n FROM notification_outbox GROUP BY status')->fetchAll() as $r) {
        $out[$r['status']] = (int) $r['n'];
    }
    return $out;
}
