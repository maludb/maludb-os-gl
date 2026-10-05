<?php
declare(strict_types=1);
/** Action `journal_entries_post_batch` (`entries`: ids, each in its own savepoint; confirm; money_out for an agent; log `journal_entry.post_batch` with posted and refused[]): journal.write. 200 even when some refused. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/present.php';
gl_handler_begin();
require_right('journal.write');
$pdo = db();
$ids = array_values(array_unique(array_map('intval', array_filter(request_list('entries') ?? [], static fn (string $v): bool => ctype_digit($v)))));
if ($ids === []) {
    refuse(422, 'Say which entries (ids, comma-separated or entries[]).');
}
foreach ($ids as $id) {
    require_visible($pdo, 'mcp_journal_entries', 'journal_entry_id', $id, 'Entry');
}
try {
    $pdo->beginTransaction();
    $r = post_entries($pdo, $ids, (int) current_member_id());
    foreach ($r['posted'] as $p) {
        log_activity($pdo, 'journal_entry.post', 'journal_entry', $p['journal_entry_id'], ['journal_entry_id' => $p['journal_entry_id'], 'period_id' => $p['period_id'], 'after' => ['number' => $p['number'], 'amount' => $p['total'], 'currency' => $p['currency'], 'via' => 'post_batch']]);
    }
    log_activity($pdo, 'journal_entry.post_batch', 'journal', null, ['after' => ['entries' => $ids, 'posted' => array_column($r['posted'], 'number'), 'refused' => $r['refused']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    entry_refused($pdo, $ex);
}
$did = 'Posted ' . count($r['posted']) . ' of ' . count($ids) . ($r['refused'] !== [] ? '; ' . count($r['refused']) . ' refused: ' . implode(' · ', array_map(static fn (array $x): string => '#' . $x['journal_entry_id'] . ' ' . $x['reason'], $r['refused'])) : '');
if (!wants_json() && $r['refused'] !== []) {
    hx_trigger('journalEntryChanged');
    emit_action_status(true, ['did' => $did, 'record_id' => null, 'posted' => $r['posted'], 'refused' => $r['refused']]);
    http_response_code(200);
    header('HX-Retarget: #flash');
    header('HX-Reswap: innerHTML');
    echo view('shared/flash.php', ['kind' => 'warning', 'message' => $did]);
    exit;
}
gl_done($did, null, gl_land(return_path('/journal/'), 'posted_batch'), 'journalEntryChanged', ['posted' => $r['posted'], 'refused' => $r['refused']]);
