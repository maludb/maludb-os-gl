<?php
declare(strict_types=1);

/**
 * The mail an invoice, a reminder and a statement make (slice 4): subject, text and HTML bodies, queued in notification_outbox for the worker (slice 8) to send through
 * app/mail.php. The wording of the three reminder steps is fixed text here (an admin cannot edit it in v1). The link in a mail is the customer's secure link; it appears in
 * the mail and in the one held outbox row, never in the activity log.
 */

/** 1 = a friendly nudge (1–14 days past due), 2 = a firmer note (15–44), 3 = the final notice (45 and more). */
function reminder_step(int $daysOverdue): int
{
    return $daysOverdue >= 45 ? 3 : ($daysOverdue >= 15 ? 2 : 1);
}

/** The business's name for a mail's greeting and sign-off. */
function business_name(PDO $pdo): string
{
    return (string) ($pdo->query('SELECT business_name FROM mcp_settings')->fetchColumn() ?: app_name());
}

/** The mail for an invoice. $kind invoice_sent | invoice_reminder (with $step). Answers ['subject', 'text', 'html']. */
function invoice_mail(PDO $pdo, array $inv, string $rawToken, ?string $message, string $kind, int $step = 1): array
{
    $biz = business_name($pdo);
    $link = app_url('/i/' . $rawToken);
    $num = (string) $inv['number'];
    $due = fmt_money($inv['balance_due'], $inv['currency']);
    $dueOn = format_date($inv['due_date']);
    if ($kind === 'invoice_reminder') {
        $days = (int) $inv['days_overdue'];
        [$subject, $lead] = match ($step) {
            1 => ["A friendly reminder: invoice $num", "A quick note that invoice $num for $due was due on $dueOn. If it is already on its way, thank you — nothing more to do."],
            2 => ["Invoice $num is $days days past due", "Invoice $num for $due was due on $dueOn and is now $days days past due. Please arrange payment this week, or tell us when to expect it."],
            default => ["Final notice: invoice $num is $days days past due", "Invoice $num for $due, due on $dueOn, is $days days past due. Please pay it now or contact us today so we can agree what happens next."],
        };
    } else {
        $subject = "Invoice $num from $biz — $due due $dueOn";
        $lead = "Here is invoice $num for " . fmt_money($inv['total'], $inv['currency']) . ($inv['balance_due'] !== $inv['total'] ? " ($due still due)" : '') . ", due on $dueOn.";
    }
    $words = trim((string) $message);
    $text = ($words !== '' ? $words . "\n\n" : '') . $lead . "\n\nView and print the invoice, or tell us you have paid:\n$link\n\nThank you,\n$biz\n";
    $html = '<p>' . ($words !== '' ? nl2br(e($words)) . '</p><p>' : '') . e($lead) . '</p><p><a href="' . e($link) . '">View the invoice</a> — print it, or tell us you have paid.</p><p>Thank you,<br>' . e($biz) . '</p>';
    return ['subject' => $subject, 'text' => $text, 'html' => $html];
}

/** The statement mail: the closing balance and the lines in plain text. */
function statement_mail(PDO $pdo, array $data): array
{
    $biz = business_name($pdo);
    $lines = [];
    foreach ($data['rows'] as $r) {
        $lines[] = sprintf('%s  %-22s %12s', format_date($r['date']), (string) $r['number'], fmt_money($r['debit'] ?? '-' . $r['credit'], $data['currency'], false));
    }
    $text = 'Statement for ' . $data['customer_name'] . ' — ' . format_date($data['from']) . ' to ' . format_date($data['to']) . "\n\nOpening balance " . fmt_money($data['opening'], $data['currency']) . "\n"
        . implode("\n", $lines) . "\n\nClosing balance " . fmt_money($data['closing'], $data['currency']) . "\n\nThank you,\n$biz\n";
    return ['subject' => 'Your statement from ' . $biz, 'text' => $text, 'html' => '<pre>' . e($text) . '</pre>'];
}

/** Queue one email for a customer in the outbox (the worker sends it). Answers the outbox id. */
function queue_customer_mail(PDO $pdo, int $customerId, string $to, string $kind, string $recordType, int $recordId, array $mail): int
{
    $st = $pdo->prepare("INSERT INTO notification_outbox (channel, customer_id, to_email, kind, record_type, record_id, subject, body, body_html, status) VALUES ('email', :c, :to, :k, :t, :r, :s, :b, :h, 'queued') RETURNING id");
    $st->execute(['c' => $customerId, 'to' => $to, 'k' => $kind, 't' => $recordType, 'r' => $recordId, 's' => $mail['subject'], 'b' => $mail['text'], 'h' => $mail['html']]);
    return (int) $st->fetchColumn();
}

/** Queue a statement for a customer: the address named, else the primary contact's, else the customer's. */
function queue_statement_mail(PDO $pdo, int $customerId, array $data, ?string $to): array
{
    $address = customer_mail_address($pdo, $customerId, $to);
    return ['outbox_id' => queue_customer_mail($pdo, $customerId, $address, 'statement', 'customer', $customerId, statement_mail($pdo, $data))];
}
