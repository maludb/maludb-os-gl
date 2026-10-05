<?php
/**
 * Proof — the application's registration is whole and consistent: the action registry regenerates from the manifest (124 actions, 69 pausing, 92 screens built) with the approval kinds maludb-os.json
 * declares; every log event an approval policy will match is one the handlers really write; the kernel-side wrapper's resolve block names real tools that answer a plain LIST when given `q` alone; the
 * two agents' grants name real tools and none can do what it should not; the endpoints, shares, services, skills and job descriptions the manifest lists exist; the deploy units start the two servers;
 * the installer's read-only plan sees no stop.
 */
require __DIR__ . '/lib.php';
$W = p4world();
$m = json_decode(file_get_contents('maludb-os.json'), true);
$reg = json_decode(file_get_contents('mcp/action_registry.json'), true);

echo "1. The registry regenerates\n";
$tmp = sys_get_temp_dir() . '/gl-reg-' . getmypid();
@mkdir("$tmp/bin", 0777, true); @mkdir("$tmp/mcp", 0777, true);
copy('bin/build_action_registry.php', "$tmp/bin/build_action_registry.php");
symlink(realpath('docs'), "$tmp/docs"); symlink(realpath('html'), "$tmp/html");
shell_exec('php ' . escapeshellarg("$tmp/bin/build_action_registry.php") . ' 2>&1');
$new = json_decode((string) @file_get_contents("$tmp/mcp/action_registry.json"), true);
ok(is_array($new) && count($new['actions']) === 124, 'built from docs/gl-action-manifest.md into a scratch copy: ' . count($new['actions'] ?? []) . ' actions');
$strip = function (array $r): array { unset($r['generated_at']); return $r; };
ok($strip($new) == $strip($reg), 'the committed mcp/action_registry.json is exactly what the builder writes today (bar its timestamp)');
exec('rm -rf ' . escapeshellarg($tmp));
ok(str_contains((string) shell_exec('php bin/build_action_registry.php --check 2>&1; echo "exit=$?"'), 'exit=0'), 'bin/build_action_registry.php --check: current');
ok(count(array_filter($reg['actions'], fn ($a) => $a['built'])) === 124 && count($reg['screens']) === 92 && count(array_filter($reg['screens'], fn ($s) => $s['built'])) === 92, '124 of 124 actions and 92 of 92 screens built');
$pausing = array_filter($reg['actions'], fn ($a) => $a['approval']);
ok(count($pausing) === 69, '69 actions carry an approval category for an agent: ' . count($pausing));
$kinds = array_count_values(array_column($pausing, 'approval'));
ok(($kinds['money_out'] ?? 0) === 33 && ($kinds['other'] ?? 0) === 21 && ($kinds['deletion'] ?? 0) === 12 && ($kinds['external_send'] ?? 0) === 3, 'money_out 33, other 21, deletion 12, external_send 3: ' . json_encode($kinds));
$cat = array_column($m['approvals'], 'category', 'action');
ok(count($m['approvals']) === 69 && array_map(fn ($a) => $a['approval'], $pausing) == $cat, "maludb-os.json approvals[] (69 entries) equal the registry's categories, action for action");
foreach (['invoice_send' => 'external_send', 'invoice_remind' => 'external_send', 'statement_send' => 'external_send', 'bill_approve' => 'money_out', 'journal_entry_post' => 'money_out', 'receipt_record' => 'money_out', 'payment_record' => 'money_out', 'period_close' => 'other', 'settings_save' => 'other', 'invoice_delete' => 'deletion'] as $a => $k) {
    ok(($reg['actions'][$a]['approval'] ?? '') === $k, "$a pauses as $k");
}
foreach (['customer_save', 'vendor_save', 'invoice_draft', 'invoice_line_save', 'bill_draft', 'journal_entry_draft', 'journal_line_save', 'note_add', 'ai_statement_pull', 'ai_statement_import', 'recurring_run', 'morning_note_send', 'bank_transaction_match'] as $a) {
    ok(isset($reg['actions'][$a]) && !$reg['actions'][$a]['approval'], "$a never pauses: an agent drafts, imports, matches and notes freely");
}
$src = '';
foreach (['html', 'app'] as $dir) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) { if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) { $src .= file_get_contents($f->getPathname()); } } }
$missing = [];
foreach ($pausing as $k => $a) { if (!str_contains($src, "'" . $a['log_event'] . "'")) { $missing[] = "$k:{$a['log_event']}"; } }
ok($missing === [], 'every pausing action\'s log event is one the application writes' . ($missing ? ' — NOT: ' . implode(', ', $missing) : ''));

