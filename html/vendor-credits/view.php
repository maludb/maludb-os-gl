<?php
declare(strict_types=1);
/** /vendor-credits/{id} — one vendor credit: lines (the editor for a draft), where it is applied, the actions by status (screen `vendor-credit-view`; documents.view). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
require_right('documents.view');
$pdo = db();
$id = req_id('id', 'vendor_credit') ?? refuse(404, 'Vendor credit not found.');
$vc = find_vendor_credit($pdo, $id) ?? refuse(404, 'Vendor credit not found.');
$attachments = find_attachments($pdo, 'vendor_credit', $id);
$notes = find_notes($pdo, 'vendor_credit', $id);
$timeline = find_record_activity($pdo, 'vendor_credit', $id);
$targets = $vc['status'] === 'issued' && (float) $vc['balance'] > 0 ? creditable_bills($pdo, $vc['vendor_id'], $vc['currency']) : [];
$may = ['write' => has_right('ap.write'), 'approve' => has_right('ap.approve'), 'override' => has_right('journal.override'), 'attach' => has_right('attachments.write'), 'delete' => has_right('journal.override'), 'note' => has_right('documents.view')];
log_screen_view($pdo, 'vendor-credit-view', ['after' => ['record_type' => 'vendor_credit', 'record_id' => $id], 'vendor_id' => $vc['vendor_id']]);
if (wants_json()) {
    respond_screen(['vendor_credit' => ['vendor_credit_id' => $vc['vendor_credit_id'], 'number' => $vc['number'], 'vendor' => ['vendor_id' => $vc['vendor_id'], 'name' => $vc['vendor_name']], 'bill' => $vc['bill_id'] === null ? null : ['bill_id' => $vc['bill_id'], 'number' => $vc['bill_number']], 'vendor_ref' => $vc['vendor_ref'],
        'credit_date' => $vc['credit_date'], 'currency' => $vc['currency'], 'status' => $vc['status'], 'subtotal' => $vc['subtotal'], 'tax_total' => $vc['tax_total'], 'total' => $vc['total'], 'amount_applied' => $vc['amount_applied'], 'balance' => $vc['balance'], 'reason' => $vc['reason'],
        'journal_entry' => $vc['journal_entry_id'] === null ? null : ['journal_entry_id' => $vc['journal_entry_id'], 'number' => $vc['journal_entry_number']],
        'lines' => array_map(static fn (array $l): array => ['vendor_credit_line_id' => $l['vendor_credit_line_id'], 'sort_order' => $l['sort_order'], 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'line_subtotal' => $l['line_subtotal'], 'line_tax' => $l['line_tax'], 'line_total' => $l['line_total'], 'expense_account' => ['account_id' => $l['expense_account_id'], 'code' => $l['expense_account_code']]], $vc['lines']),
        'applications' => array_map(static fn (array $a): array => ['bill_id' => $a['bill_id'], 'number' => $a['bill_number'], 'amount' => $a['amount']], $vc['applications'])], 'attachments' => array_map('present_attachment', $attachments), 'notes' => array_map('present_note', $notes), 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved.'], 'issued' => ['success', 'Issued.'], 'applied' => ['success', 'Applied.'], 'unapplied' => ['success', 'Taken off the bill.'], 'voided' => ['warning', 'This vendor credit is void; its entry was reversed.'], 'line_saved' => ['success', 'Saved the line.'],
            'line_removed' => ['success', 'Removed the line.'], 'attached' => ['success', 'Attached.'], 'noted' => ['success', 'Added the note.'], 'attachment_removed' => ['success', 'The attachment was removed.']];
$data = ['vc' => $vc, 'targets' => $targets, 'attachments' => $attachments, 'notes' => $notes, 'timeline' => $timeline, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => gl_notice($_GET['notice'] ?? null, $notices)] + doc_lines_data($pdo, 'vendor_credit', $vc);
if (is_htmx_request() && !is_htmx_boosted() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'vendor-credit-lines') {
    answer_doc_region($pdo, 'vendor_credit', $vc);
}
render_screen($vc['number'] . ' — ' . $vc['vendor_name'], view('vendor_credits/view.php', $data), ['activeNav' => 'vendor-credits', 'screen' => 'vendor-credit-view', 'entity' => 'vendorCredit', 'recordId' => (string) $id]);
