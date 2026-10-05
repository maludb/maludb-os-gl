<?php
declare(strict_types=1);
/** /settings/banks/ — the bank accounts' settings (screen `bank-settings`; settings.manage): each with its ledger account, currency, opening balance, the Add button. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/cash/handler.php';
require_right('settings.manage');
$pdo = db();
$banks = find_bank_accounts($pdo, true);
log_screen_view($pdo, 'bank-settings');
if (wants_json()) {
    respond_screen(['bank_accounts' => array_map('present_bank_account', $banks), 'kinds' => array_keys(BANK_KINDS)]);
}
render_screen('Bank accounts (settings)', view('settings/banks.php', ['banks' => $banks, 'here' => here_url(), 'notice' => gl_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.'], 'archived' => ['success', 'The bank account was archived.'], 'restored' => ['success', 'The bank account was restored.']])]),
    ['activeNav' => 'bank-settings', 'screen' => 'bank-settings', 'entity' => 'bankAccount']);
