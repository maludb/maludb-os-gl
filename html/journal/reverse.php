<?php
declare(strict_types=1);
/**
 * Action `journal_entry_reverse` (`entry` a posted one, `reason`, reverse_date; confirm; money_out for an agent; log `journal_entry.reverse` on the original with reversal_id, reversal_number, reason, amount):
 * journal.write on one's own, journal.override on any. A document-born entry is reversed from its document (its void). gl_entry_reverse() is the referee.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/present.php';
gl_handler_begin();
require_any_right('journal.write|journal.override');
$pdo = db();
$e = load_entry($pdo);
$id = $e['journal_entry_id'];
if (!may_reverse($e)) {
    refuse(403, 'You may not reverse, void or write off.');
}
if ($e['has_document']) {
    refuse(422, 'Reverse it from the ' . record_words((string) $e['source_type']) . ' (void it) so the document and the books agree.');
}
$reason = mb_substr((string) req_val('reason'), 0, 500);
$date = request_date('reverse_date') ?? request_date('date');
if ($date === false) {
    gl_refuse_fields(['reverse_date' => 'The date is YYYY-MM-DD.']);
}
if ($date === null && $e['period_status'] === 'closed') {
    $date = date('Y-m-d');                           // the original's period is closed: the reversal lands today (an open period)
}
try {
    $pdo->beginTransaction();
    $r = reverse_entry($pdo, $id, (int) current_member_id(), $reason, $date);
    log_activity($pdo, 'journal_entry.reverse', 'journal_entry', $id, ['journal_entry_id' => $id, 'period_id' => $r['period_id'],
        'after' => ['reversal_id' => $r['journal_entry_id'], 'reversal_number' => $r['number'], 'reason' => $reason, 'amount' => $r['total'], 'currency' => $r['currency'], 'reverse_date' => $r['entry_date'], 'number' => $e['number']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    entry_refused($pdo, $ex);
}
gl_done('Reversed ' . $e['number'] . ' with ' . $r['number'] . ' (' . number_format((float) $r['total'], 2) . ' ' . $r['currency'] . ')', $r['journal_entry_id'], gl_land(return_path('/journal/' . $r['journal_entry_id']), 'reversed'), 'journalEntryChanged',
    ['journal_entry_id' => $r['journal_entry_id'], 'reversal_number' => $r['number'], 'reversed_entry_id' => $id]);
