<?php
declare(strict_types=1);
/** /ap/ai/ — the kernel's AI statement by month (screen `ai-statements`; reports.view): one card per month, newest first — open in the OS or closed, pulled at, the ledger's state, totals per currency, late calls, the bills; months the kernel lists but the ledger has not pulled, muted; Pull now (ap.write). The kernel is asked on every load; if it does not answer the page says so and shows what the ledger holds. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/ai/handler.php';
require_right('reports.view');
$pdo = db();
$kernel = null;
$kernelError = null;
try {
    $kernel = pull_ai_periods();
} catch (AiKernelError $e) {
    $kernelError = $e->getMessage();
}
$rows = find_ai_statements($pdo, $kernel);
$may = ['pull' => has_right('ap.write'), 'import' => has_right('ap.write'), 'check' => has_right('reports.view')];
log_screen_view($pdo, 'ai-statements');
if (wants_json()) {
    respond_screen(['statements' => array_map('present_ai_statement_row', $rows), 'kernel_error' => $kernelError, 'may' => $may]);
}
$data = ['rows' => $rows, 'kernelError' => $kernelError, 'may' => $may, 'here' => here_url(), 'notice' => gl_notice($_GET['notice'] ?? null, ['pulled' => ['success', 'Pulled from the OS.']])];
if (is_htmx_request() && !is_htmx_boosted() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'ai-statements-list') {
    echo view('ai/partials/statement-cards.php', $data);
    exit;
}
render_screen('AI statements', view('ai/list.php', $data), ['activeNav' => 'ai', 'screen' => 'ai-statements', 'entity' => 'aiStatement']);
