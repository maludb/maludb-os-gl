<?php
declare(strict_types=1);
/** Action `period_close` (log `period.close` with period_id, after.name; confirm): periods.manage. gl_period_close() is the referee. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/periods/queries.php';
gl_handler_begin();
require_right('periods.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('period') ?? request_integer('period_id') ?? refuse(422, 'Say which period.');
$p = gl_guard($pdo, static function () use ($pdo, $me, $id): array {
    $pdo->beginTransaction();
    close_period($pdo, $id, $me);
    $p = find_period($pdo, $id) ?? throw new DomainException('Not found.');
    log_activity($pdo, 'period.close', 'period', $id, ['period_id' => $id, 'after' => ['name' => $p['name'], 'status' => 'closed', 'posted_entries' => $p['posted_entries'], 'draft_entries' => $p['draft_entries']]]);
    $pdo->commit();
    return $p;
});
gl_done('Closed ' . $p['name'], $id, gl_land(return_path('/periods/' . $id), 'closed'), 'periodChanged', ['period_id' => $id, 'name' => $p['name']]);
