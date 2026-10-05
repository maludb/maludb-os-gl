<?php
declare(strict_types=1);
/** /payment-runs/new — select the approved bills due by a date and make a run of payments from one bank account (screen `payment-run-add`; ap.write). Params: bank_account, due_by. Checkboxes by vendor, totals live. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
require_right('ap.write');
require_human();
$pdo = db();
$banks = banks_for_select($pdo);
$bankId = request_integer('bank_account') ?? (($d = $pdo->query('SELECT default_bank_account_id FROM gl_settings WHERE id = 1')->fetchColumn()) ? (int) $d : null);
$dueBy = request_date('due_by');
if ($dueBy === false) {
    refuse(422, 'A date is YYYY-MM-DD.');
}
$bank = null;
foreach ($banks as $b) {
    if ($b['bank_account_id'] === $bankId) {
        $bank = $b;
    }
}
$dueBy ??= date('Y-m-d', strtotime('+7 days'));
$bills = $bank === null ? [] : bills_due_by($pdo, $dueBy, $bank['currency']);
log_screen_view($pdo, 'payment-run-add');
if (wants_json()) {
    respond_screen(['banks' => $banks, 'bank_account' => $bank, 'due_by' => $dueBy, 'bills' => $bills]);
}
render_screen('New payment run', view('payment_runs/form.php', ['banks' => $banks, 'bank' => $bank, 'dueBy' => $dueBy, 'bills' => $bills, 'here' => here_url()]), ['activeNav' => 'payment-runs', 'screen' => 'payment-run-add', 'entity' => 'paymentRun']);
