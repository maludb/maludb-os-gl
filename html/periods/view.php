<?php
declare(strict_types=1);
/** /periods/{id} — one period: its state, history, what posted, what is still a draft, the close checklist (screen `period-view`; reports.view). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/periods/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/periods/present.php';
require_right('reports.view');
$pdo = db();
$id = request_integer('id') ?? request_integer('period') ?? refuse(404, 'Period not found.');
$p = find_period($pdo, $id) ?? refuse(404, 'Period not found.');
$year = find_fiscal_year($pdo, $p['fiscal_year_id']);
$summary = period_summary($pdo, $id);
$checklist = period_checklist($pdo, $id);
$history = period_history($pdo, $id);
$currency = (string) $pdo->query('SELECT gl_base_currency()')->fetchColumn();
$may = ['periods' => has_right('periods.manage')];
$yearClosable = $p['is_last_of_year'] && $year !== null && $year['status'] === 'open' && $year['open_periods'] === 0;
log_screen_view($pdo, 'period-view');
if (wants_json()) {
    respond_screen(['period' => present_period($p), 'fiscal_year' => $year === null ? null : present_fiscal_year($year), 'summary' => $summary, 'checklist' => $checklist, 'history' => $history, 'currency' => $currency, 'may' => $may, 'year_closable' => $yearClosable]);
}
$notices = ['closed' => ['success', 'The period is closed.'], 'reopened' => ['warning', 'The period is open again.'], 'year_closed' => ['success', 'The year is closed.'], 'year_reopened' => ['warning', 'The year is open again; its closing entry was reversed.']];
$link = null;
if (($_GET['notice'] ?? '') === 'year_closed' && $year !== null && $year['closing_entry_id'] !== null) {
    $link = ['/journal/' . $year['closing_entry_id'], 'Closing entry ' . ($year['closing_entry_number'] ?? '#' . $year['closing_entry_id'])];
}
render_screen('Period ' . $p['name'], view('periods/view.php', ['p' => $p, 'year' => $year, 'summary' => $summary, 'checklist' => $checklist, 'history' => $history, 'currency' => $currency, 'may' => $may, 'yearClosable' => $yearClosable,
    'here' => here_url(), 'tz' => member_timezone(), 'notice' => gl_notice($_GET['notice'] ?? null, $notices), 'link' => $link]), ['activeNav' => 'periods', 'screen' => 'period-view', 'entity' => 'period', 'recordId' => (string) $id]);
