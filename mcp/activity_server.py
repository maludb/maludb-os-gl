"""gl_activity_mcp — General Ledger's activity-memory server (activity_log through mcp_activity_log, read-only).

Tool surface: docs/gl-mcp-tool-surface.md, "Activity server". Every query runs as the asking member; the view decides which rows they see: their own always, every row with reports.view
(the books are business-wide). The activity role reads mcp_activity_log plus mcp_members, mcp_departments, mcp_journal_entries, mcp_invoices and mcp_bills for names and document facts.
A secure token, a token value, a party's contact details, a note's full text and a document's attachment are never in a payload (the handlers' rule), so nothing here can return one.
"""
from __future__ import annotations

import datetime

from mcp.server.fastmcp import FastMCP
from pydantic import Field

import db
from gl_common import RO, Where, _Base, dumps, err, lim, month_end, to_date
from gl_words import WORDS

mcp = FastMCP("gl_activity_mcp")


async def _pool():
    return await db.get_pool(db.ENV["MCP_ACTIVITY_DB_USER"], db.ENV["MCP_ACTIVITY_DB_PASSWORD"])


async def fetch(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


COLS = "l.activity_id, l.occurred_at, l.actor_member_id, l.actor_name, l.actor_kind, l.source, l.action, l.entity_type, l.entity_id, l.journal_entry_id, l.period_id, l.customer_id, l.vendor_id, l.bank_account_id, l.before, l.after, l.agent_run_id"
CONFIG_PREFIXES = ("account.", "posting_rule.", "tax_rate.", "item.", "bank_account.", "numbering.", "recurring.save", "recurring.toggle", "recurring.delete", "settings.", "site.", "fiscal_year.create")
TYPES = {"journal_entry": ("mcp_journal_entries", "journal_entry_id", "number"), "invoice": ("mcp_invoices", "invoice_id", "number"), "bill": ("mcp_bills", "bill_id", "number")}


class SearchIn(_Base):
    sql: str = Field(..., description="A single read-only SELECT over mcp_activity_log and the fact views (mcp_members, mcp_departments, mcp_journal_entries, mcp_invoices, mcp_bills).")


class HistoryIn(_Base):
    record_type: str = Field(..., description="journal_entry, invoice, bill, receipt, payment, payment_run, deposit, credit_note, vendor_credit, customer, vendor, bank_account, reconciliation, transfer, ai_statement, period, recurring_template …")
    record_id: int | None = None
    number: str | None = Field(None, description="JE-000123, INV-00042 or BILL-000007 instead of the id (journal entries, invoices, bills)")
    order: str = Field("oldest", description="oldest or newest first")
    limit: int = Field(100, ge=1, le=200)


class TouchedIn(_Base):
    record_type: str
    record_id: int
    limit: int = Field(50, ge=1, le=200)


class TimelineIn(_Base):
    member_id: int | None = Field(None, description="A person or agent; omit for yourself (others need reports.view)")
    actor_kind: str | None = Field(None, description="human or agent — everyone of that kind (what every agent did yesterday)")
    from_: str | None = Field(None, alias="from", description="YYYY-MM-DD (the last 7 days by default)")
    to: str | None = None
    action: str | None = Field(None, description="An action or a prefix, e.g. journal_entry.post, bill., screen.view")
    limit: int = Field(100, ge=1, le=200)


class PeriodIn(_Base):
    period_id: int | None = None
    name: str | None = Field(None, description="2026-09")
    limit: int = Field(100, ge=1, le=200)


class ConfigIn(_Base):
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    entity_type: str | None = Field(None, description="account, posting_rule, tax_rate, item, bank_account, settings, site, recurring_template …")
    limit: int = Field(100, ge=1, le=200)


class SinceIn(_Base):
    since: str = Field(..., description="A time: 2026-10-05 or 2026-10-05T07:00:00Z")
    kinds: list[str] | None = Field(None, description="Action prefixes to count, e.g. ['journal_entry.', 'bill.']")
    limit: int = Field(50, ge=1, le=200)


def sentence(r: dict) -> str:
    """One line in words: 'SMOKE Carl posted an entry (JE-000012, 75.00 USD)'."""
    who = r.get("actor_name") or ("A customer" if r.get("source") == "portal" else "The ledger")
    words = WORDS.get(r["action"], r["action"])
    a = r.get("after") or {}
    bits = []
    for k in ("number", "reversal_number", "period", "name"):
        if isinstance(a, dict) and a.get(k):
            bits.append(str(a[k])); break
    if isinstance(a, dict) and a.get("amount") not in (None, ""):
        bits.append(f"{a['amount']} {a.get('currency', '')}".strip())
    if isinstance(a, dict) and a.get("reason"):
        bits.append(f"“{a['reason']}”")
    return f"{who} {words}" + (f" ({', '.join(bits)})" if bits else "")


def words_for(rows: list[dict]) -> list[dict]:
    for r in rows:
        r["sentence"] = sentence(r)
    return rows


def ts(s: str | None, end: bool = False) -> datetime.datetime | None:
    if not s:
        return None
    try:
        if len(s) == 10:
            d = datetime.datetime.fromisoformat(s).replace(tzinfo=datetime.timezone.utc)
            return d + datetime.timedelta(days=1) if end else d
        d = datetime.datetime.fromisoformat(s.replace("Z", "+00:00"))
        return d if d.tzinfo else d.replace(tzinfo=datetime.timezone.utc)
    except ValueError:
        raise ValueError(f"'{s}' is not a date or a time (YYYY-MM-DD or YYYY-MM-DDTHH:MM:SSZ).")


async def resolve_record(rtype: str, rid: int | None, number: str | None) -> tuple[int | None, str | None]:
    if rid is not None:
        return rid, None
    if number and rtype in TYPES:
        view, idc, numc = TYPES[rtype]
        rows = await fetch(f"SELECT {idc} AS id FROM {view} WHERE {numc} ILIKE $1", number.strip())
        return (rows[0]["id"], None) if rows else (None, f"No {rtype.replace('_', ' ')} numbered {number}.")
    return None, "Give record_id" + (" or number." if rtype in TYPES else ".")


def record_where(rtype: str, rid: int, w: Where) -> Where:
    parts = [f"(l.entity_type = {w.p(rtype)} AND l.entity_id = {w.p(rid)})"]
    key = {"journal_entry": "journal_entry_id", "period": "period_id", "customer": "customer_id", "vendor": "vendor_id", "bank_account": "bank_account_id"}.get(rtype)
    if key:
        parts.append(f"l.{key} = {w.p(rid)}")
    w.raw("(" + " OR ".join(parts) + ")")
    return w


@mcp.tool(name="record_history", annotations={"title": "A record's history", **RO})
async def record_history(params: HistoryIn) -> str:
    """A record's timeline in words and facts: drafted, lines changed, posted (with the amount), reversed (by what, why), issued, sent, viewed, reminded, received against, written off, approved, recorded, matched,
    reconciled, attached, noted. For a journal entry, a document that posted it adds its events; for a customer, vendor, bank account or period, everything that carries its key. Oldest first by default."""
    rid, why = await resolve_record(params.record_type, params.record_id, params.number)
    if rid is None:
        return err(why or "No such record.")
    w = record_where(params.record_type, rid, Where())
    order = "DESC" if params.order == "newest" else "ASC"
    rows = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at {order}, l.activity_id {order} LIMIT {lim(params.limit, 100, 200)}", *w.args)
    return dumps({"record_type": params.record_type, "record_id": rid, "events": words_for(rows), "count": len(rows)})


@mcp.tool(name="actor_timeline", annotations={"title": "What a person or an agent did", **RO})
async def actor_timeline(params: TimelineIn) -> str:
    """What a person or an agent did over a period (the last 7 days by default) — and, with actor_kind = agent, what every agent drafted, imported, matched or asked to post; money events carry their amounts. Yourself always; anyone else needs reports.view."""
    me = db.request_member_id.get()
    try:
        f, t = ts(params.from_), ts(params.to, True)
    except ValueError as e:
        return err(str(e))
    f = f or (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=7))
    w = Where().add("l.occurred_at >= {}", f)
    if t:
        w.add("l.occurred_at < {}", t)
    if params.actor_kind in ("human", "agent"):
        w.add("l.actor_kind = {}", params.actor_kind)
    else:
        w.add("l.actor_member_id = {}", params.member_id if params.member_id is not None else me)
    if params.action:
        w.add("l.action LIKE {} || '%'", params.action.strip())
    rows = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC LIMIT {lim(params.limit, 100, 200)}", *w.args)
    return dumps({"from": f, "to": t, "events": words_for(rows), "count": len(rows),
                  "note": None if rows or params.member_id in (None, me) else "No rows: another person's activity needs the reports.view right."})


