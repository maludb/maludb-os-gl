<?php
declare(strict_types=1);
/** /accounts/{id} — one account: its balance, activity with a running balance, its children (screen `account-view`; reports.view). Params: from, to, department, site; format=csv (logged export.download). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/periods/queries.php';
require_right('reports.view');
$pdo = db();
$id = request_integer('id') ?? request_integer('account') ?? refuse(404, 'Account not found.');
$a = find_account($pdo, $id) ?? refuse(404, 'Account not found.');
$fy = fiscal_year_for($pdo);
$from = request_date('from');
$to = request_date('to');
if ($from === false || $to === false) {
    refuse(422, 'A date is YYYY-MM-DD.');
}
$from ??= $fy['start_date'] ?? date('Y-01-01');
$to ??= date('Y-m-d');
if ($from > $to) {
    refuse(422, 'The range ends before it starts.');
}
$dept = request_integer('department');
$site = request_integer('site');
$activity = account_activity($pdo, $id, $from, $to, $dept, $site);
$children = array_values(array_filter(find_accounts($pdo, ['include_archived' => true]), static fn (array $c): bool => $c['parent_id'] === $id));
$currency = (string) $pdo->query('SELECT gl_base_currency()')->fetchColumn();
$may = ['manage' => has_right('settings.manage'), 'export' => has_right('exports.download')];
$bank = null;
if ($a['is_bank']) {
    $st = $pdo->prepare('SELECT id, name FROM bank_accounts WHERE account_id = :a AND archived_at IS NULL ORDER BY id LIMIT 1');
    $st->execute(['a' => $id]);
    $bank = $st->fetch() ?: null;
}
if (request_string('format') === 'csv') {
    require_right('exports.download');
    log_activity($pdo, 'export.download', 'account', $id, ['after' => ['format' => 'csv', 'from' => $from, 'to' => $to, 'rows' => count($activity['rows'])]]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="account-' . $a['code'] . '-' . $from . '-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['date', 'entry', 'source', 'memo', 'description', 'debit', 'credit', 'balance']);
    fputcsv($out, [$from, '', 'opening', 'Opening balance', '', '', '', $activity['opening']]);
    foreach ($activity['rows'] as $r) {
        fputcsv($out, [$r['entry_date'], $r['entry_number'], $r['source'], $r['memo'], $r['description'], $r['debit'], $r['credit'], $r['running_balance']]);
    }
    fclose($out);
    exit;
}
log_screen_view($pdo, 'account-view');
if (wants_json()) {
    respond_screen(['account' => present_account($a), 'range' => ['from' => $from, 'to' => $to, 'department' => $dept, 'site' => $site], 'opening_balance' => $activity['opening'],
        'activity' => array_map('present_account_activity', $activity['rows']), 'children' => array_map('present_account', $children), 'currency' => $currency, 'bank_account' => $bank === null ? null : ['bank_account_id' => (int) $bank['id'], 'name' => $bank['name']], 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved the account.'], 'archived' => ['success', 'The account was archived.'], 'restored' => ['success', 'The account was restored.']];
$data = ['a' => $a, 'range' => ['from' => $from, 'to' => $to, 'department' => $dept, 'site' => $site], 'activity' => $activity, 'children' => $children, 'currency' => $currency, 'bank' => $bank, 'may' => $may,
         'departments' => find_live_departments($pdo), 'sites' => find_live_sites($pdo), 'here' => here_url(), 'notice' => gl_notice($_GET['notice'] ?? null, $notices)];
if (is_htmx_request() && !is_htmx_boosted() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'account-activity') {
    echo view('accounts/activity-table.php', $data);
    exit;
}
render_screen($a['code'] . ' ' . $a['name'], view('accounts/view.php', $data), ['activeNav' => 'accounts', 'screen' => 'account-view', 'entity' => 'account', 'recordId' => (string) $id]);
