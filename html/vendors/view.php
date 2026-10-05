<?php
declare(strict_types=1);
/** /vendors/{id} — one vendor: facts, contacts, open bills, payments, credits, documents, notes, the timeline (screen `vendor-view`; parties.view; the address and notes need ap.write). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
require_right('parties.view');
$pdo = db();
$id = req_id('id', 'vendor') ?? refuse(404, 'Vendor not found.');
$v = find_vendor($pdo, $id) ?? refuse(404, 'Vendor not found.');
$docs = has_right('documents.view');
$open = $docs ? vendor_open_bills($pdo, $id) : [];
$payments = $docs ? find_payments($pdo, ['vendor' => $id], 10) : [];
$credits = $docs ? find_vendor_credits($pdo, ['vendor' => $id, 'status' => 'issued'], 10) : [];
$attachments = $docs ? find_attachments($pdo, 'vendor', $id) : [];
$notes = $docs ? find_notes($pdo, 'vendor', $id) : [];
$timeline = find_record_activity($pdo, 'vendor', $id);
$may = ['write' => has_right('ap.write'), 'override' => has_right('journal.override'), 'attach' => has_right('attachments.write'), 'delete' => has_right('journal.override'), 'note' => $docs];
log_screen_view($pdo, 'vendor-view', ['after' => ['record_type' => 'vendor', 'record_id' => $id], 'vendor_id' => $id]);
if (wants_json()) {
    respond_screen(['vendor' => present_vendor($v), 'open_bills' => array_map('present_bill_row', array_map(static fn (array $b): array => $b + ['vendor_id' => $id, 'vendor_name' => $v['name'], 'amount_paid' => '0', 'source' => 'manual'], $open)), 'payments' => array_map('present_payment_row', $payments),
        'credits' => array_map(static fn (array $c): array => ['vendor_credit_id' => $c['vendor_credit_id'], 'number' => $c['number'], 'total' => $c['total'], 'balance' => $c['balance'], 'currency' => $c['currency']], $credits),
        'attachments' => array_map('present_attachment', $attachments), 'notes' => array_map('present_note', $notes), 'timeline' => array_map(static fn (array $r): array => ['activity_id' => (int) $r['activity_id'], 'at' => json_ts($r['occurred_at']), 'action' => $r['action'], 'sentence' => activity_sentence($r)], $timeline), 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved.'], 'restored' => ['success', 'The vendor was restored.'], 'attached' => ['success', 'Attached.'], 'noted' => ['success', 'Added the note.'], 'attachment_removed' => ['success', 'The attachment was removed.']];
render_screen($v['name'], view('vendors/view.php', ['v' => $v, 'open' => $open, 'payments' => $payments, 'credits' => $credits, 'attachments' => $attachments, 'notes' => $notes, 'timeline' => $timeline, 'may' => $may,
    'here' => here_url(), 'tz' => member_timezone(), 'notice' => gl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'vendors', 'screen' => 'vendor-view', 'entity' => 'vendor', 'recordId' => (string) $id]);
