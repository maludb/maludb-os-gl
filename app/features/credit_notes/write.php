<?php
declare(strict_types=1);

/** Credit notes' writes (slice 4): drafted like an invoice (the lines editor and normalize_doc_line are shared), issued by gl_credit_note_issue(), applied through credit_applications (guarded), voided here (no gl_credit_note_void: the guard refuses an applied one, the entry is reversed). */

function draft_credit_note(PDO $pdo, array $head, array $lines, int $by): int
{
    $st = $pdo->prepare('INSERT INTO credit_notes (customer_id, invoice_id, issue_date, currency, reason, department_id, site_id, created_by) VALUES (:c, :i, :d, :cur, :r, :dept, :site, :by) RETURNING id');
    $st->execute(['c' => $head['customer_id'], 'i' => $head['invoice_id'], 'd' => $head['issue_date'], 'cur' => $head['currency'], 'r' => $head['reason'], 'dept' => $head['department_id'], 'site' => $head['site_id'], 'by' => $by]);
    $id = (int) $st->fetchColumn();
    foreach ($lines as $n => $l) {
        insert_doc_line($pdo, 'credit_note_lines', 'credit_note_id', $id, $n + 1, $l);
    }
    return $id;
}

function update_credit_note(PDO $pdo, int $id, array $head, int $by): array
{
    $cur = credit_note_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($cur['status'] !== 'draft') {
        throw new DomainException('Credit note ' . ($cur['number'] ?? '#' . $id) . ' is ' . $cur['status'] . ' and cannot change');
    }
    $before = credit_note_log_state($cur);
    $after = array_intersect_key($head, $before) + $before;
    $changed = array_keys(array_filter($after, static fn ($v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    if ($changed === []) {
        return ['before' => [], 'after' => []];
    }
    $pdo->prepare('UPDATE credit_notes SET customer_id = :c, invoice_id = :i, issue_date = :d, currency = :cur, reason = :r, department_id = :dept, site_id = :site WHERE id = :id')
        ->execute(['c' => $after['customer_id'], 'i' => $after['invoice_id'], 'd' => $after['issue_date'], 'cur' => $after['currency'], 'r' => $after['reason'], 'dept' => $after['department_id'], 'site' => $after['site_id'], 'id' => $id]);
    return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))];
}

function save_credit_note_line(PDO $pdo, int $id, ?int $lineId, array $l): int
{
    if ($lineId === null) {
        $st = $pdo->prepare('SELECT COALESCE(max(sort_order), 0) + 1 FROM credit_note_lines WHERE credit_note_id = :i');
        $st->execute(['i' => $id]);
        return insert_doc_line($pdo, 'credit_note_lines', 'credit_note_id', $id, (int) $st->fetchColumn(), $l);
    }
    $st = $pdo->prepare('UPDATE credit_note_lines SET item_id = :i, description = :de, quantity = :q, unit_price = :p, tax_rate_id = :t, income_account_id = :a, department_id = :dept, site_id = :site WHERE id = :id AND credit_note_id = :cn');
    $st->execute(['i' => $l['item_id'], 'de' => $l['description'], 'q' => $l['quantity'], 'p' => $l['unit_price'], 't' => $l['tax_rate_id'], 'a' => $l['income_account_id'], 'dept' => $l['department_id'], 'site' => $l['site_id'], 'id' => $lineId, 'cn' => $id]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Not found.');
    }
    return $lineId;
}

function remove_credit_note_line(PDO $pdo, int $id, int $lineId): bool
{
    $st = $pdo->prepare('DELETE FROM credit_note_lines WHERE id = :id AND credit_note_id = :c');
    $st->execute(['id' => $lineId, 'c' => $id]);
    return $st->rowCount() > 0;
}

/** Issue (numbered, posted); `$applyTo` applies it to an invoice at once, what fits. */
function issue_credit_note(PDO $pdo, int $id, int $by, ?int $applyTo): array
{
    $st = $pdo->prepare('SELECT (c).id, (c).number, (c).total, (c).currency, (c).customer_id, (c).journal_entry_id FROM gl_credit_note_issue(:id, :by) c');
    $st->execute(['id' => $id, 'by' => $by]);
    $r = $st->fetch();
    $e = $pdo->prepare('SELECT number FROM journal_entries WHERE id = :e');
    $e->execute(['e' => $r['journal_entry_id']]);
    $out = ['credit_note_id' => (int) $r['id'], 'number' => $r['number'], 'total' => $r['total'], 'currency' => trim((string) $r['currency']), 'customer_id' => (int) $r['customer_id'], 'journal_entry_id' => (int) $r['journal_entry_id'], 'journal_entry_number' => (string) $e->fetchColumn(), 'applied' => null];
    if ($applyTo !== null) {
        $out['applied'] = apply_credit($pdo, $id, $applyTo, null, $by);
    }
    return $out;
}

