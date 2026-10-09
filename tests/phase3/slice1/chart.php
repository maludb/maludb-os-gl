<?php
/** Proof — the chart (spec "Proof", 2): the guard's words, archive with a balance, archive hides from the pickers, balances equal gl_account_balance(), the running balance, the CSV logged. */
require __DIR__ . '/lib.php';
$owner = as_member(1); $vera = as_member(26);
if (one("SELECT count(*) FROM fiscal_years") == 0) { act($owner, '/periods/save.php', ['start' => '2026-01-01']); }
echo "1. Adding accounts — the trigger is the referee\n";
$since = last_activity_id();
[$c, $b] = act($owner, '/accounts/save.php', ['code' => '6150', 'name' => 'SMOKE Software', 'account_type' => 'expense', 'subtype' => 'operating', 'description' => 'SMOKE subscriptions']);
$sw = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $sw > 0 && ($b['location'] ?? '') === '/accounts/' . $sw . '?notice=saved', "account_save: 200, record_id $sw, location the account");
$log = activity('account.save', $since);
ok(count($log) === 1 && (int) $log[0]['entity_id'] === $sw && str_contains((string) $log[0]['after'], '"code": "6150"') && $log[0]['before'] === null, 'account.save logs the fields (no before on a create)');
[$c, $b] = act($owner, '/accounts/save.php', ['code' => '6151', 'name' => 'SMOKE Under an asset', 'account_type' => 'expense', 'parent' => account_id('1200')]);
ok($c === 422 && str_contains(msg($b), 'cannot sit under a asset account') || ($c === 422 && str_contains(msg($b), 'cannot sit under')), 'an expense under an asset parent: 422 in the trigger\'s words (' . msg($b) . ')');
[$c, $b] = act($owner, '/accounts/save.php', ['code' => '1015', 'name' => 'SMOKE Bank and control', 'account_type' => 'asset', 'subtype' => 'bank', 'is_bank' => 'yes']);
$bc = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $bc > 0, 'a bank asset account is added');
try { q('UPDATE accounts SET is_control = true WHERE id = :id', ['id' => $bc]); $raised = ''; } catch (PDOException $e) { $raised = $e->getMessage(); }
ok(str_contains($raised, 'cannot be both a bank account and a control account'), 'flagging it control too is refused by the trigger ("cannot be both")');
[$c, $b] = act($owner, '/accounts/save.php', ['account' => account_id('1200'), 'is_bank' => 'yes']);
ok($c === 422 && str_contains(msg($b), 'cannot be both a bank account and a control account'), 'account_save making the receivable (control) a bank: 422 "cannot be both"');
[$c, $b] = act($owner, '/accounts/save.php', ['account' => account_id('1200'), 'account_type' => 'liability']);
ok($c === 422 && str_contains(msg($b), 'is a system account and keeps its type'), 'a system account\'s type change: 422 "keeps its type"');
[$c, $b] = act($owner, '/accounts/save.php', ['account' => account_id('1200'), 'name' => 'SMOKE Receivable renamed']);
ok($c === 200 && (string) one('SELECT name FROM accounts WHERE code = \'1200\'') === 'SMOKE Receivable renamed', 'but a rename: 200 (a system account is renameable)');
$log = activity('account.save', $since);
$last = end($log);
ok(json_decode((string) $last['after'], true) === ['name' => 'SMOKE Receivable renamed'] && json_decode((string) $last['before'], true) === ['name' => 'Accounts receivable'], 'the update logs only the changed field, before and after');
act($owner, '/accounts/save.php', ['account' => account_id('1200'), 'name' => 'Accounts receivable']);
[$c, $b] = act($owner, '/accounts/save.php', ['code' => '6150', 'name' => 'SMOKE Dupe', 'account_type' => 'expense']);
ok($c === 422 && str_contains(msg($b), 'already used by SMOKE Software'), 'a code in use: 422 naming its owner');
[$c, $b] = act($owner, '/accounts/save.php', ['code' => '12', 'name' => '', 'account_type' => 'magic']);
$f = fields($b);
ok($c === 422 && isset($f['code'], $f['name'], $f['account_type']) && ($b['error']['code'] ?? '') === 'invalid', 'three bad fields: 422 with three field errors');
[$c, $b] = act($owner, '/accounts/save.php', ['code' => '6152', 'name' => 'SMOKE Odd subtype', 'account_type' => 'expense', 'subtype' => 'bank']);
ok($c === 422 && isset(fields($b)['subtype']), 'a subtype of another type: 422 (field subtype)');
[$c, $b] = act($owner, '/accounts/save.php', ['code' => '6150.10', 'name' => 'SMOKE Software child', 'account_type' => 'expense', 'subtype' => 'operating', 'parent' => $sw]);
$child = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $child > 0, 'a child under 6150 (code 6150.10)');

