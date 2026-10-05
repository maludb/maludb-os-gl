<?php
declare(strict_types=1);
/** Action `bank_transaction_unmatch` (bank_line, not reconciled; log `bank.unmatch`): bank.write. gl_bank_unmatch() refuses a reconciled line ("reopen the reconciliation first"); an ignored line is freed too. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/cash/handler.php';
gl_handler_begin();
require_right('bank.write');
$pdo = db();
$lineId = req_id('bank_line', 'bank_transaction', 'line', 'bank_transaction_id') ?? refuse(422, 'Say which bank line.');
$line = find_bank_line($pdo, $lineId) ?? refuse(404, 'Bank line not found.');
try {
    $pdo->beginTransaction();
    $b = unmatch_line($pdo, $lineId);
    log_activity($pdo, 'bank.unmatch', 'bank_line', $lineId, bank_keys($line['bank_account_id']) + ['before' => ['status' => $b['status'], 'item_type' => $b['matched_type'], 'item_id' => $b['matched_id']], 'after' => ['bank_transaction_id' => $lineId, 'amount' => $b['amount']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    cash_refused($pdo, $ex);
}
gl_done('Freed the bank line of ' . $b['amount'], $lineId, gl_land(return_path('/cash/banks/' . $line['bank_account_id']), 'unmatched'), 'bankChanged', ['bank_transaction_id' => $lineId]);
