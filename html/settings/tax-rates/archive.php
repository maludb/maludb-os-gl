<?php
declare(strict_types=1);
/** Action `tax_rate_archive` (log `tax_rate.archive`; confirm): settings.manage. Never the default ("Make another rate the default first."). `archived` yes archives, no restores. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
gl_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('tax_rate') ?? request_integer('tax_rate_id') ?? refuse(422, 'Say which rate.');
$archived = gl_yes('archived', true);
$t = gl_guard($pdo, static function () use ($pdo, $id, $archived): array {
    $pdo->beginTransaction();
    archive_tax_rate($pdo, $id, $archived);
    $t = find_tax_rate($pdo, $id);
    log_activity($pdo, 'tax_rate.archive', 'tax_rate', $id, ['before' => ['archived' => !$archived], 'after' => ['archived' => $archived, 'name' => $t['name']]]);
    $pdo->commit();
    return $t;
});
gl_done(($archived ? 'Archived the rate ' : 'Restored the rate ') . $t['name'], $id, gl_land(return_path('/settings/tax-rates/'), $archived ? 'archived' : 'restored'), 'taxRateChanged');
