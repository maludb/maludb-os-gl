<?php
/**
 * Helpers for the Phase 4 proofs (docs/gl-mcp-tool-surface.md). Run through tests/phase4/run.sh: a fresh SCRATCH database (never the installed one), the application on :8391,
 * a fake kernel on :8392 (run-facts, K6, the ledger feed), and the TWO MCP SERVERS on :8398 (records) and :8399 (activity) — plus a third records server on :8400 whose kernel URL
 * is dead (the gate must fail closed). Everything a proof makes is named "SMOKE …". Builds on slice 9's helpers.
 */
require dirname(__DIR__) . '/phase3/slice9/lib.php';

const REC = 8398;
const ACT = 8399;
const REC_DEAD = 8400;

/** A person's own mcp_ token (the servers resolve it through mcp_resolve_token). Cached per member. */
function ptoken(int $member): string
{
    static $t = [];
    if (isset($t[$member])) { return $t[$member]; }
    $raw = 'mcp_' . bin2hex(random_bytes(24));
    q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, 'SMOKE p4', :h, 'mcp')", ['m' => $member, 'h' => hash('sha256', $raw)]);
    return $t[$member] = $raw;
}
/** The tenant's signed RUN token an agent presents ('mid.exp.run.hmac' over run:mid.exp.run). */
function rtoken(int $member, int $run, int $ttl = 300): string
{
    $p = $member . '.' . (time() + $ttl) . '.' . $run;
    return $p . '.' . hash_hmac('sha256', 'run:' . $p, need('ACTION_TOKEN_KEY'));
}
/** The kernel's own 60-second token: 'kernel.exp.app.nonce.hmac' over kernel:exp.app.nonce. */
function ktoken(string $app = 'gl', int $ttl = 60): string
{
    $p = (time() + $ttl) . '.' . $app . '.' . bin2hex(random_bytes(16));
    return 'kernel.' . $p . '.' . hash_hmac('sha256', 'kernel:' . $p, need('ACTION_TOKEN_KEY'));
}
/** Tell the fake kernel what the run-facts call answers for a run id. $endpoints: [name => [tool => constraints]]. */
function facts(int $run, int $member, array $endpoints, array $o = []): void
{
    kernel_state(function ($s) use ($run, $member, $endpoints, $o) {
        $eps = [];
        foreach ($endpoints as $name => $tools) { $eps[] = ['id' => 1, 'name' => $name, 'tools' => (object) $tools]; }
        $s['facts'][(string) $run] = ['valid' => $o['valid'] ?? true, 'is_agent' => $o['is_agent'] ?? true, 'member_id' => $member, 'run_id' => $run, 'request_id' => "req-run-$run",
            'trigger' => $o['trigger'] ?? 'chat', 'is_eval' => ($o['trigger'] ?? '') === 'eval', 'endpoints' => $eps];
        return $s;
    });
}
/** An agent: a mirror row with its roles. capability null = not yet admitted (the first-contact path admits it). */
function make_agent(int $id, string $name, array $roles = ['user'], ?string $cap = 'write'): void
{
    $capSql = $cap === null ? 'NULL' : "'$cap'";
    q("INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (:i, 'agent', :n, 'user', 'active', $capSql, CAST(:r AS text[]))
       ON CONFLICT (id) DO UPDATE SET capability = $capSql, status = 'active', roles = CAST(:r AS text[])", ['i' => $id, 'n' => $name, 'r' => '{' . implode(',', $roles) . '}']);
}

// ---- the MCP client (the kernel's own: initialize, initialized, then the call) ------------------------------------
function mcp_post(int $port, string $token, array $msg, ?string $session = null, string $path = '/mcp'): array
{
    $h = ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-06-18'];
    if ($token !== '') { $h[] = 'Authorization: Bearer ' . $token; }
    if ($session) { $h[] = 'Mcp-Session-Id: ' . $session; }
    $sess = $session;
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($msg), CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$sess) { if (stripos($line, 'mcp-session-id:') === 0) { $sess = trim(substr($line, 15)); } return strlen($line); }]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if (!is_string($body) || $body === '') { return [null, $sess, $status]; }
    if (stripos($type, 'text/event-stream') !== false) {
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (str_starts_with($line, 'data:')) { $m = json_decode(trim(substr($line, 5)), true); if (is_array($m) && ($m['id'] ?? null) === ($msg['id'] ?? null)) { return [$m, $sess, $status]; } }
        }
        return [null, $sess, $status];
    }
    return [json_decode($body, true), $sess, $status];
}
function mcp_open(int $port, string $token, string $path = '/mcp'): array
{
    [$init, $sess, $st] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'p4', 'version' => '1']]], null, $path);
    if ($st === 200) { mcp_post($port, $token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sess, $path); }
    return [$sess, $st];
}
/** The tool names a bearer is offered, or null when the server refused it (401). */
function mcp_tools(int $port, string $token, string $path = '/mcp'): ?array
{
    [$sess, $st] = mcp_open($port, $token, $path);
    if ($st !== 200) { return null; }
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sess, $path);
    $names = array_map(fn ($t) => $t['name'], $a['result']['tools'] ?? []);
    sort($names);
    return $names;
}
const NO_PARAMS = ['app_roles', 'my_tokens', 'gl_settings'];
/** Call a tool as a bearer. Answers ['http', 'error' => bool (the tool refused), 'text', 'data' => decoded JSON or null]. Arguments are wrapped as {"params": ...} except the flat kernel call ($flat) and the tools that take none. */
function mcp_tool(int $port, string $token, string $tool, array $args = [], bool $flat = false, string $path = '/mcp'): array
{
    [$sess, $st] = mcp_open($port, $token, $path);
    if ($st !== 200) { return ['http' => $st, 'error' => true, 'text' => '', 'data' => null]; }
    $arguments = $flat || ($args === [] && in_array($tool, NO_PARAMS, true)) ? (object) $args : ['params' => (object) $args];
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], $sess, $path);
    $res = $a['result'] ?? [];
    $text = (string) ($res['content'][0]['text'] ?? ($a['error']['message'] ?? ''));
    $data = json_decode($text, true);
    return ['http' => 200, 'error' => !empty($res['isError']) || isset($a['error']) || (is_array($data) && isset($data['error']) && count($data) <= 2), 'text' => $text, 'data' => is_array($data) ? $data : null];
}
/** A tool as a person (their own token): the whole answer, or just its decoded data. */
function tool(int $member, string $name, array $args = [], int $port = REC): array { return mcp_tool($port, ptoken($member), $name, $args); }
function tdata(int $member, string $name, array $args = [], int $port = REC) { return tool($member, $name, $args, $port)['data']; }
function walk($v, callable $f, string $path = ''): void
{
    if (is_array($v)) { foreach ($v as $k => $x) { walk($x, $f, $path . '.' . $k); } return; }
    $f($path, $v);
}
function has_key($data, string $key): bool
{
    $found = false;
    walk($data, function ($p) use ($key, &$found) { if (substr($p, (int) strrpos($p, '.') + 1) === $key) { $found = true; } });
    return $found;
}
/** SQL over the views AS a member (the same rows the server hands that member): rows. */
function sql_as(int $member, string $sql, array $args = []): array
{
    pdo()->exec("SELECT set_config('app.member_id', '$member', false)");
    $rows = q($sql, $args);
    pdo()->exec("SELECT set_config('app.member_id', '1', false)");
    return $rows;
}
function val_as(int $member, string $sql, array $args = []) { $r = sql_as($member, $sql, $args); return $r ? array_values($r[0])[0] : null; }
/** Money as the servers write it (a string with its scale); compare two money strings to the cent. */
function same_money($a, $b): bool { return round((float) $a, 4) === round((float) $b, 4); }

