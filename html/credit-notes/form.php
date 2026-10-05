<?php
declare(strict_types=1);
/** /credit-notes/new and /{id}/edit — a credit note's head and first lines, or a draft's head (screens `credit-note-add`, `credit-note-edit`; ar.write). Params on add: customer, invoice (its lines are prefilled). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('ar.write');
require_human();
$pdo = db();
$id = req_id('id', 'credit_note');
$cur = null;
if ($id !== null) {
    require_visible($pdo, 'mcp_credit_notes', 'credit_note_id', $id, 'Credit note');
    $cur = find_credit_note($pdo, $id) ?? refuse(404, 'Credit note not found.');
    if ($cur['status'] !== 'draft') {
        refuse(422, 'Credit note ' . $cur['number'] . ' is ' . $cur['status'] . ' and cannot change.');
    }
}
$inv = null;
if ($cur === null && ($iid = request_integer('invoice')) !== null) {
    require_visible($pdo, 'mcp_invoices', 'invoice_id', $iid, 'Invoice');
    $inv = find_invoice($pdo, $iid);
}
$customerId = $cur['customer_id'] ?? request_integer('customer') ?? ($inv['customer_id'] ?? null);
$prefill = $inv === null ? [] : credit_prefill_from_invoice($pdo, $inv['invoice_id']);
$screen = $cur === null ? 'credit-note-add' : 'credit-note-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['credit_note' => $cur === null ? null : ['credit_note_id' => $cur['credit_note_id'], 'customer_id' => $cur['customer_id'], 'invoice_id' => $cur['invoice_id'], 'reason' => $cur['reason']], 'prefill' => $prefill]);
}
render_screen($cur === null ? 'New credit note' : 'Change the credit note', view('credit_notes/form.php', ['cur' => $cur, 'inv' => $inv, 'customerId' => $customerId, 'prefill' => $prefill, 'pdo' => $pdo, 'departments' => find_live_departments($pdo), 'sites' => find_live_sites($pdo)]),
    ['activeNav' => 'credit-notes', 'screen' => $screen, 'entity' => 'creditNote', 'recordId' => $id === null ? '' : (string) $id]);
