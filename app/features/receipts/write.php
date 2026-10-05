<?php
declare(strict_types=1);

/**
 * Receipts, applications and claims' writes (slice 4). The database is the referee: the receipt's number, its posting (gl_receipt_post: DR the bank named or Undeposited funds / CR receivable),
 * the applications' limits (guard triggers), void (gl_receipt_void). A DomainException is our own sentence.
 */

/** The amount an application takes when none is named: what is left on the receipt or what the invoice is owed, whichever is less. */
function application_amount(PDO $pdo, int $receiptId, int $invoiceId, ?string $named): string
{
    if ($named !== null && $named !== '') {
        return $named;
    }
    $r = $pdo->prepare('SELECT amount - COALESCE((SELECT sum(amount) FROM receipt_applications WHERE receipt_id = :id), 0) FROM receipts WHERE id = :id');
    $r->execute(['id' => $receiptId]);
    $left = (string) $r->fetchColumn();
    $i = $pdo->prepare('SELECT balance_due, status FROM invoices WHERE id = :id');
    $i->execute(['id' => $invoiceId]);
    $inv = $i->fetch() ?: throw new DomainException('Not found.');
    if (!in_array($inv['status'], OPEN_INVOICE_STATUSES, true) && m_cmp($left, '0') > 0) {
        return $left;                                  // not open: let the guard say it ("… takes no payment")
    }
    $owed = (string) $inv['balance_due'];
    $a = m_cmp($left, $owed) <= 0 ? $left : $owed;
    if (m_cmp($a, '0') <= 0) {
        throw new DomainException('There is nothing to apply: the receipt is fully applied or the invoice is owed nothing.');
    }
    return $a;
}

/** Apply (or add to an existing application of) a receipt to an invoice; the guards refuse overpaying, another customer, another currency. Answers ['invoice_number', 'amount']. */
function apply_receipt(PDO $pdo, int $receiptId, int $invoiceId, ?string $amount, int $by): array
{
    $amt = application_amount($pdo, $receiptId, $invoiceId, $amount);
    $ex = $pdo->prepare('SELECT amount FROM receipt_applications WHERE receipt_id = :r AND invoice_id = :i');
    $ex->execute(['r' => $receiptId, 'i' => $invoiceId]);
    $had = $ex->fetchColumn();
    if ($had === false) {
        $pdo->prepare('INSERT INTO receipt_applications (receipt_id, invoice_id, amount, applied_by) VALUES (:r, :i, :a, :by)')->execute(['r' => $receiptId, 'i' => $invoiceId, 'a' => $amt, 'by' => $by]);
    } else {
        $pdo->prepare('UPDATE receipt_applications SET amount = :a, applied_by = :by, applied_at = now() WHERE receipt_id = :r AND invoice_id = :i')->execute(['r' => $receiptId, 'i' => $invoiceId, 'a' => m_add((string) $had, $amt), 'by' => $by]);
    }
    $n = $pdo->prepare('SELECT number FROM invoices WHERE id = :i');
    $n->execute(['i' => $invoiceId]);
    return ['invoice_number' => (string) $n->fetchColumn(), 'amount' => $amt];
}

function unapply_receipt(PDO $pdo, int $receiptId, int $invoiceId): array
{
    $r = receipt_state($pdo, $receiptId) ?? throw new DomainException('Not found.');
    if ($r['deposit_id'] !== null) {
        throw new DomainException('Take it out of the deposit first.');
    }
    $st = $pdo->prepare('DELETE FROM receipt_applications WHERE receipt_id = :r AND invoice_id = :i RETURNING amount');
    $st->execute(['r' => $receiptId, 'i' => $invoiceId]);
    $amt = $st->fetchColumn();
    if ($amt === false) {
        throw new DomainException('That receipt is not applied to that invoice.');
    }
    $n = $pdo->prepare('SELECT number FROM invoices WHERE id = :i');
    $n->execute(['i' => $invoiceId]);
    return ['invoice_number' => (string) $n->fetchColumn(), 'amount' => $amt, 'receipt_number' => $r['number']];
}

/**
 * Record money in: insert the receipt (numbered by the trigger), post it, then apply — `invoice` first, then the explicit amounts in $apply (invoice id => amount), then
 * `apply_oldest` over the customer's open invoices in the receipt's currency, oldest due first. A claim is confirmed with the receipt. Answers the receipt id and what was applied.
 * $f: customer_id, received_on, amount, currency, method, reference, memo, deposit_to (bank account id or null), invoice_id, apply_oldest.
 */
