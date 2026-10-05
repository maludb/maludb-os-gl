<?php
declare(strict_types=1);
/** Action `customer_delete` (log `customer.delete`; confirm; deletion for an agent): journal.override. Refused in words when any invoice, credit note, receipt or posted line exists. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('journal.override');
$pdo = db();
$id = req_id('customer', 'customer_id') ?? refuse(422, 'Say which customer.');
require_visible($pdo, 'mcp_customers', 'customer_id', $id, 'Customer');
$c = gl_guard($pdo, static function () use ($pdo, $id): array {
    $pdo->beginTransaction();
    $c = delete_customer($pdo, $id);
    log_activity($pdo, 'customer.delete', 'customer', $id, ['before' => customer_log_state($c)]);
    $pdo->commit();
    return $c;
});
gl_done('Deleted ' . $c['name'], $id, gl_land(return_path('/customers/'), 'archived'), 'customerChanged');