echo "2. The kernel-side wrapper's resolve block\n";
$w = json_decode(file_get_contents('deploy/kernel-registry-gl.json'), true);
$recTools = mcp_tools(REC, ptoken(28));
$params = [];
foreach ($reg['actions'] as $a) { foreach ($a['params'] as $p) { if ($p['name'] !== null) { $params[$p['name']] = ($params[$p['name']] ?? false) || $p['repeated']; } } }
ok(count($w['resolve']) === 24, 'twenty-four entities resolve');
$bad = [];
foreach ($w['resolve'] as $ent => $spec) {
    if (!in_array($spec['tool'], $recTools, true) || $spec['query_param'] !== 'q') { $bad[] = "$ent tool"; }
    foreach ($spec['params'] as $p) { if (!isset($params[$p]) || $params[$p]) { $bad[] = "$ent:$p"; } }
}
ok($bad === [], 'every entity names a tool of the records server and real, non-repeated action params' . ($bad ? ' — NOT: ' . implode(', ', $bad) : ''));
$num = fn (string $t, int $id): string => (string) one("SELECT number FROM $t WHERE id = :i", ['i' => $id]);
act(as_member(1), '/settings/items/save.php', ['code' => 'REG-1', 'name' => 'SMOKE Reg Widget', 'kind' => 'product', 'unit' => 'each', 'unit_price' => '5.00', 'income_account' => account_id('4100')]);
$samples = ['account' => '6200', 'customer' => 'Acme', 'vendor' => 'Hosting', 'invoice' => $num('invoices', $W['inv_a1']), 'bill' => 'H-2026-10', 'journal_entry' => (string) one('SELECT number FROM journal_entries WHERE id = :i', ['i' => $W['capital']]),
            'receipt' => 'SMOKE-CHQ-1', 'payment' => '7001', 'deposit' => $num('deposits', $W['deposit']), 'payment_run' => 'SMOKE', 'credit_note' => $num('credit_notes', $W['credit']), 'vendor_credit' => 'VC', 'bank_account' => 'Checking',
            'bank_transaction' => 'SMOKE-REF-75', 'period' => '2026-02', 'fiscal_year' => 'FY2026', 'item' => 'Reg Widget', 'tax_rate' => 'Sales', 'ai_statement' => '2026-09', 'reconciliation' => '2026-09', 'recurring' => 'rent',
            'member' => 'Carl', 'department' => 'Account', 'site' => 'Main'];
$mayBeEmpty = ['vendor_credit', 'reconciliation', 'site', 'payment_run', 'department'];
foreach ($w['resolve'] as $ent => $spec) {
    $r = mcp_tool(REC, ptoken(1), $spec['tool'], ['q' => $samples[$ent]]);
    $rows = is_array($r['data']) && array_is_list($r['data']) ? $r['data'] : null;
    $withId = $rows === null ? [] : array_values(array_filter($rows, fn ($x) => isset($x[$spec['id_field']])));
    $shape = $rows !== null && (in_array($ent, $mayBeEmpty, true) ? true : (count($withId) >= 1 && isset($withId[0][$spec['label_field']])));
    ok($shape && ($rows === [] || isset($withId[0][$spec['label_field']])), "$ent: {$spec['tool']}(q='{$samples[$ent]}') answers a plain list whose rows carry {$spec['id_field']} and {$spec['label_field']} (" . count($withId) . ')');
}
ok(count(mcp_tool(REC, ptoken(1), 'find_invoices', ['q' => 'zzzznothing'])['data'] ?? [1]) === 0, 'a name nobody has resolves to an empty list ("No invoice matching…" from the kernel)');

echo "3. The two agents\n";
$actionNames = array_keys($reg['actions']);
$actTools = mcp_tools(ACT, ptoken(28));
$agents = array_column($m['agents'], null, 'key');
ok(array_keys($agents) === ['expert', 'bookkeeper'] && !array_filter($agents, fn ($a) => !$a['hired_on_install'] || $a['access_capability'] !== 'write' || $a['roles'] !== ['bookkeeper']), 'expert and bookkeeper: hired on install at write capability, the Bookkeeper role');
$unknown = [];
foreach ($agents as $key => $a) {
    foreach ($a['tool_grants'] as $endpoint => $tools) {
        $known = $endpoint === 'Records MCP' ? $recTools : ($endpoint === 'Activity MCP' ? $actTools : $actionNames);
        foreach ($tools as $t) { if (!in_array($t, $known, true)) { $unknown[] = "$key:$endpoint:$t"; } }
    }
}
ok($unknown === [], 'every granted tool is a real tool of its endpoint' . ($unknown ? ' — NOT: ' . implode(', ', $unknown) : ''));
$grants = fn (string $k) => array_merge(...array_values($agents[$k]['tool_grants']));
$forbid = array_merge(array_keys(array_filter($reg['actions'], fn ($a) => $a['approval'] === 'deletion')), ['period_close', 'period_reopen', 'year_close', 'year_reopen', 'bill_approve', 'payment_approve', 'payment_run_approve', 'payment_record', 'payment_run_record', 'vendor_credit_issue',
    'invoice_void', 'invoice_write_off', 'bill_void', 'payment_void', 'receipt_void', 'journal_entry_reverse', 'settings_save', 'account_save', 'numbering_save', 'posting_rule_save', 'tax_rate_save', 'item_save', 'bank_account_save', 'reconciliation_complete', 'token_mint', 'transfer_record']);
