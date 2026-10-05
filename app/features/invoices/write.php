<?php
declare(strict_types=1);

/**
 * Invoices' writes (slice 4). The database is the referee: line money and totals (triggers), the number and the posting on issue (gl_invoice_issue), void and write-off
 * (gl_invoice_void / gl_invoice_write_off). A DomainException is our own sentence. Document lines (invoices and credit notes alike) are read by normalize_doc_line().
 */

/** Read one document line's fields (an invoice's or a credit note's): item (fills description, unit price, account, tax rate), description, quantity, unit_price, tax_rate, income_account, department, site. Throws "Row N: …". */
function normalize_doc_line(PDO $pdo, array $row, int $rowNo, ?array $defaults = null): array
{
    $row = array_change_key_case($row, CASE_LOWER);
    $get = static fn (string ...$k): mixed => array_values(array_filter(array_map(static fn (string $n) => $row[$n] ?? null, $k), static fn ($v) => $v !== null))[0] ?? null;
    $itemId = $get('item', 'item_id');
    $item = null;
    if ($itemId !== null && $itemId !== '') {
        $st = $pdo->prepare('SELECT item_id, description, name, unit_price, income_account_id, tax_rate_id FROM mcp_items WHERE item_id = :i AND archived_at IS NULL');
        $st->execute(['i' => (int) $itemId]);
        $item = $st->fetch() ?: throw new DomainException('Row ' . $rowNo . ': that item is not here.');
    }
    $description = trim((string) ($get('description') ?? ''));
    if ($description === '' && $item !== null) {
        $description = (string) ($item['description'] ?: $item['name']);
    }
    if ($description === '') {
        throw new DomainException('Row ' . $rowNo . ': give the line a description.');
    }
    $qty = trim((string) ($get('quantity') ?? '1'));
    if ($qty === '' || !is_numeric(str_replace(',', '', $qty)) || (float) str_replace(',', '', $qty) <= 0) {
        throw new DomainException('Row ' . $rowNo . ': the quantity is a number above zero.');
    }
    $qty = number_format((float) str_replace(',', '', $qty), 3, '.', '');
    $priceRaw = $get('unit_price', 'price');
    $price = $priceRaw === null || $priceRaw === '' ? ($item['unit_price'] ?? null) : parse_money((string) $priceRaw);
    if ($price === null || (float) $price < 0) {
        throw new DomainException('Row ' . $rowNo . ': the unit price is an amount of zero or more.');
    }
    $resolve = static function (string $sql, mixed $v, string $label) use ($pdo, $rowNo): ?int {
        if ($v === null || $v === '' || $v === '0') {
            return null;
        }
        if (filter_var($v, FILTER_VALIDATE_INT) === false) {
            throw new DomainException('Row ' . $rowNo . ': choose the ' . $label . ' from the list.');
        }
        $st = $pdo->prepare($sql);
        $st->execute(['id' => (int) $v]);
        if ($st->fetchColumn() === false) {
            throw new DomainException('Row ' . $rowNo . ': that ' . $label . ' is not here.');
        }
        return (int) $v;
    };
    $taxGiven = array_key_exists('tax_rate', $row) || array_key_exists('tax_rate_id', $row);
    $tax = $taxGiven ? $resolve('SELECT 1 FROM tax_rates WHERE id = :id AND archived_at IS NULL', $get('tax_rate', 'tax_rate_id'), 'tax rate') : ($item['tax_rate_id'] ?? $defaults['tax_rate_id'] ?? null);
    $incomeRaw = $get('income_account', 'income_account_id');
    if ($incomeRaw !== null && $incomeRaw !== '' && !ctype_digit((string) $incomeRaw)) {                 // an agent may name the account by code
        $a = find_account_by_code($pdo, (string) $incomeRaw);
        $incomeRaw = $a === null ? $incomeRaw : $a['account_id'];
    } elseif ($incomeRaw !== null && $incomeRaw !== '' && preg_match('/^[0-9]{3,8}$/', (string) $incomeRaw) && !($pdo->query('SELECT 1 FROM accounts WHERE id = ' . (int) $incomeRaw)->fetchColumn())) {
        $a = find_account_by_code($pdo, (string) $incomeRaw);
        $incomeRaw = $a === null ? $incomeRaw : $a['account_id'];
    }
    $income = ($incomeRaw === null || $incomeRaw === '') ? ($item['income_account_id'] ?? $defaults['income_account_id'] ?? null)
        : $resolve("SELECT 1 FROM accounts WHERE id = :id AND archived_at IS NULL AND account_type = 'income'", $incomeRaw, 'income account');
    if ($income === null) {
        throw new DomainException('Row ' . $rowNo . ': choose the income account.');
    }
    return ['item_id' => $item === null ? null : (int) $item['item_id'], 'description' => mb_substr($description, 0, 300), 'quantity' => $qty, 'unit_price' => $price, 'tax_rate_id' => $tax, 'income_account_id' => $income,
            'department_id' => $resolve('SELECT 1 FROM departments WHERE id = :id AND archived_at IS NULL', $get('department', 'department_id'), 'department'),
            'site_id' => $resolve('SELECT 1 FROM sites WHERE id = :id AND archived_at IS NULL', $get('site', 'site_id'), 'site')];
}

