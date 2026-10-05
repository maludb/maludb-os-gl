<?php
declare(strict_types=1);

/**
 * The journal's writes (slice 2): the head and the lines to the base tables, the verbs through db/008's writer functions. The database is the
 * referee: every refusal arrives as a PDOException P0001 that the handler's entry_refused() turns into the 422. A DomainException here is our own
 * sentence (a row that names no account, a zero line). Requires journal/queries.php and accounts/queries.php.
 */

/** Resolve a line row's account: an id, a code or an exact name (live accounts). Throws DomainException naming the row. */
function resolve_line_account(PDO $pdo, mixed $ref, int $rowNo): int
{
    $ref = trim((string) $ref);
    if ($ref === '') {
        throw new DomainException('Row ' . $rowNo . ': name the account.');
    }
    if (ctype_digit($ref) && !preg_match('/^[0-9]{3,8}$/', $ref)) {
        $st = $pdo->prepare('SELECT account_id FROM mcp_accounts WHERE account_id = :id AND archived_at IS NULL');
        $st->execute(['id' => (int) $ref]);
        if (($id = $st->fetchColumn()) !== false) {
            return (int) $id;
        }
    }
    if (ctype_digit($ref)) {                                   // an id first, then a code (a 3–8 digit string is usually a code)
        $st = $pdo->prepare('SELECT account_id FROM mcp_accounts WHERE account_id = :id AND archived_at IS NULL');
        $st->execute(['id' => (int) $ref]);
        $byId = $st->fetchColumn();
        $a = find_account_by_code($pdo, $ref);
        if ($a !== null && $a['archived_at'] === null) {
            return $a['account_id'];
        }
        if ($byId !== false) {
            return (int) $byId;
        }
    }
    $a = find_account_by_code($pdo, $ref);
    if ($a !== null && $a['archived_at'] === null) {
        return $a['account_id'];
    }
    $st = $pdo->prepare('SELECT account_id FROM mcp_accounts WHERE lower(name) = lower(:n) AND archived_at IS NULL ORDER BY code LIMIT 1');
    $st->execute(['n' => $ref]);
    if (($id = $st->fetchColumn()) !== false) {
        return (int) $id;
    }
    throw new DomainException('Row ' . $rowNo . ': no account called ' . $ref . '.');
}

/**
 * Normalize one line's fields: account (resolved), description, debit, credit (one side; a negative debit becomes a credit), department, site,
 * customer, vendor, tax_rate. Throws DomainException in words. $rowNo names the row in a refusal.
 */
function normalize_line(PDO $pdo, array $row, int $rowNo): array
{
    $account = resolve_line_account($pdo, $row['account'] ?? $row['account_id'] ?? '', $rowNo);
    $debit = parse_money(isset($row['debit']) ? (string) $row['debit'] : null) ?? '0.00';
    $credit = parse_money(isset($row['credit']) ? (string) $row['credit'] : null) ?? '0.00';
    if ((isset($row['debit']) && (string) $row['debit'] !== '' && parse_money((string) $row['debit']) === null) || (isset($row['credit']) && (string) $row['credit'] !== '' && parse_money((string) $row['credit']) === null)) {
        throw new DomainException('Row ' . $rowNo . ': the amount is a number like 1,608.24.');
    }
    if ((float) $debit < 0) {
        $credit = number_format(-(float) $debit, 2, '.', '');
        $debit = '0.00';
    }
    if ((float) $credit < 0) {
        $debit = number_format(-(float) $credit, 2, '.', '');
        $credit = '0.00';
    }
    if (((float) $debit > 0) === ((float) $credit > 0)) {
        throw new DomainException('Row ' . $rowNo . ': a line is a debit or a credit.');
    }
    $ref = static function (string $key, string $sql, string $label) use ($pdo, $row, $rowNo): ?int {
        $v = $row[$key] ?? $row[$key . '_id'] ?? null;
        if ($v === null || $v === '' || $v === '0') {
            return null;
        }
        if (filter_var($v, FILTER_VALIDATE_INT) === false) {
            throw new DomainException('Row ' . $rowNo . ': choose the ' . $label . ' from the list.');
        }
        $st = $pdo->prepare($sql);
        $st->execute(['id' => (int) $v]);
        if ($st->fetchColumn() === false) {
            throw new DomainException('Row ' . $rowNo . ': that ' . $label . ' is not here.');
        }
        return (int) $v;
    };
    return ['account_id' => $account, 'description' => isset($row['description']) && trim((string) $row['description']) !== '' ? mb_substr(trim((string) $row['description']), 0, 300) : null,
            'debit' => $debit, 'credit' => $credit,
            'department_id' => $ref('department', 'SELECT 1 FROM departments WHERE id = :id AND archived_at IS NULL', 'department'),
            'site_id' => $ref('site', 'SELECT 1 FROM sites WHERE id = :id AND archived_at IS NULL', 'site'),
            'customer_id' => $ref('customer', 'SELECT 1 FROM customers WHERE id = :id', 'customer'),
            'vendor_id' => $ref('vendor', 'SELECT 1 FROM vendors WHERE id = :id', 'vendor'),
            'tax_rate_id' => $ref('tax_rate', 'SELECT 1 FROM tax_rates WHERE id = :id', 'tax rate')];
}

