<?php
declare(strict_types=1);

/** Bills (slice 5): the list and the page through mcp_bills / mcp_bill_lines / the payment and credit application views; the defaults a new bill takes from its vendor. Money is a numeric string. */

const BILL_STATUSES = ['draft' => 'Awaiting approval', 'approved' => 'Approved', 'partially_paid' => 'Partly paid', 'paid' => 'Paid', 'void' => 'Void'];
const BILL_STATUS_BADGE = ['draft' => 'secondary', 'approved' => 'info', 'partially_paid' => 'warning', 'paid' => 'success', 'void' => 'dark'];
const BILL_SOURCES = ['manual' => 'Typed in', 'ai_usage' => 'AI statement', 'inbox' => 'Inbox', 'recurring' => 'Recurring', 'import' => 'Import'];
const BILL_PAGE = 50;

function bill_cast(array $b): array
{
    foreach (['bill_id', 'vendor_id', 'department_id', 'site_id', 'journal_entry_id', 'days_overdue', 'attachment_count', 'created_by', 'approved_by'] as $k) {
        $b[$k] = ($b[$k] ?? null) === null ? null : (int) $b[$k];
    }
    $b['currency'] = trim((string) $b['currency']);
    $b['overdue'] = (bool) $b['overdue'];
    $b['has_attachment'] = (bool) $b['has_attachment'];
    return $b;
}

/** The WHERE of the list's filters: status (a status, or `unpaid` = approved + partly paid), vendor, overdue (a bucket relative to as_of, or 1), due_within (days), no_document, source, from/to (bill date), q. */
function bill_where(array $filters): array
{
    $where = [];
    $args = [];
    $s = (string) ($filters['status'] ?? '');
    if ($s === 'unpaid') {
        $where[] = "b.status IN ('approved', 'partially_paid')";
    } elseif ($s !== '' && isset(BILL_STATUSES[$s])) {
        $where[] = 'b.status = :status';
        $args['status'] = $s;
    }
    if (($filters['vendor'] ?? null) !== null && $filters['vendor'] !== '') {
        $where[] = 'b.vendor_id = :vendor';
        $args['vendor'] = (int) $filters['vendor'];
    }
    foreach (['from' => 'b.bill_date >= :from', 'to' => 'b.bill_date <= :to'] as $k => $sql) {
        if (($filters[$k] ?? '') !== '') {
            $where[] = $sql;
            $args[$k] = $filters[$k];
        }
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(b.number ILIKE :q OR b.vendor_name ILIKE :q OR b.vendor_ref ILIKE :q)';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    if (($filters['source'] ?? '') !== '' && isset(BILL_SOURCES[$filters['source']])) {
        $where[] = 'b.source = :source';
        $args['source'] = $filters['source'];
    }
    if (!empty($filters['no_document'])) {
        $where[] = "NOT b.has_attachment AND b.status <> 'void'";
    }
    if (($filters['due_within'] ?? null) !== null && $filters['due_within'] !== '') {
        $where[] = "b.status IN ('approved', 'partially_paid') AND b.balance_due > 0 AND b.due_date <= current_date + CAST(:within AS integer)";
        $args['within'] = (int) $filters['due_within'];
    }
    $b = (string) ($filters['overdue'] ?? '');
    if ($b !== '' && isset(OVERDUE_BUCKETS[$b])) {
        $as = ($filters['as_of'] ?? '') !== '' ? (string) $filters['as_of'] : date('Y-m-d');
        $args['as_of'] = $as;
        $where[] = "b.status IN ('approved', 'partially_paid') AND b.balance_due > 0";
        $days = '(CAST(:as_of AS date) - b.due_date)';
        $where[] = match ($b) { 'current' => "$days <= 0", '1_30' => "$days BETWEEN 1 AND 30", '31_60' => "$days BETWEEN 31 AND 60", '61_90' => "$days BETWEEN 61 AND 90", 'over_90' => "$days > 90", default => "$days > 0" };
    } elseif (!empty($filters['overdue'])) {
        $where[] = 'b.overdue';
    }
    return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $args];
}