/** The `lines` an agent sends (JSON rows) or a form's line arrays; null when neither came. */
function request_doc_rows(): ?array
{
    if (req_has('lines')) {
        $raw = $_POST['lines'];
        if (is_string($raw)) {
            $rows = json_decode($raw, true);
            if (!is_array($rows)) {
                refuse(422, 'lines is a JSON list of rows (item or description, quantity, unit_price, tax_rate, income_account…).');
            }
            return array_values($rows);
        }
        return is_array($raw) ? array_values($raw) : null;
    }
    if (isset($_POST['line_description']) && is_array($_POST['line_description'])) {
        $rows = [];
        foreach ($_POST['line_description'] as $i => $d) {
            $row = ['description' => $d, 'quantity' => $_POST['line_quantity'][$i] ?? '1', 'unit_price' => $_POST['line_unit_price'][$i] ?? null, 'item' => $_POST['line_item'][$i] ?? null];
            if (trim((string) $d) === '' && trim((string) ($row['unit_price'] ?? '')) === '' && trim((string) ($row['item'] ?? '')) === '') {
                continue;
            }
            $rows[] = $row;
        }
        return $rows;
    }
    return null;
}

function insert_doc_line(PDO $pdo, string $table, string $fk, int $docId, int $sort, array $l): int
{
    $st = $pdo->prepare("INSERT INTO {$table} ({$fk}, sort_order, item_id, description, quantity, unit_price, tax_rate_id, income_account_id, department_id, site_id) VALUES (:d, :s, :i, :de, :q, :p, :t, :a, :dept, :site) RETURNING id");
    $st->execute(['d' => $docId, 's' => $sort, 'i' => $l['item_id'], 'de' => $l['description'], 'q' => $l['quantity'], 'p' => $l['unit_price'], 't' => $l['tax_rate_id'], 'a' => $l['income_account_id'], 'dept' => $l['department_id'], 'site' => $l['site_id']]);
    return (int) $st->fetchColumn();
}

/** Draft an invoice: head (customer_id, issue_date, due_date, currency, reference, memo, terms_text, department_id, site_id) and normalized lines. Answers the id. */
function draft_invoice(PDO $pdo, array $head, array $lines, int $by): int
{
    $st = $pdo->prepare('INSERT INTO invoices (customer_id, issue_date, due_date, currency, reference, memo, terms_text, department_id, site_id, created_by) VALUES (:c, :i, :d, :cur, :r, :m, :t, :dept, :site, :by) RETURNING id');
    $st->execute(['c' => $head['customer_id'], 'i' => $head['issue_date'], 'd' => $head['due_date'], 'cur' => $head['currency'], 'r' => $head['reference'], 'm' => $head['memo'], 't' => $head['terms_text'], 'dept' => $head['department_id'], 'site' => $head['site_id'], 'by' => $by]);
    $id = (int) $st->fetchColumn();
    foreach ($lines as $n => $l) {
        insert_doc_line($pdo, 'invoice_lines', 'invoice_id', $id, $n + 1, $l);
    }
    return $id;
}

