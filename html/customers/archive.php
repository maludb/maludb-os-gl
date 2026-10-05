<?php
declare(strict_types=1);
/** Action `customer_archive` (log `customer.archive`; confirm): ar.write. `archived` yes archives (refused with an open invoice), no restores. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('customer', 'customer_id') ?? refuse(422, 'Say which customer.');
require_visible($pdo, 'mcp_customers', 'customer_id', $id, 'Customer');
$archived = gl_yes('archived', true);
$c = gl_guard($pdo, static function () use ($pdo, $id, $archived): array {
    $pdo->beginTransaction();
    archive_customer($pdo, $id, $archived);
    $c = customer_state($pdo, $id);
    log_activity($pdo, 'customer.archive', 'customer', $id, audit_keys($id) + ['before' => ['archived' => !$archived], 'after' => ['archived' => $archived, 'name' => $c['name']]]);
    $pdo->commit();
    return $c;
});
gl_done(($archived ? 'Archived ' : 'Restored ') . $c['name'], $id, gl_land(return_path($archived ? '/customers/' : '/customers/' . $id), $archived ? 'archived' : 'restored'), 'customerChanged');
