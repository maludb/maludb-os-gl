<?php
declare(strict_types=1);
/** /accounts/new and /accounts/{id}/edit — add or change an account (screens `account-add`, `account-edit`; settings.manage). Params on add: type, parent. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('account');
$cur = null;
if ($id !== null) {
    $cur = account_state($pdo, $id) ?? refuse(404, 'Account not found.');
}
$type = $cur['account_type'] ?? (isset(ACCOUNT_TYPES[request_string('type')]) ? request_string('type') : 'asset');
$parent = $cur['parent_id'] ?? request_integer('parent');
$data = ['cur' => $cur, 'type' => $type, 'parent' => $parent, 'parents' => array_values(array_filter(accounts_for_select($pdo), static fn (array $a): bool => $a['account_id'] !== $id)), 'here' => here_url()];
$screen = $cur === null ? 'account-add' : 'account-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['account' => $cur === null ? null : account_log_state($cur) + ['account_id' => $cur['account_id'], 'is_control' => $cur['is_control'], 'is_system' => $cur['is_system'], 'archived' => $cur['archived_at'] !== null],
        'types' => ACCOUNT_TYPES, 'subtypes' => ACCOUNT_SUBTYPES, 'parents' => $data['parents']]);
}
render_screen($cur === null ? 'Add an account' : 'Change ' . $cur['code'] . ' ' . $cur['name'], view('accounts/form.php', $data), ['activeNav' => 'accounts', 'screen' => $screen, 'entity' => 'account', 'recordId' => $id === null ? '' : (string) $id]);
