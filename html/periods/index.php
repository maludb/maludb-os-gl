<?php
declare(strict_types=1);
/** /periods/ — the fiscal years and their periods as cards (screen `periods-list`; reports.view). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/periods/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/periods/present.php';
require_right('reports.view');
$pdo = db();
$years = find_fiscal_years($pdo);
$may = ['manage' => has_right('settings.manage'), 'periods' => has_right('periods.manage')];
log_screen_view($pdo, 'periods-list');
if (wants_json()) {
    respond_screen(['fiscal_years' => array_map('present_fiscal_year', $years), 'may' => $may]);
}
render_screen('Periods', view('periods/list.php', ['years' => $years, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(),
    'notice' => gl_notice($_GET['notice'] ?? null, ['created' => ['success', 'The fiscal year is open with its twelve periods.'], 'year_closed' => ['success', 'The year is closed.'], 'year_reopened' => ['success', 'The year is open again; its closing entry was reversed.']])]),
    ['activeNav' => 'periods', 'screen' => 'periods-list', 'entity' => 'period']);
