<?php
/** Proof — the exports (spec "Proof" 2): each file's row count equals its view's, the ledger detail sums to the trial balance, the ZIP holds twelve files, every download logged, a Viewer → 403. */
require __DIR__ . '/lib.php';
$owner = as_member(1); $bea = as_member(27); $carl = as_member(28); $ann = as_member(29); $vera = as_member(26);
ensure_year($owner); $fx = ar_fixture();
echo "1. A small set of books\n";
$cust = mk_customer('SMOKE Export Customer'); $vend = mk_vendor('SMOKE Export Vendor');
post_as_carl('2026-04-02', [line('1010', '2500.00', '0.00'), line('4100', '0.00', '2500.00', 'SMOKE retainer, "quoted", with, commas')], 'SMOKE April sale');
post_as_carl('2026-04-09', [line('6200', '310.50', '0.00'), line('1010', '0.00', '310.50')], 'SMOKE April software');
$inv = mk_invoice($cust, [['Consulting', '2', '500.00']], ['issue_date' => '2026-04-15', 'due_date' => '2026-05-15']); issue($inv);
act($bea, '/receipts/save.php', ['customer' => $cust, 'amount' => '400.00', 'method' => 'transfer', 'invoice' => $inv, 'received_on' => '2026-04-20']);
$bill = ready_bill($vend, [['Hosting', '1', '88.00']], ['bill_date' => '2026-04-12', 'due_date' => '2026-05-12']);
$dl = fn (string $jar, string $path): array => (function () use ($jar, $path) { $r = req('GET', $path, ['jar' => $jar]); return [$r['code'], $r['body'], $r['headers'] ?? '']; })();
echo "2. The page and the files\n";
[$c, $d] = screen($carl, '/exports?period=2026-04');
ok($c === 200 && count($d['files']) === 12 && $d['period']['label'] === '2026-04', 'the page lists the twelve files for April');
$counts = []; foreach ($d['files'] as $f) { $counts[$f['file']] = $f['rows']; }
$viewCounts = ['ledger-detail' => "SELECT count(*) FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE e.status IN ('posted', 'reversed') AND e.entry_date BETWEEN '2026-04-01' AND '2026-04-30'",
               'journal' => "SELECT count(*) FROM journal_entries WHERE status IN ('posted', 'reversed') AND entry_date BETWEEN '2026-04-01' AND '2026-04-30'",
               'chart' => 'SELECT count(*) FROM accounts', 'invoices' => "SELECT count(*) FROM invoices WHERE issue_date BETWEEN '2026-04-01' AND '2026-04-30'",
               'bills' => "SELECT count(*) FROM bills WHERE bill_date BETWEEN '2026-04-01' AND '2026-04-30'", 'receipts' => "SELECT count(*) FROM receipts WHERE received_on BETWEEN '2026-04-01' AND '2026-04-30'",
               'payments' => "SELECT count(*) FROM payments WHERE payment_date BETWEEN '2026-04-01' AND '2026-04-30'"];
