<?php
declare(strict_types=1);

/**
 * Invoices (slice 4): the list and the page through mcp_invoices / mcp_invoice_lines / the application and claim views (the database decides what the caller sees);
 * the defaults a new invoice takes from its customer. Money is a numeric string.
 */

const INVOICE_STATUSES = ['draft' => 'Draft', 'issued' => 'Issued', 'sent' => 'Sent', 'viewed' => 'Viewed', 'partially_paid' => 'Partially paid', 'paid' => 'Paid', 'void' => 'Void', 'written_off' => 'Written off'];
const INVOICE_STATUS_BADGE = ['draft' => 'secondary', 'issued' => 'info', 'sent' => 'info', 'viewed' => 'info', 'partially_paid' => 'warning', 'paid' => 'success', 'void' => 'dark', 'written_off' => 'danger'];
const OVERDUE_BUCKETS = ['current' => 'Not yet due', '1_30' => '1–30 days', '31_60' => '31–60 days', '61_90' => '61–90 days', 'over_90' => 'Over 90 days', 'any' => 'Any overdue amount'];
const INVOICE_PAGE = 50;

function invoice_cast(array $i): array
{
    foreach (['invoice_id', 'customer_id', 'department_id', 'site_id', 'journal_entry_id', 'write_off_entry_id', 'days_overdue', 'reminder_count', 'open_claims', 'attachment_count', 'created_by'] as $k) {
        $i[$k] = ($i[$k] ?? null) === null ? null : (int) $i[$k];
    }
    $i['currency'] = trim((string) $i['currency']);
    $i['overdue'] = (bool) $i['overdue'];
    $i['has_live_link'] = (bool) $i['has_live_link'];
    return $i;
}

/** The WHERE of the list's filters: status, overdue (a bucket relative to as_of, or 'any'), customer, from/to (issue date), q (number, customer, reference). */
function invoice_where(array $filters): array
{
    $where = [];
    $args = [];
    if (($filters['status'] ?? '') !== '' && isset(INVOICE_STATUSES[$filters['status']])) {
        $where[] = 'i.status = :status';
        $args['status'] = $filters['status'];
    }
    if (($filters['customer'] ?? null) !== null && $filters['customer'] !== '') {
        $where[] = 'i.customer_id = :customer';
        $args['customer'] = (int) $filters['customer'];
    }
    if (($filters['from'] ?? '') !== '') {
        $where[] = 'i.issue_date >= :from';
        $args['from'] = $filters['from'];
    }
    if (($filters['to'] ?? '') !== '') {
        $where[] = 'i.issue_date <= :to';
        $args['to'] = $filters['to'];
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(i.number ILIKE :q OR i.customer_name ILIKE :q OR i.reference ILIKE :q)';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    $b = (string) ($filters['overdue'] ?? '');
    if ($b !== '' && isset(OVERDUE_BUCKETS[$b])) {
        $as = ($filters['as_of'] ?? '') !== '' ? (string) $filters['as_of'] : date('Y-m-d');
        $args['as_of'] = $as;
        $where[] = "i.status IN ('issued', 'sent', 'viewed', 'partially_paid') AND i.balance_due > 0";
        $days = '(CAST(:as_of AS date) - i.due_date)';
        $where[] = match ($b) { 'current' => "$days <= 0", '1_30' => "$days BETWEEN 1 AND 30", '31_60' => "$days BETWEEN 31 AND 60", '61_90' => "$days BETWEEN 61 AND 90", 'over_90' => "$days > 90", default => "$days > 0" };
    } elseif (!empty($filters['overdue'])) {
        $where[] = 'i.overdue';
    }
    return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $args];
}

