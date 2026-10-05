<?php
declare(strict_types=1);
/** /settings/sites/ — the sites as cards (screen `sites-list`; settings.manage). Param: include_archived. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
require_right('settings.manage');
$pdo = db();
$rows = find_sites($pdo, request_bool('include_archived'));
log_screen_view($pdo, 'sites-list');
if (wants_json()) {
    respond_screen(['sites' => array_map('present_site', $rows)]);
}
render_screen('Sites', view('settings/sites.php', ['rows' => $rows, 'includeArchived' => request_bool('include_archived'), 'here' => here_url(),
    'notice' => gl_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved the site.'], 'archived' => ['success', 'The site was archived.'], 'restored' => ['success', 'The site was restored.']])]),
    ['activeNav' => 'sites', 'screen' => 'sites-list', 'entity' => 'site']);
