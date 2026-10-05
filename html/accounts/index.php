<?php
declare(strict_types=1);
/** /accounts/ — the chart of accounts as cards grouped by type, balances as of today (screen `accounts-list`; reports.view). Params: type, include_archived, as_of, q. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/present.php';
require_right('reports.view');
$pdo = db();
$asOf = request_date('as_of');
if ($asOf === false) {
    refuse(422, 'The as-of date is YYYY-MM-DD.');
}
$filters = ['type' => request_string('type'), 'include_archived' => request_bool('include_archived'), 'as_of' => $asOf ?? date('Y-m-d'), 'q' => request_string('q')];
$rows = find_accounts($pdo, $filters);
$currency = (string) $pdo->query('SELECT gl_base_currency()')->fetchColumn();
$may = ['manage' => has_right('settings.manage')];
log_screen_view($pdo, 'accounts-list');
if (wants_json()) {
    respond_screen(['accounts' => array_map('present_account', $rows), 'as_of' => $filters['as_of'], 'currency' => $currency, 'filters' => $filters, 'may' => $may]);
}
$groups = [];
foreach (ACCOUNT_TYPES as $t => $label) {
    $groups[$t] = ['label' => $label, 'rows' => array_values(array_filter($rows, static fn (array $a): bool => $a['account_type'] === $t))];
}
$page = view('accounts/list.php', ['groups' => $groups, 'filters' => $filters, 'currency' => $currency, 'may' => $may, 'here' => here_url(),
    'notice' => gl_notice($_GET['notice'] ?? null, ['archived' => ['success', 'The account was archived.'], 'restored' => ['success', 'The account was restored.']])]);
if (is_htmx_request() && !is_htmx_boosted() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'accounts-results') {
    echo view('accounts/partials/groups.php', ['groups' => $groups, 'filters' => $filters, 'currency' => $currency, 'may' => $may, 'here' => here_url()]);
    exit;
}
render_screen('Chart of accounts', $page, ['activeNav' => 'accounts', 'screen' => 'accounts-list', 'entity' => 'account']);
