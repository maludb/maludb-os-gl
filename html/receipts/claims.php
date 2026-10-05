<?php
declare(strict_types=1);
/** /receipts/claims — what customers said on the link that is not yet confirmed (screen `claims`; documents.view). Param: status (submitted by default; confirmed, dismissed, or all). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('documents.view');
$pdo = db();
$status = request_string('status', 'submitted');
if (!in_array($status, ['submitted', 'confirmed', 'dismissed', 'all'], true)) {
    refuse(422, 'The status is submitted, confirmed, dismissed or all.');
}
$rows = find_claims($pdo, $status === 'all' ? '' : $status);
$may = ['write' => has_right('ar.write')];
log_screen_view($pdo, 'claims');
if (wants_json()) {
    respond_screen(['claims' => $rows, 'status' => $status, 'may' => $may]);
}
render_screen('Said paid', view('receipts/claims.php', ['rows' => $rows, 'status' => $status, 'may' => $may, 'here' => here_url(), 'notice' => gl_notice($_GET['notice'] ?? null, ['dismissed' => ['success', 'The claim was dismissed.']])]),
    ['activeNav' => 'receipts', 'screen' => 'claims', 'entity' => 'receipt']);
