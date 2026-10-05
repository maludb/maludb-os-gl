<?php
declare(strict_types=1);
/** /payments/new — prepare a payment for one or several of a vendor's bills (screen `payment-add`; ap.write). Params: vendor, bill, bank_account. The vendor's open bills come oldest due first with amounts to apply. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
require_right('ap.write');
require_human();
$pdo = db();
$billId = request_integer('bill');
$bill = null;
if ($billId !== null) {
    require_visible($pdo, 'mcp_bills', 'bill_id', $billId, 'Bill');
    $bill = find_bill($pdo, $billId);
}
$vendorId = request_integer('vendor') ?? ($bill['vendor_id'] ?? null);
$vendor = $vendorId === null ? null : vendor_state($pdo, (int) $vendorId);
$banks = banks_for_select($pdo);
$bankId = request_integer('bank_account') ?? (($d = $pdo->query('SELECT default_bank_account_id FROM gl_settings WHERE id = 1')->fetchColumn()) ? (int) $d : null);
$bank = null;
foreach ($banks as $b) {
    if ($b['bank_account_id'] === $bankId) {
        $bank = $b;
    }
}
$bills = $vendorId === null ? [] : payable_bills($pdo, (int) $vendorId, $bank['currency'] ?? ($bill['currency'] ?? null));
log_screen_view($pdo, 'payment-add');
if (wants_json()) {
    respond_screen(['vendor' => $vendor === null ? null : ['vendor_id' => $vendor['vendor_id'], 'name' => $vendor['name'], 'pay_method' => $vendor['pay_method']], 'bank_account' => $bank, 'banks' => $banks, 'payable_bills' => $bills, 'methods' => array_keys(PAY_METHODS)]);
}
render_screen('Prepare a payment', view('payments/form.php', ['vendor' => $vendor, 'vendorId' => $vendorId, 'bank' => $bank, 'banks' => $banks, 'bills' => $bills, 'billId' => $billId, 'pdo' => $pdo, 'here' => here_url()]), ['activeNav' => 'payments', 'screen' => 'payment-add', 'entity' => 'payment']);
