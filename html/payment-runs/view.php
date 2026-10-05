<?php
declare(strict_types=1);
/** /payment-runs/{id} — one run: its payments, approve, record as paid, the CSV for the bank or the cheque printer (screen `payment-run-view`; documents.view). `?format=csv` (exports.download, logged `export.download`). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
require_right('documents.view');
$pdo = db();
$id = req_id('id', 'payment_run') ?? refuse(404, 'Payment run not found.');
$run = find_payment_run($pdo, $id) ?? refuse(404, 'Payment run not found.');
if (request_string('format') === 'csv') {
    require_right('exports.download');
    $csv = run_csv($pdo, $id);
    log_activity($pdo, 'export.download', 'payment_run', $id, ap_keys(null, null, $run['bank_account_id']) + ['after' => ['what' => 'payment_run', 'format' => 'csv', 'number' => $run['number'], 'rows' => count(array_filter($run['payments'], static fn (array $p): bool => $p['status'] !== 'void'))]]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $run['number'] . '.csv"');
    echo $csv;
    exit;
}
$attachments = find_attachments($pdo, 'payment_run', $id);
$notes = find_notes($pdo, 'payment_run', $id);
$timeline = find_record_activity($pdo, 'payment_run', $id);
$more = $run['status'] === 'draft' ? runless_payments($pdo, $run['bank_account_id'], $run['currency']) : [];
$may = ['write' => has_right('ap.write'), 'approve' => has_right('ap.approve'), 'override' => has_right('journal.override'), 'export' => has_right('exports.download'), 'attach' => has_right('attachments.write'), 'delete' => has_right('journal.override'), 'note' => has_right('documents.view')];
log_screen_view($pdo, 'payment-run-view', ['after' => ['record_type' => 'payment_run', 'record_id' => $id], 'bank_account_id' => $run['bank_account_id']]);
if (wants_json()) {
    respond_screen(['payment_run' => ['payment_run_id' => $run['payment_run_id'], 'number' => $run['number'], 'pay_date' => $run['pay_date'], 'bank_account' => ['bank_account_id' => $run['bank_account_id'], 'name' => $run['bank_account_name']], 'currency' => $run['currency'], 'status' => $run['status'],
        'total' => $run['total'], 'memo' => $run['memo'], 'partly_recorded' => $run['partly_recorded'], 'payments' => array_map(static fn (array $p): array => ['payment_id' => $p['payment_id'], 'number' => $p['number'], 'vendor' => ['vendor_id' => $p['vendor_id'], 'name' => $p['vendor_name']], 'amount' => $p['amount'], 'method' => $p['method'],
        'cheque_number' => $p['cheque_number'], 'status' => $p['status'], 'bills' => $p['bills']], $run['payments'])], 'addable_payments' => $more, 'attachments' => array_map('present_attachment', $attachments), 'notes' => array_map('present_note', $notes), 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved.'], 'approved' => ['success', 'Approved. Record the run once the money has moved.'], 'recorded' => ['success', 'Recorded as paid.'], 'voided' => ['warning', 'The run was voided.'], 'attached' => ['success', 'Attached.'], 'noted' => ['success', 'Added the note.'], 'attachment_removed' => ['success', 'The attachment was removed.']];
render_screen($run['number'] . ' — ' . $run['bank_account_name'], view('payment_runs/view.php', ['run' => $run, 'more' => $more, 'attachments' => $attachments, 'notes' => $notes, 'timeline' => $timeline, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(),
    'notice' => gl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'payment-runs', 'screen' => 'payment-run-view', 'entity' => 'paymentRun', 'recordId' => (string) $id]);
