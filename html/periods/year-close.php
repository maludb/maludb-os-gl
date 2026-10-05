<?php
declare(strict_types=1);
/** Action `year_end_close` (log `fiscal_year.close`: after.closing_entry_number, amount, currency, journal_entry_id; confirm; money_out for agents): periods.manage. gl_year_close() is the referee. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/periods/queries.php';
gl_handler_begin();
require_right('periods.manage');
$pdo = db();
$me = (int) current_member_id();
$yid = request_integer('fiscal_year') ?? request_integer('fiscal_year_id') ?? refuse(422, 'Say which fiscal year.');
$r = gl_guard($pdo, static function () use ($pdo, $me, $yid): array {
    $pdo->beginTransaction();
    $e = close_year($pdo, $yid, $me);
    $y = find_fiscal_year($pdo, $yid) ?? throw new DomainException('Not found.');
    $last = $y['periods'] === [] ? null : end($y['periods'])['fiscal_period_id'];
    log_activity($pdo, 'fiscal_year.close', 'fiscal_year', $yid, ['period_id' => $last, 'journal_entry_id' => $e['journal_entry_id'] ?? null,
        'after' => ['name' => $y['name'], 'closing_entry_number' => $e['number'] ?? null, 'amount' => $e['total'] ?? '0.00', 'currency' => $e['currency'] ?? null]]);
    $pdo->commit();
    return ['year' => $y, 'entry' => $e, 'last' => $last];
});
$did = 'Closed ' . $r['year']['name'] . ($r['entry'] === null ? ' (nothing to close to retained earnings)' : ' — closing entry ' . $r['entry']['number']);
gl_done($did, $yid, gl_land(return_path($r['last'] === null ? '/periods/' : '/periods/' . $r['last']), 'year_closed'), 'periodChanged',
    ['fiscal_year_id' => $yid, 'closing_entry' => $r['entry'] === null ? null : ['journal_entry_id' => $r['entry']['journal_entry_id'], 'number' => $r['entry']['number'], 'total' => $r['entry']['total']]]);