/** Draft an entry: the head (entry_date, memo, currency, department_id, site_id, source, source_type, source_id) and the normalized lines. Answers the id. */
function draft_entry(PDO $pdo, array $head, array $lines, int $by): int
{
    $st = $pdo->prepare('INSERT INTO journal_entries (entry_date, memo, currency, department_id, site_id, source, source_type, source_id, created_by) VALUES (:d, :m, :c, :dept, :site, :src, :st, :sid, :by) RETURNING id');
    $st->execute(['d' => $head['entry_date'], 'm' => $head['memo'] ?? null, 'c' => $head['currency'] ?? $pdo->query('SELECT gl_base_currency()')->fetchColumn(), 'dept' => $head['department_id'] ?? null, 'site' => $head['site_id'] ?? null,
                  'src' => $head['source'] ?? 'manual', 'st' => $head['source_type'] ?? null, 'sid' => $head['source_id'] ?? null, 'by' => $by]);
    $id = (int) $st->fetchColumn();
    $n = 0;
    foreach ($lines as $l) {
        $n++;
        insert_line($pdo, $id, $n, $l);
    }
    return $id;
}

function insert_line(PDO $pdo, int $entryId, int $lineNo, array $l): int
{
    $st = $pdo->prepare('INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, department_id, site_id, customer_id, vendor_id, tax_rate_id) VALUES (:e, :n, :a, :d, :dr, :cr, :dept, :site, :cust, :vend, :tax) RETURNING id');
    $st->execute(['e' => $entryId, 'n' => $lineNo, 'a' => $l['account_id'], 'd' => $l['description'], 'dr' => $l['debit'], 'cr' => $l['credit'], 'dept' => $l['department_id'], 'site' => $l['site_id'], 'cust' => $l['customer_id'], 'vend' => $l['vendor_id'], 'tax' => $l['tax_rate_id']]);
    return (int) $st->fetchColumn();
}

/** A draft's head: entry_date, memo, department_id, site_id (keys present change). Answers ['before' => …, 'after' => …] of the changed fields. The guard refuses a non-draft. */
function update_entry(PDO $pdo, int $id, array $head, int $by): array
{
    $st = $pdo->prepare('SELECT entry_date, memo, department_id, site_id FROM journal_entries WHERE id = :id FOR UPDATE');
    $st->execute(['id' => $id]);
    $cur = $st->fetch();
    if ($cur === false) {
        throw new DomainException('Not found.');
    }
    $before = ['entry_date' => $cur['entry_date'], 'memo' => $cur['memo'], 'department_id' => $cur['department_id'] === null ? null : (int) $cur['department_id'], 'site_id' => $cur['site_id'] === null ? null : (int) $cur['site_id']];
    $after = array_intersect_key($head, $before) + $before;
    $changed = array_keys(array_filter($after, static fn ($v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    if ($changed === []) {
        return ['before' => [], 'after' => []];
    }
    $pdo->prepare('UPDATE journal_entries SET entry_date = :d, memo = :m, department_id = :dept, site_id = :site WHERE id = :id')
        ->execute(['d' => $after['entry_date'], 'm' => $after['memo'], 'dept' => $after['department_id'], 'site' => $after['site_id'], 'id' => $id]);
    return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))];
}

