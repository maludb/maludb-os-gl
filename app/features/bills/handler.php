<?php
declare(strict_types=1);

/**
 * The payables slice's shared prelude (slice 5): everything slice 4's prelude loads (the lines editor, the shared helpers) plus the vendors', bills', vendor credits', payments' and runs' files.
 * The database is the referee (db/011, db/013): a handler calls the gl_* verb or writes the base row and shows the sentence.
 */
require_once dirname(__DIR__) . '/receivables/handler.php';
require_once dirname(__DIR__) . '/vendors/queries.php';
require_once dirname(__DIR__) . '/vendors/present.php';
require_once dirname(__DIR__) . '/vendors/write.php';
require_once dirname(__DIR__) . '/bills/queries.php';
require_once dirname(__DIR__) . '/bills/present.php';
require_once dirname(__DIR__) . '/bills/write.php';
require_once dirname(__DIR__) . '/vendor_credits/queries.php';
require_once dirname(__DIR__) . '/vendor_credits/write.php';
require_once dirname(__DIR__) . '/payments/queries.php';
require_once dirname(__DIR__) . '/payments/present.php';
require_once dirname(__DIR__) . '/payments/write.php';
require_once dirname(__DIR__) . '/payment_runs/queries.php';
require_once dirname(__DIR__) . '/payment_runs/write.php';
require_once dirname(__DIR__) . '/payment_runs/csv.php';
require_once dirname(__DIR__, 2) . '/views/shared/vendor-select.php';

/** The audit keys of a payables record for log_activity (vendor_id, journal_entry_id, bank_account_id). */
function ap_keys(?int $vendorId = null, ?int $entryId = null, ?int $bankId = null): array
{
    return array_filter(['vendor_id' => $vendorId, 'journal_entry_id' => $entryId, 'bank_account_id' => $bankId], static fn ($v) => $v !== null);
}

/** A refusal in a payables handler: a duplicate vendor reference answers with the bill it is (record_id); everything else as ar_refused(). */
function bill_refused(PDO $pdo, Throwable $e): never
{
    if ($e instanceof DuplicateBillRef) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $m = $e->getMessage();
        emit_action_status(false, ['errors' => [$m], 'fields' => ['vendor_ref' => $m, 'record_id' => $e->billId]]);
        if (wants_json()) {
            respond_invalid([$m], ['vendor_ref' => $m, 'record_id' => $e->billId]);
        }
        refuse(422, $m);
    }
    ar_refused($pdo, $e);
}
