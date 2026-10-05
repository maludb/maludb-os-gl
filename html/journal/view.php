<?php
declare(strict_types=1);
/** /journal/{id} — one entry: head, lines (the editor for a draft), the source document, attachments, notes, the timeline, the actions by status (screen `journal-entry-view`; documents.view). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/present.php';
require_once dirname(__DIR__, 2) . '/app/features/records/attachments.php';
require_once dirname(__DIR__, 2) . '/app/features/records/notes.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/views/shared/account-select.php';
require_once dirname(__DIR__, 2) . '/app/views/shared/party-select.php';
require_right('documents.view');
$pdo = db();
$e = load_entry($pdo);
$id = $e['journal_entry_id'];
$balance = entry_balance($pdo, $id);
$attachments = find_attachments($pdo, 'journal_entry', $id);
$notes = find_notes($pdo, 'journal_entry', $id);
$timeline = find_record_activity($pdo, 'journal_entry', $id);
$may = ['write' => has_right('journal.write'), 'override' => has_right('journal.override'), 'reverse' => may_reverse($e), 'attach' => has_right('attachments.write'), 'delete' => has_right('journal.override'), 'note' => has_right('documents.view')];
log_screen_view($pdo, 'journal-entry-view', ['after' => ['record_type' => 'journal_entry', 'record_id' => $id]]);
if (wants_json()) {
    respond_screen(['entry' => present_entry($e), 'balance' => $balance, 'attachments' => array_map('present_attachment', $attachments), 'notes' => array_map('present_note', $notes),
        'timeline' => array_map(static fn (array $r): array => ['activity_id' => (int) $r['activity_id'], 'at' => json_ts($r['occurred_at']), 'action' => $r['action'], 'sentence' => activity_sentence($r)], $timeline), 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved the draft.'], 'posted' => ['success', 'Posted ' . ($e['number'] ?? 'the entry') . '.'], 'reversed' => ['success', 'This is the reversal; the original is marked reversed.'], 'line_saved' => ['success', 'Saved the line.'], 'line_removed' => ['success', 'Removed the line.'],
            'attached' => ['success', 'Attached.'], 'attachment_removed' => ['success', 'The attachment was removed.'], 'noted' => ['success', 'Added the note.'], 'voided' => ['warning', 'This draft is void.']];
$data = ['e' => $e, 'balance' => $balance, 'attachments' => $attachments, 'notes' => $notes, 'timeline' => $timeline, 'may' => $may, 'pdo' => $pdo, 'departments' => find_live_departments($pdo), 'sites' => find_live_sites($pdo),
         'taxRates' => find_tax_rates_live($pdo), 'here' => here_url(), 'tz' => member_timezone(), 'notice' => gl_notice($_GET['notice'] ?? null, $notices)];
if (is_htmx_request() && !is_htmx_boosted() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'entry-lines') {
    answer_lines_region($pdo, $e);
}
render_screen(($e['number'] ?? 'Draft entry') . ($e['memo'] !== null ? ' — ' . $e['memo'] : ''), view('journal/view.php', $data), ['activeNav' => 'journal', 'screen' => 'journal-entry-view', 'entity' => 'journalEntry', 'recordId' => (string) $id]);
