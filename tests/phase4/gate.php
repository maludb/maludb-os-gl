<?php
/**
 * Proof — the door. Who gets in and what they are offered: no token, a forged, an expired one -> 401; a person's own token, and the command bar's action token -> every tool (the three shares
 * are the kernel's alone); an agent's RUN token -> exactly the tools the kernel's run-facts call grants on THIS endpoint (fail closed: facts that say invalid, facts for another application, a kernel
 * that cannot be reached, an endpoint the agent was not granted); an agent the directory has not admitted is admitted at first contact only when the kernel vouches for it; an evaluation run may
 * read; the kernel's own token reaches app_roles and the three shares only. Servers: records :8398, activity :8399, records-with-a-dead-kernel :8400.
 */
require __DIR__ . '/lib.php';
p4world();
$run = fn () => random_int(700000, 799999);      // a fresh run id every time: the servers cache a run's facts
const SHARES = ['account_balances', 'ap_due_summary', 'ar_aging_summary'];

echo "1. Tokens that do not get in\n";
foreach ([REC, ACT] as $port) {
    $n = $port === REC ? 'records' : 'activity';
    ok(mcp_tools($port, '') === null, "$n: no token -> 401");
    ok(mcp_tools($port, 'mcp_' . str_repeat('0', 48)) === null, "$n: a made-up mcp_ token -> 401");
    $t = rtoken(951, $run());
    ok(mcp_tools($port, substr($t, 0, -1) . (substr($t, -1) === '0' ? '1' : '0')) === null, "$n: a run token with a bad signature -> 401");
    ok(mcp_tools($port, rtoken(951, $run(), -5)) === null, "$n: an expired run token -> 401");
    ok(mcp_tools($port, person_token(28, -5)) === null, "$n: an expired action token -> 401");
    ok(mcp_tools($port, person_token(9999)) === null, "$n: an action token for a member the mirror does not know -> 401 (never created)");
}
ok(one('SELECT count(*) FROM members WHERE id = 9999') == 0, 'and the unknown member was not created');

echo "2. People\n";
$rec = mcp_tools(REC, ptoken(27));
ok(is_array($rec) && count($rec) === 58 && !array_intersect($rec, SHARES), 'Bea (bookkeeper) is offered the 58 records tools — the three shares are not among them: ' . count($rec ?? []));
$act = mcp_tools(ACT, ptoken(27));
ok($act === ['activity_search', 'actor_timeline', 'changes_since', 'config_change_log', 'period_activity', 'record_history', 'who_touched'], 'and the 7 activity tools on the activity server');
ok(mcp_tools(REC, ptoken(26)) === $rec && mcp_tools(REC, person_token(26)) === $rec && mcp_tools(REC, person_token(1)) === $rec, 'a Viewer (own token), the command bar\'s action token and the owner get the same 58 (the views do the scoping)');
$r = mcp_tool(REC, person_token(27), 'find_customers', ['q' => 'Acme', 'include_archived' => false]);
ok(!$r['error'] && count($r['data']['customers'] ?? []) >= 1, 'the action token acts as its member: find_customers runs');
$me = mcp_tool(REC, person_token(27), 'my_notifications', []);
ok(!$me['error'] && isset($me['data']['notifications']), 'the action token acts as its member: my_notifications is Bea\'s');
ok(count(array_filter($rec, fn ($n) => in_array($n, ['find_accounts', 'trial_balance', 'get_invoice', 'unposted_entries', 'records_search', 'app_roles', 'cash_position']))) === 7, 'the key tools are among them');

