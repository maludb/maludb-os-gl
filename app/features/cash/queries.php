<?php
declare(strict_types=1);

/** Cash (slice 6): bank accounts, the bank's lines, candidates, reconciliations, transfers, the position and its trend — reads through the mcp_* views and gl_cash_position() / gl_bank_candidates(); money is a numeric string. */

const BANK_KINDS = ['checking' => 'Checking', 'savings' => 'Savings', 'credit_card' => 'Credit card', 'cash' => 'Cash', 'other' => 'Other'];
const LINE_STATUSES = ['unmatched' => 'Unmatched', 'matched' => 'Matched', 'ignored' => 'Ignored'];
const LINE_STATUS_BADGE = ['unmatched' => 'warning', 'matched' => 'success', 'ignored' => 'secondary'];
const MATCH_TYPES = ['receipt' => 'Receipt', 'deposit' => 'Deposit', 'payment' => 'Payment', 'transfer' => 'Transfer', 'journal_entry' => 'Entry'];
const RECON_STATUS_BADGE = ['in_progress' => 'info', 'completed' => 'success', 'reopened' => 'secondary'];
const BANK_LINE_PAGE = 50;

function bank_cast(array $b): array
{
    foreach (['bank_account_id', 'account_id', 'unmatched_lines', 'reconciliation_in_progress_id'] as $k) {
        $b[$k] = ($b[$k] ?? null) === null ? null : (int) $b[$k];
    }
    $b['currency'] = trim((string) $b['currency']);
    $b['difference'] = m_sub((string) $b['ledger_balance'], (string) $b['cleared_balance']);
    return $b;
}

function find_bank_accounts(PDO $pdo, bool $includeArchived = false): array
{
    $st = $pdo->query('SELECT * FROM mcp_bank_accounts' . ($includeArchived ? '' : ' WHERE archived_at IS NULL') . ' ORDER BY (archived_at IS NOT NULL), lower(name)');
    return array_map('bank_cast', $st->fetchAll());
}

function find_bank_account(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_bank_accounts WHERE bank_account_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : bank_cast($r);
}

/** The base row as the settings form reads it and a log row carries it. */
function bank_account_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT b.id AS bank_account_id, b.name, b.kind, b.account_id, a.code AS account_code, b.institution, b.account_last4, b.currency, b.opening_balance, b.opening_date, b.last_reconciled_through, b.last_reconciled_balance, b.archived_at
                           FROM bank_accounts b JOIN accounts a ON a.id = b.account_id WHERE b.id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['bank_account_id'] = (int) $r['bank_account_id'];
    $r['account_id'] = (int) $r['account_id'];
    $r['currency'] = trim((string) $r['currency']);
    return $r;
}

function bank_account_log_state(array $s): array
{
    return array_intersect_key($s, array_flip(['name', 'kind', 'account_id', 'institution', 'account_last4', 'currency', 'opening_balance', 'opening_date']));
}

/** What exists against a bank account: what fixes its currency and stops a delete. */
function bank_account_documents(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT (SELECT count(*) FROM bank_transactions WHERE bank_account_id = :b) AS lines, (SELECT count(*) FROM receipts WHERE deposit_to_bank_account_id = :b) AS receipts, (SELECT count(*) FROM deposits WHERE bank_account_id = :b) AS deposits,
                                (SELECT count(*) FROM payments WHERE bank_account_id = :b) AS payments, (SELECT count(*) FROM payment_runs WHERE bank_account_id = :b) AS payment_runs,
                                (SELECT count(*) FROM transfers WHERE :b IN (from_bank_account_id, to_bank_account_id)) AS transfers, (SELECT count(*) FROM bank_reconciliations WHERE bank_account_id = :b) AS reconciliations,
                                (SELECT count(*) FROM bank_reconciliations WHERE bank_account_id = :b AND status = 'completed') AS completed");
    $st->execute(['b' => $id]);
    return array_map('intval', $st->fetch());
}

/** The bank accounts a select offers: live, with currency and kind. */
function bank_accounts_for_select(PDO $pdo): array
{
    return array_map(static function (array $b): array { $b['bank_account_id'] = (int) $b['bank_account_id']; $b['currency'] = trim((string) $b['currency']); return $b; },
        $pdo->query('SELECT bank_account_id, name, currency, kind, account_code FROM mcp_bank_accounts WHERE archived_at IS NULL ORDER BY lower(name)')->fetchAll());
}

