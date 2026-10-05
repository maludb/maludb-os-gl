<?php
declare(strict_types=1);
/** /settings/items/new and /{id}/edit — an item (screens `item-add`, `item-edit`; settings.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
require_once dirname(__DIR__, 3) . '/app/views/shared/account-select.php';
require_right('settings.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('item');
$cur = $id === null ? null : (find_item($pdo, $id) ?? refuse(404, 'Item not found.'));
$screen = $cur === null ? 'item-add' : 'item-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['item' => $cur === null ? null : present_item($cur), 'kinds' => ITEM_KINDS, 'tax_rates' => array_map('present_tax_rate', find_tax_rates($pdo)), 'income_accounts' => accounts_for_select($pdo, ['income']), 'expense_accounts' => accounts_for_select($pdo, ['expense'])]);
}
render_screen($cur === null ? 'Add an item' : 'Change ' . $cur['name'], view('settings/item-form.php', ['cur' => $cur, 'pdo' => $pdo, 'taxRates' => find_tax_rates($pdo)]), ['activeNav' => 'items', 'screen' => $screen, 'entity' => 'item', 'recordId' => $id === null ? '' : (string) $id]);
