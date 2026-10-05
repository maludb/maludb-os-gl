<?php
declare(strict_types=1);
/** /customers/ — the customers as cards with balance due and open invoices (screen `customers-list`; parties.view). Params: q, include_archived. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('parties.view');
$pdo = db();
$filters = ['q' => request_string('q'), 'include_archived' => request_bool('include_archived')];
$rows = find_customers($pdo, $filters);
$may = ['write' => has_right('ar.write')];
log_screen_view($pdo, 'customers-list');
if (wants_json()) {
    respond_screen(['customers' => array_map('present_customer', $rows), 'filters' => $filters, 'may' => $may]);
}
$data = ['rows' => $rows, 'filters' => $filters, 'may' => $may, 'here' => here_url(), 'notice' => gl_notice($_GET['notice'] ?? null, ['archived' => ['success', 'The customer was archived.'], 'restored' => ['success', 'The customer was restored.']])];
if (is_htmx_request() && !is_htmx_boosted() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'customers-results') {
    echo view('customers/partials/cards.php', $data);
    exit;
}
render_screen('Customers', view('customers/list.php', $data), ['activeNav' => 'customers', 'screen' => 'customers-list', 'entity' => 'customer']);
