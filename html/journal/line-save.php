<?php
declare(strict_types=1);
/** Action `journal_line_save` (`entry` a draft; `line` present = change; log `journal_entry.line_save` with the account code and the amounts, before of a changed line): journal.write. The immutability trigger refuses a non-draft. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/present.php';
gl_handler_begin();
require_right('journal.write');
$pdo = db();
$e = load_entry($pdo);
$id = $e['journal_entry_id'];
$lineId = request_integer('line') ?? request_integer('line_id');
$before = null;
if ($lineId !== null) {
    foreach ($e['lines'] as $l) {
        if ($l['journal_line_id'] === $lineId) {
            $before = $l;
        }
    }
    if ($before === null) {
        refuse(404, 'Line not found.');
    }
}
$row = ['account' => $_POST['account'] ?? $_POST['account_id'] ?? ($before['account_id'] ?? ''), 'description' => $_POST['description'] ?? ($before['description'] ?? null),
        'debit' => $_POST['debit'] ?? null, 'credit' => $_POST['credit'] ?? null, 'department' => $_POST['department'] ?? $_POST['department_id'] ?? ($before['department_id'] ?? $e['department_id']),
        'site' => $_POST['site'] ?? $_POST['site_id'] ?? ($before['site_id'] ?? $e['site_id']), 'customer' => $_POST['customer'] ?? $_POST['customer_id'] ?? ($before['customer_id'] ?? null),
        'vendor' => $_POST['vendor'] ?? $_POST['vendor_id'] ?? ($before['vendor_id'] ?? null), 'tax_rate' => $_POST['tax_rate'] ?? $_POST['tax_rate_id'] ?? ($before['tax_rate_id'] ?? null)];
if ($before !== null && !req_has('debit') && !req_has('credit')) {
    $row['debit'] = $before['debit'];
    $row['credit'] = $before['credit'];
}
try {
    $f = normalize_line($pdo, $row, $before['line_no'] ?? count($e['lines']) + 1);
} catch (DomainException $ex) {
    $m = preg_replace('/^Row \d+: /', '', $ex->getMessage());
    $m = ucfirst($m);
    gl_refuse_fields([$m === 'A line is a debit or a credit.' ? 'debit' : 'account' => $m]);
}
try {
    $pdo->beginTransaction();
    $newLine = save_line($pdo, $id, $lineId, $f);
    $after = null;
    foreach (entry_lines($pdo, $id) as $l) {
        if ($l['journal_line_id'] === $newLine) {
            $after = $l;
        }
    }
    $pack = static fn (array $l): array => ['line_no' => $l['line_no'], 'account_code' => $l['account_code'], 'account_name' => $l['account_name'], 'debit' => $l['debit'], 'credit' => $l['credit'], 'description' => $l['description'], 'customer_id' => $l['customer_id'], 'vendor_id' => $l['vendor_id']];
    log_activity($pdo, 'journal_entry.line_save', 'journal_entry', $id, ['journal_entry_id' => $id, 'before' => $before === null ? null : $pack($before), 'after' => $pack($after) + ['currency' => $e['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    entry_refused($pdo, $ex);
}
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'entry-lines') {
    emit_action_status(true, ['did' => 'Saved the line', 'record_id' => $newLine]);
    answer_lines_region($pdo, $e);
}
gl_done(($lineId === null ? 'Added line ' : 'Saved line ') . $after['line_no'] . ' on ' . $after['account_code'], $newLine, gl_land(return_path('/journal/' . $id), 'line_saved', 'entry-lines'), 'journalEntryChanged', ['journal_entry_id' => $id, 'journal_line_id' => $newLine, 'balance' => entry_balance($pdo, $id)]);
