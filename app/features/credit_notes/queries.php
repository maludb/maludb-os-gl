<?php
declare(strict_types=1);

const CREDIT_STATUSES = ['draft' => 'Draft', 'issued' => 'Issued', 'void' => 'Void'];
const CREDIT_STATUS_BADGE = ['draft' => 'secondary', 'issued' => 'success', 'void' => 'dark'];

function credit_note_cast(array $c): array
{
    foreach (['credit_note_id', 'customer_id', 'invoice_id', 'department_id', 'site_id', 'journal_entry_id', 'created_by'] as $k) {
        $c[$k] = ($c[$k] ?? null) === null ? null : (int) $c[$k];
    }
    $c['currency'] = trim((string) $c['currency']);
    return $c;
}

function find_credit_notes(PDO $pdo, array $filters = [], int $limit = 100): array
{
    $where = [];
    $args = [];
    if (($filters['status'] ?? '') !== '') {
        $where[] = 'status = :s';
        $args['s'] = $filters['status'];
    }
    if (($filters['customer'] ?? null) !== null && $filters['customer'] !== '') {
        $where[] = 'customer_id = :c';
        $args['c'] = (int) $filters['customer'];
    }
    $st = $pdo->prepare('SELECT * FROM mcp_credit_notes' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY (status = \'draft\') DESC, issue_date DESC, credit_note_id DESC LIMIT ' . (int) $limit);
    $st->execute($args);
    return array_map('credit_note_cast', $st->fetchAll());
}

function credit_note_lines(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT l.*, a.code AS income_account_code, it.name AS item_name FROM mcp_credit_note_lines l JOIN mcp_accounts a ON a.account_id = l.income_account_id LEFT JOIN mcp_items it ON it.item_id = l.item_id WHERE l.credit_note_id = :id ORDER BY l.sort_order, l.credit_note_line_id');
    $st->execute(['id' => $id]);
    return array_map(static function (array $l): array {
        foreach (['credit_note_line_id', 'credit_note_id', 'sort_order', 'item_id', 'tax_rate_id', 'income_account_id', 'department_id', 'site_id'] as $k) {
            $l[$k] = $l[$k] === null ? null : (int) $l[$k];
        }
        return $l;
    }, $st->fetchAll());
}

function find_credit_note(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_credit_notes WHERE credit_note_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $c = credit_note_cast($r);
    $c['lines'] = credit_note_lines($pdo, $id);
    $a = $pdo->prepare('SELECT invoice_id, invoice_number, amount, applied_at FROM mcp_credit_applications WHERE credit_note_id = :id ORDER BY applied_at');
    $a->execute(['id' => $id]);
    $c['applications'] = array_map(static function (array $x): array { $x['invoice_id'] = (int) $x['invoice_id']; return $x; }, $a->fetchAll());
    $e = $pdo->prepare('SELECT number FROM mcp_journal_entries WHERE journal_entry_id = :e');
    $e->execute(['e' => $c['journal_entry_id']]);
    $c['journal_entry_number'] = $c['journal_entry_id'] === null ? null : ($e->fetchColumn() ?: null);
    return $c;
}

function credit_note_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS credit_note_id, number, customer_id, invoice_id, issue_date, currency, status, reason, department_id, site_id, total, amount_applied FROM credit_notes WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : credit_note_cast($r);
}

function credit_note_log_state(array $s): array
{
    return array_intersect_key($s, array_flip(['customer_id', 'invoice_id', 'issue_date', 'currency', 'reason', 'department_id', 'site_id']));
}

/** The invoices a credit note may be applied to: the customer's open ones in its currency, oldest due first. */
function creditable_invoices(PDO $pdo, int $customerId, string $currency): array
{
    $st = $pdo->prepare("SELECT invoice_id, number, due_date, balance_due FROM mcp_invoices WHERE customer_id = :c AND currency = :cur AND status IN ('issued', 'sent', 'viewed', 'partially_paid') ORDER BY due_date, invoice_id");
    $st->execute(['c' => $customerId, 'cur' => $currency]);
    return array_map(static function (array $i): array { $i['invoice_id'] = (int) $i['invoice_id']; return $i; }, $st->fetchAll());
}