@mcp.tool(name="who_touched", annotations={"title": "Who touched a record", **RO})
async def who_touched(params: TouchedIn) -> str:
    """Everyone who touched a record, with the first and last time, how many times, and the last thing each did — the last one first."""
    w = record_where(params.record_type, params.record_id, Where())
    rows = await fetch(f"SELECT l.actor_member_id, l.actor_name, l.actor_kind, min(l.occurred_at) AS first_at, max(l.occurred_at) AS last_at, count(*) AS events, "
                       f"(array_agg(l.action ORDER BY l.occurred_at DESC))[1] AS last_action FROM mcp_activity_log l WHERE {w.sql()} AND l.action <> 'screen.view' GROUP BY 1, 2, 3 ORDER BY last_at DESC LIMIT {lim(params.limit, 50, 200)}", *w.args)
    for r in rows:
        r["last_action_words"] = WORDS.get(r["last_action"], r["last_action"])
    return dumps({"record_type": params.record_type, "record_id": params.record_id, "people": rows})


@mcp.tool(name="period_activity", annotations={"title": "Everything that happened to a period", **RO})
async def period_activity(params: PeriodIn) -> str:
    """Everything that happened to a fiscal period: its closes, its reopenings (who, why), every posting into it after a reopening, and reversals dated in it — the audit page's rows."""
    pid = params.period_id
    if pid is None and params.name:
        # the activity role cannot read mcp_fiscal_periods: the period's id is read from the rows that name it
        rows = await fetch("SELECT DISTINCT l.period_id FROM mcp_activity_log l WHERE l.period_id IS NOT NULL AND (l.after->>'name' = $1 OR l.after->>'period' = $1)", params.name.strip())
        pid = rows[0]["period_id"] if rows else None
    if pid is None:
        return err("Give period_id, or a name (2026-09) the log has an event for.")
    ev = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE l.period_id = $1 AND (l.action IN ('period.close', 'period.reopen', 'fiscal_year.close', 'fiscal_year.reopen') OR (l.action = 'journal_entry.post' AND (l.after->>'reopened_period')::boolean) OR l.action = 'journal_entry.reverse') "
                     f"ORDER BY l.occurred_at LIMIT {lim(params.limit, 100, 200)}", pid)
    groups = {"closes": [r for r in ev if r["action"] in ("period.close", "fiscal_year.close")], "reopens": [r for r in ev if r["action"] in ("period.reopen", "fiscal_year.reopen")],
              "posted_after_reopen": [r for r in ev if r["action"] == "journal_entry.post"], "reversals": [r for r in ev if r["action"] == "journal_entry.reverse"]}
    return dumps({"period_id": pid, **{k: words_for(v) for k, v in groups.items()}})


