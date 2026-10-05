<?php
declare(strict_types=1);
/**
 * Actions `bill_draft` (no `bill`: vendor, vendor_ref, bill_date, due_date, memo, department, site, `lines` JSON rows or the form's line arrays, an optional `document` file; log `bill.draft`) and `bill_update` (`bill` a draft: the head; log `bill.update` before/after): ap.write.
 * The trigger computes each line's money and the totals; the vendor's reference is unique per vendor (422 naming the bill, with its id as record_id); an approved bill's head is refused in the guard's words.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$me = (int) current_member_id();
$id = req_id('bill', 'bill_id');
$cur = null;
if ($id !== null) {
    require_visible($pdo, 'mcp_bills', 'bill_id', $id, 'Bill');
    $cur = bill_state($pdo, $id) ?? refuse(404, 'Bill not found.');
}
$errors = [];
$vendorId = $cur['vendor_id'] ?? null;
if ($cur === null || req_has('vendor') || req_has('vendor_id')) {
    $vendorId = gl_ref($pdo, req_has('vendor') ? 'vendor' : 'vendor_id', $vendorId, 'SELECT 1 FROM vendors WHERE id = :id AND archived_at IS NULL', 'vendor', $errors, false);
}
$billDate = $cur['bill_date'] ?? null;
if ($cur === null || req_has('bill_date')) {
    $d = request_date('bill_date');
    if ($d === false) {
        $errors['bill_date'] = 'The bill date is YYYY-MM-DD.';
    } else {
        $billDate = $d ?? date('Y-m-d');
    }
}
$due = $cur['due_date'] ?? null;
if ($cur === null || req_has('due_date')) {
    $d = request_date('due_date');
    if ($d === false) {
        $errors['due_date'] = 'The due date is YYYY-MM-DD.';
    } else {
        $due = $d;                                      // empty: the vendor's terms, below
    }
}
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$defaults = $vendorId === null ? null : bill_defaults($pdo, $vendorId, $billDate);
if ($due === null && $defaults !== null) {
    $due = $defaults['due_date'];
}
if ($due !== null && $billDate !== null && $due < $billDate) {
    $errors['due_date'] = 'The due date cannot be before the bill date.';
}
$opt = fn (string $k, int $max): ?string => req_has($k) ? ((string) req_val($k) === '' ? null : mb_substr((string) req_val($k), 0, $max)) : ($cur[$k] ?? null);
$deptField = req_has('department') ? 'department' : 'department_id';
$dept = gl_ref($pdo, $deptField, $cur['department_id'] ?? null, 'SELECT 1 FROM departments WHERE id = :id AND archived_at IS NULL', 'department', $errors);
$siteField = req_has('site') ? 'site' : 'site_id';
$site = gl_ref($pdo, $siteField, $cur['site_id'] ?? null, 'SELECT 1 FROM sites WHERE id = :id AND archived_at IS NULL', 'site', $errors);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$head = ['vendor_id' => $vendorId, 'vendor_ref' => $opt('vendor_ref', 120), 'bill_date' => $billDate, 'due_date' => $due, 'currency' => $cur['currency'] ?? $defaults['currency'], 'memo' => $opt('memo', 1000), 'department_id' => $dept, 'site_id' => $site, 'source' => 'manual'];
$document = isset($_FILES['document']) && (int) ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $_FILES['document'] : null;
if ($document !== null && !has_right('attachments.write')) {
    refuse(403, 'You may not attach documents.');
}
try {
    $pdo->beginTransaction();
    if ($cur === null) {
        $lines = [];
        foreach ((request_doc_rows() ?? []) as $n => $row) {
            $lines[] = normalize_doc_line($pdo, is_array($row) ? $row : [], $n + 1, $defaults, 'expense');
        }
        $newId = draft_bill($pdo, $head, $lines, $me);
        $b = bill_state($pdo, $newId);
        $withDoc = false;
        if ($document !== null) {
            $aid = store_attachment($pdo, 'bill', $newId, $document, $me);
            $a = $pdo->query('SELECT filename, byte_size, mime_type FROM attachments WHERE id = ' . $aid)->fetch();
            log_activity($pdo, 'attachment.add', 'bill', $newId, ap_keys($vendorId) + ['after' => ['attachment_id' => $aid, 'record_type' => 'bill', 'record_id' => $newId, 'filename' => $a['filename'], 'byte_size' => (int) $a['byte_size'], 'mime_type' => $a['mime_type']]]);
            $withDoc = true;
        }
        log_activity($pdo, 'bill.draft', 'bill', $newId, ap_keys($vendorId) + ['after' => ['number' => $b['number'], 'vendor_ref' => $head['vendor_ref'], 'bill_date' => $billDate, 'due_date' => $due, 'currency' => $head['currency'], 'lines' => count($lines), 'amount' => $b['total'], 'has_document' => $withDoc]]);
        notify_bill_awaiting($pdo, $newId, $b['number'], (string) $defaults['vendor']['name'], (string) $b['total'], $head['currency']);
        $pdo->commit();
        gl_done('Entered ' . $b['number'] . ' from ' . $defaults['vendor']['name'] . ' (' . count($lines) . ' line' . (count($lines) === 1 ? '' : 's') . ')', $newId, gl_land(return_path('/bills/' . $newId), 'saved'), 'billChanged', ['bill_id' => $newId, 'number' => $b['number'], 'has_document' => $withDoc]);
    }
    $d = update_bill($pdo, $id, $head, $me);
    if ($d['after'] !== []) {
        log_activity($pdo, 'bill.update', 'bill', $id, ap_keys($cur['vendor_id']) + ['before' => $d['before'], 'after' => $d['after']]);
    }
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done($d['after'] === [] ? 'Nothing changed' : 'Saved the bill (' . implode(', ', array_keys($d['after'])) . ')', $id, gl_land(return_path('/bills/' . $id), 'saved'), 'billChanged', ['bill_id' => $id, 'changed' => array_keys($d['after'])]);
