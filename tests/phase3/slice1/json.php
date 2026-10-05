<?php
/** Proof — JSON mode (spec "Proof", 6): every handler answers {ok, did, record_id, location} to an action token and 422 {error:{code invalid, errors[], fields{}}} on a bad form; _partial=1 fills the base row; a run token's rows are source agent and the rights hold. */
require __DIR__ . '/lib.php';
$admin = ['X-Action-Token: ' . person_token(1)];
$ctrl = ['X-Action-Token: ' . person_token(28)];
if (one("SELECT count(*) FROM fiscal_years") == 0) { act_token('/periods/save.php', ['start' => '2026-01-01'], $admin); }
echo "1. Creates under an action token: {ok, did, record_id, location ending in the id}\n";
$creates = [
    ['/accounts/save.php', ['code' => '7100', 'name' => 'SMOKE Token account', 'account_type' => 'expense'], $admin, '#^/accounts/(\d+)\?notice=saved$#'],
    ['/settings/tax-rates/save.php', ['name' => 'SMOKE Token rate', 'rate' => '5'], $admin, '#^/settings/tax-rates/\?notice=saved\#tax-rate-row-(\d+)$#'],
    ['/settings/items/save.php', ['name' => 'SMOKE Token item', 'unit_price' => '10'], $admin, '#^/settings/items/\?notice=saved\#item-card-(\d+)$#'],
    ['/settings/sites/save.php', ['name' => 'SMOKE Token site'], $admin, '#^/settings/sites/\?notice=saved\#site-card-(\d+)$#'],
    ['/settings/posting-rules/save.php', ['rule' => 'payment', 'match_key' => 'smoke-token', 'debit_account' => account_id('2000'), 'credit_account' => account_id('1010')], $admin, '#^/settings/posting-rules/\?notice=saved\#posting-rule-row-(\d+)$#'],
];
$ids = [];
foreach ($creates as [$path, $form, $h, $re]) {
    [$c, $b, $r] = act_token($path, $form, $h);
    $m = [];
    $okc = $c === 200 && ($b['ok'] ?? false) && isset($b['did']) && preg_match($re, (string) ($b['location'] ?? ''), $m) && (int) $m[1] === (int) ($b['record_id'] ?? -1) && !str_contains($r['headers'], 'Set-Cookie');
    ok($okc, "$path → {ok, did, record_id " . ($b['record_id'] ?? '?') . ", location " . ($b['location'] ?? '?') . '}' . ($okc ? '' : " ($c " . msg($b) . ')'));
    $ids[$path] = (int) ($b['record_id'] ?? 0);
}
$acct = $ids['/accounts/save.php']; $rate = $ids['/settings/tax-rates/save.php']; $item = $ids['/settings/items/save.php']; $site = $ids['/settings/sites/save.php'];
echo "2. Updates, archives, settings and the period actions\n";
$jan = period_id('2026-01');
$writes = [
    ['/accounts/save.php', ['account' => $acct, 'description' => 'SMOKE changed'], $admin],
    ['/accounts/archive.php', ['account' => $acct], $admin],
    ['/accounts/archive.php', ['account' => $acct, 'archived' => 'no'], $admin],
    ['/settings/tax-rates/save.php', ['tax_rate' => $rate, 'rate' => '6'], $admin],
    ['/settings/tax-rates/archive.php', ['tax_rate' => $rate], $admin],
    ['/settings/items/save.php', ['item' => $item, 'unit' => 'each'], $admin],
    ['/settings/items/archive.php', ['item' => $item], $admin],
    ['/settings/sites/save.php', ['site' => $site, 'address' => 'SMOKE 2 Side St'], $admin],
    ['/settings/sites/archive.php', ['site' => $site], $admin],
    ['/settings/general.php', ['bill_terms_days' => '21'], $admin],
    ['/settings/numbering.php', ['kind' => 'deposit', 'padding' => '7'], $admin],
];
$writes = array_merge($writes, (string) one('SELECT status FROM fiscal_periods WHERE id = :p', ['p' => $jan]) === 'open'
    ? [['/periods/close.php', ['period' => $jan], $ctrl], ['/periods/reopen.php', ['period' => $jan, 'reason' => 'SMOKE token reopen'], $ctrl]]
    : [['/periods/reopen.php', ['period' => $jan, 'reason' => 'SMOKE token reopen'], $ctrl], ['/periods/close.php', ['period' => $jan], $ctrl], ['/periods/reopen.php', ['period' => $jan, 'reason' => 'SMOKE token reopen again'], $ctrl]]);
