-- 010: ACCOUNTS RECEIVABLE (design §6, D8–D10): invoices with gapless numbers on issue, credit notes, receipts (money in)
-- and their applications, the customer's claim from the secure link, deposits (receipts grouped into one bank line),
-- and the secure link itself.
--
-- Money rules (the kernel's retired sales spec, kept): totals are stored and written in ONE place
-- (gl_invoice_recalculate); a line's money is line-level, rounded per line; one currency per document; paid is derived
-- from the applications, never typed; `balance_due` is a generated column. Issuing an invoice POSTS it (one
-- transaction): DR receivable (the customer named) / CR each line's income account / CR sales tax. A receipt posts
-- DR undeposited funds (or the bank it names) / CR receivable; a deposit DR bank / CR undeposited funds.
BEGIN;

CREATE TABLE invoices (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number              text UNIQUE,                                             -- gapless, on issue (D10)
    customer_id         bigint NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
    issue_date          date NOT NULL DEFAULT current_date,
    due_date            date NOT NULL,
    currency            char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    status              text NOT NULL DEFAULT 'draft'
                        CHECK (status IN ('draft', 'issued', 'sent', 'viewed', 'partially_paid', 'paid', 'void', 'written_off')),
    subtotal            numeric(14,2) NOT NULL DEFAULT 0,
    tax_total           numeric(14,2) NOT NULL DEFAULT 0,
    total               numeric(14,2) NOT NULL DEFAULT 0,
    amount_paid         numeric(14,2) NOT NULL DEFAULT 0 CHECK (amount_paid >= 0),
    amount_credited     numeric(14,2) NOT NULL DEFAULT 0 CHECK (amount_credited >= 0),
    balance_due         numeric(14,2) GENERATED ALWAYS AS (total - amount_paid - amount_credited) STORED,
    reference           text,                                                    -- the customer's PO
    memo                text,                                                    -- on the invoice
    terms_text          text,
    department_id       bigint REFERENCES departments(id) ON DELETE SET NULL,
    site_id             bigint REFERENCES sites(id) ON DELETE SET NULL,
    journal_entry_id    bigint REFERENCES journal_entries(id) ON DELETE SET NULL,
    write_off_entry_id  bigint REFERENCES journal_entries(id) ON DELETE SET NULL,
    issued_at           timestamptz,
    issued_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    sent_at             timestamptz,
    viewed_at           timestamptz,
    paid_at             timestamptz,
    voided_at           timestamptz,
    voided_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    void_reason         text,
    written_off_at      timestamptz,
    written_off_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    write_off_reason    text,
    last_reminder_at    timestamptz,
    reminder_count      integer NOT NULL DEFAULT 0 CHECK (reminder_count >= 0),
    created_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (status = 'draft' OR number IS NOT NULL),
    CHECK (due_date >= issue_date)
);
CREATE INDEX invoices_customer_idx ON invoices (customer_id, issue_date DESC);
CREATE INDEX invoices_status_due_idx ON invoices (status, due_date);
CREATE INDEX invoices_open_idx ON invoices (due_date) WHERE status IN ('issued', 'sent', 'viewed', 'partially_paid');
CREATE INDEX invoices_issue_idx ON invoices (issue_date);
CREATE INDEX invoices_dept_idx ON invoices (department_id);
CREATE INDEX invoices_site_idx ON invoices (site_id);
CREATE INDEX invoices_entry_idx ON invoices (journal_entry_id);
CREATE INDEX invoices_write_off_entry_idx ON invoices (write_off_entry_id);
CREATE INDEX invoices_issued_by_idx ON invoices (issued_by);
CREATE INDEX invoices_voided_by_idx ON invoices (voided_by);
CREATE INDEX invoices_written_off_by_idx ON invoices (written_off_by);
CREATE INDEX invoices_created_by_idx ON invoices (created_by);
CREATE TRIGGER invoices_touch BEFORE UPDATE ON invoices FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE invoice_lines (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id         bigint NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    sort_order         integer NOT NULL DEFAULT 0,
    item_id            bigint REFERENCES items(id) ON DELETE SET NULL,
    description        text NOT NULL,
    quantity           numeric(12,3) NOT NULL DEFAULT 1 CHECK (quantity > 0),
    unit_price         numeric(14,2) NOT NULL CHECK (unit_price >= 0),
    tax_rate_id        bigint REFERENCES tax_rates(id) ON DELETE SET NULL,
    tax_rate           numeric(7,4) NOT NULL DEFAULT 0,                          -- snapshot of the rate at the time
    line_subtotal      numeric(14,2) NOT NULL DEFAULT 0,
    line_tax           numeric(14,2) NOT NULL DEFAULT 0,
    line_total         numeric(14,2) NOT NULL DEFAULT 0,
    income_account_id  bigint NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
    department_id      bigint REFERENCES departments(id) ON DELETE SET NULL,
    site_id            bigint REFERENCES sites(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX invoice_lines_invoice_idx ON invoice_lines (invoice_id, sort_order);
CREATE INDEX invoice_lines_item_idx ON invoice_lines (item_id);
CREATE INDEX invoice_lines_tax_idx ON invoice_lines (tax_rate_id);
CREATE INDEX invoice_lines_account_idx ON invoice_lines (income_account_id);
CREATE INDEX invoice_lines_dept_idx ON invoice_lines (department_id);
CREATE INDEX invoice_lines_site_idx ON invoice_lines (site_id);
CREATE TRIGGER invoice_lines_touch BEFORE UPDATE ON invoice_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE credit_notes (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number              text UNIQUE,                                             -- gapless, on issue
    customer_id         bigint NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
    invoice_id          bigint REFERENCES invoices(id) ON DELETE SET NULL,       -- the invoice it was raised against, if one
    issue_date          date NOT NULL DEFAULT current_date,
    currency            char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    status              text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'issued', 'void')),
    subtotal            numeric(14,2) NOT NULL DEFAULT 0,
    tax_total           numeric(14,2) NOT NULL DEFAULT 0,
    total               numeric(14,2) NOT NULL DEFAULT 0,
    amount_applied      numeric(14,2) NOT NULL DEFAULT 0 CHECK (amount_applied >= 0),
    balance             numeric(14,2) GENERATED ALWAYS AS (total - amount_applied) STORED,
    reason              text,
    department_id       bigint REFERENCES departments(id) ON DELETE SET NULL,
    site_id             bigint REFERENCES sites(id) ON DELETE SET NULL,
    journal_entry_id    bigint REFERENCES journal_entries(id) ON DELETE SET NULL,
    issued_at           timestamptz,
    issued_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    voided_at           timestamptz,
    voided_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    void_reason         text,
    created_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (status = 'draft' OR number IS NOT NULL)
);
CREATE INDEX credit_notes_customer_idx ON credit_notes (customer_id, issue_date DESC);
CREATE INDEX credit_notes_invoice_idx ON credit_notes (invoice_id);
CREATE INDEX credit_notes_dept_idx ON credit_notes (department_id);
CREATE INDEX credit_notes_site_idx ON credit_notes (site_id);
CREATE INDEX credit_notes_entry_idx ON credit_notes (journal_entry_id);
CREATE INDEX credit_notes_issued_by_idx ON credit_notes (issued_by);
CREATE INDEX credit_notes_voided_by_idx ON credit_notes (voided_by);
CREATE INDEX credit_notes_created_by_idx ON credit_notes (created_by);
CREATE TRIGGER credit_notes_touch BEFORE UPDATE ON credit_notes FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE credit_note_lines (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    credit_note_id     bigint NOT NULL REFERENCES credit_notes(id) ON DELETE CASCADE,
    sort_order         integer NOT NULL DEFAULT 0,
    item_id            bigint REFERENCES items(id) ON DELETE SET NULL,
    description        text NOT NULL,
    quantity           numeric(12,3) NOT NULL DEFAULT 1 CHECK (quantity > 0),
    unit_price         numeric(14,2) NOT NULL CHECK (unit_price >= 0),
    tax_rate_id        bigint REFERENCES tax_rates(id) ON DELETE SET NULL,
    tax_rate           numeric(7,4) NOT NULL DEFAULT 0,
    line_subtotal      numeric(14,2) NOT NULL DEFAULT 0,
    line_tax           numeric(14,2) NOT NULL DEFAULT 0,
    line_total         numeric(14,2) NOT NULL DEFAULT 0,
    income_account_id  bigint NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
    department_id      bigint REFERENCES departments(id) ON DELETE SET NULL,
    site_id            bigint REFERENCES sites(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX credit_note_lines_note_idx ON credit_note_lines (credit_note_id, sort_order);
CREATE INDEX credit_note_lines_item_idx ON credit_note_lines (item_id);
CREATE INDEX credit_note_lines_tax_idx ON credit_note_lines (tax_rate_id);
CREATE INDEX credit_note_lines_account_idx ON credit_note_lines (income_account_id);
CREATE INDEX credit_note_lines_dept_idx ON credit_note_lines (department_id);
CREATE INDEX credit_note_lines_site_idx ON credit_note_lines (site_id);
CREATE TRIGGER credit_note_lines_touch BEFORE UPDATE ON credit_note_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- An issued credit applied to an invoice (no money moves; both documents' balances fall).
CREATE TABLE credit_applications (
    credit_note_id  bigint NOT NULL REFERENCES credit_notes(id) ON DELETE RESTRICT,
    invoice_id      bigint NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT,
    amount          numeric(14,2) NOT NULL CHECK (amount > 0),
    applied_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    applied_at      timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (credit_note_id, invoice_id)
);
CREATE INDEX credit_applications_invoice_idx ON credit_applications (invoice_id);
CREATE INDEX credit_applications_by_idx ON credit_applications (applied_by);

-- ---------------------------------------------------------------------------------------------
-- Receipts: customer money IN (named apart from `payments`, money OUT, so no screen ever confuses them).
-- deposit_to: a bank account (FK in db/013) or NULL = Undeposited funds, from which a deposit (below) moves it.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE deposits (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number           text NOT NULL UNIQUE,                                       -- DEP-, at creation
    bank_account_id  bigint NOT NULL,                                            -- FK in db/013
    deposit_date     date NOT NULL DEFAULT current_date,
    currency         char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    total            numeric(14,2) NOT NULL DEFAULT 0 CHECK (total >= 0),       -- the sum of its receipts (trigger)
    memo             text,
    status           text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'recorded', 'void')),
    journal_entry_id bigint REFERENCES journal_entries(id) ON DELETE SET NULL,
    recorded_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    recorded_at      timestamptz,
    voided_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    voided_at        timestamptz,
    void_reason      text,
    created_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX deposits_bank_idx ON deposits (bank_account_id, deposit_date DESC);
CREATE INDEX deposits_entry_idx ON deposits (journal_entry_id);
CREATE INDEX deposits_recorded_by_idx ON deposits (recorded_by);
CREATE INDEX deposits_voided_by_idx ON deposits (voided_by);
CREATE INDEX deposits_created_by_idx ON deposits (created_by);
CREATE TRIGGER deposits_touch BEFORE UPDATE ON deposits FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE receipts (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number              text NOT NULL UNIQUE,                                    -- RCT-, at creation
    customer_id         bigint NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
    received_on         date NOT NULL DEFAULT current_date,
    amount              numeric(14,2) NOT NULL CHECK (amount > 0),
    currency            char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    method              text NOT NULL CHECK (method IN ('cash', 'cheque', 'transfer', 'card', 'other')),
    reference           text,                                                    -- cheque number, bank reference
    memo                text,
    deposit_to_bank_account_id bigint,                                           -- FK in db/013; NULL = undeposited funds
    deposit_id          bigint REFERENCES deposits(id) ON DELETE SET NULL,
    status              text NOT NULL DEFAULT 'recorded' CHECK (status IN ('recorded', 'void')),
    journal_entry_id    bigint REFERENCES journal_entries(id) ON DELETE SET NULL,
    recorded_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    voided_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    voided_at           timestamptz,
    void_reason         text,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX receipts_customer_idx ON receipts (customer_id, received_on DESC);
CREATE INDEX receipts_received_idx ON receipts (received_on);
CREATE INDEX receipts_undeposited_idx ON receipts (received_on) WHERE deposit_to_bank_account_id IS NULL AND deposit_id IS NULL AND status = 'recorded';
CREATE INDEX receipts_deposit_idx ON receipts (deposit_id);
CREATE INDEX receipts_bank_idx ON receipts (deposit_to_bank_account_id);
CREATE INDEX receipts_entry_idx ON receipts (journal_entry_id);
CREATE INDEX receipts_recorded_by_idx ON receipts (recorded_by);
CREATE INDEX receipts_voided_by_idx ON receipts (voided_by);
CREATE TRIGGER receipts_touch BEFORE UPDATE ON receipts FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE receipt_applications (
    receipt_id  bigint NOT NULL REFERENCES receipts(id) ON DELETE RESTRICT,
    invoice_id  bigint NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT,
    amount      numeric(14,2) NOT NULL CHECK (amount > 0),
    applied_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    applied_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (receipt_id, invoice_id)
);
CREATE INDEX receipt_applications_invoice_idx ON receipt_applications (invoice_id);
CREATE INDEX receipt_applications_by_idx ON receipt_applications (applied_by);

-- What a customer said on the secure link (D9): "I paid this on … by …". Never money until a person confirms it
-- into a receipt.
CREATE TABLE receipt_claims (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id   bigint NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    claimed_on   date NOT NULL,
    method       text NOT NULL CHECK (method IN ('cash', 'cheque', 'transfer', 'card', 'other')),
    reference    text,
    amount       numeric(14,2) CHECK (amount > 0),
    note         text,
    status       text NOT NULL DEFAULT 'submitted' CHECK (status IN ('submitted', 'confirmed', 'dismissed')),
    receipt_id   bigint REFERENCES receipts(id) ON DELETE SET NULL,
    decided_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at   timestamptz,
    ip_address   inet,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX receipt_claims_invoice_idx ON receipt_claims (invoice_id, created_at DESC);
CREATE INDEX receipt_claims_open_idx ON receipt_claims (created_at) WHERE status = 'submitted';
CREATE INDEX receipt_claims_receipt_idx ON receipt_claims (receipt_id);
CREATE INDEX receipt_claims_decided_by_idx ON receipt_claims (decided_by);

-- The customer's door (D1 of the help desk, D9 here): /i/<48 hex>. Only the hash is stored; rotating makes a new row
-- and dates the old; the link dies link_lifetime_days after the invoice is paid (the worker sets expires_at).
CREATE TABLE invoice_links_secure (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id    bigint NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    token_hash    text NOT NULL UNIQUE,
    expires_at    timestamptz,
    rotated_at    timestamptz,
    last_used_at  timestamptz,
    view_count    integer NOT NULL DEFAULT 0,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX invoice_links_secure_invoice_idx ON invoice_links_secure (invoice_id) WHERE rotated_at IS NULL;

-- ---------------------------------------------------------------------------------------------
-- Lines: money computed per line; changeable while the document is a draft only; the document recalculated after.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION gl_invoice_line_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE st text; m record;
BEGIN
    SELECT status INTO st FROM invoices WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);
    IF st IS DISTINCT FROM 'draft' THEN
        RAISE EXCEPTION 'The lines of an invoice change only while it is a draft (it is %)', st;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    IF NEW.tax_rate_id IS NOT NULL THEN
        SELECT rate INTO NEW.tax_rate FROM tax_rates WHERE id = NEW.tax_rate_id;
    ELSE
        NEW.tax_rate := 0;
    END IF;
    SELECT * INTO m FROM gl_line_money(NEW.quantity, NEW.unit_price, NEW.tax_rate);
    NEW.line_subtotal := m.line_subtotal; NEW.line_tax := m.line_tax; NEW.line_total := m.line_total;
    RETURN NEW;
END$$;
CREATE TRIGGER invoice_lines_before BEFORE INSERT OR UPDATE OR DELETE ON invoice_lines FOR EACH ROW EXECUTE FUNCTION gl_invoice_line_before();

-- The ONE place an invoice's totals, paid, credited and status are written.
CREATE OR REPLACE FUNCTION gl_invoice_recalculate(p_invoice_id bigint) RETURNS invoices
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE i invoices%ROWTYPE; s numeric(14,2); t numeric(14,2); paid numeric(14,2); cred numeric(14,2); ns text;
BEGIN
    SELECT * INTO i FROM invoices WHERE id = p_invoice_id FOR UPDATE;
    IF i.id IS NULL THEN RAISE EXCEPTION 'No such invoice'; END IF;
    SELECT COALESCE(sum(line_subtotal), 0), COALESCE(sum(line_tax), 0) INTO s, t FROM invoice_lines WHERE invoice_id = i.id;
    SELECT COALESCE(sum(ra.amount), 0) INTO paid FROM receipt_applications ra JOIN receipts r ON r.id = ra.receipt_id AND r.status = 'recorded' WHERE ra.invoice_id = i.id;
    SELECT COALESCE(sum(ca.amount), 0) INTO cred FROM credit_applications ca JOIN credit_notes c ON c.id = ca.credit_note_id AND c.status = 'issued' WHERE ca.invoice_id = i.id;
    ns := i.status;
    IF i.status IN ('issued', 'sent', 'viewed', 'partially_paid', 'paid') THEN
        IF s + t - paid - cred <= 0 AND s + t > 0 THEN ns := 'paid';
        ELSIF paid + cred > 0 THEN ns := 'partially_paid';
        ELSIF i.viewed_at IS NOT NULL THEN ns := 'viewed';
        ELSIF i.sent_at IS NOT NULL THEN ns := 'sent';
        ELSE ns := 'issued';
        END IF;
    END IF;
    UPDATE invoices SET subtotal = CASE WHEN status = 'draft' THEN s ELSE subtotal END,
                        tax_total = CASE WHEN status = 'draft' THEN t ELSE tax_total END,
                        total = CASE WHEN status = 'draft' THEN s + t ELSE total END,
                        amount_paid = paid, amount_credited = cred, status = ns,
                        paid_at = CASE WHEN ns = 'paid' THEN COALESCE(paid_at, now()) ELSE NULL END
     WHERE id = i.id RETURNING * INTO i;
    RETURN i;
END$$;

CREATE OR REPLACE FUNCTION gl_invoice_line_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM gl_invoice_recalculate(COALESCE(NEW.invoice_id, OLD.invoice_id));
    RETURN NULL;
END$$;
CREATE TRIGGER invoice_lines_after AFTER INSERT OR UPDATE OR DELETE ON invoice_lines FOR EACH ROW EXECUTE FUNCTION gl_invoice_line_after();

-- An invoice's own head: a draft may change; an issued one keeps its lines, dates, customer and totals.
CREATE OR REPLACE FUNCTION gl_invoice_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'draft' THEN RAISE EXCEPTION 'An invoice is born a draft and issued afterwards'; END IF;
        NEW.number := NULL;
        RETURN NEW;
    END IF;
    IF OLD.status = 'void' OR OLD.status = 'written_off' THEN
        IF row(NEW.last_reminder_at, NEW.reminder_count, NEW.viewed_at) IS DISTINCT FROM row(OLD.last_reminder_at, OLD.reminder_count, OLD.viewed_at) THEN RETURN NEW; END IF;
        RAISE EXCEPTION 'Invoice % is % and cannot be changed', OLD.number, OLD.status;
    END IF;
    IF OLD.status <> 'draft' THEN
        IF row(NEW.customer_id, NEW.issue_date, NEW.due_date, NEW.currency, NEW.subtotal, NEW.tax_total, NEW.total, NEW.number)
           IS DISTINCT FROM row(OLD.customer_id, OLD.issue_date, OLD.due_date, OLD.currency, OLD.subtotal, OLD.tax_total, OLD.total, OLD.number) THEN
            RAISE EXCEPTION 'Invoice % is issued: its customer, dates, number and totals cannot change (credit it, or void it)', OLD.number;
        END IF;
        IF NEW.status = 'draft' THEN RAISE EXCEPTION 'Invoice % is issued and cannot return to draft', OLD.number; END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER invoices_guard BEFORE INSERT OR UPDATE ON invoices FOR EACH ROW EXECUTE FUNCTION gl_invoice_guard();

CREATE OR REPLACE FUNCTION gl_invoice_no_delete() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'draft' THEN RAISE EXCEPTION 'Invoice % is issued and is never deleted; void it', OLD.number; END IF;
    RETURN OLD;
END$$;
CREATE TRIGGER invoices_no_delete BEFORE DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION gl_invoice_no_delete();

-- Credit note lines and head: the same shape.
CREATE OR REPLACE FUNCTION gl_credit_note_line_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE st text; m record;
BEGIN
    SELECT status INTO st FROM credit_notes WHERE id = COALESCE(NEW.credit_note_id, OLD.credit_note_id);
    IF st IS DISTINCT FROM 'draft' THEN
        RAISE EXCEPTION 'The lines of a credit note change only while it is a draft (it is %)', st;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    IF NEW.tax_rate_id IS NOT NULL THEN SELECT rate INTO NEW.tax_rate FROM tax_rates WHERE id = NEW.tax_rate_id; ELSE NEW.tax_rate := 0; END IF;
    SELECT * INTO m FROM gl_line_money(NEW.quantity, NEW.unit_price, NEW.tax_rate);
    NEW.line_subtotal := m.line_subtotal; NEW.line_tax := m.line_tax; NEW.line_total := m.line_total;
    RETURN NEW;
END$$;
CREATE TRIGGER credit_note_lines_before BEFORE INSERT OR UPDATE OR DELETE ON credit_note_lines FOR EACH ROW EXECUTE FUNCTION gl_credit_note_line_before();

CREATE OR REPLACE FUNCTION gl_credit_note_recalculate(p_id bigint) RETURNS credit_notes
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c credit_notes%ROWTYPE; s numeric(14,2); t numeric(14,2); ap numeric(14,2);
BEGIN
    SELECT * INTO c FROM credit_notes WHERE id = p_id FOR UPDATE;
    IF c.id IS NULL THEN RAISE EXCEPTION 'No such credit note'; END IF;
    SELECT COALESCE(sum(line_subtotal), 0), COALESCE(sum(line_tax), 0) INTO s, t FROM credit_note_lines WHERE credit_note_id = c.id;
    SELECT COALESCE(sum(amount), 0) INTO ap FROM credit_applications WHERE credit_note_id = c.id;
    UPDATE credit_notes SET subtotal = CASE WHEN status = 'draft' THEN s ELSE subtotal END,
                            tax_total = CASE WHEN status = 'draft' THEN t ELSE tax_total END,
                            total = CASE WHEN status = 'draft' THEN s + t ELSE total END,
                            amount_applied = ap
     WHERE id = c.id RETURNING * INTO c;
    RETURN c;
END$$;

CREATE OR REPLACE FUNCTION gl_credit_note_line_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM gl_credit_note_recalculate(COALESCE(NEW.credit_note_id, OLD.credit_note_id));
    RETURN NULL;
END$$;
CREATE TRIGGER credit_note_lines_after AFTER INSERT OR UPDATE OR DELETE ON credit_note_lines FOR EACH ROW EXECUTE FUNCTION gl_credit_note_line_after();

CREATE OR REPLACE FUNCTION gl_credit_note_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'draft' THEN RAISE EXCEPTION 'A credit note is born a draft and issued afterwards'; END IF;
        NEW.number := NULL; RETURN NEW;
    END IF;
    IF OLD.status = 'void' THEN RAISE EXCEPTION 'Credit note % is void and cannot be changed', OLD.number; END IF;
    IF OLD.status = 'issued' THEN
        IF row(NEW.customer_id, NEW.issue_date, NEW.currency, NEW.subtotal, NEW.tax_total, NEW.total, NEW.number)
           IS DISTINCT FROM row(OLD.customer_id, OLD.issue_date, OLD.currency, OLD.subtotal, OLD.tax_total, OLD.total, OLD.number) THEN
            RAISE EXCEPTION 'Credit note % is issued and cannot change', OLD.number;
        END IF;
        IF NEW.status = 'draft' THEN RAISE EXCEPTION 'Credit note % is issued and cannot return to draft', OLD.number; END IF;
        IF NEW.status = 'void' AND OLD.amount_applied > 0 THEN RAISE EXCEPTION 'Credit note % is applied to an invoice; unapply it first', OLD.number; END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER credit_notes_guard BEFORE INSERT OR UPDATE ON credit_notes FOR EACH ROW EXECUTE FUNCTION gl_credit_note_guard();

-- ---------------------------------------------------------------------------------------------
-- Applications: a receipt's applications never exceed it; an invoice's never exceed what it is owed.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION gl_receipt_application_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE r receipts%ROWTYPE; i invoices%ROWTYPE; applied numeric(14,2); owed numeric(14,2);
BEGIN
    SELECT * INTO r FROM receipts WHERE id = NEW.receipt_id;
    SELECT * INTO i FROM invoices WHERE id = NEW.invoice_id;
    IF r.status <> 'recorded' THEN RAISE EXCEPTION 'Receipt % is void', r.number; END IF;
    IF i.status NOT IN ('issued', 'sent', 'viewed', 'partially_paid') THEN RAISE EXCEPTION 'Invoice % is % and takes no payment', COALESCE(i.number, i.id::text), i.status; END IF;
    IF r.customer_id <> i.customer_id THEN RAISE EXCEPTION 'Receipt % is from another customer than invoice %', r.number, i.number; END IF;
    IF r.currency <> i.currency THEN RAISE EXCEPTION 'Receipt % (%) and invoice % (%) are in different currencies', r.number, r.currency, i.number, i.currency; END IF;
    SELECT COALESCE(sum(amount), 0) INTO applied FROM receipt_applications WHERE receipt_id = NEW.receipt_id AND invoice_id <> NEW.invoice_id;
    IF applied + NEW.amount > r.amount THEN RAISE EXCEPTION 'Receipt % is %; % is already applied and % more would exceed it', r.number, r.amount, applied, NEW.amount; END IF;
    SELECT COALESCE(sum(amount), 0) INTO applied FROM receipt_applications WHERE invoice_id = NEW.invoice_id AND receipt_id <> NEW.receipt_id;
    owed := i.total - i.amount_credited - applied;
    IF NEW.amount > owed THEN RAISE EXCEPTION 'Invoice % is owed %; applying % would overpay it', i.number, owed, NEW.amount; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER receipt_applications_guard BEFORE INSERT OR UPDATE ON receipt_applications FOR EACH ROW EXECUTE FUNCTION gl_receipt_application_guard();

CREATE OR REPLACE FUNCTION gl_receipt_application_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM gl_invoice_recalculate(COALESCE(NEW.invoice_id, OLD.invoice_id));
    IF TG_OP = 'UPDATE' AND NEW.invoice_id <> OLD.invoice_id THEN PERFORM gl_invoice_recalculate(OLD.invoice_id); END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER receipt_applications_after AFTER INSERT OR UPDATE OR DELETE ON receipt_applications FOR EACH ROW EXECUTE FUNCTION gl_receipt_application_after();

CREATE OR REPLACE FUNCTION gl_credit_application_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE c credit_notes%ROWTYPE; i invoices%ROWTYPE; applied numeric(14,2); owed numeric(14,2);
BEGIN
    SELECT * INTO c FROM credit_notes WHERE id = NEW.credit_note_id;
    SELECT * INTO i FROM invoices WHERE id = NEW.invoice_id;
    IF c.status <> 'issued' THEN RAISE EXCEPTION 'Credit note % is %, not issued', COALESCE(c.number, c.id::text), c.status; END IF;
    IF i.status NOT IN ('issued', 'sent', 'viewed', 'partially_paid') THEN RAISE EXCEPTION 'Invoice % is % and takes no credit', COALESCE(i.number, i.id::text), i.status; END IF;
    IF c.customer_id <> i.customer_id THEN RAISE EXCEPTION 'Credit note % belongs to another customer than invoice %', c.number, i.number; END IF;
    IF c.currency <> i.currency THEN RAISE EXCEPTION 'Credit note % and invoice % are in different currencies', c.number, i.number; END IF;
    SELECT COALESCE(sum(amount), 0) INTO applied FROM credit_applications WHERE credit_note_id = NEW.credit_note_id AND invoice_id <> NEW.invoice_id;
    IF applied + NEW.amount > c.total THEN RAISE EXCEPTION 'Credit note % is %; applying % more would exceed it', c.number, c.total, NEW.amount; END IF;
    SELECT COALESCE(sum(amount), 0) INTO applied FROM credit_applications WHERE invoice_id = NEW.invoice_id AND credit_note_id <> NEW.credit_note_id;
    owed := i.total - i.amount_paid - applied;
    IF NEW.amount > owed THEN RAISE EXCEPTION 'Invoice % is owed %; crediting % would exceed it', i.number, owed, NEW.amount; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER credit_applications_guard BEFORE INSERT OR UPDATE ON credit_applications FOR EACH ROW EXECUTE FUNCTION gl_credit_application_guard();

CREATE OR REPLACE FUNCTION gl_credit_application_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM gl_invoice_recalculate(COALESCE(NEW.invoice_id, OLD.invoice_id));
    PERFORM gl_credit_note_recalculate(COALESCE(NEW.credit_note_id, OLD.credit_note_id));
    RETURN NULL;
END$$;
CREATE TRIGGER credit_applications_after AFTER INSERT OR UPDATE OR DELETE ON credit_applications FOR EACH ROW EXECUTE FUNCTION gl_credit_application_after();

-- ---------------------------------------------------------------------------------------------
-- Receipts and deposits: numbered at creation; a receipt in a deposit was undeposited; a deposit's total is its receipts'.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION gl_receipt_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE dstat text;
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.number := COALESCE(NEW.number, next_document_number('receipt'));
        IF NEW.status <> 'recorded' THEN RAISE EXCEPTION 'A receipt is recorded when it is made'; END IF;
        IF NEW.deposit_id IS NOT NULL THEN RAISE EXCEPTION 'A receipt joins a deposit afterwards'; END IF;
        RETURN NEW;
    END IF;
    IF OLD.status = 'void' THEN RAISE EXCEPTION 'Receipt % is void and cannot be changed', OLD.number; END IF;
    IF NEW.status = 'void' THEN
        IF OLD.deposit_id IS NOT NULL THEN RAISE EXCEPTION 'Receipt % is in deposit; take it out of the deposit first', OLD.number; END IF;
        IF EXISTS (SELECT 1 FROM receipt_applications WHERE receipt_id = OLD.id) THEN RAISE EXCEPTION 'Receipt % is applied to an invoice; unapply it first', OLD.number; END IF;
        NEW.voided_at := COALESCE(NEW.voided_at, now());
        RETURN NEW;
    END IF;
    IF row(NEW.customer_id, NEW.received_on, NEW.amount, NEW.currency, NEW.number) IS DISTINCT FROM row(OLD.customer_id, OLD.received_on, OLD.amount, OLD.currency, OLD.number) THEN
        IF EXISTS (SELECT 1 FROM receipt_applications WHERE receipt_id = OLD.id) OR OLD.journal_entry_id IS NOT NULL OR OLD.deposit_id IS NOT NULL THEN
            RAISE EXCEPTION 'Receipt % is applied, posted or deposited: void it and record it again', OLD.number;
        END IF;
    END IF;
    IF NEW.deposit_id IS NOT NULL AND NEW.deposit_id IS DISTINCT FROM OLD.deposit_id THEN
        IF NEW.deposit_to_bank_account_id IS NOT NULL THEN RAISE EXCEPTION 'Receipt % went straight to a bank account; only undeposited receipts join a deposit', OLD.number; END IF;
        SELECT status INTO dstat FROM deposits WHERE id = NEW.deposit_id;
        IF dstat <> 'draft' THEN RAISE EXCEPTION 'The deposit is %; receipts join a draft deposit', dstat; END IF;
    END IF;
    IF OLD.deposit_id IS NOT NULL AND NEW.deposit_id IS DISTINCT FROM OLD.deposit_id THEN
        SELECT status INTO dstat FROM deposits WHERE id = OLD.deposit_id;
        IF dstat <> 'draft' THEN RAISE EXCEPTION 'The deposit is %; a receipt leaves a draft deposit only', dstat; END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER receipts_guard BEFORE INSERT OR UPDATE ON receipts FOR EACH ROW EXECUTE FUNCTION gl_receipt_guard();

CREATE OR REPLACE FUNCTION gl_receipt_no_delete() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Receipt % is never deleted; void it', OLD.number;
END$$;
CREATE TRIGGER receipts_no_delete BEFORE DELETE ON receipts FOR EACH ROW EXECUTE FUNCTION gl_receipt_no_delete();

CREATE OR REPLACE FUNCTION gl_deposit_total() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    UPDATE deposits d SET total = (SELECT COALESCE(sum(amount), 0) FROM receipts WHERE deposit_id = d.id AND status = 'recorded')
     WHERE d.id IN (NEW.deposit_id, OLD.deposit_id) AND d.status = 'draft';
    RETURN NULL;
END$$;
CREATE TRIGGER receipts_deposit_total AFTER INSERT OR UPDATE OF deposit_id, status, amount ON receipts FOR EACH ROW EXECUTE FUNCTION gl_deposit_total();

CREATE OR REPLACE FUNCTION gl_deposit_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.number := COALESCE(NEW.number, next_document_number('deposit'));
        IF NEW.status <> 'draft' THEN RAISE EXCEPTION 'A deposit is born a draft and recorded afterwards'; END IF;
        RETURN NEW;
    END IF;
    IF OLD.status = 'void' THEN RAISE EXCEPTION 'Deposit % is void and cannot be changed', OLD.number; END IF;
    IF OLD.status = 'recorded' THEN
        IF NEW.status = 'draft' THEN RAISE EXCEPTION 'Deposit % is recorded and cannot return to draft', OLD.number; END IF;
        IF NEW.status = 'recorded' AND row(NEW.bank_account_id, NEW.deposit_date, NEW.total, NEW.currency) IS DISTINCT FROM row(OLD.bank_account_id, OLD.deposit_date, OLD.total, OLD.currency) THEN
            RAISE EXCEPTION 'Deposit % is recorded and cannot change; void it', OLD.number;
        END IF;
    END IF;
    IF NEW.status = 'recorded' AND OLD.status = 'draft' AND NEW.total <= 0 THEN RAISE EXCEPTION 'Deposit % has no receipts', OLD.number; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER deposits_guard BEFORE INSERT OR UPDATE ON deposits FOR EACH ROW EXECUTE FUNCTION gl_deposit_guard();

-- ---------------------------------------------------------------------------------------------
-- The secure link.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION gl_secure_link_mint(p_invoice_id bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE raw text;
BEGIN
    raw := encode(gen_random_bytes(24), 'hex');
    UPDATE invoice_links_secure SET rotated_at = now() WHERE invoice_id = p_invoice_id AND rotated_at IS NULL;
    INSERT INTO invoice_links_secure (invoice_id, token_hash) VALUES (p_invoice_id, encode(sha256(raw::bytea), 'hex'));
    RETURN raw;
END$$;

-- The invoice a presented token opens: live, not rotated, not expired; counts the view and stamps the invoice as
-- viewed (issued/sent → viewed). NULL otherwise — the same nothing for every reason.
CREATE OR REPLACE FUNCTION gl_secure_link_invoice(p_raw text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE iid bigint;
BEGIN
    IF p_raw !~ '^[a-f0-9]{48}$' THEN RETURN NULL; END IF;
    UPDATE invoice_links_secure SET last_used_at = now(), view_count = view_count + 1
     WHERE token_hash = encode(sha256(p_raw::bytea), 'hex') AND rotated_at IS NULL AND (expires_at IS NULL OR expires_at > now())
    RETURNING invoice_id INTO iid;
    IF iid IS NOT NULL THEN
        UPDATE invoices SET viewed_at = COALESCE(viewed_at, now()), status = CASE WHEN status IN ('issued', 'sent') THEN 'viewed' ELSE status END WHERE id = iid;
    END IF;
    RETURN iid;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The writer's verbs: issue (number + post), void, write off; a receipt's and a deposit's posting.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION gl_invoice_issue(p_invoice_id bigint, p_by bigint) RETURNS invoices
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE i invoices%ROWTYPE; rule posting_rules%ROWTYPE; ar bigint; e journal_entries%ROWTYPE; n integer := 1; rec record;
BEGIN
    SELECT * INTO i FROM invoices WHERE id = p_invoice_id FOR UPDATE;
    IF i.id IS NULL THEN RAISE EXCEPTION 'No such invoice'; END IF;
    IF i.status <> 'draft' THEN RAISE EXCEPTION 'Invoice % is already issued', i.number; END IF;
    IF NOT EXISTS (SELECT 1 FROM invoice_lines WHERE invoice_id = i.id) THEN RAISE EXCEPTION 'An invoice needs at least one line'; END IF;
    IF i.total <= 0 THEN RAISE EXCEPTION 'An invoice for nothing is not issued'; END IF;
    rule := gl_posting_rule('invoice');
    IF rule.id IS NULL THEN RAISE EXCEPTION 'No posting rule for invoices'; END IF;
    SELECT ar_account_id INTO ar FROM gl_settings WHERE id = 1;
    INSERT INTO journal_entries (entry_date, memo, source, source_type, source_id, currency, department_id, site_id, created_by)
    VALUES (i.issue_date, 'Invoice to ' || (SELECT name FROM customers WHERE id = i.customer_id), 'invoice', 'invoice', i.id, i.currency, i.department_id, i.site_id, p_by)
    RETURNING * INTO e;
    -- the number first (gapless): rolled back with everything else if the posting fails
    UPDATE invoices SET number = next_document_number('invoice') WHERE id = i.id;
    INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, department_id, site_id, customer_id)
    VALUES (e.id, n, COALESCE(rule.debit_account_id, ar), 'Receivable', i.total, 0, i.department_id, i.site_id, i.customer_id);
    FOR rec IN SELECT income_account_id, department_id, site_id, sum(line_subtotal) AS s FROM invoice_lines WHERE invoice_id = i.id
                GROUP BY income_account_id, department_id, site_id HAVING sum(line_subtotal) <> 0 LOOP
        n := n + 1;
        INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, department_id, site_id)
        VALUES (e.id, n, rec.income_account_id, 'Revenue', CASE WHEN rec.s < 0 THEN -rec.s ELSE 0 END, CASE WHEN rec.s > 0 THEN rec.s ELSE 0 END,
                COALESCE(rec.department_id, i.department_id), COALESCE(rec.site_id, i.site_id));
    END LOOP;
    IF i.tax_total <> 0 THEN
        IF rule.tax_account_id IS NULL THEN RAISE EXCEPTION 'The invoice carries tax but the posting rule names no tax account'; END IF;
        n := n + 1;
        INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, department_id, site_id)
        VALUES (e.id, n, rule.tax_account_id, 'Sales tax', 0, i.tax_total, i.department_id, i.site_id);
    END IF;
    PERFORM gl_entry_post(e.id, p_by);
    UPDATE invoices SET status = 'issued', issued_at = now(), issued_by = p_by, journal_entry_id = e.id WHERE id = i.id RETURNING * INTO i;
    RETURN i;
END$$;

CREATE OR REPLACE FUNCTION gl_invoice_void(p_invoice_id bigint, p_by bigint, p_reason text) RETURNS invoices
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE i invoices%ROWTYPE;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'Voiding an invoice needs a reason'; END IF;
    SELECT * INTO i FROM invoices WHERE id = p_invoice_id FOR UPDATE;
    IF i.id IS NULL THEN RAISE EXCEPTION 'No such invoice'; END IF;
    IF i.status IN ('void', 'written_off') THEN RAISE EXCEPTION 'Invoice % is already %', i.number, i.status; END IF;
    IF i.amount_paid > 0 OR i.amount_credited > 0 THEN RAISE EXCEPTION 'Invoice % has payments or credits applied; unapply them first', i.number; END IF;
    IF i.journal_entry_id IS NOT NULL THEN
        PERFORM gl_entry_reverse(i.journal_entry_id, p_by, 'Invoice ' || i.number || ' voided: ' || p_reason);
    END IF;
    UPDATE invoices SET status = 'void', voided_at = now(), voided_by = p_by, void_reason = p_reason WHERE id = i.id RETURNING * INTO i;
    RETURN i;
END$$;

CREATE OR REPLACE FUNCTION gl_invoice_write_off(p_invoice_id bigint, p_by bigint, p_reason text, p_date date DEFAULT current_date) RETURNS invoices
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE i invoices%ROWTYPE; rule posting_rules%ROWTYPE; e journal_entries%ROWTYPE; ar bigint;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'A write-off needs a reason'; END IF;
    SELECT * INTO i FROM invoices WHERE id = p_invoice_id FOR UPDATE;
    IF i.id IS NULL THEN RAISE EXCEPTION 'No such invoice'; END IF;
    IF i.status NOT IN ('issued', 'sent', 'viewed', 'partially_paid') THEN RAISE EXCEPTION 'Invoice % is % and is not written off', i.number, i.status; END IF;
    IF i.balance_due <= 0 THEN RAISE EXCEPTION 'Invoice % is owed nothing', i.number; END IF;
    rule := gl_posting_rule('write_off');
    IF rule.id IS NULL THEN RAISE EXCEPTION 'No posting rule for write-offs'; END IF;
    SELECT ar_account_id INTO ar FROM gl_settings WHERE id = 1;
    INSERT INTO journal_entries (entry_date, memo, source, source_type, source_id, currency, department_id, site_id, created_by)
    VALUES (p_date, 'Write-off of ' || i.number || ': ' || p_reason, 'write_off', 'invoice_write_off', i.id, i.currency, i.department_id, i.site_id, p_by)
    RETURNING * INTO e;
    INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, department_id, site_id, customer_id) VALUES
        (e.id, 1, rule.debit_account_id, 'Bad debt', i.balance_due, 0, i.department_id, i.site_id, NULL),
        (e.id, 2, COALESCE(rule.credit_account_id, ar), 'Receivable written off', 0, i.balance_due, i.department_id, i.site_id, i.customer_id);
    PERFORM gl_entry_post(e.id, p_by);
    UPDATE invoices SET status = 'written_off', written_off_at = now(), written_off_by = p_by, write_off_reason = p_reason, write_off_entry_id = e.id
     WHERE id = i.id RETURNING * INTO i;
    RETURN i;
END$$;

CREATE OR REPLACE FUNCTION gl_credit_note_issue(p_id bigint, p_by bigint) RETURNS credit_notes
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c credit_notes%ROWTYPE; rule posting_rules%ROWTYPE; ar bigint; e journal_entries%ROWTYPE; n integer := 1; rec record;
BEGIN
    SELECT * INTO c FROM credit_notes WHERE id = p_id FOR UPDATE;
    IF c.id IS NULL THEN RAISE EXCEPTION 'No such credit note'; END IF;
    IF c.status <> 'draft' THEN RAISE EXCEPTION 'Credit note % is already issued', c.number; END IF;
    IF c.total <= 0 THEN RAISE EXCEPTION 'A credit note for nothing is not issued'; END IF;
    rule := gl_posting_rule('credit_note');
    IF rule.id IS NULL THEN RAISE EXCEPTION 'No posting rule for credit notes'; END IF;
    SELECT ar_account_id INTO ar FROM gl_settings WHERE id = 1;
    INSERT INTO journal_entries (entry_date, memo, source, source_type, source_id, currency, department_id, site_id, created_by)
    VALUES (c.issue_date, 'Credit note to ' || (SELECT name FROM customers WHERE id = c.customer_id), 'credit_note', 'credit_note', c.id, c.currency, c.department_id, c.site_id, p_by)
    RETURNING * INTO e;
    UPDATE credit_notes SET number = next_document_number('credit_note') WHERE id = c.id;
    FOR rec IN SELECT income_account_id, department_id, site_id, sum(line_subtotal) AS s FROM credit_note_lines WHERE credit_note_id = c.id
                GROUP BY income_account_id, department_id, site_id HAVING sum(line_subtotal) <> 0 LOOP
        INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, department_id, site_id)
        VALUES (e.id, n, rec.income_account_id, 'Revenue credited', CASE WHEN rec.s > 0 THEN rec.s ELSE 0 END, CASE WHEN rec.s < 0 THEN -rec.s ELSE 0 END,
                COALESCE(rec.department_id, c.department_id), COALESCE(rec.site_id, c.site_id));
        n := n + 1;
    END LOOP;
    IF c.tax_total <> 0 THEN
        IF rule.tax_account_id IS NULL THEN RAISE EXCEPTION 'The credit note carries tax but the posting rule names no tax account'; END IF;
        INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, department_id, site_id)
        VALUES (e.id, n, rule.tax_account_id, 'Sales tax credited', c.tax_total, 0, c.department_id, c.site_id);
        n := n + 1;
    END IF;
    INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, department_id, site_id, customer_id)
    VALUES (e.id, n, COALESCE(rule.credit_account_id, ar), 'Receivable credited', 0, c.total, c.department_id, c.site_id, c.customer_id);
    PERFORM gl_entry_post(e.id, p_by);
    UPDATE credit_notes SET status = 'issued', issued_at = now(), issued_by = p_by, journal_entry_id = e.id WHERE id = c.id RETURNING * INTO c;
    RETURN c;
