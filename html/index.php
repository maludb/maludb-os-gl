<?php
declare(strict_types=1);
/** / — the dashboard (screen `dashboard`): the cash position, receivables and payables, what waits, the AI bill, posted-through, the morning note. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_once dirname(__DIR__) . '/app/views/shared/money.php';
require_login();
require_human();
require_right('reports.view');
$pdo = db();
log_screen_view($pdo, 'dashboard');
$home = home_summary($pdo);
if (wants_json()) {
    respond_screen(present_home($home));
}
render_screen('Home', view('home/dashboard.php', ['h' => $home, 'tz' => member_timezone(), 'may' => ['journal' => has_right('journal.write'), 'bank' => has_right('bank.write'), 'ap' => has_right('ap.write'), 'ar' => has_right('ar.write'), 'admin' => has_right('settings.manage')]]),
    ['activeNav' => 'home', 'screen' => 'dashboard']);
