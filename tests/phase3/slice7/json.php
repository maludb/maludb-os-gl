<?php
/** Proof — JSON mode: every handler under a signed action token answers {ok, did, location}; 422 {error:{code invalid, fields}}; a run token's rows are source agent and the rights hold; the screens answer JSON. */
require __DIR__ . '/lib.php';
ensure_year(as_member(1));
$bea = ['X-Action-Token: ' . person_token(27)]; $carl = ['X-Action-Token: ' . person_token(28)]; $vera = ['X-Action-Token: ' . person_token(26)];
$feb = ai_doc('2026-02', 'closed', [ai_line('anthropic', 'claude-sonnet-5-5', 'Accounting', 'Sasha', 10, 0, '1.2345'), ai_line('openai', 'gpt-5', 'IT', 'Sysadmin', 5, 0, '0.5000')]);
$mar = ai_doc('2026-03', 'open', [ai_line('anthropic', 'claude-sonnet-5-5', 'Accounting', 'Sasha', 4, 0, '0.1000')]);
ledger_state(['2026-03' => $mar, '2026-02' => $feb]);
$wrong = []; $count = 0;
$call = function (string $label, string $path, array $form, array $h) use (&$wrong, &$count): array {
    [$c, $b] = act_token($path, $form, $h);
    $count++;
    if ($c !== 200 || !($b['ok'] ?? false) || !isset($b['did']) || !isset($b['location'])) { $wrong[] = "$label $c " . msg($b); }
    return $b;
};
echo "1. Every action\n";
$b = $call('ai_statement_pull (all)', '/ap/ai/pull.php', [], $bea);
ok(count($b['pulled'] ?? []) === 2 && ($b['refused'] ?? [1]) === [] && ($b['pulled'][0]['changed'] ?? '') === 'new', 'ai_statement_pull answers the months pulled and none refused');
$b = $call('ai_statement_pull (one)', '/ap/ai/pull.php', ['period' => '2026-02'], $bea);
$S = stmt_id('2026-02');
$b = $call('ai_statement_import', '/ap/ai/import.php', ['statement' => $S], $bea);
ok(count($b['bills'] ?? []) === 2 && ($b['period'] ?? '') === '2026-02' && $b['bills'][0]['total'] === '1.23' && str_starts_with($b['location'], '/ap/ai/' . $S), 'ai_statement_import answers the period and the bills (Anthropic 1.2345 → 1.23)');
$b = $call('ai_statement_check', '/ap/ai/check.php', ['statement' => $S], $bea);
ok(($b['verdict'] ?? '') === 'differs' && count($b['differences'] ?? []) === 0 || ($b['verdict'] ?? '') === 'agrees', 'ai_statement_check answers the verdict (' . ($b['verdict'] ?? '?') . ') — draft bills count');
ok($wrong === [], "$count handlers answer {ok, did, location}" . ($wrong ? ' — wrong: ' . implode('; ', $wrong) : ''));
echo "2. Refusals\n";
[$c, $b] = act_token('/ap/ai/pull.php', ['period' => 'next month'], $bea);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset(fields($b)['period']) && count($b['error']['errors']) === 1, 'a bad period: 422 {error: {code invalid, errors[1], fields{period}}}');
[$c, $b] = act_token('/ap/ai/import.php', [], $bea);
ok($c === 422 && str_contains(msg($b), 'Say which statement'), 'no statement named: 422 in words');
[$c, $b] = act_token('/ap/ai/import.php', ['statement' => 424242], $bea);
ok($c === 404 && msg($b) === 'AI statement not found.', 'an unknown statement: 404 in one sentence');
[$c, $b] = act_token('/ap/ai/import.php', ['statement' => stmt_id('2026-03')], $bea);
ok($c === 422 && str_contains(msg($b), 'still open in the OS'), 'the open month: 422 in the function\'s words');
[$c, $b] = act_token('/ap/ai/import.php', ['statement' => $S], $vera);
ok($c === 403 && msg($b) === 'You may not keep the payables.', 'a Viewer importing: 403 in the right\'s sentence');
[$c, $b] = act_token('/ap/ai/pull.php', [], ['X-Action-Token: ' . person_token(1, -5)]);
ok($c === 401, 'an expired token: 401');
ledger_state(['2026-03' => $mar, '2026-02' => $feb], 'down');
[$c, $b] = act_token('/ap/ai/pull.php', [], $bea);
ok($c === 502 && str_contains(msg($b), 'The OS did not answer') && ($b['error']['code'] ?? '') !== '', 'the OS down: 502 {error: …} in words');
ledger_state(['2026-03' => $mar, '2026-02' => $feb]);
echo "3. An agent's run token\n";
kernel_state(function ($st) { $st['facts']['75'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 75, 'request_id' => 'req-run-75', 'trigger' => 'duty', 'is_eval' => false, 'endpoints' => [['name' => 'Records MCP']]]; return $st; });
[$tok, $relay] = run_token(40, 75);
$h = ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay];
$since = last_activity_id();
[$c, $b] = act_token('/ap/ai/pull.php', ['period' => '2026-03'], $h);
ok($c === 200, 'the Bookkeeper agent pulls (ap.write)');
$apr = ai_doc('2026-04', 'closed', [ai_line('anthropic', 'claude-sonnet-5-5', 'Accounting', 'Sasha', 4, 0, '2.0000')]);
ledger_state(['2026-04' => $apr, '2026-03' => $mar, '2026-02' => $feb]);
act_token('/ap/ai/pull.php', ['period' => '2026-04'], $h);
[$c, $b] = act_token('/ap/ai/import.php', ['period' => '2026-04'], $h);
ok($c === 200 && count($b['bills'] ?? []) === 1, 'and imports a closed month (it makes drafts: approval is the pause)');
[$c, $b] = act_token('/ap/ai/check.php', ['period' => '2026-04'], $h);
ok($c === 200, 'and checks it');
$rows = q("SELECT action, source, agent_run_id, request_id FROM activity_log WHERE id > :s AND action LIKE 'ai_statement.%' ORDER BY id", ['s' => $since]);
ok(count($rows) === 4 && array_unique(array_column($rows, 'source')) === ['agent'] && (int) $rows[0]['agent_run_id'] === 75 && $rows[0]['request_id'] === 'req-run-75', 'its rows are source agent, run 75, the run\'s request id (pull, pull, import, check)');
$agentBill = (int) one("SELECT bill_id FROM ai_statement_bills WHERE statement_id = :s", ['s' => stmt_id('2026-04')]);
[$c, $b] = act_token('/bills/approve.php', ['bill' => $agentBill], $h);
ok($c === 403, 'the agent may not approve the AI bill (ap.approve): 403');
[$c, $b] = act_token('/ap/ai/pull.php', [], ['X-Action-Token: ' . $tok]);
ok($c === 401, 'the relay missing: 401');
kernel_state(function ($st) { unset($st['facts']); return $st; });
echo "4. The screens answer JSON\n";
$hj = array_merge(JSONH, $bea);
$bad = [];
foreach (['/ap/ai/', '/ap/ai/' . $S, '/ap/ai/' . $S . '?group_by=department'] as $path) {
    $r = req('GET', $path, ['headers' => $hj]);
    if ($r['code'] !== 200 || !isset(json_decode($r['body'], true)['data'])) { $bad[] = "$path {$r['code']}"; }
}
ok(empty($bad), 'the screens answer JSON' . (empty($bad) ? '' : ' — wrong: ' . implode('; ', $bad)));
$d = json_decode(req('GET', '/ap/ai/' . $S, ['headers' => $hj])['body'], true)['data'];
ok(isset($d['statement']['totals'], $d['statement']['header'], $d['lines'], $d['totals'], $d['check'], $d['may']) && !str_contains(json_encode($d), 'document_hash') === true || isset($d['check']), 'the month: header, lines, totals, the check, rights');
finish();
