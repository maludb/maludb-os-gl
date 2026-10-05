<?php
declare(strict_types=1);
/** Action `posting_rule_save` (log `posting_rule.save` before/after of the changed fields): settings.manage. `rule` = an existing rule's id or a source name (+ match_key → a new keyed rule). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
gl_handler_begin();
require_right('settings.manage');
$pdo = db();
$rule = (string) (req_val('rule') ?? req_val('posting_rule') ?? '');
if ($rule === '') {
    refuse(422, 'Say which rule: its id or its source.');
}
$errors = [];
$f = [];
foreach (['debit_account' => 'debit_account_id', 'credit_account' => 'credit_account_id', 'tax_account' => 'tax_account_id'] as $short => $col) {
    $field = req_has($short) ? $short : (req_has($col) ? $col : null);
    if ($field !== null) {
        $f[$col] = gl_ref($pdo, $field, null, 'SELECT 1 FROM accounts WHERE id = :id AND archived_at IS NULL', str_replace('_', ' ', $short), $errors);
    }
}
if (req_has('match_key')) {
    $f['match_key'] = mb_substr((string) req_val('match_key'), 0, 60);
}
if (req_has('note')) {
    $f['note'] = (string) req_val('note') === '' ? null : mb_substr((string) req_val('note'), 0, 300);
}
if (req_has('active')) {
    $f['active'] = gl_yes('active');
}
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$r = gl_guard($pdo, static function () use ($pdo, $rule, $f): array {
    $pdo->beginTransaction();
    $before = ctype_digit($rule) ? find_posting_rule($pdo, (int) $rule) : null;
    $id = save_posting_rule($pdo, ctype_digit($rule) ? (int) $rule : $rule, $f);
    $r = find_posting_rule($pdo, $id);
    $after = posting_rule_log_state($r);
    if ($before === null) {
        log_activity($pdo, 'posting_rule.save', 'posting_rule', $id, ['after' => $after]);
    } else {
        $d = gl_diff(posting_rule_log_state($before), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'posting_rule.save', 'posting_rule', $id, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $r;
});
gl_done('Saved the rule for ' . $r['source_label'] . ($r['match_key'] !== null ? ' (' . $r['match_key'] . ')' : ''), $r['posting_rule_id'], gl_land(return_path('/settings/posting-rules/'), 'saved', 'posting-rule-row-' . $r['posting_rule_id']), 'postingRuleChanged', ['posting_rule_id' => $r['posting_rule_id']]);
