<?php
declare(strict_types=1);
/** /customers/{id}/statement — slice 3's customer statement with the customer fixed, and Send (screen `customer-statement`; reports.view). Params: from, to, format (csv | print). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/statements/periods.php';
require_once dirname(__DIR__, 2) . '/app/features/statements/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/statements/present.php';
require_right('reports.view');
$pdo = db();
$id = req_id('id', 'customer') ?? refuse(404, 'Customer not found.');
require_visible($pdo, 'mcp_customers', 'customer_id', $id, 'Customer');
$range = period_range($pdo, ['period' => request_string('period'), 'from' => valid_date_param('from') ?? '', 'to' => valid_date_param('to') ?? '']);
$data = customer_statement($pdo, $id, $range['from'], $range['to']);
$table = customer_statement_table($data);
$format = request_string('format');
if ($format === 'csv' || $format === 'print') {
    log_activity($pdo, 'export.download', 'customer', $id, audit_keys($id) + ['after' => ['what' => 'customer-statement', 'period' => $range['label'], 'format' => $format]]);
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="statement-' . $id . '-' . $range['to'] . '.csv"');
        echo statement_csv([$table]);
        exit;
    }
    $biz = $pdo->query('SELECT business_name, business_address FROM mcp_settings')->fetch() ?: [];
    echo view('statements/print.php', ['title' => 'Statement', 'table' => $table, 'range' => $range, 'pt' => posted_through($pdo), 'biz' => $biz, 'hasBooks' => true]);
    exit;
}
log_screen_view($pdo, 'customer-statement', ['customer_id' => $id]);
if (wants_json()) {
    respond_screen(['customer_id' => $id, 'period' => $range, 'statement' => $data, 'table' => $table]);
}
render_screen('Statement — ' . $data['customer_name'], view('customers/statement.php', ['data' => $data, 'table' => $table, 'range' => $range, 'periods' => period_choices($pdo), 'may' => ['send' => has_right('ar.write')],
    'notice' => gl_notice($_GET['notice'] ?? null, ['sent' => ['success', 'The statement is queued for the customer.']]), 'here' => here_url()]), ['activeNav' => 'customers', 'screen' => 'customer-statement', 'entity' => 'customer', 'recordId' => (string) $id]);
