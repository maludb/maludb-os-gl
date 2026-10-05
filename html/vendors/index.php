<?php
declare(strict_types=1);
/** /vendors/ — the vendors as cards with balance due and open bills; AI providers marked (screen `vendors-list`; parties.view). Params: q, kind, include_archived. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
require_right('parties.view');
$pdo = db();
$filters = ['q' => request_string('q'), 'kind' => request_string('kind'), 'include_archived' => request_bool('include_archived')];
$rows = find_vendors($pdo, $filters);
$may = ['write' => has_right('ap.write')];
log_screen_view($pdo, 'vendors-list');
if (wants_json()) {
    respond_screen(['vendors' => array_map('present_vendor', $rows), 'filters' => $filters, 'may' => $may]);
}
$data = ['rows' => $rows, 'filters' => $filters, 'may' => $may, 'here' => here_url(), 'notice' => gl_notice($_GET['notice'] ?? null, ['archived' => ['success', 'The vendor was archived.'], 'restored' => ['success', 'The vendor was restored.']])];
if (is_htmx_request() && !is_htmx_boosted() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'vendors-results') {
    echo view('vendors/partials/cards.php', $data);
    exit;
}
render_screen('Vendors', view('vendors/list.php', $data), ['activeNav' => 'vendors', 'screen' => 'vendors-list', 'entity' => 'vendor']);
