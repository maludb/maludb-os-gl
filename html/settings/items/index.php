<?php
declare(strict_types=1);
/** /settings/items/ — products and services as cards (screen `items-list`; settings.manage). Params: q, kind, include_archived. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
require_right('settings.manage');
$pdo = db();
$filters = ['q' => request_string('q'), 'kind' => request_string('kind'), 'include_archived' => request_bool('include_archived')];
$rows = find_items($pdo, $filters);
$currency = (string) $pdo->query('SELECT gl_base_currency()')->fetchColumn();
log_screen_view($pdo, 'items-list');
if (wants_json()) {
    respond_screen(['items' => array_map('present_item', $rows), 'filters' => $filters, 'currency' => $currency]);
}
$data = ['rows' => $rows, 'filters' => $filters, 'currency' => $currency, 'here' => here_url(), 'notice' => gl_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved the item.'], 'archived' => ['success', 'The item was archived.'], 'restored' => ['success', 'The item was restored.']])];
if (is_htmx_request() && !is_htmx_boosted() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'items-results') {
    echo view('settings/partials/item-cards.php', $data);
    exit;
}
render_screen('Items', view('settings/items.php', $data), ['activeNav' => 'items', 'screen' => 'items-list', 'entity' => 'item']);
