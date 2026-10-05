<?php
declare(strict_types=1);

/**
 * The ledger's own configuration (slice 1, docs/build-specs/chart-fiscal.md): settings, numbering, tax rates, items, posting rules, sites,
 * the agents that keep books here. Reads through the mcp_* views; writes to the base tables under the handler's gate (settings.manage).
 * Requires app/features/accounts/queries.php for the account pickers.
 */

const SETTINGS_KEYS = ['business_name', 'business_address', 'business_tax_id', 'base_currency', 'fiscal_year_start_month', 'timezone', 'ar_account_id', 'ap_account_id',
    'undeposited_funds_account_id', 'sales_tax_account_id', 'ai_usage_account_id', 'retained_earnings_account_id', 'bad_debt_account_id', 'default_bank_account_id',
    'invoice_terms_days', 'bill_terms_days', 'link_lifetime_days', 'reminder_days', 'ai_statement_poll', 'morning_note_member_id', 'invoice_footer'];
/** The control accounts and the type each is narrowed to (the form's selects; the handler refuses another type in words). */
const CONTROL_ACCOUNTS = ['ar_account_id' => ['asset', 'Accounts receivable'], 'ap_account_id' => ['liability', 'Accounts payable'], 'undeposited_funds_account_id' => ['asset', 'Undeposited funds'],
    'sales_tax_account_id' => ['liability', 'Sales tax payable'], 'ai_usage_account_id' => ['expense', 'AI usage'], 'retained_earnings_account_id' => ['equity', 'Retained earnings'], 'bad_debt_account_id' => ['expense', 'Bad debt']];
const SEQUENCE_KINDS = ['invoice' => 'Invoice', 'credit_note' => 'Credit note', 'journal_entry' => 'Journal entry', 'bill' => 'Bill', 'vendor_credit' => 'Vendor credit', 'receipt' => 'Receipt',
    'payment' => 'Payment', 'deposit' => 'Deposit', 'payment_run' => 'Payment run', 'transfer' => 'Transfer'];
const POSTING_SOURCES = ['invoice' => 'Invoice issued', 'credit_note' => 'Credit note issued', 'receipt' => 'Receipt recorded', 'deposit' => 'Deposit recorded', 'write_off' => 'Invoice written off',
    'bill' => 'Bill approved', 'vendor_credit' => 'Vendor credit issued', 'payment' => 'Payment recorded', 'ai_usage' => 'AI usage (the OS statement)', 'payroll' => 'Payroll',
    'bank_fee' => 'Bank fee', 'bank_interest' => 'Bank interest', 'transfer' => 'Transfer', 'opening' => 'Opening balance'];
/** The usual account type of each rule's side (the form warns when another is chosen). */
const POSTING_USUAL = ['invoice' => ['asset', 'income'], 'credit_note' => ['income', 'asset'], 'receipt' => ['asset', 'asset'], 'deposit' => ['asset', 'asset'], 'write_off' => ['expense', 'asset'],
    'bill' => ['expense', 'liability'], 'vendor_credit' => ['liability', 'expense'], 'payment' => ['liability', 'asset'], 'ai_usage' => ['expense', 'liability'], 'payroll' => ['expense', 'liability'],
    'bank_fee' => ['expense', 'asset'], 'bank_interest' => ['asset', 'income'], 'transfer' => ['asset', 'asset'], 'opening' => ['asset', 'equity']];
const ITEM_KINDS = ['service' => 'Service', 'product' => 'Product'];

// ---- settings ------------------------------------------------------------------------------------------------------------
function find_settings(PDO $pdo): array
{
    $r = $pdo->query('SELECT s.*, g.business_tax_id, g.invoice_footer FROM mcp_settings s JOIN gl_settings g ON g.id = 1')->fetch();
    if ($r === false) {
        throw new RuntimeException('The settings row is missing.');
    }
    return settings_cast($r);
}