$wrong = [];
foreach ($writes as [$path, $form, $h]) {
    [$c, $b] = act_token($path, $form, $h);
    if ($c !== 200 || !($b['ok'] ?? false) || !isset($b['location']) || !isset($b['did'])) { $wrong[] = "$path $c " . msg($b); }
}
ok($wrong === [], count($writes) . ' more handlers answer {ok, did, location}' . ($wrong ? ' — wrong: ' . implode('; ', $wrong) : ''));
act_token('/settings/general.php', ['bill_terms_days' => '30'], $admin);
echo "3. A bad form: 422 {error: {code invalid, errors[], fields{}}}\n";
[$c, $b] = act_token('/accounts/save.php', ['code' => 'x', 'name' => '', 'account_type' => 'magic'], $admin);
$f = fields($b);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($f['code'], $f['name'], $f['account_type']) && count($b['error']['errors']) === 3, 'account_save with three bad fields: 422, three field errors (' . implode(', ', array_keys($f)) . ')');
[$c, $b] = act_token('/periods/reopen.php', ['period' => $jan, 'reason' => 'x'], $admin);
ok($c === 422 && str_contains(msg($b), 'is open'), 'a database refusal under a token is a 422 in its words (' . msg($b) . ')');
[$c, $b] = act_token('/accounts/save.php', ['code' => '7200', 'name' => 'SMOKE Not admin', 'account_type' => 'expense'], $ctrl);
ok($c === 403 && ($b['error']['code'] ?? '') === 'forbidden', 'the rights hold under a token: the Controller creating an account → 403');
[$c, $b] = act_token('/accounts/save.php', ['code' => '7200', 'name' => 'SMOKE Expired', 'account_type' => 'expense'], ['X-Action-Token: ' . person_token(1, -5)]);
ok($c === 401, 'an expired token: 401');
echo "4. The `any field of` partial update (the kernel's _partial)\n";
$a2 = (int) act_token('/accounts/save.php', ['code' => '7300', 'name' => 'SMOKE Partial', 'account_type' => 'expense', 'subtype' => 'operating', 'description' => 'SMOKE keep me'], $admin)[1]['record_id'];
[$c, $b] = act_token('/accounts/save.php', ['account' => $a2, 'name' => 'SMOKE Partial renamed', '_partial' => '1'], $admin);
ok($c === 200 && q('SELECT name, description, subtype FROM accounts WHERE id = :a', ['a' => $a2])[0] == ['name' => 'SMOKE Partial renamed', 'description' => 'SMOKE keep me', 'subtype' => 'operating'], 'account_save with _partial=1: the base row fills what was not sent');
$i2 = (int) act_token('/settings/items/save.php', ['name' => 'SMOKE Partial item', 'unit' => 'hour', 'unit_price' => '50'], $admin)[1]['record_id'];
[$c, $b] = act_token('/settings/items/save.php', ['item' => $i2, 'unit_price' => '60', '_partial' => '1'], $admin);
ok($c === 200 && q('SELECT name, unit, unit_price FROM items WHERE id = :i', ['i' => $i2])[0] == ['name' => 'SMOKE Partial item', 'unit' => 'hour', 'unit_price' => '60.00'], 'item_save with _partial=1 too');
$s2 = (int) act_token('/settings/sites/save.php', ['name' => 'SMOKE Partial site', 'address' => 'SMOKE keep'], $admin)[1]['record_id'];
[$c, $b] = act_token('/settings/sites/save.php', ['site' => $s2, 'kernel_location' => '9', '_partial' => '1'], $admin);
ok($c === 200 && q('SELECT name, address, kernel_location_id FROM sites WHERE id = :s', ['s' => $s2])[0] == ['name' => 'SMOKE Partial site', 'address' => 'SMOKE keep', 'kernel_location_id' => 9], 'site_save with _partial=1 too');
echo "5. An agent's run token obeys the rights and is logged as the run\n";
kernel_state(function ($st) { $st['facts']['88'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 88, 'request_id' => 'req-run-88', 'trigger' => 'duty', 'endpoints' => [['name' => 'Records MCP']]]; return $st; });
[$tok, $relay] = run_token(40, 88);
$h = ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay];
[$c, $b] = act_token('/accounts/save.php', ['code' => '7400', 'name' => 'SMOKE Agent account', 'account_type' => 'expense'], $h);
ok($c === 403, 'the Bookkeeper agent (no settings.manage) creating an account: 403');
[$c, $b] = act_token('/periods/close.php', ['period' => $jan], $h);
ok($c === 403 && msg($b) === 'You may not close or reopen a period.', 'nor closing a period: 403 in the right\'s sentence');
$since = last_activity_id();
$r = req('GET', '/accounts/', ['headers' => array_merge(JSONH, $h, ['X-Screen-View: 1'])]);
ok($r['code'] === 200 && count(json_decode($r['body'], true)['data']['accounts']) > 20, 'it reads the chart (reports.view): 200');
$row = q("SELECT source, agent_run_id, request_id FROM activity_log WHERE action = 'screen.view' AND id > :s ORDER BY id DESC LIMIT 1", ['s' => $since])[0] ?? [];
ok(($row['source'] ?? '') === 'agent' && (int) ($row['agent_run_id'] ?? 0) === 88 && ($row['request_id'] ?? '') === 'req-run-88', 'its row is source agent, run 88, the run\'s request id');
kernel_state(function ($st) { unset($st['facts']); return $st; });
finish();
