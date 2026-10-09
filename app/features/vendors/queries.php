<?php
declare(strict_types=1);

/** Vendors and their contacts (slice 5): reads through mcp_vendors / mcp_party_contacts / mcp_bills (the database decides who sees an address or a note); the writes are in write.php. */

const VENDOR_KINDS = ['vendor' => 'Vendor', 'employee' => 'Employee', 'tax_authority' => 'Tax authority', 'ai_provider' => 'AI provider'];
const VENDOR_FORM_KINDS = ['vendor' => 'Vendor', 'employee' => 'Employee', 'tax_authority' => 'Tax authority'];     // ai_provider is made by the import only (slice 7)
const PAY_METHODS = ['ach' => 'Bank transfer (ACH)', 'cheque' => 'Cheque', 'card' => 'Card', 'cash' => 'Cash', 'other' => 'Other'];
const OPEN_BILL_STATUSES = ['approved', 'partially_paid'];

function vendor_cast(array $v): array
{
    foreach (['vendor_id', 'terms_days', 'expense_account_id', 'member_id', 'open_bills'] as $k) {
        $v[$k] = ($v[$k] ?? null) === null ? null : (int) $v[$k];
    }
    $v['currency'] = $v['currency'] === null ? null : trim((string) $v['currency']);
    return $v;
}

/** What is owed per currency, by vendor: [vendor_id => [[currency, balance]]]. */
function vendor_balances(PDO $pdo, ?int $vendorId = null): array
{
    $st = $pdo->prepare("SELECT vendor_id, currency, sum(balance_due) AS balance FROM mcp_bills WHERE status IN ('approved', 'partially_paid')" . ($vendorId === null ? '' : ' AND vendor_id = :v') . ' GROUP BY vendor_id, currency ORDER BY currency');
    $st->execute($vendorId === null ? [] : ['v' => $vendorId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['vendor_id']][] = ['currency' => trim((string) $r['currency']), 'balance' => $r['balance']];
    }
    return $out;
}

/** The vendors the caller may see. $filters: q (name), kind, include_archived. */
function find_vendors(PDO $pdo, array $filters = []): array
{
    $where = [];
    $args = [];
    if (empty($filters['include_archived'])) {
        $where[] = 'archived_at IS NULL';
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(name ILIKE :q OR legal_name ILIKE :q)';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    if (($filters['kind'] ?? '') !== '' && isset(VENDOR_KINDS[$filters['kind']])) {
        $where[] = 'kind = :kind';
        $args['kind'] = $filters['kind'];
    }
    $st = $pdo->prepare('SELECT * FROM mcp_vendors' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY lower(name)');
    $st->execute($args);
    $bal = vendor_balances($pdo);
    return array_map(static function (array $v) use ($bal): array { $v = vendor_cast($v); $v['balances'] = $bal[$v['vendor_id']] ?? []; return $v; }, $st->fetchAll());
}

function find_vendor(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_vendors WHERE vendor_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $v = vendor_cast($r);
    $v['balances'] = vendor_balances($pdo, $id)[$id] ?? [];
    $v['contacts'] = find_contacts($pdo, 'vendor', $id);
    return $v;
}

/** The base row as the form reads it and a log row carries it. */
function vendor_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS vendor_id, name, legal_name, kind, provider_key, email::text AS email, phone, remit_address, tax_id, terms_days, currency, expense_account_id, pay_method, member_id, notes, archived_at FROM vendors WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : vendor_cast($r + ['open_bills' => null]);
}

/** A vendor's log row: the contact fields and notes by presence only. */
function vendor_log_state(array $s): array
{
    $out = array_intersect_key($s, array_flip(['name', 'legal_name', 'kind', 'terms_days', 'currency', 'expense_account_id', 'pay_method', 'member_id']));
    foreach (['email', 'phone', 'remit_address', 'tax_id', 'notes'] as $k) {
        $out[$k] = ($s[$k] ?? null) === null || $s[$k] === '' ? null : '(set)';
    }
    return $out;
}

/** Documents that stop a delete or a currency change. */
function vendor_documents(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT (SELECT count(*) FROM bills WHERE vendor_id = :v) AS bills, (SELECT count(*) FROM vendor_credits WHERE vendor_id = :v) AS vendor_credits, (SELECT count(*) FROM payments WHERE vendor_id = :v) AS payments,
                                (SELECT count(*) FROM journal_lines WHERE vendor_id = :v) AS entries, (SELECT count(*) FROM attachments WHERE record_type = 'vendor' AND record_id = :v) AS attachments");
    $st->execute(['v' => $id]);
    return array_map('intval', $st->fetch());
}

/** The vendor's open bills, oldest due first (for the page and the payment form). */
function vendor_open_bills(PDO $pdo, int $vendorId): array
{
    $st = $pdo->prepare("SELECT bill_id, number, vendor_ref, bill_date, due_date, currency, status, total, balance_due, overdue, days_overdue, has_attachment FROM mcp_bills WHERE vendor_id = :v AND status IN ('approved', 'partially_paid') ORDER BY due_date, bill_id");
    $st->execute(['v' => $vendorId]);
    return array_map(static function (array $b): array { $b['bill_id'] = (int) $b['bill_id']; $b['currency'] = trim((string) $b['currency']); $b['overdue'] = (bool) $b['overdue']; $b['days_overdue'] = (int) $b['days_overdue']; return $b; }, $st->fetchAll());
}

/** The vendors a picker offers: live, [{vendor_id, name, kind, currency, terms_days, expense_account_id, pay_method}]. */
function vendors_for_select(PDO $pdo): array
{
    return array_map(static function (array $v): array { $v['vendor_id'] = (int) $v['vendor_id']; return $v; }, $pdo->query('SELECT vendor_id, name, kind, currency, terms_days, expense_account_id, pay_method FROM mcp_vendors WHERE archived_at IS NULL ORDER BY lower(name)')->fetchAll());
}

/** A page of the live vendors the record picker offers, by name (the same ILIKE as find_vendors(), without the balances a list card carries): kind, currency and terms as the detail. */
function vendors_pick_page(PDO $pdo, string $q, int $page): array
{
    $where = 'archived_at IS NULL';
    $args = [];
    if ($q !== '') {
        $where .= ' AND (name ILIKE :q OR legal_name ILIKE :q)';
        $args['q'] = picker_like($q);
    }
    $r = picker_paged_query($pdo, 'vendor_id, name, kind, currency, terms_days', 'FROM mcp_vendors WHERE ' . $where, $args, 'lower(name), vendor_id', $page);
    $r['rows'] = array_map(static fn (array $v): array => [
        'id' => (int) $v['vendor_id'], 'label' => $v['name'],
        'detail' => implode(' · ', array_filter([VENDOR_KINDS[$v['kind']] ?? null, trim((string) $v['currency']), $v['terms_days'] === null ? null : 'Net ' . (int) $v['terms_days']])),
        'badge' => $v['kind'] === 'ai_provider' ? 'AI provider' : null, 'color' => 'info',
    ], $r['rows']);
    return $r;
}

/** One vendor's name for the picker field (archived or not). */
function vendor_pick_label(PDO $pdo, int $id): ?string
{
    $st = $pdo->prepare('SELECT name FROM mcp_vendors WHERE vendor_id = :id');
    $st->execute(['id' => $id]);
    $n = $st->fetchColumn();
    return $n === false ? null : (string) $n;
}
