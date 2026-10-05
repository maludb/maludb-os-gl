<?php
declare(strict_types=1);

/** The customers' writes (slice 4). A DomainException is our own sentence; the database's RAISE and constraint answers are shown by gl_guard(). */

/** Add ($id null) or change a customer. $f: the base columns. A live name is unique (case-blind). Answers the id. */
function save_customer(PDO $pdo, ?int $id, array $f, int $by): int
{
    $dupe = $pdo->prepare('SELECT 1 FROM customers WHERE lower(name) = lower(:n) AND archived_at IS NULL AND id IS DISTINCT FROM :id');
    $dupe->execute(['n' => $f['name'], 'id' => $id]);
    if ($dupe->fetchColumn() !== false) {
        throw new DomainException('A customer named ' . $f['name'] . ' exists.');
    }
    if ($id !== null) {
        $cur = customer_state($pdo, $id) ?? throw new DomainException('Not found.');
        if (($cur['currency'] ?? null) !== ($f['currency'] ?? null) && array_sum(customer_documents($pdo, $id)) > 0) {
            throw new DomainException('The currency is fixed once ' . $cur['name'] . ' has a document.');
        }
    }
    $args = ['n' => $f['name'], 'ln' => $f['legal_name'], 'e' => $f['email'], 'p' => $f['phone'], 'ba' => $f['billing_address'], 'sa' => $f['shipping_address'], 'tid' => $f['tax_id'], 'td' => $f['terms_days'],
             'c' => $f['currency'], 'ia' => $f['income_account_id'], 'tr' => $f['tax_rate_id'], 'm' => $f['member_id'], 'no' => $f['notes']];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO customers (name, legal_name, email, phone, billing_address, shipping_address, tax_id, terms_days, currency, income_account_id, tax_rate_id, member_id, notes, created_by)
                             VALUES (:n, :ln, :e, :p, :ba, :sa, :tid, :td, :c, :ia, :tr, :m, :no, :by) RETURNING id');
        $st->execute($args + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE customers SET name = :n, legal_name = :ln, email = :e, phone = :p, billing_address = :ba, shipping_address = :sa, tax_id = :tid, terms_days = :td, currency = :c,
                          income_account_id = :ia, tax_rate_id = :tr, member_id = :m, notes = :no WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

function archive_customer(PDO $pdo, int $id, bool $archived): void
{
    $c = customer_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($archived) {
        if ($c['archived_at'] !== null) {
            throw new DomainException($c['name'] . ' is already archived.');
        }
        $st = $pdo->prepare("SELECT count(*) FROM invoices WHERE customer_id = :c AND status IN ('issued', 'sent', 'viewed', 'partially_paid')");
        $st->execute(['c' => $id]);
        $n = (int) $st->fetchColumn();
        if ($n > 0) {
            throw new DomainException('Settle or void its ' . $n . ' open invoice' . ($n === 1 ? '' : 's') . ' first.');
        }
    } elseif ($c['archived_at'] === null) {
        throw new DomainException($c['name'] . ' is not archived.');
    }
    $pdo->prepare('UPDATE customers SET archived_at = ' . ($archived ? 'now()' : 'NULL') . ' WHERE id = :id')->execute(['id' => $id]);
}

/** Delete a customer who has no document at all; the sentence says which document stops it. */
function delete_customer(PDO $pdo, int $id): array
{
    $c = customer_state($pdo, $id) ?? throw new DomainException('Not found.');
    $d = customer_documents($pdo, $id);
    foreach (['invoices' => 'invoices', 'credit_notes' => 'credit notes', 'receipts' => 'receipts', 'entries' => 'posted lines'] as $k => $words) {
        if ($d[$k] > 0) {
            throw new DomainException($c['name'] . ' has ' . $words . '; archive it instead.');
        }
    }
    $pdo->prepare("DELETE FROM party_contacts WHERE party_kind = 'customer' AND party_id = :id")->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $id]);
    return $c;
}

/** Add ($contactId null) or change a contact of a party; one primary per party (making one primary un-primaries the other). Answers the id. */
function save_contact(PDO $pdo, string $kind, int $partyId, ?int $contactId, array $f): int
{
    if ($f['is_primary']) {
        $pdo->prepare('UPDATE party_contacts SET is_primary = false WHERE party_kind = :k AND party_id = :p AND id IS DISTINCT FROM :c')->execute(['k' => $kind, 'p' => $partyId, 'c' => $contactId]);
    }
    if ($contactId === null) {
        $st = $pdo->prepare('INSERT INTO party_contacts (party_kind, party_id, name, email, phone, role, is_primary) VALUES (:k, :p, :n, :e, :ph, :r, :pr) RETURNING id');
        $st->execute(['k' => $kind, 'p' => $partyId, 'n' => $f['name'], 'e' => $f['email'], 'ph' => $f['phone'], 'r' => $f['role'], 'pr' => $f['is_primary'] ? 't' : 'f']);
        return (int) $st->fetchColumn();
    }
    $st = $pdo->prepare('UPDATE party_contacts SET name = :n, email = :e, phone = :ph, role = :r, is_primary = :pr WHERE id = :c AND party_kind = :k AND party_id = :p');
    $st->execute(['n' => $f['name'], 'e' => $f['email'], 'ph' => $f['phone'], 'r' => $f['role'], 'pr' => $f['is_primary'] ? 't' : 'f', 'c' => $contactId, 'k' => $kind, 'p' => $partyId]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Not found.');
    }
    return $contactId;
}

function remove_contact(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('DELETE FROM party_contacts WHERE id = :id RETURNING party_kind, party_id, name');
    $st->execute(['id' => $id]);
    return $st->fetch() ?: null;
}
