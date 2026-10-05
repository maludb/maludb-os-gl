<?php
declare(strict_types=1);

/**
 * The receivables slice's shared prelude (slice 4): the files every handler and screen of the slice needs, and the small helpers they share.
 * The database is the referee (db/010, db/013): a handler calls the gl_* verb or writes the base row inside gl_guard() and shows the sentence.
 */
require_once dirname(__DIR__) . '/records/views.php';
require_once dirname(__DIR__) . '/records/attachments.php';
require_once dirname(__DIR__) . '/records/notes.php';
require_once dirname(__DIR__) . '/accounts/queries.php';
require_once dirname(__DIR__) . '/settings/ledger.php';
require_once dirname(__DIR__) . '/activity/queries.php';
require_once dirname(__DIR__) . '/customers/queries.php';
require_once dirname(__DIR__) . '/customers/present.php';
require_once dirname(__DIR__) . '/customers/write.php';
require_once dirname(__DIR__) . '/invoices/queries.php';
require_once dirname(__DIR__) . '/invoices/present.php';
require_once dirname(__DIR__) . '/invoices/write.php';
require_once dirname(__DIR__) . '/invoices/mail.php';
require_once dirname(__DIR__) . '/credit_notes/queries.php';
require_once dirname(__DIR__) . '/credit_notes/write.php';
require_once dirname(__DIR__) . '/receipts/queries.php';
require_once dirname(__DIR__) . '/receipts/present.php';
require_once dirname(__DIR__) . '/receipts/write.php';
require_once dirname(__DIR__) . '/deposits/queries.php';
require_once dirname(__DIR__) . '/deposits/write.php';
require_once dirname(__DIR__) . '/public/invoice.php';
require_once dirname(__DIR__, 2) . '/views/shared/account-select.php';
require_once dirname(__DIR__, 2) . '/views/shared/party-select.php';
require_once dirname(__DIR__, 2) . '/views/shared/customer-select.php';

/** The `{kind}Changed` event name for a record type (customer → customerChanged, credit_note → creditNoteChanged). */
function change_event(string $type): string
{
    return lcfirst(str_replace('_', '', ucwords($type, '_'))) . 'Changed';
}

/** The audit keys of a record for log_activity (customer_id, journal_entry_id, bank_account_id) from the keys a handler knows. */
function audit_keys(?int $customerId = null, ?int $entryId = null, ?int $bankId = null): array
{
    return array_filter(['customer_id' => $customerId, 'journal_entry_id' => $entryId, 'bank_account_id' => $bankId], static fn ($v) => $v !== null);
}

/** An id from the request under any of its names (`invoice`, `invoice_id`, the route's `id`). */
function req_id(string ...$names): ?int
{
    foreach ($names as $n) {
        $v = request_integer($n);
        if ($v !== null) {
            return $v;
        }
    }
    return null;
}

/** A request address: trimmed, lower-cased, valid or null; adds to $errors when invalid. Empty = null. */
function req_email(string $name, array &$errors, ?string $keep = null): ?string
{
    if (!req_has($name)) {
        return $keep;
    }
    $v = strtolower((string) req_val($name));
    if ($v === '') {
        return null;
    }
    if (!filter_var($v, FILTER_VALIDATE_EMAIL) || mb_strlen($v) > 200) {
        $errors[$name] = 'That is not an email address.';
        return $keep;
    }
    return $v;
}

/** A request money field: parse_money(), > 0 when $positive; null when empty; adds to $errors. */
function req_money(string $name, array &$errors, string $label, bool $positive = true, ?string $keep = null): ?string
{
    if (!req_has($name)) {
        return $keep;
    }
    $raw = (string) req_val($name);
    if ($raw === '') {
        return null;
    }
    $m = parse_money($raw);
    if ($m === null || ($positive && (float) $m <= 0)) {
        $errors[$name] = $label . ' is an amount' . ($positive ? ' above zero' : '') . ', like 1,608.24.';
        return $keep;
    }
    return $m;
}

function base_currency_code(PDO $pdo): string
{
    return trim((string) $pdo->query('SELECT gl_base_currency()')->fetchColumn());
}

/**
 * A refusal in a receivables handler: our own sentence (a DomainException; "Row N: …" is a line's, "Not found." a 404) or the database's RAISE (P0001) as the 422 in its words;
 * the transaction is rolled back. Anything else is the caller's to see.
 */
function ar_refused(PDO $pdo, Throwable $e): never
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof DomainException) {
        $m = $e->getMessage();
        if (preg_match('/^Row (\d+): (.+)$/', $m, $mm)) {
            emit_action_status(false, ['errors' => [$m], 'fields' => ['lines' => $m, 'row' => (int) $mm[1]]]);
            if (wants_json()) {
                respond_invalid([$m], ['lines' => $m, 'row' => (int) $mm[1]]);
            }
        }
        refuse($m === 'Not found.' ? 404 : 422, $m);
    }
    if ($e instanceof PDOException && (string) $e->getCode() === 'P0001') {
        $m = db_message($e, 'That could not be done.');
        emit_action_status(false, ['errors' => [$m], 'fields' => (object) []]);
        if (wants_json()) {
            respond_invalid([$m]);
        }
        refuse(422, $m);
    }
    if ($e instanceof PDOException && (string) $e->getCode() === '23514') {
        refuse(422, 'That is not allowed: a number is out of range.');
    }
    throw $e;
}
