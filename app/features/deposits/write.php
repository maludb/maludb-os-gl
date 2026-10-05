<?php
declare(strict_types=1);

/** Deposits' writes (slice 4): a draft groups undeposited receipts of the bank's currency; record posts DR bank / CR undeposited funds (gl_deposit_record); void reverses (gl_deposit_void). The guards refuse a bank receipt and a recorded deposit's change. */

function draft_deposit(PDO $pdo, array $f, array $receiptIds, int $by): int
{
    $b = $pdo->prepare('SELECT currency, name FROM bank_accounts WHERE id = :b AND archived_at IS NULL');
    $b->execute(['b' => $f['bank_account_id']]);
    $bank = $b->fetch() ?: throw new DomainException('Choose a bank account from the list.');
    $st = $pdo->prepare('INSERT INTO deposits (bank_account_id, deposit_date, currency, memo, created_by) VALUES (:b, :d, :c, :m, :by) RETURNING id');
    $st->execute(['b' => $f['bank_account_id'], 'd' => $f['deposit_date'], 'c' => trim((string) $bank['currency']), 'm' => $f['memo'], 'by' => $by]);
    $id = (int) $st->fetchColumn();
    foreach ($receiptIds as $r) {
        deposit_add($pdo, $id, (int) $r);
    }
    return $id;
}

/** Put an undeposited receipt into a draft deposit (the receipt guard refuses a bank receipt and a non-draft deposit; the currency must match). */
function deposit_add(PDO $pdo, int $depositId, int $receiptId): array
{
    $d = deposit_state($pdo, $depositId) ?? throw new DomainException('Not found.');
    $r = receipt_state($pdo, $receiptId) ?? throw new DomainException('Receipt not found.');
    if ($r['currency'] !== $d['currency']) {
        throw new DomainException('Receipt ' . $r['number'] . ' is in ' . $r['currency'] . ' and the deposit in ' . $d['currency'] . '.');
    }
    if ($r['deposit_id'] !== null && $r['deposit_id'] !== $depositId) {
        throw new DomainException('Receipt ' . $r['number'] . ' is already in another deposit.');
    }
    $pdo->prepare('UPDATE receipts SET deposit_id = :d WHERE id = :r')->execute(['d' => $depositId, 'r' => $receiptId]);
    return ['receipt_number' => $r['number'], 'amount' => $r['amount']];
}

function deposit_remove(PDO $pdo, int $depositId, int $receiptId): array
{
    $r = receipt_state($pdo, $receiptId) ?? throw new DomainException('Receipt not found.');
    if ($r['deposit_id'] !== $depositId) {
        throw new DomainException('Receipt ' . $r['number'] . ' is not in that deposit.');
    }
    $pdo->prepare('UPDATE receipts SET deposit_id = NULL WHERE id = :r')->execute(['r' => $receiptId]);
    return ['receipt_number' => $r['number'], 'amount' => $r['amount']];
}

function record_deposit(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT (d).id, (d).number, (d).total, (d).currency, (d).bank_account_id, (d).journal_entry_id FROM gl_deposit_record(:id, :by) d');
    $st->execute(['id' => $id, 'by' => $by]);
    $r = $st->fetch();
    $n = $pdo->prepare('SELECT count(*) FROM receipts WHERE deposit_id = :id');
    $n->execute(['id' => $id]);
    return ['deposit_id' => (int) $r['id'], 'number' => $r['number'], 'amount' => $r['total'], 'currency' => trim((string) $r['currency']), 'bank_account_id' => (int) $r['bank_account_id'], 'journal_entry_id' => (int) $r['journal_entry_id'], 'receipts' => (int) $n->fetchColumn()];
}

function void_deposit(PDO $pdo, int $id, int $by, string $reason): array
{
    $before = deposit_state($pdo, $id) ?? throw new DomainException('Not found.');
    $st = $pdo->prepare('SELECT (d).id, (d).number, (d).total, (d).currency, (d).bank_account_id, (d).journal_entry_id FROM gl_deposit_void(:id, :by, :r) d');
    $st->execute(['id' => $id, 'by' => $by, 'r' => $reason]);
    $r = $st->fetch();
    return ['deposit_id' => (int) $r['id'], 'number' => $r['number'], 'amount' => $r['total'], 'currency' => trim((string) $r['currency']), 'bank_account_id' => (int) $r['bank_account_id'], 'journal_entry_id' => $r['journal_entry_id'] === null ? null : (int) $r['journal_entry_id']];
}

function delete_deposit(PDO $pdo, int $id): array
{
    $d = deposit_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($d['status'] !== 'draft') {
        throw new DomainException('Deposit ' . $d['number'] . ' is ' . $d['status'] . '; only a draft is deleted.');
    }
    $pdo->prepare('UPDATE receipts SET deposit_id = NULL WHERE deposit_id = :d')->execute(['d' => $id]);
    $pdo->prepare('DELETE FROM deposits WHERE id = :d')->execute(['d' => $id]);
    return $d;
}
