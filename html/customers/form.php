<?php
declare(strict_types=1);
/** /customers/new and /customers/{id}/edit — a customer (screens `customer-add`, `customer-edit`; ar.write). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('ar.write');
require_human();
$pdo = db();
$id = req_id('id', 'customer');
$cur = null;
if ($id !== null) {
    require_visible($pdo, 'mcp_customers', 'customer_id', $id, 'Customer');
    $cur = customer_state($pdo, $id) ?? refuse(404, 'Customer not found.');
}
$locked = $cur !== null && array_sum(customer_documents($pdo, $id)) > 0;
$screen = $cur === null ? 'customer-add' : 'customer-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['customer' => $cur === null ? null : customer_log_state($cur) + ['customer_id' => $cur['customer_id']], 'currency_fixed' => $locked, 'tax_rates' => find_tax_rates($pdo), 'income_accounts' => accounts_for_select($pdo, ['income'])]);
}
render_screen($cur === null ? 'Add a customer' : 'Change ' . $cur['name'], view('customers/form.php', ['cur' => $cur, 'locked' => $locked, 'pdo' => $pdo, 'taxRates' => find_tax_rates($pdo), 'members' => find_members_for_select($pdo), 'base' => base_currency_code($pdo)]),
    ['activeNav' => 'customers', 'screen' => $screen, 'entity' => 'customer', 'recordId' => $id === null ? '' : (string) $id]);
