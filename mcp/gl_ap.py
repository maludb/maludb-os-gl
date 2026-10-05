"""General Ledger — payables (tool surface, "Payables"). Reads the bill, payment, run and vendor-credit views (documents.view), vendors (parties.view; contact details and notes only with ap.write —
the view decides), gl_ap_aging() (reports.view)."""
from __future__ import annotations

import datetime
from decimal import Decimal

from pydantic import Field

from gl_common import RO, Where, _Base, as_of, doc_label, dumps, err, has_right, lim, q_alone, to_date, word_start, words

OPEN_BILL = ("approved", "partially_paid")


class FindVendorsIn(_Base):
    q: str | None = Field(None, description="Name or words of it (word start). `q` alone answers a plain list (vendor_id, name)")
    kind: str | None = Field(None, description="vendor, ai_provider, employee or tax_authority")
    with_balance: bool | None = None
    include_archived: bool = False
    limit: int = Field(25, ge=1, le=100)


class VendorIn(_Base):
    vendor_id: int


class FindBillsIn(_Base):
    q: str | None = Field(None, description="A number (BILL-000007), the vendor's reference, or words of the vendor. `q` alone answers a plain list (bill_id, label = 'BILL-000007 — Hosting Co — 259.80 USD')")
    vendor_id: int | None = None
    status: list[str] | None = Field(None, description="draft (awaiting approval), approved, partially_paid, paid, void")
    due_within_days: int | None = Field(None, ge=0, le=365, description="Approved or part-paid bills due within this many days (overdue ones included)")
    overdue: bool | None = None
    source: list[str] | None = Field(None, description="manual, ai_usage, inbox, recurring, import")
    no_document: bool | None = Field(None, description="Bills with no document attached")
    from_: str | None = Field(None, alias="from", description="Bill date from")
    to: str | None = None
    limit: int = Field(25, ge=1, le=100)


class GetBillIn(_Base):
    bill_id: int | None = None
    number: str | None = None


class AgingIn(_Base):
    as_of: str | None = None
    vendor_id: int | None = None
    currency: str | None = None
    limit: int = Field(50, ge=1, le=100)


class ApDueIn(_Base):
    due_within_days: int = Field(7, ge=0, le=365)
    vendor_id: int | None = None
    limit: int = Field(25, ge=1, le=100)


class SpendIn(_Base):
    from_: str = Field(..., alias="from")
    to: str
    group_by: str = Field("account", description="account, vendor, department or site")
    limit: int = Field(25, ge=1, le=100)


class FindPaymentsIn(_Base):
    q: str | None = Field(None, description="A number or cheque number. `q` alone answers a plain list (payment_id, label)")
    vendor_id: int | None = None
    status: list[str] | None = None
    bank_account_id: int | None = None
    method: str | None = None
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    payment_run_id: int | None = None
    limit: int = Field(25, ge=1, le=100)


class FindRunsIn(_Base):
    q: str | None = Field(None, description="A number. `q` alone answers a plain list (payment_run_id, label)")
    status: str | None = None
    bank_account_id: int | None = None
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    limit: int = Field(25, ge=1, le=100)


class FindVendorCreditsIn(_Base):
    q: str | None = Field(None, description="A number. `q` alone answers a plain list (vendor_credit_id, label)")
    vendor_id: int | None = None
    status: str | None = None
    bill_id: int | None = None
    limit: int = Field(25, ge=1, le=100)


def _dates(f, t):
    return to_date(f, "from"), to_date(t, "to")