echo "3. An agent sees exactly what the kernel granted on this endpoint\n";
make_agent(951, 'SMOKE Bookkeeper P4', ['bookkeeper', 'user']);
$r1 = $run();
facts($r1, 951, ['Records MCP' => ['unposted_entries' => [], 'find_invoices' => [], 'ar_aging' => []], 'Activity MCP' => ['record_history' => []]]);
$tok = rtoken(951, $r1);
ok(mcp_tools(REC, $tok) === ['ar_aging', 'find_invoices', 'unposted_entries'], 'records: exactly the three granted (' . implode(',', mcp_tools(REC, $tok) ?? []) . ')');
ok(mcp_tools(ACT, $tok) === ['record_history'], 'activity: exactly record_history — a grant on one endpoint is not a grant on the other');
$c = mcp_tool(REC, $tok, 'unposted_entries', []);
ok(!$c['error'] && isset($c['data']['waiting']), 'a granted tool answers (as the agent)');
$c = mcp_tool(REC, $tok, 'find_vendors', ['q' => 'Hosting']);
ok($c['error'] && str_contains($c['text'], 'not among the tools'), 'a tool it was not granted is refused: ' . substr($c['text'], 0, 90));
ok(mcp_tool(REC, $tok, 'records_search', ['sql' => 'select 1'])['error'], 'records_search is a tool like any other: refused unless granted');
ok(mcp_tool(ACT, $tok, 'activity_search', ['sql' => 'select 1'])['error'], 'and activity_search');
ok(mcp_tool(REC, $tok, 'account_balances', [])['error'], 'a share is never offered to an agent, granted or not');

echo "4. Fail closed\n";
$r = $run(); facts($r, 951, ['Records MCP' => ['unposted_entries' => []]], ['valid' => false]);
$t = rtoken(951, $r);
ok(mcp_tools(REC, $t) === [], 'the kernel says the token is not valid: an admitted agent is offered NOTHING');
ok(mcp_tool(REC, $t, 'unposted_entries', [])['error'], 'and cannot call anything');
$r = $run(); facts($r, 951, []);
ok(mcp_tools(REC, rtoken(951, $r)) === [], "facts with no endpoint for this application (another application's agent): nothing offered");
ok(mcp_tools(REC, rtoken(951, $run())) === [], 'a run the kernel does not know (valid:false by default): nothing offered');
$r = $run(); facts($r, 951, ['Records MCP' => ['unposted_entries' => []]], ['is_agent' => false]);
ok(count(mcp_tools(REC, rtoken(951, $r)) ?? []) === 58, 'facts that say is_agent false are a person: never filtered');
$r = $run(); facts($r, 951, ['Records MCP' => ['unposted_entries' => []]]);
$t = rtoken(951, $r);
ok(mcp_tools(REC_DEAD, $t) === [], 'the kernel cannot be reached (:8400, dead URL): an admitted agent is offered nothing');
ok(mcp_tool(REC_DEAD, $t, 'unposted_entries', [])['error'], 'and cannot call');
ok(count(mcp_tools(REC_DEAD, ptoken(27)) ?? []) === 58 && count(mcp_tools(REC_DEAD, person_token(27)) ?? []) === 58, "while a person's own token and the command bar's action token still work with no kernel");

