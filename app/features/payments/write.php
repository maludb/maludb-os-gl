<?php
declare(strict_types=1);

/**
 * Payments' writes (slice 5). The database is the referee: the number (at creation), the applications' limits (guard triggers), approval (gl_payment_approve), the posting at record (gl_payment_record: DR AP (vendor) / CR the bank's ledger account), void (gl_payment_void).
 * Money OUT is prepared by the bookkeeper, approved by the controller, recorded when it has moved. A DomainException is our own sentence.
 */

/** Draft a payment: f = vendor_id, payment_date, amount, currency, method, cheque_number, bank_account_id, reference, memo, payment_run_id. `$billIds` are applied oldest due first up to the amount. Answers the id. */
function draft_payment(PDO $pdo, array $f, array $billIds, int $by): int
{
    $st = $pdo->prepare('INSERT INTO payments (vendor_id, payment_date, amount, currency, method, cheque_number, bank_account_id, reference, memo, payment_run_id, created_by) VALUES (:v, :d, :a, :cur, :m, :cn, :b, :r, :me, :run, :by) RETURNING id');
    $st->execute(['v' => $f['vendor_id'], 'd' => $f['payment_date'], 'a' => $f['amount'], 'cur' => $f['currency'], 'm' => $f['method'], 'cn' => $f['cheque_number'], 'b' => $f['bank_account_id'], 'r' => $f['reference'], 'me' => $f['memo'], 'run' => $f['payment_run_id'], 'by' => $by]);
    $id = (int) $st->fetchColumn();
    $left = $f['amount'];
    foreach (order_bills_by_due($pdo, $billIds) as $billId) {
        if (m_cmp($left, '0') <= 0) {
            break;
        }
        $a = apply_payment($pdo, $id, $billId, null, $by);
        $left = m_sub($left, $a['amount']);
    }
    return $id;
}

/** The bill ids sorted oldest due first (an id that is not a bill stays last and is met by the guard). */
function order_bills_by_due(PDO $pdo, array $billIds): array
{
    if ($billIds === []) {
        return [];
    }
    $in = implode(',', array_map('intval', $billIds));
    $ordered = array_map('intval', $pdo->query("SELECT id FROM bills WHERE id IN ({$in}) ORDER BY due_date, id")->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_unique(array_merge($ordered, array_map('intval', $billIds))));
}

/** A draft or approved payment's fields (keys present change). A draft may change anything; an approved one only date, method, cheque number, reference, memo (the guard says the rest). Answers ['before', 'after']. */
function update_payment(PDO $pdo, int $id, array $f, int $by): array
{
    $cur = payment_state($pdo, $id) ?? throw new DomainException('Not found.');
    $before = payment_log_state($cur);
    $after = array_intersect_key($f, $before) + $before;
    $changed = array_keys(array_filter($after, static fn ($v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    if ($changed === []) {
        return ['before' => [], 'after' => []];
    }
    $applied = (string) $pdo->query('SELECT COALESCE(sum(amount), 0) FROM payment_applications WHERE payment_id = ' . $id)->fetchColumn();
    if (m_cmp((string) $after['amount'], $applied) < 0) {
        throw new DomainException('Payment ' . $cur['number'] . ' already pays ' . number_format((float) $applied, 2) . '; unapply a bill before reducing it to ' . number_format((float) $after['amount'], 2) . '.');
    }
    $pdo->prepare('UPDATE payments SET vendor_id = :v, payment_date = :d, amount = :a, currency = :cur, method = :m, cheque_number = :cn, bank_account_id = :b, reference = :r, memo = :me, payment_run_id = :run WHERE id = :id')
        ->execute(['v' => $after['vendor_id'], 'd' => $after['payment_date'], 'a' => $after['amount'], 'cur' => $after['currency'], 'm' => $after['method'], 'cn' => $after['cheque_number'], 'b' => $after['bank_account_id'], 'r' => $after['reference'], 'me' => $after['memo'], 'run' => $after['payment_run_id'], 'id' => $id]);
    return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))];
}

