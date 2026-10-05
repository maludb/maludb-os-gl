<?php
declare(strict_types=1);
/**
 * Actions `credit_note_draft` (customer, invoice — the one it is raised against, issue_date, reason, department, site, `lines`; log `credit_note.draft`) and `credit_note_update` (`credit_note` a draft; log `credit_note.update` before/after):
 * ar.write. Lines are read like an invoice's (normalize_doc_line); the trigger computes the money.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$me = (int) current_member_id();
$id = req_id('credit_note', 'credit_note_id');
$cur = null;
if ($id !== null) {
    require_visible($pdo, 'mcp_credit_notes', 'credit_note_id', $id, 'Credit note');
    $cur = credit_note_state($pdo, $id) ?? refuse(404, 'Credit note not found.');
}
$errors = [];
$invoiceId = $cur['invoice_id'] ?? null;
$inv = null;
if ($cur === null || req_has('invoice') || req_has('invoice_id')) {
    $invoiceId = req_id('invoice', 'invoice_id');
    if ($invoiceId !== null) {
        require_visible($pdo, 'mcp_invoices', 'invoice_id', $invoiceId, 'Invoice');
    }
}
if ($invoiceId !== null) {
    $inv = find_invoice($pdo, $invoiceId);
}
$customerId = $cur['customer_id'] ?? ($inv['customer_id'] ?? null);
if ($cur === null || req_has('customer') || req_has('customer_id')) {
    $customerId = gl_ref($pdo, req_has('customer') ? 'customer' : 'customer_id', $customerId, 'SELECT 1 FROM customers WHERE id = :id AND archived_at IS NULL', 'customer', $errors, false);
}
$issue = $cur['issue_date'] ?? date('Y-m-d');
if (req_has('issue_date')) {
    $d = request_date('issue_date');
    if ($d === false) {
        $errors['issue_date'] = 'The date is YYYY-MM-DD.';
    } else {
        $issue = $d ?? date('Y-m-d');
    }
}
$deptField = req_has('department') ? 'department' : 'department_id';
$dept = gl_ref($pdo, $deptField, $cur['department_id'] ?? ($inv['department_id'] ?? null), 'SELECT 1 FROM departments WHERE id = :id AND archived_at IS NULL', 'department', $errors);
$siteField = req_has('site') ? 'site' : 'site_id';
$site = gl_ref($pdo, $siteField, $cur['site_id'] ?? ($inv['site_id'] ?? null), 'SELECT 1 FROM sites WHERE id = :id AND archived_at IS NULL', 'site', $errors);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
if ($inv !== null && $inv['customer_id'] !== $customerId) {
    gl_refuse_fields(['invoice' => 'That invoice belongs to another customer.']);
}
$defaults = invoice_defaults($pdo, (int) $customerId, $issue);
$head = ['customer_id' => $customerId, 'invoice_id' => $invoiceId, 'issue_date' => $issue, 'currency' => $cur['currency'] ?? ($inv['currency'] ?? $defaults['currency']),
         'reason' => req_has('reason') ? ((string) req_val('reason') === '' ? null : mb_substr((string) req_val('reason'), 0, 500)) : ($cur['reason'] ?? null), 'department_id' => $dept, 'site_id' => $site];
try {
    $pdo->beginTransaction();
    if ($cur === null) {
        $lines = [];
        foreach ((request_doc_rows() ?? []) as $n => $row) {
            $lines[] = normalize_doc_line($pdo, is_array($row) ? $row : [], $n + 1, $defaults);
        }
        $newId = draft_credit_note($pdo, $head, $lines, $me);
        log_activity($pdo, 'credit_note.draft', 'credit_note', $newId, audit_keys($customerId) + ['after' => ['issue_date' => $issue, 'currency' => $head['currency'], 'lines' => count($lines), 'invoice_id' => $invoiceId]]);
        $pdo->commit();
        gl_done('Drafted a credit note (' . count($lines) . ' line' . (count($lines) === 1 ? '' : 's') . ')', $newId, gl_land(return_path('/credit-notes/' . $newId), 'saved'), 'creditNoteChanged', ['credit_note_id' => $newId]);
    }
    $d = update_credit_note($pdo, $id, $head, $me);
    if ($d['after'] !== []) {
        log_activity($pdo, 'credit_note.update', 'credit_note', $id, audit_keys($cur['customer_id']) + ['before' => $d['before'], 'after' => $d['after']]);
    }
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done($d['after'] === [] ? 'Nothing changed' : 'Saved the credit note (' . implode(', ', array_keys($d['after'])) . ')', $id, gl_land(return_path('/credit-notes/' . $id), 'saved'), 'creditNoteChanged', ['credit_note_id' => $id, 'changed' => array_keys($d['after'])]);
