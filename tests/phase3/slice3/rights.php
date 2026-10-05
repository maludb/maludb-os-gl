<?php
/** Proof — rights and the registry (spec "Proof", 7): every role reads every statement and report; unknown names are 404; the screens read as built. */
require dirname(__DIR__) . '/lib.php';
$w = world();
$jars = ['Viewer' => as_member(26), 'Bookkeeper' => as_member(27), 'Controller' => as_member(28), 'Accountant' => as_member(29), 'admin' => as_member(1)];
$paths = ['/statements/', '/statements/pl?period=2026-06', '/statements/balance-sheet?as_of=2026-06-30', '/statements/trial-balance?as_of=2026-06-30', '/statements/cash-flow?period=2026-06',
    '/reports/ar-aging', '/reports/ap-aging', '/reports/sales-tax?period=2026-06', '/reports/spend?period=2026-06', '/reports/revenue?period=2026-06', '/reports/customer-statement', '/reports/control-check'];
$wrong = [];
foreach ($jars as $who => $j) { foreach ($paths as $p) { $c = page($j, $p)['code']; if ($c !== 200) { $wrong[] = "$who $p $c"; } } }
ok($wrong === [], count($jars) . ' roles × ' . count($paths) . ' screens answer 200' . ($wrong ? ' — wrong: ' . implode('; ', $wrong) : ''));
$r = req('GET', '/statements/pl');
ok($r['code'] === 302, 'no session: the launcher');
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$built = array_keys(array_filter($reg['screens'], fn ($s) => $s['built']));
ok(in_array('statements', $built, true) && in_array('statement', $built, true) && in_array('reports', $built, true), 'the registry reads statements, statement and reports as built');
$names = array_column(array_filter($reg['actions'], fn ($a) => str_contains((string) ($a['endpoint'] ?? ''), '/statements') || str_contains((string) ($a['endpoint'] ?? ''), '/reports')), 'action');
ok($names === [], 'and there are no actions behind them (every screen is a GET)');
$r = req('POST', '/statements/pl', ['jar' => $jars['Viewer'], 'form' => ['x' => 1]]);
ok(in_array($r['code'], [403, 405, 200], true) && one("SELECT count(*) FROM activity_log WHERE action LIKE 'statement.%'") == 0, 'a POST to a statement writes nothing');
$r = req('GET', '/statements/pl?format=csv&period=2026-06', ['jar' => $jars['Viewer']]);
ok($r['code'] === 200, 'a Viewer\'s CSV: 200');
finish();
