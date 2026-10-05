<?php
declare(strict_types=1);

/** The run's CSV for the bank's bulk-payment upload or the cheque printer: one row per live payment — vendor, remit address, amount, currency, method, cheque number, reference, the bills' references. */
function run_csv(PDO $pdo, int $id): string
{
    $run = find_payment_run($pdo, $id) ?? throw new DomainException('Not found.');
    $out = fopen('php://memory', 'w+');
    fputcsv($out, ['payment', 'vendor', 'remit_address', 'amount', 'currency', 'method', 'cheque_number', 'pay_date', 'reference', 'bills']);
    foreach ($run['payments'] as $p) {
        if ($p['status'] === 'void') {
            continue;
        }
        $st = $pdo->prepare('SELECT remit_address FROM mcp_vendors WHERE vendor_id = :v');
        $st->execute(['v' => $p['vendor_id']]);
        $refs = [];
        foreach ($p['bills'] as $b) {
            $r = $pdo->prepare('SELECT vendor_ref FROM mcp_bills WHERE bill_id = :b');
            $r->execute(['b' => $b['bill_id']]);
            $refs[] = (string) ($r->fetchColumn() ?: $b['number']);
        }
        $pay = $pdo->prepare('SELECT reference FROM mcp_payments WHERE payment_id = :p');
        $pay->execute(['p' => $p['payment_id']]);
        fputcsv($out, [$p['number'], $p['vendor_name'], str_replace(["\r", "\n"], ' ', (string) ($st->fetchColumn() ?: '')), $p['amount'], $p['currency'], $p['method'], $p['cheque_number'] ?? '', $run['pay_date'], (string) ($pay->fetchColumn() ?: ''), implode('; ', $refs)]);
    }
    rewind($out);
    return (string) stream_get_contents($out);
}
