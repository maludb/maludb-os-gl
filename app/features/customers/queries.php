<?php
declare(strict_types=1);

/** Customers and their contacts (slice 4): reads through mcp_customers / mcp_party_contacts / mcp_invoices (the database decides who sees an address), writes to the base tables under the handler's gate. */

const OPEN_INVOICE_STATUSES = ['issued', 'sent', 'viewed', 'partially_paid'];

function customer_cast(array $c): array
{
    foreach (['customer_id', 'terms_days', 'income_account_id', 'tax_rate_id', 'member_id', 'open_invoices'] as $k) {
        $c[$k] = ($c[$k] ?? null) === null ? null : (int) $c[$k];
    }
    $c['currency'] = $c['currency'] === null ? null : trim((string) $c['currency']);
    return $c;
}

/** What is owed per currency by customer: [customer_id => [[currency, balance]]]. */
function customer_balances(PDO $pdo, ?int $customerId = null): array
{
    $sql = "SELECT customer_id, currency, sum(balance_due) AS balance FROM mcp_invoices WHERE status IN ('issued', 'sent', 'viewed', 'partially_paid')" . ($customerId === null ? '' : ' AND customer_id = :c') . ' GROUP BY customer_id, currency ORDER BY currency';
    $st = $pdo->prepare($sql);
    $st->execute($customerId === null ? [] : ['c' => $customerId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['customer_id']][] = ['currency' => trim((string) $r['currency']), 'balance' => $r['balance']];
    }
    return $out;
}

/** The customers the caller may see. $filters: q (name), include_archived. */
function find_customers(PDO $pdo, array $filters = []): array
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
    $st = $pdo->prepare('SELECT * FROM mcp_customers' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY lower(name)');
    $st->execute($args);
    $bal = customer_balances($pdo);
    return array_map(static function (array $c) use ($bal): array { $c = customer_cast($c); $c['balances'] = $bal[$c['customer_id']] ?? []; return $c; }, $st->fetchAll());
}

function find_customer(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_customers WHERE customer_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $c = customer_cast($r);
    $c['balances'] = customer_balances($pdo, $id)[$id] ?? [];
    $c['contacts'] = find_contacts($pdo, 'customer', $id);
    return $c;
}

/** The base row as the form reads it and a log row carries it. */
function customer_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS customer_id, name, legal_name, email::text AS email, phone, billing_address, shipping_address, tax_id, terms_days, currency, income_account_id, tax_rate_id, member_id, notes, archived_at FROM customers WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : customer_cast($r);
}

/** What a customer's log row carries (never the notes' text or the address: the keys only, and the contact fields by presence). */
function customer_log_state(array $s): array
{
    $out = array_intersect_key($s, array_flip(['name', 'legal_name', 'terms_days', 'currency', 'income_account_id', 'tax_rate_id', 'member_id']));
    foreach (['email', 'phone', 'billing_address', 'shipping_address', 'tax_id', 'notes'] as $k) {
        $out[$k] = ($s[$k] ?? null) === null || $s[$k] === '' ? null : '(set)';
    }
    return $out;
}

/** The contacts of a party (the view shows customers' to ar.write and vendors' to ap.write; none otherwise). */
function find_contacts(PDO $pdo, string $kind, int $partyId): array
{
    $st = $pdo->prepare('SELECT party_contact_id, party_kind, party_id, name, email, phone, role, is_primary FROM mcp_party_contacts WHERE party_kind = :k AND party_id = :p ORDER BY is_primary DESC, lower(name)');
    $st->execute(['k' => $kind, 'p' => $partyId]);
    return array_map(static function (array $c): array { $c['party_contact_id'] = (int) $c['party_contact_id']; $c['party_id'] = (int) $c['party_id']; return $c; }, $st->fetchAll());
}

/** The customer's open invoices, oldest due first. */
function customer_open_invoices(PDO $pdo, int $customerId): array
{
    $st = $pdo->prepare("SELECT invoice_id, number, issue_date, due_date, currency, status, total, balance_due, overdue, days_overdue FROM mcp_invoices WHERE customer_id = :c AND status IN ('issued', 'sent', 'viewed', 'partially_paid') ORDER BY due_date, invoice_id");
    $st->execute(['c' => $customerId]);
    return array_map(static function (array $i): array { $i['invoice_id'] = (int) $i['invoice_id']; $i['currency'] = trim((string) $i['currency']); return $i; }, $st->fetchAll());
}

/** Does the customer have any document (invoice, credit note, receipt) or posted line? — what stops a delete and a currency change. */
function customer_documents(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT (SELECT count(*) FROM invoices WHERE customer_id = :c) AS invoices, (SELECT count(*) FROM credit_notes WHERE customer_id = :c) AS credit_notes,
                                (SELECT count(*) FROM receipts WHERE customer_id = :c) AS receipts, (SELECT count(*) FROM journal_lines WHERE customer_id = :c) AS entries');
    $st->execute(['c' => $id]);
    return array_map('intval', $st->fetch());
}

/** The customers a picker offers: live, [{customer_id, name, currency, terms_days}]. */
function customers_for_select(PDO $pdo): array
{
    return array_map(static function (array $c): array { $c['customer_id'] = (int) $c['customer_id']; return $c; }, $pdo->query('SELECT customer_id, name, currency, terms_days FROM mcp_customers WHERE archived_at IS NULL ORDER BY lower(name)')->fetchAll());
}