function record_receipt(PDO $pdo, array $f, array $apply, ?int $claimId, int $by): array
{
    $st = $pdo->prepare('INSERT INTO receipts (customer_id, received_on, amount, currency, method, reference, memo, deposit_to_bank_account_id, recorded_by) VALUES (:c, :d, :a, :cur, :m, :r, :me, :b, :by) RETURNING id');
    $st->execute(['c' => $f['customer_id'], 'd' => $f['received_on'], 'a' => $f['amount'], 'cur' => $f['currency'], 'm' => $f['method'], 'r' => $f['reference'], 'me' => $f['memo'], 'b' => $f['deposit_to'], 'by' => $by]);
    $id = (int) $st->fetchColumn();
    $pdo->prepare('SELECT gl_receipt_post(:r, :by)')->execute(['r' => $id, 'by' => $by]);
    $applied = [];
    $left = $f['amount'];
    $take = static function (int $invoiceId, ?string $amount) use ($pdo, $id, $by, &$applied, &$left): void {
        $a = apply_receipt($pdo, $id, $invoiceId, $amount, $by);
        $applied[] = ['invoice_id' => $invoiceId, 'number' => $a['invoice_number'], 'amount' => $a['amount']];
        $left = m_sub($left, $a['amount']);
    };
    if ($f['invoice_id'] !== null) {
        $take($f['invoice_id'], null);
    }
    foreach ($apply as $invoiceId => $amount) {
        if ((int) $invoiceId !== $f['invoice_id'] && m_cmp($amount, '0') > 0) {
            $take((int) $invoiceId, $amount);
        }
    }
    if ($f['apply_oldest'] && m_cmp($left, '0') > 0) {
        $open = $pdo->prepare("SELECT id FROM invoices WHERE customer_id = :c AND currency = :cur AND status IN ('issued', 'sent', 'viewed', 'partially_paid') AND balance_due > 0 AND id <> COALESCE(:skip, 0) ORDER BY due_date, id");
        $open->execute(['c' => $f['customer_id'], 'cur' => $f['currency'], 'skip' => $f['invoice_id']]);
        foreach ($open->fetchAll(PDO::FETCH_COLUMN) as $invoiceId) {
            if (m_cmp($left, '0') <= 0) {
                break;
            }
            if (in_array((int) $invoiceId, array_column($applied, 'invoice_id'), true)) {
                continue;
            }
            $take((int) $invoiceId, null);
        }
    }
    if ($claimId !== null) {
        $pdo->prepare("UPDATE receipt_claims SET status = 'confirmed', receipt_id = :r, decided_by = :by, decided_at = now() WHERE id = :c")->execute(['r' => $id, 'by' => $by, 'c' => $claimId]);
    }
    return ['receipt_id' => $id, 'applied' => $applied, 'unapplied' => $left];
}

function void_receipt(PDO $pdo, int $id, int $by, string $reason): array
{
    $st = $pdo->prepare('SELECT (r).id, (r).number, (r).amount, (r).currency, (r).customer_id, (r).journal_entry_id FROM gl_receipt_void(:id, :by, :r) r');
    $st->execute(['id' => $id, 'by' => $by, 'r' => $reason]);
    $r = $st->fetch();
    return ['receipt_id' => (int) $r['id'], 'number' => $r['number'], 'amount' => $r['amount'], 'currency' => trim((string) $r['currency']), 'customer_id' => (int) $r['customer_id'], 'journal_entry_id' => $r['journal_entry_id'] === null ? null : (int) $r['journal_entry_id']];
}

/** Confirm a claim as a receipt (the claim's facts, with overrides for the amount and the deposit-to), applied to its invoice. Answers record_receipt()'s result and the claim. */
function confirm_claim(PDO $pdo, int $claimId, array $overrides, int $by): array
{
    $k = find_claim($pdo, $claimId) ?? throw new DomainException('Not found.');
    if ($k['status'] !== 'submitted') {
        throw new DomainException('That claim is already ' . $k['status'] . '.');
    }
    $inv = $pdo->prepare('SELECT customer_id, currency, balance_due FROM invoices WHERE id = :i');
    $inv->execute(['i' => $k['invoice_id']]);
    $i = $inv->fetch() ?: throw new DomainException('Not found.');
    $amount = $overrides['amount'] ?? $k['amount'] ?? $i['balance_due'];
    $r = record_receipt($pdo, ['customer_id' => (int) $i['customer_id'], 'received_on' => $k['claimed_on'], 'amount' => $amount, 'currency' => trim((string) $i['currency']), 'method' => $k['method'], 'reference' => $k['reference'],
        'memo' => 'Confirmed from the customer\'s claim', 'deposit_to' => $overrides['deposit_to'] ?? null, 'invoice_id' => $k['invoice_id'], 'apply_oldest' => false], [], $claimId, $by);
    return $r + ['claim' => $k, 'amount' => $amount, 'currency' => trim((string) $i['currency']), 'customer_id' => (int) $i['customer_id']];
}

function dismiss_claim(PDO $pdo, int $claimId, ?string $reason, int $by): array
{
    $k = find_claim($pdo, $claimId) ?? throw new DomainException('Not found.');
    if ($k['status'] !== 'submitted') {
        throw new DomainException('That claim is already ' . $k['status'] . '.');
    }
    $pdo->prepare("UPDATE receipt_claims SET status = 'dismissed', decided_by = :by, decided_at = now() WHERE id = :c")->execute(['by' => $by, 'c' => $claimId]);
    return $k;
}
