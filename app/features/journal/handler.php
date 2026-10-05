<?php
declare(strict_types=1);

/**
 * The journal handlers' shared prelude (slice 2): load the entry the request names (404 in one sentence), the right, and entry_refused() — a
 * P0001 from the referee becomes the 422 in the database's words; when the sentence names a line's account, as that field's error too.
 */
require_once dirname(__DIR__, 2) . '/features/records/views.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';
require_once dirname(__DIR__, 2) . '/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/views/shared/account-select.php';
require_once dirname(__DIR__, 2) . '/views/shared/party-select.php';

/** The entry named by `entry` (or `entry_id`, or the route's `id`), visible through its view; else 404 "Entry not found.". */
function load_entry(PDO $pdo, bool $required = true): ?array
{
    $id = request_integer('entry') ?? request_integer('entry_id') ?? request_integer('id');
    if ($id === null) {
        if ($required) {
            refuse(422, 'Say which entry.');
        }
        return null;
    }
    require_visible($pdo, 'mcp_journal_entries', 'journal_entry_id', $id, 'Entry');
    return find_entry($pdo, $id) ?? refuse(404, 'Entry not found.');
}

/** A refusal from the referee or our own sentence, as the 422 (a "Not found." as 404). */
function entry_refused(PDO $pdo, Throwable $e): never
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof DomainException) {
        $m = $e->getMessage();
        if (preg_match('/^Row (\d+): (.+)$/', $m, $mm)) {
            emit_action_status(false, ['errors' => [$m], 'fields' => ['lines' => $m, 'row' => (int) $mm[1]]]);
            if (wants_json()) {
                respond_invalid([$m], ['lines' => $m, 'row' => (int) $mm[1]]);
            }
        }
        refuse($m === 'Not found.' ? 404 : 422, $m);
    }
    if ($e instanceof PDOException && (string) $e->getCode() === 'P0001') {
        $m = db_message($e, 'That could not be done.');
        $fields = [];
        if (preg_match('/^Account (\S+) /', $m, $mm)) {
            $fields['account'] = $m;
        }
        emit_action_status(false, ['errors' => [$m], 'fields' => $fields]);
        if (wants_json()) {
            respond_invalid([$m], $fields);
        }
        refuse(422, $m);
    }
    if ($e instanceof PDOException && (string) $e->getCode() === '23514') {
        refuse(422, 'A line is a debit or a credit.');
    }
    throw $e;
}

/** The lines rows an agent sends as JSON (`lines`), or a browser form sends as parallel arrays (line_account[] …). Null when neither came. */
function request_line_rows(): ?array
{
    if (req_has('lines')) {
        $raw = $_POST['lines'];
        if (is_string($raw)) {
            $rows = json_decode($raw, true);
            if (!is_array($rows)) {
                refuse(422, 'lines is a JSON list of rows (account, debit or credit, description…).');
            }
            return array_values($rows);
        }
        return is_array($raw) ? array_values($raw) : null;
    }
    if (isset($_POST['line_account']) && is_array($_POST['line_account'])) {
        $rows = [];
        foreach ($_POST['line_account'] as $i => $acct) {
            $row = ['account' => $acct, 'description' => $_POST['line_description'][$i] ?? null, 'debit' => $_POST['line_debit'][$i] ?? null, 'credit' => $_POST['line_credit'][$i] ?? null,
                    'customer' => $_POST['line_customer'][$i] ?? null, 'vendor' => $_POST['line_vendor'][$i] ?? null];
            if (trim((string) $acct) === '' && trim((string) $row['debit']) === '' && trim((string) $row['credit']) === '') {
                continue;                                          // a blank row of the form
            }
            $rows[] = $row;
        }
        return $rows;
    }
    return null;
}

/** May the member reverse this entry? journal.write for one's own, journal.override for any. */
function may_reverse(array $e): bool
{
    return has_right('journal.override') || (has_right('journal.write') && $e['created_by'] === (int) current_member_id());
}

/** The HTMX answer of a line write: the lines region re-rendered and the strip out of band (the whole region and the strip change together). */
function answer_lines_region(PDO $pdo, array $e): never
{
    hx_trigger('journalEntryChanged');
    $e = find_entry($pdo, $e['journal_entry_id']);
    $balance = entry_balance($pdo, $e['journal_entry_id']);
    echo view('journal/partials/lines.php', ['e' => $e, 'balance' => $balance, 'pdo' => $pdo, 'may' => ['write' => has_right('journal.write')], 'departments' => find_live_departments($pdo), 'sites' => find_live_sites($pdo), 'taxRates' => find_tax_rates_live($pdo)]);
    echo view('journal/partials/balance-strip.php', ['e' => $e, 'balance' => $balance, 'oob' => true, 'may' => ['write' => has_right('journal.write')]]);
    exit;
}

/** The live tax rates for a line's select (the settings' find_tax_rates lives in settings/ledger.php; this one is the journal's own small read). */
function find_tax_rates_live(PDO $pdo): array
{
    return array_map(static fn (array $t): array => ['tax_rate_id' => (int) $t['tax_rate_id'], 'name' => $t['name']], $pdo->query('SELECT tax_rate_id, name FROM mcp_tax_rates WHERE archived_at IS NULL ORDER BY is_default DESC, lower(name)')->fetchAll());
}
