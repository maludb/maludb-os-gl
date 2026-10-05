<?php
declare(strict_types=1);

/** The AI statement's writes (slice 7): load a pulled document, import a closed month as draft bills, check the bills against it, and the poll the worker calls. The database is the referee (db/012). */

/** Keep a pulled document (gl_ai_statement_load): answers the statement row and what changed — new | updated | unchanged. A different document for an imported month is the function's RAISE. */
function load_ai_statement(PDO $pdo, array $document): array
{
    $period = (string) ($document['period'] ?? '');
    $before = $pdo->prepare('SELECT id, document_hash, kernel_status, status FROM ai_statements WHERE period = :p');
    $before->execute(['p' => $period]);
    $was = $before->fetch() ?: null;
    $st = $pdo->prepare('SELECT (s).id, (s).period, (s).kernel_status, (s).status, (s).document_hash, (s).late_calls FROM gl_ai_statement_load(CAST(:d AS jsonb)) s');
    $st->execute(['d' => json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    $s = $st->fetch();
    $changed = $was === null ? 'new' : ($was['document_hash'] !== $s['document_hash'] || $was['kernel_status'] !== $s['kernel_status'] ? 'updated' : 'unchanged');
    return ['ai_statement_id' => (int) $s['id'], 'period' => $s['period'], 'kernel_status' => $s['kernel_status'], 'status' => $s['status'], 'late_calls' => (int) $s['late_calls'], 'changed' => $changed, 'document_hash' => $s['document_hash']];
}

/** Pull one month and keep it. Answers load_ai_statement()'s result; throws AiKernelError (the OS) or the database's RAISE (a changed imported month). */
function pull_and_load_ai_period(PDO $pdo, string $period): array
{
    $doc = pull_ai_period($period);
    return load_ai_statement($pdo, $doc);
}

/** Import a CLOSED month: one draft bill per provider and currency (gl_ai_statement_import). Answers the bills made [{provider, currency, bill_id, number, total}] and the count. */
function import_ai_statement(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT gl_ai_statement_import(:id, :by)');
    $st->execute(['id' => $id, 'by' => $by]);
    $made = (int) $st->fetchColumn();
    $b = $pdo->prepare('SELECT provider, currency, bill_id, bill_number AS number, bill_total AS total FROM mcp_ai_statement_bills WHERE ai_statement_id = :id ORDER BY provider, currency');
    $b->execute(['id' => $id]);
    $bills = array_map(static function (array $r): array { $r['currency'] = trim((string) $r['currency']); $r['bill_id'] = (int) $r['bill_id']; return $r; }, $b->fetchAll());
    return ['made' => $made, 'bills' => $bills];
}

/** Check and record the verdict (gl_ai_statement_mark_checked): ['verdict' => agrees | differs, 'rows' => the check, 'differences' => the rows that do not agree]. An unimported month is refused in words. */
function check_ai_statement(PDO $pdo, int $id, int $by): array
{
    $s = ai_statement_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($s['status'] === 'pulled') {
        throw new DomainException('Statement ' . $s['period'] . ' is not imported yet; import it first, then check it.');
    }
    $rows = ai_check_rows($pdo, $id);
    $v = $pdo->prepare('SELECT gl_ai_statement_mark_checked(:id)');
    $v->execute(['id' => $id]);
    $verdict = (string) $v->fetchColumn();
    return ['verdict' => $verdict, 'rows' => $rows, 'differences' => array_values(array_filter($rows, static fn (array $r): bool => !$r['agrees'])), 'period' => $s['period']];
}

/** What to say about one provider's difference, in words (the page and the notice). */
function ai_difference_sentence(array $r, string $period): string
{
    if ($r['bill_id'] === null) {
        return 'There is no bill for ' . ai_provider_label($r['provider']) . ' (' . number_format((float) $r['statement_amount'], 2) . ' ' . $r['currency'] . ' on the statement).';
    }
    if ($r['bill_status'] === 'void') {
        return 'The bill for ' . ai_provider_label($r['provider']) . ' (' . $r['bill_number'] . ') is void: the check differs by its whole ' . number_format((float) $r['statement_amount'], 2) . ' ' . $r['currency'] . '. Enter a bill for ' . ai_provider_label($r['provider']) . ' of '
            . number_format((float) $r['statement_amount'], 2) . ' ' . $r['currency'] . " with reference '" . $r['provider'] . ' ' . $period . "' and the check will agree.";
    }
    return ai_provider_label($r['provider']) . "'s bill " . $r['bill_number'] . ' is ' . number_format((float) $r['bills_amount'], 2) . ' ' . $r['currency'] . ' and the statement says ' . number_format((float) $r['statement_amount'], 2) . ' ' . $r['currency'] . ' (' . ($r['difference'] > 0 ? 'short by ' : 'over by ') . number_format(abs((float) $r['difference']), 2) . ').';
}

/**
 * The worker's poll (slice 8's `bin/worker.php` step `ai` calls this once an hour; `php bin/ai_poll.php` runs it by hand): when gl_settings.ai_statement_poll, list the kernel's months and pull every one — a closed month that
 * changed is the thing to catch (the load keeps an unchanged one as it is). An imported month whose document changed is refused by the database and logged `ai_statement.pull` with `after.changed = refused`; the poll goes on.
 * A new or changed month, a refusal and a failure are logged with source cron (an unchanged month is not). Answers ['skipped' => bool, 'pulled' => [...], 'refused' => [...], 'error' => ?string].
 */
function ai_poll_pass(PDO $pdo): array
{
    $out = ['skipped' => false, 'pulled' => [], 'refused' => [], 'error' => null];
    if (!(bool) $pdo->query('SELECT ai_statement_poll FROM gl_settings WHERE id = 1')->fetchColumn()) {
        return ['skipped' => true] + $out;
    }
    try {
        $months = pull_ai_periods();
    } catch (AiKernelError $e) {
        $out['error'] = $e->getMessage();
        log_activity($pdo, 'ai_statement.pull', 'ai_statement', null, ['actor_member_id' => null, 'source' => 'cron', 'after' => ['changed' => 'failed', 'error' => $e->getMessage()]]);
        return $out;
    }
    foreach ($months as $m) {
        $p = (string) $m['period'];
        try {
            $pdo->beginTransaction();
            $r = pull_and_load_ai_period($pdo, $p);
            if ($r['changed'] !== 'unchanged') {
                log_activity($pdo, 'ai_statement.pull', 'ai_statement', $r['ai_statement_id'], ['actor_member_id' => null, 'source' => 'cron', 'after' => ['period' => $p, 'kernel_status' => $r['kernel_status'], 'changed' => $r['changed']]]);
            }
            $pdo->commit();
            $out['pulled'][] = ['period' => $p, 'changed' => $r['changed'], 'kernel_status' => $r['kernel_status']];
        } catch (AiKernelError $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $out['error'] = $e->getMessage();
            log_activity($pdo, 'ai_statement.pull', 'ai_statement', null, ['actor_member_id' => null, 'source' => 'cron', 'after' => ['period' => $p, 'changed' => 'failed', 'error' => $e->getMessage()]]);
            break;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = $e instanceof PDOException ? db_message($e, 'The statement could not be kept.') : $e->getMessage();
            $sid = $pdo->prepare('SELECT id FROM ai_statements WHERE period = :p');
            $sid->execute(['p' => $p]);
            $existing = $sid->fetchColumn();
            log_activity($pdo, 'ai_statement.pull', 'ai_statement', $existing === false ? null : (int) $existing, ['actor_member_id' => null, 'source' => 'cron', 'after' => ['period' => $p, 'changed' => 'refused', 'reason' => $msg]]);
            $out['refused'][] = ['period' => $p, 'reason' => $msg];
        }
    }
    return $out;
}
