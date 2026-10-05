<?php
declare(strict_types=1);
/**
 * Action `ai_statement_pull` (period to pull one month, else every month the kernel lists; log `ai_statement.pull` with period, kernel_status, changed): ap.write. The kernel's document is kept verbatim (gl_ai_statement_load);
 * a different document for an already imported month is refused in the database's words, shown and logged with changed = refused. The kernel not answering is 502 in words; a token it refuses is a sentence.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/ai/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$me = (int) current_member_id();
$only = trim((string) req_val('period'));
if ($only !== '' && !preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $only)) {
    gl_refuse_fields(['period' => 'A period is written YYYY-MM.']);
}
try {
    $periods = $only !== '' ? [$only] : array_column(pull_ai_periods(), 'period');
} catch (AiKernelError $e) {
    refuse($e->httpStatus, $e->getMessage());
}
if ($periods === []) {
    gl_done('The OS lists no month yet; nothing to pull', null, gl_land(return_path('/ap/ai/'), 'pulled'), 'aiStatementChanged', ['pulled' => [], 'refused' => []]);
}
$pulled = [];
$refused = [];
foreach ($periods as $p) {
    try {
        $r = pull_ai_period_row($pdo, $p, $me);
        $pulled[] = $r;
    } catch (AiKernelError $e) {
        refuse($e->httpStatus, $e->getMessage());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (string) $e->getCode() === 'P0001') {
            $msg = db_message($e, 'That statement could not be kept.');
            log_activity($pdo, 'ai_statement.pull', 'ai_statement', null, ['after' => ['period' => $p, 'changed' => 'refused', 'reason' => $msg]]);
            $refused[] = ['period' => $p, 'reason' => $msg];
            continue;
        }
        throw $e;
    }
}
if ($pulled === [] && $refused !== []) {
    $m = $refused[0]['reason'];
    emit_action_status(false, ['errors' => [$m], 'fields' => (object) []]);
    if (wants_json()) {
        respond_invalid([$m]);
    }
    refuse(422, $m);
}
$words = array_map(static fn (array $r): string => $r['period'] . ' (' . $r['changed'] . ', ' . $r['kernel_status'] . ')', $pulled);
gl_done('Pulled ' . count($pulled) . ' month' . (count($pulled) === 1 ? '' : 's') . ': ' . implode(', ', $words) . ($refused !== [] ? '; refused ' . count($refused) . ': ' . $refused[0]['reason'] : ''), $pulled[0]['ai_statement_id'], gl_land(return_path('/ap/ai/'), 'pulled'), 'aiStatementChanged',
    ['pulled' => $pulled, 'refused' => $refused]);

/** Pull, keep and log one month in its own transaction. */
function pull_ai_period_row(PDO $pdo, string $period, int $by): array
{
    $pdo->beginTransaction();
    $r = pull_and_load_ai_period($pdo, $period);
    log_activity($pdo, 'ai_statement.pull', 'ai_statement', $r['ai_statement_id'], ['after' => ['period' => $period, 'kernel_status' => $r['kernel_status'], 'changed' => $r['changed']]]);
    $pdo->commit();
    return ['ai_statement_id' => $r['ai_statement_id'], 'period' => $period, 'kernel_status' => $r['kernel_status'], 'status' => $r['status'], 'changed' => $r['changed']];
}
