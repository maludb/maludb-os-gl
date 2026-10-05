<?php
declare(strict_types=1);
/** /invoices/ — placeholder (screen `invoices`): built by slice 4 (receivables). render_nav_stub() answers 200 after the right, 501 to JSON and to a POST; the registry builder reads it as unbuilt. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('invoices', 'slice 4 (receivables)', 'documents.view');
