<?php
declare(strict_types=1);

/**
 * The one funnel (memory.md §2). Every state change and every screen entry writes a row; the
 * ingest bridge ships it to the tenant's MaluDB with "application": "gl".
 * $opts: actor_member_id, actor_customer_id (a customer acting by the secure link), source, screen, route, before, after
 *        (every MONEY event puts amount and currency here), request_id, agent_run_id, department_id, site_id, kernel_request_id,
 *        journal_entry_id, period_id, customer_id, vendor_id, bank_account_id (the record each event concerns — the audit questions' keys)
 */
function log_activity(PDO $pdo, string $action, ?string $entityType = null, ?int $entityId = null, array $opts = []): void
{
    try {
        $stmt = $pdo->prepare(<<<'SQL'
            INSERT INTO activity_log
                (actor_member_id, actor_customer_id, source, action, screen, route, entity_type, entity_id,
                 before, after, request_id, session_id, ip_address, agent_run_id, department_id, site_id, kernel_request_id,
                 journal_entry_id, period_id, customer_id, vendor_id, bank_account_id)
            VALUES
                (:actor, :actor_cust, :source, :action, :screen, :route, :etype, :eid,
                 :before, :after, :rid, :sid, :ip, :run, :dept, :site, :krid, :je, :period, :customer, :vendor, :bank)
        SQL);
        $stmt->execute([
            'actor'  => array_key_exists('actor_member_id', $opts) ? $opts['actor_member_id'] : ($_SESSION['member_id'] ?? null),
            'actor_cust' => $opts['actor_customer_id'] ?? null,
            'source' => $opts['source'] ?? default_activity_source(),
            'action' => $action,
            'screen' => $opts['screen'] ?? null,
            'route'  => $opts['route'] ?? request_route(),
            'etype'  => $entityType,
            'eid'    => $entityId,
            'before' => isset($opts['before']) ? json_encode($opts['before'], JSON_THROW_ON_ERROR) : null,
            'after'  => isset($opts['after']) ? json_encode($opts['after'], JSON_THROW_ON_ERROR) : null,
            'rid'    => $opts['request_id'] ?? agent_run_request_id() ?? request_id(),
            'sid'    => PHP_SAPI === 'cli' ? null : (session_id() ?: null),
            'ip'     => PHP_SAPI === 'cli' ? null : client_ip(),
            'run'    => $opts['agent_run_id'] ?? current_agent_run_id(),
            'dept'   => $opts['department_id'] ?? null,
            'krid'   => $opts['kernel_request_id'] ?? null,
            'site'   => $opts['site_id'] ?? null,
            'je'     => $opts['journal_entry_id'] ?? ($entityType === 'journal_entry' ? $entityId : null),
            'period' => $opts['period_id'] ?? ($entityType === 'fiscal_period' ? $entityId : null),
            'customer' => $opts['customer_id'] ?? ($entityType === 'customer' ? $entityId : null),
            'vendor' => $opts['vendor_id'] ?? ($entityType === 'vendor' ? $entityId : null),
            'bank'   => $opts['bank_account_id'] ?? ($entityType === 'bank_account' ? $entityId : null),
        ]);
    } catch (Throwable $e) {
        error_log('activity_log insert failed: ' . $e->getMessage());
    }
}

/** 'web' for the UI; 'agent' under a run token; 'assistant' under a person's action token; 'cron' under CLI. */
function default_activity_source(): string
{
    if (is_action_authed()) {
        return current_agent_run_id() !== null ? 'agent' : 'assistant';
    }
    if (!empty($GLOBALS['__public_door'])) {
        return (string) $GLOBALS['__public_door'];      // 'portal' (the secure invoice link) or 'cron' (the worker)
    }
    return PHP_SAPI === 'cli' ? 'cron' : 'web';
}

function log_screen_view(PDO $pdo, string $screen, array $opts = []): void
{
    if (wants_json() && ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') !== '1') {
        return;
    }
    log_activity($pdo, 'screen.view', null, null, ['screen' => $screen] + $opts);      // $opts: after (the statement's name, the record), keys like journal_entry_id
}

/**
 * Under a run token every row of the run carries the run's own request id (the join to the
 * kernel's prompt ledger). General Ledger cannot read the kernel's database: it asks the run-facts call once
 * per request (agents.md, A7 b) — never a header the agent could set.
 */
function agent_run_request_id(): ?string
{
    static $resolved = false, $id = null;
    if (!$resolved) {
        $resolved = true;
        if (current_agent_run_id() !== null) {
            $facts = run_facts((string) ($GLOBALS['__action_token'] ?? ''));
            $id = $facts !== null && !empty($facts['valid']) ? ($facts['request_id'] ?? null) : null;
        }
    }
    return $id;
}

function request_id(): string
{
    static $id = null;
    if ($id === null) {
        $given = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        $id = $given !== '' && preg_match('/^[A-Za-z0-9._:-]{4,80}$/', $given) ? $given : bin2hex(random_bytes(8));
    }
    return $id;
}

function request_route(): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : 'GET');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH) ?: '';
    return trim($method . ' ' . $path);
}