/** Apply (or add to an application of) an issued credit note to an invoice: what fits when no amount is named. */
function apply_credit(PDO $pdo, int $creditId, int $invoiceId, ?string $amount, int $by): array
{
    if ($amount === null || $amount === '') {
        $c = $pdo->prepare('SELECT total - COALESCE((SELECT sum(amount) FROM credit_applications WHERE credit_note_id = :c), 0) FROM credit_notes WHERE id = :c');
        $c->execute(['c' => $creditId]);
        $left = (string) $c->fetchColumn();
        $i = $pdo->prepare('SELECT balance_due FROM invoices WHERE id = :i');
        $i->execute(['i' => $invoiceId]);
        $owed = (string) $i->fetchColumn();
        $amount = m_cmp($left, $owed) <= 0 ? $left : $owed;
        if (m_cmp($amount, '0') <= 0) {
            throw new DomainException('There is nothing to apply: the credit is fully applied or the invoice is owed nothing.');
        }
    }
    $ex = $pdo->prepare('SELECT amount FROM credit_applications WHERE credit_note_id = :c AND invoice_id = :i');
    $ex->execute(['c' => $creditId, 'i' => $invoiceId]);
    $had = $ex->fetchColumn();
    if ($had === false) {
        $pdo->prepare('INSERT INTO credit_applications (credit_note_id, invoice_id, amount, applied_by) VALUES (:c, :i, :a, :by)')->execute(['c' => $creditId, 'i' => $invoiceId, 'a' => $amount, 'by' => $by]);
    } else {
        $pdo->prepare('UPDATE credit_applications SET amount = :a, applied_by = :by, applied_at = now() WHERE credit_note_id = :c AND invoice_id = :i')->execute(['c' => $creditId, 'i' => $invoiceId, 'a' => m_add((string) $had, $amount), 'by' => $by]);
    }
    $n = $pdo->prepare('SELECT number FROM invoices WHERE id = :i');
    $n->execute(['i' => $invoiceId]);
    return ['invoice_id' => $invoiceId, 'invoice_number' => (string) $n->fetchColumn(), 'amount' => $amount];
}

function unapply_credit(PDO $pdo, int $creditId, int $invoiceId): array
{
    $st = $pdo->prepare('DELETE FROM credit_applications WHERE credit_note_id = :c AND invoice_id = :i RETURNING amount');
    $st->execute(['c' => $creditId, 'i' => $invoiceId]);
    $amt = $st->fetchColumn();
    if ($amt === false) {
        throw new DomainException('That credit note is not applied to that invoice.');
    }
    $n = $pdo->prepare('SELECT number FROM invoices WHERE id = :i');
    $n->execute(['i' => $invoiceId]);
    return ['invoice_number' => (string) $n->fetchColumn(), 'amount' => $amt];
}

/** Void an issued, unapplied credit note: the guard refuses an applied one ("unapply it first"); its entry is reversed. */
function void_credit_note(PDO $pdo, int $id, int $by, string $reason): array
{
    if (trim($reason) === '') {
        throw new DomainException('Voiding a credit note needs a reason');
    }
    $c = credit_note_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($c['status'] !== 'issued') {
        throw new DomainException('Credit note ' . ($c['number'] ?? '#' . $id) . ' is ' . $c['status'] . ' and is not voided');
    }
    $pdo->prepare("UPDATE credit_notes SET status = 'void', voided_at = now(), voided_by = :by, void_reason = :r WHERE id = :id")->execute(['by' => $by, 'r' => $reason, 'id' => $id]);
    $je = $pdo->prepare('SELECT journal_entry_id FROM credit_notes WHERE id = :id');
    $je->execute(['id' => $id]);
    $entry = $je->fetchColumn();
    if ($entry !== false && $entry !== null) {
        $pdo->prepare('SELECT gl_entry_reverse(:e, :by, :r)')->execute(['e' => $entry, 'by' => $by, 'r' => 'Credit note ' . $c['number'] . ' voided: ' . $reason]);
    }
    return $c + ['journal_entry_id' => $entry === false ? null : (int) $entry];
}

function delete_credit_note(PDO $pdo, int $id): array
{
    $c = credit_note_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($c['status'] !== 'draft') {
        throw new DomainException('Credit note ' . $c['number'] . ' is ' . $c['status'] . '; only a draft is deleted.');
    }
    $pdo->prepare('DELETE FROM credit_note_lines WHERE credit_note_id = :i')->execute(['i' => $id]);
    $pdo->prepare('DELETE FROM credit_notes WHERE id = :i')->execute(['i' => $id]);
    return $c;
}

/** The lines of an invoice, as rows for a credit note against it (the Credit button). */
function credit_prefill_from_invoice(PDO $pdo, int $invoiceId): array
{
    $rows = [];
    foreach (invoice_lines($pdo, $invoiceId) as $l) {
        $rows[] = ['item' => $l['item_id'], 'description' => $l['description'], 'quantity' => rtrim(rtrim((string) $l['quantity'], '0'), '.'), 'unit_price' => $l['unit_price'], 'tax_rate' => $l['tax_rate_id'], 'income_account' => $l['income_account_id'], 'department' => $l['department_id'], 'site' => $l['site_id']];
    }
    return $rows;
}
