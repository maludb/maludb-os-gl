<?php
declare(strict_types=1);
/**
 * POST /i/<token>/claim — the customer says "I paid this" (no session, no acting member; the CSRF token comes from the anonymous session the page opened). A honeypot, a rate limit (3 claims an hour per
 * invoice, 20 per address), then the claim is stored with the address, logged `claim.submit` (source portal, actor_customer_id, never the token) and the bookkeepers are told. A claim is never money.
 */
$GLOBALS['__public_door'] = 'portal';
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_post();
verify_csrf();
$token = (string) ($_GET['token'] ?? '');
$inv = public_invoice($token);
if ($inv === null) {
    http_response_code(404);
    echo view('public/dead-link.php', []);
    exit;
}
if (trim((string) ($_POST['website'] ?? '')) !== '') {                     // the honeypot: a robot fills it; it is told thanks and nothing is stored
    echo view('public/claim-done.php', ['inv' => $inv, 'token' => $token]);
    exit;
}
$ip = client_ip();
$counts = claim_counts($inv['invoice_id'], $ip);
if ($counts['invoice'] >= CLAIM_LIMIT_PER_INVOICE_HOUR || $counts['ip'] >= CLAIM_LIMIT_PER_IP_HOUR) {
    http_response_code(429);
    header('Retry-After: 3600');
    echo view('public/claim-form-error.php', ['message' => 'You have sent enough claims for now. The business has them; please try again later.', 'token' => $token]);
    exit;
}
$errors = [];
$on = request_date('claimed_on');
if ($on === null || $on === false) {
    $errors[] = 'Say the day you paid (YYYY-MM-DD).';
} elseif ($on > date('Y-m-d')) {
    $errors[] = 'The day you paid cannot be in the future.';
}
$method = (string) ($_POST['method'] ?? '');
if (!isset(RECEIPT_METHODS[$method])) {
    $errors[] = 'Say how you paid.';
}
$amountRaw = trim((string) ($_POST['amount'] ?? ''));
$amount = $amountRaw === '' ? $inv['balance_due'] : parse_money($amountRaw);
if ($amount === null || (float) $amount <= 0 || (float) $amount > 100000000) {
    $errors[] = 'The amount is a number above zero, like 1,608.24.';
}
if ($errors !== []) {
    http_response_code(422);
    echo view('public/claim-form-error.php', ['message' => implode(' ', $errors), 'token' => $token]);
    exit;
}
$pdo = db();
$f = ['claimed_on' => $on, 'method' => $method, 'reference' => trim((string) ($_POST['reference'] ?? '')) === '' ? null : mb_substr(trim((string) $_POST['reference']), 0, 120), 'amount' => $amount, 'note' => trim((string) ($_POST['note'] ?? '')) === '' ? null : mb_substr(trim((string) $_POST['note']), 0, 500)];
$pdo->beginTransaction();
try {
    $claimId = submit_claim($inv['invoice_id'], $f, $ip);
    log_activity($pdo, 'claim.submit', 'invoice', $inv['invoice_id'], ['actor_member_id' => null, 'actor_customer_id' => $inv['customer_id'], 'customer_id' => $inv['customer_id'],
        'after' => ['claim_id' => $claimId, 'number' => $inv['number'], 'amount' => $amount, 'currency' => $inv['currency'], 'method' => $method]]);
    notify_members_with_right('ar.write', 'claim_submitted', 'invoice', $inv['invoice_id'], $inv['customer_name'] . ' says they paid ' . $inv['number'], fmt_money($amount, $inv['currency']) . ' by ' . $method . ' on ' . format_date($on));
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('claim failed: ' . $e->getMessage());
    http_response_code(500);
    echo view('public/claim-form-error.php', ['message' => 'Something went wrong. Please try again, or tell the business directly.', 'token' => $token]);
    exit;
}
echo view('public/claim-done.php', ['inv' => $inv, 'token' => $token]);
