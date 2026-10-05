<?php
declare(strict_types=1);
/** /accounts/ — placeholder (screen `accounts`): built by slice 1 (the chart and fiscal time). render_nav_stub() answers 200 after the right, 501 to JSON and to a POST; the registry builder reads it as unbuilt. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('accounts', 'slice 1 (the chart and fiscal time)', 'reports.view');