function settings_cast(array $r): array
{
    foreach (['fiscal_year_start_month', 'invoice_terms_days', 'bill_terms_days', 'link_lifetime_days'] as $k) {
        $r[$k] = (int) $r[$k];
    }
    foreach (CONTROL_ACCOUNTS as $k => $_) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    $r['default_bank_account_id'] = $r['default_bank_account_id'] === null ? null : (int) $r['default_bank_account_id'];
    $r['morning_note_member_id'] = $r['morning_note_member_id'] === null ? null : (int) $r['morning_note_member_id'];
    $r['ai_statement_poll'] = (bool) $r['ai_statement_poll'];
    $r['reminder_days'] = pg_int_array((string) $r['reminder_days']);
    $r['base_currency'] = trim((string) $r['base_currency']);
    return array_intersect_key($r, array_flip(SETTINGS_KEYS)) + ['updated_at' => $r['updated_at']];
}

/** True once any document exists: the base currency is fixed from then on. */
function books_hold_a_document(PDO $pdo): bool
{
    return db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM journal_entries) OR EXISTS (SELECT 1 FROM invoices) OR EXISTS (SELECT 1 FROM bills) OR EXISTS (SELECT 1 FROM receipts) OR EXISTS (SELECT 1 FROM payments)');
}

/** Save the keys present in $f (the rest stay). Answers ['before' => …, 'after' => …] of the changed keys only. */
function save_settings(PDO $pdo, array $f): array
{
    $before = find_settings($pdo);
    unset($before['updated_at']);
    $f = array_intersect_key($f, array_flip(SETTINGS_KEYS));
    $after = $f + $before;
    $changed = array_keys(array_filter($after, static fn ($v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    if ($changed === []) {
        return ['before' => [], 'after' => []];
    }
    $sets = [];
    $args = [];
    foreach ($changed as $k) {
        if ($k === 'reminder_days') {
            $sets[] = 'reminder_days = CAST(:reminder_days AS integer[])';
            $args['reminder_days'] = pg_array_literal($after[$k]);
        } elseif ($k === 'ai_statement_poll') {
            $sets[] = 'ai_statement_poll = :ai_statement_poll';
            $args['ai_statement_poll'] = $after[$k] ? 't' : 'f';
        } else {
            $sets[] = $k . ' = :' . $k;
            $args[$k] = $after[$k];
        }
    }
    $st = $pdo->prepare('UPDATE gl_settings SET ' . implode(', ', $sets) . ', updated_at = now() WHERE id = 1');
    $st->execute($args);
    return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))];
}

// ---- numbering -------------------------------------------------------------------------------------------------------------
function numbering_preview(string $prefix, int $next, int $padding): string
{
    return $prefix . str_pad((string) $next, $padding, '0', STR_PAD_LEFT);
}

/** Every document sequence in kind order, with a preview of the next number. */
function find_numbering(PDO $pdo): array
{
    $rows = $pdo->query('SELECT kind, prefix, next_value, padding, updated_at FROM document_sequences')->fetchAll();
    $byKind = array_column($rows, null, 'kind');
    $out = [];
    foreach (SEQUENCE_KINDS as $kind => $label) {
        if (!isset($byKind[$kind])) {
            continue;
        }
        $r = $byKind[$kind];
        $out[] = ['kind' => $kind, 'label' => $label, 'prefix' => $r['prefix'], 'next_value' => (int) $r['next_value'], 'padding' => (int) $r['padding'],
                  'preview' => numbering_preview($r['prefix'], (int) $r['next_value'], (int) $r['padding']), 'updated_at' => $r['updated_at']];
    }
    return $out;
}

