"""General Ledger — receivables (tool surface, "Receivables"). Reads the invoice, receipt, credit, deposit and claim views (documents.view), customers (parties.view; contact details,
notes and addresses only with ar.write — the view decides) and gl_ar_aging() (reports.view). A customer by the secure link is not a caller here."""
from __future__ import annotations

import datetime
from decimal import Decimal

from pydantic import Field

from gl_common import RO, Where, _Base, as_of, doc_label, dumps, err, has_right, lim, q_alone, to_date, word_start, words

OPEN_INV = ("issued", "sent", "viewed", "partially_paid")


class FindCustomersIn(_Base):
    q: str | None = Field(None, description="Name or words of it (word start). `q` alone answers a plain list (customer_id, name)")
    with_balance: bool | None = Field(None, description="Only customers who owe something")
    include_archived: bool = False
    limit: int = Field(25, ge=1, le=100)


class CustomerIn(_Base):
    customer_id: int


class StatementIn(_Base):
    customer_id: int
    from_: str = Field(..., alias="from")
    to: str


class FindInvoicesIn(_Base):
    q: str | None = Field(None, description="A number (INV-00042) or words of the customer, reference or memo. `q` alone answers a plain list (invoice_id, label = 'INV-00042 — Acme — 1608.24 USD')")
    customer_id: int | None = None
    status: list[str] | None = Field(None, description="draft, issued, sent, viewed, partially_paid, paid, written_off, void")
    overdue: bool | None = None
    from_: str | None = Field(None, alias="from", description="Issue date from")
    to: str | None = None
    department_id: int | None = None
    site_id: int | None = None
    limit: int = Field(25, ge=1, le=100)


class GetInvoiceIn(_Base):
    invoice_id: int | None = None
    number: str | None = None


class AgingIn(_Base):
    as_of: str | None = None
    customer_id: int | None = None
    vendor_id: int | None = None
    currency: str | None = None
    limit: int = Field(50, ge=1, le=100)


class FindReceiptsIn(_Base):
    q: str | None = Field(None, description="A number or reference. `q` alone answers a plain list (receipt_id, label)")
    customer_id: int | None = None
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    method: str | None = None
    unapplied: bool | None = Field(None, description="Money not yet put against an invoice")
    undeposited: bool | None = Field(None, description="Not yet in a deposit")
    limit: int = Field(25, ge=1, le=100)


class FindDepositsIn(_Base):
    q: str | None = Field(None, description="A number. `q` alone answers a plain list (deposit_id, label)")
    bank_account_id: int | None = None
    status: str | None = None
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    limit: int = Field(25, ge=1, le=100)


class FindCreditNotesIn(_Base):
    q: str | None = Field(None, description="A number. `q` alone answers a plain list (credit_note_id, label)")
    customer_id: int | None = None
    status: str | None = None
    invoice_id: int | None = None
    limit: int = Field(25, ge=1, le=100)


class OpenClaimsIn(_Base):
    status: str = Field("submitted", description="submitted (default), confirmed or dismissed")
    customer_id: int | None = None
    limit: int = Field(25, ge=1, le=100)


class RangeIn(_Base):
    from_: str = Field(..., alias="from")
    to: str


class DaysToPayIn(_Base):
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    customer_id: int | None = None
    limit: int = Field(25, ge=1, le=100)


def _dates(f, t):
    return to_date(f, "from"), to_date(t, "to")