/** A draft's head (keys present change). Answers ['before' => …, 'after' => …] of the changed fields. An issued invoice's head is refused in the guard's words. */
function update_invoice(PDO $pdo, int $id, array $head, int $by): array
{
    $cur = invoice_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($cur['status'] !== 'draft') {
        throw new DomainException('Invoice ' . ($cur['number'] ?? '#' . $id) . ' is issued: its customer, dates, number and totals cannot change (credit it, or void it)');
    }
    $before = invoice_log_state($cur);
    $after = array_intersect_key($head, $before) + $before;
    $changed = array_keys(array_filter($after, static fn ($v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    if ($changed === []) {
        return ['before' => [], 'after' => []];
    }
    $pdo->prepare('UPDATE invoices SET customer_id = :c, issue_date = :i, due_date = :d, currency = :cur, reference = :r, memo = :m, terms_text = :t, department_id = :dept, site_id = :site WHERE id = :id')
        ->execute(['c' => $after['customer_id'], 'i' => $after['issue_date'], 'd' => $after['due_date'], 'cur' => $after['currency'], 'r' => $after['reference'], 'm' => $after['memo'], 't' => $after['terms_text'], 'dept' => $after['department_id'], 'site' => $after['site_id'], 'id' => $id]);
    return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))];
}

/** Add ($lineId null) or change a line of a draft (the trigger refuses anything else and computes the money). Answers the line id. */
function save_invoice_line(PDO $pdo, int $invoiceId, ?int $lineId, array $l): int
{
    if ($lineId === null) {
        $st = $pdo->prepare('SELECT COALESCE(max(sort_order), 0) + 1 FROM invoice_lines WHERE invoice_id = :i');
        $st->execute(['i' => $invoiceId]);
        return insert_doc_line($pdo, 'invoice_lines', 'invoice_id', $invoiceId, (int) $st->fetchColumn(), $l);
    }
    $st = $pdo->prepare('UPDATE invoice_lines SET item_id = :i, description = :de, quantity = :q, unit_price = :p, tax_rate_id = :t, income_account_id = :a, department_id = :dept, site_id = :site WHERE id = :id AND invoice_id = :inv');
    $st->execute(['i' => $l['item_id'], 'de' => $l['description'], 'q' => $l['quantity'], 'p' => $l['unit_price'], 't' => $l['tax_rate_id'], 'a' => $l['income_account_id'], 'dept' => $l['department_id'], 'site' => $l['site_id'], 'id' => $lineId, 'inv' => $invoiceId]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Not found.');
    }
    return $lineId;
}

function remove_invoice_line(PDO $pdo, int $invoiceId, int $lineId): bool
{
    $st = $pdo->prepare('DELETE FROM invoice_lines WHERE id = :id AND invoice_id = :i');
    $st->execute(['id' => $lineId, 'i' => $invoiceId]);
    return $st->rowCount() > 0;
}

/** The invoice with its totals as the trigger wrote them (for the totals panel). */
function invoice_totals_row(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT subtotal, tax_total, total, amount_paid, amount_credited, balance_due, currency, status FROM invoices WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    $r['currency'] = trim((string) $r['currency']);
    return $r;
}

/** Mint a link for an invoice (the old one dies) with the settings' life; answers the raw token — which only the held outbox row keeps. */
function mint_invoice_link(PDO $pdo, int $invoiceId): string
{
    $st = $pdo->prepare('SELECT gl_secure_link_mint(:i)');
    $st->execute(['i' => $invoiceId]);
    $raw = (string) $st->fetchColumn();
    $days = (int) $pdo->query('SELECT link_lifetime_days FROM gl_settings WHERE id = 1')->fetchColumn();
    $pdo->prepare("UPDATE invoice_links_secure SET expires_at = now() + (:d || ' days')::interval WHERE token_hash = :h")->execute(['d' => $days, 'h' => hash('sha256', $raw)]);
    return $raw;
}

