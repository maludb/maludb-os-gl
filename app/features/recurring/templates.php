<?php
declare(strict_types=1);

/**
 * Recurring templates (slice 8): the shape each kind's form writes and an agent sends, its validation (every refusal names its line), and `run_template()` — the one place a template becomes a DRAFT entry, bill or invoice.
 * Accounts and parties are ids in a saved template (names resolve at save); a deleted or archived one makes the run fail with a sentence, never a half-made document. The draft is a person's to post, approve or issue (D7).
 */

const TEMPLATE_KINDS = ['journal' => 'Journal entry', 'bill' => 'Bill', 'invoice' => 'Invoice'];
const CADENCES = ['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'];
const TEMPLATE_HEAD_KEYS = ['journal' => ['memo', 'department', 'site', 'currency', 'day_offset', 'lines'], 'bill' => ['vendor', 'vendor_ref_pattern', 'terms_days', 'memo', 'department', 'site', 'lines'], 'invoice' => ['customer', 'reference_pattern', 'memo', 'department', 'site', 'lines']];
const TEMPLATE_LINE_KEYS = ['journal' => ['account', 'description', 'debit', 'credit', 'department', 'site', 'customer', 'vendor'], 'bill' => ['item', 'expense_account', 'description', 'quantity', 'unit_price', 'tax_rate', 'department', 'site'], 'invoice' => ['item', 'income_account', 'description', 'quantity', 'unit_price', 'tax_rate', 'department', 'site']];

/** "Monthly", "Every 2 weeks", "Quarterly", "Every 2 quarters". */
function cadence_words(string $cadence, int $every): string
{
    if ($every === 1) {
        return CADENCES[$cadence] ?? $cadence;
    }
    return 'Every ' . $every . ' ' . ['weekly' => 'weeks', 'monthly' => 'months', 'quarterly' => 'quarters', 'yearly' => 'years'][$cadence];
}

/** A reference pattern with the run date written in: {YYYY-MM} → 2026-10, {YYYY}, {MM}, {DD}, {MON} (Oct). */
function expand_pattern(string $pattern, string $runDate): string
{
    $t = strtotime($runDate);
    return strtr($pattern, ['{YYYY-MM}' => date('Y-m', $t), '{YYYY}' => date('Y', $t), '{MM}' => date('m', $t), '{DD}' => date('d', $t), '{MON}' => date('M', $t)]);
}

/** A party or dimension named by id or exact name → its id; throws "<where>: …". */
function template_ref(PDO $pdo, mixed $v, string $table, string $label, string $where, bool $required = false): ?int
{
    if ($v === null || $v === '' || $v === '0' || $v === 0) {
        if ($required) {
            throw new DomainException($where . ': choose the ' . $label . '.');
        }
        return null;
    }
    $live = in_array($table, ['departments', 'sites', 'vendors', 'customers'], true) ? ' AND archived_at IS NULL' : '';
    if (is_int($v) || ctype_digit((string) $v)) {
        $st = $pdo->prepare("SELECT id FROM {$table} WHERE id = :id{$live}");
        $st->execute(['id' => (int) $v]);
        if (($id = $st->fetchColumn()) !== false) {
            return (int) $id;
        }
    }
    $st = $pdo->prepare("SELECT id FROM {$table} WHERE lower(name) = lower(:n){$live} LIMIT 1");
    $st->execute(['n' => trim((string) $v)]);
    if (($id = $st->fetchColumn()) !== false) {
        return (int) $id;
    }
    throw new DomainException($where . ': that ' . $label . ' is not here (or is archived).');
}

/**
 * Validate a template for its kind and answer it normalized (ids, money as strings). Refusals name their line: "Line 2: a line is a debit or a credit.", "Line 1: name the account.", "Unknown key flavour".
 */