@mcp.tool(name="config_change_log", annotations={"title": "Configuration changes", **RO})
async def config_change_log(params: ConfigIn) -> str:
    """What configuration changed in a period (the last 30 days by default) and by whom: accounts, posting rules, tax rates, items, bank accounts, numbering, recurring templates, sites, settings — with the before and after of the changed fields."""
    try:
        f, t = ts(params.from_), ts(params.to, True)
    except ValueError as e:
        return err(str(e))
    f = f or (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=30))
    w = Where().add("l.occurred_at >= {}", f)
    if t:
        w.add("l.occurred_at < {}", t)
    w.raw("(" + " OR ".join(f"l.action LIKE {w.p(p + '%')}" for p in CONFIG_PREFIXES) + ")")
    if params.entity_type:
        w.add("l.entity_type = {}", params.entity_type)
    rows = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC LIMIT {lim(params.limit, 100, 200)}", *w.args)
    return dumps({"from": f, "to": t, "changes": words_for(rows), "count": len(rows)})


@mcp.tool(name="changes_since", annotations={"title": "What has changed since …", **RO})
async def changes_since(params: SinceIn) -> str:
    """What has changed in the books since a time: the actions by kind with counts, and the records touched (newest first) — the answer to 'what happened since I last looked'. Screen views are not counted. Yours; everyone's with reports.view."""
    try:
        s = ts(params.since)
    except ValueError as e:
        return err(str(e))
    w = Where().add("l.occurred_at >= {}", s).raw("l.action <> 'screen.view'")
    if params.kinds:
        w.raw("(" + " OR ".join(f"l.action LIKE {w.p(k.strip() + '%')}" for k in params.kinds[:10]) + ")")
    counts = await fetch(f"SELECT l.action, count(*) AS events FROM mcp_activity_log l WHERE {w.sql()} GROUP BY 1 ORDER BY 2 DESC LIMIT 100", *w.args)
    for c in counts:
        c["words"] = WORDS.get(c["action"], c["action"])
    touched = await fetch(f"SELECT l.entity_type, l.entity_id, max(l.occurred_at) AS last_at, count(*) AS events FROM mcp_activity_log l WHERE {w.sql()} AND l.entity_id IS NOT NULL GROUP BY 1, 2 ORDER BY last_at DESC LIMIT {lim(params.limit, 50, 200)}", *w.args)
    return dumps({"since": s, "by_action": counts, "records_touched": touched})


@mcp.tool(name="activity_search", annotations={"title": "Activity search (SQL)", **RO})
async def activity_search(params: SearchIn) -> str:
    """The long tail: one read-only SELECT over mcp_activity_log (activity_id, occurred_at, actor_member_id, actor_name, actor_kind, source, action, entity_type, entity_id, journal_entry_id, period_id, customer_id, vendor_id,
    bank_account_id, department_id, site_id, before, after, agent_run_id, request_id) and the fact views mcp_members, mcp_departments, mcp_journal_entries, mcp_invoices, mcp_bills. Already scoped to what you may see. One statement, 5 s, 200 rows."""
    return await db.run_search(await _pool(), params.sql)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_ACTIVITY_DB_USER", "MCP_ACTIVITY_DB_PASSWORD", port=int(db.ENV["MCP_ACTIVITY_PORT"]), endpoint_name="Activity MCP")
