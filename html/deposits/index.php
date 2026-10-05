<?php
declare(strict_types=1);
/** /deposits/ — placeholder (screen `deposits`): built by slice 4 (receivables). render_nav_stub() answers 200 after the right, 501 to JSON and to a POST; the registry builder reads it as unbuilt. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('deposits', 'slice 4 (receivables)', 'documents.view');
