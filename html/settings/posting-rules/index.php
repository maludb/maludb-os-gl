<?php
declare(strict_types=1);
/** /settings/posting-rules/ — which source posts where (screen `posting-rules`; settings.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
require_right('settings.manage');
$pdo = db();
$rows = find_posting_rules($pdo);
log_screen_view($pdo, 'posting-rules');
if (wants_json()) {
    respond_screen(['posting_rules' => array_map('present_posting_rule', $rows), 'sources' => POSTING_SOURCES]);
}
render_screen('Posting rules', view('settings/posting-rules.php', ['rows' => $rows, 'here' => here_url(), 'notice' => gl_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved the rule.']])]),
    ['activeNav' => 'posting-rules', 'screen' => 'posting-rules', 'entity' => 'postingRule']);