function line_cast(array $l): array
{
    foreach (['bank_transaction_id', 'bank_account_id', 'import_id', 'matched_id', 'reconciliation_id'] as $k) {
        $l[$k] = ($l[$k] ?? null) === null ? null : (int) $l[$k];
    }
    return $l;
}

function line_where(array $f): array
{
    $where = [];
    $args = [];
    if (($f['status'] ?? '') !== '' && isset(LINE_STATUSES[$f['status']])) {
        $where[] = 't.status = :st';
        $args['st'] = $f['status'];
    }
    foreach (['from' => 't.txn_date >= :from', 'to' => 't.txn_date <= :to'] as $k => $sql) {
        if (($f[$k] ?? '') !== '') {
            $where[] = $sql;
            $args[$k] = $f[$k];
        }
    }
    if (($f['q'] ?? '') !== '') {
        $where[] = '(t.description ILIKE :q OR t.external_ref ILIKE :q OR t.counterparty ILIKE :q)';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['q']) . '%';
    }
    return [$where === [] ? '' : ' AND ' . implode(' AND ', $where), $args];
}

/** A bank account's lines, newest first by default ($oldestFirst for the match page). Filters: status, from, to, q. */
function find_bank_lines(PDO $pdo, int $bankAccountId, array $filters = [], int $limit = BANK_LINE_PAGE, int $offset = 0, bool $oldestFirst = false): array
{
    [$w, $args] = line_where($filters);
    $st = $pdo->prepare('SELECT t.* FROM mcp_bank_transactions t WHERE t.bank_account_id = :b' . $w . ' ORDER BY t.txn_date ' . ($oldestFirst ? 'ASC, t.bank_transaction_id ASC' : 'DESC, t.bank_transaction_id DESC') . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset);
    $st->execute(['b' => $bankAccountId] + $args);
    return array_map('line_cast', $st->fetchAll());
}

function count_bank_lines(PDO $pdo, int $bankAccountId, array $filters = []): int
{
    [$w, $args] = line_where($filters);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_bank_transactions t WHERE t.bank_account_id = :b' . $w);
    $st->execute(['b' => $bankAccountId] + $args);
    return (int) $st->fetchColumn();
}

/** The status counts of a bank account's lines. */
function bank_line_counts(PDO $pdo, int $bankAccountId): array
{
    $st = $pdo->prepare('SELECT status, count(*) AS n FROM mcp_bank_transactions WHERE bank_account_id = :b GROUP BY status');
    $st->execute(['b' => $bankAccountId]);
    $out = ['unmatched' => 0, 'matched' => 0, 'ignored' => 0];
    foreach ($st->fetchAll() as $r) {
        $out[$r['status']] = (int) $r['n'];
    }
    return $out + ['all' => array_sum($out)];
}

function find_bank_line(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_bank_transactions WHERE bank_transaction_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : line_cast($r);
}

/** The base row of a line for a log row. */
function bank_line_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS bank_transaction_id, bank_account_id, txn_date, amount, description, status, matched_type, matched_id, reconciliation_id FROM bank_transactions WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : line_cast($r);
}

/** The ledger items a line may be matched to: gl_bank_candidates() (the same amount to the cent, ±5 days, not matched yet), nearest first. */
function line_candidates(PDO $pdo, int $lineId): array
{
    $st = $pdo->prepare('SELECT item_type, item_id, number, item_date, amount, description, days_apart FROM gl_bank_candidates(:l)');
    $st->execute(['l' => $lineId]);
    return array_map(static function (array $c): array { $c['item_id'] = (int) $c['item_id']; $c['days_apart'] = (int) $c['days_apart']; return $c; }, $st->fetchAll());
}

/** What a matched line is matched to: [label, url] by the record map, or null. */
function matched_item(PDO $pdo, array $line): ?array
{
    if ($line['status'] !== 'matched' || $line['matched_type'] === null) {
        return null;
    }
    $label = record_label($pdo, (string) $line['matched_type'], (int) $line['matched_id']);
    return ['type' => $line['matched_type'], 'id' => (int) $line['matched_id'], 'label' => $label ?? (MATCH_TYPES[$line['matched_type']] ?? $line['matched_type']) . ' #' . $line['matched_id'], 'url' => record_url((string) $line['matched_type'], (int) $line['matched_id'])];
}

function find_bank_imports(PDO $pdo, int $bankAccountId, int $limit = 10): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_bank_imports WHERE bank_account_id = :b ORDER BY bank_import_id DESC LIMIT ' . (int) $limit);
    $st->execute(['b' => $bankAccountId]);
    return $st->fetchAll();
}

