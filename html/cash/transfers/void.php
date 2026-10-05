<?php
declare(strict_types=1);
/** Action `transfer_void` (transfer unmatched, reason; confirm; money_out for an agent; log `transfer.void`): journal.override. gl_transfer_void() reverses the entry; refused while matched to a bank line. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/cash/handler.php';
gl_handler_begin();
require_right('journal.override');
$pdo = db();
$id = req_id('transfer', 'transfer_id') ?? refuse(422, 'Say which transfer.');
$vis = $pdo->prepare('SELECT 1 FROM mcp_transfers WHERE transfer_id = :i');
$vis->execute(['i' => $id]);
$vis->fetchColumn() !== false || refuse(404, 'Transfer not found.');
$reason = mb_substr(trim((string) req_val('reason')), 0, 500);
try {
    $pdo->beginTransaction();
    $t = void_transfer($pdo, $id, (int) current_member_id(), $reason);
    log_activity($pdo, 'transfer.void', 'transfer', $id, bank_keys($t['from_bank_account_id'], $t['journal_entry_id']) + ['after' => ['number' => $t['number'], 'reason' => $reason, 'amount' => $t['amount'], 'currency' => $t['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    cash_refused($pdo, $ex);
}
gl_done('Voided ' . $t['number'], $id, gl_land(return_path('/cash/transfers/' . $id), 'voided'), 'bankChanged', ['transfer_id' => $id]);
