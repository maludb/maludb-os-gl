<?php
declare(strict_types=1);
/** /settings/recurring/new and /settings/recurring/{id}/edit — a template: name, kind, cadence, every N, next date, end date, active, and the document as the kind's own form (screens `recurring-add`, `recurring-edit`; settings.manage). Params on add: kind, from (journal:ID | bill:ID | invoice:ID — copies the document). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/recurring/handler.php';
require_right('settings.manage');
require_human();
$pdo = db();
$id = req_id('id', 'template');
$cur = null;
if ($id !== null) {
    require_visible($pdo, 'mcp_recurring_templates', 'recurring_template_id', $id, 'Template');
    $cur = template_state($pdo, $id) ?? refuse(404, 'Template not found.');
}
$prefill = null;
$from = request_string('from');
if ($cur === null && preg_match('/^(journal|bill|invoice):(\d+)$/', $from, $m)) {
    try {
        $prefill = template_from($pdo, $m[1], (int) $m[2]);
    } catch (DomainException $e) {
        refuse(404, 'That document is not here.');
    }
}
$kind = $cur['kind'] ?? $prefill['kind'] ?? (isset(TEMPLATE_KINDS[request_string('kind')]) ? request_string('kind') : 'journal');
$screen = $cur === null ? 'recurring-add' : 'recurring-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['template' => $cur === null ? null : $cur, 'prefill' => $prefill, 'kind' => $kind, 'cadences' => array_keys(CADENCES), 'kinds' => array_keys(TEMPLATE_KINDS)]);
}
render_screen($cur === null ? 'New recurring template' : 'Change ' . $cur['name'], view('settings/recurring-form.php', ['cur' => $cur, 'prefill' => $prefill, 'kind' => $kind, 'pdo' => $pdo, 'departments' => find_live_departments($pdo), 'sites' => find_live_sites($pdo), 'here' => here_url()]),
    ['activeNav' => 'recurring', 'screen' => $screen, 'entity' => 'recurringTemplate', 'recordId' => $id === null ? '' : (string) $id]);