function recon_cast(array $r): array
{
    foreach (['bank_reconciliation_id', 'bank_account_id', 'line_count', 'completed_by', 'reopened_by', 'started_by'] as $k) {
        $r[$k] = ($r[$k] ?? null) === null ? null : (int) $r[$k];
    }
    return $r;
}

function reconciliation_in_progress(PDO $pdo, int $bankAccountId): ?array
{
    $st = $pdo->prepare("SELECT * FROM mcp_bank_reconciliations WHERE bank_account_id = :b AND status = 'in_progress'");
    $st->execute(['b' => $bankAccountId]);
    $r = $st->fetch();
    return $r === false ? null : recon_cast($r);
}

function find_reconciliations(PDO $pdo, int $bankAccountId): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_bank_reconciliations WHERE bank_account_id = :b ORDER BY statement_date DESC, bank_reconciliation_id DESC');
    $st->execute(['b' => $bankAccountId]);
    return array_map('recon_cast', $st->fetchAll());
}

function find_reconciliation(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_bank_reconciliations WHERE bank_reconciliation_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : recon_cast($r);
}

/** The latest completed reconciliation of the account (the only one that may be reopened). */
function latest_completed_reconciliation(PDO $pdo, int $bankAccountId): ?int
{
    $st = $pdo->prepare("SELECT bank_reconciliation_id FROM mcp_bank_reconciliations WHERE bank_account_id = :b AND status = 'completed' ORDER BY statement_date DESC, bank_reconciliation_id DESC LIMIT 1");
    $st->execute(['b' => $bankAccountId]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int) $id;
}

/** The lines a reconciliation covers: a completed one's stamped lines; an in-progress one's every line through the statement date that is not yet stamped. Matched and ignored first as they stand, unmatched in red. */
function reconciliation_lines(PDO $pdo, array $rec): array
{
    if ($rec['status'] === 'in_progress') {
        $st = $pdo->prepare('SELECT * FROM mcp_bank_transactions WHERE bank_account_id = :b AND txn_date <= :d AND reconciliation_id IS NULL ORDER BY txn_date, bank_transaction_id');
        $st->execute(['b' => $rec['bank_account_id'], 'd' => $rec['statement_date']]);
    } else {
        $st = $pdo->prepare('SELECT * FROM mcp_bank_transactions WHERE reconciliation_id = :r ORDER BY txn_date, bank_transaction_id');
        $st->execute(['r' => $rec['bank_reconciliation_id']]);
    }
    return array_map('line_cast', $st->fetchAll());
}

function transfer_cast(array $t): array
{
    foreach (['transfer_id', 'from_bank_account_id', 'to_bank_account_id', 'journal_entry_id', 'recorded_by', 'voided_by'] as $k) {
        $t[$k] = ($t[$k] ?? null) === null ? null : (int) $t[$k];
    }
    $t['currency'] = trim((string) $t['currency']);
    return $t;
}

function find_transfers(PDO $pdo, array $filters = [], int $limit = 100): array
{
    $where = [];
    $args = [];
    if (($filters['bank_account'] ?? null) !== null && $filters['bank_account'] !== '') {
        $where[] = ':b IN (from_bank_account_id, to_bank_account_id)';
        $args['b'] = (int) $filters['bank_account'];
    }
    if (($filters['status'] ?? '') !== '') {
        $where[] = 'status = :s';
        $args['s'] = $filters['status'];
    }
    $st = $pdo->prepare('SELECT * FROM mcp_transfers' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY transfer_date DESC, transfer_id DESC LIMIT ' . (int) $limit);
    $st->execute($args);
    return array_map('transfer_cast', $st->fetchAll());
}

/** One transfer with its entry's number and the bank lines it matched (one per account). */
function find_transfer(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_transfers WHERE transfer_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $t = transfer_cast($r);
    $e = $pdo->prepare('SELECT number FROM mcp_journal_entries WHERE journal_entry_id = :e');
    $e->execute(['e' => $t['journal_entry_id']]);
    $t['journal_entry_number'] = $t['journal_entry_id'] === null ? null : ($e->fetchColumn() ?: null);
    $m = $pdo->prepare("SELECT bank_transaction_id, bank_account_id, bank_account_name, txn_date, amount FROM mcp_bank_transactions WHERE matched_type = 'transfer' AND matched_id = :id AND status = 'matched' ORDER BY bank_account_id");
    $m->execute(['id' => $id]);
    $t['matched_lines'] = array_map(static function (array $x): array { $x['bank_transaction_id'] = (int) $x['bank_transaction_id']; $x['bank_account_id'] = (int) $x['bank_account_id']; return $x; }, $m->fetchAll());
    return $t;
}

function transfer_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS transfer_id, number, from_bank_account_id, to_bank_account_id, transfer_date, amount, currency, memo, status, journal_entry_id FROM transfers WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : transfer_cast($r + ['recorded_by' => null, 'voided_by' => null]);
}