function validate_template(PDO $pdo, string $kind, array $t): array
{
    if (!isset(TEMPLATE_KINDS[$kind])) {
        throw new DomainException('The kind is journal, bill or invoice.');
    }
    $unknown = array_diff(array_keys($t), TEMPLATE_HEAD_KEYS[$kind]);
    if ($unknown !== []) {
        throw new DomainException('The template has a key a ' . $kind . ' template does not: ' . implode(', ', $unknown) . '.');
    }
    $lines = $t['lines'] ?? [];
    if (!is_array($lines) || $lines === []) {
        throw new DomainException('Line 1: a template needs at least one line.');
    }
    $out = ['memo' => isset($t['memo']) && trim((string) $t['memo']) !== '' ? mb_substr(trim((string) $t['memo']), 0, 1000) : null,
            'department' => template_ref($pdo, $t['department'] ?? null, 'departments', 'department', 'The template'), 'site' => template_ref($pdo, $t['site'] ?? null, 'sites', 'site', 'The template')];
    $rows = [];
    foreach (array_values($lines) as $i => $line) {
        $n = $i + 1;
        $where = 'Line ' . $n;
        if (!is_array($line)) {
            throw new DomainException($where . ': a line is an object.');
        }
        $bad = array_diff(array_keys($line), TEMPLATE_LINE_KEYS[$kind]);
        if ($bad !== []) {
            throw new DomainException($where . ' has a key a ' . $kind . ' line does not: ' . implode(', ', $bad) . '.');
        }
        try {
            if ($kind === 'journal') {
                $nl = normalize_line($pdo, $line, $n);
                $rows[] = ['account' => $nl['account_id'], 'description' => $nl['description'], 'debit' => $nl['debit'], 'credit' => $nl['credit'], 'department' => $nl['department_id'], 'site' => $nl['site_id'], 'customer' => $nl['customer_id'], 'vendor' => $nl['vendor_id']];
            } else {
                $side = $kind === 'bill' ? 'expense' : 'income';
                $nl = normalize_doc_line($pdo, $line, $n, null, $side);
                $rows[] = ['item' => $nl['item_id'], $side . '_account' => $nl['account_id'], 'description' => $nl['description'], 'quantity' => $nl['quantity'], 'unit_price' => $nl['unit_price'], 'tax_rate' => $nl['tax_rate_id'], 'department' => $nl['department_id'], 'site' => $nl['site_id']];
            }
        } catch (DomainException $e) {
            throw new DomainException(preg_replace('/^Row (\d+):/', 'Line $1:', $e->getMessage()));
        }
    }
    if ($kind === 'journal') {
        $dr = '0'; $cr = '0';
        foreach ($rows as $r) { $dr = m_add($dr, $r['debit']); $cr = m_add($cr, $r['credit']); }
        if (m_cmp($dr, $cr) !== 0) {
            throw new DomainException('The lines do not balance: debits ' . $dr . ' and credits ' . $cr . '.');
        }
        $out['currency'] = isset($t['currency']) && $t['currency'] !== '' ? strtoupper((string) $t['currency']) : null;
        if ($out['currency'] !== null && !preg_match('/^[A-Z]{3}$/', $out['currency'])) {
            throw new DomainException('The currency is three letters (USD).');
        }
        $off = $t['day_offset'] ?? 0;
        if (filter_var($off, FILTER_VALIDATE_INT) === false || (int) $off < -31 || (int) $off > 31) {
            throw new DomainException('day_offset is a number of days from -31 to 31 (0: the run date).');
        }
        $out['day_offset'] = (int) $off;
    } elseif ($kind === 'bill') {
        $out['vendor'] = template_ref($pdo, $t['vendor'] ?? null, 'vendors', 'vendor', 'The template', true);
        $out['vendor_ref_pattern'] = isset($t['vendor_ref_pattern']) && trim((string) $t['vendor_ref_pattern']) !== '' ? mb_substr(trim((string) $t['vendor_ref_pattern']), 0, 120) : null;
        $terms = $t['terms_days'] ?? null;
        if ($terms !== null && $terms !== '' && (filter_var($terms, FILTER_VALIDATE_INT) === false || (int) $terms < 0 || (int) $terms > 365)) {
            throw new DomainException('terms_days is 0 to 365, or empty for the vendor\'s.');
        }
        $out['terms_days'] = $terms === null || $terms === '' ? null : (int) $terms;
    } else {
        $out['customer'] = template_ref($pdo, $t['customer'] ?? null, 'customers', 'customer', 'The template', true);
        $out['reference_pattern'] = isset($t['reference_pattern']) && trim((string) $t['reference_pattern']) !== '' ? mb_substr(trim((string) $t['reference_pattern']), 0, 120) : null;
    }
    $out['lines'] = $rows;
    return $out;
}

