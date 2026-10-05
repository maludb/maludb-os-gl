<?php
declare(strict_types=1);

const VENDOR_CREDIT_STATUSES = ['draft' => 'Draft', 'issued' => 'Issued', 'void' => 'Void'];
const VENDOR_CREDIT_STATUS_BADGE = ['draft' => 'secondary', 'issued' => 'success', 'void' => 'dark'];

function vendor_credit_cast(array $c): array
{
    foreach (['vendor_credit_id', 'vendor_id', 'bill_id', 'department_id', 'site_id', 'journal_entry_id', 'created_by'] as $k) {
        $c[$k] = ($c[$k] ?? null) === null ? null : (int) $c[$k];
    }
    $c['currency'] = trim((string) $c['currency']);
    return $c;
}

function find_vendor_credits(PDO $pdo, array $filters = [], int $limit = 100): array
{
    $where = [];
    $args = [];
    if (($filters['status'] ?? '') !== '') {
        $where[] = 'status = :s';
        $args['s'] = $filters['status'];
    }
    if (($filters['vendor'] ?? null) !== null && $filters['vendor'] !== '') {
        $where[] = 'vendor_id = :v';
        $args['v'] = (int) $filters['vendor'];
    }
    $st = $pdo->prepare('SELECT * FROM mcp_vendor_credits' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . " ORDER BY (status = 'draft') DESC, credit_date DESC, vendor_credit_id DESC LIMIT " . (int) $limit);
    $st->execute($args);
    return array_map('vendor_credit_cast', $st->fetchAll());
}

function vendor_credit_lines(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT l.*, a.code AS expense_account_code FROM mcp_vendor_credit_lines l JOIN mcp_accounts a ON a.account_id = l.expense_account_id WHERE l.vendor_credit_id = :id ORDER BY l.sort_order, l.vendor_credit_line_id');
    $st->execute(['id' => $id]);
    return array_map(static function (array $l): array {
        foreach (['vendor_credit_line_id', 'vendor_credit_id', 'sort_order', 'tax_rate_id', 'expense_account_id', 'department_id', 'site_id'] as $k) {
            $l[$k] = $l[$k] === null ? null : (int) $l[$k];
        }
        $l['item_id'] = null;
        return $l;
    }, $st->fetchAll());
}

function find_vendor_credit(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_vendor_credits WHERE vendor_credit_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $c = vendor_credit_cast($r);
    $c['lines'] = vendor_credit_lines($pdo, $id);
    $a = $pdo->prepare('SELECT bill_id, bill_number, amount, applied_at FROM mcp_vendor_credit_applications WHERE vendor_credit_id = :id ORDER BY applied_at');
    $a->execute(['id' => $id]);
    $c['applications'] = array_map(static function (array $x): array { $x['bill_id'] = (int) $x['bill_id']; return $x; }, $a->fetchAll());
    $e = $pdo->prepare('SELECT number FROM mcp_journal_entries WHERE journal_entry_id = :e');
    $e->execute(['e' => $c['journal_entry_id']]);
    $c['journal_entry_number'] = $c['journal_entry_id'] === null ? null : ($e->fetchColumn() ?: null);
    return $c;
}

function vendor_credit_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS vendor_credit_id, number, vendor_id, bill_id, vendor_ref, credit_date, currency, status, reason, department_id, site_id, total, amount_applied FROM vendor_credits WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : vendor_credit_cast($r);
}

function vendor_credit_log_state(array $s): array
{
    return array_intersect_key($s, array_flip(['vendor_id', 'bill_id', 'vendor_ref', 'credit_date', 'currency', 'reason', 'department_id', 'site_id']));
}

/** The bills a vendor credit may be applied to: the vendor's open ones in its currency, oldest due first. */
function creditable_bills(PDO $pdo, int $vendorId, string $currency): array
{
    $st = $pdo->prepare("SELECT bill_id, number, vendor_ref, due_date, balance_due FROM mcp_bills WHERE vendor_id = :v AND currency = :cur AND status IN ('approved', 'partially_paid') ORDER BY due_date, bill_id");
    $st->execute(['v' => $vendorId, 'cur' => $currency]);
    return array_map(static function (array $b): array { $b['bill_id'] = (int) $b['bill_id']; return $b; }, $st->fetchAll());
}
