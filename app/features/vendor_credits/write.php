<?php
declare(strict_types=1);

/** Vendor credits' writes (slice 5): drafted like a bill (the lines editor and normalize_doc_line are shared), issued by gl_vendor_credit_issue(), applied through vendor_credit_applications (guarded), voided here (no gl_vendor_credit_void: the guard refuses an applied one, the entry is reversed). */

function draft_vendor_credit(PDO $pdo, array $head, array $lines, int $by): int
{
    $st = $pdo->prepare('INSERT INTO vendor_credits (vendor_id, bill_id, vendor_ref, credit_date, currency, reason, department_id, site_id, created_by) VALUES (:v, :b, :r, :d, :cur, :re, :dept, :site, :by) RETURNING id');
    $st->execute(['v' => $head['vendor_id'], 'b' => $head['bill_id'], 'r' => $head['vendor_ref'], 'd' => $head['credit_date'], 'cur' => $head['currency'], 're' => $head['reason'], 'dept' => $head['department_id'], 'site' => $head['site_id'], 'by' => $by]);
    $id = (int) $st->fetchColumn();
    foreach ($lines as $n => $l) {
        insert_doc_line($pdo, 'vendor_credit_lines', 'vendor_credit_id', $id, $n + 1, $l);
    }
    return $id;
}

function update_vendor_credit(PDO $pdo, int $id, array $head, int $by): array
{
    $cur = vendor_credit_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($cur['status'] !== 'draft') {
        throw new DomainException('Vendor credit ' . $cur['number'] . ' is ' . $cur['status'] . ' and cannot change');
    }
    $before = vendor_credit_log_state($cur);
    $after = array_intersect_key($head, $before) + $before;
    $changed = array_keys(array_filter($after, static fn ($v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    if ($changed === []) {
        return ['before' => [], 'after' => []];
    }
    $pdo->prepare('UPDATE vendor_credits SET vendor_id = :v, bill_id = :b, vendor_ref = :r, credit_date = :d, currency = :cur, reason = :re, department_id = :dept, site_id = :site WHERE id = :id')
        ->execute(['v' => $after['vendor_id'], 'b' => $after['bill_id'], 'r' => $after['vendor_ref'], 'd' => $after['credit_date'], 'cur' => $after['currency'], 're' => $after['reason'], 'dept' => $after['department_id'], 'site' => $after['site_id'], 'id' => $id]);
    return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))];
}

function save_vendor_credit_line(PDO $pdo, int $id, ?int $lineId, array $l): int
{
    if ($lineId === null) {
        $st = $pdo->prepare('SELECT COALESCE(max(sort_order), 0) + 1 FROM vendor_credit_lines WHERE vendor_credit_id = :i');
        $st->execute(['i' => $id]);
        return insert_doc_line($pdo, 'vendor_credit_lines', 'vendor_credit_id', $id, (int) $st->fetchColumn(), $l);
    }
    $st = $pdo->prepare('UPDATE vendor_credit_lines SET description = :de, quantity = :q, unit_price = :p, tax_rate_id = :t, expense_account_id = :a, department_id = :dept, site_id = :site WHERE id = :id AND vendor_credit_id = :c');
    $st->execute(['de' => $l['description'], 'q' => $l['quantity'], 'p' => $l['unit_price'], 't' => $l['tax_rate_id'], 'a' => $l['account_id'], 'dept' => $l['department_id'], 'site' => $l['site_id'], 'id' => $lineId, 'c' => $id]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Not found.');
    }
    return $lineId;
}

function remove_vendor_credit_line(PDO $pdo, int $id, int $lineId): bool
{
    $st = $pdo->prepare('DELETE FROM vendor_credit_lines WHERE id = :id AND vendor_credit_id = :c');
    $st->execute(['id' => $lineId, 'c' => $id]);
    return $st->rowCount() > 0;
}

/** Issue (posted); `$applyTo` applies it to a bill at once, what fits. */
function issue_vendor_credit(PDO $pdo, int $id, int $by, ?int $applyTo): array
{
    $st = $pdo->prepare('SELECT (c).id, (c).number, (c).total, (c).currency, (c).vendor_id, (c).journal_entry_id FROM gl_vendor_credit_issue(:id, :by) c');
    $st->execute(['id' => $id, 'by' => $by]);
    $r = $st->fetch();
    $e = $pdo->prepare('SELECT number FROM journal_entries WHERE id = :e');
    $e->execute(['e' => $r['journal_entry_id']]);
    $out = ['vendor_credit_id' => (int) $r['id'], 'number' => $r['number'], 'total' => $r['total'], 'currency' => trim((string) $r['currency']), 'vendor_id' => (int) $r['vendor_id'], 'journal_entry_id' => (int) $r['journal_entry_id'], 'journal_entry_number' => (string) $e->fetchColumn(), 'applied' => null];
    if ($applyTo !== null) {
        $out['applied'] = apply_vendor_credit($pdo, $id, $applyTo, null, $by);
    }
    return $out;
}

