<?php
declare(strict_types=1);
/** Action `bank_account_archive` (log `bank_account.archive`; confirm): settings.manage. `archived` yes archives (refused with an unmatched line or a reconciliation in progress), no restores. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/cash/handler.php';
gl_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = req_id('bank_account', 'bank_account_id') ?? refuse(422, 'Say which bank account.');
$archived = gl_yes('archived', true);
try {
    $pdo->beginTransaction();
    archive_bank_account($pdo, $id, $archived);
    $b = bank_account_state($pdo, $id);
    log_activity($pdo, 'bank_account.archive', 'bank_account', $id, bank_keys($id) + ['before' => ['archived' => !$archived], 'after' => ['archived' => $archived, 'name' => $b['name']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    cash_refused($pdo, $ex);
}
gl_done(($archived ? 'Archived ' : 'Restored ') . $b['name'], $id, gl_land(return_path('/settings/banks/'), $archived ? 'archived' : 'restored'), 'bankChanged');
