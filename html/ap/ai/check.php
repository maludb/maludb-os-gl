<?php
declare(strict_types=1);
/** Action `ai_statement_check` (ai_statement or period — an imported month; log `ai_statement.check` with verdict and differences[]): reports.view. gl_ai_statement_check() compares each provider's bill with the statement to the cent and the verdict is recorded; a differs verdict goes to the approvers as `ai_statement_differs`. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/ai/handler.php';
gl_handler_begin();
require_right('reports.view');
$pdo = db();
$s = ai_statement_from_request($pdo);
try {
    $pdo->beginTransaction();
    $r = check_ai_statement($pdo, (int) $s['ai_statement_id'], (int) current_member_id());
    $sentences = array_map(static fn (array $d): string => ai_difference_sentence($d, $s['period']), $r['differences']);
    log_activity($pdo, 'ai_statement.check', 'ai_statement', (int) $s['ai_statement_id'], ['after' => ['period' => $s['period'], 'verdict' => $r['verdict'], 'differences' => array_map(static fn (array $d): array => ['provider' => $d['provider'], 'currency' => $d['currency'], 'statement_amount' => $d['statement_amount'], 'bills_amount' => $d['bills_amount'], 'difference' => $d['difference'], 'bill_number' => $d['bill_number'], 'bill_status' => $d['bill_status']], $r['differences'])]]);
    if ($r['verdict'] === 'differs') {
        notify_members_with_right('ap.approve', 'ai_statement_differs', 'ai_statement', (int) $s['ai_statement_id'], 'AI usage ' . $s['period'] . ': the bills differ from the OS statement', implode(' ', $sentences));
    }
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done($r['verdict'] === 'agrees' ? 'Checked ' . $s['period'] . ': the bills agree with the statement, provider by provider, to the cent' : 'Checked ' . $s['period'] . ': the bills differ — ' . implode(' ', $sentences), (int) $s['ai_statement_id'], gl_land(return_path('/ap/ai/' . (int) $s['ai_statement_id']), $r['verdict'] === 'agrees' ? 'agrees' : 'differs'),
    'aiStatementChanged', ['period' => $s['period'], 'verdict' => $r['verdict'], 'differences' => $r['differences'], 'sentences' => $sentences]);