function find_invoices(PDO $pdo, array $filters, int $limit = INVOICE_PAGE, int $offset = 0): array
{
    [$w, $args] = invoice_where($filters);
    $st = $pdo->prepare('SELECT i.* FROM mcp_invoices i' . $w . " ORDER BY (i.status = 'draft') DESC, i.issue_date DESC, i.invoice_id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
    $st->execute($args);
    return array_map('invoice_cast', $st->fetchAll());
}

function count_invoices(PDO $pdo, array $filters): int
{
    [$w, $args] = invoice_where($filters);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_invoices i' . $w);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

/** Per currency over the listed filter (not voided or draft): invoices, total, balance due. */
function invoice_totals(PDO $pdo, array $filters): array
{
    [$w, $args] = invoice_where($filters);
    $st = $pdo->prepare('SELECT i.currency, count(*) AS invoices, sum(i.total) AS total, sum(i.balance_due) AS balance FROM mcp_invoices i' . ($w === '' ? ' WHERE ' : $w . ' AND ') . "i.status NOT IN ('draft', 'void') GROUP BY i.currency ORDER BY i.currency");
    $st->execute($args);
    return array_map(static fn (array $r): array => ['currency' => trim((string) $r['currency']), 'invoices' => (int) $r['invoices'], 'total' => $r['total'], 'balance' => $r['balance']], $st->fetchAll());
}

function invoice_lines(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT l.*, it.name AS item_name FROM mcp_invoice_lines l LEFT JOIN mcp_items it ON it.item_id = l.item_id WHERE l.invoice_id = :id ORDER BY l.sort_order, l.invoice_line_id');
    $st->execute(['id' => $id]);
    return array_map(static function (array $l): array {
        foreach (['invoice_line_id', 'invoice_id', 'sort_order', 'item_id', 'tax_rate_id', 'income_account_id', 'department_id', 'site_id'] as $k) {
            $l[$k] = $l[$k] === null ? null : (int) $l[$k];
        }
        return $l;
    }, $st->fetchAll());
}

/** One invoice with its lines, the receipts and credits applied, its claims, the live link's state, the held link (never the token) and the entry's number. */
function find_invoice(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_invoices WHERE invoice_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $i = invoice_cast($r);
    $i['lines'] = invoice_lines($pdo, $id);
    $a = $pdo->prepare('SELECT ra.receipt_id, ra.receipt_number, ra.amount, ra.applied_at, r.received_on, r.method, r.deposit_id FROM mcp_receipt_applications ra JOIN mcp_receipts r ON r.receipt_id = ra.receipt_id WHERE ra.invoice_id = :id ORDER BY ra.applied_at, ra.receipt_id');
    $a->execute(['id' => $id]);
    $i['receipts'] = array_map(static function (array $x): array { $x['receipt_id'] = (int) $x['receipt_id']; $x['deposit_id'] = $x['deposit_id'] === null ? null : (int) $x['deposit_id']; return $x; }, $a->fetchAll());
    $c = $pdo->prepare('SELECT credit_note_id, credit_note_number, amount, applied_at FROM mcp_credit_applications WHERE invoice_id = :id ORDER BY applied_at');
    $c->execute(['id' => $id]);
    $i['credits'] = array_map(static function (array $x): array { $x['credit_note_id'] = (int) $x['credit_note_id']; return $x; }, $c->fetchAll());
    $k = $pdo->prepare('SELECT receipt_claim_id, claimed_on, method, reference, amount, note, status, receipt_id, created_at FROM mcp_receipt_claims WHERE invoice_id = :id ORDER BY receipt_claim_id DESC');
    $k->execute(['id' => $id]);
    $i['claims'] = array_map(static function (array $x): array { $x['receipt_claim_id'] = (int) $x['receipt_claim_id']; return $x; }, $k->fetchAll());
    $l = $pdo->prepare('SELECT view_count, last_used_at, expires_at, created_at FROM invoice_links_secure WHERE invoice_id = :id AND rotated_at IS NULL ORDER BY id DESC LIMIT 1');
    $l->execute(['id' => $id]);
    $i['link'] = $l->fetch() ?: null;
    $e = $pdo->prepare('SELECT number FROM mcp_journal_entries WHERE journal_entry_id = :e');
    $e->execute(['e' => $i['journal_entry_id']]);
    $i['journal_entry_number'] = $i['journal_entry_id'] === null ? null : ($e->fetchColumn() ?: null);
    return $i;
}

function find_invoice_by_number(PDO $pdo, string $number): ?array
{
    $st = $pdo->prepare('SELECT invoice_id FROM mcp_invoices WHERE number = :n');
    $st->execute(['n' => $number]);
    $id = $st->fetchColumn();
    return $id === false ? null : find_invoice($pdo, (int) $id);
}

/** The base head as a log row carries it. */
function invoice_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS invoice_id, number, customer_id, issue_date, due_date, currency, status, reference, memo, terms_text, department_id, site_id, total, balance_due FROM invoices WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['invoice_id', 'customer_id', 'department_id', 'site_id'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    $r['currency'] = trim((string) $r['currency']);
    return $r;
}

function invoice_log_state(array $s): array
{
    return array_intersect_key($s, array_flip(['customer_id', 'issue_date', 'due_date', 'currency', 'reference', 'memo', 'terms_text', 'department_id', 'site_id']));
}

/** What a new invoice takes from its customer and the settings: currency, due date from the terms, the line defaults. */
function invoice_defaults(PDO $pdo, int $customerId, ?string $issueDate = null): array
{
    $c = customer_state($pdo, $customerId) ?? throw new DomainException('Not found.');
    $s = find_settings($pdo);
    $issue = $issueDate ?? date('Y-m-d');
    $terms = $c['terms_days'] ?? $s['invoice_terms_days'];
    $rate = $c['tax_rate_id'];
    if ($rate === null) {
        $d = $pdo->query('SELECT tax_rate_id FROM mcp_tax_rates WHERE is_default AND archived_at IS NULL LIMIT 1')->fetchColumn();
        $rate = $d === false ? null : (int) $d;
    }
    $income = $c['income_account_id'];
    if ($income === null) {
        $a = find_account_by_code($pdo, '4100');
        $income = $a === null ? null : $a['account_id'];
    }
    return ['customer' => $c, 'currency' => $c['currency'] ?? $s['base_currency'], 'issue_date' => $issue, 'due_date' => date('Y-m-d', strtotime($issue . ' +' . (int) $terms . ' days')), 'tax_rate_id' => $rate, 'income_account_id' => $income];
}

/** The items a line's select offers (live), with what picking one fills. */
function items_for_lines(PDO $pdo): array
{
    return array_map(static function (array $i): array {
        foreach (['item_id', 'income_account_id', 'tax_rate_id'] as $k) {
            $i[$k] = $i[$k] === null ? null : (int) $i[$k];
        }
        return $i;
    }, $pdo->query('SELECT item_id, code, name, description, unit_price, income_account_id, tax_rate_id FROM mcp_items WHERE archived_at IS NULL ORDER BY lower(name)')->fetchAll());
}
