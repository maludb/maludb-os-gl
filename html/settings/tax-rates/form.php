<?php
declare(strict_types=1);
/** /settings/tax-rates/new and /{id}/edit — a rate (screens `tax-rate-add`, `tax-rate-edit`; settings.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('tax_rate');
$cur = $id === null ? null : (find_tax_rate($pdo, $id) ?? refuse(404, 'Tax rate not found.'));
$screen = $cur === null ? 'tax-rate-add' : 'tax-rate-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['tax_rate' => $cur === null ? null : present_tax_rate($cur)]);
}
render_screen($cur === null ? 'Add a tax rate' : 'Change ' . $cur['name'], view('settings/tax-rate-form.php', ['cur' => $cur]), ['activeNav' => 'tax-rates', 'screen' => $screen, 'entity' => 'taxRate', 'recordId' => $id === null ? '' : (string) $id]);
