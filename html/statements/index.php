<?php
declare(strict_types=1);
/** /statements/ — the four statements as cards with the current period's headline figures, under the posted-through line (screen `statements`; reports.view). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/statements/periods.php';
require_once dirname(__DIR__, 2) . '/app/features/statements/queries.php';
require_right('reports.view');
$pdo = db();
$range = period_range($pdo, []);
$currency = base_currency($pdo);
$ni = $pdo->prepare('SELECT gl_net_income(:f, :t)');
$ni->execute(['f' => $range['from'], 't' => $range['to']]);
$bs = balance_sheet($pdo, $range['as_of'], null);
$tb = trial_balance($pdo, $range['as_of']);
$cf = cash_flow($pdo, $range['from'], $range['to']);
$pt = posted_through($pdo);
$cards = [
    ['key' => 'pl', 'title' => 'Profit and loss', 'icon' => 'feather-trending-up', 'figure' => (string) $ni->fetchColumn(), 'label' => 'Net income, ' . $range['label'], 'url' => '/statements/pl', 'state' => null],
    ['key' => 'balance-sheet', 'title' => 'Balance sheet', 'icon' => 'feather-layers', 'figure' => $bs['totals']['asset']['balance'], 'label' => 'Assets as of ' . format_date($range['as_of']), 'url' => '/statements/balance-sheet', 'state' => ['ok' => $bs['holds'], 'text' => $bs['holds'] ? 'The equation holds' : 'The equation does not hold']],
    ['key' => 'trial-balance', 'title' => 'Trial balance', 'icon' => 'feather-columns', 'figure' => $tb['debit'], 'label' => 'Debits as of ' . format_date($range['as_of']), 'url' => '/statements/trial-balance', 'state' => ['ok' => $tb['balanced'], 'text' => $tb['balanced'] ? 'Balanced' : 'Out of balance']],
    ['key' => 'cash-flow', 'title' => 'Cash flow', 'icon' => 'feather-activity', 'figure' => $cf['net_change'], 'label' => 'Net change in cash, ' . $range['label'], 'url' => '/statements/cash-flow', 'state' => ['ok' => m_cmp((string) $cf['check'], '0') === 0, 'text' => m_cmp((string) $cf['check'], '0') === 0 ? 'Ties to the bank' : 'Off by ' . $cf['check']]],
];
log_screen_view($pdo, 'statements');
if (wants_json()) {
    respond_screen(['period' => $range, 'posted_through' => $pt, 'currency' => $currency, 'statements' => array_map(static fn (array $c): array => ['statement' => $c['key'], 'title' => $c['title'], 'figure' => $c['figure'], 'label' => $c['label'], 'ok' => $c['state']['ok'] ?? null, 'url' => $c['url']], $cards)]);
}
render_screen('Statements', view('statements/index.php', ['cards' => $cards, 'range' => $range, 'pt' => $pt, 'currency' => $currency, 'mixed' => array_values(array_diff(books_currencies($pdo), [$currency]))]),
    ['activeNav' => 'statements', 'screen' => 'statements', 'entity' => 'statement']);
