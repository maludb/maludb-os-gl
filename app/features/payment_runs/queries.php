<?php
declare(strict_types=1);

const RUN_STATUS_BADGE = ['draft' => 'secondary', 'approved' => 'info', 'recorded' => 'success', 'void' => 'dark'];

function run_cast(array $r): array
{
    foreach (['payment_run_id', 'bank_account_id', 'payment_count', 'approved_by', 'recorded_by', 'created_by'] as $k) {
        $r[$k] = ($r[$k] ?? null) === null ? null : (int) $r[$k];
    }
    $r['currency'] = trim((string) $r['currency']);
    return $r;
}

function find_payment_runs(PDO $pdo, array $filters = [], int $limit = 100): array
{
    $where = [];
    $args = [];
    if (($filters['status'] ?? '') !== '' && isset(PAYMENT_STATUSES[$filters['status']])) {
        $where[] = 'status = :s';
        $args['s'] = $filters['status'];
    }
    $st = $pdo->prepare('SELECT * FROM mcp_payment_runs' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY pay_date DESC, payment_run_id DESC LIMIT ' . (int) $limit);
    $st->execute($args);
    return array_map('run_cast', $st->fetchAll());
}

/** One run with its payments (each with the bills it pays), how many are void, and the "partly recorded" fact. */
function find_payment_run(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_payment_runs WHERE payment_run_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $run = run_cast($r);
    $p = $pdo->prepare('SELECT payment_id, number, vendor_id, vendor_name, amount, currency, method, cheque_number, status, amount_applied FROM mcp_payments WHERE payment_run_id = :id ORDER BY vendor_name, payment_id');
    $p->execute(['id' => $id]);
    $run['payments'] = array_map(static function (array $x) use ($pdo): array {
        $x['payment_id'] = (int) $x['payment_id']; $x['vendor_id'] = (int) $x['vendor_id']; $x['currency'] = trim((string) $x['currency']);
        $x['bills'] = payment_applied_list($pdo, $x['payment_id']);
        return $x;
    }, $p->fetchAll());
    $run['void_count'] = count(array_filter($run['payments'], static fn (array $x): bool => $x['status'] === 'void'));
    $run['recorded_count'] = count(array_filter($run['payments'], static fn (array $x): bool => $x['status'] === 'recorded'));
    $run['partly_recorded'] = $run['status'] === 'recorded' && $run['void_count'] > 0;
    return $run;
}

function payment_run_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS payment_run_id, number, pay_date, bank_account_id, currency, status, total, memo FROM payment_runs WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : run_cast($r + ['payment_count' => null]);
}

/** The approved and partly paid bills due by a date in one currency that still have something payable (nothing pending in another live payment), oldest due first, with what is payable. */
function bills_due_by(PDO $pdo, string $dueBy, string $currency): array
{
    $st = $pdo->prepare("SELECT bill_id, number, vendor_id, vendor_name, vendor_ref, due_date, currency, total, balance_due, has_attachment FROM mcp_bills WHERE status IN ('approved', 'partially_paid') AND currency = :c AND due_date <= :d ORDER BY vendor_name, due_date, bill_id");
    $st->execute(['c' => $currency, 'd' => $dueBy]);
    $out = [];
    foreach ($st->fetchAll() as $b) {
        $b['bill_id'] = (int) $b['bill_id'];
        $b['vendor_id'] = (int) $b['vendor_id'];
        $b['currency'] = trim((string) $b['currency']);
        $b['payable'] = bill_payable($pdo, $b['bill_id']);
        if (m_cmp($b['payable'], '0') > 0) {
            $out[] = $b;
        }
    }
    return $out;
}
