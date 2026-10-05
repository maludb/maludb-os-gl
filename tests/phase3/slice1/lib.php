<?php
/**
 * Helpers for the slice proofs (docs/build-specs/chart-fiscal.md, "Proof"). Run through tests/phase3/slice1/run.sh: a fresh SCRATCH database, the
 * application on :8391, a fake kernel and a fake MaluDB. Everything a proof makes is named "SMOKE …". Builds on tests/phase2/lib.php.
 * The cast: Vera 26 (Viewer), Bea 27 (Bookkeeper), Carl 28 (Controller), Ann 29 (Accountant, external), the owner 1 (admin), the Bookkeeper agent 40.
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

const JSONH = ['Accept: application/json'];

/** A signed-on jar for a fixture member (cached per proof). */
function as_member(int $m): string
{
    static $jars = [];
    if (isset($jars[$m])) { return $jars[$m]; }
    [$j, $r] = sign_on($m);
    if ($r['code'] !== 302) { fwrite(STDERR, "sign-on of $m failed: {$r['code']}\n"); }
    return $jars[$m] = $j;
}
/** A write over HTTP as a signed-on person, answered as JSON: [status, decoded body, raw]. */
function act(string $jar, string $path, array $form, array $extraHeaders = []): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $r = req('POST', $path, ['jar' => $jar, 'headers' => array_merge(JSONH, $extraHeaders), 'form' => $form + ['csrf_token' => $tok[$jar]]]);
    return [$r['code'], json_decode($r['body'], true) ?? [], $r];
}
/** The same write as the browser sends it (a form POST, HTML back): [status, raw response]. */
function act_html(string $jar, string $path, array $form): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $r = req('POST', $path, ['jar' => $jar, 'form' => $form + ['csrf_token' => $tok[$jar]]]);
    return [$r['code'], $r];
}
/** A screen as JSON (data only): [status, data, raw]. */
function screen(string $jar, string $path): array { $r = req('GET', $path, ['jar' => $jar, 'headers' => JSONH]); return [$r['code'], json_decode($r['body'], true)['data'] ?? [], $r]; }
function msg(array $body): string { return (string) ($body['error']['message'] ?? ''); }
function fields(array $body): array { return (array) ($body['error']['fields'] ?? []); }
function person_token(int $m, int $ttl = 300): string { $key = need('ACTION_TOKEN_KEY'); $p = $m . '.' . (time() + $ttl); return $p . '.' . hash_hmac('sha256', $p, $key); }
function run_token(int $m, int $runId): array { $key = need('ACTION_TOKEN_KEY'); $p = $m . '.' . (time() + 300) . '.' . $runId; $t = $p . '.' . hash_hmac('sha256', 'run:' . $p, $key); return [$t, hash_hmac('sha256', $t, need('ACTIONS_RELAY_KEY'))]; }
function act_token(string $path, array $form, array $headers): array { $r = req('POST', $path, ['headers' => array_merge(JSONH, $headers), 'form' => $form]); return [(int) $r['code'], json_decode($r['body'], true) ?? [], $r]; }

function account_id(string $code): int { return (int) one('SELECT id FROM accounts WHERE code = :c', ['c' => $code]); }
/** The balance the database says (as the owner, reports.view). */
function balance_of(string $code, ?string $asOf = null): string { pdo()->exec("SELECT set_config('app.member_id', '1', false)"); return (string) one('SELECT gl_account_balance(:a, :d)', ['a' => account_id($code), 'd' => $asOf ?? date('Y-m-d')]); }
/** A posted journal entry by SQL (slice 2 brings the screens): [[code, debit, credit], …] on a date. Answers its id. */
function post_entry(string $date, array $lines, string $memo = 'SMOKE entry'): int
{
    pdo()->beginTransaction();                 // a refused post leaves no draft behind
    try {
        $id = (int) one('INSERT INTO journal_entries (entry_date, memo, created_by) VALUES (:d, :m, 1) RETURNING id', ['d' => $date, 'm' => $memo]);
        $n = 0;
        foreach ($lines as [$code, $dr, $cr]) {
            $n++;
            q('INSERT INTO journal_lines (entry_id, line_no, account_id, debit, credit) VALUES (:e, :n, :a, :d, :c)', ['e' => $id, 'n' => $n, 'a' => account_id($code), 'd' => $dr, 'c' => $cr]);
        }
        one('SELECT gl_entry_post(:e, 1)', ['e' => $id]);
        pdo()->commit();
        return $id;
    } catch (Throwable $e) {
        pdo()->rollBack();
        throw $e;
    }
}
function fy_id(string $name = 'FY2026'): int { return (int) one('SELECT id FROM fiscal_years WHERE name = :n', ['n' => $name]); }
function period_id(string $name): int { return (int) one('SELECT id FROM fiscal_periods WHERE name = :n', ['n' => $name]); }
