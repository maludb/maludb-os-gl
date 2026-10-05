<?php
/** Proof driver — the kernel's Actions MCP against a stubbed hook (tests/phase4/hook.py, run under the KERNEL's venv). Passes the world's ids in the environment. */
require __DIR__ . '/lib.php';
$W = p4world();
$num = fn (string $t, int $id): string => (string) one("SELECT number FROM $t WHERE id = :i", ['i' => $id]);
act(as_member(1), '/settings/items/save.php', ['code' => 'HK-1', 'name' => 'SMOKE Hook Widget', 'kind' => 'product', 'unit' => 'each', 'unit_price' => '5.00', 'income_account' => account_id('4100')]);
$env = ['P4_TOKEN' => ptoken(28), 'P4_REC_PORT' => (string) REC,
        'P4_INVOICE' => (string) $W['inv_a1'], 'P4_INVOICE_NUMBER' => $num('invoices', $W['inv_a1']), 'P4_BILL' => (string) $W['bill_h1'], 'P4_ENTRY' => (string) $W['capital'], 'P4_ENTRY_NUMBER' => $num('journal_entries', $W['capital']),
        'P4_ACME' => (string) $W['acme'], 'P4_BETA' => (string) $W['beta'], 'P4_BANK' => (string) $W['bank'], 'P4_CASH' => (string) $W['cash'], 'P4_LINE' => (string) $W['line_match'], 'P4_PERIOD' => (string) period_id('2026-02'),
        'P4_AI' => (string) $W['ai'], 'P4_TEMPLATE' => (string) $W['template'], 'P4_ACCOUNT' => (string) account_id('6200'), 'P4_MEMBER' => '28', 'P4_ITEM' => (string) one("SELECT id FROM items WHERE code = 'HK-1'")];
$cmd = '';
foreach ($env as $k => $v) { $cmd .= $k . '=' . escapeshellarg($v) . ' '; }
passthru($cmd . '/var/www/mcp/venv/bin/python ' . escapeshellarg(__DIR__ . '/hook.py') . ' 2>&1', $code);
exit($code);
