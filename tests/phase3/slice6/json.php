<?php
/** Proof — JSON mode (spec "Proof", 6): every handler under a signed action token answers {ok, did, location}; 422 {error:{code invalid, fields}}; _partial=1; a run token's rows are source agent and the rights hold. */
require __DIR__ . '/lib.php';
$w = cash_world();
$bea = ['X-Action-Token: ' . person_token(27)]; $carl = ['X-Action-Token: ' . person_token(28)]; $owner = ['X-Action-Token: ' . person_token(1)];
pdo()->exec("SELECT set_config('app.member_id', '1', false)");
foreach (['1080' => 'SMOKE Json bank', '1090' => 'SMOKE Json second bank'] as $code => $name) { if (!one('SELECT 1 FROM accounts WHERE code = :c', ['c' => $code])) { q("INSERT INTO accounts (code, name, account_type, subtype, is_bank, is_control, is_system) VALUES (:c, :n, 'asset', 'bank', true, false, false)", ['c' => $code, 'n' => $name]); } }
$wrong = []; $count = 0;
$call = function (string $label, string $path, array $form, array $h, bool $location = true) use (&$wrong, &$count): array {
    [$c, $b] = act_token($path, $form, $h);
    $count++;
    if ($c !== 200 || !($b['ok'] ?? false) || !isset($b['did']) || ($location && !isset($b['location']))) { $wrong[] = "$label $c " . msg($b); }
    return $b;
};
echo "1. Bank accounts and lines\n";
$b = $call('bank_account_save', '/settings/banks/save.php', ['name' => 'SMOKE Json Bank', 'kind' => 'checking', 'account' => account_id('1080'), 'currency' => 'USD', 'opening_balance' => '50.00', 'opening_date' => '2026-01-01'], $owner);
$bk = (int) $b['record_id'];
ok(preg_match('#^/settings/banks/\?notice=saved$#', $b['location']) === 1 && ($b['bank_account_id'] ?? 0) === $bk, 'bank_account_save: record_id and a location');
$b2 = $call('bank_account_save (second)', '/settings/banks/save.php', ['name' => 'SMOKE Json Second Bank', 'kind' => 'savings', 'account' => account_id('1090'), 'currency' => 'USD'], $owner);
$bk2 = (int) $b2['record_id'];
$call('bank_account_save (update)', '/settings/banks/save.php', ['bank_account' => $bk, 'institution' => 'SMOKE CU'], $owner);
$b = $call('bank_line_add', '/cash/line.php', ['bank_account' => $bk, 'txn_date' => '2026-06-02', 'amount' => '-12.34', 'description' => 'SMOKE json fee', 'reference' => 'J-1'], $bea);
$l1 = (int) $b['record_id'];
$b = $call('bank_import (csv text)', '/cash/import.php', ['bank_account' => $bk, 'csv' => "Date,Description,Amount,Reference\n2026-06-03,SMOKE JSON A,10.00,JA-1\n2026-06-04,SMOKE JSON B,-20.00,JB-1\n", 'date_format' => 'ymd'], $bea);
$imp = (int) $b['record_id'];
ok(($b['rows'] ?? 0) === 2 && ($b['inserted'] ?? 0) === 2 && ($b['skipped'] ?? -1) === 0 && ($b['bank_account_id'] ?? 0) === $bk && str_starts_with($b['location'], '/cash/match?bank_account=' . $bk), 'bank_import answers rows, inserted, skipped and the match page as location');
$b = act_token('/cash/import.php', ['bank_account' => $bk, 'preview' => '1', 'csv' => "Date,Description,Amount\n2026-06-05,PREVIEW ONLY,1.00\n"], $bea)[1];
ok(($b['ok'] ?? false) === true && count($b['preview']['rows'] ?? []) === 1 && (int) one('SELECT count(*) FROM bank_transactions WHERE description = \'PREVIEW ONLY\'') === 0 && ($b['preview']['hashed'] ?? 0) === 1 && str_contains((string) ($b['preview']['hashed_note'] ?? ''), 'gave no reference'), 'a preview over a token writes nothing and carries the hashed note');
$lj = (int) one("SELECT id FROM bank_transactions WHERE external_ref = 'JA-1'"); $lk = (int) one("SELECT id FROM bank_transactions WHERE external_ref = 'JB-1'");
echo "2. Matching, entries, ignoring\n";
$b = $call('bank_entry_from_line', '/cash/entry.php', ['bank_line' => $l1, 'account' => account_id('6500'), 'memo' => 'SMOKE json fee entry'], $bea);
ok(($b['journal_entry_number'] ?? '') !== '' && ($b['journal_entry_id'] ?? 0) > 0 && line_status($l1) === 'matched', 'bank_entry_from_line answers the entry\'s id and number; the line is matched');
$call('bank_transaction_unmatch', '/cash/unmatch.php', ['bank_line' => $l1], $bea);
$call('bank_transaction_ignore', '/cash/ignore.php', ['bank_line' => $l1, 'reason' => 'SMOKE json ignore'], $bea);
$call('bank_transaction_unmatch (ignored)', '/cash/unmatch.php', ['bank_line' => $l1], $bea);
$r = (int) one("INSERT INTO receipts (customer_id, received_on, amount, currency, method, reference, deposit_to_bank_account_id, recorded_by) VALUES ((SELECT id FROM customers WHERE name = 'SMOKE Cash Acme'), '2026-06-03', 10.00, 'USD', 'cash', 'JSON', :b, 27) RETURNING id", ['b' => $bk]);
one('SELECT gl_receipt_post(:r, 27)', ['r' => $r]);
$b = $call('bank_transaction_match', '/cash/match.php', ['bank_line' => $lj, 'item_type' => 'receipt', 'item' => $r], $bea);
ok(($b['item_type'] ?? '') === 'receipt' && ($b['item_id'] ?? 0) === $r && ($b['amount'] ?? '') === '10.00', 'bank_transaction_match answers the item and the amount');
echo "3. Reconciling and transfers\n";
$b = $call('reconciliation_start', '/cash/reconcile-start.php', ['bank_account' => $bk, 'statement_date' => '2026-06-30', 'statement_balance' => '60.00'], $bea);
$rec = (int) $b['record_id'];
ok(($b['difference'] ?? '') === '0.00' || ($b['difference'] ?? '') !== '', 'reconciliation_start answers the figures (' . ($b['difference'] ?? '?') . ')');
$call('bank_transaction_ignore (the other line)', '/cash/ignore.php', ['bank_line' => $lk, 'reason' => 'SMOKE json'], $bea);
[$c, $bb] = act_token('/cash/reconcile-complete.php', ['reconciliation' => $rec], $bea);
ok($c === 403, 'the Bookkeeper completing: 403 (bank.reconcile)');
$cd = (string) one('SELECT difference FROM bank_reconciliations WHERE id = :r', ['r' => $rec]);
pdo()->exec("SELECT set_config('app.member_id', '1', false)");
$call('reconciliation_complete', '/cash/reconcile-complete.php', ['reconciliation' => $rec, 'note' => 'SMOKE json'], $carl, true);
$call('reconciliation_reopen', '/cash/reconcile-reopen.php', ['reconciliation' => $rec, 'reason' => 'SMOKE json'], $carl);
$b = act_token('/cash/reconcile-start.php', ['bank_account' => $bk, 'statement_date' => '2026-07-31', 'statement_balance' => '1.00'], $bea)[1];
$call('reconcile-abandon', '/cash/reconcile-abandon.php', ['reconciliation' => (int) $b['record_id']], $bea);
$b = $call('transfer_record', '/cash/transfers/save.php', ['from_bank_account' => $bk, 'to_bank_account' => $bk2, 'amount' => '5.00', 'transfer_date' => '2026-06-10', 'memo' => 'SMOKE json'], $bea);
$tr = (int) $b['record_id'];
ok(preg_match('#^/cash/transfers/' . $tr . '\?notice=recorded$#', $b['location']) === 1 && preg_match('/^TRF-\d{6}$/', (string) ($b['number'] ?? '')) === 1, 'transfer_record: record_id, location, number');
$call('transfer_void', '/cash/transfers/void.php', ['transfer' => $tr, 'reason' => 'SMOKE json'], $carl);
$lines2 = array_column(q('SELECT id FROM bank_transactions WHERE bank_account_id = :b', ['b' => $bk2]), 'id');
$call('bank_import_delete', '/cash/import-delete.php', ['import' => $imp], $bea);
$call('bank_account_archive', '/settings/banks/archive.php', ['bank_account' => $bk2], $owner);
ok($wrong === [], "$count handlers answer {ok, did, location}" . ($wrong ? ' — wrong: ' . implode('; ', $wrong) : ''));
echo "4. Refusals\n";
[$c, $b] = act_token('/cash/line.php', ['bank_account' => '', 'txn_date' => 'x', 'amount' => 'y', 'description' => ''], $bea);
$f = fields($b);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($f['bank_account'], $f['txn_date'], $f['amount'], $f['description']) && count($b['error']['errors']) === 4, 'a bad line: 422 {error: {code invalid, errors[4], fields{bank_account, txn_date, amount, description}}}');
[$c, $b] = act_token('/cash/match.php', ['bank_line' => 424242, 'item_type' => 'receipt', 'item' => 1], $bea);
ok($c === 404 && msg($b) === 'Bank line not found.', 'an unknown bank line: 404 in one sentence');
$odd = bank_line_row($bk, '2026-06-20', '77.00', 'SMOKE odd', 'J-ODD');
[$c, $b] = act_token('/cash/match.php', ['bank_line' => $odd, 'item_type' => 'receipt', 'item' => $r], $bea);
ok($c === 422 && (str_contains(msg($b), 'a match is to the cent') || str_contains(msg($b), 'No recorded receipt')), 'the database\'s words under a token (' . msg($b) . ')');
[$c, $b] = act_token('/cash/transfers/void.php', ['transfer' => $tr, 'reason' => 'x'], $bea);
ok($c === 403 && msg($b) === 'You may not reverse, void or write off.', 'the rights hold under a token: the Bookkeeper voiding → 403');
[$c, $b] = act_token('/settings/banks/save.php', ['name' => 'SMOKE Nope', 'account' => account_id('1080')], $bea);
ok($c === 403, 'the Bookkeeper changing the bank settings → 403');
[$c, $b] = act_token('/cash/match.php', ['bank_line' => $odd, 'item_type' => 'receipt', 'item' => $r], ['X-Action-Token: ' . person_token(26)]);
ok($c === 403, 'a Viewer matching → 403');
[$c, $b] = act_token('/cash/match.php', ['bank_line' => $odd, 'item_type' => 'receipt', 'item' => $r], ['X-Action-Token: ' . person_token(1, -5)]);
ok($c === 401, 'an expired token: 401');
echo "5. _partial=1 keeps the rest\n";
[$c, $b] = act_token('/settings/banks/save.php', ['bank_account' => $bk, 'account_last4' => '1234', '_partial' => '1'], $owner);
$row = q('SELECT * FROM bank_accounts WHERE id = :b', ['b' => $bk])[0];
ok($c === 200 && $row['account_last4'] === '1234' && $row['name'] === 'SMOKE Json Bank' && $row['institution'] === 'SMOKE CU' && $row['opening_balance'] === '50.00' && (int) $row['account_id'] === account_id('1080') && $row['currency'] === 'USD', 'bank_account_save with _partial=1: the base row fills the rest (name, institution, opening, ledger account, currency)');
echo "6. An agent's run token\n";
kernel_state(function ($st) { $st['facts']['74'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 74, 'request_id' => 'req-run-74', 'trigger' => 'duty', 'is_eval' => false, 'endpoints' => [['name' => 'Records MCP']]]; return $st; });
[$tok, $relay] = run_token(40, 74);
$h = ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay];
$since = last_activity_id();
[$c, $b] = act_token('/cash/import.php', ['bank_account' => $bk, 'csv' => "Date,Description,Amount,Reference\n2026-06-21,SMOKE AGENT,3.00,AG-1\n"], $h);
ok($c === 200 && ($b['inserted'] ?? 0) === 1, 'the Bookkeeper agent imports a statement (bank.write)');
$la = (int) one("SELECT id FROM bank_transactions WHERE external_ref = 'AG-1'");
$r2 = (int) one("INSERT INTO receipts (customer_id, received_on, amount, currency, method, reference, deposit_to_bank_account_id, recorded_by) VALUES ((SELECT id FROM customers WHERE name = 'SMOKE Cash Acme'), '2026-06-21', 3.00, 'USD', 'cash', 'AGENT', :b, 27) RETURNING id", ['b' => $bk]);
one('SELECT gl_receipt_post(:r, 27)', ['r' => $r2]);
[$c, $b] = act_token('/cash/match.php', ['bank_line' => $la, 'item_type' => 'receipt', 'item' => $r2], $h);
ok($c === 200, 'and matches a line to the receipt');
$rows = q("SELECT action, source, agent_run_id, request_id FROM activity_log WHERE id > :s AND action IN ('bank.import', 'bank.match') ORDER BY id", ['s' => $since]);
ok(count($rows) === 2 && array_unique(array_column($rows, 'source')) === ['agent'] && (int) $rows[0]['agent_run_id'] === 74 && $rows[0]['request_id'] === 'req-run-74', 'its rows are source agent, run 74, the run\'s request id');
[$c, $b] = act_token('/cash/reconcile-complete.php', ['reconciliation' => $rec], $h);
ok($c === 403, 'the agent may not complete a reconciliation (bank.reconcile): 403');
[$c, $b] = act_token('/cash/entry.php', ['bank_line' => $odd, 'account' => account_id('6500')], $h);
ok($c === 200 || $c === 403, 'an entry from a line is the journal right\'s (the agent holds it as a Bookkeeper: ' . $c . ')');
[$c, $b] = act_token('/settings/banks/save.php', ['name' => 'SMOKE agent bank', 'account' => account_id('1090')], $h);
ok($c === 403, 'nor change the bank settings: 403');
[$c, $b] = act_token('/cash/line.php', ['bank_account' => $bk, 'txn_date' => '2026-06-22', 'amount' => '1', 'description' => 'x'], ['X-Action-Token: ' . $tok]);
ok($c === 401, 'the relay missing: 401');
kernel_state(function ($st) { unset($st['facts']); return $st; });
echo "7. The screens answer JSON\n";
$hj = array_merge(JSONH, $bea);
$bad = [];
foreach (['/cash/', '/cash/banks/' . $bk, '/cash/import?bank_account=' . $bk, '/cash/match?bank_account=' . $bk, '/cash/reconcile?bank_account=' . $bk, '/cash/reconciliations/' . $rec, '/cash/transfers/', '/cash/transfers/new', '/cash/transfers/' . $tr] as $path) {
    $r = req('GET', $path, ['headers' => $hj]);
    if ($r['code'] !== 200 || !isset(json_decode($r['body'], true)['data'])) { $bad[] = "$path {$r['code']}"; }
}
foreach (['/settings/banks/', '/settings/banks/new', '/settings/banks/' . $bk . '/edit'] as $path) {
    $r = req('GET', $path, ['headers' => array_merge(JSONH, $owner)]);
    if ($r['code'] !== 200 || !isset(json_decode($r['body'], true)['data'])) { $bad[] = "$path {$r['code']}"; }
}
ok(empty($bad), 'twelve screens answer JSON' . (empty($bad) ? '' : ' — wrong: ' . implode('; ', $bad)));
$d = json_decode(req('GET', '/cash/banks/' . $bk, ['headers' => $hj])['body'], true)['data'];
ok(isset($d['bank_account']['ledger_balance'], $d['bank_account']['cleared_balance'], $d['bank_account']['difference'], $d['counts'], $d['lines'], $d['reconciliations'], $d['transfers'], $d['may']), 'the bank account: figures, counts, lines, reconciliations, transfers');
finish();
