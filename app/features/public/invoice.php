<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/notify/write.php';

/**
 * The customer's secure invoice page (slice 4, D9): no session, no acting member — the link's token is the whole authority. Everything here reads the BASE tables as the writer
 * (the mcp_* views answer nothing without an acting member); gl_secure_link_invoice() is the one door: the invoice id for a live token, NULL for every other reason.
 * The page never shows another invoice, a note, the customer's own email or the token anywhere but the URL.
 */
const CLAIM_LIMIT_PER_INVOICE_HOUR = 3;
const CLAIM_LIMIT_PER_IP_HOUR = 20;

/** The invoice a raw token opens (counting the view, stamping viewed), with its lines, receipts and credits; null for a dead or malformed token. */
function public_invoice(string $raw): ?array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT gl_secure_link_invoice(:t)');
    $st->execute(['t' => $raw]);
    $id = $st->fetchColumn();
    if ($id === false || $id === null) {
        return null;
    }
    $id = (int) $id;
    $i = $pdo->prepare('SELECT i.id AS invoice_id, i.number, i.customer_id, c.name AS customer_name, i.issue_date, i.due_date, i.currency, i.status, i.subtotal, i.tax_total, i.total, i.amount_paid, i.amount_credited, i.balance_due, i.reference, i.memo, i.terms_text
                         FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.id = :i');
    $i->execute(['i' => $id]);
    $inv = $i->fetch();
    if ($inv === false || $inv['status'] === 'draft') {
        return null;
    }
    $inv['invoice_id'] = (int) $inv['invoice_id'];
    $inv['customer_id'] = (int) $inv['customer_id'];
    $inv['currency'] = trim((string) $inv['currency']);
    $l = $pdo->prepare('SELECT description, quantity, unit_price, tax_rate, line_subtotal, line_tax, line_total FROM invoice_lines WHERE invoice_id = :i ORDER BY sort_order, id');
    $l->execute(['i' => $id]);
    $inv['lines'] = $l->fetchAll();
    $r = $pdo->prepare("SELECT r.received_on, ra.amount FROM receipt_applications ra JOIN receipts r ON r.id = ra.receipt_id AND r.status = 'recorded' WHERE ra.invoice_id = :i ORDER BY r.received_on");
    $r->execute(['i' => $id]);
    $inv['receipts'] = $r->fetchAll();
    $c = $pdo->prepare("SELECT cn.number AS credit_note_number, ca.amount FROM credit_applications ca JOIN credit_notes cn ON cn.id = ca.credit_note_id AND cn.status = 'issued' WHERE ca.invoice_id = :i");
    $c->execute(['i' => $id]);
    $inv['credits'] = $c->fetchAll();
    return $inv;
}

/** What the print page shows around an invoice: the business, the customer's billing address (never the email) and the footer. */
function public_invoice_frame(int $customerId): array
{
    $pdo = db();
    $biz = $pdo->query('SELECT business_name, business_address, invoice_footer FROM gl_settings WHERE id = 1')->fetch() ?: [];
    $c = $pdo->prepare('SELECT billing_address FROM customers WHERE id = :c');
    $c->execute(['c' => $customerId]);
    return ['biz' => ['business_name' => $biz['business_name'] ?? '', 'business_address' => $biz['business_address'] ?? ''], 'cust' => $c->fetch() ?: [], 'footer' => (string) ($biz['invoice_footer'] ?? '')];
}

/** How many claims this invoice and this address have submitted in the last hour. */
function claim_counts(int $invoiceId, string $ip): array
{
    $pdo = db();
    $st = $pdo->prepare("SELECT count(*) FILTER (WHERE invoice_id = :i), count(*) FILTER (WHERE ip_address = CAST(:ip AS inet)) FROM receipt_claims WHERE created_at > now() - interval '1 hour'");
    $st->execute(['i' => $invoiceId, 'ip' => $ip]);
    $r = $st->fetch(PDO::FETCH_NUM);
    return ['invoice' => (int) $r[0], 'ip' => (int) $r[1]];
}

/** Insert the customer's claim ("I paid this"): the facts as stated, never money. Answers the claim id. */
function submit_claim(int $invoiceId, array $f, string $ip): int
{
    $st = db()->prepare('INSERT INTO receipt_claims (invoice_id, claimed_on, method, reference, amount, note, ip_address) VALUES (:i, :d, :m, :r, :a, :n, CAST(:ip AS inet)) RETURNING id');
    $st->execute(['i' => $invoiceId, 'd' => $f['claimed_on'], 'm' => $f['method'], 'r' => $f['reference'], 'a' => $f['amount'], 'n' => $f['note'], 'ip' => $ip]);
    return (int) $st->fetchColumn();
}
