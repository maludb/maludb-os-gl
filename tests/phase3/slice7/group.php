<?php
/** Proof — the month's page (spec "Proof", 2): the group-by tables' totals equal the document's totals; late calls shown; the header facts; a Viewer reads the page. */
require __DIR__ . '/lib.php';
$bea = as_member(27); $vera = as_member(26);
$sept = sept_doc();
$aug = ai_doc('2026-08', 'closed', [ai_line('anthropic', 'claude-sonnet-5-5', null, 'Sasha', 40, 1, '0.4000', 'no department'), ai_line('anthropic', 'claude-sonnet-5-5', 'Accounting', 'Sasha', 60, 0, '0.6001')]);
ledger_state(['2026-09' => $sept, '2026-08' => $aug]);
act($bea, '/ap/ai/pull.php', []);
$S = stmt_id('2026-09'); $A = stmt_id('2026-08');
echo "1. The header and the groups\n";
$body = page($vera, '/ap/ai/' . $S)['body'];
ok(str_contains($body, 'id="ai-statement-head"') && str_contains($body, 'SMOKE Co') && str_contains($body, 'id="ai-statement-head-generated">2026-10-02T08:00:00Z') && str_contains($body, 'nothing is converted') && str_contains($body, 'id="ai-statement-head-late"') && str_contains($body, '2 folded in') && str_contains($body, 'id="ai-statement-head-total-USD"') && str_contains($body, 'Sep 1, 2026'), 'the header facts: business, generated at, the exchange note, late calls, the total, the period');
$doc = $sept['totals'][0];
foreach (['provider' => 2, 'department' => 3, 'agent' => 4, 'model' => 3] as $g => $rows) {
    $d = screen($vera, '/ap/ai/' . $S . '?group_by=' . $g)[1];
    $sum = 0; foreach ($d['lines'] as $l) { $sum += (int) round((float) $l['amount'] * 10000); }
    ok(($d['group_by'] ?? '') === $g && count($d['lines']) === $rows && $d['totals'][0]['amount'] === $doc['amount'] && $sum === 230054 && $d['totals'][0]['calls'] === $doc['calls'] && $d['totals'][0]['late_calls'] === $doc['late_calls'], "group by $g: $rows rows, the rows add to 23.0054 and the total row equals the document's total ($doc[amount]), calls $doc[calls], late $doc[late_calls]");
}
$d = screen($vera, '/ap/ai/' . $S . '?group_by=provider')[1];
$byProv = array_column($d['lines'], null, 'group');
ok($byProv['anthropic']['amount'] === '20.0054' && $byProv['anthropic']['calls'] === 210 && $byProv['anthropic']['late_calls'] === 2 && $byProv['openai']['amount'] === '3.0000' && $byProv['openai']['late_calls'] === 0, 'by provider: Anthropic 20.0054 (210 calls, 2 late), OpenAI 3.0000');
$d = screen($vera, '/ap/ai/' . $S . '?group_by=department')[1];
$byDept = array_column($d['lines'], null, 'group');
ok($byDept['Accounting']['amount'] === '12.3505' && $byDept['IT']['amount'] === '7.6549' && $byDept['IT']['late_calls'] === 2 && $byDept['Front Office']['amount'] === '3.0000', 'by department: Accounting 12.3505, IT 7.6549 (2 late), Front Office 3.0000');
$d = screen($vera, '/ap/ai/' . $S . '?group_by=bogus')[1];
ok(($d['group_by'] ?? '') === 'provider', 'an unknown grouping falls back to provider');
$body = page($vera, '/ap/ai/' . $S . '?group_by=agent')['body'];
ok(str_contains($body, 'id="ai-lines-table"') && str_contains($body, 'Usage by agent') && str_contains($body, 'Sasha') && str_contains($body, 'Seamus') && str_contains($body, 'id="ai-lines-total-USD">23.0054 USD') && str_contains($body, 'id="ai-lines-group-model"') && str_contains($body, 'id="ai-lines-group-department"'), 'the page: the table by agent, the total 23.0054 USD, the four grouping links');
$late = preg_match('/id="ai-line-\d+"><td>Sysadmin<\/td><td class="text-end">80<\/td><td class="text-end text-warning">2<\/td>/', $body);
ok($late === 1, 'late calls are shown (and in warning colour where there are some)');
echo "2. A line with no department; the list\n";
$d = screen($vera, '/ap/ai/' . $A . '?group_by=department')[1];
$names = array_column($d['lines'], 'group');
ok(in_array('No department', $names, true) && in_array('Accounting', $names, true) && $d['totals'][0]['amount'] === '1.0001', 'a line the OS sent with no department is "No department"; the total is 1.0001');
$body = page($vera, '/ap/ai/')['body'];
ok(str_contains($body, 'id="ai-statement-card-' . $A . '"') && str_contains($body, 'id="ai-statement-card-' . $S . '"') && strpos($body, 'ai-statement-card-' . $S) < strpos($body, 'ai-statement-card-' . $A), 'the list: both months, newest first');
ok(str_contains($body, '1 late call'), 'August\'s card says "1 late call"');
[$c, $b] = act($vera, '/ap/ai/import.php', ['ai_statement' => $S]);
ok($c === 403, 'a Viewer reads, but may not import: 403');
$d = screen($vera, '/ap/ai/' . $S)[1];
ok(($d['may']['import'] ?? true) === false && ($d['may']['pull'] ?? true) === false && isset($d['statement']['header']['business']) && $d['statement']['header']['business'] === 'SMOKE Co', 'JSON: the header facts and the rights');
ok(req('GET', '/ap/ai/424242', ['jar' => $vera])['code'] === 404, 'an unknown statement: 404');
finish();
