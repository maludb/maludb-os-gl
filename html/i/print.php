<?php
declare(strict_types=1);
/** GET /i/<token>/print — the print layout of the customer's invoice (no session; the link is the authority; a dead link is the one dead-link page, 404). */
$GLOBALS['__public_door'] = 'portal';
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
$token = (string) ($_GET['token'] ?? '');
$inv = public_invoice($token);
if ($inv === null) {
    http_response_code(404);
    header('Cache-Control: no-store');
    echo view('public/dead-link.php', []);
    exit;
}
log_activity(db(), 'invoice.view', 'invoice', $inv['invoice_id'], ['actor_member_id' => null, 'actor_customer_id' => $inv['customer_id'], 'customer_id' => $inv['customer_id'], 'after' => ['number' => $inv['number'], 'format' => 'print']]);
$frame = public_invoice_frame($inv['customer_id']);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo view('invoices/print.php', ['inv' => $inv, 'biz' => $frame['biz'], 'cust' => $frame['cust'], 'footer' => $frame['footer'], 'public' => true, 'printMode' => true, 'token' => $token]);
