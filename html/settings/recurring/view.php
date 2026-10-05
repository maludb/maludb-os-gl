<?php
declare(strict_types=1);
/** /settings/recurring/{id} — one template: the document it drafts read-only, its runs (date, what it drafted with a link, status), Run now (screen `recurring-view`; settings.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/recurring/handler.php';
require_right('settings.manage');
$pdo = db();
$id = req_id('id', 'template') ?? refuse(404, 'Template not found.');
$t = find_template($pdo, $id) ?? refuse(404, 'Template not found.');
$timeline = find_record_activity($pdo, 'recurring_template', $id);
$may = ['run' => has_right('journal.write'), 'write' => true];
log_screen_view($pdo, 'recurring-view', ['after' => ['record_type' => 'recurring_template', 'record_id' => $id]]);
if (wants_json()) {
    respond_screen(['template' => present_template($t), 'may' => $may]);
}
render_screen($t['name'], view('settings/recurring-view.php', ['t' => $t, 'timeline' => $timeline, 'may' => $may, 'pdo' => $pdo, 'here' => here_url(), 'tz' => member_timezone(),
    'notice' => gl_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.']])]), ['activeNav' => 'recurring', 'screen' => 'recurring-view', 'entity' => 'recurringTemplate', 'recordId' => (string) $id]);
