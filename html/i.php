<?php
declare(strict_types=1);
/**
 * GET /i/<48 hex> — the customer's secure invoice page (D9): no session, no acting member; the link is the whole authority. A live token shows the invoice (lines, received, balance due, Print)
 * and the claim form "I paid this"; a dead or malformed one the one dead-link page (404). Logged `invoice.view` (source portal, actor_customer_id), never with the token.
 */
$GLOBALS['__public_door'] = 'portal';
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/receivables/handler.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$token = (string) ($_GET['token'] ?? '');
$inv = public_invoice($token);
if ($inv === null) {
    http_response_code(404);
    header('Cache-Control: no-store');
    echo view('public/dead-link.php', []);
    exit;
}
$pdo = db();
log_activity($pdo, 'invoice.view', 'invoice', $inv['invoice_id'], ['actor_member_id' => null, 'actor_customer_id' => $inv['customer_id'], 'customer_id' => $inv['customer_id'], 'after' => ['number' => $inv['number'], 'format' => 'page']]);
$frame = public_invoice_frame($inv['customer_id']);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$open = in_array($inv['status'], OPEN_INVOICE_STATUSES, true) && (float) $inv['balance_due'] > 0;
echo view('invoices/print.php', ['inv' => $inv, 'biz' => $frame['biz'], 'cust' => $frame['cust'], 'footer' => $frame['footer'], 'public' => true, 'token' => $token,
    'publicExtra' => $open ? view('public/claim-form.php', ['inv' => $inv, 'token' => $token, 'methods' => RECEIPT_METHODS]) : '']);