END$$;

-- A receipt posts when recorded: DR the bank it names (or undeposited funds) / CR receivable (the customer named).
-- The bank account's ledger account is read in db/013 (the FK and the function come there); until then the
-- deposit-to must be NULL — db/013 replaces this function with the bank-aware one.
CREATE OR REPLACE FUNCTION gl_receipt_post(p_receipt_id bigint, p_by bigint) RETURNS receipts
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r receipts%ROWTYPE; rule posting_rules%ROWTYPE; ar bigint; e journal_entries%ROWTYPE;
BEGIN
    SELECT * INTO r FROM receipts WHERE id = p_receipt_id FOR UPDATE;
    IF r.id IS NULL THEN RAISE EXCEPTION 'No such receipt'; END IF;
    IF r.journal_entry_id IS NOT NULL THEN RETURN r; END IF;
    IF r.status <> 'recorded' THEN RAISE EXCEPTION 'Receipt % is void', r.number; END IF;
    IF r.deposit_to_bank_account_id IS NOT NULL THEN RAISE EXCEPTION 'Bank accounts arrive in db/013'; END IF;
    rule := gl_posting_rule('receipt');
    IF rule.id IS NULL THEN RAISE EXCEPTION 'No posting rule for receipts'; END IF;
    SELECT ar_account_id INTO ar FROM gl_settings WHERE id = 1;
    INSERT INTO journal_entries (entry_date, memo, source, source_type, source_id, currency, created_by)
    VALUES (r.received_on, 'Receipt ' || r.number || ' from ' || (SELECT name FROM customers WHERE id = r.customer_id), 'receipt', 'receipt', r.id, r.currency, p_by)
    RETURNING * INTO e;
    INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, customer_id) VALUES
        (e.id, 1, rule.debit_account_id, 'Undeposited funds', r.amount, 0, NULL),
        (e.id, 2, COALESCE(rule.credit_account_id, ar), 'Receivable', 0, r.amount, r.customer_id);
    PERFORM gl_entry_post(e.id, p_by);
    UPDATE receipts SET journal_entry_id = e.id WHERE id = r.id RETURNING * INTO r;
    RETURN r;
