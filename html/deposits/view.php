<?php
declare(strict_types=1);
/** /deposits/{id} — one deposit: its receipts, its entry, the bank line it matched (screen `deposit-view`; documents.view). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('documents.view');
$pdo = db();
$id = req_id('id', 'deposit') ?? refuse(404, 'Deposit not found.');
$d = find_deposit($pdo, $id) ?? refuse(404, 'Deposit not found.');
$attachments = find_attachments($pdo, 'deposit', $id);
$notes = find_notes($pdo, 'deposit', $id);
$timeline = find_record_activity($pdo, 'deposit', $id);
$more = $d['status'] === 'draft' ? undeposited_receipts($pdo, $d['currency']) : [];
$more = array_values(array_filter($more, static fn (array $r): bool => $r['deposit_id'] === null));
$may = ['write' => has_right('ar.write'), 'override' => has_right('journal.override'), 'attach' => has_right('attachments.write'), 'delete' => has_right('journal.override'), 'note' => has_right('documents.view')];
log_screen_view($pdo, 'deposit-view', ['after' => ['record_type' => 'deposit', 'record_id' => $id], 'bank_account_id' => $d['bank_account_id']]);
if (wants_json()) {
    respond_screen(['deposit' => ['deposit_id' => $d['deposit_id'], 'number' => $d['number'], 'bank_account' => ['bank_account_id' => $d['bank_account_id'], 'name' => $d['bank_account_name']], 'deposit_date' => $d['deposit_date'], 'currency' => $d['currency'], 'total' => $d['total'], 'status' => $d['status'],
        'memo' => $d['memo'], 'journal_entry' => $d['journal_entry_id'] === null ? null : ['journal_entry_id' => $d['journal_entry_id'], 'number' => $d['journal_entry_number']], 'receipts' => array_map(static fn (array $r): array => ['receipt_id' => $r['receipt_id'], 'number' => $r['number'], 'customer' => $r['customer_name'], 'amount' => $r['amount']], $d['receipts']),
        'matched_line' => $d['matched_line']], 'addable_receipts' => $more, 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved.'], 'recorded' => ['success', 'Recorded: DR the bank, CR Undeposited funds.'], 'voided' => ['warning', 'This deposit is void; its receipts are undeposited again.'], 'attached' => ['success', 'Attached.'], 'noted' => ['success', 'Added the note.'], 'attachment_removed' => ['success', 'The attachment was removed.']];
render_screen($d['number'] . ' — ' . $d['bank_account_name'], view('deposits/view.php', ['d' => $d, 'more' => $more, 'attachments' => $attachments, 'notes' => $notes, 'timeline' => $timeline, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(),
    'notice' => gl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'deposits', 'screen' => 'deposit-view', 'entity' => 'deposit', 'recordId' => (string) $id]);
