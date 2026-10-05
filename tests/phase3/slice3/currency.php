<?php
/** Proof — a second currency (spec "Proof", 4): the ageing renders one table per currency, never a sum; the CSV holds both with a TOTAL each; the statements say the books hold another currency. RUNS LAST (it adds a EUR invoice). */
require dirname(__DIR__) . '/lib.php';
$vera = as_member(26);
$w = world();
if (!one("SELECT 1 FROM invoices WHERE currency = 'EUR'")) {
    $inv = (int) one("INSERT INTO invoices (customer_id, issue_date, due_date, currency, created_by) VALUES (:c, '2026-07-10', '2026-08-09', 'EUR', 27) RETURNING id", ['c' => $w['beta']]);
    q("INSERT INTO invoice_lines (invoice_id, sort_order, description, quantity, unit_price, income_account_id) VALUES (:i, 1, 'EUR retainer', 1, 200, :a)", ['i' => $inv, 'a' => account_id('4100')]);
    one('SELECT gl_invoice_issue(:i, 27)', ['i' => $inv]);
}
echo "1. The ageing, per currency\n";
[$c, $d] = screen($vera, '/reports/ar-aging?as_of=2026-09-30');
$cur = array_column($d['tables'], 'currency');
ok($c === 200 && $cur === ['EUR', 'USD'], 'two tables, EUR and USD, never one sum (' . implode(', ', $cur) . ')');
$eur = $d['tables'][0]; $usd = $d['tables'][1];
ok($eur['rows'][count($eur['rows']) - 1]['cells'][6] === '200.00' && $usd['rows'][count($usd['rows']) - 1]['cells'][6] === '1008.24', 'EUR totals 200.00, USD 1,008.24');
$r = req('GET', '/reports/ar-aging?as_of=2026-09-30&format=csv', ['jar' => $vera]);
$lines = array_values(array_filter(explode("\n", trim($r['body']))));
ok(count(array_filter($lines, fn ($l) => str_starts_with($l, 'Currency,'))) === 2 && count(array_filter($lines, fn ($l) => str_contains($l, 'TOTAL'))) === 2 && str_contains($r['body'], 'Currency,EUR') && str_contains($r['body'], 'Currency,USD'), 'the CSV holds both tables with a header pair and a TOTAL row each');
$body = page($vera, '/reports/ar-aging?as_of=2026-09-30')['body'];
ok(str_contains($body, 'id="report-eur"') && str_contains($body, 'id="report-usd"'), 'the screen has a card per currency');
echo "2. The statements say it\n";
$body = page($vera, '/statements/pl?from=2026-01-01&to=2026-12-31')['body'];
ok(str_contains($body, 'id="statement-mixed-currency"') && str_contains($body, 'EUR as well as USD'), 'a statement notes the EUR entry: nothing is converted and the currencies are not shown apart');
[$c, $d] = screen($vera, '/statements/trial-balance?as_of=2026-12-31');
ok($d['data']['balanced'] === true, 'the trial balance still balances');
finish();
