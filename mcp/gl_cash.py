"""General Ledger — the kernel's AI statement and cash (tool surface, "The kernel's AI statement" and "Cash"). Reads mcp_ai_statements / _lines / _bills, gl_ai_statement_check(), gl_cash_position(),
mcp_bank_accounts, mcp_bank_transactions, gl_bank_candidates(), mcp_bank_reconciliations, mcp_transfers."""
from __future__ import annotations

import datetime
import re
from decimal import Decimal

from pydantic import Field

from gl_common import RO, Where, _Base, as_of, dumps, err, lim, month_end, q_alone, to_date, word_start, words


class AiStatusIn(_Base):
    q: str | None = Field(None, description="A month (2026-09). `q` alone answers a plain list (ai_statement_id, period)")
    period: str | None = None
    status: str | None = Field(None, description="pulled, imported, agrees or differs")
    limit: int = Field(12, ge=1, le=100)


class AiLinesIn(_Base):
    ai_statement_id: int | None = None
    period: str | None = None
    group_by: str | None = Field(None, description="provider, department, agent or model — sums per group; omit for the verbatim lines")
    limit: int = Field(100, ge=1, le=100)


class AiCheckIn(_Base):
    ai_statement_id: int | None = None
    period: str | None = None


class CashPosIn(_Base):
    as_of: str | None = None
    trend: bool = Field(False, description="Add month-end ledger balances for the last twelve months")


class FindBanksIn(_Base):
    q: str | None = Field(None, description="Name (word start). `q` alone answers a plain list (bank_account_id, name)")
    kind: str | None = None
    include_archived: bool = False


class UnmatchedIn(_Base):
    q: str | None = Field(None, description="A reference or words of the description. `q` alone answers a plain list (bank_transaction_id, label)")
    bank_account_id: int | None = None
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    status: str = Field("unmatched", description="unmatched (default), matched or ignored")
    limit: int = Field(25, ge=1, le=100)


class CandidatesIn(_Base):
    bank_transaction_id: int


class InTransitIn(_Base):
    bank_account_id: int | None = None
    as_of: str | None = None
    limit: int = Field(50, ge=1, le=100)


class FindReconsIn(_Base):
    q: str | None = Field(None, description="A statement date (2026-09-30). `q` alone answers a plain list (bank_reconciliation_id, label)")
    bank_account_id: int | None = None
    status: str | None = Field(None, description="in_progress, completed or reopened")
    limit: int = Field(25, ge=1, le=100)


class FindTransfersIn(_Base):
    q: str | None = Field(None, description="A number. `q` alone answers a plain list (transfer_id, label)")
    bank_account_id: int | None = None
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    limit: int = Field(25, ge=1, le=100)


async def _statement(fetch, sid, period):
    if sid is not None:
        r = await fetch("SELECT ai_statement_id, period FROM mcp_ai_statements WHERE ai_statement_id = $1", sid)
    elif period:
        r = await fetch("SELECT ai_statement_id, period FROM mcp_ai_statements WHERE period = $1", period.strip())
    else:
        return None
    return r[0] if r else None


