<?php
declare(strict_types=1);
/** /settings/tax-rates/ — the tax rates (screen `tax-rates`; settings.manage). Param: include_archived. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
require_right('settings.manage');
$pdo = db();
$rows = find_tax_rates($pdo, request_bool('include_archived'));
log_screen_view($pdo, 'tax-rates');
if (wants_json()) {
    respond_screen(['tax_rates' => array_map('present_tax_rate', $rows)]);
}
render_screen('Tax rates', view('settings/tax-rates.php', ['rows' => $rows, 'includeArchived' => request_bool('include_archived'), 'here' => here_url(),
    'notice' => gl_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved the rate.'], 'archived' => ['success', 'The rate was archived.'], 'restored' => ['success', 'The rate was restored.']])]),
    ['activeNav' => 'tax-rates', 'screen' => 'tax-rates', 'entity' => 'taxRate']);
