"""General Ledger — the statements (tool surface, "The statements"). One call per statement over the db/015 functions: the same numbers as the Statements screens and the exports.
The functions answer nothing without reports.view. Money stays a string with its own scale."""
from __future__ import annotations

import datetime
from decimal import Decimal

from pydantic import Field

from gl_common import RO, _Base, as_of, dumps, err, period_range, prior_range, to_date

ZERO = Decimal("0.00")


class AsOfIn(_Base):
    as_of: str | None = Field(None, description="YYYY-MM-DD (today by default)")


class PlIn(_Base):
    period: str | None = Field(None, description="2026-09, FY2026, this_month, last_month, this_year, last_year (default: the fiscal period covering today)")
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    compare: str | None = Field(None, description="previous (the period before) or last_year")
    department_id: int | None = None
    site_id: int | None = None


class BsIn(_Base):
    as_of: str | None = None
    compare: str | None = Field(None, description="previous (a month earlier) or last_year; adds the prior balance and the variance to each row")


class CfIn(_Base):
    period: str | None = None
    from_: str | None = Field(None, alias="from")
    to: str | None = None


def _d(x) -> Decimal:
    return Decimal(str(x if x is not None else 0))


def _var(cur: Decimal, prior: Decimal) -> dict:
    v = cur - prior
    return {"variance": v, "variance_pct": (None if prior == 0 else f"{(v / abs(prior) * 100):.1f}")}


