<?php
declare(strict_types=1);
/** Action `year_reopen` (log `fiscal_year.reopen`: after.reason; confirm): periods.manage. gl_year_reopen() reverses the closing entry and refuses a blank reason. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/periods/queries.php';
gl_handler_begin();
require_right('periods.manage');
$pdo = db();
$me = (int) current_member_id();
$yid = request_integer('fiscal_year') ?? request_integer('fiscal_year_id') ?? refuse(422, 'Say which fiscal year.');
$reason = mb_substr((string) req_val('reason'), 0, 500);
$r = gl_guard($pdo, static function () use ($pdo, $me, $yid, $reason): array {
    $pdo->beginTransaction();
    $before = find_fiscal_year($pdo, $yid) ?? throw new DomainException('Not found.');
    reopen_year($pdo, $yid, $me, $reason);
    $y = find_fiscal_year($pdo, $yid);
    $last = $y['periods'] === [] ? null : end($y['periods'])['fiscal_period_id'];
    log_activity($pdo, 'fiscal_year.reopen', 'fiscal_year', $yid, ['period_id' => $last, 'journal_entry_id' => $before['closing_entry_id'], 'after' => ['name' => $y['name'], 'reason' => $reason, 'reversed_entry_id' => $before['closing_entry_id']]]);
    $pdo->commit();
    return ['year' => $y, 'last' => $last];
});
gl_done('Reopened ' . $r['year']['name'], $yid, gl_land(return_path($r['last'] === null ? '/periods/' : '/periods/' . $r['last']), 'year_reopened'), 'periodChanged', ['fiscal_year_id' => $yid]);
