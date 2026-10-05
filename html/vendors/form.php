<?php
declare(strict_types=1);
/** /vendors/new and /vendors/{id}/edit — a vendor (screens `vendor-add`, `vendor-edit`; ap.write). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
require_right('ap.write');
require_human();
$pdo = db();
$id = req_id('id', 'vendor');
$cur = null;
if ($id !== null) {
    require_visible($pdo, 'mcp_vendors', 'vendor_id', $id, 'Vendor');
    $cur = vendor_state($pdo, $id) ?? refuse(404, 'Vendor not found.');
}
$locked = $cur !== null && (array_sum(vendor_documents($pdo, $id)) - vendor_documents($pdo, $id)['attachments']) > 0;
$screen = $cur === null ? 'vendor-add' : 'vendor-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['vendor' => $cur === null ? null : vendor_log_state($cur) + ['vendor_id' => $cur['vendor_id']], 'currency_fixed' => $locked, 'kinds' => array_keys(VENDOR_FORM_KINDS), 'pay_methods' => array_keys(PAY_METHODS), 'expense_accounts' => accounts_for_select($pdo, ['expense', 'asset'])]);
}
render_screen($cur === null ? 'Add a vendor' : 'Change ' . $cur['name'], view('vendors/form.php', ['cur' => $cur, 'locked' => $locked, 'pdo' => $pdo, 'members' => find_members_for_select($pdo), 'base' => base_currency_code($pdo)]),
    ['activeNav' => 'vendors', 'screen' => $screen, 'entity' => 'vendor', 'recordId' => $id === null ? '' : (string) $id]);
