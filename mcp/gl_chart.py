"""General Ledger — the chart, the posting rules, fiscal time (tool surface, "The chart, the rules, fiscal time"). Reads mcp_accounts, mcp_posting_rules, mcp_fiscal_years,
mcp_fiscal_periods and gl_account_balance() / gl_account_activity() (db/015, reports.view)."""
from __future__ import annotations

import datetime

from pydantic import Field

from gl_common import RO, Where, _Base, as_of, dumps, err, lim, period_range, q_alone, to_date, word_start, words


class FindAccountsIn(_Base):
    q: str | None = Field(None, description="A code (6200), the start of a code, or words of the name. `q` alone answers a plain list (account_id, label = '6200 — Software and subscriptions')")
    account_type: str | None = Field(None, description="asset, liability, equity, income or expense")
    subtype: str | None = None
    is_bank: bool | None = None
    is_control: bool | None = None
    include_archived: bool = False
    as_of: str | None = Field(None, description="YYYY-MM-DD for the balances (today by default)")
    with_balance: bool = Field(True, description="Add each account's balance in its normal sense")
    limit: int = Field(50, ge=1, le=100)


class AccountRefIn(_Base):
    account_id: int | None = None
    code: str | None = Field(None, description="The account's code, instead of account_id")
    as_of: str | None = None
    department_id: int | None = None
    site_id: int | None = None


class AccountActivityIn(_Base):
    account_id: int | None = None
    code: str | None = None
    from_: str = Field(..., alias="from", description="YYYY-MM-DD")
    to: str = Field(..., description="YYYY-MM-DD")
    department_id: int | None = None
    site_id: int | None = None
    limit: int = Field(100, ge=1, le=100)


class PostingRulesIn(_Base):
    source: str | None = Field(None, description="invoice, bill, receipt, payment … the document kind that posts")


class FindPeriodsIn(_Base):
    q: str | None = Field(None, description="A period (2026-09) or a year (FY2026). `q` alone answers a plain list (fiscal_period_id, name)")
    fiscal_year_id: int | None = None
    status: str | None = Field(None, description="open or closed")
    date: str | None = Field(None, description="The period this date falls in")
    limit: int = Field(25, ge=1, le=100)


async def resolve_account(fetch, account_id: int | None, code: str | None) -> dict | None:
    if account_id is not None:
        rows = await fetch("SELECT account_id, code, name, account_type, normal_balance FROM mcp_accounts WHERE account_id = $1", account_id)
    elif code:
        rows = await fetch("SELECT account_id, code, name, account_type, normal_balance FROM mcp_accounts WHERE code = $1", code.strip())
    else:
        return None
    return rows[0] if rows else None


