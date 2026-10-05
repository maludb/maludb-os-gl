<?php
declare(strict_types=1);
/** /settings/banks/ — placeholder (screen `bank-settings`): built by slice 6 (cash). render_nav_stub() answers 200 after the right, 501 to JSON and to a POST; the registry builder reads it as unbuilt. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
render_nav_stub('bank-settings', 'slice 6 (cash)', 'settings.manage');
