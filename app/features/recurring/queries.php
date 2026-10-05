<?php
declare(strict_types=1);

/** Recurring templates (slice 8): reads through mcp_recurring_templates / mcp_recurring_runs (documents.view); the writes are in write.php. */

const RUN_STATUS_BADGE_REC = ['drafted' => 'success', 'failed' => 'danger', 'skipped' => 'secondary'];

function template_cast(array $t): array
{
    $t['recurring_template_id'] = (int) $t['recurring_template_id'];
    $t['every_n'] = (int) $t['every_n'];
    $t['run_count'] = (int) $t['run_count'];
    $t['active'] = (bool) $t['active'];
    $t['template'] = is_string($t['template']) ? (json_decode($t['template'], true) ?: []) : ($t['template'] ?? []);
    return $t;
}

/** Each template with its newest run (status and sentence), so a card says "last run failed: …". Filters: kind, active (yes|no), q. */
function find_templates(PDO $pdo, array $filters = []): array
{
    $where = [];
    $args = [];
    if (($filters['kind'] ?? '') !== '' && isset(TEMPLATE_KINDS[$filters['kind']])) {
        $where[] = 't.kind = :k';
        $args['k'] = $filters['kind'];
    }
    if (($filters['active'] ?? '') === 'yes' || ($filters['active'] ?? '') === 'no') {
        $where[] = 't.active = :a';
        $args['a'] = $filters['active'] === 'yes' ? 't' : 'f';
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = 't.name ILIKE :q';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    $st = $pdo->prepare('SELECT t.*, (SELECT r.status FROM mcp_recurring_runs r WHERE r.recurring_template_id = t.recurring_template_id ORDER BY r.run_date DESC, r.recurring_run_id DESC LIMIT 1) AS last_status,
                                (SELECT r.detail FROM mcp_recurring_runs r WHERE r.recurring_template_id = t.recurring_template_id ORDER BY r.run_date DESC, r.recurring_run_id DESC LIMIT 1) AS last_detail
                           FROM mcp_recurring_templates t' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY (NOT t.active), t.next_date, lower(t.name)');
    $st->execute($args);
    return array_map('template_cast', $st->fetchAll());
}

/** One template with its runs (newest first), each with the document's label and link. */
function find_template(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_recurring_templates WHERE recurring_template_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $t = template_cast($r);
    $runs = $pdo->prepare('SELECT * FROM mcp_recurring_runs WHERE recurring_template_id = :id ORDER BY run_date DESC, recurring_run_id DESC LIMIT 60');
    $runs->execute(['id' => $id]);
    $t['runs'] = array_map(static function (array $x) use ($pdo): array {
        $x['recurring_run_id'] = (int) $x['recurring_run_id'];
        $x['record_id'] = $x['record_id'] === null ? null : (int) $x['record_id'];
        $x['record_label'] = $x['record_id'] === null ? null : record_label($pdo, (string) $x['record_type'], (int) $x['record_id']);
        $x['record_url'] = $x['record_id'] === null ? null : record_url((string) $x['record_type'], (int) $x['record_id']);
        return $x;
    }, $runs->fetchAll());
    $f = $pdo->prepare("SELECT detail FROM mcp_recurring_runs WHERE recurring_template_id = :id AND status = 'failed' ORDER BY run_date DESC LIMIT 1");
    $f->execute(['id' => $id]);
    $t['last_failed'] = $f->fetchColumn() ?: null;
    return $t;
}

function template_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS recurring_template_id, name, kind, cadence, every_n, next_date, end_date, template, active, run_count FROM recurring_templates WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['template'] = json_decode((string) $r['template'], true) ?: [];
    $r['recurring_template_id'] = (int) $r['recurring_template_id'];
    $r['active'] = (bool) $r['active'];
    return $r;
}

function template_log_state(array $s): array
{
    return ['name' => $s['name'], 'kind' => $s['kind'], 'cadence' => $s['cadence'], 'every_n' => (int) $s['every_n'], 'next_date' => $s['next_date'], 'end_date' => $s['end_date'], 'active' => (bool) $s['active'], 'lines' => count($s['template']['lines'] ?? [])];
}
