<?php
declare(strict_types=1);
/** Action `journal_line_remove` (`entry` a draft, `line`; log `journal_entry.line_remove`): journal.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/present.php';
gl_handler_begin();
require_right('journal.write');
$pdo = db();
$e = load_entry($pdo);
$id = $e['journal_entry_id'];
$lineId = request_integer('line') ?? request_integer('line_id') ?? refuse(422, 'Say which line.');
$gone = null;
foreach ($e['lines'] as $l) {
    if ($l['journal_line_id'] === $lineId) {
        $gone = $l;
    }
}
if ($gone === null) {
    refuse(404, 'Line not found.');
}
try {
    $pdo->beginTransaction();
    remove_line($pdo, $id, $lineId);
    log_activity($pdo, 'journal_entry.line_remove', 'journal_entry', $id, ['journal_entry_id' => $id, 'before' => ['line_no' => $gone['line_no'], 'account_code' => $gone['account_code'], 'debit' => $gone['debit'], 'credit' => $gone['credit']],
        'after' => ['line_no' => $gone['line_no'], 'account_code' => $gone['account_code'], 'account_name' => $gone['account_name'], 'debit' => $gone['debit'], 'credit' => $gone['credit'], 'removed' => true]]);
    $pdo->commit();
} catch (Throwable $ex) {
    entry_refused($pdo, $ex);
}
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'entry-lines') {
    emit_action_status(true, ['did' => 'Removed the line', 'record_id' => $lineId]);
    answer_lines_region($pdo, $e);
}
gl_done('Removed line ' . $gone['line_no'] . ' on ' . $gone['account_code'], $lineId, gl_land(return_path('/journal/' . $id), 'line_removed', 'entry-lines'), 'journalEntryChanged', ['journal_entry_id' => $id, 'balance' => entry_balance($pdo, $id)]);
