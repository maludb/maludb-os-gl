<?php
declare(strict_types=1);
/** Action `ai_statement_import` (ai_statement or period — a CLOSED month not yet imported; log `ai_statement.import` with period and bills[]): ap.write. gl_ai_statement_import() makes one draft bill per provider and currency; the controller approves them (the pause is the approval, not this). A notice `ai_statement_imported` goes to the approvers. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/ai/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$s = ai_statement_from_request($pdo);
try {
    $pdo->beginTransaction();
    $r = import_ai_statement($pdo, (int) $s['ai_statement_id'], (int) current_member_id());
    log_activity($pdo, 'ai_statement.import', 'ai_statement', (int) $s['ai_statement_id'], ['after' => ['period' => $s['period'], 'bills' => array_map(static fn (array $b): array => ['bill_id' => $b['bill_id'], 'number' => $b['number'], 'provider' => $b['provider'], 'total' => $b['total'], 'currency' => $b['currency']], $r['bills'])]]);
    notify_members_with_right('ap.approve', 'ai_statement_imported', 'ai_statement', (int) $s['ai_statement_id'], 'AI usage ' . $s['period'] . ' imported: ' . $r['made'] . ' bill' . ($r['made'] === 1 ? '' : 's') . ' await approval', implode(', ', array_map(static fn (array $b): string => $b['number'] . ' ' . $b['provider'] . ' ' . $b['total'] . ' ' . $b['currency'], $r['bills'])));
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
$list = implode(', ', array_map(static fn (array $b): string => $b['number'] . ' ' . ai_provider_label($b['provider']) . ' ' . number_format((float) $b['total'], 2) . ' ' . $b['currency'], $r['bills']));
gl_done('Imported ' . $s['period'] . ': ' . $r['made'] . ' bill' . ($r['made'] === 1 ? '' : 's') . ($r['made'] > 0 ? ' — ' . $list : ''), (int) $s['ai_statement_id'], gl_land(return_path('/ap/ai/' . (int) $s['ai_statement_id']), 'imported'), 'aiStatementChanged', ['period' => $s['period'], 'bills' => $r['bills']]);
