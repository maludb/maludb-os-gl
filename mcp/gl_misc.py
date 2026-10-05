"""General Ledger — items, tax rates, recurring templates, dispatches, the caller's own notices and tokens (tool surface, "Settings, recurring, me"), and the three K7 shares."""
from __future__ import annotations

import datetime
from decimal import Decimal

from mcp.server.fastmcp.exceptions import ToolError
from pydantic import Field

import db
from gl_common import RO, Where, _Base, as_of, dumps, err, lim, q_alone, to_date, word_start, words


class FindItemsIn(_Base):
    q: str | None = Field(None, description="A code or words of the name. `q` alone answers a plain list (item_id, name)")
    kind: str | None = Field(None, description="product or service")
    include_archived: bool = False
    limit: int = Field(25, ge=1, le=100)


class FindTaxIn(_Base):
    q: str | None = Field(None, description="Name. `q` alone answers a plain list (tax_rate_id, name)")
    include_archived: bool = False


class FindRecurringIn(_Base):
    q: str | None = Field(None, description="Name. `q` alone answers a plain list (recurring_template_id, name)")
    kind: str | None = Field(None, description="journal, bill or invoice")
    active: bool | None = None
    recurring_template_id: int | None = Field(None, description="One template with its runs")
    limit: int = Field(25, ge=1, le=100)


class DueIn(_Base):
    as_of: str | None = Field(None, description="Today by default")


class DispatchesIn(_Base):
    agent_member_id: int | None = None
    record_type: str | None = None
    record_id: int | None = None
    since: str | None = Field(None, description="YYYY-MM-DD")
    limit: int = Field(25, ge=1, le=100)


class NotifIn(_Base):
    unread_only: bool = False
    limit: int = Field(25, ge=1, le=100)


class SearchIn(_Base):
    sql: str = Field(..., description="A single read-only SELECT over the mcp_* views and the gl_* readers (see the tool's description).")


class SharedBalancesIn(_Base):
    as_of: str | None = None


class SharedApIn(_Base):
    weeks: int = Field(8, ge=1, le=26)


def _kernel_only(name: str) -> None:
    # A share fails as a TOOL ERROR (not an answer): the kernel reports it to the consumer as provider_failed, with these words.
    if not db.request_is_kernel.get():
        raise ToolError(f"{name} is for the Business OS kernel's own token only.")