def register(mcp, fetch) -> None:
    @mcp.tool(name="trial_balance", annotations={"title": "Trial balance", **RO})
    async def trial_balance(params: AsOfIn) -> str:
        """Every account with a balance as of a date, in debit and credit columns, with the totals — which must be equal. `balanced` says whether they are."""
        d = as_of(params.as_of)
        rows = await fetch("SELECT account_id, code, name, account_type, subtype, debit, credit FROM gl_trial_balance($1) ORDER BY code", d)
        dr = sum((_d(r["debit"]) for r in rows), ZERO)
        cr = sum((_d(r["credit"]) for r in rows), ZERO)
        return dumps({"as_of": d, "rows": rows, "total_debit": dr, "total_credit": cr, "balanced": dr == cr, "difference": dr - cr})

    @mcp.tool(name="profit_and_loss", annotations={"title": "Profit and loss", **RO})
    async def profit_and_loss(params: PlIn) -> str:
        """Income and expenses for a period by account, with net income; `compare` (previous or last_year) adds each account's prior amount and the variance; filter by department or site.
        Income and expenses are each positive in their own sense; net income is income less expenses."""
        try:
            r = await period_range(fetch, params.period, params.from_, params.to)
        except ValueError as e:
            return err(str(e))
        sql = "SELECT account_type, subtype, account_id, code, name, amount FROM gl_profit_and_loss($1, $2, $3, $4) ORDER BY code"
        cur = await fetch(sql, r["from"], r["to"], params.department_id, params.site_id)
        pr = prior_range(r, params.compare)
        prior = {x["account_id"]: x for x in await fetch(sql, pr["from"], pr["to"], params.department_id, params.site_id)} if pr else None
        accounts: dict[int, dict] = {}
        for x in cur:
            accounts[x["account_id"]] = dict(x, prior=None)
        if prior is not None:
            for aid, x in prior.items():
                accounts.setdefault(aid, dict(x, amount=ZERO, prior=None))
            for aid, row in accounts.items():
                p = _d(prior[aid]["amount"]) if aid in prior else ZERO
                row["prior"] = p
                row.update(_var(_d(row["amount"]), p))
        income = sorted((a for a in accounts.values() if a["account_type"] == "income"), key=lambda a: a["code"])
        expense = sorted((a for a in accounts.values() if a["account_type"] == "expense"), key=lambda a: a["code"])
        ti = sum((_d(a["amount"]) for a in income), ZERO)
        te = sum((_d(a["amount"]) for a in expense), ZERO)
        net = (await fetch("SELECT gl_net_income($1, $2, $3, $4) AS n", r["from"], r["to"], params.department_id, params.site_id))[0]["n"]
        out = {"from": r["from"], "to": r["to"], "department_id": params.department_id, "site_id": params.site_id, "income": income, "total_income": ti,
               "expenses": expense, "total_expenses": te, "net_income": net}
        if prior is not None:
            tpi = sum((_d(a["prior"]) for a in income), ZERO)
            tpe = sum((_d(a["prior"]) for a in expense), ZERO)
            out["prior"] = {"from": pr["from"], "to": pr["to"], "total_income": tpi, "total_expenses": tpe, "net_income": tpi - tpe}
            out["net_variance"] = _var(_d(net), tpi - tpe)
        return dumps(out)

    @mcp.tool(name="balance_sheet", annotations={"title": "Balance sheet", **RO})
    async def balance_sheet(params: BsIn) -> str:
        """Assets, liabilities and equity as of a date, with the current earnings not yet closed to equity and the equation's check (`difference` must be 0). `compare` adds a prior column."""
        d = as_of(params.as_of)
        rows = await fetch("SELECT section, subtype, account_id, code, name, balance FROM gl_balance_sheet($1) ORDER BY section, code NULLS LAST", d)
        chk = (await fetch("SELECT assets, liabilities, equity, difference FROM gl_balance_sheet_check($1)", d))
        prior_d = None
        if params.compare == "last_year":
            try:
                prior_d = d.replace(year=d.year - 1)
            except ValueError:
                prior_d = d.replace(year=d.year - 1, day=28)
        elif params.compare == "previous":
            first = d.replace(day=1) - datetime.timedelta(days=1)
            prior_d = first
        prior = {}
        if prior_d:
            for x in await fetch("SELECT section, account_id, code, balance FROM gl_balance_sheet($1)", prior_d):
                prior[(x["section"], x["account_id"], x["code"])] = _d(x["balance"])
        sections: dict[str, dict] = {}
        for x in rows:
            sec = sections.setdefault(x["section"], {"section": x["section"], "rows": [], "total": ZERO})
            row = dict(x)
            if prior_d:
                p = prior.get((x["section"], x["account_id"], x["code"]), ZERO)
                row["prior"] = p
                row.update(_var(_d(x["balance"]), p))
            sec["rows"].append(row)
            sec["total"] += _d(x["balance"])
        out = {"as_of": d, "sections": list(sections.values()), "check": chk[0] if chk else None}
        if prior_d:
            out["compare_as_of"] = prior_d
        return dumps(out)

    @mcp.tool(name="cash_flow", annotations={"title": "Cash flow statement", **RO})
    async def cash_flow(params: CfIn) -> str:
        """The indirect cash flow statement for a period: net income, the changes by section (operating, investing, financing), the net change in cash, cash at the start and the end, and the check against the books."""
        try:
            r = await period_range(fetch, params.period, params.from_, params.to)
        except ValueError as e:
            return err(str(e))
        rows = await fetch("SELECT section, line_no, label, account_id, code, amount FROM gl_cash_flow($1, $2) ORDER BY line_no", r["from"], r["to"])
        sections: dict[str, list] = {}
        for x in rows:
            sections.setdefault(x["section"], []).append(x)
        return dumps({"from": r["from"], "to": r["to"], "sections": [{"section": k, "lines": v} for k, v in sections.items()]})

    @mcp.tool(name="control_check", annotations={"title": "Control accounts against their documents", **RO})
    async def control_check(params: AsOfIn) -> str:
        """The subledger against the ledger as of a date: receivables per the invoices against the receivable account, payables per the bills against the payable account, each with its difference (0 when they tie)."""
        d = as_of(params.as_of)
        rows = await fetch("SELECT control, subledger, ledger, difference FROM gl_control_check($1)", d)
        return dumps({"as_of": d, "controls": rows, "ties": all(_d(x["difference"]) == 0 for x in rows) if rows else None})
