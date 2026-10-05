<?php
declare(strict_types=1);

/**
 * The cash slice's shared prelude (slice 6): everything slice 4's prelude loads plus the cash files. The database is the referee (db/013): a handler calls the gl_* verb and shows the sentence.
 */
require_once dirname(__DIR__) . '/receivables/handler.php';
require_once dirname(__DIR__) . '/cash/csv.php';
require_once dirname(__DIR__) . '/cash/queries.php';
require_once dirname(__DIR__) . '/cash/present.php';
require_once dirname(__DIR__) . '/cash/write.php';

/** The audit keys of a cash record for log_activity: bank_account_id (+ journal_entry_id). */
function bank_keys(?int $bankId, ?int $entryId = null): array
{
    return array_filter(['bank_account_id' => $bankId, 'journal_entry_id' => $entryId], static fn ($v) => $v !== null);
}

/** A refusal in a cash handler: the RAISE in its words as a 422, our own sentence, 404 for "Not found." (ar_refused). */
function cash_refused(PDO $pdo, Throwable $e): never
{
    ar_refused($pdo, $e);
}