/** Change one sequence ($f keys present: prefix, next_value, padding). The next value never goes down. Answers before/after of the changed keys. */
function save_numbering(PDO $pdo, string $kind, array $f): array
{
    $st = $pdo->prepare('SELECT prefix, next_value, padding FROM document_sequences WHERE kind = :k FOR UPDATE');
    $st->execute(['k' => $kind]);
    $cur = $st->fetch();
    if ($cur === false) {
        throw new DomainException('Not found.');
    }
    $before = ['prefix' => $cur['prefix'], 'next_value' => (int) $cur['next_value'], 'padding' => (int) $cur['padding']];
    $after = array_intersect_key($f, $before) + $before;
    if ($after['next_value'] < $before['next_value']) {
        throw new DomainException('The next number cannot go below the current (' . $before['next_value'] . ').');
    }
    $changed = array_keys(array_filter($after, static fn ($v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    if ($changed !== []) {
        $pdo->prepare('UPDATE document_sequences SET prefix = :p, next_value = :n, padding = :d, updated_at = now() WHERE kind = :k')
            ->execute(['p' => $after['prefix'], 'n' => $after['next_value'], 'd' => $after['padding'], 'k' => $kind]);
    }
    return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))];
}

// ---- tax rates --------------------------------------------------------------------------------------------------------------
function tax_rate_cast(array $t): array
{
    $t['tax_rate_id'] = (int) $t['tax_rate_id'];
    $t['is_default'] = (bool) $t['is_default'];
    return $t;
}

function find_tax_rates(PDO $pdo, bool $includeArchived = false): array
{
    return array_map('tax_rate_cast', $pdo->query('SELECT tax_rate_id, name, rate, is_default, archived_at FROM mcp_tax_rates' . ($includeArchived ? '' : ' WHERE archived_at IS NULL') . ' ORDER BY is_default DESC, lower(name)')->fetchAll());
}

function find_tax_rate(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT tax_rate_id, name, rate, is_default, archived_at FROM mcp_tax_rates WHERE tax_rate_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : tax_rate_cast($r);
}

/** Add ($id null) or change a rate. $f: name, rate, is_default. Making one the default un-defaults the other in the same transaction. */
function save_tax_rate(PDO $pdo, ?int $id, array $f): int
{
    $dupe = $pdo->prepare('SELECT 1 FROM tax_rates WHERE lower(name) = lower(:n) AND archived_at IS NULL AND id IS DISTINCT FROM :id');
    $dupe->execute(['n' => $f['name'], 'id' => $id]);
    if ($dupe->fetchColumn() !== false) {
        throw new DomainException('A rate named ' . $f['name'] . ' already exists.');
    }
    if ($id !== null && !$f['is_default'] && db_bool($pdo, 'SELECT is_default FROM tax_rates WHERE id = :id', ['id' => $id])) {
        throw new DomainException('Make another rate the default first.');
    }
    if ($f['is_default']) {
        $pdo->prepare('UPDATE tax_rates SET is_default = false WHERE is_default AND id IS DISTINCT FROM :id')->execute(['id' => $id]);
    }
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO tax_rates (name, rate, is_default) VALUES (:n, :r, :d) RETURNING id');
        $st->execute(['n' => $f['name'], 'r' => $f['rate'], 'd' => $f['is_default'] ? 't' : 'f']);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE tax_rates SET name = :n, rate = :r, is_default = :d WHERE id = :id')->execute(['n' => $f['name'], 'r' => $f['rate'], 'd' => $f['is_default'] ? 't' : 'f', 'id' => $id]);
    return $id;
}

function archive_tax_rate(PDO $pdo, int $id, bool $archived): void
{
    $st = $pdo->prepare('SELECT name, is_default, archived_at FROM tax_rates WHERE id = :id');
    $st->execute(['id' => $id]);
    $t = $st->fetch() ?: throw new DomainException('Not found.');
    if ($archived && $t['is_default']) {
        throw new DomainException('Make another rate the default first.');
    }
    if ($archived && $t['archived_at'] !== null) {
        throw new DomainException($t['name'] . ' is already archived.');
    }
    if (!$archived && $t['archived_at'] === null) {
        throw new DomainException($t['name'] . ' is not archived.');
    }
    $pdo->prepare('UPDATE tax_rates SET archived_at = ' . ($archived ? 'now()' : 'NULL') . ' WHERE id = :id')->execute(['id' => $id]);
}

