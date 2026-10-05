<?php
declare(strict_types=1);
/** /journal/ — the entries as a ledger: drafts first, then by date (screen `journal-list`; documents.view). Params: period, status, source, account, from, to, q, sort, dir, page; format=csv (exports.download, logged). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/present.php';
require_once dirname(__DIR__, 2) . '/app/views/shared/account-select.php';
require_right('documents.view');
$pdo = db();
$from = request_date('from'); $to = request_date('to');
if ($from === false || $to === false) {
    refuse(422, 'A date is YYYY-MM-DD.');
}
$filters = ['period' => request_integer('period'), 'status' => request_string('status'), 'source' => request_string('source'), 'account' => request_integer('account'), 'from' => $from ?? '', 'to' => $to ?? '',
            'q' => request_string('q'), 'sort' => request_string('sort'), 'dir' => request_string('dir')];
$page = max(1, request_integer('page') ?? 1);
$total = count_entries($pdo, $filters);
$rows = find_entries($pdo, $filters, ENTRY_PAGE, ($page - 1) * ENTRY_PAGE);
$totals = entry_totals($pdo, $filters);
$may = ['write' => has_right('journal.write'), 'export' => has_right('exports.download')];
$balancedDrafts = array_values(array_map(static fn (array $e): int => $e['journal_entry_id'], array_filter($rows, static fn (array $e): bool => $e['status'] === 'draft' && $e['balances'])));
if (request_string('format') === 'csv') {
    require_right('exports.download');
    $all = find_entries($pdo, $filters, 10000, 0);
    log_activity($pdo, 'export.download', 'journal', null, ['after' => ['format' => 'csv', 'rows' => count($all), 'filters' => array_filter($filters, static fn ($v) => $v !== '' && $v !== null)]]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=\"journal.csv\"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['number', 'date', 'period', 'status', 'source', 'memo', 'currency', 'total', 'created_by']);
    foreach ($all as $e) {
        fputcsv($out, [$e['number'], $e['entry_date'], $e['period_name'], $e['status'], $e['source_label'], $e['memo'], $e['currency'], $e['total'], $e['created_by_name']]);
    }
    fclose($out);
    exit;
}
log_screen_view($pdo, 'journal-list');
if (wants_json()) {
    respond_screen(['entries' => array_map('present_entry', $rows), 'total' => $total, 'page' => $page, 'per_page' => ENTRY_PAGE, 'totals' => $totals, 'filters' => $filters, 'balanced_drafts' => $balancedDrafts, 'may' => $may]);
}
$data = ['rows' => $rows, 'total' => $total, 'page' => $page, 'totals' => $totals, 'filters' => $filters, 'may' => $may, 'balancedDrafts' => $balancedDrafts, 'periods' => periods_for_select($pdo), 'pdo' => $pdo, 'here' => here_url(),
         'notice' => gl_notice($_GET['notice'] ?? null, ['voided' => ['success', 'The draft was voided.'], 'posted_batch' => ['success', 'The balanced drafts were posted; the refusals are in the flash.']])];
if (is_htmx_request() && !is_htmx_boosted() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'entry-list') {
    echo view('journal/partials/entry-list.php', $data);
    exit;
}
render_screen('Journal', view('journal/list.php', $data), ['activeNav' => 'journal', 'screen' => 'journal-list', 'entity' => 'journalEntry']);
