<?php
declare(strict_types=1);

/**
 * Handing something to an OS agent (slice 8): a person's "Ask the Bookkeeper" on a bill, a reconciliation out of balance, a closed AI statement. One chat turn through the kernel's chat endpoint
 * (`agents/chat.php?agent=<id>`, application token, `X-Acting-Member` = the person) recorded in `agent_dispatches`; the agent's own actions are the kernel's and the actions MCP's, under its approvals.
 * A failed one is retried by the worker (three attempts); the reply is kept to 200 characters.
 */

const DISPATCH_KINDS = ['bill_attached', 'reconciliation_out', 'statement_closed', 'claim_submitted', 'ask'];

/** The utterance an agent is given for a kind and record — the facts, in one sentence, and what to do. */
function dispatch_utterance(string $kind, ?string $recordType, ?int $recordId): string
{
    $what = $recordType === null || $recordId === null ? 'a record' : str_replace('_', ' ', $recordType) . ' ' . $recordId;
    return match ($kind) {
        'bill_attached'      => 'A document was attached to ' . $what . '. Read it, draft or complete the bill from it, and tell me what needs a person.',
        'reconciliation_out' => 'A bank reconciliation is out of balance (' . $what . '). Find what is unmatched and tell me what needs a person.',
        'statement_closed'   => 'The OS closed an AI usage statement (' . $what . '). Import it as bills and tell me what needs a person.',
        'claim_submitted'    => 'A customer says they paid (' . $what . '). Check the bank lines and tell me whether it can be matched.',
        default              => 'Please look at ' . $what . ' and tell me what needs a person.',
    };
}

/** Make one chat turn for a dispatch row. Answers ['status' => answered | refused | failed | awaiting_approval, 'detail', 'reply', 'run_id']. */
function dispatch_turn(string $agent, int $acting, string $utterance, string $requestId): array
{
    $answer = kernel_call('POST', '/api/v1/agents/chat.php?agent=' . rawurlencode($agent), ['utterance' => $utterance, 'context' => ['application' => app_key()], 'conversation_id' => $requestId, 'wait' => 20], ['X-Acting-Member: ' . $acting], 30);
    if ($answer === null || $answer['status'] >= 500 || $answer['status'] === 401) {
        return ['status' => 'failed', 'detail' => $answer === null ? 'The OS did not answer.' : 'The OS answered HTTP ' . $answer['status'] . '.', 'reply' => null, 'run_id' => null];
    }
    $b = $answer['body'] ?? [];
    if ($answer['status'] >= 400) {
        return ['status' => 'refused', 'detail' => (string) ($b['error']['message'] ?? 'The OS refused it.'), 'reply' => null, 'run_id' => null];
    }
    $run = isset($b['run_id']) ? (int) $b['run_id'] : null;
    if (($b['status'] ?? '') === 'awaiting_approval') {
        return ['status' => 'awaiting_approval', 'detail' => null, 'reply' => null, 'run_id' => $run];
    }
    if (empty($b['finished']) && ($b['status'] ?? '') !== 'succeeded') {
        return ['status' => 'failed', 'detail' => 'The turn did not finish (' . (string) ($b['status'] ?? 'unknown') . ').', 'reply' => null, 'run_id' => $run];
    }
    return ['status' => 'answered', 'detail' => null, 'reply' => mb_substr((string) ($b['reply'] ?? ''), 0, 200), 'run_id' => $run];
}

/**
 * Hand a record to an agent: writes the row, makes the turn, records the outcome, logs `agent.dispatch` (kind, via, status, never the reply). $agentId must be an active agent here (else a sentence).
 * Answers the row as ['agent_dispatch_id', 'status', 'detail', 'reply'].
 */
function dispatch_to_agent(PDO $pdo, ?string $recordType, ?int $recordId, int $agentId, string $kind, ?int $actingMemberId, string $source = 'web'): array
{
    if (!in_array($kind, DISPATCH_KINDS, true)) {
        throw new DomainException('Unknown dispatch kind ' . $kind . '.');
    }
    $a = $pdo->prepare("SELECT 1 FROM members WHERE id = :id AND member_kind = 'agent' AND status = 'active' AND capability IS NOT NULL");
    $a->execute(['id' => $agentId]);
    if ($a->fetchColumn() === false) {
        throw new DomainException('That agent does not keep the books here.');
    }
    $requestId = 'gl-dispatch-' . bin2hex(random_bytes(6));
    $ins = $pdo->prepare("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, request_id) VALUES (:t, :i, :a, :k, 'chat', :m, :r) RETURNING id");
    $ins->execute(['t' => $recordType, 'i' => $recordId, 'a' => $agentId, 'k' => $kind, 'm' => $actingMemberId, 'r' => $requestId]);
    $id = (int) $ins->fetchColumn();
    $r = dispatch_turn((string) $agentId, $actingMemberId ?? $agentId, dispatch_utterance($kind, $recordType, $recordId), $requestId);
    finish_dispatch($pdo, $id, $r, 1, $source);
    return ['agent_dispatch_id' => $id, 'status' => $r['status'], 'detail' => $r['detail'], 'reply' => $r['reply']];
}

function finish_dispatch(PDO $pdo, int $id, array $r, int $attempts, string $source): void
{
    $pdo->prepare("UPDATE agent_dispatches SET status = :s, detail = :d, reply_excerpt = :r, run_id = :run, attempts = :a, answered_at = CASE WHEN :s2 IN ('answered', 'awaiting_approval') THEN now() ELSE answered_at END WHERE id = :id")
        ->execute(['s' => $r['status'], 'd' => $r['detail'], 'r' => $r['reply'], 'run' => $r['run_id'], 'a' => $attempts, 's2' => $r['status'], 'id' => $id]);
    $row = $pdo->query('SELECT record_type, record_id, kind, agent_member_id, acting_member_id FROM agent_dispatches WHERE id = ' . $id)->fetch();
    log_activity($pdo, 'agent.dispatch', 'agent_dispatch', $id, ['source' => $source, 'actor_member_id' => $source === 'cron' ? null : $row['acting_member_id'],
        'after' => ['kind' => $row['kind'], 'via' => 'chat', 'status' => $r['status'], 'agent_member_id' => (int) $row['agent_member_id'], 'record_type' => $row['record_type'], 'record_id' => $row['record_id'], 'attempts' => $attempts]]);
}

/** The worker's retry: failed dispatches with fewer than three attempts, oldest first. Answers counts. */
function retry_failed_dispatches(PDO $pdo, int $limit = 20): array
{
    $out = ['retried' => 0, 'answered' => 0, 'failed' => 0];
    $rows = $pdo->query("SELECT * FROM agent_dispatches WHERE status = 'failed' AND attempts < 3 ORDER BY id LIMIT " . (int) $limit)->fetchAll();
    foreach ($rows as $d) {
        $r = dispatch_turn((string) $d['agent_member_id'], (int) ($d['acting_member_id'] ?? $d['agent_member_id']), dispatch_utterance($d['kind'], $d['record_type'], $d['record_id'] === null ? null : (int) $d['record_id']), (string) $d['request_id']);
        finish_dispatch($pdo, (int) $d['id'], $r, (int) $d['attempts'] + 1, 'cron');
        $out['retried']++;
        $out[$r['status'] === 'failed' ? 'failed' : 'answered']++;
    }
    return $out;
}