/** Add ($lineId null) or change a line of a draft (the immutability trigger refuses anything else). Answers the line id. */
function save_line(PDO $pdo, int $entryId, ?int $lineId, array $f): int
{
    if ($lineId === null) {
        $st = $pdo->prepare('SELECT COALESCE(max(line_no), 0) + 1 FROM journal_lines WHERE entry_id = :e');
        $st->execute(['e' => $entryId]);
        return insert_line($pdo, $entryId, (int) $st->fetchColumn(), $f);
    }
    $st = $pdo->prepare('UPDATE journal_lines SET account_id = :a, description = :d, debit = :dr, credit = :cr, department_id = :dept, site_id = :site, customer_id = :cust, vendor_id = :vend, tax_rate_id = :tax WHERE id = :id AND entry_id = :e');
    $st->execute(['a' => $f['account_id'], 'd' => $f['description'], 'dr' => $f['debit'], 'cr' => $f['credit'], 'dept' => $f['department_id'], 'site' => $f['site_id'], 'cust' => $f['customer_id'], 'vend' => $f['vendor_id'], 'tax' => $f['tax_rate_id'], 'id' => $lineId, 'e' => $entryId]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Not found.');
    }
    return $lineId;
}

function remove_line(PDO $pdo, int $entryId, int $lineId): bool
{
    $st = $pdo->prepare('DELETE FROM journal_lines WHERE id = :id AND entry_id = :e');
    $st->execute(['id' => $lineId, 'e' => $entryId]);
    return $st->rowCount() > 0;
}

/** gl_entry_post(): the posted row (number, totals, period). */
function post_entry(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT (e).id, (e).number, (e).total_debit, (e).currency, (e).period_id, (e).entry_date FROM gl_entry_post(:id, :by) e');
    $st->execute(['id' => $id, 'by' => $by]);
    $r = $st->fetch();
    return ['journal_entry_id' => (int) $r['id'], 'number' => $r['number'], 'total' => $r['total_debit'], 'currency' => trim((string) $r['currency']), 'period_id' => $r['period_id'] === null ? null : (int) $r['period_id'], 'entry_date' => $r['entry_date']];
}

/** Post several, each in its own savepoint: ['posted' => [[id, number, total, currency]], 'refused' => [[id, sentence]]]. The caller holds the transaction. */
function post_entries(PDO $pdo, array $ids, int $by): array
{
    $posted = [];
    $refused = [];
    foreach ($ids as $i => $id) {
        $sp = 'post_' . $i;
        $pdo->exec('SAVEPOINT ' . $sp);
        try {
            $posted[] = post_entry($pdo, (int) $id, $by);
            $pdo->exec('RELEASE SAVEPOINT ' . $sp);
        } catch (PDOException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $sp);
            $refused[] = ['journal_entry_id' => (int) $id, 'reason' => (string) $e->getCode() === 'P0001' ? db_message($e, 'That could not be posted.') : 'That could not be posted.'];
        }
    }
    return ['posted' => $posted, 'refused' => $refused];
}

/** gl_entry_reverse(): the reversal row. */
function reverse_entry(PDO $pdo, int $id, int $by, string $reason, ?string $date): array
{
    $st = $pdo->prepare('SELECT (e).id, (e).number, (e).total_debit, (e).currency, (e).period_id, (e).entry_date FROM gl_entry_reverse(:id, :by, :r, :d) e');
    $st->execute(['id' => $id, 'by' => $by, 'r' => $reason, 'd' => $date]);
    $r = $st->fetch();
    return ['journal_entry_id' => (int) $r['id'], 'number' => $r['number'], 'total' => $r['total_debit'], 'currency' => trim((string) $r['currency']), 'period_id' => $r['period_id'] === null ? null : (int) $r['period_id'], 'entry_date' => $r['entry_date']];
}

/** gl_entry_void(): the voided row. */
function void_entry(PDO $pdo, int $id, int $by, ?string $reason): array
{
    $st = $pdo->prepare('SELECT (e).id, (e).status, (e).memo FROM gl_entry_void(:id, :by, :r) e');
    $st->execute(['id' => $id, 'by' => $by, 'r' => $reason]);
    $r = $st->fetch();
    return ['journal_entry_id' => (int) $r['id'], 'status' => $r['status'], 'memo' => $r['memo']];
}
