<?php
declare(strict_types=1);
/** /payments/ — placeholder (screen `payments`): built by slice 5 (payables). render_nav_stub() answers 200 after the right, 501 to JSON and to a POST; the registry builder reads it as unbuilt. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('payments', 'slice 5 (payables)', 'documents.view');
