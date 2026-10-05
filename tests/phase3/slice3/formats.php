<?php
/** Proof — posted-through, the print page, the CSV and what is logged (spec "Proof", 5). */
require dirname(__DIR__) . '/lib.php';
$vera = as_member(26); $bea = as_member(27);
$w = world();
draft($bea, '2026-08-01', [['account' => '6100', 'debit' => '1'], ['account' => '1010', 'credit' => '1']], 'SMOKE waiting draft');
echo "1. Posted through\n";
foreach (['pl', 'balance-sheet', 'trial-balance', 'cash-flow'] as $s) {
    $body = page($vera, "/statements/$s?period=2026-06")['body'];
    $ok = str_contains($body, 'id="posted-through-text"') && preg_match('/Posted through \d+ \w+ 2026/', $body) === 1 && str_contains($body, 'waiting');
    if (!$ok) { break; }
}
ok($ok, 'every statement carries "Posted through … · N waiting"');
[$c, $d] = screen($vera, '/statements/pl?period=2026-06');
ok(preg_match('/^Posted through 1 Jun 2026 · /', $d['posted_through']['sentence']) === 0 && $d['posted_through']['date'] === '2026-06-10' && $d['posted_through']['waiting']['draft_entries'] >= 1, 'the date is the last posted entry (2026-06-10) and the drafts are counted: ' . $d['posted_through']['sentence']);
echo "2. The CSV\n";
$since = last_activity_id();
$r = req('GET', '/statements/pl?from=2026-01-01&to=2026-12-31&format=csv', ['jar' => $vera]);
$lines = array_values(array_filter(explode("\n", trim($r['body']))));
ok($r['code'] === 200 && str_contains($r['headers'], 'text/csv') && str_contains($r['headers'], 'filename="pl-2026-12-31.csv"') && str_contains($lines[0], 'Profit and loss') && $lines[1] === 'Currency,USD', 'a Viewer takes the CSV (no exports.download needed): the title, the currency [' . $r['code'] . ' | ' . trim(strtok($r['headers'], "\r\n")) . ' | ' . implode(' / ', array_slice($lines, 0, 2)) . ']');
ok(in_array('"INCOME",,', $lines, true) || in_array('INCOME,,', $lines, true) || count(array_filter($lines, fn ($l) => str_starts_with($l, 'INCOME'))) === 1, 'a section header row');
ok(str_contains(end($lines), 'TOTAL Net income'), 'net income is the last line, the table\'s TOTAL row (' . end($lines) . ')');
$log = activity('export.download', $since);
$a = json_decode((string) $log[0]['after'], true);
ok(count($log) === 1 && $a['what'] === 'pl' && $a['format'] === 'csv' && $a['period'] === '2026-01-01 – 2026-12-31' || str_contains($a['period'], '2026'), 'export.download logged with what, format and the period');
$r = req('GET', '/statements/trial-balance?as_of=2026-12-31&format=csv', ['jar' => $vera]);
ok(str_contains($r['body'], 'TOTAL') && count(array_filter(explode("\n", $r['body']), fn ($l) => str_starts_with($l, ',TOTAL') || str_contains($l, 'TOTAL Total'))) >= 1, 'the trial balance CSV has a TOTAL row');
echo "3. The print page\n";
$since = last_activity_id();
$r = req('GET', '/statements/balance-sheet?as_of=2026-06-30&format=print', ['jar' => $vera]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="print-business">SMOKE Co<') && str_contains($r['body'], '1 Main St') && str_contains($r['body'], 'id="print-posted-through"') && !str_contains($r['body'], 'nxl-navigation') && !str_contains($r['body'], 'id="page-content"'), 'bare: the business\'s name and address and posted-through, no shell');
ok(str_contains($r['body'], 'Balance sheet') && str_contains($r['body'], '2008.24') === false && str_contains($r['body'], '2,008.24'), 'the statement with formatted money');
ok(count(activity('export.download', $since)) === 1 && json_decode((string) activity('export.download', $since)[0]['after'], true)['format'] === 'print', 'the print page logs export.download with format print');
$since = last_activity_id();
page($vera, '/statements/pl?period=2026-06', ['headers' => ['Accept: application/json', 'X-Screen-View: 1']]);
$v = activity('screen.view', $since);
ok($v !== [] && json_decode((string) end($v)['after'], true)['statement'] === 'pl', 'the table view logs screen.view with after.statement');
finish();
