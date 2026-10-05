<?php
declare(strict_types=1);
/** /deposits/new — group undeposited receipts into one bank deposit (screen `deposit-add`; ar.write). Param: bank_account (the choice picks the currency). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('ar.write');
require_human();
$pdo = db();
$banks = banks_for_select($pdo);
$bankId = request_integer('bank_account');
$bank = null;
foreach ($banks as $b) {
    if ($b['bank_account_id'] === $bankId) {
        $bank = $b;
    }
}
$receipts = $bank === null ? [] : undeposited_receipts($pdo, $bank['currency']);
log_screen_view($pdo, 'deposit-add');
if (wants_json()) {
    respond_screen(['banks' => $banks, 'bank_account' => $bank, 'undeposited_receipts' => $receipts]);
}
render_screen('New deposit', view('deposits/form.php', ['banks' => $banks, 'bank' => $bank, 'receipts' => $receipts, 'here' => here_url()]), ['activeNav' => 'deposits', 'screen' => 'deposit-add', 'entity' => 'deposit']);
