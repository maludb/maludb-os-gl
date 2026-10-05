<?php
declare(strict_types=1);

const DEPOSIT_STATUS_BADGE = ['draft' => 'secondary', 'recorded' => 'success', 'void' => 'dark'];

function deposit_cast(array $d): array
{
    foreach (['deposit_id', 'bank_account_id', 'journal_entry_id', 'receipt_count', 'recorded_by'] as $k) {
        $d[$k] = ($d[$k] ?? null) === null ? null : (int) $d[$k];
    }
    $d['currency'] = trim((string) $d['currency']);
    return $d;
}

function find_deposits(PDO $pdo, array $filters = [], int $limit = 100): array
{
    $where = [];
    $args = [];
    if (($filters['bank_account'] ?? null) !== null && $filters['bank_account'] !== '') {
        $where[] = 'bank_account_id = :b';
        $args['b'] = (int) $filters['bank_account'];
    }
    if (($filters['status'] ?? '') !== '') {
        $where[] = 'status = :s';
        $args['s'] = $filters['status'];
    }
    $st = $pdo->prepare('SELECT * FROM mcp_deposits' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY deposit_date DESC, deposit_id DESC LIMIT ' . (int) $limit);
    $st->execute($args);
    return array_map('deposit_cast', $st->fetchAll());
}

function find_deposit(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_deposits WHERE deposit_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $d = deposit_cast($r);
    $rs = $pdo->prepare('SELECT receipt_id, number, customer_name, received_on, amount, currency, method, reference FROM mcp_receipts WHERE deposit_id = :id ORDER BY received_on, receipt_id');
    $rs->execute(['id' => $id]);
    $d['receipts'] = array_map(static function (array $x): array { $x['receipt_id'] = (int) $x['receipt_id']; return $x; }, $rs->fetchAll());
    $e = $pdo->prepare('SELECT number FROM mcp_journal_entries WHERE journal_entry_id = :e');
    $e->execute(['e' => $d['journal_entry_id']]);
    $d['journal_entry_number'] = $d['journal_entry_id'] === null ? null : ($e->fetchColumn() ?: null);
    $d['matched_line'] = null;                                  // slice 6 fills the bank line this deposit matched
    try {
        $m = $pdo->prepare("SELECT bank_transaction_id, txn_date, amount FROM mcp_bank_transactions WHERE matched_type = 'deposit' AND matched_id = :id AND status = 'matched'");
        $m->execute(['id' => $id]);
        $d['matched_line'] = $m->fetch() ?: null;
    } catch (PDOException) {
    }
    return $d;
}

function deposit_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS deposit_id, number, bank_account_id, deposit_date, currency, total, memo, status, journal_entry_id FROM deposits WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : deposit_cast($r + ['receipt_count' => null]);
}
