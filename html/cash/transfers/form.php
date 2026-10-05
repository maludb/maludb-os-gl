<?php
declare(strict_types=1);
/** /cash/transfers/new — record a transfer (screen `transfer-add`; bank.write). Params: from, to. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/cash/handler.php';
require_right('bank.write');
require_human();
$pdo = db();
$banks = bank_accounts_for_select($pdo);
log_screen_view($pdo, 'transfer-add');
if (wants_json()) {
    respond_screen(['banks' => $banks, 'from' => request_integer('from'), 'to' => request_integer('to')]);
}
render_screen('Record a transfer', view('cash/transfer-form.php', ['banks' => $banks, 'from' => request_integer('from'), 'to' => request_integer('to'), 'here' => here_url()]), ['activeNav' => 'transfers', 'screen' => 'transfer-add', 'entity' => 'transfer']);
