<?php
declare(strict_types=1);

/**
 * Payment runs' writes (slice 5): one draft payment per vendor from one bank account, approved together (cheque numbers assigned in sequence first), recorded together on the pay date,
 * voided by their unrecorded payments (a recorded one stays — the run is then "partly recorded"). The database is the referee: the run's number and total, the payments' joining a draft run only,
 * gl_payment_run_approve / gl_payment_run_record. A DomainException is our own sentence.
 */

/** Create a draft run from a bank account, a pay date, a memo and the bills (ids) or `due_by` — one payment per vendor, applied oldest due first. Answers the id and the payments made. */
function create_payment_run(PDO $pdo, array $f, array $billIds, int $by): array
{
    $bank = $pdo->prepare('SELECT currency, name FROM bank_accounts WHERE id = :b AND archived_at IS NULL');
    $bank->execute(['b' => $f['bank_account_id']]);
    $b = $bank->fetch() ?: throw new DomainException('Choose a bank account from the list.');
    $currency = trim((string) $b['currency']);
    $bills = [];
    if ($billIds !== []) {
        foreach (array_values(array_unique($billIds)) as $id) {
            $r = $pdo->prepare('SELECT bill_id, number, vendor_id, due_date, currency, status FROM mcp_bills WHERE bill_id = :i');
            $r->execute(['i' => $id]);
            $x = $r->fetch() ?: throw new DomainException('Bill #' . $id . ' is not here.');
            if (!in_array($x['status'], OPEN_BILL_STATUSES, true)) {
                throw new DomainException('Bill ' . $x['number'] . ' is ' . $x['status'] . ' and takes no payment.');
            }
            if (trim((string) $x['currency']) !== $currency) {
                throw new DomainException('Bill ' . $x['number'] . ' is in ' . trim((string) $x['currency']) . ' and ' . $b['name'] . ' holds ' . $currency . '.');
            }
            $bills[] = ['bill_id' => (int) $x['bill_id'], 'vendor_id' => (int) $x['vendor_id'], 'due_date' => $x['due_date'], 'payable' => bill_payable($pdo, (int) $x['bill_id'])];
        }
    } else {
        foreach (bills_due_by($pdo, $f['due_by'] ?? $f['pay_date'], $currency) as $x) {
            $bills[] = ['bill_id' => $x['bill_id'], 'vendor_id' => $x['vendor_id'], 'due_date' => $x['due_date'], 'payable' => $x['payable']];
        }
    }
    $bills = array_values(array_filter($bills, static fn (array $x): bool => m_cmp($x['payable'], '0') > 0));
    if ($bills === []) {
        throw new DomainException('No approved bill is waiting to be paid' . ($billIds === [] ? ' by that date' : '') . ' in ' . $currency . '.');
    }
    $st = $pdo->prepare('INSERT INTO payment_runs (pay_date, bank_account_id, currency, memo, created_by) VALUES (:d, :b, :c, :m, :by) RETURNING id');
    $st->execute(['d' => $f['pay_date'], 'b' => $f['bank_account_id'], 'c' => $currency, 'm' => $f['memo'], 'by' => $by]);
    $runId = (int) $st->fetchColumn();
    usort($bills, static fn (array $a, array $c): int => [$a['vendor_id'], $a['due_date'], $a['bill_id']] <=> [$c['vendor_id'], $c['due_date'], $c['bill_id']]);
    $byVendor = [];
    foreach ($bills as $x) {
        $byVendor[$x['vendor_id']][] = $x;
    }
    $made = [];
    foreach ($byVendor as $vendorId => $list) {
        $v = vendor_state($pdo, (int) $vendorId);
        $sum = '0';
        foreach ($list as $x) {
            $sum = m_add($sum, $x['payable']);
        }
        $pid = draft_payment($pdo, ['vendor_id' => (int) $vendorId, 'payment_date' => $f['pay_date'], 'amount' => $sum, 'currency' => $currency, 'method' => $v['pay_method'] ?? 'ach', 'cheque_number' => null,
            'bank_account_id' => $f['bank_account_id'], 'reference' => null, 'memo' => null, 'payment_run_id' => $runId], array_column($list, 'bill_id'), $by);
        $made[] = ['payment_id' => $pid, 'vendor_id' => (int) $vendorId, 'amount' => $sum];
    }
    return ['payment_run_id' => $runId, 'payments' => $made, 'bills' => count($bills)];
}