/** Keep a raw token for the customer's notice: the ONE place it is held. A previous holder is blanked. */
function hold_invoice_link(PDO $pdo, int $invoiceId, int $customerId, string $raw): void
{
    $pdo->prepare("UPDATE notification_outbox SET body = '(replaced)' WHERE kind = 'invoice_link' AND record_type = 'invoice' AND record_id = :i")->execute(['i' => $invoiceId]);
    $pdo->prepare("INSERT INTO notification_outbox (channel, customer_id, kind, record_type, record_id, subject, body, status, detail) VALUES ('email', :c, 'invoice_link', 'invoice', :i, 'Secure link held', :b, 'skipped', 'held for invoice_send')")
        ->execute(['c' => $customerId, 'i' => $invoiceId, 'b' => app_url('/i/' . $raw)]);
}

/** The raw token of the invoice's live link: the held one when still live, else a fresh mint (held again). */
function live_invoice_token(PDO $pdo, int $invoiceId, int $customerId): string
{
    $st = $pdo->prepare("SELECT body FROM notification_outbox WHERE kind = 'invoice_link' AND record_type = 'invoice' AND record_id = :i AND status = 'skipped' ORDER BY id DESC LIMIT 1");
    $st->execute(['i' => $invoiceId]);
    $body = $st->fetchColumn();
    if ($body !== false && preg_match('#/i/([a-f0-9]{48})$#', (string) $body, $m)) {
        $live = $pdo->prepare('SELECT 1 FROM invoice_links_secure WHERE token_hash = :h AND rotated_at IS NULL AND (expires_at IS NULL OR expires_at > now())');
        $live->execute(['h' => hash('sha256', $m[1])]);
        if ($live->fetchColumn() !== false) {
            return $m[1];
        }
    }
    $raw = mint_invoice_link($pdo, $invoiceId);
    hold_invoice_link($pdo, $invoiceId, $customerId, $raw);
    return $raw;
}

/** Issue: gl_invoice_issue() numbers and posts; the link is minted and held. Answers the invoice head and the entry's number. */
function issue_invoice(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT (i).id, (i).number, (i).total, (i).currency, (i).customer_id, (i).journal_entry_id FROM gl_invoice_issue(:id, :by) i');
    $st->execute(['id' => $id, 'by' => $by]);
    $r = $st->fetch();
    $raw = mint_invoice_link($pdo, $id);
    hold_invoice_link($pdo, $id, (int) $r['customer_id'], $raw);
    $e = $pdo->prepare('SELECT number FROM journal_entries WHERE id = :e');
    $e->execute(['e' => $r['journal_entry_id']]);
    return ['invoice_id' => (int) $r['id'], 'number' => $r['number'], 'total' => $r['total'], 'currency' => trim((string) $r['currency']), 'customer_id' => (int) $r['customer_id'], 'journal_entry_id' => (int) $r['journal_entry_id'], 'journal_entry_number' => (string) $e->fetchColumn()];
}

/** The address an invoice mail goes to: the one named, else the customer's primary contact, else the customer's. */
function customer_mail_address(PDO $pdo, int $customerId, ?string $named): string
{
    if ($named !== null && $named !== '') {
        return $named;
    }
    $st = $pdo->prepare("SELECT email::text FROM party_contacts WHERE party_kind = 'customer' AND party_id = :c AND email IS NOT NULL ORDER BY is_primary DESC, id LIMIT 1");
    $st->execute(['c' => $customerId]);
    $e = $st->fetchColumn();
    if ($e === false || $e === null || $e === '') {
        $st = $pdo->prepare('SELECT email::text FROM customers WHERE id = :c');
        $st->execute(['c' => $customerId]);
        $e = $st->fetchColumn();
    }
    if ($e === false || $e === null || $e === '') {
        throw new DomainException('There is no address to send to: add a contact or an email to the customer, or name one.');
    }
    return (string) $e;
}

