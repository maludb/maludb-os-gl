<?php
declare(strict_types=1);
/** Action `statement_send` (customer, from, to, to_email; confirm; external_send for an agent; log `statement.send` with the count, never the address): ar.write. Queues the statement for the customer in the outbox (the worker of slice 8 sends it). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/statements/periods.php';
require_once dirname(__DIR__, 2) . '/app/features/statements/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/statements/present.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('customer', 'customer_id') ?? refuse(422, 'Say which customer.');
require_visible($pdo, 'mcp_customers', 'customer_id', $id, 'Customer');
$errors = [];
$to = req_email('to_email', $errors);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$range = period_range($pdo, ['from' => valid_date_param('from') ?? '', 'to' => valid_date_param('to') ?? '']);
$r = gl_guard($pdo, static function () use ($pdo, $id, $range, $to): array {
    $pdo->beginTransaction();
    $data = customer_statement($pdo, $id, $range['from'], $range['to']);
    $q = queue_statement_mail($pdo, $id, $data, $to);
    log_activity($pdo, 'statement.send', 'customer', $id, audit_keys($id) + ['after' => ['to' => 1, 'from' => $range['from'], 'to_date' => $range['to'], 'closing' => $data['closing'], 'currency' => $data['currency']]]);
    $pdo->commit();
    return $q;
});
gl_done('Queued the statement for the customer', $id, gl_land(return_path('/customers/' . $id . '/statement?from=' . $range['from'] . '&to=' . $range['to']), 'sent'), 'customerChanged', ['outbox_id' => $r['outbox_id']]);