/** The cash position (gl_cash_position): one row per live bank account, with the difference. */
function cash_position(PDO $pdo, ?string $asOf = null): array
{
    $st = $pdo->prepare('SELECT * FROM gl_cash_position(COALESCE(CAST(:d AS date), current_date))');
    $st->execute(['d' => $asOf]);
    return array_map(static function (array $c): array {
        $c['bank_account_id'] = (int) $c['bank_account_id'];
        $c['unmatched_lines'] = (int) $c['unmatched_lines'];
        $c['currency'] = trim((string) $c['currency']);
        $c['difference'] = m_sub((string) $c['ledger_balance'], (string) $c['cleared_balance']);
        return $c;
    }, $st->fetchAll());
}

/** The ledger totals per currency of a position. */
function cash_totals(array $position): array
{
    $t = [];
    foreach ($position as $c) {
        $t[$c['currency']] = m_add($t[$c['currency']] ?? '0', (string) $c['ledger_balance']);
    }
    ksort($t);
    return array_map(static fn (string $cur, string $v): array => ['currency' => $cur, 'ledger_balance' => $v], array_keys($t), $t);
}

/** Month-end ledger balances of every live bank account for the last $months months (this month to date): ['months' => [Y-m-d, …] oldest first, 'accounts' => [{bank_account_id, name, currency}], 'rows' => [month_end => [bank_account_id => balance]]]. */
function cash_trend(PDO $pdo, int $months = 12): array
{
    $accounts = array_map(static fn (array $b): array => ['bank_account_id' => $b['bank_account_id'], 'name' => $b['name'], 'currency' => $b['currency']], bank_accounts_for_select($pdo));
    $ends = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $first = strtotime(date('Y-m-01') . ' -' . $i . ' months');
        $ends[] = $i === 0 ? date('Y-m-d') : date('Y-m-t', $first);
    }
    $st = $pdo->prepare('SELECT gl_bank_ledger_balance(:b, :d)');
    $rows = [];
    foreach ($ends as $d) {
        foreach ($accounts as $a) {
            $st->execute(['b' => $a['bank_account_id'], 'd' => $d]);
            $rows[$d][$a['bank_account_id']] = (string) $st->fetchColumn();
        }
    }
    return ['months' => $ends, 'accounts' => $accounts, 'rows' => $rows];
}

/** The ledger account's recent activity, for the bank account page: its posted lines in a range, newest first (slice 1's account_activity()). */
function bank_ledger_activity(PDO $pdo, array $bank, string $from, string $to): array
{
    return array_reverse(account_activity($pdo, $bank['account_id'], $from, $to)['rows']);
}

/** The prefill of slice 2's entry form for a bank account's opening balance: DR the bank's ledger account / CR 3000 Owner equity (a card, or a negative opening, the other way round). */
function opening_entry_prefill(PDO $pdo, int $bankAccountId): ?array
{
    $b = bank_account_state($pdo, $bankAccountId);
    if ($b === null) {
        return null;
    }
    $equity = find_account_by_code($pdo, '3000');
    $amount = ltrim((string) $b['opening_balance'], '-');
    $isCard = $b['kind'] === 'credit_card';
    $positive = m_cmp((string) $b['opening_balance'], '0') >= 0;
    $bankDebit = $isCard ? !$positive : $positive;
    $name = 'Opening balance — ' . $b['name'];
    return ['memo' => $name, 'entry_date' => $b['opening_date'], 'lines' => [
        ['account' => $b['account_id'], 'description' => $name, 'debit' => $bankDebit ? $amount : '0', 'credit' => $bankDebit ? '0' : $amount],
        ['account' => $equity['account_id'] ?? null, 'description' => $name, 'debit' => $bankDebit ? '0' : $amount, 'credit' => $bankDebit ? $amount : '0'],
    ]];
}
