<?php
declare(strict_types=1);

/** Payments (slice 5): reads through mcp_payments / mcp_payment_applications; the bills a payment may take; what the guards compute for "owed". Money is a numeric string. */

const PAYMENT_STATUSES = ['draft' => 'Draft', 'approved' => 'Approved', 'recorded' => 'Recorded', 'void' => 'Void'];
const PAYMENT_STATUS_BADGE = ['draft' => 'secondary', 'approved' => 'info', 'recorded' => 'success', 'void' => 'dark'];
const PAYMENT_PAGE = 50;

function payment_cast(array $p): array
{
    foreach (['payment_id', 'vendor_id', 'bank_account_id', 'payment_run_id', 'journal_entry_id', 'recorded_by', 'approved_by', 'created_by'] as $k) {
        $p[$k] = ($p[$k] ?? null) === null ? null : (int) $p[$k];
    }
    $p['currency'] = trim((string) $p['currency']);
    return $p;
}

function payment_where(array $f): array
{
    $where = [];
    $args = [];
    if (($f['status'] ?? '') !== '' && isset(PAYMENT_STATUSES[$f['status']])) {
        $where[] = 'p.status = :st';
        $args['st'] = $f['status'];
    }
    foreach (['vendor' => ['p.vendor_id = :v', 'v'], 'bank_account' => ['p.bank_account_id = :b', 'b'], 'run' => ['p.payment_run_id = :r', 'r']] as $k => [$sql, $n]) {
        if (($f[$k] ?? null) !== null && $f[$k] !== '') {
            $where[] = $sql;
            $args[$n] = (int) $f[$k];
        }
    }
    foreach (['from' => 'p.payment_date >= :from', 'to' => 'p.payment_date <= :to'] as $k => $sql) {
        if (($f[$k] ?? '') !== '') {
            $where[] = $sql;
            $args[$k] = $f[$k];
        }
    }
    return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $args];
}

function find_payments(PDO $pdo, array $filters, int $limit = PAYMENT_PAGE, int $offset = 0): array
{
    [$w, $args] = payment_where($filters);
    $st = $pdo->prepare('SELECT p.* FROM mcp_payments p' . $w . ' ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset);
    $st->execute($args);
    return array_map('payment_cast', $st->fetchAll());
}

function count_payments(PDO $pdo, array $filters): int
{
    [$w, $args] = payment_where($filters);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_payments p' . $w);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

/** One payment with its applications, its entry's number and the bank line it matched (slice 6 fills that view; absent until then). */
function find_payment(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_payments WHERE payment_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $p = payment_cast($r);
    $a = $pdo->prepare('SELECT pa.bill_id, pa.bill_number, pa.vendor_ref, pa.amount, pa.applied_at, b.due_date, b.balance_due, b.total AS bill_total FROM mcp_payment_applications pa JOIN mcp_bills b ON b.bill_id = pa.bill_id WHERE pa.payment_id = :id ORDER BY b.due_date, pa.bill_id');
    $a->execute(['id' => $id]);
    $p['applications'] = array_map(static function (array $x): array { $x['bill_id'] = (int) $x['bill_id']; return $x; }, $a->fetchAll());
    $e = $pdo->prepare('SELECT number FROM mcp_journal_entries WHERE journal_entry_id = :e');
    $e->execute(['e' => $p['journal_entry_id']]);
    $p['journal_entry_number'] = $p['journal_entry_id'] === null ? null : ($e->fetchColumn() ?: null);
    $p['matched_line'] = null;
    try {
        $m = $pdo->prepare("SELECT bank_transaction_id, txn_date, amount FROM mcp_bank_transactions WHERE matched_type = 'payment' AND matched_id = :id AND status = 'matched'");
        $m->execute(['id' => $id]);
        $p['matched_line'] = $m->fetch() ?: null;
    } catch (PDOException) {
    }
    $p['unapplied'] = m_sub((string) $p['amount'], (string) $p['amount_applied']);
    return $p;
}

function payment_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS payment_id, number, vendor_id, payment_date, amount, currency, method, cheque_number, bank_account_id, reference, memo, status, payment_run_id, journal_entry_id FROM payments WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : payment_cast($r);
}

function payment_log_state(array $s): array
{
    return array_intersect_key($s, array_flip(['vendor_id', 'payment_date', 'amount', 'currency', 'method', 'cheque_number', 'bank_account_id', 'reference', 'memo', 'payment_run_id']));
}

/** What is not yet applied to a bill on the payment (amount minus its applications). */
function payment_unapplied(PDO $pdo, int $id): string
{
    $st = $pdo->prepare('SELECT amount - COALESCE((SELECT sum(amount) FROM payment_applications WHERE payment_id = :id), 0) FROM payments WHERE id = :id');
    $st->execute(['id' => $id]);
    return (string) $st->fetchColumn();
}

/** What a payment may still apply to a bill: the bill's total less credits and what other live payments already took (the guard's own sum). */
function bill_payable(PDO $pdo, int $billId, ?int $exceptPaymentId = null): string
{
    $st = $pdo->prepare("SELECT b.total - b.amount_credited - COALESCE((SELECT sum(pa.amount) FROM payment_applications pa JOIN payments p ON p.id = pa.payment_id AND p.status <> 'void' WHERE pa.bill_id = b.id AND pa.payment_id IS DISTINCT FROM :x), 0) FROM bills b WHERE b.id = :b");
    $st->execute(['b' => $billId, 'x' => $exceptPaymentId]);
    return (string) ($st->fetchColumn() ?: '0');
}

/** A vendor's open bills in one currency with what is still payable on each (for the payment form), oldest due first. */
function payable_bills(PDO $pdo, int $vendorId, ?string $currency = null, ?int $exceptPaymentId = null): array
{
    $st = $pdo->prepare("SELECT bill_id, number, vendor_ref, due_date, currency, total, balance_due, overdue, days_overdue FROM mcp_bills WHERE vendor_id = :v AND status IN ('approved', 'partially_paid') AND (CAST(:c AS text) IS NULL OR currency = :c) ORDER BY due_date, bill_id");
    $st->execute(['v' => $vendorId, 'c' => $currency]);
    $out = [];
    foreach ($st->fetchAll() as $b) {
        $b['bill_id'] = (int) $b['bill_id'];
        $b['currency'] = trim((string) $b['currency']);
        $b['payable'] = bill_payable($pdo, $b['bill_id'], $exceptPaymentId);
        if (m_cmp($b['payable'], '0') > 0) {
            $out[] = $b;
        }
    }
    return $out;
}

/** Draft or approved payments of a bank account and currency that are in no run — what a run may add. */
function runless_payments(PDO $pdo, int $bankId, string $currency): array
{
    $st = $pdo->prepare("SELECT payment_id, number, vendor_name, amount, currency, method, status FROM mcp_payments WHERE status = 'draft' AND payment_run_id IS NULL AND bank_account_id = :b AND currency = :c ORDER BY payment_id");
    $st->execute(['b' => $bankId, 'c' => $currency]);
    return array_map(static function (array $p): array { $p['payment_id'] = (int) $p['payment_id']; $p['currency'] = trim((string) $p['currency']); return $p; }, $st->fetchAll());
}
