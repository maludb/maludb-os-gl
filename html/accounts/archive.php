<?php
declare(strict_types=1);
/** Action `account_archive` (log `account.archive`, after.archived; confirm): settings.manage. `archived` yes archives, no restores. The archive guard (db/008) refuses a balance in its own words. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
gl_handler_begin();
require_right('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('account') ?? request_integer('account_id') ?? refuse(422, 'Say which account.');
$archived = gl_yes('archived', true);
$s = gl_guard($pdo, static function () use ($pdo, $me, $id, $archived): array {
    $pdo->beginTransaction();
    archive_account($pdo, $id, $archived, $me);
    $s = account_state($pdo, $id);
    log_activity($pdo, 'account.archive', 'account', $id, ['before' => ['archived' => !$archived], 'after' => ['archived' => $archived, 'code' => $s['code'], 'name' => $s['name']]]);
    $pdo->commit();
    return $s;
});
gl_done(($archived ? 'Archived ' : 'Restored ') . $s['code'] . ' ' . $s['name'], $id, gl_land(return_path($archived ? '/accounts/' : '/accounts/' . $id), $archived ? 'archived' : 'restored'), 'accountChanged');
