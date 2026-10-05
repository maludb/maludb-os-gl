<?php
declare(strict_types=1);
/** Action `recurring_run` (template, run_date — default its next date; log `recurring.run` with record_type, record_id, run_date): journal.write. Drafts the document dated for the run date — a DRAFT, for a person to post, approve or issue — and advances the schedule; a failure is recorded as a failed run with its sentence (422) and advances nothing. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/recurring/handler.php';
gl_handler_begin();
require_right('journal.write');
$pdo = db();
$id = req_id('template', 'recurring_template', 'template_id') ?? refuse(422, 'Say which template.');
require_visible($pdo, 'mcp_recurring_templates', 'recurring_template_id', $id, 'Template');
$date = request_date('run_date');
if ($date === false) {
    gl_refuse_fields(['run_date' => 'The run date is YYYY-MM-DD.']);
}
try {
    $r = run_template($pdo, $id, $date, (int) current_member_id(), 'web');
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
if ($r['status'] === 'failed') {
    emit_action_status(false, ['errors' => [$r['detail']], 'fields' => ['template' => $r['detail']]]);
    if (wants_json()) {
        respond_invalid([$r['detail']], ['template' => $r['detail'], 'run_date' => $r['run_date']]);
    }
    refuse(422, $r['detail']);
}
gl_done('Drafted a ' . str_replace('_', ' ', $r['record_type']) . ' for ' . $r['run_date'], $r['record_id'], gl_land(return_path(record_url($r['record_type'], $r['record_id'])), 'saved'), 'recurringChanged', $r);
