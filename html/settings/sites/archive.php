<?php
declare(strict_types=1);
/** Action `site_archive` (log `site.archive`; confirm): settings.manage. `archived` yes archives, no restores. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
gl_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('site') ?? request_integer('site_id') ?? refuse(422, 'Say which site.');
$archived = gl_yes('archived', true);
$s = gl_guard($pdo, static function () use ($pdo, $id, $archived): array {
    $pdo->beginTransaction();
    archive_site($pdo, $id, $archived);
    $s = find_site($pdo, $id);
    log_activity($pdo, 'site.archive', 'site', $id, ['site_id' => $id, 'before' => ['archived' => !$archived], 'after' => ['archived' => $archived, 'name' => $s['name']]]);
    $pdo->commit();
    return $s;
});
gl_done(($archived ? 'Archived the site ' : 'Restored the site ') . $s['name'], $id, gl_land(return_path('/settings/sites/'), $archived ? 'archived' : 'restored'), 'siteChanged');
