<?php
declare(strict_types=1);

/**
 * The General Ledger worker (slice 8): one pass a minute from gl-worker.timer — the outbox (email through MaluMail, texts through the kernel), the AI statement poll (hourly), recurring templates falling due, reminders,
 * secure links' expiry, stale claims, dispatch retries, housekeeping. Advisory-locked; each step its own try; the report is printed as one JSON line and logged once as `worker.pass` (counts and sentences, never a body).
 *   php bin/worker.php [--only=send,ai,recurring,reminders,links,claims,dispatch,housekeeping] [--limit=200]
 * GL_WORKER_NOW (YYYY-MM-DD[ HH:MM:SS]) fakes the clock; it is honoured only when APP_ENV is not production.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/recurring/handler.php';
require_once dirname(__DIR__) . '/app/features/invoices/mail.php';
require_once dirname(__DIR__) . '/app/features/ai/kernel.php';
require_once dirname(__DIR__) . '/app/features/ai/queries.php';
require_once dirname(__DIR__) . '/app/features/ai/write.php';
require_once dirname(__DIR__) . '/app/features/agents/dispatch.php';
require_once dirname(__DIR__) . '/app/features/worker/steps.php';

$only = [];
$limit = 200;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--only=')) {
        $only = array_values(array_filter(explode(',', substr($a, 7))));
        if (array_diff($only, WORKER_STEPS) !== []) {
            fwrite(STDERR, 'Unknown step: ' . implode(', ', array_diff($only, WORKER_STEPS)) . '. Steps: ' . implode(', ', WORKER_STEPS) . ".\n");
            exit(2);
        }
    } elseif (str_starts_with($a, '--limit=')) {
        $limit = max(1, min(1000, (int) substr($a, 8)));
    } else {
        fwrite(STDERR, "Usage: php bin/worker.php [--only=a,b] [--limit=N]\n");
        exit(2);
    }
}
$now = new DateTimeImmutable();
if (($fake = getenv('GL_WORKER_NOW')) !== false && $fake !== '' && (string) env('APP_ENV', 'prod') !== 'prod') {
    $now = new DateTimeImmutable($fake);
}
$pdo = db();
$lock = $pdo->query("SELECT pg_try_advisory_lock(hashtext('gl_worker'))")->fetchColumn();
if (!(bool) $lock) {
    echo json_encode(['skipped' => 'another pass holds the lock']) . "\n";
    exit(0);
}
// The steps that read through the mcp_* views (reminders, recurring) read as the ledger's administrator — the views answer nothing to nobody; every write is still logged source cron, actor none.
$admin = $pdo->query("SELECT id FROM members WHERE status = 'active' AND member_kind = 'human' AND capability IS NOT NULL AND (business_role = 'super_admin' OR capability = 'admin') ORDER BY (business_role = 'super_admin') DESC, id LIMIT 1")->fetchColumn();
if ($admin !== false) {
    $pdo->prepare("SELECT set_config('app.member_id', ?, false)")->execute([(string) (int) $admin]);
}
$started = microtime(true);
$report = worker_pass($pdo, $only, $limit, $now);
$report['seconds'] = round(microtime(true) - $started, 2);
log_activity($pdo, 'worker.pass', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => $report]);
echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
$pdo->query("SELECT pg_advisory_unlock(hashtext('gl_worker'))");
exit($report['errors'] === [] ? 0 : 1);
