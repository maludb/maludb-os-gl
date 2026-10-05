<?php
declare(strict_types=1);

/** The recurring slice's prelude (slice 8): the payables prelude (bills) plus the journal's writes, the notifications and the recurring files. */
require_once dirname(__DIR__) . '/bills/handler.php';
require_once dirname(__DIR__) . '/journal/queries.php';
require_once dirname(__DIR__) . '/journal/write.php';
require_once dirname(__DIR__) . '/notify/write.php';
require_once dirname(__DIR__) . '/recurring/templates.php';
require_once dirname(__DIR__) . '/recurring/queries.php';
require_once dirname(__DIR__) . '/recurring/write.php';
require_once dirname(__DIR__) . '/recurring/present.php';
require_once dirname(__DIR__, 2) . '/views/shared/customer-select.php';
require_once dirname(__DIR__, 2) . '/views/shared/account-select.php';
require_once dirname(__DIR__) . '/invoices/queries.php';
require_once dirname(__DIR__) . '/invoices/write.php';
