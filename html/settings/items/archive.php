<?php
declare(strict_types=1);
/** Action `item_archive` (log `item.archive`; confirm): settings.manage. `archived` yes archives (off the pickers), no restores. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
gl_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('item') ?? request_integer('item_id') ?? refuse(422, 'Say which item.');
$archived = gl_yes('archived', true);
$i = gl_guard($pdo, static function () use ($pdo, $id, $archived): array {
    $pdo->beginTransaction();
    archive_item($pdo, $id, $archived);
    $i = find_item($pdo, $id);
    log_activity($pdo, 'item.archive', 'item', $id, ['before' => ['archived' => !$archived], 'after' => ['archived' => $archived, 'name' => $i['name']]]);
    $pdo->commit();
    return $i;
});
gl_done(($archived ? 'Archived the item ' : 'Restored the item ') . $i['name'], $id, gl_land(return_path('/settings/items/'), $archived ? 'archived' : 'restored'), 'itemChanged');
