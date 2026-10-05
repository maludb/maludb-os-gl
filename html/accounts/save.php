<?php
declare(strict_types=1);
/**
 * Action `account_save` (by the presence of `account`; log `account.save` with before/after of the changed fields): settings.manage. A field left out stays as it was.
 * The handler validates the shape; the guard trigger (db/005) is the referee: its RAISE (cannot sit under, keeps its type, cannot be both) is the 422 in its own words.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
gl_handler_begin();
require_right('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('account') ?? request_integer('account_id');
$cur = null;
if ($id !== null) {
    $cur = account_state($pdo, $id) ?? refuse(404, 'Account not found.');
}
$errors = [];
$code = req_has('code') ? (string) req_val('code') : (string) ($cur['code'] ?? '');
if (!preg_match('/^[0-9]{3,8}(\.[0-9]{1,4})?$/', $code)) {
    $errors['code'] = 'The code is 3 to 8 digits, with an optional dot part (1200 or 1200.10).';
}
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 120) {
    $errors['name'] = 'Give the account a name of up to 120 characters.';
}
$type = req_has('account_type') ? (string) req_val('account_type') : (string) ($cur['account_type'] ?? '');
if (!isset(ACCOUNT_TYPES[$type])) {
    $errors['account_type'] = 'The type is asset, liability, equity, income or expense.';
}
$subtype = req_has('subtype') ? (string) req_val('subtype') : (string) ($cur['subtype'] ?? 'other');
if ($subtype === '' || (!req_has('subtype') && $cur !== null && $type !== $cur['account_type'])) {
    $subtype = 'other';                       // the type changed and no subtype came: the old subtype belongs to the old type (a system account's change is the trigger's to refuse)
}
if (isset(ACCOUNT_TYPES[$type]) && !isset(ACCOUNT_SUBTYPES[$type][$subtype]) && !($cur !== null && $cur['is_system'] && $type !== $cur['account_type'])) {
    $errors['subtype'] = 'The subtype of a' . ($type === 'asset' || $type === 'income' || $type === 'equity' || $type === 'expense' ? 'n ' : ' ') . $type . ' account is one of: ' . implode(', ', array_keys(ACCOUNT_SUBTYPES[$type])) . '.';
}
$parentField = req_has('parent') ? 'parent' : 'parent_id';
$parent = gl_ref($pdo, $parentField, $cur['parent_id'] ?? null, 'SELECT 1 FROM accounts WHERE id = :id AND archived_at IS NULL', 'parent account', $errors);
$isBank = gl_yes('is_bank', $cur['is_bank'] ?? false);
$description = req_has('description') ? ((string) req_val('description') === '' ? null : mb_substr((string) req_val('description'), 0, 500)) : ($cur['description'] ?? null);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$f = ['code' => $code, 'name' => $name, 'account_type' => $type, 'subtype' => $subtype, 'parent_id' => $parent, 'is_bank' => $isBank, 'description' => $description];
$newId = gl_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_account($pdo, $id, $f, $me);
    $after = account_log_state(account_state($pdo, $newId));
    if ($cur === null) {
        log_activity($pdo, 'account.save', 'account', $newId, ['after' => $after]);
    } else {
        $d = gl_diff(account_log_state($cur), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'account.save', 'account', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
gl_done(($id === null ? 'Added the account ' : 'Saved the account ') . $f['code'] . ' ' . $f['name'], $newId, gl_land(return_path('/accounts/' . $newId), 'saved'), 'accountChanged', ['account_id' => $newId]);
