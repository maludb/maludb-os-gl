<?php
declare(strict_types=1);
/** Action `bank_transaction_ignore` (bank_line, reason; confirm; log `bank.ignore` with the reason): bank.write. An ignored line counts for nothing in the cleared balance; unmatch frees it. Under HTMX the card is swapped out. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/cash/handler.php';
gl_handler_begin();
require_right('bank.write');
$pdo = db();
$lineId = req_id('bank_line', 'bank_transaction', 'line', 'bank_transaction_id') ?? refuse(422, 'Say which bank line.');
$line = find_bank_line($pdo, $lineId) ?? refuse(404, 'Bank line not found.');
$reason = mb_substr(trim((string) req_val('reason')), 0, 500);
try {
    $pdo->beginTransaction();
    $b = ignore_line($pdo, $lineId, $reason, (int) current_member_id());
    log_activity($pdo, 'bank.ignore', 'bank_line', $lineId, bank_keys($line['bank_account_id']) + ['after' => ['bank_transaction_id' => $lineId, 'amount' => $b['amount'], 'reason' => $reason]]);
    $pdo->commit();
} catch (Throwable $ex) {
    cash_refused($pdo, $ex);
}
$did = 'Ignored the bank line of ' . $b['amount'] . ': ' . $reason;
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'bank-line-' . $lineId) {
    emit_action_status(true, ['did' => $did, 'record_id' => $lineId]);
    hx_trigger('bankChanged');
    echo view('cash/partials/bank-figures.php', ['bank' => find_bank_account($pdo, $line['bank_account_id']), 'oob' => true, 'unmatched' => bank_line_counts($pdo, $line['bank_account_id'])['unmatched']]);
    exit;
}
gl_done($did, $lineId, gl_land(return_path('/cash/match?bank_account=' . $line['bank_account_id']), 'saved'), 'bankChanged', ['bank_transaction_id' => $lineId]);
