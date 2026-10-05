<?php
declare(strict_types=1);
/** Action `journal_entry_post` (`entry` a balanced draft in an open period; confirm; money_out for an agent; log `journal_entry.post` with number, amount, currency, period, reopened_period, period_id, journal_entry_id): journal.write. gl_entry_post() is the referee. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/present.php';
gl_handler_begin();
require_right('journal.write');
$pdo = db();
$e = load_entry($pdo);
$id = $e['journal_entry_id'];
try {
    $pdo->beginTransaction();
    $p = post_entry($pdo, $id, (int) current_member_id());
    $period = $p['period_id'] === null ? null : $pdo->query('SELECT name, reopen_count FROM fiscal_periods WHERE id = ' . (int) $p['period_id'])->fetch();
    log_activity($pdo, 'journal_entry.post', 'journal_entry', $id, ['journal_entry_id' => $id, 'period_id' => $p['period_id'],
        'after' => ['number' => $p['number'], 'amount' => $p['total'], 'currency' => $p['currency'], 'period' => $period['name'] ?? null, 'reopened_period' => (int) ($period['reopen_count'] ?? 0) > 0, 'entry_date' => $p['entry_date'], 'source' => $e['source']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    entry_refused($pdo, $ex);
}
gl_done('Posted ' . $p['number'] . ' (' . number_format((float) $p['total'], 2) . ' ' . $p['currency'] . ')', $id, gl_land(return_path('/journal/' . $id), 'posted'), 'journalEntryChanged', ['journal_entry_id' => $id, 'number' => $p['number'], 'amount' => $p['total'], 'currency' => $p['currency']]);