/** Send an issued invoice: queue the mail (with the link) for the customer, stamp sent_at, let the trigger-owned status follow. Answers ['outbox_id', 'to_count']. */
function send_invoice(PDO $pdo, int $id, ?string $to, ?string $message, int $by): array
{
    $inv = find_invoice($pdo, $id) ?? throw new DomainException('Not found.');
    if (in_array($inv['status'], ['draft', 'void', 'written_off'], true)) {
        throw new DomainException('Invoice ' . ($inv['number'] ?? '#' . $id) . ' is ' . $inv['status'] . '; only an issued invoice is sent.');
    }
    $address = customer_mail_address($pdo, $inv['customer_id'], $to);
    $raw = live_invoice_token($pdo, $id, $inv['customer_id']);
    $mail = invoice_mail($pdo, $inv, $raw, $message, 'invoice_sent');
    $outbox = queue_customer_mail($pdo, $inv['customer_id'], $address, 'invoice_sent', 'invoice', $id, $mail);
    $pdo->prepare('UPDATE invoices SET sent_at = COALESCE(sent_at, now()) WHERE id = :id')->execute(['id' => $id]);
    $pdo->prepare('SELECT gl_invoice_recalculate(:id)')->execute(['id' => $id]);
    return ['outbox_id' => $outbox, 'to_count' => 1];
}

/** Remind: past due only; the wording escalates with the days past due (1–14, 15–44, 45 and more). */
function remind_invoice(PDO $pdo, int $id, ?string $message, int $by): array
{
    $inv = find_invoice($pdo, $id) ?? throw new DomainException('Not found.');
    if (!in_array($inv['status'], OPEN_INVOICE_STATUSES, true) || !$inv['overdue']) {
        throw new DomainException('Invoice ' . ($inv['number'] ?? '#' . $id) . ' is not past due.');
    }
    $step = reminder_step((int) $inv['days_overdue']);
    $address = customer_mail_address($pdo, $inv['customer_id'], null);
    $raw = live_invoice_token($pdo, $id, $inv['customer_id']);
    $mail = invoice_mail($pdo, $inv, $raw, $message, 'invoice_reminder', $step);
    $outbox = queue_customer_mail($pdo, $inv['customer_id'], $address, 'invoice_reminder', 'invoice', $id, $mail);
    $pdo->prepare('UPDATE invoices SET last_reminder_at = now(), reminder_count = reminder_count + 1 WHERE id = :id')->execute(['id' => $id]);
    return ['outbox_id' => $outbox, 'step' => $step, 'days_overdue' => (int) $inv['days_overdue'], 'reminder_count' => (int) $inv['reminder_count'] + 1];
}

function void_invoice(PDO $pdo, int $id, int $by, string $reason): array
{
    $st = $pdo->prepare('SELECT (i).id, (i).number, (i).total, (i).currency, (i).customer_id, (i).journal_entry_id FROM gl_invoice_void(:id, :by, :r) i');
    $st->execute(['id' => $id, 'by' => $by, 'r' => $reason]);
    $r = $st->fetch();
    $pdo->prepare("UPDATE notification_outbox SET body = '(void)' WHERE kind = 'invoice_link' AND record_type = 'invoice' AND record_id = :i")->execute(['i' => $id]);
    $pdo->prepare('UPDATE invoice_links_secure SET rotated_at = now() WHERE invoice_id = :i AND rotated_at IS NULL')->execute(['i' => $id]);
    return ['invoice_id' => (int) $r['id'], 'number' => $r['number'], 'total' => $r['total'], 'currency' => trim((string) $r['currency']), 'customer_id' => (int) $r['customer_id'], 'journal_entry_id' => (int) $r['journal_entry_id']];
}

function write_off_invoice(PDO $pdo, int $id, int $by, string $reason, ?string $date): array
{
    $before = invoice_state($pdo, $id) ?? throw new DomainException('Not found.');
    $st = $pdo->prepare('SELECT (i).id, (i).number, (i).currency, (i).customer_id, (i).write_off_entry_id FROM gl_invoice_write_off(:id, :by, :r, COALESCE(:d, current_date)) i');
    $st->execute(['id' => $id, 'by' => $by, 'r' => $reason, 'd' => $date]);
    $r = $st->fetch();
    return ['invoice_id' => (int) $r['id'], 'number' => $r['number'], 'amount' => $before['balance_due'], 'currency' => trim((string) $r['currency']), 'customer_id' => (int) $r['customer_id'], 'journal_entry_id' => (int) $r['write_off_entry_id']];
}

