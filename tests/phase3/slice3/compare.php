<?php
/** Proof — compare (spec "Proof", 2): previous month's column and variance; last year with no books says so. */
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__, 3) . '/app/money_math.php';
$vera = as_member(26);
world();
echo "1. compare = previous\n";
[$c, $d] = screen($vera, '/statements/pl?period=2026-06&compare=previous');
$p = $d['data'];
ok($c === 200 && $d['compare']['from'] === '2026-05-01' && $d['compare']['to'] === '2026-05-31', 'the previous month of 2026-06 is 2026-05');
// June: income 500 − 100 = 400; expenses rent 2000. May: income 0, expenses rent 2000
ok($p['income_total'] === '400.00' && $p['income_prior'] === '0.00' && $p['expense_total'] === '2000.00' && $p['expense_prior'] === '2000.00', 'June: income 400.00 against 0.00 in May; rent 2,000.00 each month');
ok($p['net_income'] === '-1600.00' && $p['net_prior'] === '-2000.00' && $p['net_variance'][0] === '400.00' && $p['net_variance'][1] === '20.0', 'net income -1,600.00 against -2,000.00: variance 400.00, 20.0%');
$rent = array_values(array_filter($p['expense_groups'][0]['rows'], fn ($r) => $r['code'] === '6100'))[0];
ok($rent['prior'] === '2000.00' && $rent['variance'] === '0.00', 'an account row carries its prior and a zero variance');
$body = page($vera, '/statements/pl?period=2026-06&compare=previous')['body'];
ok(str_contains($body, '<th class="text-end">Prior</th>') && str_contains($body, '<th class="text-end">Variance</th>') && str_contains($body, 'id="statement-compare-note"'), 'the screen shows the Prior and Variance columns and says which period');
echo "2. last year\n";
[$c, $d] = screen($vera, '/statements/pl?period=2026-06&compare=last_year');
ok($c === 200 && $d['compare']['from'] === '2025-06-01' && $d['data']['income_prior'] === '0.00' && $d['data']['net_prior'] === '0.00', 'last year of June is zeros');
$body = page($vera, '/statements/pl?from=2025-01-01&to=2025-12-31')['body'];
ok(str_contains($body, 'id="statement-no-books"') && str_contains($body, 'No books for that period.') && !str_contains($body, 'id="statement-table"'), 'a year with no books says "No books for that period." rather than an empty table');
[$c, $d] = screen($vera, '/statements/balance-sheet?as_of=2026-06-30&compare=previous');
ok($c === 200 && $d['compare']['as_of'] === '2026-05-31' && count($d['table']['columns']) === 5, 'the balance sheet compares as-of dates: five columns');
$rcv = array_values(array_filter($d['data']['sections']['asset'], fn ($r) => $r['code'] === '1200'))[0];
ok($rcv['balance'] === '2008.24' && $rcv['prior'] === '1608.24', 'receivable 2,008.24 on June 30 against 1,608.24 on May 31 (the retainer less the credit)');
[$c, $d] = screen($vera, '/statements/pl?period=2026-06&compare=bogus');
ok($c === 422, 'an unknown compare mode: 422');
[$c, $d] = screen($vera, '/statements/pl?period=2026-13');
ok($c === 422, 'an unknown period: 422');
$r = req('GET', '/statements/pl?from=bad', ['jar' => $vera]);
ok($r['code'] === 422, 'a bad date: 422');
finish();
