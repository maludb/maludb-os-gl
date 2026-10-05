<?php
declare(strict_types=1);
/** /ap/ai/{id} — one month: the document's header facts, the lines grouped by provider (default), department, agent or model (`?group_by=`), the bills panel with the check, Import (a closed month not yet imported), Check (an imported one), attachments, notes, the timeline (screen `ai-statement-view`; reports.view). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/ai/handler.php';
require_right('reports.view');
$pdo = db();
$id = req_id('id', 'ai_statement') ?? refuse(404, 'AI statement not found.');
$s = find_ai_statement($pdo, $id) ?? refuse(404, 'AI statement not found.');
$group = request_string('group_by');
$group = isset(AI_GROUPS[$group]) ? $group : 'provider';
$lines = ai_statement_lines($pdo, $id, $group);
$check = $s['status'] === 'pulled' ? [] : ai_check_rows($pdo, $id);
$attachments = has_right('documents.view') ? find_attachments($pdo, 'ai_statement', $id) : [];
$notes = has_right('documents.view') ? find_notes($pdo, 'ai_statement', $id) : [];
$timeline = find_record_activity($pdo, 'ai_statement', $id);
$may = ['import' => has_right('ap.write') && $s['kernel_status'] === 'closed' && $s['status'] === 'pulled', 'check' => has_right('reports.view') && $s['status'] !== 'pulled', 'pull' => has_right('ap.write'), 'write' => has_right('ap.write'), 'attach' => has_right('attachments.write'), 'delete' => false, 'note' => has_right('documents.view')];
log_screen_view($pdo, 'ai-statement-view', ['after' => ['record_type' => 'ai_statement', 'record_id' => $id]]);
if (wants_json()) {
    respond_screen(['statement' => present_ai_statement_row($s + ['pulled' => true]) + ['header' => $s['header']], 'group_by' => $lines['group_by'], 'lines' => array_map(static fn (array $r): array => ['group' => $r['grp'], 'currency' => $r['currency'], 'calls' => $r['calls'], 'late_calls' => $r['late_calls'], 'input_tokens' => $r['input_tokens'], 'output_tokens' => $r['output_tokens'], 'amount' => $r['amount']], $lines['rows']),
        'totals' => $lines['totals'], 'check' => array_map(static fn (array $c): array => ['provider' => $c['provider'], 'currency' => $c['currency'], 'statement_amount' => $c['statement_amount'], 'bills_amount' => $c['bills_amount'], 'difference' => $c['difference'], 'bill_id' => $c['bill_id'], 'bill_number' => $c['bill_number'], 'bill_status' => $c['bill_status'], 'agrees' => $c['agrees'],
        'sentence' => $c['agrees'] ? null : ai_difference_sentence($c, $s['period'])], $check), 'attachments' => array_map('present_attachment', $attachments), 'notes' => array_map('present_note', $notes), 'may' => $may]);
}
$notices = ['imported' => ['success', 'Imported: the bills are drafts; the controller approves them.'], 'agrees' => ['success', 'The bills agree with the statement, to the cent.'], 'differs' => ['danger', 'The bills differ from the statement; see the check below.'], 'pulled' => ['success', 'Pulled from the OS.'],
            'attached' => ['success', 'Attached.'], 'noted' => ['success', 'Added the note.'], 'attachment_removed' => ['success', 'The attachment was removed.']];
render_screen('AI usage ' . $s['period'], view('ai/view.php', ['s' => $s, 'lines' => $lines, 'check' => $check, 'attachments' => $attachments, 'notes' => $notes, 'timeline' => $timeline, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(),
    'notice' => gl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'ai', 'screen' => 'ai-statement-view', 'entity' => 'aiStatement', 'recordId' => (string) $id]);