/** A template from an existing document — journal_entry, bill or invoice — its lines as the kind's rows. */
function template_from(PDO $pdo, string $kind, int $recordId): array
{
    if ($kind === 'journal') {
        $e = find_entry($pdo, $recordId) ?? throw new DomainException('Not found.');
        $lines = array_map(static fn (array $l): array => ['account' => $l['account_id'], 'description' => $l['description'], 'debit' => $l['debit'], 'credit' => $l['credit'], 'department' => $l['department_id'], 'site' => $l['site_id'], 'customer' => $l['customer_id'], 'vendor' => $l['vendor_id']], $e['lines']);
        return ['kind' => 'journal', 'name' => $e['memo'] ?: 'Entry ' . ($e['number'] ?? ''), 'template' => ['memo' => $e['memo'], 'department' => $e['department_id'], 'site' => $e['site_id'], 'currency' => $e['currency'], 'day_offset' => 0, 'lines' => $lines]];
    }
    if ($kind === 'bill') {
        $b = find_bill($pdo, $recordId) ?? throw new DomainException('Not found.');
        $lines = array_map(static fn (array $l): array => ['item' => $l['item_id'], 'expense_account' => $l['expense_account_id'], 'description' => $l['description'], 'quantity' => rtrim(rtrim((string) $l['quantity'], '0'), '.'), 'unit_price' => $l['unit_price'], 'tax_rate' => $l['tax_rate_id'], 'department' => $l['department_id'], 'site' => $l['site_id']], $b['lines']);
        return ['kind' => 'bill', 'name' => $b['vendor_name'] . ' — recurring', 'template' => ['vendor' => $b['vendor_id'], 'vendor_ref_pattern' => null, 'terms_days' => null, 'memo' => $b['memo'], 'department' => $b['department_id'], 'site' => $b['site_id'], 'lines' => $lines]];
    }
    $i = find_invoice($pdo, $recordId) ?? throw new DomainException('Not found.');
    $lines = array_map(static fn (array $l): array => ['item' => $l['item_id'], 'income_account' => $l['income_account_id'], 'description' => $l['description'], 'quantity' => rtrim(rtrim((string) $l['quantity'], '0'), '.'), 'unit_price' => $l['unit_price'], 'tax_rate' => $l['tax_rate_id'], 'department' => $l['department_id'], 'site' => $l['site_id']], $i['lines']);
    return ['kind' => 'invoice', 'name' => $i['customer_name'] . ' — recurring', 'template' => ['customer' => $i['customer_id'], 'reference_pattern' => null, 'memo' => $i['memo'], 'department' => $i['department_id'], 'site' => $i['site_id'], 'lines' => $lines]];
}

/** The templates that are due: active, next_date on or before the day. */
function templates_due(PDO $pdo, string $asOf): array
{
    $st = $pdo->prepare('SELECT * FROM recurring_templates WHERE active AND next_date <= :d ORDER BY next_date, id');
    $st->execute(['d' => $asOf]);
    return $st->fetchAll();
}

/** Every id a saved template names must still be there and live: a sentence naming the line, or null. */
function template_still_valid(PDO $pdo, string $kind, array $t): ?string
{
    try {
        validate_template($pdo, $kind, $t);
        return null;
    } catch (DomainException $e) {
        return $e->getMessage();
    }
}