def register(mcp, fetch) -> None:
    @mcp.tool(name="find_accounts", annotations={"title": "Find accounts (the chart)", **RO})
    async def find_accounts(params: FindAccountsIn) -> str:
        """The chart of accounts, or one account: by code, name or words, by type, subtype, bank or control flag; each with its balance as of a date in its normal sense
        (an asset or expense: debits less credits; the rest: credits less debits). Call before naming an account on a line. `q` alone answers a plain list
        (account_id, label = '6200 — Software and subscriptions')."""
        w = Where()
        if not params.include_archived:
            w.raw("a.archived_at IS NULL")
        for word in words(params.q):
            n = w.p(word_start(word))
            c = w.p(word + "%")
            w.raw(f"(a.code LIKE {c} OR a.name ~* {n})")
        for col, v in (("account_type", params.account_type), ("subtype", params.subtype)):
            if v:
                w.add(f"a.{col} = {{}}", v)
        for col, v in (("is_bank", params.is_bank), ("is_control", params.is_control)):
            if v is not None:
                w.add(f"a.{col} = {{}}", v)
        bal = ""
        if params.with_balance and not q_alone(params):
            bal = f", gl_account_balance(a.account_id, {w.p(as_of(params.as_of))}) AS balance"
        rows = await fetch(f"SELECT a.account_id, a.code, a.name, a.account_type, a.subtype, a.normal_balance, a.is_bank, a.is_control, a.is_system, a.description, a.archived_at, a.code || ' — ' || a.name AS label{bal} "
                           f"FROM mcp_accounts a WHERE {w.sql()} ORDER BY a.code LIMIT {lim(params.limit, 50)}", *w.args)
        if q_alone(params):
            return dumps([{"account_id": r["account_id"], "label": r["label"]} for r in rows])
        return dumps({"as_of": as_of(params.as_of), "accounts": rows})

    @mcp.tool(name="account_balance", annotations={"title": "One account's balance", **RO})
    async def account_balance(params: AccountRefIn) -> str:
        """One account's balance as of a date in its normal sense, optionally for one department or site. By account_id or code."""
        a = await resolve_account(fetch, params.account_id, params.code)
        if a is None:
            return err("No such account (give account_id or a code from find_accounts).")
        rows = await fetch("SELECT gl_account_balance($1, $2, $3, $4) AS balance", a["account_id"], as_of(params.as_of), params.department_id, params.site_id)
        if not rows or rows[0]["balance"] is None:
            return err("Balances need the reports.view right.")
        return dumps({"account_id": a["account_id"], "code": a["code"], "name": a["name"], "normal_balance": a["normal_balance"], "as_of": as_of(params.as_of),
                      "department_id": params.department_id, "site_id": params.site_id, "balance": rows[0]["balance"]})

    @mcp.tool(name="account_activity", annotations={"title": "One account's lines with a running balance", **RO})
    async def account_activity(params: AccountActivityIn) -> str:
        """One account's posted lines over a period, oldest first, with a running balance: date, entry, source, memo, description, debit, credit, the customer or vendor, the dimensions."""
        a = await resolve_account(fetch, params.account_id, params.code)
        if a is None:
            return err("No such account (give account_id or a code from find_accounts).")
        try:
            f, t = to_date(params.from_, "from"), to_date(params.to, "to")
        except ValueError as e:
            return err(str(e))
        rows = await fetch("SELECT entry_id AS journal_entry_id, entry_number, entry_date, source, memo, description, debit, credit, running_balance, department_id, site_id, customer_id, vendor_id "
                           f"FROM gl_account_activity($1, $2, $3, $4, $5) LIMIT {lim(params.limit, 100)}", a["account_id"], f, t, params.department_id, params.site_id)
        return dumps({"account": {"account_id": a["account_id"], "code": a["code"], "name": a["name"]}, "from": f, "to": t, "lines": rows, "count": len(rows)})

    @mcp.tool(name="posting_rules", annotations={"title": "Which source posts to which accounts", **RO})
    async def posting_rules(params: PostingRulesIn) -> str:
        """Which document kind posts to which accounts: the debit, credit and tax accounts of each rule, the keyed overrides (match_key), and whether each is active."""
        w = Where()
        if params.source:
            w.add("r.source = {}", params.source)
        rows = await fetch(f"SELECT * FROM mcp_posting_rules r WHERE {w.sql()} ORDER BY r.source, r.match_key NULLS FIRST", *w.args)
        return dumps({"rules": rows})

    @mcp.tool(name="find_periods", annotations={"title": "Fiscal years and periods", **RO})
    async def find_periods(params: FindPeriodsIn) -> str:
        """The fiscal years and their periods: open, closed or reopened (by whom, why, how many times), posted and draft entry counts; the period a date falls in.
        `q` alone resolves a period (2026-09) or a year (FY2026) as a plain list (fiscal_period_id, name)."""
        w = Where()
        if params.q:
            w.add("(lower(p.name) = lower({0}) OR lower(p.fiscal_year_name) = lower({0}) OR p.name LIKE {0} || '%')", params.q.strip())
        if params.fiscal_year_id is not None:
            w.add("p.fiscal_year_id = {}", params.fiscal_year_id)
        if params.status in ("open", "closed"):
            w.add("p.status = {}", params.status)
        if params.date:
            try:
                w.add("{} BETWEEN p.start_date AND p.end_date", to_date(params.date, "date"))
            except ValueError as e:
                return err(str(e))
        rows = await fetch(f"SELECT p.fiscal_period_id, p.fiscal_year_id, p.fiscal_year_name, p.name, p.start_date, p.end_date, p.status, p.closed_at, p.closed_by_name, p.reopened_at, p.reopened_by_name, p.reopen_reason, p.reopen_count, "
                           f"p.posted_entries, p.draft_entries FROM mcp_fiscal_periods p WHERE {w.sql()} ORDER BY p.start_date DESC LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            yrs = await fetch("SELECT fiscal_year_id, name FROM mcp_fiscal_years WHERE lower(name) = lower($1)", params.q.strip())
            if yrs:
                return dumps([{"fiscal_year_id": y["fiscal_year_id"], "fiscal_year_name": y["name"], "name": y["name"]} for y in yrs])
            return dumps([{"fiscal_period_id": r["fiscal_period_id"], "name": r["name"]} for r in rows])
        years = await fetch("SELECT fiscal_year_id, name, start_date, end_date, status, closed_at, reopened_at, open_periods FROM mcp_fiscal_years ORDER BY start_date DESC")
        return dumps({"periods": rows, "fiscal_years": years})
