<?php
declare(strict_types=1);
/** /settings/posting-rules/{id}/edit — a rule's accounts (screen `posting-rule-edit`; settings.manage). /settings/posting-rules/new?source= starts a keyed rule. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
require_once dirname(__DIR__, 3) . '/app/views/shared/account-select.php';
require_right('settings.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('rule');
$cur = $id === null ? null : (find_posting_rule($pdo, $id) ?? refuse(404, 'Posting rule not found.'));
$source = $cur['source'] ?? (isset(POSTING_SOURCES[request_string('source')]) ? request_string('source') : 'invoice');
log_screen_view($pdo, 'posting-rule-edit');
if (wants_json()) {
    respond_screen(['posting_rule' => $cur === null ? null : present_posting_rule($cur), 'sources' => POSTING_SOURCES, 'usual' => POSTING_USUAL, 'accounts' => accounts_for_select($pdo)]);
}
render_screen($cur === null ? 'Add a posting rule' : 'Change the rule: ' . $cur['source_label'], view('settings/posting-rule-form.php', ['cur' => $cur, 'source' => $source, 'pdo' => $pdo]), ['activeNav' => 'posting-rules', 'screen' => 'posting-rule-edit', 'entity' => 'postingRule', 'recordId' => $id === null ? '' : (string) $id]);
