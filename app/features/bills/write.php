<?php
declare(strict_types=1);

/**
 * Bills' writes (slice 5). The database is the referee: line money and totals (triggers), the number (at creation), the approval's posting (gl_bill_approve: DR each expense account for the GROSS line total / CR AP),
 * void (gl_bill_void). Lines are read by normalize_doc_line() on the expense side. A DomainException is our own sentence.
 */

/** A vendor reference already entered: the sentence, and the bill it is. */
final class DuplicateBillRef extends DomainException
{
    public function __construct(string $message, public readonly int $billId)
    {
        parent::__construct($message);
    }
}

function assert_vendor_ref_free(PDO $pdo, int $vendorId, ?string $ref, ?int $exceptBillId): void
{
    $d = find_duplicate_vendor_ref($pdo, $vendorId, $ref, $exceptBillId);
    if ($d !== null) {
        $name = (string) $pdo->query('SELECT name FROM vendors WHERE id = ' . $vendorId)->fetchColumn();
        throw new DuplicateBillRef($name . '\'s ' . $ref . ' is already entered as ' . $d['number'] . '.', $d['id']);
    }
}

/** Draft a bill: head (vendor_id, vendor_ref, bill_date, due_date, currency, memo, department_id, site_id, source) and normalized lines. Answers the id. */
function draft_bill(PDO $pdo, array $head, array $lines, int $by): int
{
    assert_vendor_ref_free($pdo, $head['vendor_id'], $head['vendor_ref'], null);
    $st = $pdo->prepare('INSERT INTO bills (vendor_id, vendor_ref, bill_date, due_date, currency, memo, source, department_id, site_id, created_by) VALUES (:v, :r, :d, :due, :cur, :m, :src, :dept, :site, :by) RETURNING id');
    $st->execute(['v' => $head['vendor_id'], 'r' => $head['vendor_ref'], 'd' => $head['bill_date'], 'due' => $head['due_date'], 'cur' => $head['currency'], 'm' => $head['memo'], 'src' => $head['source'] ?? 'manual', 'dept' => $head['department_id'], 'site' => $head['site_id'], 'by' => $by]);
    $id = (int) $st->fetchColumn();
    foreach ($lines as $n => $l) {
        insert_doc_line($pdo, 'bill_lines', 'bill_id', $id, $n + 1, $l);
    }
    return $id;
}

/** A draft's head (keys present change). Answers ['before' => …, 'after' => …] of the changed fields. An approved bill's head is refused in the guard's words. */
function update_bill(PDO $pdo, int $id, array $head, int $by): array
{
    $cur = bill_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($cur['status'] !== 'draft') {
        throw new DomainException('Bill ' . $cur['number'] . ' is approved: its vendor, date and totals cannot change (credit it, or void it)');
    }
    $before = bill_log_state($cur);
    $after = array_intersect_key($head, $before) + $before;
    $changed = array_keys(array_filter($after, static fn ($v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    if ($changed === []) {
        return ['before' => [], 'after' => []];
    }
    assert_vendor_ref_free($pdo, (int) $after['vendor_id'], $after['vendor_ref'], $id);
    $pdo->prepare('UPDATE bills SET vendor_id = :v, vendor_ref = :r, bill_date = :d, due_date = :due, currency = :cur, memo = :m, department_id = :dept, site_id = :site WHERE id = :id')
        ->execute(['v' => $after['vendor_id'], 'r' => $after['vendor_ref'], 'd' => $after['bill_date'], 'due' => $after['due_date'], 'cur' => $after['currency'], 'm' => $after['memo'], 'dept' => $after['department_id'], 'site' => $after['site_id'], 'id' => $id]);
    return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))];
}

/** Add ($lineId null) or change a line of a draft (the trigger refuses anything else and computes the money). Answers the line id. */
function save_bill_line(PDO $pdo, int $billId, ?int $lineId, array $l): int
{
    if ($lineId === null) {
        $st = $pdo->prepare('SELECT COALESCE(max(sort_order), 0) + 1 FROM bill_lines WHERE bill_id = :i');
        $st->execute(['i' => $billId]);
        return insert_doc_line($pdo, 'bill_lines', 'bill_id', $billId, (int) $st->fetchColumn(), $l);
    }
    $st = $pdo->prepare('UPDATE bill_lines SET item_id = :i, description = :de, quantity = :q, unit_price = :p, tax_rate_id = :t, expense_account_id = :a, department_id = :dept, site_id = :site WHERE id = :id AND bill_id = :b');
    $st->execute(['i' => $l['item_id'], 'de' => $l['description'], 'q' => $l['quantity'], 'p' => $l['unit_price'], 't' => $l['tax_rate_id'], 'a' => $l['account_id'], 'dept' => $l['department_id'], 'site' => $l['site_id'], 'id' => $lineId, 'b' => $billId]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Not found.');
    }
    return $lineId;
}