/** The day a draft is dated plus an offset in days. */
function plus_days(string $date, int $days): string
{
    return date('Y-m-d', strtotime($date . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
}

/**
 * Draft the template's document dated for $runDate (default the template's next date), in one transaction with gl_recurring_advance(): the run is recorded as drafted with the document's type and id and the schedule moves on
 * (a template whose next date after this run passes its end date goes inactive). A failure is recorded as a `failed` run with the sentence and advances nothing. A date already drafted is refused. $source is web | cron (the log).
 * Answers ['status' => drafted | failed, 'record_type', 'record_id', 'detail', 'run_date', 'template_id'].
 */
function run_template(PDO $pdo, int $id, ?string $runDate, int $by, string $source = 'web'): array
{
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    $st = $pdo->prepare('SELECT * FROM recurring_templates WHERE id = :id FOR UPDATE');
    $st->execute(['id' => $id]);
    $t = $st->fetch() ?: throw new DomainException('Not found.');
    $date = $runDate ?? (string) $t['next_date'];
    $seen = $pdo->prepare("SELECT status FROM recurring_runs WHERE template_id = :t AND run_date = :d");
    $seen->execute(['t' => $id, 'd' => $date]);
    if ($seen->fetchColumn() === 'drafted') {
        if ($ownTx) { $pdo->rollBack(); }
        throw new DomainException('"' . $t['name'] . '" was already drafted for ' . $date . '.');
    }
    $tpl = json_decode((string) $t['template'], true) ?: [];
    $kind = (string) $t['kind'];
    if ($by <= 0) {
        $by = (int) ($t['created_by'] ?? 0) ?: (int) $pdo->query("SELECT id FROM members WHERE business_role = 'super_admin' AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    }
    $recordType = null;
    $recordId = null;
    $pdo->exec('SAVEPOINT recurring_run');
    try {
        $why = template_still_valid($pdo, $kind, $tpl);
        if ($why !== null) {
            throw new DomainException($why);
        }
        if ($kind === 'journal') {
            $lines = [];
            foreach ($tpl['lines'] as $i => $l) {
                $lines[] = normalize_line($pdo, ['account' => $l['account'], 'description' => $l['description'] ?? null, 'debit' => $l['debit'], 'credit' => $l['credit'], 'department' => $l['department'] ?? null, 'site' => $l['site'] ?? null, 'customer' => $l['customer'] ?? null, 'vendor' => $l['vendor'] ?? null], $i + 1);
            }
            $recordId = draft_entry($pdo, ['entry_date' => plus_days($date, (int) ($tpl['day_offset'] ?? 0)), 'memo' => $tpl['memo'] ?? $t['name'], 'currency' => $tpl['currency'] ?? null, 'department_id' => $tpl['department'] ?? null, 'site_id' => $tpl['site'] ?? null,
                'source' => 'recurring'], $lines, $by);
            $recordType = 'journal_entry';
        } elseif ($kind === 'bill') {
            $defaults = bill_defaults($pdo, (int) $tpl['vendor'], $date);
            $terms = $tpl['terms_days'] ?? null;
            $lines = [];
            foreach ($tpl['lines'] as $i => $l) {
                $lines[] = normalize_doc_line($pdo, $l, $i + 1, $defaults, 'expense');
            }
            $ref = ($tpl['vendor_ref_pattern'] ?? null) === null ? null : expand_pattern((string) $tpl['vendor_ref_pattern'], $date);
            $recordId = draft_bill($pdo, ['vendor_id' => (int) $tpl['vendor'], 'vendor_ref' => $ref, 'bill_date' => $date, 'due_date' => $terms === null ? $defaults['due_date'] : plus_days($date, (int) $terms), 'currency' => $defaults['currency'],
                'memo' => $tpl['memo'] ?? null, 'department_id' => $tpl['department'] ?? null, 'site_id' => $tpl['site'] ?? null, 'source' => 'recurring'], $lines, $by);
            $recordType = 'bill';
        } else {
            $defaults = invoice_defaults($pdo, (int) $tpl['customer'], $date);
            $lines = [];
            foreach ($tpl['lines'] as $i => $l) {
                $lines[] = normalize_doc_line($pdo, $l, $i + 1, $defaults, 'income');
            }
            $ref = ($tpl['reference_pattern'] ?? null) === null ? null : expand_pattern((string) $tpl['reference_pattern'], $date);
            $recordId = draft_invoice($pdo, ['customer_id' => (int) $tpl['customer'], 'issue_date' => $date, 'due_date' => $defaults['due_date'], 'currency' => $defaults['currency'], 'reference' => $ref, 'memo' => $tpl['memo'] ?? null, 'terms_text' => null,
                'department_id' => $tpl['department'] ?? null, 'site_id' => $tpl['site'] ?? null], $lines, $by);
            $recordType = 'invoice';
        }
        $pdo->exec('RELEASE SAVEPOINT recurring_run');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK TO SAVEPOINT recurring_run');
        $msg = $e instanceof PDOException ? db_message($e, 'The document could not be drafted.') : $e->getMessage();
        $pdo->prepare("INSERT INTO recurring_runs (template_id, run_date, status, detail) VALUES (:t, :d, 'failed', :m) ON CONFLICT (template_id, run_date) DO UPDATE SET status = 'failed', detail = EXCLUDED.detail, created_at = now()")->execute(['t' => $id, 'd' => $date, 'm' => mb_substr($msg, 0, 500)]);
        log_activity($pdo, 'recurring.run', 'recurring_template', $id, ['source' => $source, 'actor_member_id' => $source === 'cron' ? null : $by, 'after' => ['status' => 'failed', 'run_date' => $date, 'reason' => mb_substr($msg, 0, 300)]]);
        if ($ownTx) { $pdo->commit(); }
        return ['status' => 'failed', 'record_type' => null, 'record_id' => null, 'detail' => $msg, 'run_date' => $date, 'template_id' => $id];
    }
    $pdo->prepare("DELETE FROM recurring_runs WHERE template_id = :t AND run_date = :d AND status = 'failed'")->execute(['t' => $id, 'd' => $date]);
    $pdo->prepare("SELECT gl_recurring_advance(:t, :d, :rt, :ri, 'drafted', NULL)")->execute(['t' => $id, 'd' => $date, 'rt' => $recordType, 'ri' => $recordId]);
    $keys = $kind === 'bill' ? ['vendor_id' => (int) $tpl['vendor']] : ($kind === 'invoice' ? ['customer_id' => (int) $tpl['customer']] : ['journal_entry_id' => $recordId]);
    log_activity($pdo, 'recurring.run', 'recurring_template', $id, ['source' => $source, 'actor_member_id' => $source === 'cron' ? null : $by] + $keys + ['after' => ['status' => 'drafted', 'record_type' => $recordType, 'record_id' => $recordId, 'run_date' => $date, 'name' => $t['name']]]);
    if ($ownTx) {
        $pdo->commit();
    }
    return ['status' => 'drafted', 'record_type' => $recordType, 'record_id' => $recordId, 'detail' => null, 'run_date' => $date, 'template_id' => $id];
}
