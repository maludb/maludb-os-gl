<?php
/** Proof — department and site filters on the P&L (spec "Proof", 3). */
require dirname(__DIR__) . '/lib.php';
$vera = as_member(26);
$w = world();
$q = 'from=2026-01-01&to=2026-12-31';
echo "1. Department\n";
[$c, $d] = screen($vera, "/statements/pl?$q&department=5");
$p = $d['data'];
ok($c === 200 && $p['income'] === [] && count($p['expense_groups']) === 1 && $p['expense_total'] === '259.80' && $p['expense_groups'][0]['rows'][0]['code'] === '6200', 'IT (5): only the hosting, 259.80 on 6200');
[$c, $d] = screen($vera, "/statements/pl?$q&department=6");
$p = $d['data'];
ok($p['income_total'] === '1599.99' && $p['expense_total'] === '0.00', 'Front Office (6): invoice 1\'s income, 1,599.99');
$body = page($vera, "/statements/pl?$q&department=5")['body'];
ok(str_contains($body, 'href="/accounts/' . one("SELECT id FROM accounts WHERE code = '6200'") . '?from=2026-01-01&amp;to=2026-12-31&amp;department=5"'), 'the account link keeps the department');
ok(str_contains($body, 'id="period-picker-department"') && preg_match('/<option value="5" selected>/', $body) === 1, 'the picker shows the department chosen');
echo "2. Site\n";
[$c, $d] = screen($vera, "/statements/pl?$q&site=" . $w['site']);
ok($c === 200 && $d['data']['income_total'] === '1599.99' && $d['data']['expense_total'] === '0.00', 'the site\'s lines: invoice 1\'s income');
[$c, $d] = screen($vera, "/statements/pl?$q");
ok($d['data']['income_total'] === '1999.99', 'no filter: all income again');
[$c, $d] = screen($vera, "/statements/balance-sheet?as_of=2026-12-31&department=5");
ok($c === 200 && $d['department'] === null, 'a department on the balance sheet is ignored (P&L only)');
$body = page($vera, '/statements/balance-sheet?as_of=2026-12-31')['body'];
ok(!str_contains($body, 'id="period-picker-department"'), 'the balance sheet\'s picker offers no department');
finish();