def register(mcp, fetch) -> None:
    @mcp.tool(name="ai_statement_status", annotations={"title": "The kernel's AI statement, month by month", **RO})
    async def ai_statement_status(params: AiStatusIn) -> str:
        """The months as the ledger holds them: open or closed in the OS, pulled, imported, agrees or differs, totals per currency, late calls, the bills made. `q` alone resolves a month as a plain list (ai_statement_id, period)."""
        w = Where()
        q = params.q or params.period
        if q:
            w.add("s.period LIKE {} || '%'", q.strip())
        if params.status:
            w.add("s.status = {}", params.status)
        rows = await fetch(f"SELECT s.ai_statement_id, s.period, s.period_start, s.period_end, s.kernel_status, s.status, s.pulled_at, s.imported_at, s.checked_at, s.late_calls, s.totals, s.line_count, s.bill_count "
                           f"FROM mcp_ai_statements s WHERE {w.sql()} ORDER BY s.period DESC LIMIT {lim(params.limit, 12)}", *w.args)
        if q_alone(params):
            return dumps([{"ai_statement_id": r["ai_statement_id"], "period": r["period"]} for r in rows])
        ids = [r["ai_statement_id"] for r in rows]
        bills = await fetch("SELECT ai_statement_id, provider, currency, bill_id, bill_number, bill_status, bill_total FROM mcp_ai_statement_bills WHERE ai_statement_id = ANY($1) ORDER BY provider", ids) if ids else []
        for r in rows:
            r["bills"] = [{k: b[k] for k in ("provider", "currency", "bill_id", "bill_number", "bill_status", "bill_total")} for b in bills if b["ai_statement_id"] == r["ai_statement_id"]]
        return dumps({"statements": rows, "count": len(rows)})

    @mcp.tool(name="ai_statement_lines", annotations={"title": "One month's AI lines", **RO})
    async def ai_statement_lines(params: AiLinesIn) -> str:
        """One month's lines verbatim — provider, model, department, agent, application, calls, late calls, tokens, amount at four decimals — or summed by provider, department, agent or model."""
        s = await _statement(fetch, params.ai_statement_id, params.period)
        if s is None:
            return err("No such AI statement month (give ai_statement_id or period like 2026-09).")
        sid = s["ai_statement_id"]
        if params.group_by:
            col = {"provider": "provider", "department": "COALESCE(department, 'No department')", "agent": "COALESCE(agent, 'No agent')", "model": "model"}.get(params.group_by)
            if col is None:
                return err("group_by is provider, department, agent or model.")
            rows = await fetch(f"SELECT {col} AS \"group\", currency, sum(calls) AS calls, sum(late_calls) AS late_calls, sum(input_tokens) AS input_tokens, sum(output_tokens) AS output_tokens, sum(amount) AS amount "
                               f"FROM mcp_ai_statement_lines WHERE ai_statement_id = $1 GROUP BY 1, 2 ORDER BY 7 DESC LIMIT {lim(params.limit, 100)}", sid)
            return dumps({"period": s["period"], "group_by": params.group_by, "rows": rows})
        rows = await fetch(f"SELECT line_no, provider, model, department, agent, application, calls, late_calls, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, amount, currency, note "
                           f"FROM mcp_ai_statement_lines WHERE ai_statement_id = $1 ORDER BY line_no LIMIT {lim(params.limit, 100)}", sid)
        return dumps({"period": s["period"], "lines": rows, "count": len(rows)})

    @mcp.tool(name="ai_statement_check", annotations={"title": "Check the AI bills against the statement", **RO})
    async def ai_statement_check(params: AiCheckIn) -> str:
        """The bills against the statement, provider by provider: the statement's amount, the bills' amount, the difference, the bill and its status, whether it agrees. Reads only; marking the verdict is the action."""
        s = await _statement(fetch, params.ai_statement_id, params.period)
        if s is None:
            return err("No such AI statement month (give ai_statement_id or period like 2026-09).")
        rows = await fetch("SELECT provider, currency, statement_amount, bills_amount, difference, bill_id, bill_number, bill_status, agrees FROM gl_ai_statement_check($1) ORDER BY provider", s["ai_statement_id"])
        return dumps({"period": s["period"], "rows": rows, "agrees": all(r["agrees"] for r in rows) if rows else None})

    @mcp.tool(name="cash_position", annotations={"title": "The cash position", **RO})
    async def cash_position(params: CashPosIn) -> str:
        """Every bank account's ledger balance, cleared balance, last reconciled-through date and balance, unmatched lines — and the total per currency; `trend` adds month-end ledger balances for the last twelve months."""
        d = as_of(params.as_of)
        rows = await fetch("SELECT bank_account_id, name, kind, currency, ledger_balance, cleared_balance, last_reconciled_through, last_reconciled_balance, unmatched_lines FROM gl_cash_position($1) ORDER BY name", d)
        totals: dict[str, dict] = {}
        for r in rows:
            t = totals.setdefault(r["currency"], {"currency": r["currency"], "ledger_balance": Decimal("0"), "cleared_balance": Decimal("0")})
            t["ledger_balance"] += Decimal(str(r["ledger_balance"])); t["cleared_balance"] += Decimal(str(r["cleared_balance"]))
        out = {"as_of": d, "accounts": rows, "totals": list(totals.values())}
        if params.trend:
            months = []
            m = d.replace(day=1)
            for _ in range(12):
                months.append(month_end(m) if month_end(m) <= d else d)
                m = (m - datetime.timedelta(days=1)).replace(day=1)
            months.reverse()
            trend = []
            for me in months:
                bal = await fetch("SELECT bank_account_id, gl_bank_ledger_balance(bank_account_id, $1) AS balance FROM mcp_bank_accounts WHERE archived_at IS NULL", me)
                trend.append({"month_end": me, "balances": {str(b["bank_account_id"]): b["balance"] for b in bal}})
            out["trend"] = trend
        return dumps(out)

    @mcp.tool(name="find_bank_accounts", annotations={"title": "Find bank accounts", **RO})
    async def find_bank_accounts(params: FindBanksIn) -> str:
        """The bank accounts by name or kind with their ledger account, currency, balances and any reconciliation in progress. `q` alone answers a plain list (bank_account_id, name)."""
        w = Where()
        if not params.include_archived:
            w.raw("b.archived_at IS NULL")
        for word in words(params.q):
            w.add("b.name ~* {}", word_start(word))
        if params.kind:
            w.add("b.kind = {}", params.kind)
        rows = await fetch(f"SELECT b.bank_account_id, b.name, b.kind, b.account_id, b.account_code, b.institution, b.account_last4, b.currency, b.opening_balance, b.opening_date, b.last_reconciled_through, b.last_reconciled_balance, "
                           f"b.ledger_balance, b.cleared_balance, b.unmatched_lines, b.reconciliation_in_progress_id, b.archived_at FROM mcp_bank_accounts b WHERE {w.sql()} ORDER BY lower(b.name)", *w.args)
        if q_alone(params):
            return dumps([{"bank_account_id": r["bank_account_id"], "name": r["name"]} for r in rows])
        return dumps({"bank_accounts": rows})

    @mcp.tool(name="unmatched_bank_lines", annotations={"title": "Bank lines to match", **RO})
    async def unmatched_bank_lines(params: UnmatchedIn) -> str:
        """A bank account's (or every account's) imported lines, oldest first — unmatched by default: date, amount, description, reference, the import. `q` alone resolves a line by its reference or description as a plain list (bank_transaction_id, label)."""
        w = Where().add("t.status = {}", params.status if params.status in ("unmatched", "matched", "ignored") else "unmatched")
        if params.q:
            q = params.q.strip()
            w.raw(f"(t.external_ref ILIKE {w.p(q)} OR t.description ~* {w.p(word_start(q))})")
        if params.bank_account_id is not None:
            w.add("t.bank_account_id = {}", params.bank_account_id)
        try:
            f, t2 = to_date(params.from_, "from"), to_date(params.to, "to")
        except ValueError as e:
            return err(str(e))
        if f:
            w.add("t.txn_date >= {}", f)
        if t2:
            w.add("t.txn_date <= {}", t2)
        rows = await fetch(f"SELECT t.bank_transaction_id, t.bank_account_id, t.bank_account_name, t.import_id, t.txn_date, t.amount, t.description, t.counterparty, t.external_ref, t.status, t.matched_type, t.matched_id, t.ignore_reason, "
                           f"t.txn_date || ' — ' || t.amount || ' — ' || COALESCE(t.description, '') AS label FROM mcp_bank_transactions t WHERE {w.sql()} ORDER BY t.txn_date, t.bank_transaction_id LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"bank_transaction_id": r["bank_transaction_id"], "label": r["label"]} for r in rows])
        return dumps({"lines": rows, "count": len(rows)})

    @mcp.tool(name="match_candidates", annotations={"title": "What a bank line could be", **RO})
    async def match_candidates(params: CandidatesIn) -> str:
        """For one unmatched bank line, the ledger items it could be — receipt, deposit, payment, transfer or entry with number, date, amount, description, days apart — by exactly the database's rule (to the cent, within five days, not yet matched)."""
        t = await fetch("SELECT bank_transaction_id, bank_account_name, txn_date, amount, description, status FROM mcp_bank_transactions WHERE bank_transaction_id = $1", params.bank_transaction_id)
        if not t:
            return err("No such bank line.")
        rows = await fetch("SELECT item_type, item_id, number, item_date, amount, description, days_apart FROM gl_bank_candidates($1) ORDER BY days_apart, item_date", params.bank_transaction_id)
        return dumps({"line": t[0], "candidates": rows, "count": len(rows)})

    @mcp.tool(name="in_transit", annotations={"title": "What the ledger holds that the bank has not shown", **RO})
    async def in_transit(params: InTransitIn) -> str:
        """What the ledger holds that no bank line has matched yet: receipts not deposited, deposits, payments and transfers recorded but not matched."""
        d = as_of(params.as_of)
        b = params.bank_account_id
        n = lim(params.limit, 50)
        matched = "NOT EXISTS (SELECT 1 FROM mcp_bank_transactions t WHERE t.status = 'matched' AND t.matched_type = '{typ}' AND t.matched_id = {id})"
        return dumps({
            "as_of": d,
            "receipts_undeposited": await fetch(f"SELECT receipt_id, number, customer_name, received_on, amount, currency, method FROM mcp_receipts r WHERE status = 'recorded' AND deposit_id IS NULL AND received_on <= $1 AND ($2::bigint IS NULL OR deposit_to_bank_account_id = $2) AND {matched.format(typ='receipt', id='r.receipt_id')} ORDER BY received_on LIMIT {n}", d, b),
            "deposits_unmatched": await fetch(f"SELECT deposit_id, number, bank_account_name, deposit_date, total, currency FROM mcp_deposits d WHERE status = 'recorded' AND deposit_date <= $1 AND ($2::bigint IS NULL OR bank_account_id = $2) AND {matched.format(typ='deposit', id='d.deposit_id')} ORDER BY deposit_date LIMIT {n}", d, b),
            "payments_unmatched": await fetch(f"SELECT payment_id, number, vendor_name, bank_account_name, payment_date, amount, currency, method, cheque_number FROM mcp_payments p WHERE status = 'recorded' AND payment_date <= $1 AND ($2::bigint IS NULL OR bank_account_id = $2) AND {matched.format(typ='payment', id='p.payment_id')} ORDER BY payment_date LIMIT {n}", d, b),
            "transfers_unmatched": await fetch(f"SELECT transfer_id, number, from_bank_name, to_bank_name, transfer_date, amount, currency FROM mcp_transfers x WHERE status = 'recorded' AND transfer_date <= $1 AND ($2::bigint IS NULL OR from_bank_account_id = $2 OR to_bank_account_id = $2) AND {matched.format(typ='transfer', id='x.transfer_id')} ORDER BY transfer_date LIMIT {n}", d, b),
        })

    @mcp.tool(name="find_reconciliations", annotations={"title": "Find reconciliations", **RO})
    async def find_reconciliations(params: FindReconsIn) -> str:
        """A bank account's reconciliations: statement date and balance, cleared, difference, status, who completed, reopened and why; the one in progress first. `q` alone resolves one by statement date as a plain list (bank_reconciliation_id, label)."""
        w = Where()
        if params.q:
            w.add("r.statement_date::text LIKE {} || '%'", params.q.strip())
        if params.bank_account_id is not None:
            w.add("r.bank_account_id = {}", params.bank_account_id)
        if params.status:
            w.add("r.status = {}", params.status)
        rows = await fetch(f"SELECT r.bank_reconciliation_id, r.bank_account_id, r.bank_account_name, r.statement_date, r.statement_balance, r.opening_balance, r.cleared_balance, r.difference, r.ledger_balance, r.status, "
                           f"r.completed_at, r.completed_by_name, r.reopened_at, r.reopen_reason, r.line_count, r.bank_account_name || ' — ' || r.statement_date AS label FROM mcp_bank_reconciliations r WHERE {w.sql()} "
                           f"ORDER BY (r.status = 'in_progress') DESC, r.statement_date DESC LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"bank_reconciliation_id": r["bank_reconciliation_id"], "label": r["label"]} for r in rows])
        return dumps({"reconciliations": rows, "count": len(rows)})

    @mcp.tool(name="find_transfers", annotations={"title": "Find transfers between bank accounts", **RO})
    async def find_transfers(params: FindTransfersIn) -> str:
        """Transfers between bank accounts by account, date range and status. `q` alone answers a plain list (transfer_id, label)."""
        w = Where()
        if params.q:
            w.add("x.number ILIKE {}", params.q.strip())
        if params.bank_account_id is not None:
            w.add("(x.from_bank_account_id = {0} OR x.to_bank_account_id = {0})", params.bank_account_id)
        try:
            f, t = to_date(params.from_, "from"), to_date(params.to, "to")
        except ValueError as e:
            return err(str(e))
        if f:
            w.add("x.transfer_date >= {}", f)
        if t:
            w.add("x.transfer_date <= {}", t)
        rows = await fetch(f"SELECT x.transfer_id, x.number, x.from_bank_account_id, x.from_bank_name, x.to_bank_account_id, x.to_bank_name, x.transfer_date, x.amount, x.currency, x.memo, x.status, "
                           f"COALESCE(x.number, 'Draft #' || x.transfer_id) || ' — ' || x.from_bank_name || ' → ' || x.to_bank_name || ' — ' || x.amount || ' ' || x.currency AS label FROM mcp_transfers x WHERE {w.sql()} ORDER BY x.transfer_date DESC, x.transfer_id DESC LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"transfer_id": r["transfer_id"], "label": r["label"]} for r in rows])
        return dumps({"transfers": rows, "count": len(rows)})
