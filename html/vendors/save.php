<?php
declare(strict_types=1);
/** Action `vendor_save` (by the presence of `vendor`; log `vendor.save` before/after, the contact fields as "(set)" only): ap.write. A field left out stays as it was. An AI provider is made by the AI statement; its kind is read-only here. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$me = (int) current_member_id();
$id = req_id('vendor', 'vendor_id');
$cur = null;
if ($id !== null) {
    require_visible($pdo, 'mcp_vendors', 'vendor_id', $id, 'Vendor');
    $cur = vendor_state($pdo, $id) ?? refuse(404, 'Vendor not found.');
}
$errors = [];
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 120) {
    $errors['name'] = 'Give the vendor a name of up to 120 characters.';
}
$opt = fn (string $k, int $max): ?string => req_has($k) ? ((string) req_val($k) === '' ? null : mb_substr((string) req_val($k), 0, $max)) : ($cur[$k] ?? null);
$kind = $cur['kind'] ?? 'vendor';
if (req_has('kind') && (string) req_val('kind') !== '') {
    $kind = (string) req_val('kind');
    if (!isset(VENDOR_FORM_KINDS[$kind]) && $kind !== ($cur['kind'] ?? '')) {
        $errors['kind'] = 'The kind is vendor, employee or tax_authority' . (($cur['kind'] ?? '') === 'ai_provider' ? ' (an AI provider keeps its kind)' : '') . '.';
    }
}
$email = req_email('email', $errors, $cur['email'] ?? null);
$terms = gl_int('terms_days', $cur['terms_days'] ?? null, 0, 365, 'Terms', $errors, true);
$currency = $cur['currency'] ?? null;
if (req_has('currency')) {
    $currency = strtoupper((string) req_val('currency'));
    if ($currency === '') {
        $currency = null;
    } elseif (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $errors['currency'] = 'The currency is three letters (USD), or empty for the base.';
    }
}
$accField = req_has('expense_account') ? 'expense_account' : 'expense_account_id';
$account = gl_ref($pdo, $accField, $cur['expense_account_id'] ?? null, "SELECT 1 FROM accounts WHERE id = :id AND archived_at IS NULL AND account_type IN ('expense', 'asset')", 'expense account', $errors);
$pay = $cur['pay_method'] ?? null;
if (req_has('pay_method')) {
    $pay = (string) req_val('pay_method') === '' ? null : (string) req_val('pay_method');
    if ($pay !== null && !isset(PAY_METHODS[$pay])) {
        $errors['pay_method'] = 'The pay method is cheque, ach, card, cash or other.';
    }
}
$memField = req_has('member') ? 'member' : 'member_id';
$member = gl_ref($pdo, $memField, $cur['member_id'] ?? null, "SELECT 1 FROM members WHERE id = :id AND status = 'active'", 'member', $errors);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$f = ['name' => $name, 'legal_name' => $opt('legal_name', 200), 'kind' => $kind, 'email' => $email, 'phone' => $opt('phone', 60), 'remit_address' => $opt('remit_address', 500), 'tax_id' => $opt('tax_id', 60), 'terms_days' => $terms,
      'currency' => $currency, 'expense_account_id' => $account, 'pay_method' => $pay, 'member_id' => $member, 'notes' => $opt('notes', 2000)];
try {
    $pdo->beginTransaction();
    $newId = save_vendor($pdo, $id, $f, $me);
    $after = vendor_log_state(vendor_state($pdo, $newId));
    if ($cur === null) {
        log_activity($pdo, 'vendor.save', 'vendor', $newId, ap_keys($newId) + ['after' => $after]);
    } else {
        $d = gl_diff(vendor_log_state($cur), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'vendor.save', 'vendor', $newId, ap_keys($newId) + ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done(($id === null ? 'Added the vendor ' : 'Saved the vendor ') . $f['name'], $newId, gl_land(return_path('/vendors/' . $newId), 'saved'), 'vendorChanged', ['vendor_id' => $newId]);
