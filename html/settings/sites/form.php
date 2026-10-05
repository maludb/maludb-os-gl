<?php
declare(strict_types=1);
/** /settings/sites/new and /{id}/edit — a site (screens `site-add`, `site-edit`; settings.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('site');
$cur = $id === null ? null : (find_site($pdo, $id) ?? refuse(404, 'Site not found.'));
$screen = $cur === null ? 'site-add' : 'site-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['site' => $cur === null ? null : present_site($cur)]);
}
render_screen($cur === null ? 'Add a site' : 'Change ' . $cur['name'], view('settings/site-form.php', ['cur' => $cur]), ['activeNav' => 'sites', 'screen' => $screen, 'entity' => 'site', 'recordId' => $id === null ? '' : (string) $id]);