$held = array_values(array_intersect(array_unique($forbid), array_merge($grants('expert'), $grants('bookkeeper'))));
ok($held === [], 'no agent holds a deletion, a close or reopen, an approval of money, a void, a reversal, a configuration action or a transfer' . ($held ? ' — HELD: ' . implode(', ', $held) : ''));
ok(in_array('journal_entry_post', $grants('expert')) && !in_array('journal_entry_post', $grants('bookkeeper')) && in_array('invoice_send', $grants('expert')) && !in_array('invoice_send', $grants('bookkeeper')), 'the expert may post and send (each pauses for a person); the morning Bookkeeper drafts, imports, matches and reminds but does not post or send');
ok(in_array('invoice_remind', $grants('bookkeeper')) && $reg['actions']['invoice_remind']['approval'] === 'external_send', 'the Bookkeeper\'s reminder pauses as an external send (D7)');
ok(!array_filter(['expert', 'bookkeeper'], fn ($k) => in_array('config_change_log', $grants($k)) || in_array('agent_dispatches', $grants($k)) || in_array('my_tokens', $grants($k))), 'neither reads configuration changes, dispatches or tokens');
ok($agents['bookkeeper']['duty']['schedule_cron'] === '30 6 * * *' && $agents['bookkeeper']['duty']['name'] === 'The morning books' && !isset($agents['expert']['duty']), 'the Bookkeeper has one duty, at 06:30 daily; the expert answers when asked');
ok(count(array_diff($grants('bookkeeper'), array_merge($recTools, $actTools, $actionNames))) === 0 && in_array('morning_note_send', $grants('bookkeeper')) && in_array('unposted_entries', $grants('bookkeeper')), 'the Bookkeeper\'s morning starts at unposted_entries and ends at morning_note_send');
$gone = [];
foreach ($m['agents'] as $a) { foreach (array_merge([$a['job_description']], $a['skills']) as $f) { if (!is_file($f) && !is_dir($f) && !is_file($f . '/SKILL.md')) { $gone[] = $f; } } }
ok($gone === [], 'every job description and skill an agent names exists' . ($gone ? ' — NOT: ' . implode(', ', $gone) : ''));
$gone = array_filter($m['skills'], fn ($s) => !is_dir($s));
ok($gone === [], 'every skill the manifest lists exists (' . count($m['skills']) . ')');

echo "4. Endpoints, shares, services, the vhost\n";
$ep = array_column($m['endpoints'], null, 'name');
ok(isset($ep['Records MCP'], $ep['Activity MCP']) && $ep['Records MCP']['port_env'] === 'MCP_RECORDS_PORT' && $ep['Activity MCP']['path'] === '/mcp/activity' && $ep['Records MCP']['agent_reachable'] === true && $ep['Records MCP']['kind'] === 'mcp', 'the manifest lists both MCP endpoints with their port env and path');
$shares = array_column($m['shares'], 'tool');
ok($shares === ['account_balances', 'ap_due_summary', 'ar_aging_summary'] && !array_diff($shares, mcp_tools(REC, ktoken())), 'shares[] names the three tools and the kernel\'s token is offered every one');
$missing = array_filter($m['services'], fn ($s) => !is_file($s));
ok($missing === [], 'every unit the manifest lists exists (' . count($m['services']) . ')');
$unit = (string) file_get_contents('deploy/gl-records-mcp.service') . (string) file_get_contents('deploy/gl-activity-mcp.service');
ok(str_contains($unit, 'records_server.py') && str_contains($unit, 'activity_server.py') && str_contains($unit, 'mcp/venv/bin/python') && str_contains($unit, 'User=www-data'), 'the two units run the servers from mcp/venv as www-data');
$conf = (string) file_get_contents('deploy/apache-gl.conf');
ok(str_contains($conf, 'ProxyPass        /mcp/records  http://127.0.0.1:{{MCP_RECORDS_PORT}}/mcp') && str_contains($conf, 'ProxyPass        /mcp/activity http://127.0.0.1:{{MCP_ACTIVITY_PORT}}/mcp'), "the public vhost proxies both servers under the application's name");
$envReq = $m['env']['required'];
ok(in_array('MCP_RECORDS_PORT', $envReq) && in_array('MCP_ACTIVITY_PORT', $envReq) && in_array('OS_APPLICATION_TOKEN', $envReq) && in_array('ACTION_TOKEN_KEY', $envReq) && in_array('ACTIONS_RELAY_KEY', $envReq) && in_array('APP_KEY', $envReq), 'the env the servers need is declared required (ports, run-facts token, signing keys, the app key)');
ok(preg_match('/\bGRANT EXECUTE ON FUNCTION gl_share_account_balances/', (string) file_get_contents('db/020_shares.sql')) === 1 && !str_contains((string) file_get_contents('db/020_shares.sql'), 'TO PUBLIC'), 'db/020 grants the share functions to the read role alone');
$plan = (string) shell_exec('env -i PATH="$PATH" HOME="$HOME" php /var/www/bin/app_install.php plan /srv/apps/gl --domain subello.com 2>&1');
ok(!preg_match('/^stop\b/mi', $plan) && preg_match('/^Plan: \d+ steps to do/m', $plan) === 1, "the installer's read-only plan sees no stop");
finish();
