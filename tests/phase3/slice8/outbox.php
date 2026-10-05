<?php
/** Proof — the outbox (spec "Proof" 2): email through MaluMail with the link in the body, notification.send without it, retries then failed, a text through K6 and its refusals, the held link never sent. */
require __DIR__ . '/lib.php';
$bea = as_member(27); $owner = as_member(1);
ensure_year($owner); ar_fixture();
pdo()->exec("UPDATE notification_outbox SET status = 'sent', sent_at = now() WHERE status = 'queued' AND kind <> 'invoice_link'");   // start from a quiet outbox
echo "1. An invoice mail\n";
$cust = mk_customer('SMOKE Mailed Co');
$inv = mk_invoice($cust, [['Consulting', '2', '500.00']]); issue($inv);
[$c, $b] = act($bea, '/invoices/send.php', ['invoice' => $inv]);
$row = outbox("kind = 'invoice_sent' AND record_id = :i", ['i' => $inv])[0] ?? [];
ok($c === 200 && ($row['status'] ?? '') === 'queued', 'sending an invoice queues one invoice_sent row');
$held = outbox("kind = 'invoice_link' AND record_id = :i", ['i' => $inv])[0] ?? [];
ok(($held['status'] ?? '') === 'skipped', 'the held link row is skipped');
$mails = count(mail_log()); $since = last_activity_id();
$r = worker(['--only=send']);
ok(($r['results']['send']['sent'] ?? 0) >= 1 && $r['errors'] === [], 'the send step reports it sent (' . json_encode($r['results'] ?? $r) . ')');
$sent = outbox("id = :i", ['i' => $row['id']])[0];
$log = array_slice(mail_log(), $mails);
$mine = array_values(array_filter($log, fn ($m) => str_contains((string) ($m['to'] ?? ''), 'smokemailedco')))[0] ?? [];
ok($sent['status'] === 'sent' && $sent['provider_ref'] !== null && $sent['sent_at'] !== null && (int) $sent['attempts'] === 1, 'sent: provider ref kept, one attempt, sent_at set');
ok(preg_match('#/i/[a-f0-9]{48}#', (string) ($mine['text'] ?? '')) === 1 && ($mine['from'] ?? '') === 'ledger@example.invalid', 'MaluMail received the mail from MAIL_FROM with the secure link in its body');
ok(outbox("id = :i", ['i' => $held['id']])[0]['status'] === 'skipped' && count(array_filter($log, fn ($m) => str_contains((string) ($m['text'] ?? ''), '(held)'))) === 0, 'the held link row was never sent');
$rows = activity('notification.send', $since);
$a = json_decode((string) ($rows[0]['after'] ?? '{}'), true);
ok(count($rows) >= 1 && $rows[0]['source'] === 'cron' && ($a['channel'] ?? '') === 'email' && ($a['status'] ?? '') === 'sent' && !str_contains((string) $rows[0]['after'], 'smokemailedco') && !str_contains((string) $rows[0]['after'], '/i/'), 'logged notification.send with channel and status — never the address or the body');
$again = worker(['--only=send']);
ok(($again['results']['send']['sent'] ?? -1) === 0, 'a second pass sends nothing more');
echo "2. Failures\n";
$flaky = mk_customer('SMOKE Flaky Co'); $fi = mk_invoice($flaky, [['Work', '1', '10.00']]); issue($fi); act($bea, '/invoices/send.php', ['invoice' => $fi]);
$fid = (int) outbox("kind = 'invoice_sent' AND record_id = :i", ['i' => $fi])[0]['id'];
$t0 = time();
for ($n = 1; $n <= 5; $n++) {
    $r = worker(['--only=send'], date('Y-m-d H:i:s', $t0 + $n * 600));
    $row = outbox('id = :i', ['i' => $fid])[0];
    if ($n < 5) { ok($row['status'] === 'queued' && (int) $row['attempts'] === $n && str_contains((string) $row['detail'], '502'), "attempt $n: still queued, " . (int) $row['attempts'] . ' attempt(s), the sentence kept'); }
}
ok($row['status'] === 'failed' && (int) $row['attempts'] === 5 && str_contains((string) $row['detail'], 'MaluMail answered HTTP 502'), 'the fifth failed attempt leaves it failed with the provider\'s sentence (' . $row['detail'] . ')');
$f = activity('notification.send', $since);
ok(count(array_filter($f, fn ($x) => json_decode((string) $x['after'], true)['status'] === 'failed')) === 1, 'the failure is logged once, at the end');
$before = count(mail_log());
worker(['--only=send'], date('Y-m-d H:i:s', $t0 + 9000));
ok(count(mail_log()) === $before, 'a failed row is never tried again');
$sup = mk_customer('SMOKE Suppressed Co'); $si = mk_invoice($sup, [['Work', '1', '10.00']]); issue($si); act($bea, '/invoices/send.php', ['invoice' => $si]);
$rej = mk_customer('SMOKE Rejected Co'); $ri = mk_invoice($rej, [['Work', '1', '10.00']]); issue($ri); act($bea, '/invoices/send.php', ['invoice' => $ri]);
worker(['--only=send']);
$s = outbox("kind = 'invoice_sent' AND record_id = :i", ['i' => $si])[0]; $x = outbox("kind = 'invoice_sent' AND record_id = :i", ['i' => $ri])[0];
ok($s['status'] === 'failed' && (int) $s['attempts'] === 1 && str_contains((string) $s['detail'], 'suppressed'), 'a suppressed address fails at once, in MaluMail\'s words (' . $s['detail'] . ')');
ok($x['status'] === 'failed' && (int) $x['attempts'] === 1 && str_contains((string) $x['detail'], 'invalid_address'), 'a rejected recipient fails at once');
echo "3. Texts through the kernel's K6\n";
pdo()->exec("INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds) VALUES (28, true, true, '{bill_awaiting,ai_statement_differs}', '{bill_awaiting}') ON CONFLICT (member_id) DO UPDATE SET email_enabled = true, text_enabled = true, kinds = EXCLUDED.kinds, text_kinds = EXCLUDED.text_kinds");
sms_mode('ok');
$sv = mk_vendor('SMOKE Texted Vendor'); $since = last_activity_id(); $smsBefore = count(sms_log());
$bill = mk_bill($sv, [['Chairs', '1', '300.00']]);
$tx = outbox("channel = 'text' AND record_id = :b AND member_id = 28", ['b' => $bill]);
$em = outbox("channel = 'email' AND record_id = :b AND member_id = 28 AND kind = 'bill_awaiting'", ['b' => $bill]);
ok(count($tx) === 1 && count($em) === 1 && count(outbox("record_id = :b AND member_id = 27", ['b' => $bill])) === 0, 'a drafted bill queues Carl an email and a text (his choices) and tells Bea nothing (she cannot approve)');
ok((int) one('SELECT count(*) FROM notifications WHERE member_id = 28 AND kind = \'bill_awaiting\' AND record_id = :b', ['b' => $bill]) === 1, 'and the bell row');
worker(['--only=send']);
$sm = array_slice(sms_log(), $smsBefore);
ok(count($sm) === 1 && (int) $sm[0]['member_id'] === 28 && mb_strlen((string) $sm[0]['text']) <= 480 && outbox('id = :i', ['i' => $tx[0]['id']])[0]['status'] === 'sent', 'the K6 text went for member 28 (≤ 480 characters) and the row is sent');
sms_mode('no_verified_phone');
$bill2 = mk_bill($sv, [['Desks', '1', '400.00']]);
worker(['--only=send']);
$tx2 = outbox("channel = 'text' AND record_id = :b", ['b' => $bill2])[0]; $em2 = outbox("channel = 'email' AND record_id = :b AND member_id = 28", ['b' => $bill2])[0];
ok($tx2['status'] === 'skipped' && str_contains((string) $tx2['detail'], 'no_verified_phone') && $em2['status'] === 'sent', 'no_verified_phone: the text is skipped with the code and the email stands');
foreach (['opted_out', 'rate_limited', 'no_sender', 'not_held'] as $mode) {
    sms_mode($mode); $bx = mk_bill($sv, [['Item ' . $mode, '1', '5.00']]); worker(['--only=send']);
    $t = outbox("channel = 'text' AND record_id = :b", ['b' => $bx])[0];
    ok($t['status'] === 'skipped' && str_contains((string) $t['detail'], $mode), "$mode: skipped with the code");
}
sms_mode('server_error'); $by = mk_bill($sv, [['Item 500', '1', '5.00']]); worker(['--only=send']);
$t = outbox("channel = 'text' AND record_id = :b", ['b' => $by])[0];
ok($t['status'] === 'queued' && (int) $t['attempts'] === 1, 'a kernel 500: the text is retried (queued, one attempt)');
sms_mode('ok');
$t3 = time() + 7200; worker(['--only=send'], date('Y-m-d H:i:s', $t3));
ok(outbox('id = :i', ['i' => $t['id']])[0]['status'] === 'sent', 'and goes when the kernel answers');
$texts = activity('notification.send', $since);
ok(count($texts) >= 3 && count(array_filter($texts, fn ($x) => str_contains((string) $x['after'], 'Chairs'))) === 0, 'no notification.send row carries a body');
finish();
