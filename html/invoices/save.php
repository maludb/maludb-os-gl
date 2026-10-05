<?php
declare(strict_types=1);
/**
 * Actions `invoice_draft` (no `invoice`: customer, dates, reference, memo, terms_text, department, site, `lines` JSON rows or the form's line arrays; log `invoice.draft`) and `invoice_update` (`invoice` a draft: the
 * head; log `invoice.update` before/after): ar.write. The trigger computes each line's money and the totals; an issued invoice's head is refused in the guard's words.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$me = (int) current_member_id();
$id = req_id('invoice', 'invoice_id');
$cur = null;
if ($id !== null) {
    require_visible($pdo, 'mcp_invoices', 'invoice_id', $id, 'Invoice');
    $cur = invoice_state($pdo, $id) ?? refuse(404, 'Invoice not found.');
}
$errors = [];
$customerId = $cur['customer_id'] ?? null;
if ($cur === null || req_has('customer') || req_has('customer_id')) {
    $customerId = gl_ref($pdo, req_has('customer') ? 'customer' : 'customer_id', $customerId, 'SELECT 1 FROM customers WHERE id = :id AND archived_at IS NULL', 'customer', $errors, false);
}
$issue = $cur['issue_date'] ?? null;
if ($cur === null || req_has('issue_date')) {
    $d = request_date('issue_date');
    if ($d === false) {
        $errors['issue_date'] = 'The issue date is YYYY-MM-DD.';
    } else {
        $issue = $d ?? date('Y-m-d');
    }
}
$due = $cur['due_date'] ?? null;
if ($cur === null || req_has('due_date')) {
    $d = request_date('due_date');
    if ($d === false) {
        $errors['due_date'] = 'The due date is YYYY-MM-DD.';
    } else {
        $due = $d;                                      // empty: the customer's terms, below
    }
}
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$defaults = $customerId === null ? null : invoice_defaults($pdo, $customerId, $issue);
if ($due === null && $defaults !== null) {
    $due = $defaults['due_date'];
}
if ($due !== null && $issue !== null && $due < $issue) {
    $errors['due_date'] = 'The due date cannot be before the issue date.';
}
$opt = fn (string $k, int $max): ?string => req_has($k) ? ((string) req_val($k) === '' ? null : mb_substr((string) req_val($k), 0, $max)) : ($cur[$k] ?? null);
$deptField = req_has('department') ? 'department' : 'department_id';
$dept = gl_ref($pdo, $deptField, $cur['department_id'] ?? null, 'SELECT 1 FROM departments WHERE id = :id AND archived_at IS NULL', 'department', $errors);
$siteField = req_has('site') ? 'site' : 'site_id';
$site = gl_ref($pdo, $siteField, $cur['site_id'] ?? null, 'SELECT 1 FROM sites WHERE id = :id AND archived_at IS NULL', 'site', $errors);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$head = ['customer_id' => $customerId, 'issue_date' => $issue, 'due_date' => $due, 'currency' => $cur['currency'] ?? $defaults['currency'], 'reference' => $opt('reference', 120), 'memo' => $opt('memo', 1000), 'terms_text' => $opt('terms_text', 1000), 'department_id' => $dept, 'site_id' => $site];
try {
    $pdo->beginTransaction();
    if ($cur === null) {
        $lines = [];
        foreach ((request_doc_rows() ?? []) as $n => $row) {
            $lines[] = normalize_doc_line($pdo, is_array($row) ? $row : [], $n + 1, $defaults);
        }
        $newId = draft_invoice($pdo, $head, $lines, $me);
        log_activity($pdo, 'invoice.draft', 'invoice', $newId, audit_keys($customerId) + ['after' => ['issue_date' => $issue, 'due_date' => $due, 'currency' => $head['currency'], 'lines' => count($lines), 'customer_id' => $customerId]]);
        $pdo->commit();
        gl_done('Drafted an invoice for ' . ($defaults['customer']['name'] ?? 'the customer') . ' (' . count($lines) . ' line' . (count($lines) === 1 ? '' : 's') . ')', $newId, gl_land(return_path('/invoices/' . $newId), 'saved'), 'invoiceChanged', ['invoice_id' => $newId]);
    }
    $d = update_invoice($pdo, $id, $head, $me);
    if ($d['after'] !== []) {
        log_activity($pdo, 'invoice.update', 'invoice', $id, audit_keys($cur['customer_id']) + ['before' => $d['before'], 'after' => $d['after']]);
    }
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done($d['after'] === [] ? 'Nothing changed' : 'Saved the invoice (' . implode(', ', array_keys($d['after'])) . ')', $id, gl_land(return_path('/invoices/' . $id), 'saved'), 'invoiceChanged', ['invoice_id' => $id, 'changed' => array_keys($d['after'])]);
