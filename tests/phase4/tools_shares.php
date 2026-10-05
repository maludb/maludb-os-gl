<?php
/** Proof — the three K7 shares (maludb-os.json shares[]): the kernel's own token only, flat arguments as the kernel sends them; totals and no person, no party, no document; a person or an agent never lists or calls one. */
require __DIR__ . '/lib.php';
$W = p4world();
$k = fn () => ktoken();
$call = fn (string $tool, array $args = []) => mcp_tool(REC, $k(), $tool, $args, true);
echo "1. account_balances\n";
$r = $call('account_balances', ['as_of' => '2026-12-31']);
$d = $r['data'];
$tb = sql_as(1, "SELECT code, account_type, CASE WHEN account_type IN ('asset', 'expense') THEN debit - credit ELSE credit - debit END AS bal FROM gl_trial_balance(DATE '2026-12-31') ORDER BY code");
ok(!$r['error'] && $d['schema'] === 'gl.account-balances/1' && $d['as_of'] === '2026-12-31' && count($d['accounts']) === count($tb), 'the kernel gets every account with a balance as of the date, flat call (' . count($d['accounts']) . ' accounts)');
$ok = true; foreach ($tb as $i => $row) { if ($d['accounts'][$i]['code'] !== $row['code'] || !same_money($d['accounts'][$i]['balance'], $row['bal'])) { $ok = false; } }
ok($ok, 'each balance equals the trial balance in its normal sense, to the cent');
ok(array_keys($d['accounts'][0]) === ['code', 'name', 'account_type', 'subtype', 'balance'], 'only code, name, type, subtype and balance — no ids, no parties');
ok(!$call('account_balances', [])['error'], 'as_of is optional (today)');
$c = $call('account_balances', ['as_of' => 'soon']);
ok($c['error'], 'a bad date is a tool error: ' . substr($c['text'], 0, 80));
echo "2. ap_due_summary\n";
$r = $call('ap_due_summary', ['weeks' => 4]);
$d = $r['data'];
$owing = sql_as(1, "SELECT currency, due_date, balance_due FROM mcp_bills WHERE status IN ('approved', 'partially_paid') AND balance_due > 0");
$overdue = array_sum(array_map(fn ($b) => $b['due_date'] < date('Y-m-d') ? (float) $b['balance_due'] : 0, $owing));
$byBucket = []; foreach ($d['buckets'] as $b) { $byBucket[$b['bucket']] = ($byBucket[$b['bucket']] ?? 0) + (float) $b['amount']; }
ok(!$r['error'] && $d['schema'] === 'gl.ap-due-summary/1' && $d['weeks'] === 4 && same_money($byBucket['overdue'] ?? 0, $overdue), 'overdue: the bills past due, summed (' . ($byBucket['overdue'] ?? 0) . ')');
$week1 = array_sum(array_map(fn ($b) => $b['due_date'] >= date('Y-m-d') && $b['due_date'] <= date('Y-m-d', strtotime('+6 days')) ? (float) $b['balance_due'] : 0, $owing));
ok(same_money($byBucket['week 1'] ?? 0, $week1) && $week1 >= 259.80, 'week 1 holds the hosting bill due in three days (' . ($byBucket['week 1'] ?? 0) . ')');
ok(!str_contains($r['text'], 'Hosting') && !str_contains($r['text'], 'SMOKE') && !str_contains($r['text'], 'H-2026') && array_keys($d['buckets'][0]) === ['bucket', 'from_date', 'to_date', 'currency', 'amount', 'bills'], 'no vendor, no bill, no reference anywhere in the answer — buckets and totals only');
ok(!$call('ap_due_summary', [])['error'] && $call('ap_due_summary', ['weeks' => 0])['error'] && $call('ap_due_summary', ['weeks' => 40])['error'], 'weeks defaults to 8 and is 1 to 26');
echo "3. ar_aging_summary\n";
$r = $call('ar_aging_summary', ['as_of' => '2026-10-05']);
$d = $r['data'];
$sql = sql_as(1, "SELECT sum(total) AS t, sum(current_due) AS c, sum(days_over_90) AS o, sum(open_invoices) AS n FROM gl_ar_aging(DATE '2026-10-05')")[0];
ok(!$r['error'] && $d['schema'] === 'gl.ar-aging-summary/1' && same_money($d['currencies'][0]['total'], $sql['t']) && same_money($d['currencies'][0]['current_due'], $sql['c']) && same_money($d['currencies'][0]['days_over_90'], $sql['o']) && $d['currencies'][0]['open_invoices'] === (int) $sql['n'], 'the totals per bucket equal gl_ar_aging() summed (' . $d['currencies'][0]['total'] . ')');
ok(!str_contains($r['text'], 'Acme') && !str_contains($r['text'], 'Beta') && !has_key($d, 'customer_name') && !has_key($d, 'customer_id'), 'no customer named');
echo "4. Nobody else\n";
foreach (['account_balances', 'ap_due_summary', 'ar_aging_summary'] as $t) {
    $p = mcp_tool(REC, ptoken(28), $t, []);
    ok($p['error'], "a person calling $t: refused");
}
ok(!array_intersect(['account_balances', 'ap_due_summary', 'ar_aging_summary'], mcp_tools(REC, ptoken(1))) && !array_intersect(['account_balances', 'ap_due_summary', 'ar_aging_summary'], mcp_tools(REC, person_token(1))), 'no person lists a share, the owner included');
$run = random_int(800000, 899999); make_agent(951, 'SMOKE Bookkeeper P4', ['bookkeeper', 'user']);
facts($run, 951, ['Records MCP' => ['account_balances' => [], 'ar_aging_summary' => [], 'unposted_entries' => []]]);
ok(mcp_tools(REC, rtoken(951, $run)) === ['unposted_entries'], 'an agent granted a share by mistake still is not offered it');
$c = mcp_tool(REC, rtoken(951, $run), 'ar_aging_summary', []);
ok($c['error'] && str_contains($c['text'], 'kernel'), 'and cannot call it: ' . substr($c['text'], 0, 80));
ok(mcp_tool(REC_DEAD, $k(), 'ar_aging_summary', [], true)['error'] === false, 'the kernel needs no round trip to read a share');
finish();
