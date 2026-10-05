<?php
declare(strict_types=1);
/** Action `customer_save` (by the presence of `customer`; log `customer.save` before/after, the contact fields as "(set)" only): ar.write. A field left out stays as it was. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$me = (int) current_member_id();
$id = req_id('customer', 'customer_id');
$cur = null;
if ($id !== null) {
    require_visible($pdo, 'mcp_customers', 'customer_id', $id, 'Customer');
    $cur = customer_state($pdo, $id) ?? refuse(404, 'Customer not found.');
}
$errors = [];
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 120) {
    $errors['name'] = 'Give the customer a name of up to 120 characters.';
}
$opt = fn (string $k, int $max): ?string => req_has($k) ? ((string) req_val($k) === '' ? null : mb_substr((string) req_val($k), 0, $max)) : ($cur[$k] ?? null);
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
$incomeField = req_has('income_account') ? 'income_account' : 'income_account_id';
$income = gl_ref($pdo, $incomeField, $cur['income_account_id'] ?? null, "SELECT 1 FROM accounts WHERE id = :id AND archived_at IS NULL AND account_type = 'income'", 'income account', $errors);
$taxField = req_has('tax_rate') ? 'tax_rate' : 'tax_rate_id';
$tax = gl_ref($pdo, $taxField, $cur['tax_rate_id'] ?? null, 'SELECT 1 FROM tax_rates WHERE id = :id AND archived_at IS NULL', 'tax rate', $errors);
$memField = req_has('member') ? 'member' : 'member_id';
$member = gl_ref($pdo, $memField, $cur['member_id'] ?? null, "SELECT 1 FROM members WHERE id = :id AND status = 'active'", 'member', $errors);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$f = ['name' => $name, 'legal_name' => $opt('legal_name', 200), 'email' => $email, 'phone' => $opt('phone', 60), 'billing_address' => $opt('billing_address', 500), 'shipping_address' => $opt('shipping_address', 500),
      'tax_id' => $opt('tax_id', 60), 'terms_days' => $terms, 'currency' => $currency, 'income_account_id' => $income, 'tax_rate_id' => $tax, 'member_id' => $member, 'notes' => $opt('notes', 2000)];
$newId = gl_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_customer($pdo, $id, $f, $me);
    $after = customer_log_state(customer_state($pdo, $newId));
    if ($cur === null) {
        log_activity($pdo, 'customer.save', 'customer', $newId, audit_keys($newId) + ['after' => $after]);
    } else {
        $d = gl_diff(customer_log_state($cur), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'customer.save', 'customer', $newId, audit_keys($newId) + ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
gl_done(($id === null ? 'Added the customer ' : 'Saved the customer ') . $f['name'], $newId, gl_land(return_path('/customers/' . $newId), 'saved'), 'customerChanged', ['customer_id' => $newId]);
