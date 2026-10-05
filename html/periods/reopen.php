<?php
declare(strict_types=1);
/** Action `period_reopen` (log `period.reopen` with period_id, after.name, after.reason, after.reopen_count; confirm): periods.manage. gl_period_reopen() refuses a blank reason. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/periods/queries.php';
gl_handler_begin();
require_right('periods.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('period') ?? request_integer('period_id') ?? refuse(422, 'Say which period.');
$reason = mb_substr((string) req_val('reason'), 0, 500);
$p = gl_guard($pdo, static function () use ($pdo, $me, $id, $reason): array {
    $pdo->beginTransaction();
    reopen_period($pdo, $id, $me, $reason);
    $p = find_period($pdo, $id) ?? throw new DomainException('Not found.');
    log_activity($pdo, 'period.reopen', 'period', $id, ['period_id' => $id, 'after' => ['name' => $p['name'], 'status' => 'open', 'reason' => $reason, 'reopen_count' => $p['reopen_count']]]);
    $pdo->commit();
    return $p;
});
gl_done('Reopened ' . $p['name'], $id, gl_land(return_path('/periods/' . $id), 'reopened'), 'periodChanged', ['period_id' => $id, 'name' => $p['name'], 'reopen_count' => $p['reopen_count']]);
