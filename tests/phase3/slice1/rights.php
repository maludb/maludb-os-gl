<?php
/** Proof — rights (spec "Proof", 1): a Viewer and a Bookkeeper read the chart and the periods and are refused every form, archive, close and setting in the right's sentence; the Controller closes and reopens; the admin everything. */
require __DIR__ . '/lib.php';
$vera = as_member(26); $bea = as_member(27); $carl = as_member(28); $ann = as_member(29); $owner = as_member(1);
echo "1. Readers\n";
$reads = ['/accounts/', '/accounts/' . account_id('1200'), '/periods/'];
$wrong = [];
foreach ([$vera, $bea, $carl, $ann, $owner] as $i => $j) { foreach ($reads as $p) { $c = page($j, $p)['code']; if ($c !== 200) { $wrong[] = "$i $p $c"; } } }
ok($wrong === [], 'the chart, an account and the periods list answer 200 to every role' . ($wrong ? ' — wrong: ' . implode('; ', $wrong) : ''));
$forms = ['/accounts/new', '/accounts/' . account_id('1200') . '/edit', '/periods/new', '/settings/tax-rates/', '/settings/tax-rates/new', '/settings/items/', '/settings/items/new', '/settings/posting-rules/',
    '/settings/sites/', '/settings/sites/new', '/settings/general', '/settings/numbering', '/settings/agents'];
$wrong = [];
foreach ([$vera, $bea, $carl, $ann] as $i => $j) { foreach ($forms as $p) { $r = page($j, $p); if ($r['code'] !== 403 || !str_contains(html_entity_decode($r['body'], ENT_QUOTES), "You may not change the ledger's settings.")) { $wrong[] = "$i $p {$r['code']}"; } } }
ok($wrong === [], count($forms) . ' forms and settings → 403 for a Viewer, a Bookkeeper, the Controller and the Accountant, in the right\'s sentence' . ($wrong ? ' — wrong: ' . implode('; ', array_slice($wrong, 0, 5)) : ''));
$writes = ['/accounts/save.php' => ['code' => '9999', 'name' => 'x', 'account_type' => 'expense'], '/accounts/archive.php' => ['account' => 1], '/periods/save.php' => ['start' => '2030-01-01'], '/settings/tax-rates/save.php' => ['name' => 'x', 'rate' => 1],
    '/settings/tax-rates/archive.php' => ['tax_rate' => 1], '/settings/items/save.php' => ['name' => 'x'], '/settings/items/archive.php' => ['item' => 1], '/settings/posting-rules/save.php' => ['rule' => 'invoice'], '/settings/sites/save.php' => ['name' => 'x'],
    '/settings/sites/archive.php' => ['site' => 1], '/settings/general.php' => ['business_name' => 'x'], '/settings/numbering.php' => ['kind' => 'invoice', 'padding' => 5]];
$wrong = [];
foreach ([$vera, $bea, $carl, $ann] as $i => $j) { foreach ($writes as $p => $f) { [$c, $b] = act($j, $p, $f); if ($c !== 403 || msg($b) !== "You may not change the ledger's settings.") { $wrong[] = "$i $p $c"; } } }
ok($wrong === [], count($writes) . ' configuration writes → 403 for the four, "You may not change the ledger\'s settings."' . ($wrong ? ' — wrong: ' . implode('; ', array_slice($wrong, 0, 5)) : ''));
echo "2. Periods\n";
[$c, $b] = act($owner, '/periods/save.php', ['start' => '2026-01-01']);
ok($c === 200 && ($b['ok'] ?? false), 'the admin opens FY2026');
$jan = period_id('2026-01');
foreach ([[$vera, 'Vera'], [$bea, 'Bea']] as [$j, $n]) {
    [$c, $b] = act($j, '/periods/close.php', ['period' => $jan]);
    ok($c === 403 && msg($b) === 'You may not close or reopen a period.', "$n closing a period: 403 \"You may not close or reopen a period.\"");
}
[$c, $b] = act($carl, '/periods/close.php', ['period' => $jan]);
ok($c === 200 && (string) one('SELECT status FROM fiscal_periods WHERE id = :p', ['p' => $jan]) === 'closed', 'the Controller closes it');
[$c, $b] = act($ann, '/periods/reopen.php', ['period' => $jan, 'reason' => 'SMOKE the accountant reopens']);
ok($c === 200 && (string) one('SELECT status FROM fiscal_periods WHERE id = :p', ['p' => $jan]) === 'open', 'the Accountant (periods.manage) reopens it with a reason');
[$c, $b] = act($bea, '/periods/year-close.php', ['fiscal_year' => fy_id()]);
ok($c === 403, 'a Bookkeeper closing the year: 403');
$body = page($vera, '/periods/' . $jan)['body'];
ok(!str_contains($body, 'id="period-view-close-btn"') && !str_contains($body, 'id="period-view-reopen-btn"'), 'a Viewer\'s period page shows no Close or Reopen button');
$body = page($carl, '/periods/' . $jan)['body'];
ok(str_contains($body, 'id="period-view-close-btn"'), 'the Controller\'s shows Close');
$body = page($vera, '/accounts/')['body'];
ok(!str_contains($body, 'id="accounts-list-add-btn"') && !str_contains($body, '-edit-btn"') && str_contains($body, 'id="account-card-' . account_id('1200') . '"'), 'a Viewer\'s chart has the cards, no Add and no Change');
$body = page($owner, '/accounts/')['body'];
ok(str_contains($body, 'id="accounts-list-add-btn"') && str_contains($body, 'id="account-card-' . account_id('1200') . '-edit-btn"'), 'the admin\'s has both');
finish();
