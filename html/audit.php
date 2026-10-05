<?php
declare(strict_types=1);
/** /audit — placeholder (screen `audit`): built by slice 9 (audit and exports). render_nav_stub() answers 200 after the right, 501 to JSON and to a POST; the registry builder reads it as unbuilt. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
render_nav_stub('audit', 'slice 9 (audit and exports)', 'periods.manage|journal.override');