/**
 * The world every Phase 4 proof reads (built once, through the handlers as people, so every row is what the application makes): two customers and two vendors, a posted entry, a reversal,
 * February closed and reopened with a late posting, invoices (overdue and partly paid, current, paid), receipts (one unapplied and undeposited, one deposited), a credit note, bills (approved and due
 * in a week, an overdue one paid by a recorded payment, a draft awaiting approval, an AI bill), a draft payment, a payment run, the AI statement for 2026-09 pulled, imported, approved and agreeing,
 * two bank accounts with lines (one that matches a receipt), a transfer and a recurring template due. Ids in a file ($GL_DEV_STATE/world.json) so each proof process sees the same world.
 */
function p4world(): array
{
    $file = need('GL_DEV_STATE') . '/world.json';
    if (is_file($file)) { return json_decode((string) file_get_contents($file), true); }
    $owner = as_member(1); $bea = as_member(27); $carl = as_member(28);
    ensure_year($owner); $fx = ap_fixture();
    $w = ['bank' => $fx['bank'], 'cash' => $fx['cash'], 'tax' => $fx['tax']];
    $w['acme'] = mk_customer('SMOKE Acme Corp', ['terms_days' => 30]); $w['beta'] = mk_customer('SMOKE Beta Ltd');
    $w['hosting'] = mk_vendor('SMOKE Hosting Co', ['terms_days' => 10]); $w['supply'] = mk_vendor('SMOKE Office Supply');
    // the journal
    $w['capital'] = post_as_carl('2026-01-02', [line('1010', '5000.00', '0.00'), line('3000', '0.00', '5000.00')], 'SMOKE owner capital');
    $w['feb'] = post_as_carl('2026-02-10', [line('6200', '100.00', '0.00'), line('1010', '0.00', '100.00')], 'SMOKE February software');
    $feb = period_id('2026-02');
    act($carl, '/periods/close.php', ['period' => $feb]);
    act($carl, '/periods/reopen.php', ['period' => $feb, 'reason' => 'SMOKE: a late vendor bill']);
    $w['late'] = post_as_carl('2026-02-27', [line('6200', '75.00', '0.00'), line('1010', '0.00', '75.00')], 'SMOKE late February entry');
    $w['wrong'] = post_as_carl('2026-03-05', [line('6200', '12.00', '0.00'), line('1010', '0.00', '12.00')], 'SMOKE entry to reverse');
    act($carl, '/journal/reverse.php', ['entry' => $w['wrong'], 'reason' => 'SMOKE: wrong account', 'reverse_date' => '2026-03-06']);
    [, , $w['draft']] = draft($bea, '2026-10-01', [line('6200', '20.00', '0.00'), line('1010', '0.00', '20.00')], 'SMOKE draft entry');
    // receivables
    $w['inv_a1'] = mk_invoice($w['acme'], [['Consulting', '2', '500.00', $w['tax']]], ['issue_date' => '2026-03-01', 'due_date' => '2026-03-31', 'reference' => 'SMOKE-PO-1']); issue($w['inv_a1']);
    $w['inv_a2'] = mk_invoice($w['acme'], [['Retainer', '1', '300.00']], ['issue_date' => '2026-09-20', 'due_date' => '2026-10-20']); issue($w['inv_a2']);
    $w['inv_b1'] = mk_invoice($w['beta'], [['Setup', '1', '200.00']], ['issue_date' => '2026-05-05', 'due_date' => '2026-06-04']); issue($w['inv_b1']);
    $w['inv_draft'] = mk_invoice($w['beta'], [['Maybe', '1', '10.00']]);
    [, $b] = act($bea, '/receipts/save.php', ['customer' => $w['acme'], 'amount' => '400.00', 'method' => 'transfer', 'invoice' => $w['inv_a1'], 'received_on' => '2026-04-20', 'reference' => 'SMOKE-TRF-1']); $w['rcpt_a'] = (int) $b['record_id'];
    [, $b] = act($bea, '/receipts/save.php', ['customer' => $w['beta'], 'amount' => '200.00', 'method' => 'cheque', 'invoice' => $w['inv_b1'], 'received_on' => '2026-06-10', 'reference' => 'SMOKE-CHQ-1']); $w['rcpt_b'] = (int) $b['record_id'];
    [, $b] = act($bea, '/receipts/save.php', ['customer' => $w['beta'], 'amount' => '75.00', 'method' => 'cash', 'received_on' => '2026-09-25']); $w['rcpt_loose'] = (int) $b['record_id'];
    q("INSERT INTO receipt_claims (invoice_id, claimed_on, method, reference, amount, ip_address) VALUES (:i, '2026-10-01', 'cash', 'SMOKE-CLAIM', 300, '127.0.0.1')", ['i' => $w['inv_a2']]);
    [, $b] = act($bea, '/deposits/save.php', ['bank_account' => $w['bank'], 'receipts' => [$w['rcpt_a']]]); $w['deposit'] = (int) ($b['record_id'] ?? 0);
    if ($w['deposit']) { act($bea, '/deposits/record.php', ['deposit' => $w['deposit']]); }
    [, $b] = act($bea, '/credit-notes/save.php', ['customer' => $w['acme'], 'invoice' => $w['inv_a1'], 'lines' => json_encode([['description' => 'SMOKE goodwill', 'quantity' => '1', 'unit_price' => '20.00', 'income_account' => '4100']])]); $w['credit'] = (int) $b['record_id'];
    act($bea, '/credit-notes/issue.php', ['credit_note' => $w['credit']]);
    // payables
    $w['bill_h1'] = ready_bill($w['hosting'], [['Hosting October', '1', '259.80']], ['bill_date' => '2026-09-28', 'due_date' => date('Y-m-d', strtotime('+3 days')), 'vendor_ref' => 'H-2026-10']);
    $w['bill_o1'] = ready_bill($w['supply'], [['Paper', '10', '8.50']], ['bill_date' => '2026-08-01', 'due_date' => '2026-08-31', 'vendor_ref' => 'OS-77']);
    $w['bill_draft'] = mk_bill($w['hosting'], [['Extra storage', '1', '40.00']], ['bill_date' => '2026-10-02', 'vendor_ref' => 'H-DRAFT']);
    [, $b] = act($bea, '/payments/save.php', ['vendor' => $w['supply'], 'bank_account' => $w['bank'], 'method' => 'cheque', 'payment_date' => '2026-09-01', 'bills' => [$w['bill_o1']]]); $w['payment'] = (int) $b['record_id'];
    act($carl, '/payments/approve.php', ['payment' => $w['payment']]);
    act($bea, '/payments/record.php', ['payment' => $w['payment'], 'payment_date' => '2026-09-02', 'cheque_number' => '7001']);
    [, $b] = act($bea, '/payment-runs/save.php', ['bank_account' => $w['bank'], 'pay_date' => '2026-10-10', 'bills' => [$w['bill_h1']]]); $w['run'] = (int) ($b['record_id'] ?? 0);
    // the AI statement (agrees)
    $doc = ai_doc('2026-09', 'closed', [ai_line('anthropic', 'claude-sonnet-5-5', 'Accounting', 'Sasha', 120, 0, '12.3456'), ai_line('anthropic', 'claude-sonnet-5-5', 'IT', 'Sysadmin', 80, 2, '7.6549', '2 late calls'), ai_line('openai', 'gpt-5', 'Front Office', 'Seamus', 30, 0, '3.0000')]);
    ledger_state(['2026-09' => $doc]);
    act($bea, '/ap/ai/pull.php', ['period' => '2026-09']);
    $w['ai'] = stmt_id('2026-09');
    act($bea, '/ap/ai/import.php', ['ai_statement' => $w['ai']]);
    foreach (q('SELECT bill_id FROM ai_statement_bills WHERE statement_id = :s', ['s' => $w['ai']]) as $r) { act($carl, '/bills/approve.php', ['bill' => (int) $r['bill_id']]); }
    act($bea, '/ap/ai/check.php', ['ai_statement' => $w['ai']]);
    // cash
    $w['line_match'] = bank_line_row($w['bank'], '2026-09-26', '75.00', 'SMOKE DEPOSIT CASH', 'SMOKE-REF-75');
    $w['line_open'] = bank_line_row($w['bank'], '2026-09-30', '-33.10', 'SMOKE BANK FEE', 'SMOKE-FEE');
    $w['line_xfer'] = bank_line_row($w['bank'], '2026-09-16', '-50.00', 'SMOKE XFER TO PETTY CASH', 'SMOKE-XFER');
    [, $b] = act($bea, '/cash/transfers/save.php', ['from_bank_account' => $w['bank'], 'to_bank_account' => $w['cash'], 'amount' => '50.00', 'transfer_date' => '2026-09-15', 'memo' => 'SMOKE petty cash']); $w['transfer'] = (int) ($b['record_id'] ?? 0);
    // recurring
    [, , $w['template']] = mk_template('journal', 'SMOKE Monthly rent', ['memo' => 'Rent', 'lines' => [['account' => '6200', 'debit' => '900.00'], ['account' => '1010', 'credit' => '900.00']]], ['next_date' => '2026-10-01']);
    // a note and a notification
    act($bea, '/notes/save.php', ['record_type' => 'bill', 'record_id' => $w['bill_h1'], 'body' => 'SMOKE note on the hosting bill']);
    file_put_contents($file, json_encode($w));
    return $w;
}
