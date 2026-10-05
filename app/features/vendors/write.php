<?php
declare(strict_types=1);

/** The vendors' writes (slice 5). A DomainException is our own sentence; the database's RAISE and constraint answers are shown by gl_guard() / ar_refused(). */

/** Add ($id null) or change a vendor. A live name is unique (case-blind); an AI provider keeps its kind and its provider key; the currency is fixed once a document exists. Answers the id. */
function save_vendor(PDO $pdo, ?int $id, array $f, int $by): int
{
    $dupe = $pdo->prepare('SELECT 1 FROM vendors WHERE lower(name) = lower(:n) AND archived_at IS NULL AND id IS DISTINCT FROM :id');
    $dupe->execute(['n' => $f['name'], 'id' => $id]);
    if ($dupe->fetchColumn() !== false) {
        throw new DomainException('A vendor named ' . $f['name'] . ' exists.');
    }
    $cur = null;
    if ($id !== null) {
        $cur = vendor_state($pdo, $id) ?? throw new DomainException('Not found.');
        if ($cur['kind'] === 'ai_provider' && $f['kind'] !== 'ai_provider') {
            throw new DomainException($cur['name'] . ' is an AI provider made by the AI statement; its kind does not change.');
        }
        if (($cur['currency'] ?? null) !== ($f['currency'] ?? null) && array_sum(vendor_documents($pdo, $id)) - (int) vendor_documents($pdo, $id)['attachments'] > 0) {
            throw new DomainException('The currency is fixed once ' . $cur['name'] . ' has a document.');
        }
    }
    if ($f['kind'] === 'ai_provider' && $cur === null) {
        throw new DomainException('An AI provider is made by the AI statement, not here.');
    }
    $args = ['n' => $f['name'], 'ln' => $f['legal_name'], 'k' => $f['kind'], 'e' => $f['email'], 'p' => $f['phone'], 'ra' => $f['remit_address'], 'tid' => $f['tax_id'], 'td' => $f['terms_days'], 'c' => $f['currency'],
             'ea' => $f['expense_account_id'], 'pm' => $f['pay_method'], 'm' => $f['member_id'], 'no' => $f['notes']];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO vendors (name, legal_name, kind, email, phone, remit_address, tax_id, terms_days, currency, expense_account_id, pay_method, member_id, notes, created_by)
                             VALUES (:n, :ln, :k, :e, :p, :ra, :tid, :td, :c, :ea, :pm, :m, :no, :by) RETURNING id');
        $st->execute($args + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE vendors SET name = :n, legal_name = :ln, kind = :k, email = :e, phone = :p, remit_address = :ra, tax_id = :tid, terms_days = :td, currency = :c, expense_account_id = :ea, pay_method = :pm, member_id = :m, notes = :no WHERE id = :id')
        ->execute($args + ['id' => $id]);
    return $id;
}

function archive_vendor(PDO $pdo, int $id, bool $archived): void
{
    $v = vendor_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($archived) {
        if ($v['archived_at'] !== null) {
            throw new DomainException($v['name'] . ' is already archived.');
        }
        $st = $pdo->prepare("SELECT count(*) FROM bills WHERE vendor_id = :v AND status IN ('approved', 'partially_paid')");
        $st->execute(['v' => $id]);
        $n = (int) $st->fetchColumn();
        if ($n > 0) {
            throw new DomainException('Pay or void its ' . $n . ' open bill' . ($n === 1 ? '' : 's') . ' first.');
        }
    } elseif ($v['archived_at'] === null) {
        throw new DomainException($v['name'] . ' is not archived.');
    }
    $pdo->prepare('UPDATE vendors SET archived_at = ' . ($archived ? 'now()' : 'NULL') . ' WHERE id = :id')->execute(['id' => $id]);
}

/** Delete a vendor who has no document at all; the sentence says which one stops it. */
function delete_vendor(PDO $pdo, int $id): array
{
    $v = vendor_state($pdo, $id) ?? throw new DomainException('Not found.');
    $d = vendor_documents($pdo, $id);
    foreach (['bills' => 'bills', 'vendor_credits' => 'vendor credits', 'payments' => 'payments', 'entries' => 'posted lines', 'attachments' => 'attached documents'] as $k => $words) {
        if ($d[$k] > 0) {
            throw new DomainException($v['name'] . ' has ' . $words . '; archive it instead.');
        }
    }
    $pdo->prepare("DELETE FROM party_contacts WHERE party_kind = 'vendor' AND party_id = :id")->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM vendors WHERE id = :id')->execute(['id' => $id]);
    return $v;
}
