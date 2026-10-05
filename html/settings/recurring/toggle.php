<?php
declare(strict_types=1);
/** Action `recurring_toggle` (template, active yes|no; other for an agent; log `recurring.toggle`): settings.manage. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/recurring/handler.php';
gl_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = req_id('template', 'recurring_template', 'template_id') ?? refuse(422, 'Say which template.');
require_visible($pdo, 'mcp_recurring_templates', 'recurring_template_id', $id, 'Template');
$active = gl_yes('active', true);
try {
    $pdo->beginTransaction();
    $cur = toggle_template($pdo, $id, $active);
    log_activity($pdo, 'recurring.toggle', 'recurring_template', $id, ['before' => ['active' => $cur['active']], 'after' => ['active' => $active, 'name' => $cur['name']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done(($active ? 'Turned on ' : 'Turned off ') . $cur['name'], $id, gl_land(return_path('/settings/recurring/'), $active ? 'on' : 'off'), 'recurringChanged', ['recurring_template_id' => $id, 'active' => $active]);