END$$;

CREATE OR REPLACE FUNCTION gl_receipt_void(p_receipt_id bigint, p_by bigint, p_reason text) RETURNS receipts
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r receipts%ROWTYPE;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'Voiding a receipt needs a reason'; END IF;
    SELECT * INTO r FROM receipts WHERE id = p_receipt_id FOR UPDATE;
    IF r.id IS NULL THEN RAISE EXCEPTION 'No such receipt'; END IF;
    UPDATE receipts SET status = 'void', voided_by = p_by, void_reason = p_reason WHERE id = r.id RETURNING * INTO r;   -- the guard refuses when applied or deposited
    IF r.journal_entry_id IS NOT NULL THEN
        PERFORM gl_entry_reverse(r.journal_entry_id, p_by, 'Receipt ' || r.number || ' voided: ' || p_reason);
    END IF;
    RETURN r;
END$$;

REVOKE ALL ON FUNCTION gl_invoice_recalculate(bigint), gl_credit_note_recalculate(bigint), gl_secure_link_mint(bigint), gl_secure_link_invoice(text),
    gl_invoice_issue(bigint, bigint), gl_invoice_void(bigint, bigint, text), gl_invoice_write_off(bigint, bigint, text, date),
    gl_credit_note_issue(bigint, bigint), gl_receipt_post(bigint, bigint), gl_receipt_void(bigint, bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION gl_invoice_recalculate(bigint), gl_credit_note_recalculate(bigint), gl_secure_link_mint(bigint), gl_secure_link_invoice(text),
    gl_invoice_issue(bigint, bigint), gl_invoice_void(bigint, bigint, text), gl_invoice_write_off(bigint, bigint, text, date),
    gl_credit_note_issue(bigint, bigint), gl_receipt_post(bigint, bigint), gl_receipt_void(bigint, bigint, text) TO gl_rw;
GRANT SELECT, INSERT, UPDATE, DELETE ON invoices, invoice_lines, credit_notes, credit_note_lines, credit_applications, deposits, receipts,
    receipt_applications, receipt_claims, invoice_links_secure TO gl_rw;

COMMIT;
