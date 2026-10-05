<?php
declare(strict_types=1);

/**
 * The AI statement poll (slice 7; slice 8's worker calls ai_poll_pass() once an hour as its step `ai`): when gl_settings.ai_statement_poll, list the OS's months and pull what the ledger lacks, whose status moved,
 * or that is still open. Advisory-locked; every pull is logged `ai_statement.pull` with source cron; a changed imported month is refused and logged, and the pass goes on.
 *   php bin/ai_poll.php
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/ai/kernel.php';
require_once dirname(__DIR__) . '/app/features/ai/queries.php';
require_once dirname(__DIR__) . '/app/features/ai/write.php';

$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('gl_ai_poll'))")->fetchColumn()) {
    echo "another poll holds the lock — skipping.\n";
    exit(0);
}
$r = ai_poll_pass($pdo);
if ($r['skipped']) {
    echo "the poll is off (settings: ai_statement_poll).\n";
    exit(0);
}
if ($r['error'] !== null) {
    fwrite(STDERR, $r['error'] . "\n");
    exit(1);
}
echo 'pulled ' . count($r['pulled']) . ': ' . implode(', ', array_map(static fn (array $p): string => $p['period'] . ' ' . $p['changed'], $r['pulled'])) . ($r['refused'] !== [] ? '; refused ' . count($r['refused']) . ': ' . implode(' | ', array_map(static fn (array $x): string => $x['period'] . ' — ' . $x['reason'], $r['refused'])) : '') . "\n";
exit(0);
