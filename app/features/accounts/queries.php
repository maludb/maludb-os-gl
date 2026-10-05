<?php
declare(strict_types=1);

/**
 * The chart of accounts (slice 1, docs/build-specs/chart-fiscal.md): reads through mcp_accounts and the db/015 functions (the database decides
 * what the caller sees and what a balance is), writes to the base table under the handler's gate. An account's state for a log row is
 * account_state(): before/after of the changed fields only. The guard trigger (db/005) and the archive guard (db/008) are the referee.
 */

const ACCOUNT_TYPES = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expenses'];
const ACCOUNT_SUBTYPES = [
    'asset'     => ['cash' => 'Cash', 'bank' => 'Bank', 'undeposited' => 'Undeposited funds', 'receivable' => 'Receivable', 'inventory' => 'Inventory', 'prepaid' => 'Prepaid', 'current_asset' => 'Other current asset', 'fixed' => 'Fixed asset', 'other_asset' => 'Other asset', 'other' => 'Other'],
    'liability' => ['payable' => 'Payable', 'credit_card' => 'Credit card', 'tax' => 'Tax payable', 'payroll' => 'Payroll', 'accrued' => 'Accrued', 'current_liability' => 'Other current liability', 'loan' => 'Loan', 'other_liability' => 'Other liability', 'other' => 'Other'],
    'equity'    => ['owner' => 'Owner equity', 'retained' => 'Retained earnings', 'other_equity' => 'Other equity', 'other' => 'Other'],
    'income'    => ['revenue' => 'Revenue', 'other_income' => 'Other income', 'other' => 'Other'],
    'expense'   => ['cogs' => 'Cost of goods sold', 'operating' => 'Operating', 'bad_debt' => 'Bad debt', 'other_expense' => 'Other expense', 'other' => 'Other'],
];

const ACCOUNT_SELECT = 'SELECT a.account_id, a.code, a.name, a.account_type, a.type_label, a.normal_balance, a.subtype, a.parent_id, p.code AS parent_code, p.name AS parent_name,
       a.is_bank, a.is_control, a.is_system, a.description, a.archived_at, a.created_at, a.updated_at,
       (SELECT count(*) FROM mcp_accounts c WHERE c.parent_id = a.account_id AND c.archived_at IS NULL) AS child_count
  FROM mcp_accounts a LEFT JOIN mcp_accounts p ON p.account_id = a.parent_id';

function account_cast(array $a): array
{
    foreach (['account_id', 'parent_id', 'child_count'] as $k) {
        $a[$k] = $a[$k] === null ? null : (int) $a[$k];
    }
    foreach (['is_bank', 'is_control', 'is_system'] as $k) {
        $a[$k] = (bool) $a[$k];
    }
    return $a;
}

/** The chart the caller may see, each with its balance as of a date in its normal sense. $filters: type, include_archived, as_of, q. */
function find_accounts(PDO $pdo, array $filters = []): array
{
    $where = [];
    $args = [];
    if (empty($filters['include_archived'])) {
        $where[] = 'a.archived_at IS NULL';
    }
    if (($filters['type'] ?? '') !== '' && isset(ACCOUNT_TYPES[$filters['type']])) {
        $where[] = 'a.account_type = :t';
        $args['t'] = $filters['type'];
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(a.code ILIKE :q OR a.name ILIKE :q)';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    $st = $pdo->prepare(ACCOUNT_SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY a.code');
    $st->execute($args);
    $rows = array_map('account_cast', $st->fetchAll());
    $asOf = $filters['as_of'] ?? date('Y-m-d');
    $bal = $pdo->prepare('SELECT gl_account_balance(:a, :d)');
    foreach ($rows as &$r) {
        $bal->execute(['a' => $r['account_id'], 'd' => $asOf]);
        $r['balance'] = $bal->fetchColumn();
        $r['balance_as_of'] = $asOf;
    }
    unset($r);
    return $rows;
}

function find_account(PDO $pdo, int $id, ?string $asOf = null): ?array
{
    $st = $pdo->prepare(ACCOUNT_SELECT . ' WHERE a.account_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r = account_cast($r);
    $bal = $pdo->prepare('SELECT gl_account_balance(:a, :d)');
    $bal->execute(['a' => $id, 'd' => $asOf ?? date('Y-m-d')]);
    $r['balance'] = $bal->fetchColumn();
    $r['balance_as_of'] = $asOf ?? date('Y-m-d');
    return $r;
}

function find_account_by_code(PDO $pdo, string $code): ?array
{
    $st = $pdo->prepare('SELECT account_id FROM mcp_accounts WHERE code = :c');
    $st->execute(['c' => $code]);
    $id = $st->fetchColumn();
    return $id === false ? null : find_account($pdo, (int) $id);
}

/** The base row as a log row and the edit form read it. */
function account_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS account_id, code, name, account_type, subtype, parent_id, is_bank, is_control, is_system, description, archived_at FROM accounts WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['account_id'] = (int) $r['account_id'];
    $r['parent_id'] = $r['parent_id'] === null ? null : (int) $r['parent_id'];
    foreach (['is_bank', 'is_control', 'is_system'] as $k) {
        $r[$k] = (bool) $r[$k];
    }
    return $r;
}

