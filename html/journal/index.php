<?php
declare(strict_types=1);
/** /journal/ — placeholder (screen `journal`): built by slice 2 (journal core). render_nav_stub() answers 200 after the right, 501 to JSON and to a POST; the registry builder reads it as unbuilt. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('journal', 'slice 2 (journal core)', 'documents.view');
