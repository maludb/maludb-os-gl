<?php
declare(strict_types=1);

/**
 * Cash's writes (slice 6). The database is the referee: the match (gl_bank_match: to the cent), unmatch, ignore, the entry from a line, the reconciliation's figures and its completion, the transfer's posting, the bank
 * account guard. A DomainException is our own sentence; a RAISE (P0001) is shown in its words by ar_refused().
 */

/** Add ($id null) or change a bank account. $f: name, kind, account_id, institution, account_last4, currency, opening_balance, opening_date. The guard trigger says what is not a bank account in the chart. Answers the id. */
function save_bank_account(PDO $pdo, ?int $id, array $f, int $by): int
{
    $dupe = $pdo->prepare('SELECT 1 FROM bank_accounts WHERE lower(name) = lower(:n) AND archived_at IS NULL AND id IS DISTINCT FROM :id');
    $dupe->execute(['n' => $f['name'], 'id' => $id]);
    if ($dupe->fetchColumn() !== false) {
        throw new DomainException('A bank account named ' . $f['name'] . ' exists.');
    }
    $used = $pdo->prepare('SELECT 1 FROM bank_accounts WHERE account_id = :a AND archived_at IS NULL AND id IS DISTINCT FROM :id');
    $used->execute(['a' => $f['account_id'], 'id' => $id]);
    if ($used->fetchColumn() !== false) {
        throw new DomainException('That ledger account already stands behind another bank account.');
    }
    if ($id !== null) {
        $cur = bank_account_state($pdo, $id) ?? throw new DomainException('Not found.');
        $docs = bank_account_documents($pdo, $id);
        if ($docs['completed'] > 0 && ((string) $cur['opening_balance'] !== (string) $f['opening_balance'] || $cur['opening_date'] !== $f['opening_date'])) {
            throw new DomainException($cur['name'] . ' is reconciled through ' . ($cur['last_reconciled_through'] ?? 'a statement') . '; the opening balance is fixed (reopen its reconciliations to change it).');
        }
        if ($cur['currency'] !== $f['currency'] && ($docs['lines'] + $docs['receipts'] + $docs['deposits'] + $docs['payments'] + $docs['payment_runs'] + $docs['transfers']) > 0) {
            throw new DomainException('The currency is fixed once ' . $cur['name'] . ' has lines or documents.');
        }
        if ($cur['account_id'] !== $f['account_id'] && ($docs['payments'] + $docs['receipts'] + $docs['deposits'] + $docs['transfers']) > 0) {
            throw new DomainException('The ledger account is fixed once ' . $cur['name'] . ' has receipts, deposits, payments or transfers.');
        }
    }
    $args = ['n' => $f['name'], 'k' => $f['kind'], 'a' => $f['account_id'], 'i' => $f['institution'], 'l4' => $f['account_last4'], 'c' => $f['currency'], 'ob' => $f['opening_balance'], 'od' => $f['opening_date']];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO bank_accounts (name, kind, account_id, institution, account_last4, currency, opening_balance, opening_date, created_by) VALUES (:n, :k, :a, :i, :l4, :c, :ob, :od, :by) RETURNING id');
        $st->execute($args + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE bank_accounts SET name = :n, kind = :k, account_id = :a, institution = :i, account_last4 = :l4, currency = :c, opening_balance = :ob, opening_date = :od WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

function archive_bank_account(PDO $pdo, int $id, bool $archived): void
{
    $b = bank_account_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($archived) {
        if ($b['archived_at'] !== null) {
            throw new DomainException($b['name'] . ' is already archived.');
        }
        $n = (int) $pdo->query("SELECT count(*) FROM bank_transactions WHERE bank_account_id = {$id} AND status = 'unmatched'")->fetchColumn();
        if ($n > 0) {
            throw new DomainException($b['name'] . ' has ' . $n . ' unmatched bank line' . ($n === 1 ? '' : 's') . '; match or ignore them first.');
        }
        if (reconciliation_in_progress($pdo, $id) !== null) {
            throw new DomainException('A reconciliation of ' . $b['name'] . ' is in progress; finish or abandon it first.');
        }
    } elseif ($b['archived_at'] === null) {
        throw new DomainException($b['name'] . ' is not archived.');
    }
    $pdo->prepare('UPDATE bank_accounts SET archived_at = ' . ($archived ? 'now()' : 'NULL') . ' WHERE id = :id')->execute(['id' => $id]);
}

/** Import parsed rows: one bank_imports row, each line inserted with its external_ref; a duplicate (same account, same reference) is skipped and counted. Answers import_id, rows, inserted, skipped, hashed. */
function import_bank_lines(PDO $pdo, int $bankAccountId, array $rows, ?string $filename, int $by, int $hashed = 0): array
{
    $imp = $pdo->prepare('INSERT INTO bank_imports (bank_account_id, filename, row_count, imported_by) VALUES (:b, :f, :n, :by) RETURNING id');
    $imp->execute(['b' => $bankAccountId, 'f' => $filename === null ? null : mb_substr($filename, 0, 200), 'n' => count($rows), 'by' => $by]);
    $importId = (int) $imp->fetchColumn();
    $ins = $pdo->prepare('INSERT INTO bank_transactions (bank_account_id, import_id, txn_date, amount, description, external_ref, balance_after) VALUES (:b, :i, :d, :a, :de, :x, :bal)
                          ON CONFLICT (bank_account_id, external_ref) WHERE external_ref IS NOT NULL DO NOTHING RETURNING id');
    $inserted = 0;
    foreach ($rows as $r) {
        $ins->execute(['b' => $bankAccountId, 'i' => $importId, 'd' => $r['txn_date'], 'a' => $r['amount'], 'de' => $r['description'], 'x' => $r['external_ref'], 'bal' => $r['balance_after']]);
        if ($ins->fetchColumn() !== false) {
            $inserted++;
        }
    }
    $skipped = count($rows) - $inserted;
    $pdo->prepare('UPDATE bank_imports SET inserted_count = :i, skipped_count = :s WHERE id = :id')->execute(['i' => $inserted, 's' => $skipped, 'id' => $importId]);
    return ['import_id' => $importId, 'rows' => count($rows), 'inserted' => $inserted, 'skipped' => $skipped, 'hashed' => $hashed];
}

/** Delete an import's lines that are still unmatched (a matched or ignored line stays); the import row is dated. Answers the import and the counts. */
function delete_import(PDO $pdo, int $importId): array
{
    $st = $pdo->prepare('SELECT id, bank_account_id, filename, deleted_at FROM bank_imports WHERE id = :id');
    $st->execute(['id' => $importId]);
    $i = $st->fetch() ?: throw new DomainException('Not found.');
    if ($i['deleted_at'] !== null) {
        throw new DomainException('That import is already deleted.');
    }
    $kept = $pdo->prepare("SELECT count(*) FROM bank_transactions WHERE import_id = :id AND status <> 'unmatched'");
    $kept->execute(['id' => $importId]);
    $del = $pdo->prepare("DELETE FROM bank_transactions WHERE import_id = :id AND status = 'unmatched'");
    $del->execute(['id' => $importId]);
    $pdo->prepare('UPDATE bank_imports SET deleted_at = now() WHERE id = :id')->execute(['id' => $importId]);
    return ['import_id' => $importId, 'bank_account_id' => (int) $i['bank_account_id'], 'filename' => $i['filename'], 'deleted' => $del->rowCount(), 'kept' => (int) $kept->fetchColumn()];
}

/** A line typed in by hand (a bank with no export). A line cannot be dated before the account's opening day? — it can: the opening balance is the bank's balance at the END of the opening day, so such a line never counts. */
function add_bank_line(PDO $pdo, int $bankAccountId, array $f, int $by): int
{
    $st = $pdo->prepare('INSERT INTO bank_transactions (bank_account_id, txn_date, amount, description, external_ref, entered_by) VALUES (:b, :d, :a, :de, :x, :by) RETURNING id');
    $st->execute(['b' => $bankAccountId, 'd' => $f['txn_date'], 'a' => $f['amount'], 'de' => $f['description'], 'x' => $f['reference'], 'by' => $by]);
    return (int) $st->fetchColumn();
}

/** Match a line to one ledger item; gl_bank_match() holds the to-the-cent rule and says it in its words. Answers the line and the item. */
function match_line(PDO $pdo, int $lineId, string $type, int $itemId, int $by): array
{
    if (!isset(MATCH_TYPES[$type])) {
        throw new DomainException('item_type is receipt, deposit, payment, transfer or journal_entry.');
    }
    $st = $pdo->prepare('SELECT (t).id, (t).bank_account_id, (t).amount, (t).txn_date FROM gl_bank_match(:l, :t, :i, :by) t');
    $st->execute(['l' => $lineId, 't' => $type, 'i' => $itemId, 'by' => $by]);
    $r = $st->fetch();
    return ['bank_transaction_id' => (int) $r['id'], 'bank_account_id' => (int) $r['bank_account_id'], 'item_type' => $type, 'item_id' => $itemId, 'amount' => $r['amount'], 'txn_date' => $r['txn_date']];
}

function unmatch_line(PDO $pdo, int $lineId): array
{
    $before = bank_line_state($pdo, $lineId) ?? throw new DomainException('Not found.');
    if ($before['status'] === 'unmatched') {
        throw new DomainException('Bank line ' . $lineId . ' is not matched.');
    }
    $pdo->prepare('SELECT gl_bank_unmatch(:l)')->execute(['l' => $lineId]);
    return $before;
}

function ignore_line(PDO $pdo, int $lineId, string $reason, int $by): array
{
    $pdo->prepare('SELECT gl_bank_ignore(:l, :by, :r)')->execute(['l' => $lineId, 'by' => $by, 'r' => $reason]);
    return bank_line_state($pdo, $lineId) ?? throw new DomainException('Not found.');
}

/** A fee, interest or any line the ledger does not yet hold: one posted entry against the account named, matched at once. Answers the entry's id and number and the account's code. */
function entry_from_line(PDO $pdo, int $lineId, int $accountId, ?string $memo, ?int $dept, ?int $site, int $by): array
{
    $line = bank_line_state($pdo, $lineId) ?? throw new DomainException('Not found.');
    $st = $pdo->prepare('SELECT (e).id, (e).number FROM gl_bank_entry_from_line(:l, :a, :by, :m, :d, :s) e');
    $st->execute(['l' => $lineId, 'a' => $accountId, 'by' => $by, 'm' => $memo, 'd' => $dept, 's' => $site]);
    $e = $st->fetch();
    $a = $pdo->prepare('SELECT code FROM accounts WHERE id = :a');
    $a->execute(['a' => $accountId]);
    return ['journal_entry_id' => (int) $e['id'], 'journal_entry_number' => (string) $e['number'], 'account_code' => (string) $a->fetchColumn(), 'amount' => $line['amount'], 'bank_account_id' => $line['bank_account_id'], 'bank_transaction_id' => $lineId];
}

/** Start a reconciliation (one in progress per account; a statement dated after the last one); the figures are computed by the function. */
function start_reconciliation(PDO $pdo, int $bankAccountId, string $statementDate, string $statementBalance, int $by): array
{
    $st = $pdo->prepare('SELECT (r).id FROM gl_reconciliation_start(:b, :d, :s, :by) r');
    $st->execute(['b' => $bankAccountId, 'd' => $statementDate, 's' => $statementBalance, 'by' => $by]);
    return find_reconciliation($pdo, (int) $st->fetchColumn()) ?? throw new DomainException('Not found.');
}

/** Recompute an in-progress reconciliation's cleared balance, difference and ledger balance (the page does this on every load). */
function recompute_reconciliation(PDO $pdo, int $id): array
{
    $pdo->prepare('SELECT gl_reconciliation_recompute(:id)')->execute(['id' => $id]);
    return find_reconciliation($pdo, $id) ?? throw new DomainException('Not found.');
}

/** Complete: only at a zero difference (the RAISE says the figures); the lines through the date are stamped and the account's last reconciled updated. */
function complete_reconciliation(PDO $pdo, int $id, int $by, ?string $note): array
{
    $pdo->prepare('SELECT gl_reconciliation_complete(:id, :by, :n)')->execute(['id' => $id, 'by' => $by, 'n' => $note]);
    return find_reconciliation($pdo, $id) ?? throw new DomainException('Not found.');
}

function reopen_reconciliation(PDO $pdo, int $id, int $by, string $reason): array
{
    $pdo->prepare('SELECT gl_reconciliation_reopen(:id, :by, :r)')->execute(['id' => $id, 'by' => $by, 'r' => $reason]);
    return find_reconciliation($pdo, $id) ?? throw new DomainException('Not found.');
}

/** Abandon the in-progress reconciliation: the row is deleted (nothing was stamped). Answers it. */
function abandon_reconciliation(PDO $pdo, int $id): array
{
    $r = find_reconciliation($pdo, $id) ?? throw new DomainException('Not found.');
    if ($r['status'] !== 'in_progress') {
        throw new DomainException('Only a reconciliation in progress is abandoned.');
    }
    $pdo->prepare("DELETE FROM bank_reconciliations WHERE id = :id AND status = 'in_progress'")->execute(['id' => $id]);
    return $r;
}

/** Record a transfer between two of the business's bank accounts of one currency. */
function record_transfer(PDO $pdo, int $from, int $to, string $date, string $amount, ?string $memo, int $by): array
{
    if ($from === $to) {
        throw new DomainException('A transfer is between two different bank accounts.');
    }
    $st = $pdo->prepare('SELECT (x).id FROM gl_transfer_record(:f, :t, :d, :a, :m, :by) x');
    $st->execute(['f' => $from, 't' => $to, 'd' => $date, 'a' => $amount, 'm' => $memo, 'by' => $by]);
    return transfer_state($pdo, (int) $st->fetchColumn()) ?? throw new DomainException('Not found.');
}

function void_transfer(PDO $pdo, int $id, int $by, string $reason): array
{
    $pdo->prepare('SELECT gl_transfer_void(:id, :by, :r)')->execute(['id' => $id, 'by' => $by, 'r' => $reason]);
    return transfer_state($pdo, $id) ?? throw new DomainException('Not found.');
}
