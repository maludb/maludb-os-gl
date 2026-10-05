<?php
declare(strict_types=1);
/**
 * /exports — every ledger as a file for the accountant's software (screen `exports`; exports.download; no handler). Params: period (a fiscal period or year), from, to, as_of; `file` (one of the twelve) with `format=csv`
 * downloads that file, `format=zip` all twelve. Every download is logged `export.download` (after.what, period, format, rows/files). The page lists the files with their row counts.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__) . '/app/features/statements/periods.php';
require_once dirname(__DIR__) . '/app/features/statements/queries.php';
require_once dirname(__DIR__) . '/app/features/statements/present.php';
require_once dirname(__DIR__) . '/app/features/exports/queries.php';
require_once dirname(__DIR__) . '/app/features/exports/csv.php';
require_once dirname(__DIR__) . '/app/features/exports/zip.php';
require_right('exports.download');
$pdo = db();
$range = period_range($pdo, ['period' => request_string('period'), 'from' => valid_date_param('from') ?? '', 'to' => valid_date_param('to') ?? '', 'as_of' => valid_date_param('as_of') ?? '']);
$format = request_string('format');
$file = request_string('file');
if ($format === 'csv' && $file !== '') {
    if (!isset(EXPORT_FILES[$file])) {
        refuse(404, 'No such file.');
    }
    $csv = export_csv($pdo, $file, $range);
    $rows = in_array($file, EXPORT_FLAT, true) ? max(0, substr_count($csv, "\n") - 1) : null;
    log_activity($pdo, 'export.download', 'export', null, ['after' => ['what' => $file, 'period' => $range['label'], 'format' => 'csv', 'from' => $range['from'], 'to' => $range['to'], 'as_of' => $range['as_of'], 'rows' => $rows]]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . export_filename($file, $range) . '"');
    echo $csv;
    exit;
}
if ($format === 'zip') {
    $path = export_zip($pdo, $range);
    log_activity($pdo, 'export.download', 'export', null, ['after' => ['what' => 'all', 'period' => $range['label'], 'format' => 'zip', 'from' => $range['from'], 'to' => $range['to'], 'as_of' => $range['as_of'], 'files' => count(EXPORT_FILES)]]);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . export_filename('ledger', $range, 'zip') . '"');
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    @unlink($path);
    exit;
}
if ($format !== '') {
    refuse(422, 'The format is csv (with file) or zip.');
}
$counts = export_counts($pdo, $range);
log_screen_view($pdo, 'exports');
if (wants_json()) {
    respond_screen(['period' => $range, 'files' => array_map(static fn (string $slug): array => ['file' => $slug, 'description' => EXPORT_FILES[$slug], 'rows' => $counts[$slug] ?? null, 'filename' => export_filename($slug, $range)], array_keys(EXPORT_FILES)), 'periods' => period_choices($pdo)]);
}
render_screen('Exports', view('exports.php', ['range' => $range, 'counts' => $counts, 'periods' => period_choices($pdo)]), ['activeNav' => 'exports', 'screen' => 'exports', 'entity' => 'export']);