function remove_bill_line(PDO $pdo, int $billId, int $lineId): bool
{
    $st = $pdo->prepare('DELETE FROM bill_lines WHERE id = :id AND bill_id = :b');
    $st->execute(['id' => $lineId, 'b' => $billId]);
    return $st->rowCount() > 0;
}

/** The bill with its totals as the trigger wrote them (for the totals panel and a line write's answer). */
function bill_totals_row(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT subtotal, tax_total, total, amount_paid, amount_credited, balance_due, currency, status FROM bills WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    $r['currency'] = trim((string) $r['currency']);
    return $r;
}

/** Approve: gl_bill_approve() posts it (the gross line totals against the vendor's payable). Answers the head, the entry's number and whether a document is attached. */
function approve_bill(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT (b).id, (b).number, (b).total, (b).currency, (b).vendor_id, (b).journal_entry_id FROM gl_bill_approve(:id, :by) b');
    $st->execute(['id' => $id, 'by' => $by]);
    $r = $st->fetch();
    $e = $pdo->prepare('SELECT number FROM journal_entries WHERE id = :e');
    $e->execute(['e' => $r['journal_entry_id']]);
    $d = $pdo->prepare("SELECT EXISTS (SELECT 1 FROM attachments WHERE record_type = 'bill' AND record_id = :b)");
    $d->execute(['b' => $id]);
    return ['bill_id' => (int) $r['id'], 'number' => $r['number'], 'total' => $r['total'], 'currency' => trim((string) $r['currency']), 'vendor_id' => (int) $r['vendor_id'], 'journal_entry_id' => (int) $r['journal_entry_id'],
            'journal_entry_number' => (string) $e->fetchColumn(), 'has_document' => (bool) $d->fetchColumn()];
}

function void_bill(PDO $pdo, int $id, int $by, string $reason): array
{
    $st = $pdo->prepare('SELECT (b).id, (b).number, (b).total, (b).currency, (b).vendor_id, (b).journal_entry_id FROM gl_bill_void(:id, :by, :r) b');
    $st->execute(['id' => $id, 'by' => $by, 'r' => $reason]);
    $r = $st->fetch();
    return ['bill_id' => (int) $r['id'], 'number' => $r['number'], 'total' => $r['total'], 'currency' => trim((string) $r['currency']), 'vendor_id' => (int) $r['vendor_id'], 'journal_entry_id' => $r['journal_entry_id'] === null ? null : (int) $r['journal_entry_id']];
}

/** Delete a draft (its lines first: a cascade would meet the line trigger with the bill already gone). */
function delete_bill(PDO $pdo, int $id): array
{
    $s = bill_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($s['status'] !== 'draft') {
        throw new DomainException('Bill ' . $s['number'] . ' is approved and is never deleted; void it');
    }
    $pdo->prepare('DELETE FROM bill_lines WHERE bill_id = :i')->execute(['i' => $id]);
    $pdo->prepare('DELETE FROM bills WHERE id = :i')->execute(['i' => $id]);
    return $s;
}

/** The prefill of a copy: the old bill's vendor and lines, today's dates, no reference. */
function copy_bill(PDO $pdo, int $id): array
{
    $b = find_bill($pdo, $id) ?? throw new DomainException('Not found.');
    $rows = [];
    foreach ($b['lines'] as $l) {
        $rows[] = ['item' => $l['item_id'], 'description' => $l['description'], 'quantity' => rtrim(rtrim((string) $l['quantity'], '0'), '.'), 'unit_price' => $l['unit_price'], 'tax_rate' => $l['tax_rate_id'], 'expense_account' => $l['expense_account_id'], 'department' => $l['department_id'], 'site' => $l['site_id']];
    }
    return ['vendor_id' => $b['vendor_id'], 'currency' => $b['currency'], 'memo' => $b['memo'], 'department_id' => $b['department_id'], 'site_id' => $b['site_id'], 'lines' => $rows];
}

/** Tell the approvers a bill awaits (in-app rows now; the email is slice 8's). */
function notify_bill_awaiting(PDO $pdo, int $billId, string $number, string $vendorName, string $total, string $currency): void
{
    notify_members_with_right('ap.approve', 'bill_awaiting', 'bill', $billId, $number . ' awaits approval', $vendorName . ', ' . fmt_money($total, $currency));
}