// ---- items --------------------------------------------------------------------------------------------------------------------
const ITEM_SELECT = 'SELECT i.item_id, i.code, i.name, i.kind, i.description, i.unit, i.unit_price, i.income_account_id, ia.code AS income_account_code, ia.name AS income_account_name,
       i.expense_account_id, ea.code AS expense_account_code, ea.name AS expense_account_name, i.tax_rate_id, t.name AS tax_rate_name, t.rate AS tax_rate, i.archived_at
  FROM mcp_items i LEFT JOIN mcp_accounts ia ON ia.account_id = i.income_account_id LEFT JOIN mcp_accounts ea ON ea.account_id = i.expense_account_id LEFT JOIN mcp_tax_rates t ON t.tax_rate_id = i.tax_rate_id';

function item_cast(array $i): array
{
    foreach (['item_id', 'income_account_id', 'expense_account_id', 'tax_rate_id'] as $k) {
        $i[$k] = $i[$k] === null ? null : (int) $i[$k];
    }
    return $i;
}

/** $filters: q (name or code), kind, include_archived. */
function find_items(PDO $pdo, array $filters = []): array
{
    $where = [];
    $args = [];
    if (empty($filters['include_archived'])) {
        $where[] = 'i.archived_at IS NULL';
    }
    if (($filters['kind'] ?? '') !== '' && isset(ITEM_KINDS[$filters['kind']])) {
        $where[] = 'i.kind = :k';
        $args['k'] = $filters['kind'];
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(i.name ILIKE :q OR i.code ILIKE :q)';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    $st = $pdo->prepare(ITEM_SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY lower(i.name)');
    $st->execute($args);
    return array_map('item_cast', $st->fetchAll());
}