def register(mcp, fetch) -> None:
    @mcp.tool(name="find_customers", annotations={"title": "Find customers", **RO})
    async def find_customers(params: FindCustomersIn) -> str:
        """Customers by name or words, with balance due, open invoices and last invoiced; contact details only with ar.write. Call before drafting an invoice. `q` alone answers a plain list (customer_id, name)."""
        w = Where()
        if not params.include_archived:
            w.raw("c.archived_at IS NULL")
        for word in words(params.q):
            n = w.p(word_start(word))
            w.raw(f"(c.name ~* {n} OR c.legal_name ~* {n})")
        if params.with_balance:
            w.raw("c.balance_due > 0")
        rows = await fetch(f"SELECT c.customer_id, c.name, c.legal_name, c.email, c.phone, c.terms_days, c.currency, c.balance_due, c.open_invoices, c.last_invoiced_on, c.archived_at FROM mcp_customers c WHERE {w.sql()} ORDER BY lower(c.name) LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"customer_id": r["customer_id"], "name": r["name"]} for r in rows])
        return dumps({"customers": rows, "count": len(rows)})

    @mcp.tool(name="get_customer", annotations={"title": "One customer in full", **RO})
    async def get_customer(params: CustomerIn) -> str:
        """One customer in full: terms, currency, accounts, contacts and notes (ar.write), open invoices, recent receipts and credits, open claims, attachments."""
        r = await fetch("SELECT * FROM mcp_customers WHERE customer_id = $1", params.customer_id)
        if not r:
            return err("No such customer.")
        cid = params.customer_id
        ar = await has_right(fetch, "ar.write")
        return dumps({
            "customer": r[0],
            "contacts": await fetch("SELECT party_contact_id, name, email, phone, role, is_primary FROM mcp_party_contacts WHERE party_kind = 'customer' AND party_id = $1 ORDER BY is_primary DESC, name", cid) if ar else None,
            "open_invoices": await fetch("SELECT invoice_id, number, issue_date, due_date, currency, status, total, balance_due, days_overdue FROM mcp_invoices WHERE customer_id = $1 AND status = ANY($2) ORDER BY due_date", cid, list(OPEN_INV)),
            "recent_receipts": await fetch("SELECT receipt_id, number, received_on, amount, currency, method, unapplied FROM mcp_receipts WHERE customer_id = $1 AND status = 'recorded' ORDER BY received_on DESC LIMIT 10", cid),
            "recent_credit_notes": await fetch("SELECT credit_note_id, number, issue_date, currency, total, balance FROM mcp_credit_notes WHERE customer_id = $1 AND status = 'issued' ORDER BY issue_date DESC LIMIT 10", cid),
            "open_claims": await fetch("SELECT receipt_claim_id, invoice_number, claimed_on, method, reference, amount FROM mcp_receipt_claims WHERE customer_id = $1 AND status = 'submitted' ORDER BY claimed_on", cid),
            "notes": await fetch("SELECT note_id, member_name, body, created_at FROM mcp_notes WHERE record_type = 'customer' AND record_id = $1 ORDER BY created_at DESC LIMIT 20", cid) if ar else None,
            "attachments": await fetch("SELECT attachment_id, filename, mime_type, byte_size, created_at FROM mcp_attachments WHERE record_type = 'customer' AND record_id = $1 ORDER BY created_at", cid),
        })

    @mcp.tool(name="customer_statement", annotations={"title": "A customer's statement", **RO})
    async def customer_statement(params: StatementIn) -> str:
        """A customer's statement for a period: the opening balance, each invoice, receipt, credit and write-off in date order with the running balance, and the closing balance."""
        try:
            f, t = _dates(params.from_, params.to)
        except ValueError as e:
            return err(str(e))
        c = await fetch("SELECT customer_id, name FROM mcp_customers WHERE customer_id = $1", params.customer_id)
        if not c:
            return err("No such customer.")
        rows = await fetch("""SELECT d, kind, number, id, amount, memo FROM (
              SELECT issue_date AS d, 'invoice' AS kind, number, invoice_id AS id, total AS amount, NULL::text AS memo FROM mcp_invoices WHERE customer_id = $1 AND status NOT IN ('draft', 'void')
              UNION ALL SELECT received_on, 'receipt', number, receipt_id, -amount, reference FROM mcp_receipts WHERE customer_id = $1 AND status = 'recorded'
              UNION ALL SELECT issue_date, 'credit_note', number, credit_note_id, -total, NULL FROM mcp_credit_notes WHERE customer_id = $1 AND status = 'issued'
              UNION ALL SELECT written_off_at::date, 'write_off', number, invoice_id, -balance_due, write_off_reason FROM mcp_invoices WHERE customer_id = $1 AND status = 'written_off') t ORDER BY d, id""", params.customer_id)
        running, opening, out = Decimal("0.00"), Decimal("0.00"), []
        for r in rows:
            running += Decimal(str(r["amount"]))
            if r["d"] < f:
                opening = running
            elif r["d"] <= t:
                a = Decimal(str(r["amount"]))
                out.append({"date": r["d"], "kind": r["kind"], "number": r["number"], "id": r["id"], "memo": r["memo"], "debit": a if a > 0 else None, "credit": -a if a < 0 else None, "balance": running})
        return dumps({"customer_id": c[0]["customer_id"], "customer_name": c[0]["name"], "from": f, "to": t, "opening_balance": opening, "rows": out, "closing_balance": running})

    @mcp.tool(name="find_invoices", annotations={"title": "Find invoices", **RO})
    async def find_invoices(params: FindInvoicesIn) -> str:
        """Invoices by number, customer, status, overdue, date range, department or site, or words in the customer, reference or memo; each with totals, paid, credited, balance, days overdue, open claims and whether
        its link is live. `q` alone answers a plain list (invoice_id, label = 'INV-00042 — Acme — 1608.24 USD')."""
        w = Where()
        if params.q:
            q = params.q.strip()
            parts = [f"i.number ILIKE {w.p(q)}"] + [f"(i.customer_name ~* {(n := w.p(word_start(x)))} OR i.reference ~* {n} OR i.memo ~* {n})" for x in words(q)]
            w.raw("(" + parts[0] + (" OR (" + " AND ".join(parts[1:]) + ")" if len(parts) > 1 else "") + ")")
        if params.customer_id is not None:
            w.add("i.customer_id = {}", params.customer_id)
        if params.status:
            w.add("i.status = ANY({})", params.status)
        if params.overdue is not None:
            w.raw("i.overdue" if params.overdue else "NOT i.overdue")
        try:
            f, t = _dates(params.from_, params.to)
        except ValueError as e:
            return err(str(e))
        if f:
            w.add("i.issue_date >= {}", f)
        if t:
            w.add("i.issue_date <= {}", t)
        for col, v in (("department_id", params.department_id), ("site_id", params.site_id)):
            if v is not None:
                w.add(f"i.{col} = {{}}", v)
        rows = await fetch(f"SELECT i.invoice_id, i.number, i.customer_id, i.customer_name, i.issue_date, i.due_date, i.currency, i.status, i.subtotal, i.tax_total, i.total, i.amount_paid, i.amount_credited, i.balance_due, "
                           f"i.overdue, i.days_overdue, i.reference, i.sent_at, i.viewed_at, i.paid_at, i.last_reminder_at, i.reminder_count, i.open_claims, i.has_live_link, i.attachment_count, "
                           f"{doc_label('i.number', 'i.customer_name', id_col='i.invoice_id')} AS label FROM mcp_invoices i WHERE {w.sql()} ORDER BY i.issue_date DESC, i.invoice_id DESC LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"invoice_id": r["invoice_id"], "label": r["label"]} for r in rows])
        return dumps({"invoices": rows, "count": len(rows)})

    @mcp.tool(name="get_invoice", annotations={"title": "One invoice in full", **RO})
    async def get_invoice(params: GetInvoiceIn) -> str:
        """One invoice in full: lines, the receipts and credits applied with dates, claims, the entry it posted (number) and its write-off entry, the link's state (live, never the token), reminders, attachments and notes."""
        if params.invoice_id is not None:
            r = await fetch("SELECT * FROM mcp_invoices WHERE invoice_id = $1", params.invoice_id)
        elif params.number:
            r = await fetch("SELECT * FROM mcp_invoices WHERE number ILIKE $1", params.number.strip())
        else:
            return err("Give invoice_id or number.")
        if not r:
            return err("No such invoice (or you may not see it).")
        inv = r[0]
        iid = inv["invoice_id"]
        je = await fetch("SELECT journal_entry_id, number FROM mcp_journal_entries WHERE journal_entry_id = ANY($1)", [x for x in (inv["journal_entry_id"], inv["write_off_entry_id"]) if x])
        nums = {e["journal_entry_id"]: e["number"] for e in je}
        inv["journal_entry_number"] = nums.get(inv["journal_entry_id"])
        inv["write_off_entry_number"] = nums.get(inv["write_off_entry_id"])
        return dumps({
            "invoice": inv,
            "lines": await fetch("SELECT sort_order, description, quantity, unit_price, tax_rate, line_subtotal, line_tax, line_total, income_account_code, department_id, site_id FROM mcp_invoice_lines WHERE invoice_id = $1 ORDER BY sort_order", iid),
            "receipts_applied": await fetch("SELECT receipt_id, receipt_number, amount, applied_at FROM mcp_receipt_applications WHERE invoice_id = $1 ORDER BY applied_at", iid),
            "credits_applied": await fetch("SELECT credit_note_id, credit_note_number, amount, applied_at FROM mcp_credit_applications WHERE invoice_id = $1 ORDER BY applied_at", iid),
            "claims": await fetch("SELECT receipt_claim_id, claimed_on, method, reference, amount, status, created_at FROM mcp_receipt_claims WHERE invoice_id = $1 ORDER BY created_at", iid),
            "attachments": await fetch("SELECT attachment_id, filename, mime_type, byte_size, created_at FROM mcp_attachments WHERE record_type = 'invoice' AND record_id = $1 ORDER BY created_at", iid),
            "notes": await fetch("SELECT note_id, member_name, member_kind, body, created_at FROM mcp_notes WHERE record_type = 'invoice' AND record_id = $1 ORDER BY created_at", iid),
        })

    @mcp.tool(name="ar_aging", annotations={"title": "Who owes us", **RO})
    async def ar_aging(params: AgingIn) -> str:
        """Who owes us as of a date, per customer and currency, in the five buckets (current, 1–30, 31–60, 61–90, over 90) with open invoice counts and totals per currency; with customer_id, that customer's open invoices bucketed too."""
        d = as_of(params.as_of)
        w = Where()
        if params.customer_id is not None:
            w.add("customer_id = {}", params.customer_id)
        if params.currency:
            w.add("currency = {}", params.currency.upper())
        rows = await fetch(f"SELECT customer_id, customer_name, currency, current_due, days_1_30, days_31_60, days_61_90, days_over_90, total, open_invoices FROM gl_ar_aging({w.p(d)}) WHERE {w.sql()} ORDER BY customer_name, currency LIMIT {lim(params.limit, 50)}", *w.args)
        totals: dict[str, dict] = {}
        for x in rows:
            t = totals.setdefault(x["currency"], {"currency": x["currency"], "current_due": Decimal(0), "days_1_30": Decimal(0), "days_31_60": Decimal(0), "days_61_90": Decimal(0), "days_over_90": Decimal(0), "total": Decimal(0), "open_invoices": 0})
            for k in ("current_due", "days_1_30", "days_31_60", "days_61_90", "days_over_90", "total"):
                t[k] += Decimal(str(x[k]))
            t["open_invoices"] += x["open_invoices"]
        out = {"as_of": d, "customers": rows, "totals": list(totals.values())}
        if params.customer_id is not None:
            out["open_invoices"] = await fetch("SELECT invoice_id, number, issue_date, due_date, currency, balance_due, GREATEST(0, $2::date - due_date) AS days_overdue FROM mcp_invoices WHERE customer_id = $1 AND status = ANY($3) AND balance_due > 0 ORDER BY due_date", params.customer_id, d, list(OPEN_INV))
        return dumps(out)

    @mcp.tool(name="find_receipts", annotations={"title": "Find receipts (money received)", **RO})
    async def find_receipts(params: FindReceiptsIn) -> str:
        """Money received by customer, date range, method, unapplied (not yet against an invoice) or undeposited (not yet in a deposit), each with its applications and deposit. `q` alone answers a plain list (receipt_id, label)."""
        w = Where()
        if params.q:
            q = params.q.strip()
            w.raw(f"(r.number ILIKE {w.p(q)} OR r.reference ILIKE {w.p('%' + q + '%')} OR r.customer_name ~* {w.p(word_start(q))})")
        if params.customer_id is not None:
            w.add("r.customer_id = {}", params.customer_id)
        try:
            f, t = _dates(params.from_, params.to)
        except ValueError as e:
            return err(str(e))
        if f:
            w.add("r.received_on >= {}", f)
        if t:
            w.add("r.received_on <= {}", t)
        if params.method:
            w.add("r.method = {}", params.method)
        if params.unapplied:
            w.raw("r.status = 'recorded' AND r.unapplied > 0")
        if params.undeposited:
            w.raw("r.status = 'recorded' AND r.deposit_id IS NULL AND r.deposit_to_bank_account_id IS NULL")
        rows = await fetch(f"SELECT r.receipt_id, r.number, r.customer_id, r.customer_name, r.received_on, r.amount, r.currency, r.method, r.reference, r.deposit_to_bank_name, r.deposit_number, r.status, r.unapplied, "
                           f"COALESCE(r.number, 'Draft #' || r.receipt_id) || ' — ' || r.customer_name || ' — ' || r.amount || ' ' || r.currency AS label FROM mcp_receipts r WHERE {w.sql()} ORDER BY r.received_on DESC, r.receipt_id DESC LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"receipt_id": r["receipt_id"], "label": r["label"]} for r in rows])
        ids = [r["receipt_id"] for r in rows]
        apps = await fetch("SELECT receipt_id, invoice_id, invoice_number, amount FROM mcp_receipt_applications WHERE receipt_id = ANY($1) ORDER BY applied_at", ids) if ids else []
        for r in rows:
            r["applications"] = [{"invoice_id": a["invoice_id"], "invoice_number": a["invoice_number"], "amount": a["amount"]} for a in apps if a["receipt_id"] == r["receipt_id"]]
        return dumps({"receipts": rows, "count": len(rows)})

    @mcp.tool(name="find_deposits", annotations={"title": "Find deposits", **RO})
    async def find_deposits(params: FindDepositsIn) -> str:
        """Deposits by bank account, status and date, with their receipts and the bank line each is matched to. `q` alone answers a plain list (deposit_id, label)."""
        w = Where()
        if params.q:
            w.add("d.number ILIKE {}", params.q.strip())
        if params.bank_account_id is not None:
            w.add("d.bank_account_id = {}", params.bank_account_id)
        if params.status:
            w.add("d.status = {}", params.status)
        try:
            f, t = _dates(params.from_, params.to)
        except ValueError as e:
            return err(str(e))
        if f:
            w.add("d.deposit_date >= {}", f)
        if t:
            w.add("d.deposit_date <= {}", t)
        rows = await fetch(f"SELECT d.deposit_id, d.number, d.bank_account_id, d.bank_account_name, d.deposit_date, d.currency, d.total, d.memo, d.status, d.receipt_count, "
                           f"COALESCE(d.number, 'Draft #' || d.deposit_id) || ' — ' || d.bank_account_name || ' — ' || d.total || ' ' || d.currency AS label FROM mcp_deposits d WHERE {w.sql()} ORDER BY d.deposit_date DESC, d.deposit_id DESC LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"deposit_id": r["deposit_id"], "label": r["label"]} for r in rows])
        for r in rows:
            r["receipts"] = await fetch("SELECT receipt_id, number, customer_name, amount FROM mcp_receipts WHERE deposit_id = $1 ORDER BY number", r["deposit_id"])
            r["bank_line"] = (await fetch("SELECT bank_transaction_id, txn_date, amount, description FROM mcp_bank_transactions WHERE matched_type = 'deposit' AND matched_id = $1 AND status = 'matched' LIMIT 1", r["deposit_id"]) or [None])[0]
        return dumps({"deposits": rows, "count": len(rows)})

    @mcp.tool(name="find_credit_notes", annotations={"title": "Find credit notes", **RO})
    async def find_credit_notes(params: FindCreditNotesIn) -> str:
        """Credit notes by customer, status, or the invoice they were raised against, with where each is applied. `q` alone answers a plain list (credit_note_id, label)."""
        w = Where()
        if params.q:
            w.add("c.number ILIKE {}", params.q.strip())
        if params.customer_id is not None:
            w.add("c.customer_id = {}", params.customer_id)
        if params.status:
            w.add("c.status = {}", params.status)
        if params.invoice_id is not None:
            w.add("c.invoice_id = {}", params.invoice_id)
        rows = await fetch(f"SELECT c.credit_note_id, c.number, c.customer_id, c.customer_name, c.invoice_id, c.invoice_number, c.issue_date, c.currency, c.status, c.total, c.amount_applied, c.balance, c.reason, "
                           f"{doc_label('c.number', 'c.customer_name', id_col='c.credit_note_id')} AS label FROM mcp_credit_notes c WHERE {w.sql()} ORDER BY c.issue_date DESC, c.credit_note_id DESC LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"credit_note_id": r["credit_note_id"], "label": r["label"]} for r in rows])
        ids = [r["credit_note_id"] for r in rows]
        apps = await fetch("SELECT credit_note_id, invoice_id, invoice_number, amount FROM mcp_credit_applications WHERE credit_note_id = ANY($1)", ids) if ids else []
        for r in rows:
            r["applications"] = [{"invoice_id": a["invoice_id"], "invoice_number": a["invoice_number"], "amount": a["amount"]} for a in apps if a["credit_note_id"] == r["credit_note_id"]]
        return dumps({"credit_notes": rows, "count": len(rows)})

    @mcp.tool(name="open_claims", annotations={"title": "What customers said they paid", **RO})
    async def open_claims(params: OpenClaimsIn) -> str:
        """What customers said on the secure link that is not yet confirmed: the invoice, customer, claimed date, method, reference and amount (status submitted by default)."""
        w = Where().add("c.status = {}", params.status)
        if params.customer_id is not None:
            w.add("c.customer_id = {}", params.customer_id)
        rows = await fetch(f"SELECT c.receipt_claim_id, c.invoice_id, c.invoice_number, c.customer_id, c.customer_name, c.claimed_on, c.method, c.reference, c.amount, c.status, c.created_at FROM mcp_receipt_claims c WHERE {w.sql()} ORDER BY c.created_at LIMIT {lim(params.limit)}", *w.args)
        return dumps({"claims": rows, "count": len(rows)})

    @mcp.tool(name="sales_tax", annotations={"title": "Sales tax charged", **RO})
    async def sales_tax(params: RangeIn) -> str:
        """Tax charged in a period by rate: taxable sales and tax, from issued invoices less issued credit notes."""
        try:
            f, t = _dates(params.from_, params.to)
        except ValueError as e:
            return err(str(e))
        rows = await fetch("""SELECT t.tax_rate_id, COALESCE(t.name, 'No tax') AS name, t.rate, COALESCE(sum(l.line_subtotal), 0) AS taxable, COALESCE(sum(l.line_tax), 0) AS tax
              FROM (SELECT tax_rate_id, line_subtotal, line_tax FROM mcp_invoice_lines il JOIN mcp_invoices i ON i.invoice_id = il.invoice_id WHERE i.status NOT IN ('draft', 'void') AND i.issue_date BETWEEN $1 AND $2
                    UNION ALL SELECT tax_rate_id, -line_subtotal, -line_tax FROM mcp_credit_note_lines cl JOIN mcp_credit_notes c ON c.credit_note_id = cl.credit_note_id WHERE c.status = 'issued' AND c.issue_date BETWEEN $1 AND $2) l
              LEFT JOIN mcp_tax_rates t ON t.tax_rate_id = l.tax_rate_id GROUP BY t.tax_rate_id, t.name, t.rate ORDER BY t.rate NULLS FIRST""", f, t)
        return dumps({"from": f, "to": t, "rows": rows, "taxable_total": sum((Decimal(str(r["taxable"])) for r in rows), Decimal("0.00")), "tax_total": sum((Decimal(str(r["tax"])) for r in rows), Decimal("0.00"))})

    @mcp.tool(name="days_to_pay", annotations={"title": "How fast customers pay", **RO})
    async def days_to_pay(params: DaysToPayIn) -> str:
        """Average and median days from issue to paid, overall and per customer, for invoices paid in a period (the last 90 days by default)."""
        try:
            f, t = _dates(params.from_, params.to)
        except ValueError as e:
            return err(str(e))
        t = t or datetime.date.today()
        f = f or t - datetime.timedelta(days=90)
        w = Where().raw("i.status = 'paid'").add("i.paid_at::date BETWEEN {} AND {}", f, t)
        if params.customer_id is not None:
            w.add("i.customer_id = {}", params.customer_id)
        base = f"FROM mcp_invoices i WHERE {w.sql()}"
        overall = await fetch(f"SELECT count(*) AS invoices, round(avg(i.paid_at::date - i.issue_date), 1) AS average_days, percentile_cont(0.5) WITHIN GROUP (ORDER BY (i.paid_at::date - i.issue_date)) AS median_days {base}", *w.args)
        per = await fetch(f"SELECT i.customer_id, i.customer_name, count(*) AS invoices, round(avg(i.paid_at::date - i.issue_date), 1) AS average_days, percentile_cont(0.5) WITHIN GROUP (ORDER BY (i.paid_at::date - i.issue_date)) AS median_days "
                          f"{base} GROUP BY i.customer_id, i.customer_name ORDER BY 4 DESC LIMIT {lim(params.limit)}", *w.args)
        return dumps({"from": f, "to": t, "overall": overall[0] if overall else None, "customers": per})
