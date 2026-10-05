<?php
declare(strict_types=1);
/**
 * Actions `vendor_credit_draft` (vendor, bill — the one it is against, vendor_ref, credit_date, reason, department, site, `lines`; log `vendor_credit.draft`) and `vendor_credit_update` (`vendor_credit` a draft; log `vendor_credit.update` before/after):
 * ap.write. Lines are read like a bill's (normalize_doc_line, expense side); the trigger computes the money.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$me = (int) current_member_id();
$id = req_id('vendor_credit', 'vendor_credit_id');
$cur = null;
if ($id !== null) {
    require_visible($pdo, 'mcp_vendor_credits', 'vendor_credit_id', $id, 'Vendor credit');
    $cur = vendor_credit_state($pdo, $id) ?? refuse(404, 'Vendor credit not found.');
}
$errors = [];
$billId = $cur['bill_id'] ?? null;
$bill = null;
if ($cur === null || req_has('bill') || req_has('bill_id')) {
    $billId = req_id('bill', 'bill_id');
    if ($billId !== null) {
        require_visible($pdo, 'mcp_bills', 'bill_id', $billId, 'Bill');
    }
}
if ($billId !== null) {
    $bill = find_bill($pdo, $billId);
}
$vendorId = $cur['vendor_id'] ?? ($bill['vendor_id'] ?? null);
if ($cur === null || req_has('vendor') || req_has('vendor_id')) {
    $vendorId = gl_ref($pdo, req_has('vendor') ? 'vendor' : 'vendor_id', $vendorId, 'SELECT 1 FROM vendors WHERE id = :id AND archived_at IS NULL', 'vendor', $errors, false);
}
$date = $cur['credit_date'] ?? date('Y-m-d');
if (req_has('credit_date')) {
    $d = request_date('credit_date');
    if ($d === false) {
        $errors['credit_date'] = 'The date is YYYY-MM-DD.';
    } else {
        $date = $d ?? date('Y-m-d');
    }
}
$deptField = req_has('department') ? 'department' : 'department_id';
$dept = gl_ref($pdo, $deptField, $cur['department_id'] ?? ($bill['department_id'] ?? null), 'SELECT 1 FROM departments WHERE id = :id AND archived_at IS NULL', 'department', $errors);
$siteField = req_has('site') ? 'site' : 'site_id';
$site = gl_ref($pdo, $siteField, $cur['site_id'] ?? ($bill['site_id'] ?? null), 'SELECT 1 FROM sites WHERE id = :id AND archived_at IS NULL', 'site', $errors);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
if ($bill !== null && $bill['vendor_id'] !== $vendorId) {
    gl_refuse_fields(['bill' => 'That bill belongs to another vendor.']);
}
$defaults = bill_defaults($pdo, (int) $vendorId, $date);
$opt = fn (string $k, int $max): ?string => req_has($k) ? ((string) req_val($k) === '' ? null : mb_substr((string) req_val($k), 0, $max)) : ($cur[$k] ?? null);
$head = ['vendor_id' => $vendorId, 'bill_id' => $billId, 'vendor_ref' => $opt('vendor_ref', 120), 'credit_date' => $date, 'currency' => $cur['currency'] ?? ($bill['currency'] ?? $defaults['currency']), 'reason' => $opt('reason', 500), 'department_id' => $dept, 'site_id' => $site];
try {
    $pdo->beginTransaction();
    if ($cur === null) {
        $lines = [];
        foreach ((request_doc_rows() ?? []) as $n => $row) {
            $lines[] = normalize_doc_line($pdo, is_array($row) ? $row : [], $n + 1, $defaults, 'expense');
        }
        $newId = draft_vendor_credit($pdo, $head, $lines, $me);
        $c = vendor_credit_state($pdo, $newId);
        log_activity($pdo, 'vendor_credit.draft', 'vendor_credit', $newId, ap_keys($vendorId) + ['after' => ['number' => $c['number'], 'credit_date' => $date, 'currency' => $head['currency'], 'lines' => count($lines), 'bill_id' => $billId]]);
        $pdo->commit();
        gl_done('Entered ' . $c['number'] . ' (' . count($lines) . ' line' . (count($lines) === 1 ? '' : 's') . ')', $newId, gl_land(return_path('/vendor-credits/' . $newId), 'saved'), 'vendorCreditChanged', ['vendor_credit_id' => $newId, 'number' => $c['number']]);
    }
    $d = update_vendor_credit($pdo, $id, $head, $me);
    if ($d['after'] !== []) {
        log_activity($pdo, 'vendor_credit.update', 'vendor_credit', $id, ap_keys($cur['vendor_id']) + ['before' => $d['before'], 'after' => $d['after']]);
    }
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done($d['after'] === [] ? 'Nothing changed' : 'Saved the vendor credit (' . implode(', ', array_keys($d['after'])) . ')', $id, gl_land(return_path('/vendor-credits/' . $id), 'saved'), 'vendorCreditChanged', ['vendor_credit_id' => $id, 'changed' => array_keys($d['after'])]);
