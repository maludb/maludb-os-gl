<?php
declare(strict_types=1);
/** Action `recurring_delete` (template; confirm; deletion for an agent; log `recurring.delete` BEFORE the delete): settings.manage. Its runs go with it; the documents it drafted stay. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/recurring/handler.php';
gl_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = req_id('template', 'recurring_template', 'template_id') ?? refuse(422, 'Say which template.');
require_visible($pdo, 'mcp_recurring_templates', 'recurring_template_id', $id, 'Template');
try {
    $pdo->beginTransaction();
    $cur = template_state($pdo, $id) ?? throw new DomainException('Not found.');
    log_activity($pdo, 'recurring.delete', 'recurring_template', $id, ['before' => template_log_state($cur)]);
    delete_template($pdo, $id);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Deleted the template ' . $cur['name'], $id, gl_land(return_path('/settings/recurring/'), 'deleted'), 'recurringChanged');
