<?php
declare(strict_types=1);
/**
 * Ask the Bookkeeper (slice 8): a person's button on a bill. NOT an action of the manifest — a dispatch: one chat turn handed to the Bookkeeper agent through the kernel (dispatch_to_agent, kind bill_attached,
 * the acting person's id as X-Acting-Member), recorded in agent_dispatches and logged `agent.dispatch`. ap.write. The agent's reply is kept to 200 characters; what it drafts it drafts under its own approvals.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/recurring/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/dispatch.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$id = req_id('bill', 'bill_id', 'id') ?? refuse(422, 'Say which bill.');
require_visible($pdo, 'mcp_bills', 'bill_id', $id, 'Bill');
$agent = req_has('agent') ? (int) req_val('agent') : (int) ($pdo->query("SELECT member_id FROM mcp_members WHERE member_kind = 'agent' AND capability IS NOT NULL AND status = 'active' AND 'bookkeeper' = ANY (roles) ORDER BY member_id LIMIT 1")->fetchColumn() ?: 0);
if ($agent === 0) {
    gl_refuse_fields(['agent' => 'No Bookkeeper agent keeps the books here yet; a super-admin hires one in the OS.']);
}
try {
    $r = dispatch_to_agent($pdo, 'bill', $id, $agent, 'bill_attached', (int) current_member_id());
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
if (in_array($r['status'], ['refused', 'failed'], true)) {
    gl_refuse_fields(['agent' => (string) ($r['detail'] ?? 'The Bookkeeper could not be reached.')]);
}
gl_done($r['status'] === 'awaiting_approval' ? 'The Bookkeeper is waiting for an approval' : 'Asked the Bookkeeper', $id, gl_land(return_path('/bills/' . $id), 'asked'), 'billChanged', ['agent_dispatch_id' => $r['agent_dispatch_id'], 'status' => $r['status'], 'reply' => $r['reply']]);
