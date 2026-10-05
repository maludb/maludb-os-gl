<?php
declare(strict_types=1);

/** One export file as CSV text (slice 9). A flat file is its header row and its rows; a statement is the page's table through statement_csv() (title, currency, columns). */

function export_csv(PDO $pdo, string $slug, array $range): string
{
    if (!isset(EXPORT_FILES[$slug])) {
        throw new DomainException('No such file.');
    }
    if (in_array($slug, EXPORT_FLAT, true)) {
        $t = export_table($pdo, $slug, $range);
        $out = fopen('php://temp', 'w+');
        fputcsv($out, $t['columns']);
        foreach ($t['rows'] as $r) {
            fputcsv($out, array_map(static fn ($v) => $v === null ? '' : (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v), $r));
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }
    $tables = match ($slug) {
        'trial-balance' => [tb_table(trial_balance($pdo, $range['as_of']))],
        'pl'            => [pl_table(profit_and_loss($pdo, $range['from'], $range['to'], null, null, null))],
        'balance-sheet' => [bs_table(balance_sheet($pdo, $range['as_of'], null))],
        'ar-aging'      => aging_tables(ar_aging($pdo, $range['as_of'], null), 'customer', 'Receivables aging'),
        'ap-aging'      => aging_tables(ap_aging($pdo, $range['as_of'], null), 'vendor', 'Payables aging'),
    };
    return statement_csv($tables);
}

/** The file's name in a download: pl-2026-09.csv. */
function export_filename(string $slug, array $range, string $ext = 'csv'): string
{
    return $slug . '-' . preg_replace('/[^A-Za-z0-9_.-]+/', '-', $range['period'] === 'custom' ? $range['from'] . '_' . $range['to'] : $range['label']) . '.' . $ext;
}
