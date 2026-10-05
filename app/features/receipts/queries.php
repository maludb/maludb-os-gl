<?php
declare(strict_types=1);

/** Receipts and claims (slice 4): reads through mcp_receipts / mcp_receipt_applications / mcp_receipt_claims; the receipt form's open invoices; the undeposited receipts a deposit takes. */

const RECEIPT_METHODS = ['transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'cash' => 'Cash', 'card' => 'Card', 'other' => 'Other'];
const RECEIPT_PAGE = 50;

function receipt_cast(array $r): array
{
    foreach (['receipt_id', 'customer_id', 'deposit_to_bank_account_id', 'deposit_id', 'journal_entry_id', 'recorded_by'] as $k) {
        $r[$k] = ($r[$k] ?? null) === null ? null : (int) $r[$k];
    }
    $r['currency'] = trim((string) $r['currency']);
    return $r;
}

function receipt_where(array $f): array
{
    $where = [];
    $args = [];
    if (($f['customer'] ?? null) !== null && $f['customer'] !== '') {
        $where[] = 'r.customer_id = :c';
        $args['c'] = (int) $f['customer'];
    }
    if (($f['from'] ?? '') !== '') {
        $where[] = 'r.received_on >= :from';
        $args['from'] = $f['from'];
    }
    if (($f['to'] ?? '') !== '') {
        $where[] = 'r.received_on <= :to';
        $args['to'] = $f['to'];
    }
    if (!empty($f['unapplied'])) {
        $where[] = "r.status = 'recorded' AND r.unapplied > 0";
    }
    if (!empty($f['undeposited'])) {
        $where[] = "r.status = 'recorded' AND r.deposit_id IS NULL AND r.deposit_to_bank_account_id IS NULL";
    }
    if (($f['status'] ?? '') !== '') {
        $where[] = 'r.status = :st';
        $args['st'] = $f['status'];
    }
    return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $args];
}

function find_receipts(PDO $pdo, array $filters, int $limit = RECEIPT_PAGE, int $offset = 0): array
{
    [$w, $args] = receipt_where($filters);
    $st = $pdo->prepare('SELECT r.* FROM mcp_receipts r' . $w . ' ORDER BY r.received_on DESC, r.receipt_id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset);
    $st->execute($args);
    return array_map('receipt_cast', $st->fetchAll());
}

function count_receipts(PDO $pdo, array $filters): int
{
    [$w, $args] = receipt_where($filters);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_receipts r' . $w);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

function find_receipt(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_receipts WHERE receipt_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $x = receipt_cast($r);
    $a = $pdo->prepare('SELECT ra.invoice_id, ra.invoice_number, ra.amount, ra.applied_at, i.due_date, i.balance_due FROM mcp_receipt_applications ra JOIN mcp_invoices i ON i.invoice_id = ra.invoice_id WHERE ra.receipt_id = :id ORDER BY ra.applied_at');
    $a->execute(['id' => $id]);
    $x['applications'] = array_map(static function (array $y): array { $y['invoice_id'] = (int) $y['invoice_id']; return $y; }, $a->fetchAll());
    $e = $pdo->prepare('SELECT number FROM mcp_journal_entries WHERE journal_entry_id = :e');
    $e->execute(['e' => $x['journal_entry_id']]);
    $x['journal_entry_number'] = $x['journal_entry_id'] === null ? null : ($e->fetchColumn() ?: null);
    $c = $pdo->prepare('SELECT receipt_claim_id, invoice_number, status FROM mcp_receipt_claims WHERE receipt_id = :id');
    $c->execute(['id' => $id]);
    $x['claim'] = $c->fetch() ?: null;
    return $x;
}

function receipt_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS receipt_id, number, customer_id, received_on, amount, currency, method, reference, memo, deposit_to_bank_account_id, deposit_id, status, journal_entry_id FROM receipts WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : receipt_cast($r);
}

/** Claims by status (and one customer), newest first. */
function find_claims(PDO $pdo, string $status = 'submitted', ?int $customerId = null): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_receipt_claims WHERE (:s = \'\' OR status = :s) AND (CAST(:c AS bigint) IS NULL OR customer_id = :c) ORDER BY receipt_claim_id DESC');
    $st->execute(['s' => $status, 'c' => $customerId]);
    return array_map(static function (array $k): array {
        foreach (['receipt_claim_id', 'invoice_id', 'customer_id', 'receipt_id'] as $x) {
            $k[$x] = $k[$x] === null ? null : (int) $k[$x];
        }
        return $k;
    }, $st->fetchAll());
}

function find_claim(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_receipt_claims WHERE receipt_claim_id = :id');
    $st->execute(['id' => $id]);
    $k = $st->fetch();
    if ($k === false) {
        return null;
    }
    foreach (['receipt_claim_id', 'invoice_id', 'customer_id', 'receipt_id'] as $x) {
        $k[$x] = $k[$x] === null ? null : (int) $k[$x];
    }
    return $k;
}

/** The bank accounts a receipt or deposit may name: live, [{bank_account_id, name, currency, account_code}]. */
function banks_for_select(PDO $pdo): array
{
    return array_map(static function (array $b): array { $b['bank_account_id'] = (int) $b['bank_account_id']; $b['currency'] = trim((string) $b['currency']); return $b; },
        $pdo->query('SELECT bank_account_id, name, currency, account_code, kind FROM mcp_bank_accounts WHERE archived_at IS NULL ORDER BY name')->fetchAll());
}

/** Undeposited, unvoided receipts (not in any deposit), optionally of one currency, oldest first. */
function undeposited_receipts(PDO $pdo, ?string $currency = null, ?int $includeDeposit = null): array
{
    $st = $pdo->prepare("SELECT receipt_id, number, customer_name, received_on, amount, currency, method, reference, deposit_id FROM mcp_receipts WHERE status = 'recorded' AND deposit_to_bank_account_id IS NULL AND (deposit_id IS NULL OR deposit_id = :d)
                         AND (CAST(:cur AS text) IS NULL OR currency = :cur) ORDER BY received_on, receipt_id");
    $st->execute(['d' => $includeDeposit, 'cur' => $currency]);
    return array_map(static function (array $r): array { $r['receipt_id'] = (int) $r['receipt_id']; $r['currency'] = trim((string) $r['currency']); $r['deposit_id'] = $r['deposit_id'] === null ? null : (int) $r['deposit_id']; return $r; }, $st->fetchAll());
}