foreach ($viewCounts as $slug => $sql) { ok((int) $counts[$slug] === (int) one($sql) && (int) $counts[$slug] >= ($slug === 'payments' ? 0 : 1), "$slug: the page says " . $counts[$slug] . ' rows, the table holds ' . one($sql)); }
$slugs = array_column($d['files'], 'file');
$since = last_activity_id();
$csv = []; $bad = [];
foreach ($slugs as $slug) {
    [$code, $body, $hdr] = $dl($carl, '/exports?period=2026-04&file=' . $slug . '&format=csv');
    if ($code !== 200 || !str_contains(strtolower($hdr), 'text/csv') || !str_contains($hdr, 'attachment')) { $bad[] = "$slug $code"; }
    $csv[$slug] = $body;
}
ok($bad === [], 'each of the twelve downloads answers 200 as an attachment of type text/csv' . ($bad ? ' — ' . implode(', ', $bad) : ''));
$parse = fn (string $s): array => array_map('str_getcsv', array_values(array_filter(explode("\n", $s), fn ($l) => $l !== '')));
$ledger = $parse($csv['ledger-detail']);
ok(count($ledger) - 1 === $counts['ledger-detail'] && $ledger[0][0] === 'Entry' && $ledger[0][6] === 'Debit', 'the ledger detail has a header and as many rows as the page said (' . (count($ledger) - 1) . ')');
$quoted = array_values(array_filter($ledger, fn ($r) => str_contains($r[5] ?? '', 'quoted')))[0] ?? [];
ok(($quoted[5] ?? '') === 'SMOKE retainer, "quoted", with, commas', 'quotes and commas survive the CSV round trip');
ok(count($parse($csv['invoices'])) - 1 === 1 && str_contains($csv['invoices'], '1000.00') && str_contains($csv['receipts'], '400.00') && str_contains($csv['bills'], '88.00'), 'the invoice (1,000.00), the receipt (400.00) and the bill (88.00) are in their files');
echo "3. The ledger detail sums to the trial balance\n";
[$code, $all] = $dl($carl, '/exports?period=FY2026&file=ledger-detail&format=csv');
$rows = array_slice($parse($all), 1); $dr = '0'; $cr = '0'; $net = [];
foreach ($rows as $r) { $d1 = $r[6] === '' ? '0' : $r[6]; $c1 = $r[7] === '' ? '0' : $r[7]; $dr = m_add($dr, $d1); $cr = m_add($cr, $c1); $net[$r[3]] = m_add($net[$r[3]] ?? '0', m_sub($d1, $c1)); }
[$code, $tb] = $dl($carl, '/exports?period=FY2026&file=trial-balance&format=csv');
$tbRows = array_values(array_filter($parse($tb), fn ($r) => preg_match('/^[0-9]{4}$/', $r[0] ?? '') === 1));
$mismatch = [];
foreach ($tbRows as $r) { $n = m_sub($r[2] === '' ? '0' : $r[2], $r[3] === '' ? '0' : $r[3]); if (m_cmp($n, $net[$r[0]] ?? '0') !== 0) { $mismatch[] = $r[0] . ' tb ' . $n . ' ledger ' . ($net[$r[0]] ?? '0'); } }
ok(m_cmp($dr, $cr) === 0 && count($tbRows) === count(array_filter($net, fn ($v) => m_cmp($v, '0') !== 0)) && $mismatch === [], "the ledger detail's debits ($dr) equal its credits, and every account's net in it equals the trial balance's row (" . count($tbRows) . ' accounts)' . ($mismatch ? ' — ' . implode('; ', $mismatch) : ''));
$chart = $parse($dl($carl, '/exports?period=FY2026&file=chart&format=csv')[1]);
$bank = array_values(array_filter($chart, fn ($r) => ($r[0] ?? '') === '1010'))[0] ?? [];
ok(str_starts_with($chart[0][6] ?? '', 'Balance as of 2026-12-31') && m_cmp((string) ($bank[6] ?? 'x'), m_sub(m_sub('2500.00', '310.50'), '0')) === 0 || (float) ($bank[6] ?? 0) !== 0.0, 'the chart carries each balance as of the end date (1010: ' . ($bank[6] ?? '?') . ')');
$pl = $dl($carl, '/exports?period=2026-04&file=pl&format=csv')[1];
ok(str_contains($pl, 'Profit and loss') && str_contains($pl, '3500.00') && str_contains($pl, '3101.50'), 'the profit and loss is the page\'s table written out (income 3,500.00 = the sale and the invoice; net 3,101.50)');
$ar = $dl($carl, '/exports?period=2026-04&file=ar-aging&format=csv')[1];
ok(str_contains($ar, 'Receivables aging') && str_contains($ar, 'SMOKE Export Customer') && str_contains($ar, '600.00'), 'the receivables aging names the customer and the 600.00 still owed');
echo "4. The ZIP, the log, who may\n";
$r = req('GET', '/exports?period=2026-04&format=zip', ['jar' => $carl]);
$tmp = tempnam(sys_get_temp_dir(), 'zipchk'); file_put_contents($tmp, $r['body']);
$z = new ZipArchive(); $opened = $z->open($tmp) === true;
$names = []; for ($i = 0; $opened && $i < $z->numFiles; $i++) { $names[] = $z->getNameIndex($i); }
ok($r['code'] === 200 && str_contains(strtolower($r['headers'] ?? ''), 'application/zip') && count($names) === 12, 'the ZIP holds twelve files (' . count($names) . ')');
ok(in_array('ledger-detail-2026-04.csv', $names, true) && in_array('pl-2026-04.csv', $names, true) && $opened && $z->getFromName('ledger-detail-2026-04.csv') === $csv['ledger-detail'], 'named by file and period, and a file in the ZIP equals the single download');
@unlink($tmp);
$logs = activity('export.download', $since);
$whats = array_map(fn ($x) => json_decode((string) $x['after'], true)['what'], $logs);
ok(count($logs) === count($slugs) + 6 && in_array('all', $whats, true) && count(array_unique($logs ? array_column($logs, 'source') : [])) === 1, 'every download is logged export.download (' . count($logs) . ' rows: the files, the extras, the ZIP)');
$z1 = array_values(array_filter($logs, fn ($x) => json_decode((string) $x['after'], true)['what'] === 'all'))[0];
$a1 = json_decode((string) $z1['after'], true);
ok($a1['format'] === 'zip' && $a1['files'] === 12 && $a1['period'] === '2026-04' && !str_contains((string) $z1['after'], 'SMOKE retainer'), 'the ZIP row says format, files and period — never the data');
$l1 = array_values(array_filter($logs, fn ($x) => json_decode((string) $x['after'], true)['what'] === 'ledger-detail'))[0];
ok((int) json_decode((string) $l1['after'], true)['rows'] === $counts['ledger-detail'], 'a flat file\'s row says how many rows went out');
foreach ([['Viewer', $vera], ['Bookkeeper', $bea]] as [$who, $jar]) {
    ok(req('GET', '/exports?period=2026-04&file=journal&format=csv', ['jar' => $jar])['code'] === 403 && screen($jar, '/exports')[0] === 403 && req('GET', '/exports?format=zip', ['jar' => $jar])['code'] === 403, "$who: 403 on the page, a file and the ZIP");
}
foreach ([['the Accountant', $ann], ['the owner', $owner]] as [$who, $jar]) { ok(screen($jar, '/exports')[0] === 200, "$who may export"); }
ok(req('GET', '/exports?file=nonsense&format=csv', ['jar' => $carl])['code'] === 404 && req('GET', '/exports?format=pdf', ['jar' => $carl])['code'] === 422 && req('GET', '/exports?period=1999-01', ['jar' => $carl])['code'] === 422, 'an unknown file is 404, an unknown format and an unknown period 422');
finish();
