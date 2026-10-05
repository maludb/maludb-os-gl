<?php
/**
 * Helpers for the slice 8 proofs (docs/build-specs/recurring-worker.md, "Proof"): the fake MaluMail logs every send to $FAKE_MALUMAIL_LOG (one JSON line each), the fake kernel logs every text to "<state>.sms";
 * worker() runs `php bin/worker.php` (GL_WORKER_NOW fakes its clock) and answers the decoded report.
 */
require dirname(__DIR__) . '/slice7/lib.php';

function worker(array $args = [], ?string $now = null): array
{
    $env = ($now === null ? '' : 'GL_WORKER_NOW=' . escapeshellarg($now) . ' ');
    $out = (string) shell_exec($env . 'php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1');
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => $out];
}
function mail_log(): array { $f = (string) getenv('FAKE_MALUMAIL_LOG'); return array_values(array_filter(array_map(fn ($l) => json_decode($l, true), is_file($f) ? file($f, FILE_IGNORE_NEW_LINES) : []))); }
function sms_log(): array { $f = (string) getenv('FAKE_KERNEL_STATE') . '.sms'; return array_values(array_filter(array_map(fn ($l) => json_decode($l, true), is_file($f) ? file($f, FILE_IGNORE_NEW_LINES) : []))); }
function sms_mode(string $mode, array $members = []): void { kernel_state(function (array $st) use ($mode, $members) { $st['sms'] = ['mode' => $mode, 'members' => $members]; return $st; }); }
function outbox(string $where = 'true', array $args = []): array { return q('SELECT * FROM notification_outbox WHERE ' . $where . ' ORDER BY id', $args); }
function tpl_row(int $id): array { return q('SELECT * FROM recurring_templates WHERE id = :i', ['i' => $id])[0] ?? []; }
/** Save a template as the Bookkeeper's administrator (the owner); answers [code, body, id]. */
function mk_template(string $kind, string $name, array $template, array $extra = []): array
{
    [$c, $b] = act(as_member(1), '/settings/recurring/save.php', $extra + ['name' => $name, 'kind' => $kind, 'cadence' => 'monthly', 'next_date' => '2026-11-01', 'template' => json_encode($template)]);
    return [$c, $b, (int) ($b['record_id'] ?? 0)];
}
