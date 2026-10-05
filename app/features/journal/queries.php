<?php
declare(strict_types=1);

/**
 * The journal's reads (slice 2, THE EXEMPLAR): entries and lines through mcp_journal_entries / mcp_journal_lines (the database decides what the
 * caller sees), the draft's balance state for the strip (a courtesy — the trigger is the gate), the source document through the record map.
 * Requires records/views.php.
 */

const ENTRY_STATUSES = ['draft' => 'Draft', 'posted' => 'Posted', 'reversed' => 'Reversed', 'void' => 'Void'];
const ENTRY_STATUS_BADGE = ['draft' => 'secondary', 'posted' => 'success', 'reversed' => 'warning', 'void' => 'dark'];
const ENTRY_SOURCES = ['manual' => 'Manual', 'invoice' => 'Invoice', 'credit_note' => 'Credit note', 'receipt' => 'Receipt', 'deposit' => 'Deposit', 'write_off' => 'Write-off', 'bill' => 'Bill',
    'vendor_credit' => 'Vendor credit', 'payment' => 'Payment', 'transfer' => 'Transfer', 'bank_fee' => 'Bank fee', 'bank_interest' => 'Interest', 'ai_usage' => 'AI usage', 'payroll' => 'Payroll',
    'recurring' => 'Recurring', 'reversal' => 'Reversal', 'closing' => 'Year-end close', 'opening' => 'Opening balances', 'import' => 'Import'];
/** The sources that have no document of their own: reversed from the journal page. The rest are reversed by their document's void. */
const ENTRY_SOURCES_WITHOUT_DOCUMENT = ['manual', 'recurring', 'import', 'opening', 'reversal', 'closing'];
const ENTRY_SORTS = ['entry_date' => 'e.entry_date', 'number' => 'e.number', 'total_debit' => 'e.total_debit'];
const ENTRY_PAGE = 50;

const ENTRY_SELECT = 'SELECT e.*, COALESCE(p.reopen_count, 0) AS period_reopen_count,
       (SELECT COALESCE(sum(l.debit), 0) FROM journal_lines l WHERE l.entry_id = e.journal_entry_id) AS draft_debit,
       (SELECT COALESCE(sum(l.credit), 0) FROM journal_lines l WHERE l.entry_id = e.journal_entry_id) AS draft_credit
  FROM mcp_journal_entries e LEFT JOIN mcp_fiscal_periods p ON p.fiscal_period_id = e.period_id';

function entry_cast(array $e): array
{
    foreach (['journal_entry_id', 'period_id', 'source_id', 'reversal_of', 'reversed_by', 'department_id', 'site_id', 'created_by', 'posted_by', 'voided_by', 'line_count', 'attachment_count', 'period_reopen_count'] as $k) {
        $e[$k] = ($e[$k] ?? null) === null ? null : (int) $e[$k];
    }
    $e['currency'] = trim((string) $e['currency']);
    $e['source_label'] = $e['source'] === 'reversal' && $e['reversal_of_number'] !== null ? 'Reversal of ' . $e['reversal_of_number'] : (ENTRY_SOURCES[$e['source']] ?? $e['source']);
    $e['difference'] = number_format((float) $e['draft_debit'] - (float) $e['draft_credit'], 2, '.', '');
    $e['balances'] = (float) $e['difference'] == 0.0 && (float) $e['draft_debit'] > 0;
    $e['total'] = $e['status'] === 'draft' ? number_format((float) $e['draft_debit'], 2, '.', '') : $e['total_debit'];
    $e['has_document'] = !in_array($e['source'], ENTRY_SOURCES_WITHOUT_DOCUMENT, true) && $e['source_type'] !== null;
    return $e;
}