def register(mcp, fetch) -> None:
    @mcp.tool(name="find_vendors", annotations={"title": "Find vendors", **RO})
    async def find_vendors(params: FindVendorsIn) -> str:
        """Vendors by name or words, by kind (vendor, AI provider, employee, tax authority), with balance due, open bills and last billed; contact details only with ap.write. Call before drafting a bill.
        `q` alone answers a plain list (vendor_id, name)."""
        w = Where()
        if not params.include_archived:
            w.raw("v.archived_at IS NULL")
        for word in words(params.q):
            n = w.p(word_start(word))
            w.raw(f"(v.name ~* {n} OR v.legal_name ~* {n})")
        if params.kind:
            w.add("v.kind = {}", params.kind)
        if params.with_balance:
            w.raw("v.balance_due > 0")
        rows = await fetch(f"SELECT v.vendor_id, v.name, v.legal_name, v.kind, v.provider_key, v.email, v.phone, v.terms_days, v.currency, v.pay_method, v.balance_due, v.open_bills, v.last_billed_on, v.archived_at FROM mcp_vendors v WHERE {w.sql()} ORDER BY lower(v.name) LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"vendor_id": r["vendor_id"], "name": r["name"]} for r in rows])
        return dumps({"vendors": rows, "count": len(rows)})

    @mcp.tool(name="get_vendor", annotations={"title": "One vendor in full", **RO})
    async def get_vendor(params: VendorIn) -> str:
        """One vendor in full: terms, accounts, contacts and notes (ap.write), open bills, recent payments and credits, attachments."""
        r = await fetch("SELECT * FROM mcp_vendors WHERE vendor_id = $1", params.vendor_id)
        if not r:
            return err("No such vendor.")
        vid = params.vendor_id
        ap = await has_right(fetch, "ap.write")
        return dumps({
            "vendor": r[0],
            "contacts": await fetch("SELECT party_contact_id, name, email, phone, role, is_primary FROM mcp_party_contacts WHERE party_kind = 'vendor' AND party_id = $1 ORDER BY is_primary DESC, name", vid) if ap else None,
            "open_bills": await fetch("SELECT bill_id, number, vendor_ref, bill_date, due_date, currency, status, total, balance_due, days_overdue FROM mcp_bills WHERE vendor_id = $1 AND status = ANY($2) ORDER BY due_date", vid, list(OPEN_BILL)),
            "recent_payments": await fetch("SELECT payment_id, number, payment_date, amount, currency, method, status FROM mcp_payments WHERE vendor_id = $1 ORDER BY payment_date DESC LIMIT 10", vid),
            "recent_credits": await fetch("SELECT vendor_credit_id, number, credit_date, currency, total, balance, status FROM mcp_vendor_credits WHERE vendor_id = $1 ORDER BY credit_date DESC LIMIT 10", vid),
            "notes": await fetch("SELECT note_id, member_name, body, created_at FROM mcp_notes WHERE record_type = 'vendor' AND record_id = $1 ORDER BY created_at DESC LIMIT 20", vid) if ap else None,
            "attachments": await fetch("SELECT attachment_id, filename, mime_type, byte_size, created_at FROM mcp_attachments WHERE record_type = 'vendor' AND record_id = $1 ORDER BY created_at", vid),
        })

    @mcp.tool(name="find_bills", annotations={"title": "Find bills", **RO})
    async def find_bills(params: FindBillsIn) -> str:
        """Bills by number, vendor, the vendor's reference, status, due within N days, overdue, source (manual, AI usage, recurring), without a document, or date range; each with totals, paid, credited, balance and days overdue.
        `q` alone answers a plain list (bill_id, label = 'BILL-000007 — Hosting Co — 259.80 USD')."""
        w = Where()
        if params.q:
            q = params.q.strip()
            parts = [f"b.number ILIKE {w.p(q)}", f"b.vendor_ref ILIKE {w.p(q)}"]
            ws = [f"b.vendor_name ~* {w.p(word_start(x))}" for x in words(q)]
            w.raw("(" + " OR ".join(parts) + (" OR (" + " AND ".join(ws) + ")" if ws else "") + ")")
        if params.vendor_id is not None:
            w.add("b.vendor_id = {}", params.vendor_id)
        if params.status:
            w.add("b.status = ANY({})", params.status)
        if params.due_within_days is not None:
            w.add("b.status = ANY({}) AND b.balance_due > 0 AND b.due_date <= current_date + {}::int", list(OPEN_BILL), params.due_within_days)
        if params.overdue is not None:
            w.raw("b.overdue" if params.overdue else "NOT b.overdue")
        if params.source:
            w.add("b.source = ANY({})", params.source)
        if params.no_document:
            w.raw("NOT b.has_attachment AND b.status <> 'void'")
        try:
            f, t = _dates(params.from_, params.to)
        except ValueError as e:
            return err(str(e))
        if f:
            w.add("b.bill_date >= {}", f)
        if t:
            w.add("b.bill_date <= {}", t)
        rows = await fetch(f"SELECT b.bill_id, b.number, b.vendor_id, b.vendor_name, b.vendor_ref, b.bill_date, b.due_date, b.currency, b.status, b.subtotal, b.tax_total, b.total, b.amount_paid, b.amount_credited, b.balance_due, b.overdue, "
                           f"b.days_overdue, b.source, b.has_attachment, b.approved_by_name, b.approved_at, {doc_label('b.number', 'b.vendor_name', id_col='b.bill_id')} AS label "
                           f"FROM mcp_bills b WHERE {w.sql()} ORDER BY b.due_date, b.bill_id DESC LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"bill_id": r["bill_id"], "label": r["label"]} for r in rows])
        return dumps({"bills": rows, "count": len(rows)})

    @mcp.tool(name="get_bill", annotations={"title": "One bill in full", **RO})
    async def get_bill(params: GetBillIn) -> str:
        """One bill in full: lines with accounts and dimensions, the document attached, the payments and credits applied, the entry it posted, who approved it, notes."""
        if params.bill_id is not None:
            r = await fetch("SELECT * FROM mcp_bills WHERE bill_id = $1", params.bill_id)
        elif params.number:
            r = await fetch("SELECT * FROM mcp_bills WHERE number ILIKE $1", params.number.strip())
        else:
            return err("Give bill_id or number.")
        if not r:
            return err("No such bill (or you may not see it).")
        b = r[0]
        bid = b["bill_id"]
        if b["journal_entry_id"]:
            e = await fetch("SELECT number FROM mcp_journal_entries WHERE journal_entry_id = $1", b["journal_entry_id"])
            b["journal_entry_number"] = e[0]["number"] if e else None
        return dumps({
            "bill": b,
            "lines": await fetch("SELECT sort_order, description, quantity, unit_price, tax_rate, line_subtotal, line_tax, line_total, expense_account_code, expense_account_name, department_name, site_id FROM mcp_bill_lines WHERE bill_id = $1 ORDER BY sort_order", bid),
            "payments_applied": await fetch("SELECT payment_id, payment_number, amount, applied_at FROM mcp_payment_applications WHERE bill_id = $1 ORDER BY applied_at", bid),
            "credits_applied": await fetch("SELECT vendor_credit_id, vendor_credit_number, amount, applied_at FROM mcp_vendor_credit_applications WHERE bill_id = $1 ORDER BY applied_at", bid),
            "attachments": await fetch("SELECT attachment_id, filename, mime_type, byte_size, uploaded_by_name, created_at FROM mcp_attachments WHERE record_type = 'bill' AND record_id = $1 ORDER BY created_at", bid),
            "notes": await fetch("SELECT note_id, member_name, member_kind, body, created_at FROM mcp_notes WHERE record_type = 'bill' AND record_id = $1 ORDER BY created_at", bid),
        })

    @mcp.tool(name="ap_aging", annotations={"title": "Whom we owe", **RO})
    async def ap_aging(params: AgingIn) -> str:
        """Whom we owe as of a date, per vendor and currency, in the five buckets with open bill counts and totals per currency."""
        d = as_of(params.as_of)
        w = Where()
        if params.vendor_id is not None:
            w.add("vendor_id = {}", params.vendor_id)
        if params.currency:
            w.add("currency = {}", params.currency.upper())
        rows = await fetch(f"SELECT vendor_id, vendor_name, currency, current_due, days_1_30, days_31_60, days_61_90, days_over_90, total, open_bills FROM gl_ap_aging({w.p(d)}) WHERE {w.sql()} ORDER BY vendor_name, currency LIMIT {lim(params.limit, 50)}", *w.args)
        totals: dict[str, dict] = {}
        for x in rows:
            t = totals.setdefault(x["currency"], {"currency": x["currency"], "current_due": Decimal(0), "days_1_30": Decimal(0), "days_31_60": Decimal(0), "days_61_90": Decimal(0), "days_over_90": Decimal(0), "total": Decimal(0), "open_bills": 0})
            for k in ("current_due", "days_1_30", "days_31_60", "days_61_90", "days_over_90", "total"):
                t[k] += Decimal(str(x[k]))
            t["open_bills"] += x["open_bills"]
        return dumps({"as_of": d, "vendors": rows, "totals": list(totals.values())})

    @mcp.tool(name="ap_due", annotations={"title": "What is due (the controller's desk)", **RO})
    async def ap_due(params: ApDueIn) -> str:
        """What is due: approved and part-paid bills by due date within N days (a week by default, overdue ones first), bills awaiting approval (drafts), payments awaiting approval (drafts), payment runs not yet recorded."""
        v = params.vendor_id
        w = Where().add("b.status = ANY({})", list(OPEN_BILL)).raw("b.balance_due > 0").add("b.due_date <= current_date + {}::int", params.due_within_days)
        if v is not None:
            w.add("b.vendor_id = {}", v)
        due = await fetch(f"SELECT b.bill_id, b.number, b.vendor_id, b.vendor_name, b.vendor_ref, b.due_date, b.currency, b.balance_due, b.days_overdue FROM mcp_bills b WHERE {w.sql()} ORDER BY b.due_date LIMIT {lim(params.limit)}", *w.args)
        aw = await fetch("SELECT bill_id, number, vendor_name, bill_date, currency, total, has_attachment FROM mcp_bills WHERE status = 'draft' AND ($1::bigint IS NULL OR vendor_id = $1) ORDER BY bill_date LIMIT 50", v)
        pay = await fetch("SELECT payment_id, number, vendor_name, payment_date, currency, amount, method, status FROM mcp_payments WHERE status = 'draft' AND ($1::bigint IS NULL OR vendor_id = $1) ORDER BY payment_date LIMIT 50", v)
        runs = await fetch("SELECT payment_run_id, number, pay_date, bank_account_name, currency, status, total, payment_count FROM mcp_payment_runs WHERE status IN ('draft', 'approved') ORDER BY pay_date LIMIT 50")
        return dumps({"due_within_days": params.due_within_days, "due": due, "bills_awaiting_approval": aw, "payments_awaiting_approval": pay, "payment_runs_open": runs})

    @mcp.tool(name="spend", annotations={"title": "What we spent", **RO})
    async def spend(params: SpendIn) -> str:
        """What we spent in a period by account, vendor, department or site — from approved bills' lines."""
        try:
            f, t = _dates(params.from_, params.to)
        except ValueError as e:
            return err(str(e))
        g = params.group_by if params.group_by in ("account", "vendor", "department", "site") else "account"
        key, label, join = {
            "account": ("a.account_id", "a.code || ' ' || a.name", "JOIN mcp_accounts a ON a.account_id = bl.expense_account_id"),
            "vendor": ("v.vendor_id", "v.name", "JOIN mcp_vendors v ON v.vendor_id = b.vendor_id"),
            "department": ("d.department_id", "COALESCE(d.name, 'No department')", "LEFT JOIN mcp_departments d ON d.department_id = COALESCE(bl.department_id, b.department_id)"),
            "site": ("s.site_id", "COALESCE(s.name, 'No site')", "LEFT JOIN mcp_sites s ON s.site_id = COALESCE(bl.site_id, b.site_id)")}[g]
        rows = await fetch(f"SELECT {key} AS key, {label} AS label, b.currency, sum(bl.line_subtotal) AS amount, count(DISTINCT b.bill_id) AS documents FROM mcp_bill_lines bl JOIN mcp_bills b ON b.bill_id = bl.bill_id {join} "
                           f"WHERE b.status IN ('approved', 'partially_paid', 'paid') AND b.bill_date BETWEEN $1 AND $2 GROUP BY 1, 2, 3 ORDER BY 4 DESC LIMIT {lim(params.limit)}", f, t)
        return dumps({"group_by": g, "from": f, "to": t, "rows": rows})

    @mcp.tool(name="find_payments", annotations={"title": "Find payments (money out)", **RO})
    async def find_payments(params: FindPaymentsIn) -> str:
        """Money out by vendor, status, bank account, method, date range or run; each with its applications, its entry and the bank line matched. `q` alone answers a plain list (payment_id, label)."""
        w = Where()
        if params.q:
            q = params.q.strip()
            w.raw(f"(p.number ILIKE {w.p(q)} OR p.cheque_number ILIKE {w.p(q)} OR p.vendor_name ~* {w.p(word_start(q))})")
        if params.vendor_id is not None:
            w.add("p.vendor_id = {}", params.vendor_id)
        if params.status:
            w.add("p.status = ANY({})", params.status)
        if params.bank_account_id is not None:
            w.add("p.bank_account_id = {}", params.bank_account_id)
        if params.method:
            w.add("p.method = {}", params.method)
        if params.payment_run_id is not None:
            w.add("p.payment_run_id = {}", params.payment_run_id)
        try:
            f, t = _dates(params.from_, params.to)
        except ValueError as e:
            return err(str(e))
        if f:
            w.add("p.payment_date >= {}", f)
        if t:
            w.add("p.payment_date <= {}", t)
        rows = await fetch(f"SELECT p.payment_id, p.number, p.vendor_id, p.vendor_name, p.payment_date, p.amount, p.currency, p.method, p.cheque_number, p.bank_account_id, p.bank_account_name, p.status, p.payment_run_number, "
                           f"p.amount_applied, p.journal_entry_id, COALESCE(p.number, 'Draft #' || p.payment_id) || ' — ' || p.vendor_name || ' — ' || p.amount || ' ' || p.currency AS label "
                           f"FROM mcp_payments p WHERE {w.sql()} ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"payment_id": r["payment_id"], "label": r["label"]} for r in rows])
        ids = [r["payment_id"] for r in rows]
        apps = await fetch("SELECT payment_id, bill_id, bill_number, vendor_ref, amount FROM mcp_payment_applications WHERE payment_id = ANY($1)", ids) if ids else []
        lines = await fetch("SELECT matched_id, bank_transaction_id, txn_date, amount, description FROM mcp_bank_transactions WHERE matched_type = 'payment' AND status = 'matched' AND matched_id = ANY($1)", ids) if ids else []
        for r in rows:
            r["applications"] = [{k: a[k] for k in ("bill_id", "bill_number", "vendor_ref", "amount")} for a in apps if a["payment_id"] == r["payment_id"]]
            r["bank_line"] = next(({k: x[k] for k in ("bank_transaction_id", "txn_date", "amount", "description")} for x in lines if x["matched_id"] == r["payment_id"]), None)
        return dumps({"payments": rows, "count": len(rows)})

    @mcp.tool(name="find_payment_runs", annotations={"title": "Find payment runs", **RO})
    async def find_payment_runs(params: FindRunsIn) -> str:
        """The payment runs by status and date, each with its payments, who approved and who recorded. `q` alone answers a plain list (payment_run_id, label)."""
        w = Where()
        if params.q:
            w.add("r.number ILIKE {}", params.q.strip())
        if params.status:
            w.add("r.status = {}", params.status)
        if params.bank_account_id is not None:
            w.add("r.bank_account_id = {}", params.bank_account_id)
        try:
            f, t = _dates(params.from_, params.to)
        except ValueError as e:
            return err(str(e))
        if f:
            w.add("r.pay_date >= {}", f)
        if t:
            w.add("r.pay_date <= {}", t)
        rows = await fetch(f"SELECT r.payment_run_id, r.number, r.pay_date, r.bank_account_id, r.bank_account_name, r.currency, r.status, r.total, r.payment_count, r.approved_by_name, r.approved_at, r.recorded_at, "
                           f"COALESCE(r.number, 'Draft #' || r.payment_run_id) || ' — ' || r.pay_date || ' — ' || r.total || ' ' || r.currency AS label FROM mcp_payment_runs r WHERE {w.sql()} ORDER BY r.pay_date DESC, r.payment_run_id DESC LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"payment_run_id": r["payment_run_id"], "label": r["label"]} for r in rows])
        for r in rows:
            r["payments"] = await fetch("SELECT payment_id, number, vendor_name, amount, currency, status, cheque_number FROM mcp_payments WHERE payment_run_id = $1 ORDER BY vendor_name", r["payment_run_id"])
        return dumps({"payment_runs": rows, "count": len(rows)})

    @mcp.tool(name="find_vendor_credits", annotations={"title": "Find vendor credits", **RO})
    async def find_vendor_credits(params: FindVendorCreditsIn) -> str:
        """Vendor credits by vendor, status, or the bill they are against, with where each is applied. `q` alone answers a plain list (vendor_credit_id, label)."""
        w = Where()
        if params.q:
            q = params.q.strip()
            w.raw(f"(c.number ILIKE {w.p(q)} OR c.vendor_ref ILIKE {w.p(q)})")
        if params.vendor_id is not None:
            w.add("c.vendor_id = {}", params.vendor_id)
        if params.status:
            w.add("c.status = {}", params.status)
        if params.bill_id is not None:
            w.add("c.bill_id = {}", params.bill_id)
        rows = await fetch(f"SELECT c.vendor_credit_id, c.number, c.vendor_id, c.vendor_name, c.bill_id, c.bill_number, c.vendor_ref, c.credit_date, c.currency, c.status, c.total, c.amount_applied, c.balance, c.reason, "
                           f"{doc_label('c.number', 'c.vendor_name', id_col='c.vendor_credit_id')} AS label FROM mcp_vendor_credits c WHERE {w.sql()} ORDER BY c.credit_date DESC, c.vendor_credit_id DESC LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"vendor_credit_id": r["vendor_credit_id"], "label": r["label"]} for r in rows])
        ids = [r["vendor_credit_id"] for r in rows]
        apps = await fetch("SELECT vendor_credit_id, bill_id, bill_number, amount FROM mcp_vendor_credit_applications WHERE vendor_credit_id = ANY($1)", ids) if ids else []
        for r in rows:
            r["applications"] = [{k: a[k] for k in ("bill_id", "bill_number", "amount")} for a in apps if a["vendor_credit_id"] == r["vendor_credit_id"]]
        return dumps({"vendor_credits": rows, "count": len(rows)})
