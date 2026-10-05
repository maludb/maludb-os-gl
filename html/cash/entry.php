<?php
declare(strict_types=1);
/** Action `bank_entry_from_line` (bank_line, account — the fee or income account, memo, department, site; confirm; money_out for an agent; log `bank.entry_from_line` with journal_entry_number, account_code, amount, journal_entry_id, bank_account_id): journal.write. gl_bank_entry_from_line() posts the entry and matches the line at once. A control account is refused. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/cash/handler.php';
gl_handler_begin();
require_right('journal.write');
$pdo = db();
$lineId = req_id('bank_line', 'bank_transaction', 'line', 'bank_transaction_id') ?? refuse(422, 'Say which bank line.');
$line = find_bank_line($pdo, $lineId) ?? refuse(404, 'Bank line not found.');
$errors = [];
$account = gl_ref($pdo, 'account', null, 'SELECT 1 FROM accounts WHERE id = :id AND archived_at IS NULL', 'account', $errors, false);
if (!isset($errors['account']) && $account === null) {
    $errors['account'] = 'Choose the fee or income account.';
}
$dept = gl_ref($pdo, req_has('department') ? 'department' : 'department_id', null, 'SELECT 1 FROM departments WHERE id = :id AND archived_at IS NULL', 'department', $errors);
$site = gl_ref($pdo, req_has('site') ? 'site' : 'site_id', null, 'SELECT 1 FROM sites WHERE id = :id AND archived_at IS NULL', 'site', $errors);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$memo = req_has('memo') && trim((string) req_val('memo')) !== '' ? mb_substr(trim((string) req_val('memo')), 0, 300) : null;
try {
    $pdo->beginTransaction();
    $r = entry_from_line($pdo, $lineId, (int) $account, $memo, $dept, $site, (int) current_member_id());
    log_activity($pdo, 'bank.entry_from_line', 'bank_line', $lineId, bank_keys($line['bank_account_id'], $r['journal_entry_id']) + ['after' => ['bank_transaction_id' => $lineId, 'journal_entry_number' => $r['journal_entry_number'], 'account_code' => $r['account_code'], 'amount' => $r['amount']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    cash_refused($pdo, $ex);
}
$did = 'Posted ' . $r['journal_entry_number'] . ' (' . $r['amount'] . ' against ' . $r['account_code'] . ') and matched the bank line';
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'bank-line-' . $lineId) {
    emit_action_status(true, ['did' => $did, 'record_id' => $lineId]);
    hx_trigger('bankChanged');
    echo view('cash/partials/bank-figures.php', ['bank' => find_bank_account($pdo, $line['bank_account_id']), 'oob' => true, 'unmatched' => bank_line_counts($pdo, $line['bank_account_id'])['unmatched']]);
    exit;
}
gl_done($did, $lineId, gl_land(return_path('/cash/match?bank_account=' . $line['bank_account_id']), 'matched'), 'bankChanged', ['bank_transaction_id' => $lineId, 'journal_entry_id' => $r['journal_entry_id'], 'journal_entry_number' => $r['journal_entry_number']]);