echo "5. An agent the directory has not admitted yet\n";
make_agent(953, 'SMOKE Unadmitted P4', ['bookkeeper', 'user'], null);
ok(one('SELECT capability FROM members WHERE id = 953') === null, 'agent 953 is in the mirror with no capability');
$r = $run(); $t = rtoken(953, $r);
ok(mcp_tools(REC, $t) === null, 'a run the kernel does not vouch for: 401, and it is not admitted');
ok(one('SELECT capability FROM members WHERE id = 953') === null, '...capability still null');
facts($r, 953, ['Records MCP' => ['unposted_entries' => [], 'find_invoices' => []]]);
ok(mcp_tools(REC, $t) === ['find_invoices', 'unposted_entries'], 'the kernel vouches for it with endpoints here: admitted at first contact, offered its two tools');
ok(one('SELECT capability FROM members WHERE id = 953') === 'write', '...and its capability is now write');
make_agent(956, 'SMOKE Unadmitted P4 B', ['bookkeeper', 'user'], null);
$r = $run(); facts($r, 956, []);
ok(mcp_tools(REC, rtoken(956, $r)) === null && one('SELECT capability FROM members WHERE id = 956') === null, 'facts with NO endpoint here do not admit: 401, capability still null');
$r = $run(); facts($r, 956, ['Records MCP' => ['unposted_entries' => []]], ['valid' => false]);
ok(mcp_tools(REC, rtoken(956, $r)) === null && one('SELECT capability FROM members WHERE id = 956') === null, 'facts valid:false do not admit');
$r = $run(); $t = rtoken(956, $r); facts($r, 956, ['Records MCP' => ['unposted_entries' => []]]);
ok(mcp_tools(REC_DEAD, $t) === null && one('SELECT capability FROM members WHERE id = 956') === null, 'a dead kernel does not admit either (401)');
$r = $run(); facts($r, 957, ['Records MCP' => ['unposted_entries' => []]]);
ok(mcp_tools(REC, rtoken(957, $r)) === null && one('SELECT count(*) FROM members WHERE id = 957') == 0, 'a run token for a member the mirror does not know -> 401 even when the kernel vouches; 957 is never created');
q("INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (958, 'human', 'SMOKE Human-like P4', 'user', 'active', NULL, '{user}') ON CONFLICT (id) DO NOTHING");
$r = $run(); facts($r, 958, ['Records MCP' => ['unposted_entries' => []]]);
ok(mcp_tools(REC, rtoken(958, $r)) === null && one('SELECT capability FROM members WHERE id = 958') === null, 'a HUMAN is never admitted this way (their capability comes with the hand-off claims)');

echo "6. An evaluation may read\n";
$r = $run(); facts($r, 951, ['Records MCP' => ['find_invoices' => [], 'unposted_entries' => []]], ['trigger' => 'eval']);
$t = rtoken(951, $r);
ok(mcp_tools(REC, $t) === ['find_invoices', 'unposted_entries'] && !mcp_tool(REC, $t, 'unposted_entries', [])['error'], 'an eval run reads (the tools never write: nothing an eval could change)');

echo "7. The kernel's own token\n";
$k = ktoken();
ok(mcp_tools(REC, $k) === ['account_balances', 'ap_due_summary', 'app_roles', 'ar_aging_summary'], 'records: app_roles and the three shares, nothing else');
foreach (['find_invoices' => [], 'records_search' => ['sql' => 'select 1'], 'trial_balance' => [], 'get_vendor' => ['vendor_id' => 1]] as $tn => $args) {
    $c = mcp_tool(REC, $k, $tn, $args);
    ok($c['error'] && str_contains($c['text'], 'reaches'), "the kernel's token calling $tn: refused");
}
$c = mcp_tool(REC, $k, 'app_roles', []);
ok(!$c['error'] && ($c['data']['schema'] ?? '') === 'os.app-roles/1', "the kernel's token calling app_roles: answers os.app-roles/1");
ok(count($c['data']['roles'] ?? []) === 5 && count($c['data']['rights'] ?? []) === 14, 'five roles and fourteen rights');
ok(mcp_tools(REC, ktoken('hr')) === null, 'a kernel token for another application (hr) -> 401');
ok(mcp_tools(REC, ktoken('gl', -5)) === null, 'an expired kernel token -> 401');
$bad = ktoken(); ok(mcp_tools(REC, substr($bad, 0, -1) . (substr($bad, -1) === '0' ? '1' : '0')) === null, 'a kernel token with a bad signature -> 401');
ok(mcp_tools(REC_DEAD, ktoken()) === ['account_balances', 'ap_due_summary', 'app_roles', 'ar_aging_summary'], "the kernel's token needs no kernel round-trip (:8400)");
$c = mcp_tool(REC, ptoken(26), 'app_roles', []);
ok(!$c['error'] && ($c['data']['schema'] ?? '') === 'os.app-roles/1', 'any person may call app_roles (the catalogue is about no one)');
$r = $run(); facts($r, 951, ['Records MCP' => ['app_roles' => []]]);
ok(!mcp_tool(REC, rtoken(951, $r), 'app_roles', [])['error'], 'and an agent granted it');
ok(mcp_tools(ACT, $k) === [], "the activity server offers the kernel's token nothing");
finish();
