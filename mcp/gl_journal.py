"""General Ledger — the journal (tool surface, "The journal"). Reads mcp_journal_entries, mcp_journal_lines, mcp_attachments, mcp_notes and gl_unposted_summary() / gl_posted_through()."""
from __future__ import annotations

from pydantic import Field

from gl_common import RO, Where, _Base, dumps, err, like_escape, lim, q_alone, to_date, word_start, words

SOURCE_DOCS = {  # source_type -> (view, id column, number column)
    "invoice": ("mcp_invoices", "invoice_id", "number"), "bill": ("mcp_bills", "bill_id", "number"), "receipt": ("mcp_receipts", "receipt_id", "number"),
    "payment": ("mcp_payments", "payment_id", "number"), "deposit": ("mcp_deposits", "deposit_id", "number"), "transfer": ("mcp_transfers", "transfer_id", "number"),
    "credit_note": ("mcp_credit_notes", "credit_note_id", "number"), "vendor_credit": ("mcp_vendor_credits", "vendor_credit_id", "number"),
    "ai_statement": ("mcp_ai_statements", "ai_statement_id", "period"),
}


class FindEntriesIn(_Base):
    q: str | None = Field(None, description="An entry number (JE-000123) or words of the memo. `q` alone answers a plain list (journal_entry_id, label = 'JE-000123 — memo')")
    period_id: int | None = None
    from_: str | None = Field(None, alias="from", description="Entry date from, YYYY-MM-DD")
    to: str | None = None
    status: list[str] | None = Field(None, description="draft, posted, reversed, void")
    source: list[str] | None = Field(None, description="manual, invoice, bill, receipt, payment, reversal, closing, opening, ai_usage, recurring …")
    account_id: int | None = Field(None, description="Entries with a line on this account")
    customer_id: int | None = None
    vendor_id: int | None = None
    department_id: int | None = None
    site_id: int | None = None
    source_type: str | None = None
    source_id: int | None = None
    limit: int = Field(25, ge=1, le=100)


class GetEntryIn(_Base):
    journal_entry_id: int | None = None
    number: str | None = Field(None, description="JE-000123, instead of the id")


class UnpostedIn(_Base):
    detail: bool = Field(False, description="List the draft entries (balanced or not, their period's state) as well as the counts")
    limit: int = Field(25, ge=1, le=100)