function find_bills(PDO $pdo, array $filters, int $limit = BILL_PAGE, int $offset = 0): array
{
    [$w, $args] = bill_where($filters);
    $st = $pdo->prepare('SELECT b.* FROM mcp_bills b' . $w . " ORDER BY (b.status = 'draft') DESC, b.due_date, b.bill_id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
    $st->execute($args);
    return array_map('bill_cast', $st->fetchAll());
}

function count_bills(PDO $pdo, array $filters): int
{
    [$w, $args] = bill_where($filters);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_bills b' . $w);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

/** Per currency over the listed filter (not void or draft): bills, total, paid, balance due. */
function bill_totals(PDO $pdo, array $filters): array
{
    [$w, $args] = bill_where($filters);
    $st = $pdo->prepare('SELECT b.currency, count(*) AS bills, sum(b.total) AS total, sum(b.amount_paid) AS paid, sum(b.balance_due) AS balance FROM mcp_bills b' . ($w === '' ? ' WHERE ' : $w . ' AND ') . "b.status NOT IN ('draft', 'void') GROUP BY b.currency ORDER BY b.currency");
    $st->execute($args);
    return array_map(static fn (array $r): array => ['currency' => trim((string) $r['currency']), 'bills' => (int) $r['bills'], 'total' => $r['total'], 'paid' => $r['paid'], 'balance' => $r['balance']], $st->fetchAll());
}

/** How many bills each tab holds (awaiting approval, approved = open, due this week). */
function bill_tab_counts(PDO $pdo): array
{
    $r = $pdo->query("SELECT count(*) FILTER (WHERE status = 'draft') AS awaiting, count(*) FILTER (WHERE status IN ('approved', 'partially_paid')) AS approved,
                             count(*) FILTER (WHERE status IN ('approved', 'partially_paid') AND balance_due > 0 AND due_date <= current_date + 7) AS due_week FROM mcp_bills")->fetch();
    return array_map('intval', $r);
}

function bill_lines(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT l.*, it.name AS item_name FROM mcp_bill_lines l LEFT JOIN mcp_items it ON it.item_id = l.item_id WHERE l.bill_id = :id ORDER BY l.sort_order, l.bill_line_id');
    $st->execute(['id' => $id]);
    return array_map(static function (array $l): array {
        foreach (['bill_line_id', 'bill_id', 'sort_order', 'item_id', 'tax_rate_id', 'expense_account_id', 'department_id', 'site_id'] as $k) {
            $l[$k] = $l[$k] === null ? null : (int) $l[$k];
        }
        return $l;
    }, $st->fetchAll());
}

/** One bill with its lines, the payments and credits applied, its entry's number. */
function find_bill(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_bills WHERE bill_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $b = bill_cast($r);
    $b['lines'] = bill_lines($pdo, $id);
    $a = $pdo->prepare('SELECT pa.payment_id, pa.payment_number, pa.amount, pa.applied_at, p.payment_date, p.method, p.status AS payment_status, p.cheque_number FROM mcp_payment_applications pa JOIN mcp_payments p ON p.payment_id = pa.payment_id WHERE pa.bill_id = :id ORDER BY pa.applied_at, pa.payment_id');
    $a->execute(['id' => $id]);
    $b['payments'] = array_map(static function (array $x): array { $x['payment_id'] = (int) $x['payment_id']; return $x; }, $a->fetchAll());
    $c = $pdo->prepare('SELECT vendor_credit_id, vendor_credit_number, amount, applied_at FROM mcp_vendor_credit_applications WHERE bill_id = :id ORDER BY applied_at');
    $c->execute(['id' => $id]);
    $b['credits'] = array_map(static function (array $x): array { $x['vendor_credit_id'] = (int) $x['vendor_credit_id']; return $x; }, $c->fetchAll());
    $e = $pdo->prepare('SELECT number FROM mcp_journal_entries WHERE journal_entry_id = :e');
    $e->execute(['e' => $b['journal_entry_id']]);
    $b['journal_entry_number'] = $b['journal_entry_id'] === null ? null : ($e->fetchColumn() ?: null);
    return $b;
}

function find_bill_by_number(PDO $pdo, string $number): ?array
{
    $st = $pdo->prepare('SELECT bill_id FROM mcp_bills WHERE number = :n');
    $st->execute(['n' => $number]);
    $id = $st->fetchColumn();
    return $id === false ? null : find_bill($pdo, (int) $id);
}

/** The base head as a log row carries it. */
function bill_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS bill_id, number, vendor_id, vendor_ref, bill_date, due_date, currency, status, memo, source, department_id, site_id, total, balance_due FROM bills WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['bill_id', 'vendor_id', 'department_id', 'site_id'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    $r['currency'] = trim((string) $r['currency']);
    return $r;
}

function bill_log_state(array $s): array
{
    return array_intersect_key($s, array_flip(['vendor_id', 'vendor_ref', 'bill_date', 'due_date', 'currency', 'memo', 'department_id', 'site_id']));
}

/** What a new bill takes from its vendor and the settings: currency, due date from the terms, the line's expense account (the vendor's, else 6900). */
function bill_defaults(PDO $pdo, int $vendorId, ?string $billDate = null): array
{
    $v = vendor_state($pdo, $vendorId) ?? throw new DomainException('Not found.');
    $s = find_settings($pdo);
    $date = $billDate ?? date('Y-m-d');
    $terms = $v['terms_days'] ?? $s['bill_terms_days'];
    $acc = $v['expense_account_id'];
    if ($acc === null) {
        $a = find_account_by_code($pdo, '6900');
        $acc = $a === null ? null : $a['account_id'];
    }
    return ['vendor' => $v, 'currency' => $v['currency'] ?? $s['base_currency'], 'bill_date' => $date, 'due_date' => date('Y-m-d', strtotime($date . ' +' . (int) $terms . ' days')), 'tax_rate_id' => null, 'expense_account_id' => $acc];
}

/** The same vendor reference already entered (live): the bill's id and number, else null. */
function find_duplicate_vendor_ref(PDO $pdo, int $vendorId, ?string $ref, ?int $exceptBillId): ?array
{
    if ($ref === null || $ref === '') {
        return null;
    }
    $st = $pdo->prepare("SELECT id, number FROM bills WHERE vendor_id = :v AND lower(vendor_ref) = lower(:r) AND status <> 'void' AND id IS DISTINCT FROM :x LIMIT 1");
    $st->execute(['v' => $vendorId, 'r' => $ref, 'x' => $exceptBillId]);
    $r = $st->fetch();
    return $r === false ? null : ['id' => (int) $r['id'], 'number' => $r['number']];
}
