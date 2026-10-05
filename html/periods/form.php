<?php
declare(strict_types=1);
/** /periods/new — open a fiscal year (screen `fiscal-year-add`; settings.manage). Param: start (prefilled from the settings' start month after the last year). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/periods/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_right('settings.manage');
require_human();
$pdo = db();
$years = find_fiscal_years($pdo);
$start = request_date('start') ?: null;
if ($start === null) {
    if ($years !== []) {
        $start = date('Y-m-d', strtotime($years[0]['end_date'] . ' +1 day'));
    } else {
        $m = find_settings($pdo)['fiscal_year_start_month'];
        $y = (int) date('Y');
        $start = sprintf('%04d-%02d-01', (int) date('n') < $m ? $y - 1 : $y, $m);
    }
}
log_screen_view($pdo, 'fiscal-year-add');
if (wants_json()) {
    respond_screen(['start' => $start, 'suggested_name' => 'FY' . date('Y', strtotime($start . ' +1 year -1 day'))]);
}
render_screen('Open a fiscal year', view('periods/form.php', ['start' => $start, 'years' => $years]), ['activeNav' => 'periods', 'screen' => 'fiscal-year-add', 'entity' => 'period']);
