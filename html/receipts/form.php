<?php
declare(strict_types=1);
/** /receipts/new — record money received and apply it oldest first (screen `receipt-add`; ar.write). Params: customer, invoice, amount, claim. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('ar.write');
require_human();
$pdo = db();
$claim = null; $inv = null;
if (($cid = request_integer('claim')) !== null) {
    require_visible($pdo, 'mcp_receipt_claims', 'receipt_claim_id', $cid, 'Claim');
    $claim = find_claim($pdo, $cid);
    if ($claim['status'] !== 'submitted') {
        refuse(422, 'That claim is already ' . $claim['status'] . '.');
    }
}
$invoiceId = request_integer('invoice') ?? ($claim['invoice_id'] ?? null);
if ($invoiceId !== null) {
    require_visible($pdo, 'mcp_invoices', 'invoice_id', $invoiceId, 'Invoice');
    $inv = find_invoice($pdo, $invoiceId);
}
$customerId = request_integer('customer') ?? ($inv['customer_id'] ?? null);
$customer = $customerId === null ? null : customer_state($pdo, $customerId);
$open = $customer === null ? [] : customer_open_invoices($pdo, $customerId);
$currency = $inv['currency'] ?? ($customer['currency'] ?? base_currency_code($pdo));
$amountRaw = request_string('amount');
$amount = $amountRaw !== '' ? parse_money($amountRaw) : ($claim['amount'] ?? ($inv['balance_due'] ?? null));
$prefill = [];
$left = $amount ?? '0.00';
// apply in order: the named invoice first, then oldest due
usort($open, static fn (array $a, array $b): int => [$a['invoice_id'] === $invoiceId ? 0 : 1, $a['due_date'], $a['invoice_id']] <=> [$b['invoice_id'] === $invoiceId ? 0 : 1, $b['due_date'], $b['invoice_id']]);
foreach ($open as $i) {
    if ($i['currency'] === $currency && m_cmp($left, '0') > 0) {
        $a = m_cmp($left, $i['balance_due']) <= 0 ? $left : $i['balance_due'];
        $prefill[$i['invoice_id']] = $a;
        $left = m_sub($left, $a);
    }
}
log_screen_view($pdo, 'receipt-add');
if (wants_json()) {
    respond_screen(['customer_id' => $customerId, 'currency' => $currency, 'amount' => $amount, 'claim' => $claim, 'open_invoices' => array_map('present_invoice_row', $open), 'prefill' => $prefill, 'banks' => banks_for_select($pdo), 'methods' => RECEIPT_METHODS]);
}
render_screen('Record a receipt', view('receipts/form.php', ['customer' => $customer, 'customerId' => $customerId, 'open' => $open, 'currency' => $currency, 'amount' => $amount, 'claim' => $claim, 'inv' => $inv, 'prefill' => $prefill, 'unapplied' => $left,
    'banks' => banks_for_select($pdo), 'pdo' => $pdo, 'here' => here_url()]), ['activeNav' => 'receipts', 'screen' => 'receipt-add', 'entity' => 'receipt']);