echo "2. Balances, the activity table, the CSV
";
post_entry('2026-02-10', [['6150', '100.00', '0'], ['1010', '0', '100.00']], 'SMOKE February software');
post_entry('2026-03-05', [['6150', '250.00', '0'], ['1010', '0', '250.00']], 'SMOKE March software');
post_entry('2026-03-20', [['6150.10', '20.00', '0'], ['1010', '0', '20.00']], 'SMOKE child');
[$c, $d] = screen($owner, '/accounts/?as_of=2026-03-31');
$row = array_values(array_filter($d['accounts'], fn ($a) => $a['code'] === '6150'))[0];
ok($c === 200 && $row['balance'] === balance_of('6150', '2026-03-31') && $row['balance'] === '350.00', 'the card\'s balance equals gl_account_balance() (350.00 as of March 31)');
$bank = array_values(array_filter($d['accounts'], fn ($a) => $a['code'] === '1010'))[0];
ok($bank['balance'] === '-370.00' && $bank['normal_balance'] === 'debit', 'the bank shows -370.00 in its normal (debit) sense');
[$c, $d] = screen($owner, '/accounts/' . $sw . '?from=2026-03-01&to=2026-03-31');
ok($c === 200 && $d['opening_balance'] === '100.00' && count($d['activity']) === 1 && end($d['activity'])['running_balance'] === '350.00', 'the activity for March: opening 100.00, one row, running balance ends at 350.00');
ok($d['account']['child_count'] === 1 && $d['children'][0]['code'] === '6150.10', 'the child is listed');
$body = page($owner, '/accounts/' . $sw . '?from=2026-03-01&to=2026-03-31')['body'];
ok(str_contains($body, 'id="account-activity-opening-balance"') && str_contains($body, 'id="account-view-csv-link"') && preg_match('/account-activity-row-\d+/', $body) === 1, 'the screen: the opening row, the CSV link, the entry row');
$since = last_activity_id();
$r = req('GET', '/accounts/' . $sw . '?format=csv&from=2026-01-01&to=2026-03-31', ['jar' => $owner]);
$lines = array_values(array_filter(explode("\n", trim($r['body']))));
ok($r['code'] === 200 && str_contains($r['headers'], 'text/csv') && count($lines) === 4 && str_ends_with($lines[3], '350.00'), 'the CSV: 4 lines (header, opening, two entries), the last balance 350.00');
ok(count(activity('export.download', $since)) === 1 && (int) activity('export.download', $since)[0]['entity_id'] === $sw, 'export.download logged for the account');
$r = req('GET', '/accounts/' . $sw . '?format=csv', ['jar' => $vera]);
ok($r['code'] === 403, 'a Viewer (no exports.download) may not take the CSV: 403');
ok(page($vera, '/accounts/' . $sw)['code'] === 200 && !str_contains(page($vera, '/accounts/' . $sw)['body'], 'id="account-view-csv-link"'), 'but reads the account, without the CSV link');
[$c, $d] = screen($owner, '/accounts/' . account_id('1200'));
ok($d['account']['is_control'] && str_contains(page($owner, '/accounts/' . account_id('1200'))['body'], 'Takes no line without its customer or vendor.'), 'a control account says so');

echo "3. Archive
";
[$c, $b] = act($owner, '/accounts/archive.php', ['account' => $sw]);
ok($c === 422 && str_contains(msg($b), 'has a balance of 350.00 and cannot be archived until it is moved'), 'archiving 6150 with a balance: 422 naming the balance (' . msg($b) . ')');
[$c, $b] = act($owner, '/accounts/save.php', ['code' => '6160', 'name' => 'SMOKE Empty', 'account_type' => 'expense']);
$empty = (int) $b['record_id'];
$since = last_activity_id();
[$c, $b] = act($owner, '/accounts/archive.php', ['account' => $empty]);
ok($c === 200 && one('SELECT archived_at FROM accounts WHERE id = :i', ['i' => $empty]) !== null && ($b['location'] ?? '') === '/accounts/?notice=archived', 'an empty account archives: 200, lands on the chart');
ok(count(activity('account.archive', $since)) === 1 && str_contains((string) activity('account.archive', $since)[0]['after'], '"archived": true'), 'account.archive logged (after.archived)');
[$c, $d] = screen($owner, '/accounts/');
ok(!in_array($empty, array_column($d['accounts'], 'account_id'), true), 'it is gone from the chart');
[$c, $d] = screen($owner, '/accounts/?include_archived=1');
ok(in_array($empty, array_column($d['accounts'], 'account_id'), true), 'include_archived shows it');
$body = page($owner, '/pick/?source=account&types=16&q=6')['body'];       // the record picker's rows (2026-10-09): the item form's expense field is a picker typed to expense accounts
ok(!str_contains($body, 'data-value="' . $empty . '"') && str_contains($body, 'data-value="' . $sw . '"'), 'the account picker leaves it out (6150 is offered, the archived one not)');
[$c, $b] = act($owner, '/accounts/archive.php', ['account' => $empty, 'archived' => 'no']);
ok($c === 200 && one('SELECT archived_at FROM accounts WHERE id = :i', ['i' => $empty]) === null, 'restored with archived=no');
[$c, $b] = act($owner, '/accounts/archive.php', ['account' => $empty, 'archived' => 'no']);
ok($c === 422 && str_contains(msg($b), 'is not archived'), 'restoring a live account: 422');
[$c, $b] = act($owner, '/accounts/archive.php', ['account' => account_id('1000')]);
ok($c === 200 && one("SELECT archived_at FROM accounts WHERE code = '1000'") !== null, 'a system account with no balance archives (it is never deleted)');
act($owner, '/accounts/archive.php', ['account' => account_id('1000'), 'archived' => 'no']);
[$c, $b] = act($owner, '/accounts/archive.php', ['account' => 424242]);
ok($c === 404, 'an unknown account: 404');
finish();
