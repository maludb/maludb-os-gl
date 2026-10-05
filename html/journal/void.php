<?php
declare(strict_types=1);
/** Action `journal_entry_void` (`entry` a draft, reason; confirm; deletion for an agent; log `journal_entry.void`): journal.write. The guard refuses a posted entry ("reverse it instead"). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/present.php';
gl_handler_begin();
require_right('journal.write');
$pdo = db();
$e = load_entry($pdo);
$id = $e['journal_entry_id'];
$reason = (string) req_val('reason') === '' ? null : mb_substr((string) req_val('reason'), 0, 500);
try {
    $pdo->beginTransaction();
    $v = void_entry($pdo, $id, (int) current_member_id(), $reason);
    log_activity($pdo, 'journal_entry.void', 'journal_entry', $id, ['journal_entry_id' => $id, 'after' => ['status' => 'void', 'reason' => $reason, 'memo' => $e['memo']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    entry_refused($pdo, $ex);
}
gl_done('Voided draft #' . $id, $id, gl_land(return_path('/journal/' . $id), 'voided'), 'journalEntryChanged', ['journal_entry_id' => $id]);