/** Delete a draft (its lines first: a cascade would meet the line trigger with the invoice already gone). */
function delete_invoice(PDO $pdo, int $id, int $by): array
{
    $s = invoice_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($s['status'] !== 'draft') {
        throw new DomainException('Invoice ' . $s['number'] . ' is issued and is never deleted; void it');
    }
    $pdo->prepare('DELETE FROM invoice_lines WHERE invoice_id = :i')->execute(['i' => $id]);
    $pdo->prepare('DELETE FROM invoices WHERE id = :i')->execute(['i' => $id]);
    return $s;
}

/** Rotate the link: a new token, the old one dead, the new link mailed to the customer. */
function rotate_invoice_link(PDO $pdo, int $id, int $by): array
{
    $inv = find_invoice($pdo, $id) ?? throw new DomainException('Not found.');
    if (in_array($inv['status'], ['draft', 'void'], true)) {
        throw new DomainException('Invoice ' . ($inv['number'] ?? '#' . $id) . ' is ' . $inv['status'] . ' and has no link.');
    }
    $address = customer_mail_address($pdo, $inv['customer_id'], null);
    $raw = mint_invoice_link($pdo, $id);
    hold_invoice_link($pdo, $id, $inv['customer_id'], $raw);
    $mail = invoice_mail($pdo, $inv, $raw, null, 'invoice_sent');
    return ['outbox_id' => queue_customer_mail($pdo, $inv['customer_id'], $address, 'invoice_sent', 'invoice', $id, $mail)];
}

/** A line's request fields laid over the existing line (a change keeps what was not sent). Keys as normalize_doc_line() reads them. */
function doc_line_request(?array $existing): array
{
    $row = [];
    if ($existing !== null) {
        $row = ['item' => $existing['item_id'], 'description' => $existing['description'], 'quantity' => $existing['quantity'], 'unit_price' => $existing['unit_price'], 'tax_rate' => $existing['tax_rate_id'],
                'income_account' => $existing['income_account_id'], 'department' => $existing['department_id'], 'site' => $existing['site_id']];
    }
    foreach (['item', 'description', 'quantity', 'unit_price', 'tax_rate', 'income_account', 'department', 'site'] as $k) {
        foreach ([$k, $k . '_id'] as $name) {
            if (array_key_exists($name, $_POST)) {
                $row[$k] = is_array($_POST[$name]) ? null : trim((string) $_POST[$name]);
                break;
            }
        }
    }
    return $row;
}

/** The HTMX answer of a line write on a document: the lines region re-rendered, the totals panel out of band. $doc: kind (invoice|credit_note), the loaded document with lines. */
function answer_doc_region(PDO $pdo, string $kind, array $doc): never
{
    hx_trigger(change_event($kind));
    $fresh = $kind === 'invoice' ? find_invoice($pdo, $doc['invoice_id']) : find_credit_note($pdo, $doc['credit_note_id']);
    echo view('invoices/partials/lines.php', doc_lines_data($pdo, $kind, $fresh));
    echo view('invoices/partials/totals.php', doc_lines_data($pdo, $kind, $fresh) + ['oob' => true]);
    exit;
}

/** The data the lines and totals partials take, for an invoice or a credit note. */
function doc_lines_data(PDO $pdo, string $kind, array $d): array
{
    $isInv = $kind === 'invoice';
    $id = $isInv ? $d['invoice_id'] : $d['credit_note_id'];
    return ['kind' => $kind, 'doc' => $d, 'id' => $id, 'lines' => $d['lines'], 'editable' => $d['status'] === 'draft' && has_right('ar.write'), 'pdo' => $pdo, 'items' => items_for_lines($pdo), 'taxRates' => find_tax_rates($pdo),
            'departments' => find_live_departments($pdo), 'sites' => find_live_sites($pdo), 'currency' => $d['currency'], 'prefix' => $isInv ? 'invoice' : 'credit-note',
            'saveUrl' => $isInv ? '/invoices/line-save.php' : '/credit-notes/line-save.php', 'removeUrl' => $isInv ? '/invoices/line-remove.php' : '/credit-notes/line-remove.php', 'param' => $isInv ? 'invoice' : 'credit_note'];
}
