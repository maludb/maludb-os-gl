"""gl_records_mcp — General Ledger's record-memory server (PostgreSQL, the mcp_* views and the db/015 statement functions, read-only).

Tool surface: docs/gl-mcp-tool-surface.md. Every tool reads as the asking member, so a person sees what the screens show them (the views and the functions decide:
reports.view, documents.view, parties.view, and contact details only with ar.write / ap.write). An agent sees only the tools the kernel granted it (server_common). The kernel's own
token reaches app_roles and the three shares (account_balances, ap_due_summary, ar_aging_summary) and nothing else.
"""
from __future__ import annotations

from mcp.server.fastmcp import FastMCP

import db
from gl_common import RO, dumps
from gl_misc import SearchIn

mcp = FastMCP("gl_records_mcp")


async def _pool():
    return await db.get_pool(db.ENV["MCP_RECORDS_DB_USER"], db.ENV["MCP_RECORDS_DB_PASSWORD"])


async def fetch(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


@mcp.tool(name="records_search", annotations={"title": "Records search (SQL)", **RO})
async def records_search(params: SearchIn) -> str:
    """The long tail: one read-only SELECT over General Ledger's views — chart and rules (mcp_accounts, mcp_posting_rules, mcp_settings, mcp_departments, mcp_sites, mcp_members), fiscal time (mcp_fiscal_years,
    mcp_fiscal_periods), the journal (mcp_journal_entries, mcp_journal_lines), receivables (mcp_customers, mcp_invoices, mcp_invoice_lines, mcp_credit_notes, mcp_credit_note_lines, mcp_credit_applications,
    mcp_receipts, mcp_receipt_applications, mcp_receipt_claims, mcp_deposits), payables (mcp_vendors, mcp_bills, mcp_bill_lines, mcp_vendor_credits, mcp_vendor_credit_lines, mcp_vendor_credit_applications,
    mcp_payments, mcp_payment_applications, mcp_payment_runs), the AI statement (mcp_ai_statements, mcp_ai_statement_lines, mcp_ai_statement_bills), cash (mcp_bank_accounts, mcp_bank_imports,
    mcp_bank_transactions, mcp_bank_reconciliations, mcp_transfers), the rest (mcp_items, mcp_tax_rates, mcp_party_contacts, mcp_attachments, mcp_notes, mcp_recurring_templates, mcp_recurring_runs,
    mcp_agent_dispatches, mcp_notifications, mcp_my_tokens) and the readers gl_trial_balance(date), gl_profit_and_loss(from,to), gl_balance_sheet(date), gl_cash_flow(from,to), gl_ar_aging(date),
    gl_ap_aging(date), gl_control_check(date), gl_account_balance(account_id,date), gl_account_activity(account_id,from,to), gl_cash_position(date), gl_unposted_summary(). Already scoped to what you may see.
    Money is a numeric string. One statement, 5 s timeout, 200 rows."""
    return await db.run_search(await _pool(), params.sql)


@mcp.tool(name="app_roles", annotations={"title": "General Ledger's roles and rights", **RO})
async def app_roles() -> str:
    """The roles General Ledger offers and the rights each gives — what the Business OS kernel reads to let a super-admin grant them (schema os.app-roles/1, maludb-os-integration 0.4.x).
    The catalogue is not about anyone, so the kernel's own token may read it; so may any person or agent."""
    pool = await _pool()
    async with pool.acquire() as con:
        rights = await con.fetch("SELECT right_key, description FROM gl_rights ORDER BY sort_order, right_key")
        roles = await con.fetch("SELECT role_key, name, description, capability, is_admin, sort_order, rights FROM mcp_app_roles ORDER BY sort_order")
    return dumps({
        "schema": "os.app-roles/1",
        "rights": [{"key": r["right_key"], "description": r["description"]} for r in rights],
        "roles": [{"key": r["role_key"], "name": r["name"], "description": r["description"], "capability": r["capability"], "is_admin": r["is_admin"], "rights": list(r["rights"])} for r in roles],
    })


import gl_ap
import gl_ar
import gl_cash
import gl_chart
import gl_journal
import gl_misc
import gl_people
import gl_statements

for module in (gl_people, gl_chart, gl_statements, gl_journal, gl_ar, gl_ap, gl_cash, gl_misc):
    module.register(mcp, fetch)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_RECORDS_DB_USER", "MCP_RECORDS_DB_PASSWORD", port=int(db.ENV["MCP_RECORDS_PORT"]), endpoint_name="Records MCP")