function account_log_state(array $s): array
{
    return array_intersect_key($s, array_flip(['code', 'name', 'account_type', 'subtype', 'parent_id', 'is_bank', 'description']));
}

/** An account's activity over a range with a running balance (gl_account_activity()). */
function account_activity(PDO $pdo, int $id, string $from, string $to, ?int $dept = null, ?int $site = null): array
{
    $st = $pdo->prepare('SELECT * FROM gl_account_activity(:a, :f, :t, :d, :s)');
    $st->execute(['a' => $id, 'f' => $from, 't' => $to, 'd' => $dept, 's' => $site]);
    $rows = $st->fetchAll();
    $open = $pdo->prepare('SELECT gl_account_balance(:a, :d, :dept, :site)');
    $open->execute(['a' => $id, 'd' => date('Y-m-d', strtotime($from . ' -1 day')), 'dept' => $dept, 'site' => $site]);
    return ['opening' => $open->fetchColumn(), 'rows' => $rows];
}

/** Add ($id null) or change an account. $f: code, name, account_type, subtype, parent_id, is_bank, description. The code is unique. Answers the id. */
function save_account(PDO $pdo, ?int $id, array $f, int $by): int
{
    $dupe = $pdo->prepare('SELECT name FROM accounts WHERE code = :c AND id IS DISTINCT FROM :id');
    $dupe->execute(['c' => $f['code'], 'id' => $id]);
    if (($owner = $dupe->fetchColumn()) !== false) {
        throw new DomainException('The code ' . $f['code'] . ' is already used by ' . $owner . '.');
    }
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO accounts (code, name, account_type, subtype, parent_id, is_bank, description) VALUES (:c, :n, :t, :s, :p, :b, :d) RETURNING id');
        $st->execute(['c' => $f['code'], 'n' => $f['name'], 't' => $f['account_type'], 's' => $f['subtype'], 'p' => $f['parent_id'], 'b' => $f['is_bank'] ? 't' : 'f', 'd' => $f['description']]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE accounts SET code = :c, name = :n, account_type = :t, subtype = :s, parent_id = :p, is_bank = :b, description = :d WHERE id = :id')
        ->execute(['c' => $f['code'], 'n' => $f['name'], 't' => $f['account_type'], 's' => $f['subtype'], 'p' => $f['parent_id'], 'b' => $f['is_bank'] ? 't' : 'f', 'd' => $f['description'], 'id' => $id]);
    return $id;
}

/** Archive or restore (the archive guard refuses a balance). */
function archive_account(PDO $pdo, int $id, bool $archived, int $by = 0): void
{
    $s = account_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($archived && $s['archived_at'] !== null) {
        throw new DomainException($s['code'] . ' ' . $s['name'] . ' is already archived.');
    }
    if (!$archived && $s['archived_at'] === null) {
        throw new DomainException($s['code'] . ' ' . $s['name'] . ' is not archived.');
    }
    $pdo->prepare('UPDATE accounts SET archived_at = ' . ($archived ? 'now()' : 'NULL') . ' WHERE id = :id')->execute(['id' => $id]);
}

/** The accounts a picker offers: live, by type and flag. [{account_id, code, name, account_type, subtype, is_bank, is_control}] */
function accounts_for_select(PDO $pdo, array $types = [], array $flags = []): array
{
    $where = ['archived_at IS NULL'];
    $args = [];
    if ($types !== []) {
        $in = [];
        foreach (array_values($types) as $i => $t) {
            $in[] = ':t' . $i;
            $args['t' . $i] = $t;
        }
        $where[] = 'account_type IN (' . implode(', ', $in) . ')';
    }
    foreach ($flags as $flag => $want) {
        if (in_array($flag, ['is_bank', 'is_control'], true)) {
            $where[] = $flag . ' = ' . ($want ? 'true' : 'false');
        }
    }
    $st = $pdo->prepare('SELECT account_id, code, name, account_type, subtype, is_bank, is_control FROM mcp_accounts WHERE ' . implode(' AND ', $where) . ' ORDER BY code');
    $st->execute($args);
    return array_map(static function (array $a): array {
        $a['account_id'] = (int) $a['account_id'];
        $a['is_bank'] = (bool) $a['is_bank'];
        $a['is_control'] = (bool) $a['is_control'];
        return $a;
    }, $st->fetchAll());
}