/** Put a draft payment into a draft run: the same bank account and currency. */
function run_add(PDO $pdo, int $runId, int $paymentId): array
{
    $run = payment_run_state($pdo, $runId) ?? throw new DomainException('Not found.');
    $p = payment_state($pdo, $paymentId) ?? throw new DomainException('Payment not found.');
    if ($run['status'] !== 'draft') {
        throw new DomainException('Payment run ' . $run['number'] . ' is ' . $run['status'] . '; payments join a draft run');
    }
    if ($p['bank_account_id'] !== $run['bank_account_id'] || $p['currency'] !== $run['currency']) {
        throw new DomainException('Payment ' . $p['number'] . ' is from another bank account or currency than run ' . $run['number'] . '.');
    }
    if ($p['payment_run_id'] !== null && $p['payment_run_id'] !== $runId) {
        throw new DomainException('Payment ' . $p['number'] . ' is already in another run.');
    }
    $pdo->prepare('UPDATE payments SET payment_run_id = :r WHERE id = :p')->execute(['r' => $runId, 'p' => $paymentId]);
    return ['payment_number' => $p['number'], 'amount' => $p['amount'], 'run_number' => $run['number']];
}

function run_remove(PDO $pdo, int $runId, int $paymentId): array
{
    $run = payment_run_state($pdo, $runId) ?? throw new DomainException('Not found.');
    $p = payment_state($pdo, $paymentId) ?? throw new DomainException('Payment not found.');
    if ($p['payment_run_id'] !== $runId) {
        throw new DomainException('Payment ' . $p['number'] . ' is not in that run.');
    }
    $pdo->prepare('UPDATE payments SET payment_run_id = NULL WHERE id = :p')->execute(['p' => $paymentId]);
    return ['payment_number' => $p['number'], 'amount' => $p['amount'], 'run_number' => $run['number']];
}

/** The next cheque number after $n, keeping its width when it has leading zeros. */
function next_cheque_number(string $n, int $steps): string
{
    if (!ctype_digit($n)) {
        throw new DomainException('The first cheque number is digits, like 2001.');
    }
    return str_pad((string) ((int) $n + $steps), strlen($n), '0', STR_PAD_LEFT);
}

/** Approve a draft run: every draft payment fully applied; cheque numbers given in sequence to the cheque payments that have none; gl_payment_run_approve() approves them all. Answers the head and the numbers assigned. */
function approve_run(PDO $pdo, int $id, ?string $firstCheque, int $by): array
{
    $run = payment_run_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($run['status'] !== 'draft') {
        throw new DomainException('Payment run ' . $run['number'] . ' is already ' . $run['status']);
    }
    $ps = $pdo->prepare("SELECT id, number, method, cheque_number FROM payments WHERE payment_run_id = :r AND status = 'draft' ORDER BY id");
    $ps->execute(['r' => $id]);
    $drafts = $ps->fetchAll();
    $assigned = [];
    $step = 0;
    foreach ($drafts as $p) {
        assert_fully_applied($pdo, (int) $p['id']);
        if ($p['method'] === 'cheque' && ($p['cheque_number'] === null || $p['cheque_number'] === '')) {
            if ($firstCheque === null || $firstCheque === '') {
                throw new DomainException('Payment ' . $p['number'] . ' is a cheque: give the first cheque number to number the run\'s cheques from.');
            }
            $cn = next_cheque_number($firstCheque, $step++);
            $pdo->prepare('UPDATE payments SET cheque_number = :c WHERE id = :id')->execute(['c' => $cn, 'id' => $p['id']]);
            $assigned[] = ['payment_id' => (int) $p['id'], 'number' => $p['number'], 'cheque_number' => $cn];
        }
    }
    $st = $pdo->prepare('SELECT (r).id, (r).number, (r).total, (r).currency, (r).bank_account_id FROM gl_payment_run_approve(:id, :by) r');
    $st->execute(['id' => $id, 'by' => $by]);
    $r = $st->fetch();
    return ['payment_run_id' => (int) $r['id'], 'number' => $r['number'], 'total' => $r['total'], 'currency' => trim((string) $r['currency']), 'bank_account_id' => (int) $r['bank_account_id'], 'payments' => count($drafts), 'cheques' => $assigned];
}

