<?php
declare(strict_types=1);
/** Action `item_save` (by the presence of `item`; log `item.save` before/after of the changed fields): settings.manage. A field left out stays as it was; a live code is unique. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
gl_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('item') ?? request_integer('item_id');
$cur = $id === null ? null : (find_item($pdo, $id) ?? refuse(404, 'Item not found.'));
$errors = [];
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 120) {
    $errors['name'] = 'Give the item a name of up to 120 characters.';
}
$code = req_has('code') ? ((string) req_val('code') === '' ? null : mb_substr((string) req_val('code'), 0, 40)) : ($cur['code'] ?? null);
$kind = req_has('kind') ? (string) req_val('kind') : (string) ($cur['kind'] ?? 'service');
if (!isset(ITEM_KINDS[$kind])) {
    $errors['kind'] = 'The kind is product or service.';
}
$description = req_has('description') ? ((string) req_val('description') === '' ? null : mb_substr((string) req_val('description'), 0, 1000)) : ($cur['description'] ?? null);
$unit = req_has('unit') ? ((string) req_val('unit') === '' ? null : mb_substr((string) req_val('unit'), 0, 20)) : ($cur['unit'] ?? null);
$price = $cur['unit_price'] ?? '0.00';
if (req_has('unit_price')) {
    $p = parse_money(req_val('unit_price'));
    if ($p === null || (float) $p < 0) {
        $errors['unit_price'] = 'The unit price is an amount of zero or more.';
    } else {
        $price = $p;
    }
}
$incomeField = req_has('income_account') ? 'income_account' : 'income_account_id';
$income = gl_ref($pdo, $incomeField, $cur['income_account_id'] ?? null, "SELECT 1 FROM accounts WHERE id = :id AND archived_at IS NULL AND account_type = 'income'", 'income account', $errors);
$expenseField = req_has('expense_account') ? 'expense_account' : 'expense_account_id';
$expense = gl_ref($pdo, $expenseField, $cur['expense_account_id'] ?? null, "SELECT 1 FROM accounts WHERE id = :id AND archived_at IS NULL AND account_type = 'expense'", 'expense account', $errors);
$taxField = req_has('tax_rate') ? 'tax_rate' : 'tax_rate_id';
$tax = gl_ref($pdo, $taxField, $cur['tax_rate_id'] ?? null, 'SELECT 1 FROM tax_rates WHERE id = :id AND archived_at IS NULL', 'tax rate', $errors);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$f = ['code' => $code, 'name' => $name, 'kind' => $kind, 'description' => $description, 'unit' => $unit, 'unit_price' => $price, 'income_account_id' => $income, 'expense_account_id' => $expense, 'tax_rate_id' => $tax];
$newId = gl_guard($pdo, static function () use ($pdo, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_item($pdo, $id, $f);
    $after = item_log_state(find_item($pdo, $newId));
    if ($cur === null) {
        log_activity($pdo, 'item.save', 'item', $newId, ['after' => $after]);
    } else {
        $d = gl_diff(item_log_state($cur), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'item.save', 'item', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
gl_done(($id === null ? 'Added the item ' : 'Saved the item ') . $f['name'], $newId, gl_land(return_path('/settings/items/'), 'saved', 'item-card-' . $newId), 'itemChanged', ['item_id' => $newId]);
