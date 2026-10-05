<?php
declare(strict_types=1);
/** /payments/{id} — one payment: its applications, its entry, the bank line it matched, approve / record / void by status (screen `payment-view`; documents.view). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
require_right('documents.view');
$pdo = db();
$id = req_id('id', 'payment') ?? refuse(404, 'Payment not found.');
$p = find_payment($pdo, $id) ?? refuse(404, 'Payment not found.');
$attachments = find_attachments($pdo, 'payment', $id);
$notes = find_notes($pdo, 'payment', $id);
$timeline = find_record_activity($pdo, 'payment', $id);
$more = in_array($p['status'], ['draft', 'approved'], true) ? array_values(array_filter(payable_bills($pdo, $p['vendor_id'], $p['currency'], $id), static fn (array $b): bool => !in_array($b['bill_id'], array_column($p['applications'], 'bill_id'), true))) : [];
$may = ['write' => has_right('ap.write'), 'approve' => has_right('ap.approve'), 'override' => has_right('journal.override'), 'attach' => has_right('attachments.write'), 'delete' => has_right('journal.override'), 'note' => has_right('documents.view')];
log_screen_view($pdo, 'payment-view', ['after' => ['record_type' => 'payment', 'record_id' => $id], 'vendor_id' => $p['vendor_id'], 'bank_account_id' => $p['bank_account_id']]);
if (wants_json()) {
    respond_screen(['payment' => present_payment($p), 'matched_line' => $p['matched_line'], 'payable_bills' => $more, 'attachments' => array_map('present_attachment', $attachments), 'notes' => array_map('present_note', $notes),
        'timeline' => array_map(static fn (array $r): array => ['activity_id' => (int) $r['activity_id'], 'at' => json_ts($r['occurred_at']), 'action' => $r['action'], 'sentence' => activity_sentence($r)], $timeline), 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved.'], 'applied' => ['success', 'Applied.'], 'unapplied' => ['success', 'Taken off the bill.'], 'approved' => ['success', 'Approved. Record it once the money has moved.'], 'recorded' => ['success', 'Recorded as paid — match it when it shows at the bank.'],
            'voided' => ['warning', 'This payment is void; its entry was reversed and the bills are owed again.'], 'attached' => ['success', 'Attached.'], 'noted' => ['success', 'Added the note.'], 'attachment_removed' => ['success', 'The attachment was removed.']];
render_screen($p['number'] . ' — ' . $p['vendor_name'], view('payments/view.php', ['p' => $p, 'more' => $more, 'attachments' => $attachments, 'notes' => $notes, 'timeline' => $timeline, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(),
    'notice' => gl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'payments', 'screen' => 'payment-view', 'entity' => 'payment', 'recordId' => (string) $id]);
