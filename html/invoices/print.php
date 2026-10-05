<?php
declare(strict_types=1);
/** /invoices/{id}/print — the print-ready invoice (screen `invoice-print`; documents.view): the bare layout, the page the customer sees on the link without the claim form. Logged export.download (format print). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('documents.view');
$pdo = db();
$id = req_id('id', 'invoice') ?? refuse(404, 'Invoice not found.');
$inv = find_invoice($pdo, $id) ?? refuse(404, 'Invoice not found.');
log_activity($pdo, 'export.download', 'invoice', $id, audit_keys($inv['customer_id']) + ['after' => ['what' => 'invoice', 'format' => 'print', 'number' => $inv['number']]]);
$biz = $pdo->query('SELECT business_name, business_address FROM mcp_settings')->fetch() ?: [];
$cust = customer_state($pdo, $inv['customer_id']);
$footer = (string) $pdo->query('SELECT invoice_footer FROM gl_settings WHERE id = 1')->fetchColumn();
if (wants_json()) {
    respond_screen(['invoice' => present_invoice($inv)]);
}
echo view('invoices/print.php', ['inv' => $inv, 'biz' => $biz, 'cust' => $cust, 'footer' => $footer, 'public' => false, 'token' => null]);
