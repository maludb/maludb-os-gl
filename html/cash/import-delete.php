<?php
declare(strict_types=1);
/** Action `bank_import_delete` (import; its unmatched lines are removed, matched and ignored ones stay; confirm; deletion for an agent; log `bank.import_delete`): bank.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/cash/handler.php';
gl_handler_begin();
require_right('bank.write');
$pdo = db();
$id = req_id('import', 'import_id', 'bank_import_id') ?? refuse(422, 'Say which import.');
$vis = $pdo->prepare('SELECT 1 FROM mcp_bank_imports WHERE bank_import_id = :i');
$vis->execute(['i' => $id]);
$vis->fetchColumn() !== false || refuse(404, 'Import not found.');
try {
    $pdo->beginTransaction();
    $r = delete_import($pdo, $id);
    log_activity($pdo, 'bank.import_delete', 'bank_account', $r['bank_account_id'], bank_keys($r['bank_account_id']) + ['before' => ['import_id' => $id, 'filename' => $r['filename']], 'after' => ['deleted_lines' => $r['deleted'], 'kept_lines' => $r['kept']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    cash_refused($pdo, $ex);
}
gl_done('Deleted the import: ' . $r['deleted'] . ' unmatched line' . ($r['deleted'] === 1 ? '' : 's') . ' removed' . ($r['kept'] > 0 ? ', ' . $r['kept'] . ' matched or ignored kept' : ''), $id, gl_land(return_path('/cash/banks/' . $r['bank_account_id']), 'deleted'), 'bankChanged', ['deleted' => $r['deleted'], 'kept' => $r['kept']]);
