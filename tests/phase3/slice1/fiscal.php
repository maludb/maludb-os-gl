<?php
/** Proof — fiscal time (spec "Proof", 3): twelve periods, no overlap, a closed period refuses a post (SQL), reopen needs a reason, the chip says reopened, the year's close refused then made with its entry, the reopen reverses it. */
require __DIR__ . '/lib.php';
$owner = as_member(1); $carl = as_member(28);
echo "1. A fiscal year\n";
if (one("SELECT count(*) FROM fiscal_years") == 0) {
    $since = last_activity_id();
    [$c, $b] = act($owner, '/periods/save.php', ['start' => '2026-01-01']);
    ok($c === 200 && ($b['name'] ?? '') === 'FY2026' && str_ends_with((string) $b['location'], '#fiscal-year-card-' . $b['record_id']), 'fiscal_year_create: FY2026, location the card');
    ok(count(activity('fiscal_year.create', $since)) === 1 && str_contains((string) activity('fiscal_year.create', $since)[0]['after'], '"periods": 12'), 'fiscal_year.create logged with periods 12');
} else { ok(true, 'FY2026 exists from the earlier proof'); }
ok((int) one('SELECT count(*) FROM fiscal_periods WHERE fiscal_year_id = :y', ['y' => fy_id()]) === 12, 'twelve periods');
[$c, $d] = screen($owner, '/periods/');
ok($c === 200 && count($d['fiscal_years']) === 1 && count($d['fiscal_years'][0]['periods']) === 12 && $d['fiscal_years'][0]['periods'][0]['name'] === '2026-01', 'the list answers the year with its periods');
[$c, $b] = act($owner, '/periods/save.php', ['start' => '2026-07-01']);
ok($c === 422 && str_contains(strtolower(msg($b)), 'overlap'), 'a second, overlapping year: 422 (' . msg($b) . ')');
[$c, $b] = act($owner, '/periods/save.php', ['start' => '2027-01-15']);
ok($c === 422 && str_contains(msg($b), 'first of a month'), 'not the first of a month: 422 in the function\'s words');
[$c, $b] = act($owner, '/periods/save.php', ['start' => 'soon']);
ok($c === 422 && isset(fields($b)['start']), 'a bad date: 422 (field start)');
echo "2. Closing and reopening\n";
$mar = period_id('2026-03'); $feb = period_id('2026-02');
$since = last_activity_id();
[$c, $b] = act($carl, '/periods/close.php', ['period' => $mar]);
ok($c === 200 && ($b['location'] ?? '') === '/periods/' . $mar . '?notice=closed', 'period_close: 200, lands on the period');
$log = activity('period.close', $since);
ok(count($log) === 1 && (int) $log[0]['period_id'] === $mar && str_contains((string) $log[0]['after'], '"name": "2026-03"'), 'period.close logged with period_id and the name');
try { post_entry('2026-03-15', [['6100', '10.00', '0'], ['1010', '0', '10.00']], 'SMOKE into closed March'); $raised = ''; } catch (PDOException $e) { $raised = $e->getMessage(); }
ok(str_contains($raised, 'Period 2026-03 is closed; nothing posts into it'), 'posting into the closed period is refused by gl_entry_post (SQL): "' . (preg_match('/ERROR:\s*([^\n]+)/', $raised, $m) ? $m[1] : $raised) . '"');
[$c, $b] = act($carl, '/periods/close.php', ['period' => $mar]);
ok($c === 422 && str_contains(msg($b), 'is already closed'), 'closing it again: 422');
[$c, $b] = act($carl, '/periods/reopen.php', ['period' => $mar, 'reason' => '   ']);
ok($c === 422 && msg($b) === 'Reopening a period needs a reason', 'reopening without a reason: 422 "Reopening a period needs a reason"');
$since = last_activity_id();
[$c, $b] = act($carl, '/periods/reopen.php', ['period' => $mar, 'reason' => 'SMOKE a late bill']);
ok($c === 200 && ($b['reopen_count'] ?? 0) === 1, 'with one: 200, reopen_count 1');
$log = activity('period.reopen', $since);
ok(count($log) === 1 && str_contains((string) $log[0]['after'], 'SMOKE a late bill') && str_contains((string) $log[0]['after'], '"reopen_count": 1'), 'period.reopen logged with the reason and the count');
$body = page($owner, '/periods/')['body'];
ok(preg_match('/id="period-chip-' . $mar . '-status">reopened 1×</', $body) === 1 && preg_match('/id="period-chip-' . $feb . '-status">open</', $body) === 1, 'the chips: March "reopened 1×", February "open"');
[$c, $d] = screen($owner, '/periods/' . $mar);
ok($d['period']['reopen_reason'] === 'SMOKE a late bill' && $d['period']['reopened_by']['member_id'] === 28 && count($d['history']) === 2, 'the period page: the reason, who reopened, two history rows');
$body = page($owner, '/periods/' . $mar)['body'];
ok(str_contains($body, 'id="period-view-reopen-reason"') && str_contains($body, 'id="period-check-drafts"') && str_contains($body, 'data-ok="yes"'), 'the screen shows the reason and the checklist');
post_entry('2026-03-15', [['6100', '10.00', '0'], ['1010', '0', '10.00']], 'SMOKE into reopened March');
ok(true, 'and a post into reopened March goes through');
echo "3. The year's close\n";
[$c, $b] = act($carl, '/periods/year-close.php', ['fiscal_year' => fy_id()]);
ok($c === 422 && str_contains(msg($b), 'Every period of FY2026 must be closed before the year is'), 'closing the year with open periods: 422 in the function\'s words');
foreach (q('SELECT id FROM fiscal_periods WHERE fiscal_year_id = :y AND status = :s', ['y' => fy_id(), 's' => 'open']) as $p) { act($carl, '/periods/close.php', ['period' => (int) $p['id']]); }
ok((int) one('SELECT count(*) FROM fiscal_periods WHERE fiscal_year_id = :y AND status = :s', ['y' => fy_id(), 's' => 'open']) === 0, 'every period closed through the handler');
$draft = (int) one("INSERT INTO journal_entries (entry_date, memo, created_by) VALUES ('2026-06-01', 'SMOKE a draft', 1) RETURNING id");
[$c, $b] = act($carl, '/periods/year-close.php', ['fiscal_year' => fy_id()]);
ok($c === 422 && str_contains(msg($b), 'Draft entries are dated in FY2026; post or void them first'), 'with a draft dated in the year: 422');
q('DELETE FROM journal_entries WHERE id = :d', ['d' => $draft]);
$dec = period_id('2026-12');
$body = page($carl, '/periods/' . $dec)['body'];
ok(str_contains($body, 'id="period-view-year-close-btn"'), 'the December page offers Close the year');
$since = last_activity_id();
$expense = balance_of('6100', '2026-12-31');   // the closing entry is dated the year's end: balances are read as of that day
[$c, $b] = act($carl, '/periods/year-close.php', ['fiscal_year' => fy_id()]);
$ce = $b['closing_entry'] ?? null;
ok($c === 200 && $ce !== null && str_starts_with((string) $ce['number'], 'JE-') && ($b['location'] ?? '') === '/periods/' . $dec . '?notice=year_closed', 'year_end_close: 200 with the closing entry ' . ($ce['number'] ?? '?') . ($c === 200 ? '' : " ($c " . msg($b) . ')'));
$log = activity('fiscal_year.close', $since);
ok(count($log) === 1 && (int) $log[0]['journal_entry_id'] === (int) $ce['journal_entry_id'] && (int) $log[0]['period_id'] === $dec && str_contains((string) $log[0]['after'], '"closing_entry_number"'), 'fiscal_year.close logged with journal_entry_id, period_id and the number');
ok((float) balance_of('3900', '2026-12-31') != 0.0 && (float) balance_of('6100', '2026-12-31') == 0.0 && (float) balance_of('3900', '2026-12-30') == 0.0, 'as of the year\'s end retained earnings (3900) carries the net and 6100 is zero; the day before, nothing (' . balance_of('3900', '2026-12-31') . ')');
$body = page($carl, '/periods/' . $dec . '?notice=year_closed')['body'];
ok(str_contains($body, 'Closing entry ' . $ce['number']) && str_contains($body, 'href="/journal/' . $ce['journal_entry_id'] . '"') && str_contains($body, 'id="period-view-year-reopen-btn"'), 'the page shows the closing entry linked and offers Reopen the year');
[$c, $b] = act($carl, '/periods/year-reopen.php', ['fiscal_year' => fy_id(), 'reason' => '']);
ok($c === 422 && str_contains(msg($b), 'needs a reason'), 'reopening the year without a reason: 422');
$since = last_activity_id();
[$c, $b] = act($carl, '/periods/year-reopen.php', ['fiscal_year' => fy_id(), 'reason' => 'SMOKE an audit adjustment']);
ok($c === 200 && (string) one('SELECT status FROM fiscal_years WHERE id = :y', ['y' => fy_id()]) === 'open', 'year_reopen: 200, the year is open');
ok((float) balance_of('3900', '2026-12-31') == 0.0 && balance_of('6100', '2026-12-31') === $expense, 'the closing entry is reversed: 3900 back to 0, 6100 back to ' . $expense);
ok(count(activity('fiscal_year.reopen', $since)) === 1 && str_contains((string) activity('fiscal_year.reopen', $since)[0]['after'], 'SMOKE an audit adjustment'), 'fiscal_year.reopen logged with the reason');
[$c, $d] = screen($owner, '/periods/');
ok($d['fiscal_years'][0]['status'] === 'open' && $d['fiscal_years'][0]['closing_entry'] === null && $d['fiscal_years'][0]['periods'][11]['status'] === 'closed', 'the list: the year open with no closing entry; December still closed');
finish();
