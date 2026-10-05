<?php
declare(strict_types=1);
/**
 * /audit — the audit page (screen `audit`; periods.manage or journal.override; no handler). Params from, to (default the fiscal year): the periods reopened and what was posted into them after, the entries reversed and the drafts
 * voided with their reasons, the AI statement's verdicts, the control accounts' tie-out with the lines that have no document behind them, and the closed-period invariant. Every figure is a read of a view or gl_control_check().
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__) . '/app/features/statements/periods.php';
require_once dirname(__DIR__) . '/app/features/statements/queries.php';
require_once dirname(__DIR__) . '/app/features/ai/queries.php';
require_once dirname(__DIR__) . '/app/features/ai/write.php';
require_once dirname(__DIR__) . '/app/features/audit/queries.php';
require_once dirname(__DIR__) . '/app/features/audit/present.php';
require_any_right('periods.manage|journal.override');
$pdo = db();
$range = audit_range($pdo, valid_date_param('from'), valid_date_param('to'));
$a = ['range' => $range, 'reopened' => audit_reopened_periods($pdo, $range['from'], $range['to']), 'reversals' => audit_reversals($pdo, $range['from'], $range['to']), 'voids' => audit_voids($pdo, $range['from'], $range['to']),
      'ai' => audit_ai_verdicts($pdo, $range['from'], $range['to']), 'control' => audit_control_check($pdo, $range['to']), 'control_lines' => audit_control_lines($pdo, $range['to']), 'posted_after_close' => audit_posted_after_close($pdo)];
log_screen_view($pdo, 'audit');
if (wants_json()) {
    respond_screen(present_audit($a));
}
render_screen('Audit', view('audit.php', ['a' => $a, 'tz' => member_timezone(), 'here' => here_url(), 'currency' => base_currency($pdo)]), ['activeNav' => 'audit', 'screen' => 'audit', 'entity' => 'audit']);
