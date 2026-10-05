<?php
declare(strict_types=1);

/** The AI statement (slice 7): the ledger's pulled months through mcp_ai_statements / _lines / _bills, merged with the kernel's list; the lines grouped; the check. Money is a numeric string (amounts keep four decimals). */

const AI_STATUS_BADGE = ['pulled' => 'secondary', 'imported' => 'info', 'agrees' => 'success', 'differs' => 'danger'];
/** A provider key as a person writes it: anthropic → Anthropic, openai → OpenAI, google → Google, else the first letter capital. */
function ai_provider_label(string $key): string
{
    return ['anthropic' => 'Anthropic', 'openai' => 'OpenAI', 'google' => 'Google', 'mistral' => 'Mistral', 'xai' => 'xAI'][strtolower($key)] ?? ucfirst($key);
}

const AI_GROUPS = ['provider' => 'Provider', 'department' => 'Department', 'agent' => 'Agent', 'model' => 'Model'];

function ai_cast(array $s): array
{
    foreach (['ai_statement_id', 'imported_by', 'late_calls', 'line_count', 'bill_count'] as $k) {
        $s[$k] = ($s[$k] ?? null) === null ? null : (int) $s[$k];
    }
    $s['totals'] = is_string($s['totals'] ?? null) ? (json_decode($s['totals'], true) ?: []) : ($s['totals'] ?? []);
    return $s;
}

/** Each pulled month with its bills (number, status, total, provider); newest first. */
function find_pulled_statements(PDO $pdo): array
{
    $rows = array_map('ai_cast', $pdo->query('SELECT * FROM mcp_ai_statements ORDER BY period DESC')->fetchAll());
    $bills = $pdo->query('SELECT ai_statement_id, provider, currency, bill_id, bill_number, bill_status, bill_total FROM mcp_ai_statement_bills ORDER BY ai_statement_id, provider')->fetchAll();
    $by = [];
    foreach ($bills as $b) {
        $by[(int) $b['ai_statement_id']][] = ['provider' => $b['provider'], 'currency' => trim((string) $b['currency']), 'bill_id' => (int) $b['bill_id'], 'number' => $b['bill_number'], 'status' => $b['bill_status'], 'total' => $b['bill_total']];
    }
    foreach ($rows as &$r) {
        $r['bills'] = $by[$r['ai_statement_id']] ?? [];
    }
    return $rows;
}

/** The ledger's months merged with the kernel's list: pulled months first-class, months the kernel lists but the ledger has not pulled as {pulled: false, period, kernel_status}. $kernel null = the kernel was not asked or did not answer. */
function find_ai_statements(PDO $pdo, ?array $kernel = null): array
{
    $pulled = find_pulled_statements($pdo);
    $have = array_column($pulled, 'period');
    $out = array_map(static fn (array $s): array => $s + ['pulled' => true, 'kernel_listed' => null], $pulled);
    foreach ($kernel ?? [] as $k) {
        if (!in_array($k['period'], $have, true)) {
            $out[] = ['pulled' => false, 'period' => $k['period'], 'period_start' => $k['period_start'] ?? null, 'period_end' => $k['period_end'] ?? null, 'kernel_status' => (string) ($k['status'] ?? 'open'), 'statement_lines' => (int) ($k['statement_lines'] ?? 0), 'ledger_calls' => (int) ($k['ledger_calls'] ?? 0)];
        }
    }
    usort($out, static fn (array $a, array $b): int => strcmp((string) $b['period'], (string) $a['period']));
    return $out;
}

function find_ai_statement(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_ai_statements WHERE ai_statement_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $s = ai_cast($r);
    $d = $pdo->prepare('SELECT document FROM ai_statements WHERE id = :id');
    $d->execute(['id' => $id]);
    $doc = json_decode((string) $d->fetchColumn(), true) ?: [];
    $s['header'] = ['business' => $doc['business'] ?? null, 'generated_at' => $doc['generated_at'] ?? null, 'closed_at' => $doc['closed_at'] ?? null, 'exchange' => $doc['exchange'] ?? null, 'currencies' => $doc['currencies'] ?? array_keys($s['totals']),
                    'schema' => $doc['schema'] ?? null, 'amount_scale' => $doc['amount_scale'] ?? 4, 'document_totals' => $doc['totals'] ?? []];
    $s['bills'] = [];
    foreach (find_pulled_statements($pdo) as $x) {
        if ($x['ai_statement_id'] === $id) {
            $s['bills'] = $x['bills'];
        }
    }
    return $s;
}

function ai_statement_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS ai_statement_id, period, kernel_status, status, document_hash FROM ai_statements WHERE id = :id');
    $st->execute(['id' => $id]);
    return $st->fetch() ?: null;
}

/** The lines grouped by provider, department, agent or model, per currency: key, currency, calls, late_calls, tokens, amount (four decimals); then a total per currency. */
function ai_statement_lines(PDO $pdo, int $id, string $groupBy = 'provider'): array
{
    $col = match ($groupBy) { 'department' => "COALESCE(department, 'No department')", 'agent' => "COALESCE(agent, '—')", 'model' => "COALESCE(model, '—')", default => 'provider' };
    $st = $pdo->prepare("SELECT {$col} AS grp, currency, sum(calls) AS calls, sum(late_calls) AS late_calls, sum(input_tokens) AS input_tokens, sum(output_tokens) AS output_tokens, sum(cache_read_tokens + cache_write_tokens) AS cache_tokens, sum(amount) AS amount
                           FROM mcp_ai_statement_lines WHERE ai_statement_id = :id GROUP BY 1, currency ORDER BY currency, sum(amount) DESC, 1");
    $st->execute(['id' => $id]);
    $rows = array_map(static function (array $r): array {
        $r['currency'] = trim((string) $r['currency']);
        foreach (['calls', 'late_calls', 'input_tokens', 'output_tokens', 'cache_tokens'] as $k) { $r[$k] = (int) $r[$k]; }
        return $r;
    }, $st->fetchAll());
    $tot = [];
    foreach ($rows as $r) {
        $t = &$tot[$r['currency']];
        $t = $t ?? ['currency' => $r['currency'], 'calls' => 0, 'late_calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cache_tokens' => 0, 'amount' => '0.0000'];
        foreach (['calls', 'late_calls', 'input_tokens', 'output_tokens', 'cache_tokens'] as $k) { $t[$k] += $r[$k]; }
        $t['amount'] = ai_add4($t['amount'], (string) $r['amount']);
        unset($t);
    }
    return ['rows' => $rows, 'totals' => array_values($tot), 'group_by' => isset(AI_GROUPS[$groupBy]) ? $groupBy : 'provider'];
}

/** Exact four-decimal addition of two non-negative numeric strings (no bcmath). */
function ai_add4(string $a, string $b): string
{
    $to = static fn (string $v): int => (int) round((float) $v * 10000);
    $c = $to($a) + $to($b);
    return sprintf('%d.%04d', intdiv($c, 10000), $c % 10000);
}

/** The check: provider by provider, does the bill agree with the statement to the cent? gl_ai_statement_check() — a pure reader. */
function ai_check_rows(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM gl_ai_statement_check(:id)');
    $st->execute(['id' => $id]);
    return array_map(static function (array $r): array {
        $r['currency'] = trim((string) $r['currency']);
        $r['bill_id'] = $r['bill_id'] === null ? null : (int) $r['bill_id'];
        $r['agrees'] = (bool) $r['agrees'];
        return $r;
    }, $st->fetchAll());
}
