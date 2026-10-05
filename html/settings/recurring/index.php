<?php
declare(strict_types=1);
/** /settings/recurring/ — the templates as cards: kind, cadence in words, next date, last run, on or off; "last run failed: …" when it did (screen `recurring-list`; settings.manage). Params: kind, active, q. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/recurring/handler.php';
require_right('settings.manage');
$pdo = db();
$filters = ['kind' => request_string('kind'), 'active' => request_string('active'), 'q' => request_string('q')];
$rows = find_templates($pdo, $filters);
$may = ['write' => true, 'run' => has_right('journal.write')];
log_screen_view($pdo, 'recurring-list');
if (wants_json()) {
    respond_screen(['templates' => array_map('present_template_row', $rows), 'filters' => $filters, 'may' => $may]);
}
render_screen('Recurring', view('settings/recurring.php', ['rows' => $rows, 'filters' => $filters, 'may' => $may, 'here' => here_url(), 'notice' => gl_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.'], 'on' => ['success', 'Turned on.'], 'off' => ['success', 'Turned off.'], 'deleted' => ['success', 'The template was deleted.']])]),
    ['activeNav' => 'recurring', 'screen' => 'recurring-list', 'entity' => 'recurringTemplate']);