def register(mcp, fetch) -> None:
    @mcp.tool(name="find_journal_entries", annotations={"title": "Find journal entries", **RO})
    async def find_journal_entries(params: FindEntriesIn) -> str:
        """Entries by number, words in the memo, period, date range, status, source, account, customer, vendor, department, site or source document; each with totals, period and its reversal link.
        `q` alone answers a plain list (journal_entry_id, label = 'JE-000123 — memo'). A number matches whole; words match at the start of a word."""
        w = Where()
        if params.q:
            q = params.q.strip()
            n = w.p(q)
            parts = [f"e.number ILIKE {n}"]
            for word in words(q):
                parts.append(f"e.memo ~* {w.p(word_start(word))}")
            w.raw("(" + " OR ".join(parts[:1] + ([" AND ".join(parts[1:])] if len(parts) > 1 else [])) + ")")
        if params.period_id is not None:
            w.add("e.period_id = {}", params.period_id)
        try:
            f, t = to_date(params.from_, "from"), to_date(params.to, "to")
        except ValueError as ex:
            return err(str(ex))
        if f:
            w.add("e.entry_date >= {}", f)
        if t:
            w.add("e.entry_date <= {}", t)
        if params.status:
            w.add("e.status = ANY({})", params.status)
        if params.source:
            w.add("e.source = ANY({})", params.source)
        if params.account_id is not None:
            w.add("EXISTS (SELECT 1 FROM mcp_journal_lines l WHERE l.journal_entry_id = e.journal_entry_id AND l.account_id = {})", params.account_id)
        for col, v in (("customer_id", params.customer_id), ("vendor_id", params.vendor_id)):
            if v is not None:
                w.add(f"EXISTS (SELECT 1 FROM mcp_journal_lines l WHERE l.journal_entry_id = e.journal_entry_id AND l.{col} = {{}})", v)
        for col, v in (("department_id", params.department_id), ("site_id", params.site_id), ("source_type", params.source_type), ("source_id", params.source_id)):
            if v is not None:
                w.add(f"e.{col} = {{}}", v)
        rows = await fetch(f"SELECT e.journal_entry_id, e.number, e.entry_date, e.period_id, e.period_name, e.period_status, e.memo, e.source, e.source_type, e.source_id, e.status, e.reversal_of_number, e.reversed_by_number, "
                           f"e.currency, e.total_debit, e.total_credit, e.department_name, e.site_name, e.created_by_name, e.created_by_kind, e.posted_by_name, e.posted_at, e.line_count, "
                           f"COALESCE(e.number, 'Draft #' || e.journal_entry_id) || COALESCE(' — ' || e.memo, '') AS label FROM mcp_journal_entries e WHERE {w.sql()} ORDER BY e.entry_date DESC, e.journal_entry_id DESC LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"journal_entry_id": r["journal_entry_id"], "label": r["label"]} for r in rows])
        return dumps({"entries": rows, "count": len(rows)})

    @mcp.tool(name="get_journal_entry", annotations={"title": "One journal entry in full", **RO})
    async def get_journal_entry(params: GetEntryIn) -> str:
        """One entry in full: head, lines with account codes and names and parties, the source document it came from (type, id, number), what it reverses or what reversed it, attachments and notes."""
        if params.journal_entry_id is not None:
            rows = await fetch("SELECT * FROM mcp_journal_entries WHERE journal_entry_id = $1", params.journal_entry_id)
        elif params.number:
            rows = await fetch("SELECT * FROM mcp_journal_entries WHERE number ILIKE $1", params.number.strip())
        else:
            return err("Give journal_entry_id or number.")
        if not rows:
            return err("No such entry (or you may not see it).")
        e = rows[0]
        eid = e["journal_entry_id"]
        lines = await fetch("SELECT line_no, account_id, account_code, account_name, description, debit, credit, department_name, site_name, customer_id, customer_name, vendor_id, vendor_name, tax_rate_id "
                            "FROM mcp_journal_lines WHERE journal_entry_id = $1 ORDER BY line_no", eid)
        source_doc = None
        if e["source_type"] in SOURCE_DOCS and e["source_id"] is not None:
            view, idc, numc = SOURCE_DOCS[e["source_type"]]
            d = await fetch(f"SELECT {idc} AS id, {numc} AS number FROM {view} WHERE {idc} = $1", e["source_id"])
            source_doc = {"type": e["source_type"], "id": e["source_id"], "number": d[0]["number"] if d else None}
        else:  # a document that posts an entry points the other way
            for typ, (view, idc, numc) in SOURCE_DOCS.items():
                if typ in ("ai_statement",):
                    continue
                d = await fetch(f"SELECT {idc} AS id, {numc} AS number FROM {view} WHERE journal_entry_id = $1 LIMIT 1", eid) if typ not in ("ai_statement",) else []
                if d:
                    source_doc = {"type": typ, "id": d[0]["id"], "number": d[0]["number"]}
                    break
        atts = await fetch("SELECT attachment_id, filename, mime_type, byte_size, uploaded_by_name, created_at FROM mcp_attachments WHERE record_type = 'journal_entry' AND record_id = $1 ORDER BY created_at", eid)
        notes = await fetch("SELECT note_id, member_name, member_kind, body, created_at FROM mcp_notes WHERE record_type = 'journal_entry' AND record_id = $1 ORDER BY created_at", eid)
        return dumps({"entry": e, "lines": lines, "source_document": source_doc, "attachments": atts, "notes": notes})

    @mcp.tool(name="unposted_entries", annotations={"title": "What waits to be posted", **RO})
    async def unposted_entries(params: UnpostedIn) -> str:
        """What waits: draft entries (with whether each balances and its period's state), draft invoices and bills, bills and payments awaiting approval, unmatched bank lines, open claims,
        undeposited receipts, and the date the books are posted through. The Bookkeeper's morning starts here. `detail` also lists the draft entries."""
        s = await fetch("SELECT * FROM gl_unposted_summary()")
        pt = await fetch("SELECT gl_posted_through() AS d")
        out = {"posted_through": pt[0]["d"] if pt else None, "waiting": s[0] if s else {}}
        if params.detail:
            out["draft_entries"] = await fetch(
                "SELECT e.journal_entry_id, e.entry_date, e.memo, e.source, e.period_name, e.period_status, e.created_by_name, COALESCE(sum(l.debit), 0) AS total_debit, COALESCE(sum(l.credit), 0) AS total_credit, "
                "(COALESCE(sum(l.debit), 0) = COALESCE(sum(l.credit), 0) AND count(l.journal_line_id) > 0) AS balanced, count(l.journal_line_id) AS line_count "
                "FROM mcp_journal_entries e LEFT JOIN mcp_journal_lines l ON l.journal_entry_id = e.journal_entry_id WHERE e.status = 'draft' "
                f"GROUP BY e.journal_entry_id, e.entry_date, e.memo, e.source, e.period_name, e.period_status, e.created_by_name ORDER BY e.entry_date, e.journal_entry_id LIMIT {lim(params.limit)}")
        return dumps(out)