/** The WHERE of the list's filters: period, status, source, account, from, to, q. */
function entry_where(array $filters): array
{
    $where = [];
    $args = [];
    if (($filters['period'] ?? null) !== null && $filters['period'] !== '') {
        $where[] = 'e.period_id = :period';
        $args['period'] = (int) $filters['period'];
    }
    if (($filters['status'] ?? '') !== '' && isset(ENTRY_STATUSES[$filters['status']])) {
        $where[] = 'e.status = :status';
        $args['status'] = $filters['status'];
    }
    if (($filters['source'] ?? '') !== '' && isset(ENTRY_SOURCES[$filters['source']])) {
        $where[] = 'e.source = :source';
        $args['source'] = $filters['source'];
    }
    if (($filters['account'] ?? null) !== null && $filters['account'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM journal_lines l WHERE l.entry_id = e.journal_entry_id AND l.account_id = :account)';
        $args['account'] = (int) $filters['account'];
    }
    if (($filters['from'] ?? '') !== '') {
        $where[] = 'e.entry_date >= :from';
        $args['from'] = $filters['from'];
    }
    if (($filters['to'] ?? '') !== '') {
        $where[] = 'e.entry_date <= :to';
        $args['to'] = $filters['to'];
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(e.number ILIKE :q OR e.memo ILIKE :q)';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $args];
}

/** The entries the caller may see: drafts first, then by date desc (or ?sort= from the allow-list). */
function find_entries(PDO $pdo, array $filters, int $limit = ENTRY_PAGE, int $offset = 0): array
{
    [$w, $args] = entry_where($filters);
    $sort = ENTRY_SORTS[$filters['sort'] ?? ''] ?? null;
    $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $order = $sort === null ? "(e.status = 'draft') DESC, e.entry_date DESC, e.journal_entry_id DESC" : "(e.status = 'draft') DESC, {$sort} {$dir} NULLS LAST, e.journal_entry_id DESC";
    $st = $pdo->prepare(ENTRY_SELECT . $w . ' ORDER BY ' . $order . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset);
    $st->execute($args);
    return array_map('entry_cast', $st->fetchAll());
}

function count_entries(PDO $pdo, array $filters): int
{
    [$w, $args] = entry_where($filters);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_journal_entries e' . $w);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

/** Totals of the listed POSTED entries per currency: [{currency, entries, total}]. */
function entry_totals(PDO $pdo, array $filters): array
{
    [$w, $args] = entry_where($filters);
    $st = $pdo->prepare('SELECT e.currency, count(*) AS entries, sum(e.total_debit) AS total FROM mcp_journal_entries e' . ($w === '' ? ' WHERE ' : $w . ' AND ') . "e.status IN ('posted', 'reversed') GROUP BY e.currency ORDER BY e.currency");
    $st->execute($args);
    return array_map(static fn (array $r): array => ['currency' => trim((string) $r['currency']), 'entries' => (int) $r['entries'], 'total' => $r['total']], $st->fetchAll());
}

/** One entry with its lines, its source document (label and url through the record map) and the attachments count. */
function find_entry(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(ENTRY_SELECT . ' WHERE e.journal_entry_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $e = entry_cast($r);
    $e['lines'] = entry_lines($pdo, $id);
    $e['document'] = null;
    if ($e['source_type'] !== null && $e['source_id'] !== null) {
        $e['document'] = ['record_type' => $e['source_type'], 'record_id' => $e['source_id'], 'label' => record_label($pdo, (string) $e['source_type'], $e['source_id']),
                          'url' => record_known((string) $e['source_type']) ? record_url((string) $e['source_type'], $e['source_id']) : null];
    }
    return $e;
}

function find_entry_by_number(PDO $pdo, string $number): ?array
{
    $st = $pdo->prepare('SELECT journal_entry_id FROM mcp_journal_entries WHERE number = :n');
    $st->execute(['n' => $number]);
    $id = $st->fetchColumn();
    return $id === false ? null : find_entry($pdo, (int) $id);
}

function entry_lines(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT l.*, a.is_control, a.subtype, t.name AS tax_rate_name FROM mcp_journal_lines l JOIN mcp_accounts a ON a.account_id = l.account_id LEFT JOIN mcp_tax_rates t ON t.tax_rate_id = l.tax_rate_id WHERE l.journal_entry_id = :id ORDER BY l.line_no');
    $st->execute(['id' => $id]);
    return array_map(static function (array $l): array {
        foreach (['journal_line_id', 'journal_entry_id', 'line_no', 'account_id', 'department_id', 'site_id', 'customer_id', 'vendor_id', 'tax_rate_id'] as $k) {
            $l[$k] = $l[$k] === null ? null : (int) $l[$k];
        }
        $l['is_control'] = (bool) $l['is_control'];
        return $l;
    }, $st->fetchAll());
}

/** The strip: debits, credits, difference — from the lines, a courtesy only. */
function entry_balance(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT COALESCE(sum(debit), 0) AS debits, COALESCE(sum(credit), 0) AS credits, count(*) AS lines FROM journal_lines WHERE entry_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    $diff = number_format((float) $r['debits'] - (float) $r['credits'], 2, '.', '');
    return ['debits' => number_format((float) $r['debits'], 2, '.', ''), 'credits' => number_format((float) $r['credits'], 2, '.', ''), 'difference' => $diff, 'lines' => (int) $r['lines'], 'balances' => (float) $diff == 0.0 && (int) $r['lines'] > 0];
}

/** The swapped lines of a posted entry, as rows for journal_entry_draft (?reverse_of=). */
function reversal_prefill(PDO $pdo, int $id): array
{
    $e = find_entry($pdo, $id) ?? throw new DomainException('Not found.');
    if ($e['status'] !== 'posted') {
        throw new DomainException('Only a posted entry is reversed.');
    }
    $rows = [];
    foreach ($e['lines'] as $l) {
        $rows[] = ['account' => $l['account_id'], 'description' => $l['description'], 'debit' => $l['credit'], 'credit' => $l['debit'], 'department' => $l['department_id'], 'site' => $l['site_id'], 'customer' => $l['customer_id'], 'vendor' => $l['vendor_id'], 'tax_rate' => $l['tax_rate_id']];
    }
    return ['memo' => 'Reversal of ' . $e['number'] . ($e['memo'] !== null ? ': ' . $e['memo'] : ''), 'entry_date' => $e['entry_date'], 'department' => $e['department_id'], 'site' => $e['site_id'], 'lines' => $rows];
}

/** The periods a filter select offers: [{fiscal_period_id, name, status}], newest first. */
function periods_for_select(PDO $pdo): array
{
    return array_map(static fn (array $p): array => ['fiscal_period_id' => (int) $p['fiscal_period_id'], 'name' => $p['name'], 'status' => $p['status']],
        $pdo->query('SELECT fiscal_period_id, name, status FROM mcp_fiscal_periods ORDER BY start_date DESC')->fetchAll());
}