def register(mcp, fetch) -> None:
    @mcp.tool(name="find_items", annotations={"title": "Find items (products and services)", **RO})
    async def find_items(params: FindItemsIn) -> str:
        """The products and services by name or code, with their accounts, price and tax rate. Call before an invoice or bill line names an item. `q` alone answers a plain list (item_id, name)."""
        w = Where()
        if not params.include_archived:
            w.raw("i.archived_at IS NULL")
        for word in words(params.q):
            n = w.p(word_start(word))
            w.raw(f"(i.name ~* {n} OR i.code ILIKE {w.p(word + '%')})")
        if params.kind:
            w.add("i.kind = {}", params.kind)
        rows = await fetch(f"SELECT i.item_id, i.code, i.name, i.kind, i.description, i.unit, i.unit_price, i.income_account_id, i.expense_account_id, i.tax_rate_id, i.archived_at FROM mcp_items i WHERE {w.sql()} ORDER BY lower(i.name) LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"item_id": r["item_id"], "name": r["name"]} for r in rows])
        return dumps({"items": rows, "count": len(rows)})

    @mcp.tool(name="find_tax_rates", annotations={"title": "Find tax rates", **RO})
    async def find_tax_rates(params: FindTaxIn) -> str:
        """The tax rates and which one is the default. `q` alone answers a plain list (tax_rate_id, name)."""
        w = Where()
        if not params.include_archived:
            w.raw("t.archived_at IS NULL")
        for word in words(params.q):
            w.add("t.name ~* {}", word_start(word))
        rows = await fetch(f"SELECT t.tax_rate_id, t.name, t.rate, t.is_default, t.archived_at FROM mcp_tax_rates t WHERE {w.sql()} ORDER BY t.rate, lower(t.name)", *w.args)
        if q_alone(params):
            return dumps([{"tax_rate_id": r["tax_rate_id"], "name": r["name"]} for r in rows])
        return dumps({"tax_rates": rows})

    @mcp.tool(name="find_recurring", annotations={"title": "Find recurring templates", **RO})
    async def find_recurring(params: FindRecurringIn) -> str:
        """The recurring templates: kind, cadence, next date, last run, on or off; with recurring_template_id, that template with the document it drafts and its runs. `q` alone answers a plain list (recurring_template_id, name)."""
        w = Where()
        if params.recurring_template_id is not None:
            w.add("t.recurring_template_id = {}", params.recurring_template_id)
        for word in words(params.q):
            w.add("t.name ~* {}", word_start(word))
        if params.kind:
            w.add("t.kind = {}", params.kind)
        if params.active is not None:
            w.add("t.active = {}", params.active)
        cols = "t.recurring_template_id, t.name, t.kind, t.cadence, t.every_n, t.next_date, t.end_date, t.last_run_at, t.run_count, t.active" + (", t.template" if params.recurring_template_id is not None else "")
        rows = await fetch(f"SELECT {cols} FROM mcp_recurring_templates t WHERE {w.sql()} ORDER BY (NOT t.active), t.next_date, lower(t.name) LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"recurring_template_id": r["recurring_template_id"], "name": r["name"]} for r in rows])
        if params.recurring_template_id is not None and rows:
            rows[0]["runs"] = await fetch("SELECT recurring_run_id, run_date, record_type, record_id, status, detail, created_at FROM mcp_recurring_runs WHERE recurring_template_id = $1 ORDER BY run_date DESC LIMIT 24", params.recurring_template_id)
        return dumps({"templates": rows, "count": len(rows)})

    @mcp.tool(name="recurring_due", annotations={"title": "Templates that fall due", **RO})
    async def recurring_due(params: DueIn) -> str:
        """The active templates whose next date is on or before a date (today by default) — what the morning should draft."""
        d = as_of(params.as_of)
        rows = await fetch("SELECT recurring_template_id, name, kind, cadence, every_n, next_date, end_date, last_run_at FROM mcp_recurring_templates WHERE active AND next_date <= $1 ORDER BY next_date, name", d)
        return dumps({"as_of": d, "due": rows, "count": len(rows)})

    @mcp.tool(name="agent_dispatches", annotations={"title": "What was handed to which agent", **RO})
    async def agent_dispatches(params: DispatchesIn) -> str:
        """What was handed to which agent — when, by whom, how (chat, wake, duty), the run and what came back (the first 200 characters)."""
        w = Where()
        if params.agent_member_id is not None:
            w.add("d.agent_member_id = {}", params.agent_member_id)
        if params.record_type:
            w.add("d.record_type = {}", params.record_type)
        if params.record_id is not None:
            w.add("d.record_id = {}", params.record_id)
        try:
            s = to_date(params.since, "since")
        except ValueError as e:
            return err(str(e))
        if s:
            w.add("d.created_at >= {}::date", s)
        rows = await fetch(f"SELECT d.agent_dispatch_id, d.record_type, d.record_id, d.agent_member_id, d.agent_name, d.kind, d.via, d.acting_member_id, d.run_id, d.status, d.reply_excerpt, d.detail, d.attempts, d.created_at, d.answered_at "
                           f"FROM mcp_agent_dispatches d WHERE {w.sql()} ORDER BY d.created_at DESC LIMIT {lim(params.limit)}", *w.args)
        return dumps({"dispatches": rows, "count": len(rows)})

    @mcp.tool(name="my_notifications", annotations={"title": "My notifications", **RO})
    async def my_notifications(params: NotifIn) -> str:
        """The caller's own in-app notifications, unread first."""
        w = Where()
        if params.unread_only:
            w.raw("n.read_at IS NULL")
        rows = await fetch(f"SELECT n.notification_id, n.kind, n.record_type, n.record_id, n.title, n.body, n.read_at, n.created_at FROM mcp_notifications n WHERE {w.sql()} ORDER BY (n.read_at IS NOT NULL), n.created_at DESC LIMIT {lim(params.limit)}", *w.args)
        return dumps({"notifications": rows, "count": len(rows)})

    @mcp.tool(name="my_tokens", annotations={"title": "My MCP tokens", **RO})
    async def my_tokens() -> str:
        """The caller's own MCP tokens: label, scope, last used, expiry — never the value."""
        return dumps({"tokens": await fetch("SELECT token_id, label, scope, last_used_at, expires_at, revoked_at, created_at FROM mcp_my_tokens ORDER BY created_at DESC")})

    # ---- the shares (K7): the kernel's own token only ------------------------------------------------------------------
    @mcp.tool(name="account_balances", annotations={"title": "Ledger balances by account (a share)", **RO})
    async def account_balances(params: SharedBalancesIn) -> str:
        """A shared tool — the Business OS kernel's own token only, for another application's dashboard over a super-admin-approved connection. Ledger balances by account (code, name, type, balance in its normal sense) as of a date.
        Never a party, a document or a person."""
        _kernel_only("account_balances")
        d = as_of(params.as_of)
        rows = await fetch("SELECT code, name, account_type, subtype, balance FROM gl_share_account_balances($1)", d)
        return dumps({"schema": "gl.account-balances/1", "as_of": d, "accounts": rows})

    @mcp.tool(name="ap_due_summary", annotations={"title": "Amounts due to vendors per week (a share)", **RO})
    async def ap_due_summary(params: SharedApIn) -> str:
        """A shared tool — the kernel's own token only. What is owed to vendors by when it falls due: overdue, then each of the next weeks, per currency, totals and bill counts only — no vendor, no bill named."""
        _kernel_only("ap_due_summary")
        rows = await fetch("SELECT bucket, from_date, to_date, currency, amount, bills FROM gl_share_ap_due($1)", params.weeks)
        return dumps({"schema": "gl.ap-due-summary/1", "weeks": params.weeks, "buckets": rows})

    @mcp.tool(name="ar_aging_summary", annotations={"title": "Receivables by aging bucket (a share)", **RO})
    async def ar_aging_summary(params: SharedBalancesIn) -> str:
        """A shared tool — the kernel's own token only. Receivables as of a date by aging bucket (current, 1–30, 31–60, 61–90, over 90) per currency, totals and invoice counts only — no customer named."""
        _kernel_only("ar_aging_summary")
        d = as_of(params.as_of)
        rows = await fetch("SELECT currency, current_due, days_1_30, days_31_60, days_61_90, days_over_90, total, open_invoices FROM gl_share_ar_aging($1)", d)
        return dumps({"schema": "gl.ar-aging-summary/1", "as_of": d, "currencies": rows})