function find_item(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(ITEM_SELECT . ' WHERE i.item_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : item_cast($r);
}

function item_log_state(array $i): array
{
    return array_intersect_key($i, array_flip(['code', 'name', 'kind', 'description', 'unit', 'unit_price', 'income_account_id', 'expense_account_id', 'tax_rate_id']));
}

/** Add ($id null) or change an item. $f: code, name, kind, description, unit, unit_price, income_account_id, expense_account_id, tax_rate_id. A live code is unique. */
function save_item(PDO $pdo, ?int $id, array $f): int
{
    if ($f['code'] !== null) {
        $dupe = $pdo->prepare('SELECT name FROM items WHERE lower(code) = lower(:c) AND archived_at IS NULL AND id IS DISTINCT FROM :id');
        $dupe->execute(['c' => $f['code'], 'id' => $id]);
        if (($owner = $dupe->fetchColumn()) !== false) {
            throw new DomainException('The code ' . $f['code'] . ' is already used by ' . $owner . '.');
        }
    }
    $args = ['c' => $f['code'], 'n' => $f['name'], 'k' => $f['kind'], 'd' => $f['description'], 'u' => $f['unit'], 'p' => $f['unit_price'], 'ia' => $f['income_account_id'], 'ea' => $f['expense_account_id'], 't' => $f['tax_rate_id']];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO items (code, name, kind, description, unit, unit_price, income_account_id, expense_account_id, tax_rate_id) VALUES (:c, :n, :k, :d, :u, :p, :ia, :ea, :t) RETURNING id');
        $st->execute($args);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE items SET code = :c, name = :n, kind = :k, description = :d, unit = :u, unit_price = :p, income_account_id = :ia, expense_account_id = :ea, tax_rate_id = :t WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

function archive_item(PDO $pdo, int $id, bool $archived): void
{
    $st = $pdo->prepare('SELECT name, archived_at FROM items WHERE id = :id');
    $st->execute(['id' => $id]);
    $i = $st->fetch() ?: throw new DomainException('Not found.');
    if ($archived && $i['archived_at'] !== null) {
        throw new DomainException($i['name'] . ' is already archived.');
    }
    if (!$archived && $i['archived_at'] === null) {
        throw new DomainException($i['name'] . ' is not archived.');
    }
    $pdo->prepare('UPDATE items SET archived_at = ' . ($archived ? 'now()' : 'NULL') . ' WHERE id = :id')->execute(['id' => $id]);
}

// ---- posting rules --------------------------------------------------------------------------------------------------------
function posting_rule_cast(array $r): array
{
    foreach (['posting_rule_id', 'debit_account_id', 'credit_account_id', 'tax_account_id'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    $r['active'] = (bool) $r['active'];
    $r['source_label'] = POSTING_SOURCES[$r['source']] ?? $r['source'];
    return $r;
}

/** Every rule in source order (the defaults first, then the keyed ones). */
function find_posting_rules(PDO $pdo): array
{
    $order = "array_position(ARRAY['" . implode("','", array_keys(POSTING_SOURCES)) . "']::text[], r.source)";
    return array_map('posting_rule_cast', $pdo->query('SELECT r.*, ta.name AS tax_account_name FROM mcp_posting_rules r LEFT JOIN mcp_accounts ta ON ta.account_id = r.tax_account_id ORDER BY ' . $order . ', r.match_key NULLS FIRST')->fetchAll());
}

function find_posting_rule(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT r.*, ta.name AS tax_account_name FROM mcp_posting_rules r LEFT JOIN mcp_accounts ta ON ta.account_id = r.tax_account_id WHERE r.posting_rule_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : posting_rule_cast($r);
}

function posting_rule_log_state(array $r): array
{
    return array_intersect_key($r, array_flip(['source', 'match_key', 'debit_account_id', 'credit_account_id', 'tax_account_id', 'note', 'active']));
}

/**
 * Save a rule: $rule is an existing rule's id, or a source name (with $f['match_key'] — a new keyed rule, or the default rule of that source when
 * it exists). $f: match_key (new only), debit_account_id, credit_account_id, tax_account_id, note, active. Answers the rule id.
 */
function save_posting_rule(PDO $pdo, string|int $rule, array $f): int
{
    $id = null;
    if (is_int($rule) || ctype_digit((string) $rule)) {
        $id = (int) $rule;
        $st = $pdo->prepare('SELECT id FROM posting_rules WHERE id = :id');
        $st->execute(['id' => $id]);
        if ($st->fetchColumn() === false) {
            throw new DomainException('Not found.');
        }
    } else {
        $source = (string) $rule;
        if (!isset(POSTING_SOURCES[$source])) {
            throw new DomainException('No posting source is called ' . $source . '.');
        }
        $key = ($f['match_key'] ?? '') === '' ? null : $f['match_key'];
        $st = $pdo->prepare('SELECT id FROM posting_rules WHERE source = :s AND match_key IS NOT DISTINCT FROM :k');
        $st->execute(['s' => $source, 'k' => $key]);
        $found = $st->fetchColumn();
        if ($found === false) {
            $ins = $pdo->prepare('INSERT INTO posting_rules (source, match_key, debit_account_id, credit_account_id, tax_account_id, note, active) VALUES (:s, :k, :d, :c, :t, :n, :a) RETURNING id');
            $ins->execute(['s' => $source, 'k' => $key, 'd' => $f['debit_account_id'] ?? null, 'c' => $f['credit_account_id'] ?? null, 't' => $f['tax_account_id'] ?? null, 'n' => $f['note'] ?? null, 'a' => ($f['active'] ?? true) ? 't' : 'f']);
            return (int) $ins->fetchColumn();
        }
        $id = (int) $found;
    }
    $sets = [];
    $args = ['id' => $id];
    foreach (['debit_account_id' => 'd', 'credit_account_id' => 'c', 'tax_account_id' => 't', 'note' => 'n'] as $k => $p) {
        if (array_key_exists($k, $f)) {
            $sets[] = $k . ' = :' . $p;
            $args[$p] = $f[$k];
        }
    }
    if (array_key_exists('active', $f)) {
        $sets[] = 'active = :a';
        $args['a'] = $f['active'] ? 't' : 'f';
    }
    if ($sets !== []) {
        $pdo->prepare('UPDATE posting_rules SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args);
    }
    return $id;
}

// ---- sites ------------------------------------------------------------------------------------------------------------------------
function site_cast(array $s): array
{
    $s['site_id'] = (int) $s['site_id'];
    $s['kernel_location_id'] = $s['kernel_location_id'] === null ? null : (int) $s['kernel_location_id'];
    return $s;
}

function find_sites(PDO $pdo, bool $includeArchived = false): array
{
    return array_map('site_cast', $pdo->query('SELECT site_id, name, kernel_location_id, address, archived_at FROM mcp_sites' . ($includeArchived ? '' : ' WHERE archived_at IS NULL') . ' ORDER BY lower(name)')->fetchAll());
}

function find_site(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT site_id, name, kernel_location_id, address, archived_at FROM mcp_sites WHERE site_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : site_cast($r);
}

/** $f: name, kernel_location_id, address. A live name is unique. */
function save_site(PDO $pdo, ?int $id, array $f): int
{
    $dupe = $pdo->prepare('SELECT 1 FROM sites WHERE lower(name) = lower(:n) AND archived_at IS NULL AND id IS DISTINCT FROM :id');
    $dupe->execute(['n' => $f['name'], 'id' => $id]);
    if ($dupe->fetchColumn() !== false) {
        throw new DomainException('A site named ' . $f['name'] . ' already exists.');
    }
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO sites (name, kernel_location_id, address) VALUES (:n, :k, :a) RETURNING id');
        $st->execute(['n' => $f['name'], 'k' => $f['kernel_location_id'], 'a' => $f['address']]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE sites SET name = :n, kernel_location_id = :k, address = :a WHERE id = :id')->execute(['n' => $f['name'], 'k' => $f['kernel_location_id'], 'a' => $f['address'], 'id' => $id]);
    return $id;
}

function archive_site(PDO $pdo, int $id, bool $archived): void
{
    $st = $pdo->prepare('SELECT name, archived_at FROM sites WHERE id = :id');
    $st->execute(['id' => $id]);
    $s = $st->fetch() ?: throw new DomainException('Not found.');
    if ($archived && $s['archived_at'] !== null) {
        throw new DomainException($s['name'] . ' is already archived.');
    }
    if (!$archived && $s['archived_at'] === null) {
        throw new DomainException($s['name'] . ' is not archived.');
    }
    $pdo->prepare('UPDATE sites SET archived_at = ' . ($archived ? 'now()' : 'NULL') . ' WHERE id = :id')->execute(['id' => $id]);
}

// ---- agents ----------------------------------------------------------------------------------------------------------------------
/** The OS agents the mirror admits here, each with its last dispatch. */
function find_agents_here(PDO $pdo): array
{
    $rows = $pdo->query("SELECT member_id, display_name, job_title, roles FROM mcp_members WHERE member_kind = 'agent' AND capability IS NOT NULL AND status = 'active' ORDER BY display_name")->fetchAll();
    foreach ($rows as &$a) {
        $a['member_id'] = (int) $a['member_id'];
        $a['roles'] = pg_text_array((string) $a['roles']);
        $d = $pdo->prepare('SELECT agent_dispatch_id, record_type, record_id, kind, via, status, created_at, answered_at FROM mcp_agent_dispatches WHERE agent_member_id = :m ORDER BY created_at DESC LIMIT 1');
        $d->execute(['m' => $a['member_id']]);
        $a['last_dispatch'] = $d->fetch() ?: null;
    }
    unset($a);
    return $rows;
}

/** The people (and agents) a settings select may name: live members with a capability. */
function find_members_for_select(PDO $pdo): array
{
    return array_map(static function (array $m): array { $m['member_id'] = (int) $m['member_id']; return $m; },
        $pdo->query("SELECT member_id, display_name, member_kind FROM mcp_members WHERE status = 'active' AND capability IS NOT NULL ORDER BY member_kind, display_name")->fetchAll());
}