/** Apply (or add to an application of) an issued vendor credit to a bill: what fits when no amount is named. */
function apply_vendor_credit(PDO $pdo, int $creditId, int $billId, ?string $amount, int $by): array
{
    if ($amount === null || $amount === '') {
        $c = $pdo->prepare('SELECT total - COALESCE((SELECT sum(amount) FROM vendor_credit_applications WHERE vendor_credit_id = :c), 0) FROM vendor_credits WHERE id = :c');
        $c->execute(['c' => $creditId]);
        $left = (string) $c->fetchColumn();
        $b = $pdo->prepare('SELECT balance_due, status FROM bills WHERE id = :b');
        $b->execute(['b' => $billId]);
        $bill = $b->fetch() ?: throw new DomainException('Not found.');
        $owed = (string) $bill['balance_due'];
        $amount = !in_array($bill['status'], OPEN_BILL_STATUSES, true) ? $left : (m_cmp($left, $owed) <= 0 ? $left : $owed);      // not open: let the guard say it
        if (m_cmp($amount, '0') <= 0) {
            throw new DomainException('There is nothing to apply: the credit is fully applied or the bill is owed nothing.');
        }
    }
    $ex = $pdo->prepare('SELECT amount FROM vendor_credit_applications WHERE vendor_credit_id = :c AND bill_id = :b');
    $ex->execute(['c' => $creditId, 'b' => $billId]);
    $had = $ex->fetchColumn();
    if ($had === false) {
        $pdo->prepare('INSERT INTO vendor_credit_applications (vendor_credit_id, bill_id, amount, applied_by) VALUES (:c, :b, :a, :by)')->execute(['c' => $creditId, 'b' => $billId, 'a' => $amount, 'by' => $by]);
    } else {
        $pdo->prepare('UPDATE vendor_credit_applications SET amount = :a, applied_by = :by, applied_at = now() WHERE vendor_credit_id = :c AND bill_id = :b')->execute(['c' => $creditId, 'b' => $billId, 'a' => m_add((string) $had, $amount), 'by' => $by]);
    }
    $n = $pdo->prepare('SELECT number FROM bills WHERE id = :b');
    $n->execute(['b' => $billId]);
    return ['bill_id' => $billId, 'bill_number' => (string) $n->fetchColumn(), 'amount' => $amount];
}

function unapply_vendor_credit(PDO $pdo, int $creditId, int $billId): array
{
    $st = $pdo->prepare('DELETE FROM vendor_credit_applications WHERE vendor_credit_id = :c AND bill_id = :b RETURNING amount');
    $st->execute(['c' => $creditId, 'b' => $billId]);
    $amt = $st->fetchColumn();
    if ($amt === false) {
        throw new DomainException('That vendor credit is not applied to that bill.');
    }
    $n = $pdo->prepare('SELECT number FROM bills WHERE id = :b');
    $n->execute(['b' => $billId]);
    return ['bill_number' => (string) $n->fetchColumn(), 'amount' => $amt];
}

/** Void an issued, unapplied vendor credit: the guard refuses an applied one ("unapply it first"); its entry is reversed. */
function void_vendor_credit(PDO $pdo, int $id, int $by, string $reason): array
{
    if (trim($reason) === '') {
        throw new DomainException('Voiding a vendor credit needs a reason');
    }
    $c = vendor_credit_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($c['status'] !== 'issued') {
        throw new DomainException('Vendor credit ' . $c['number'] . ' is ' . $c['status'] . ' and is not voided');
    }
    $pdo->prepare("UPDATE vendor_credits SET status = 'void', voided_at = now(), voided_by = :by, void_reason = :r WHERE id = :id")->execute(['by' => $by, 'r' => $reason, 'id' => $id]);
    $je = $pdo->prepare('SELECT journal_entry_id FROM vendor_credits WHERE id = :id');
    $je->execute(['id' => $id]);
    $entry = $je->fetchColumn();
    if ($entry !== false && $entry !== null) {
        $pdo->prepare('SELECT gl_entry_reverse(:e, :by, :r)')->execute(['e' => $entry, 'by' => $by, 'r' => 'Vendor credit ' . $c['number'] . ' voided: ' . $reason]);
    }
    return $c + ['journal_entry_id' => $entry === false || $entry === null ? null : (int) $entry];
}

function delete_vendor_credit(PDO $pdo, int $id): array
{
    $c = vendor_credit_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($c['status'] !== 'draft') {
        throw new DomainException('Vendor credit ' . $c['number'] . ' is ' . $c['status'] . '; only a draft is deleted.');
    }
    $pdo->prepare('DELETE FROM vendor_credit_lines WHERE vendor_credit_id = :i')->execute(['i' => $id]);
    $pdo->prepare('DELETE FROM vendor_credits WHERE id = :i')->execute(['i' => $id]);
    return $c;
}