/** Record an approved run as paid: every approved payment takes the pay date, must have its cheque number if a cheque, and is recorded (posted) by gl_payment_run_record(). */
function record_run(PDO $pdo, int $id, string $payDate, int $by): array
{
    $run = payment_run_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($run['status'] !== 'approved') {
        throw new DomainException('Payment run ' . $run['number'] . ' is ' . $run['status'] . '; it must be approved before it is recorded');
    }
    $ps = $pdo->prepare("SELECT id, number, method, cheque_number FROM payments WHERE payment_run_id = :r AND status = 'approved'");
    $ps->execute(['r' => $id]);
    foreach ($ps->fetchAll() as $p) {
        if ($p['method'] === 'cheque' && ($p['cheque_number'] === null || $p['cheque_number'] === '')) {
            throw new DomainException('Payment ' . $p['number'] . ' is a cheque: give its cheque number.');
        }
    }
    $pdo->prepare("UPDATE payments SET payment_date = :d WHERE payment_run_id = :r AND status = 'approved'")->execute(['d' => $payDate, 'r' => $id]);
    $pdo->prepare('UPDATE payment_runs SET pay_date = :d WHERE id = :r')->execute(['d' => $payDate, 'r' => $id]);
    $st = $pdo->prepare('SELECT (r).id, (r).number, (r).total, (r).currency, (r).bank_account_id FROM gl_payment_run_record(:id, :by) r');
    $st->execute(['id' => $id, 'by' => $by]);
    $r = $st->fetch();
    $n = $pdo->prepare("SELECT count(*) FROM payments WHERE payment_run_id = :r AND status = 'recorded'");
    $n->execute(['r' => $id]);
    return ['payment_run_id' => (int) $r['id'], 'number' => $r['number'], 'total' => $r['total'], 'currency' => trim((string) $r['currency']), 'bank_account_id' => (int) $r['bank_account_id'], 'payments' => (int) $n->fetchColumn(), 'pay_date' => $payDate];
}

/** Void a run: its draft and approved payments are voided (a recorded one is left and the run reads "partly recorded"); a run with no recorded payment becomes void. */
function void_run(PDO $pdo, int $id, int $by, string $reason): array
{
    if (trim($reason) === '') {
        throw new DomainException('Voiding a payment run needs a reason');
    }
    $run = payment_run_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($run['status'] === 'void') {
        throw new DomainException('Payment run ' . $run['number'] . ' is void and cannot be changed');
    }
    $ps = $pdo->prepare('SELECT id, status FROM payments WHERE payment_run_id = :r ORDER BY id');
    $ps->execute(['r' => $id]);
    $all = $ps->fetchAll();
    $open = array_values(array_filter($all, static fn (array $p): bool => in_array($p['status'], ['draft', 'approved'], true)));
    $kept = count(array_filter($all, static fn (array $p): bool => $p['status'] === 'recorded'));
    if ($open === [] && $kept > 0) {
        throw new DomainException('Every payment of ' . $run['number'] . ' is recorded; void a payment to undo it.');
    }
    foreach ($open as $p) {
        $pdo->prepare('SELECT gl_payment_void(:p, :by, :r)')->execute(['p' => $p['id'], 'by' => $by, 'r' => 'Payment run ' . $run['number'] . ' voided: ' . $reason]);
    }
    if ($kept === 0) {
        $pdo->prepare("UPDATE payment_runs SET status = 'void', voided_at = now(), voided_by = :by WHERE id = :id")->execute(['by' => $by, 'id' => $id]);
    } elseif ($run['status'] === 'approved') {
        $pdo->prepare("UPDATE payment_runs SET status = 'recorded', recorded_at = COALESCE(recorded_at, now()), recorded_by = COALESCE(recorded_by, :by) WHERE id = :id")->execute(['by' => $by, 'id' => $id]);
    }
    return ['payment_run_id' => $id, 'number' => $run['number'], 'voided' => count($open), 'kept' => $kept, 'partly_recorded' => $kept > 0, 'total' => $run['total'], 'currency' => $run['currency'], 'bank_account_id' => $run['bank_account_id']];
}