/** Apply (or add to an application of) a payment to a bill: what fits when no amount is named (the payment's unapplied or the bill's payable, whichever is less). */
function apply_payment(PDO $pdo, int $paymentId, int $billId, ?string $amount, int $by): array
{
    if ($amount === null || $amount === '') {
        $left = payment_unapplied($pdo, $paymentId);
        $b = $pdo->prepare('SELECT status FROM bills WHERE id = :b');
        $b->execute(['b' => $billId]);
        $status = $b->fetchColumn();
        if ($status === false) {
            throw new DomainException('Not found.');
        }
        $owed = bill_payable($pdo, $billId, $paymentId);
        $amount = !in_array($status, OPEN_BILL_STATUSES, true) || m_cmp($owed, '0') <= 0 ? $left : (m_cmp($left, $owed) <= 0 ? $left : $owed);          // not open, or nothing payable: let the guard say it
        if (m_cmp($amount, '0') <= 0) {
            throw new DomainException('There is nothing to apply: the payment is fully applied or the bill is owed nothing.');
        }
    }
    $ex = $pdo->prepare('SELECT amount FROM payment_applications WHERE payment_id = :p AND bill_id = :b');
    $ex->execute(['p' => $paymentId, 'b' => $billId]);
    $had = $ex->fetchColumn();
    if ($had === false) {
        $pdo->prepare('INSERT INTO payment_applications (payment_id, bill_id, amount, applied_by) VALUES (:p, :b, :a, :by)')->execute(['p' => $paymentId, 'b' => $billId, 'a' => $amount, 'by' => $by]);
    } else {
        $pdo->prepare('UPDATE payment_applications SET amount = :a, applied_by = :by, applied_at = now() WHERE payment_id = :p AND bill_id = :b')->execute(['p' => $paymentId, 'b' => $billId, 'a' => m_add((string) $had, $amount), 'by' => $by]);
    }
    $n = $pdo->prepare('SELECT number FROM bills WHERE id = :b');
    $n->execute(['b' => $billId]);
    return ['bill_id' => $billId, 'bill_number' => (string) $n->fetchColumn(), 'amount' => $amount];
}

function unapply_payment(PDO $pdo, int $paymentId, int $billId): array
{
    $p = payment_state($pdo, $paymentId) ?? throw new DomainException('Not found.');
    if ($p['status'] === 'recorded' || $p['status'] === 'void') {
        throw new DomainException('Payment ' . $p['number'] . ' is ' . $p['status'] . '; its applications are fixed');
    }
    $st = $pdo->prepare('DELETE FROM payment_applications WHERE payment_id = :p AND bill_id = :b RETURNING amount');
    $st->execute(['p' => $paymentId, 'b' => $billId]);
    $amt = $st->fetchColumn();
    if ($amt === false) {
        throw new DomainException('That payment is not applied to that bill.');
    }
    $n = $pdo->prepare('SELECT number FROM bills WHERE id = :b');
    $n->execute(['b' => $billId]);
    return ['bill_number' => (string) $n->fetchColumn(), 'amount' => $amt, 'payment_number' => $p['number']];
}

/** The sentence when a payment is not fully applied. */
function assert_fully_applied(PDO $pdo, int $id): void
{
    $p = payment_state($pdo, $id) ?? throw new DomainException('Not found.');
    $un = payment_unapplied($pdo, $id);
    if (m_cmp($un, '0') !== 0 && in_array($p['status'], ['draft', 'approved'], true)) {
        throw new DomainException('The payment\'s ' . number_format((float) $p['amount'], 2) . ' is not fully applied: ' . number_format((float) $un, 2) . ' is unapplied — apply it or reduce the amount.');
    }
}

/** Approve: the amount must equal what is applied; gl_payment_approve() does the rest. Answers the head and the applied list. */
function approve_payment(PDO $pdo, int $id, int $by): array
{
    assert_fully_applied($pdo, $id);
    $st = $pdo->prepare('SELECT (p).id, (p).number, (p).amount, (p).currency, (p).vendor_id, (p).bank_account_id FROM gl_payment_approve(:id, :by) p');
    $st->execute(['id' => $id, 'by' => $by]);
    $r = $st->fetch();
    return ['payment_id' => (int) $r['id'], 'number' => $r['number'], 'amount' => $r['amount'], 'currency' => trim((string) $r['currency']), 'vendor_id' => (int) $r['vendor_id'], 'bank_account_id' => (int) $r['bank_account_id'], 'applied' => payment_applied_list($pdo, $id)];
}

