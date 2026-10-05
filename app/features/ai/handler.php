<?php
declare(strict_types=1);

/** The AI statement slice's prelude (slice 7): the payables prelude (the bills are slice 5's) plus the AI files. */
require_once dirname(__DIR__) . '/bills/handler.php';
require_once dirname(__DIR__) . '/ai/kernel.php';
require_once dirname(__DIR__) . '/ai/queries.php';
require_once dirname(__DIR__) . '/ai/present.php';
require_once dirname(__DIR__) . '/ai/write.php';

/** The statement an action names: `ai_statement` (its id), `statement`, or `period` (YYYY-MM); visible through the view, or a refusal. */
function ai_statement_from_request(PDO $pdo): array
{
    $id = req_id('ai_statement', 'ai_statement_id', 'statement', 'id');
    $p = trim((string) req_val('period'));
    if ($p === '' && preg_match('/^[0-9]{4}-[0-9]{2}$/', trim((string) req_val('statement')))) {
        $p = trim((string) req_val('statement'));          // the manifest's `statement` may be the month itself
    }
    if ($id === null && $p !== '') {
        $st = $pdo->prepare('SELECT ai_statement_id FROM mcp_ai_statements WHERE period = :p');
        $st->execute(['p' => $p]);
        $f = $st->fetchColumn();
        $id = $f === false ? null : (int) $f;
    }
    $id ?? refuse(422, 'Say which statement (ai_statement, or period as YYYY-MM).');
    require_visible($pdo, 'mcp_ai_statements', 'ai_statement_id', $id, 'AI statement');
    return ai_statement_state($pdo, $id) ?? refuse(404, 'AI statement not found.');
}
