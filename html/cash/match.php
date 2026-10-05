<?php
declare(strict_types=1);
/**
 * GET /cash/match — the unmatched lines of a bank account oldest first, each a card with its candidates, one click to match, an entry for a fee, ignore (screen `bank-match`; bank.write). Params: bank_account (default: the first with unmatched lines), from, to, auto=1 (the page clicks every sure match itself).
 * POST — action `bank_transaction_match` (bank_line, item_type, item; log `bank.match` with bank_transaction_id, item_type, item_id, amount and bank_account_id): bank.write. gl_bank_match() holds the to-the-cent rule. Under HTMX the card is swapped out and the figures are re-rendered.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/cash/handler.php';
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($post) {
    gl_handler_begin();
    require_right('bank.write');
    $pdo = db();
    $lineId = req_id('bank_line', 'bank_transaction', 'line', 'bank_transaction_id') ?? refuse(422, 'Say which bank line.');
    $line = find_bank_line($pdo, $lineId) ?? refuse(404, 'Bank line not found.');
    $type = (string) req_val('item_type');
    $item = req_id('item', 'item_id') ?? refuse(422, 'Say which ledger item.');
    try {
        $pdo->beginTransaction();
        $r = match_line($pdo, $lineId, $type, $item, (int) current_member_id());
        log_activity($pdo, 'bank.match', 'bank_line', $lineId, bank_keys($line['bank_account_id']) + ['after' => ['bank_transaction_id' => $lineId, 'item_type' => $type, 'item_id' => $item, 'amount' => $r['amount'], 'currency' => (string) ($pdo->query('SELECT currency FROM bank_accounts WHERE id = ' . (int) $line['bank_account_id'])->fetchColumn() ?: '')]]);
        $pdo->commit();
    } catch (Throwable $ex) {
        cash_refused($pdo, $ex);
    }
    $did = 'Matched the bank line of ' . $r['amount'] . ' to ' . (MATCH_TYPES[$type] ?? $type) . ' #' . $item;
    if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'bank-line-' . $lineId) {
        emit_action_status(true, ['did' => $did, 'record_id' => $lineId]);
        hx_trigger('bankChanged');
        echo view('cash/partials/bank-figures.php', ['bank' => find_bank_account($pdo, $line['bank_account_id']), 'oob' => true, 'unmatched' => bank_line_counts($pdo, $line['bank_account_id'])['unmatched']]);
        exit;
    }
    gl_done($did, $lineId, gl_land(return_path('/cash/match?bank_account=' . $line['bank_account_id']), 'matched'), 'bankChanged', ['bank_transaction_id' => $lineId, 'item_type' => $type, 'item_id' => $item, 'amount' => $r['amount']]);
}
require_login();
require_right('bank.write');
$pdo = db();
$banks = bank_accounts_for_select($pdo);
$bankId = request_integer('bank_account');
if ($bankId === null) {
    $first = $pdo->query("SELECT bank_account_id FROM mcp_bank_transactions WHERE status = 'unmatched' ORDER BY bank_account_id LIMIT 1")->fetchColumn();
    $bankId = $first !== false ? (int) $first : ($banks[0]['bank_account_id'] ?? null);
}
$bank = $bankId === null ? null : (find_bank_account($pdo, $bankId) ?? refuse(404, 'Bank account not found.'));
$from = request_date('from'); $to = request_date('to');
if ($from === false || $to === false) {
    refuse(422, 'A date is YYYY-MM-DD.');
}
$filters = ['status' => 'unmatched', 'from' => $from ?? '', 'to' => $to ?? ''];
$lines = $bank === null ? [] : find_bank_lines($pdo, $bankId, $filters, 50, 0, true);
$cards = [];
foreach ($lines as $l) {
    $cards[] = ['line' => $l, 'candidates' => line_candidates($pdo, $l['bank_transaction_id'])];
}
$more = $bank === null ? 0 : max(0, count_bank_lines($pdo, $bankId, $filters) - count($lines));
$may = ['write' => true, 'entry' => has_right('journal.write')];
log_screen_view($pdo, 'bank-match');
if (wants_json()) {
    respond_screen(['bank_account' => $bank === null ? null : present_bank_account($bank), 'banks' => $banks, 'lines' => array_map(static fn (array $c): array => present_bank_line($c['line']) + ['candidates' => array_map('present_candidate', $c['candidates'])], $cards), 'more' => $more, 'may' => $may]);
}
render_screen('Match the bank', view('cash/match.php', ['bank' => $bank, 'banks' => $banks, 'cards' => $cards, 'more' => $more, 'filters' => $filters, 'auto' => request_bool('auto'), 'may' => $may, 'pdo' => $pdo, 'here' => here_url(),
    'departments' => find_live_departments($pdo), 'sites' => find_live_sites($pdo), 'notice' => gl_notice($_GET['notice'] ?? null, ['imported' => ['success', 'Imported. Match each line to what the books hold.'], 'saved' => ['success', 'Saved.'], 'matched' => ['success', 'Matched.']])]),
    ['activeNav' => 'match', 'screen' => 'bank-match', 'entity' => 'bankLine']);
