<?php
declare(strict_types=1);
/** /settings/banks/new and /settings/banks/{id}/edit — a bank account (screens `bank-account-add`, `bank-account-edit`; settings.manage). The page explains the opening entry and links the entry form prefilled. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/cash/handler.php';
require_right('settings.manage');
require_human();
$pdo = db();
$id = req_id('id', 'bank_account');
$cur = null;
if ($id !== null) {
    $cur = bank_account_state($pdo, $id) ?? refuse(404, 'Bank account not found.');
}
$docs = $cur === null ? null : bank_account_documents($pdo, $id);
$screen = $cur === null ? 'bank-account-add' : 'bank-account-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['bank_account' => $cur === null ? null : bank_account_log_state($cur) + ['bank_account_id' => $cur['bank_account_id']], 'opening_fixed' => $docs !== null && $docs['completed'] > 0, 'kinds' => array_keys(BANK_KINDS), 'bank_ledger_accounts' => accounts_for_select($pdo, [], ['is_bank'])]);
}
render_screen($cur === null ? 'Add a bank account' : 'Change ' . $cur['name'], view('settings/bank-form.php', ['cur' => $cur, 'docs' => $docs, 'pdo' => $pdo, 'base' => base_currency_code($pdo)]),
    ['activeNav' => 'bank-settings', 'screen' => $screen, 'entity' => 'bankAccount', 'recordId' => $id === null ? '' : (string) $id]);