function payment_applied_list(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT pa.bill_id, pa.bill_number AS number, pa.amount FROM mcp_payment_applications pa WHERE pa.payment_id = :id ORDER BY pa.bill_id');
    $st->execute(['id' => $id]);
    return array_map(static function (array $x): array { $x['bill_id'] = (int) $x['bill_id']; return $x; }, $st->fetchAll());
}

/** Record: the money moved. Date, cheque number and reference may be set now; a cheque needs its number; gl_payment_record() posts it and the bills are recalculated. */
function record_payment(PDO $pdo, int $id, array $f, int $by): array
{
    $p = payment_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($p['status'] !== 'approved') {
        throw new DomainException('Payment ' . $p['number'] . ' is ' . $p['status'] . '; it must be approved before it is recorded');
    }
    assert_fully_applied($pdo, $id);
    $date = $f['payment_date'] ?? $p['payment_date'];
    $cheque = array_key_exists('cheque_number', $f) ? $f['cheque_number'] : $p['cheque_number'];
    $ref = array_key_exists('reference', $f) ? $f['reference'] : $p['reference'];
    if ($p['method'] === 'cheque' && ($cheque === null || $cheque === '')) {
        throw new DomainException('Payment ' . $p['number'] . ' is a cheque: give its cheque number.');
    }
    $pdo->prepare('UPDATE payments SET payment_date = :d, cheque_number = :c, reference = :r WHERE id = :id')->execute(['d' => $date, 'c' => $cheque, 'r' => $ref, 'id' => $id]);
    $st = $pdo->prepare('SELECT (p).id, (p).number, (p).amount, (p).currency, (p).method, (p).cheque_number, (p).vendor_id, (p).bank_account_id, (p).journal_entry_id, (p).payment_date FROM gl_payment_record(:id, :by) p');
    $st->execute(['id' => $id, 'by' => $by]);
    $r = $st->fetch();
    return ['payment_id' => (int) $r['id'], 'number' => $r['number'], 'amount' => $r['amount'], 'currency' => trim((string) $r['currency']), 'method' => $r['method'], 'cheque_number' => $r['cheque_number'], 'vendor_id' => (int) $r['vendor_id'],
            'bank_account_id' => (int) $r['bank_account_id'], 'journal_entry_id' => (int) $r['journal_entry_id'], 'payment_date' => $r['payment_date'], 'applied' => payment_applied_list($pdo, $id)];
}

function void_payment(PDO $pdo, int $id, int $by, string $reason): array
{
    $st = $pdo->prepare('SELECT (p).id, (p).number, (p).amount, (p).currency, (p).vendor_id, (p).bank_account_id, (p).journal_entry_id FROM gl_payment_void(:id, :by, :r) p');
    $st->execute(['id' => $id, 'by' => $by, 'r' => $reason]);
    $r = $st->fetch();
    return ['payment_id' => (int) $r['id'], 'number' => $r['number'], 'amount' => $r['amount'], 'currency' => trim((string) $r['currency']), 'vendor_id' => (int) $r['vendor_id'], 'bank_account_id' => (int) $r['bank_account_id'], 'journal_entry_id' => $r['journal_entry_id'] === null ? null : (int) $r['journal_entry_id']];
}

/** Delete a draft payment (its applications first). */
function delete_payment(PDO $pdo, int $id): array
{
    $p = payment_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($p['status'] !== 'draft') {
        throw new DomainException('Payment ' . $p['number'] . ' is ' . $p['status'] . ' and is never deleted; void it');
    }
    $pdo->prepare('DELETE FROM payment_applications WHERE payment_id = :i')->execute(['i' => $id]);
    $pdo->prepare('DELETE FROM payments WHERE id = :i')->execute(['i' => $id]);
    return $p;
}

/** Tell the approvers a payment (or a run) awaits. */
function notify_payment_awaiting(string $type, int $id, string $number, string $amount, string $currency): void
{
    notify_members_with_right('ap.approve', 'payment_run_awaiting', $type, $id, $number . ' awaits approval', fmt_money($amount, $currency));
}
