-- 011: ACCOUNTS PAYABLE (design §6, D7, D10, D11): bills numbered at creation with the vendor's own reference as the
-- double-entry guard, vendor credits, payments (money OUT — a RECORD of money a person moved; the ledger moves none)
-- and their applications, payment runs.
--
-- A bill posts on APPROVAL (a person's act, ap.approve; an agent's pauses): DR each line's expense account for the
-- line's gross total (D11: purchase tax posts gross) / CR payable (the vendor named). A payment posts when RECORDED:
-- DR payable (the vendor) / CR the bank account it names (db/013 supplies the bank's ledger account; until then this
-- file's gl_payment_record refuses, and db/013 replaces it).
BEGIN;

CREATE TABLE bills (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number              text NOT NULL UNIQUE,                                    -- BILL-, at creation
    vendor_id           bigint NOT NULL REFERENCES vendors(id) ON DELETE RESTRICT,
    vendor_ref          text,                                                    -- the vendor's own invoice number
    bill_date           date NOT NULL DEFAULT current_date,
    due_date            date NOT NULL,
    currency            char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    status              text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'approved', 'partially_paid', 'paid', 'void')),
    subtotal            numeric(14,2) NOT NULL DEFAULT 0,
    tax_total           numeric(14,2) NOT NULL DEFAULT 0,
    total               numeric(14,2) NOT NULL DEFAULT 0,
    amount_paid         numeric(14,2) NOT NULL DEFAULT 0 CHECK (amount_paid >= 0),
    amount_credited     numeric(14,2) NOT NULL DEFAULT 0 CHECK (amount_credited >= 0),
    balance_due         numeric(14,2) GENERATED ALWAYS AS (total - amount_paid - amount_credited) STORED,
    memo                text,
    source              text NOT NULL DEFAULT 'manual' CHECK (source IN ('manual', 'ai_usage', 'inbox', 'recurring', 'import')),
    department_id       bigint REFERENCES departments(id) ON DELETE SET NULL,
    site_id             bigint REFERENCES sites(id) ON DELETE SET NULL,
    journal_entry_id    bigint REFERENCES journal_entries(id) ON DELETE SET NULL,
    approved_at         timestamptz,
    approved_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    paid_at             timestamptz,
    voided_at           timestamptz,
    voided_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    void_reason         text,
    created_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (due_date >= bill_date)
);
CREATE UNIQUE INDEX bills_vendor_ref_live ON bills (vendor_id, lower(vendor_ref)) WHERE vendor_ref IS NOT NULL AND status <> 'void';
CREATE INDEX bills_vendor_idx ON bills (vendor_id, bill_date DESC);
CREATE INDEX bills_status_due_idx ON bills (status, due_date);
CREATE INDEX bills_open_idx ON bills (due_date) WHERE status IN ('approved', 'partially_paid');
CREATE INDEX bills_dept_idx ON bills (department_id);
CREATE INDEX bills_site_idx ON bills (site_id);
CREATE INDEX bills_entry_idx ON bills (journal_entry_id);
CREATE INDEX bills_approved_by_idx ON bills (approved_by);
CREATE INDEX bills_voided_by_idx ON bills (voided_by);
CREATE INDEX bills_created_by_idx ON bills (created_by);
CREATE TRIGGER bills_touch BEFORE UPDATE ON bills FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE bill_lines (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    bill_id             bigint NOT NULL REFERENCES bills(id) ON DELETE CASCADE,
    sort_order          integer NOT NULL DEFAULT 0,
    item_id             bigint REFERENCES items(id) ON DELETE SET NULL,
    description         text NOT NULL,
    quantity            numeric(12,3) NOT NULL DEFAULT 1 CHECK (quantity > 0),
    unit_price          numeric(14,4) NOT NULL CHECK (unit_price >= 0),         -- four decimals: the AI statement's amounts
    tax_rate_id         bigint REFERENCES tax_rates(id) ON DELETE SET NULL,
    tax_rate            numeric(7,4) NOT NULL DEFAULT 0,
    line_subtotal       numeric(14,2) NOT NULL DEFAULT 0,
    line_tax            numeric(14,2) NOT NULL DEFAULT 0,
    line_total          numeric(14,2) NOT NULL DEFAULT 0,
    expense_account_id  bigint NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
    department_id       bigint REFERENCES departments(id) ON DELETE SET NULL,
    site_id             bigint REFERENCES sites(id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX bill_lines_bill_idx ON bill_lines (bill_id, sort_order);
CREATE INDEX bill_lines_item_idx ON bill_lines (item_id);
CREATE INDEX bill_lines_tax_idx ON bill_lines (tax_rate_id);
CREATE INDEX bill_lines_account_idx ON bill_lines (expense_account_id);
CREATE INDEX bill_lines_dept_idx ON bill_lines (department_id);
CREATE INDEX bill_lines_site_idx ON bill_lines (site_id);
CREATE TRIGGER bill_lines_touch BEFORE UPDATE ON bill_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE vendor_credits (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number              text NOT NULL UNIQUE,                                    -- VC-, at creation
    vendor_id           bigint NOT NULL REFERENCES vendors(id) ON DELETE RESTRICT,
    bill_id             bigint REFERENCES bills(id) ON DELETE SET NULL,
    vendor_ref          text,
    credit_date         date NOT NULL DEFAULT current_date,
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
    updated_at          timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX vendor_credits_vendor_idx ON vendor_credits (vendor_id, credit_date DESC);
CREATE INDEX vendor_credits_bill_idx ON vendor_credits (bill_id);
CREATE INDEX vendor_credits_dept_idx ON vendor_credits (department_id);
CREATE INDEX vendor_credits_site_idx ON vendor_credits (site_id);
CREATE INDEX vendor_credits_entry_idx ON vendor_credits (journal_entry_id);
CREATE INDEX vendor_credits_issued_by_idx ON vendor_credits (issued_by);
CREATE INDEX vendor_credits_voided_by_idx ON vendor_credits (voided_by);
CREATE INDEX vendor_credits_created_by_idx ON vendor_credits (created_by);
CREATE TRIGGER vendor_credits_touch BEFORE UPDATE ON vendor_credits FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE vendor_credit_lines (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    vendor_credit_id    bigint NOT NULL REFERENCES vendor_credits(id) ON DELETE CASCADE,
    sort_order          integer NOT NULL DEFAULT 0,
    description         text NOT NULL,
    quantity            numeric(12,3) NOT NULL DEFAULT 1 CHECK (quantity > 0),
    unit_price          numeric(14,4) NOT NULL CHECK (unit_price >= 0),
    tax_rate_id         bigint REFERENCES tax_rates(id) ON DELETE SET NULL,
    tax_rate            numeric(7,4) NOT NULL DEFAULT 0,
    line_subtotal       numeric(14,2) NOT NULL DEFAULT 0,
    line_tax            numeric(14,2) NOT NULL DEFAULT 0,
    line_total          numeric(14,2) NOT NULL DEFAULT 0,
    expense_account_id  bigint NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
    department_id       bigint REFERENCES departments(id) ON DELETE SET NULL,
    site_id             bigint REFERENCES sites(id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX vendor_credit_lines_credit_idx ON vendor_credit_lines (vendor_credit_id, sort_order);
CREATE INDEX vendor_credit_lines_tax_idx ON vendor_credit_lines (tax_rate_id);
CREATE INDEX vendor_credit_lines_account_idx ON vendor_credit_lines (expense_account_id);
CREATE INDEX vendor_credit_lines_dept_idx ON vendor_credit_lines (department_id);
CREATE INDEX vendor_credit_lines_site_idx ON vendor_credit_lines (site_id);
CREATE TRIGGER vendor_credit_lines_touch BEFORE UPDATE ON vendor_credit_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE vendor_credit_applications (
    vendor_credit_id bigint NOT NULL REFERENCES vendor_credits(id) ON DELETE RESTRICT,
    bill_id          bigint NOT NULL REFERENCES bills(id) ON DELETE RESTRICT,
    amount           numeric(14,2) NOT NULL CHECK (amount > 0),
    applied_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    applied_at       timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (vendor_credit_id, bill_id)
);
CREATE INDEX vendor_credit_applications_bill_idx ON vendor_credit_applications (bill_id);
CREATE INDEX vendor_credit_applications_by_idx ON vendor_credit_applications (applied_by);

CREATE TABLE payment_runs (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number           text NOT NULL UNIQUE,                                       -- RUN-, at creation
    pay_date         date NOT NULL DEFAULT current_date,
    bank_account_id  bigint NOT NULL,                                            -- FK in db/013
    currency         char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    status           text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'approved', 'recorded', 'void')),
    total            numeric(14,2) NOT NULL DEFAULT 0 CHECK (total >= 0),       -- the sum of its payments (trigger)
    memo             text,
    approved_at      timestamptz,
    approved_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    recorded_at      timestamptz,
    recorded_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    voided_at        timestamptz,
    voided_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    created_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX payment_runs_bank_idx ON payment_runs (bank_account_id, pay_date DESC);
CREATE INDEX payment_runs_approved_by_idx ON payment_runs (approved_by);
CREATE INDEX payment_runs_recorded_by_idx ON payment_runs (recorded_by);
CREATE INDEX payment_runs_voided_by_idx ON payment_runs (voided_by);
CREATE INDEX payment_runs_created_by_idx ON payment_runs (created_by);
CREATE TRIGGER payment_runs_touch BEFORE UPDATE ON payment_runs FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE payments (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number              text NOT NULL UNIQUE,                                    -- PAY-, at creation
    vendor_id           bigint NOT NULL REFERENCES vendors(id) ON DELETE RESTRICT,
    payment_date        date NOT NULL DEFAULT current_date,
    amount              numeric(14,2) NOT NULL CHECK (amount > 0),
    currency            char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    method              text NOT NULL CHECK (method IN ('cheque', 'ach', 'card', 'cash', 'other')),
    cheque_number       text,
    bank_account_id     bigint NOT NULL,                                         -- FK in db/013
    reference           text,
    memo                text,
    status              text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'approved', 'recorded', 'void')),
    payment_run_id      bigint REFERENCES payment_runs(id) ON DELETE SET NULL,
    journal_entry_id    bigint REFERENCES journal_entries(id) ON DELETE SET NULL,
    approved_at         timestamptz,
    approved_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    recorded_at         timestamptz,
    recorded_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    voided_at           timestamptz,
    voided_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    void_reason         text,
    created_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX payments_vendor_idx ON payments (vendor_id, payment_date DESC);
CREATE INDEX payments_date_idx ON payments (payment_date);
CREATE INDEX payments_status_idx ON payments (status) WHERE status IN ('draft', 'approved');
CREATE INDEX payments_bank_idx ON payments (bank_account_id);
CREATE INDEX payments_run_idx ON payments (payment_run_id);
CREATE INDEX payments_entry_idx ON payments (journal_entry_id);
CREATE INDEX payments_approved_by_idx ON payments (approved_by);
CREATE INDEX payments_recorded_by_idx ON payments (recorded_by);
CREATE INDEX payments_voided_by_idx ON payments (voided_by);
CREATE INDEX payments_created_by_idx ON payments (created_by);
CREATE TRIGGER payments_touch BEFORE UPDATE ON payments FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE payment_applications (
    payment_id  bigint NOT NULL REFERENCES payments(id) ON DELETE RESTRICT,
    bill_id     bigint NOT NULL REFERENCES bills(id) ON DELETE RESTRICT,
    amount      numeric(14,2) NOT NULL CHECK (amount > 0),
    applied_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    applied_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (payment_id, bill_id)
);
CREATE INDEX payment_applications_bill_idx ON payment_applications (bill_id);
CREATE INDEX payment_applications_by_idx ON payment_applications (applied_by);

-- ---------------------------------------------------------------------------------------------
-- Bill lines and the bill's one recalculation.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION gl_bill_line_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE st text; m record;
BEGIN
    SELECT status INTO st FROM bills WHERE id = COALESCE(NEW.bill_id, OLD.bill_id);
    IF st IS DISTINCT FROM 'draft' THEN
        RAISE EXCEPTION 'The lines of a bill change only while it is a draft (it is %)', st;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    IF NEW.tax_rate_id IS NOT NULL THEN SELECT rate INTO NEW.tax_rate FROM tax_rates WHERE id = NEW.tax_rate_id; ELSE NEW.tax_rate := 0; END IF;
    SELECT * INTO m FROM gl_line_money(NEW.quantity, NEW.unit_price, NEW.tax_rate);
    NEW.line_subtotal := m.line_subtotal; NEW.line_tax := m.line_tax; NEW.line_total := m.line_total;
    RETURN NEW;
END$$;
CREATE TRIGGER bill_lines_before BEFORE INSERT OR UPDATE OR DELETE ON bill_lines FOR EACH ROW EXECUTE FUNCTION gl_bill_line_before();

CREATE OR REPLACE FUNCTION gl_bill_recalculate(p_bill_id bigint) RETURNS bills
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b bills%ROWTYPE; s numeric(14,2); t numeric(14,2); paid numeric(14,2); cred numeric(14,2); ns text;
BEGIN
    SELECT * INTO b FROM bills WHERE id = p_bill_id FOR UPDATE;
    IF b.id IS NULL THEN RAISE EXCEPTION 'No such bill'; END IF;
    SELECT COALESCE(sum(line_subtotal), 0), COALESCE(sum(line_tax), 0) INTO s, t FROM bill_lines WHERE bill_id = b.id;
    SELECT COALESCE(sum(pa.amount), 0) INTO paid FROM payment_applications pa JOIN payments p ON p.id = pa.payment_id AND p.status = 'recorded' WHERE pa.bill_id = b.id;
    SELECT COALESCE(sum(va.amount), 0) INTO cred FROM vendor_credit_applications va JOIN vendor_credits v ON v.id = va.vendor_credit_id AND v.status = 'issued' WHERE va.bill_id = b.id;
    ns := b.status;
    IF b.status IN ('approved', 'partially_paid', 'paid') THEN
        IF s + t - paid - cred <= 0 AND s + t > 0 THEN ns := 'paid';
        ELSIF paid + cred > 0 THEN ns := 'partially_paid';
        ELSE ns := 'approved';
        END IF;
    END IF;
    UPDATE bills SET subtotal = CASE WHEN status = 'draft' THEN s ELSE subtotal END,
                     tax_total = CASE WHEN status = 'draft' THEN t ELSE tax_total END,
                     total = CASE WHEN status = 'draft' THEN s + t ELSE total END,
                     amount_paid = paid, amount_credited = cred, status = ns,
                     paid_at = CASE WHEN ns = 'paid' THEN COALESCE(paid_at, now()) ELSE NULL END
     WHERE id = b.id RETURNING * INTO b;
    RETURN b;
END$$;

CREATE OR REPLACE FUNCTION gl_bill_line_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM gl_bill_recalculate(COALESCE(NEW.bill_id, OLD.bill_id));
    RETURN NULL;
END$$;
CREATE TRIGGER bill_lines_after AFTER INSERT OR UPDATE OR DELETE ON bill_lines FOR EACH ROW EXECUTE FUNCTION gl_bill_line_after();

CREATE OR REPLACE FUNCTION gl_bill_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.number := COALESCE(NEW.number, next_document_number('bill'));
        IF NEW.status <> 'draft' THEN RAISE EXCEPTION 'A bill is born a draft and approved afterwards'; END IF;
        RETURN NEW;
    END IF;
    IF OLD.status = 'void' THEN RAISE EXCEPTION 'Bill % is void and cannot be changed', OLD.number; END IF;
    IF OLD.status <> 'draft' THEN
        IF row(NEW.vendor_id, NEW.bill_date, NEW.currency, NEW.subtotal, NEW.tax_total, NEW.total, NEW.number)
           IS DISTINCT FROM row(OLD.vendor_id, OLD.bill_date, OLD.currency, OLD.subtotal, OLD.tax_total, OLD.total, OLD.number) THEN
            RAISE EXCEPTION 'Bill % is approved: its vendor, date and totals cannot change (credit it, or void it)', OLD.number;
        END IF;
        IF NEW.status = 'draft' THEN RAISE EXCEPTION 'Bill % is approved and cannot return to draft', OLD.number; END IF;
        IF NEW.status = 'void' AND (OLD.amount_paid > 0 OR OLD.amount_credited > 0) THEN RAISE EXCEPTION 'Bill % has payments or credits applied; unapply them first', OLD.number; END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER bills_guard BEFORE INSERT OR UPDATE ON bills FOR EACH ROW EXECUTE FUNCTION gl_bill_guard();

CREATE OR REPLACE FUNCTION gl_bill_no_delete() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'draft' THEN RAISE EXCEPTION 'Bill % is approved and is never deleted; void it', OLD.number; END IF;
    RETURN OLD;
END$$;
CREATE TRIGGER bills_no_delete BEFORE DELETE ON bills FOR EACH ROW EXECUTE FUNCTION gl_bill_no_delete();

-- Vendor credits: the same shape.
CREATE OR REPLACE FUNCTION gl_vendor_credit_line_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE st text; m record;
BEGIN
    SELECT status INTO st FROM vendor_credits WHERE id = COALESCE(NEW.vendor_credit_id, OLD.vendor_credit_id);
    IF st IS DISTINCT FROM 'draft' THEN RAISE EXCEPTION 'The lines of a vendor credit change only while it is a draft (it is %)', st; END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    IF NEW.tax_rate_id IS NOT NULL THEN SELECT rate INTO NEW.tax_rate FROM tax_rates WHERE id = NEW.tax_rate_id; ELSE NEW.tax_rate := 0; END IF;
    SELECT * INTO m FROM gl_line_money(NEW.quantity, NEW.unit_price, NEW.tax_rate);
    NEW.line_subtotal := m.line_subtotal; NEW.line_tax := m.line_tax; NEW.line_total := m.line_total;
    RETURN NEW;
END$$;
CREATE TRIGGER vendor_credit_lines_before BEFORE INSERT OR UPDATE OR DELETE ON vendor_credit_lines FOR EACH ROW EXECUTE FUNCTION gl_vendor_credit_line_before();

CREATE OR REPLACE FUNCTION gl_vendor_credit_recalculate(p_id bigint) RETURNS vendor_credits
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c vendor_credits%ROWTYPE; s numeric(14,2); t numeric(14,2); ap numeric(14,2);
BEGIN
    SELECT * INTO c FROM vendor_credits WHERE id = p_id FOR UPDATE;
    IF c.id IS NULL THEN RAISE EXCEPTION 'No such vendor credit'; END IF;
    SELECT COALESCE(sum(line_subtotal), 0), COALESCE(sum(line_tax), 0) INTO s, t FROM vendor_credit_lines WHERE vendor_credit_id = c.id;
    SELECT COALESCE(sum(amount), 0) INTO ap FROM vendor_credit_applications WHERE vendor_credit_id = c.id;
    UPDATE vendor_credits SET subtotal = CASE WHEN status = 'draft' THEN s ELSE subtotal END,
                              tax_total = CASE WHEN status = 'draft' THEN t ELSE tax_total END,
                              total = CASE WHEN status = 'draft' THEN s + t ELSE total END,
                              amount_applied = ap
     WHERE id = c.id RETURNING * INTO c;
    RETURN c;
END$$;

CREATE OR REPLACE FUNCTION gl_vendor_credit_line_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM gl_vendor_credit_recalculate(COALESCE(NEW.vendor_credit_id, OLD.vendor_credit_id));
    RETURN NULL;
END$$;
CREATE TRIGGER vendor_credit_lines_after AFTER INSERT OR UPDATE OR DELETE ON vendor_credit_lines FOR EACH ROW EXECUTE FUNCTION gl_vendor_credit_line_after();

CREATE OR REPLACE FUNCTION gl_vendor_credit_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.number := COALESCE(NEW.number, next_document_number('vendor_credit'));
        IF NEW.status <> 'draft' THEN RAISE EXCEPTION 'A vendor credit is born a draft and issued afterwards'; END IF;
        RETURN NEW;
    END IF;
    IF OLD.status = 'void' THEN RAISE EXCEPTION 'Vendor credit % is void and cannot be changed', OLD.number; END IF;
    IF OLD.status = 'issued' THEN
        IF row(NEW.vendor_id, NEW.credit_date, NEW.currency, NEW.subtotal, NEW.tax_total, NEW.total) IS DISTINCT FROM row(OLD.vendor_id, OLD.credit_date, OLD.currency, OLD.subtotal, OLD.tax_total, OLD.total) THEN
            RAISE EXCEPTION 'Vendor credit % is issued and cannot change', OLD.number;
        END IF;
        IF NEW.status = 'draft' THEN RAISE EXCEPTION 'Vendor credit % is issued and cannot return to draft', OLD.number; END IF;
        IF NEW.status = 'void' AND OLD.amount_applied > 0 THEN RAISE EXCEPTION 'Vendor credit % is applied; unapply it first', OLD.number; END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER vendor_credits_guard BEFORE INSERT OR UPDATE ON vendor_credits FOR EACH ROW EXECUTE FUNCTION gl_vendor_credit_guard();

-- ---------------------------------------------------------------------------------------------
-- Applications.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION gl_payment_application_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE p payments%ROWTYPE; b bills%ROWTYPE; applied numeric(14,2); owed numeric(14,2);
BEGIN
    SELECT * INTO p FROM payments WHERE id = NEW.payment_id;
    SELECT * INTO b FROM bills WHERE id = NEW.bill_id;
    IF p.status = 'void' THEN RAISE EXCEPTION 'Payment % is void', p.number; END IF;
    IF p.status = 'recorded' THEN RAISE EXCEPTION 'Payment % is recorded; its applications are fixed', p.number; END IF;
    IF b.status NOT IN ('approved', 'partially_paid') THEN RAISE EXCEPTION 'Bill % is % and takes no payment', b.number, b.status; END IF;
    IF p.vendor_id <> b.vendor_id THEN RAISE EXCEPTION 'Payment % is to another vendor than bill %', p.number, b.number; END IF;
    IF p.currency <> b.currency THEN RAISE EXCEPTION 'Payment % and bill % are in different currencies', p.number, b.number; END IF;
    SELECT COALESCE(sum(amount), 0) INTO applied FROM payment_applications WHERE payment_id = NEW.payment_id AND bill_id <> NEW.bill_id;
    IF applied + NEW.amount > p.amount THEN RAISE EXCEPTION 'Payment % is %; % more would exceed it', p.number, p.amount, NEW.amount; END IF;
    SELECT COALESCE(sum(pa.amount), 0) INTO applied FROM payment_applications pa JOIN payments pp ON pp.id = pa.payment_id AND pp.status <> 'void'
     WHERE pa.bill_id = NEW.bill_id AND pa.payment_id <> NEW.payment_id;
    owed := b.total - b.amount_credited - applied;
    IF NEW.amount > owed THEN RAISE EXCEPTION 'Bill % is owed %; paying % would overpay it', b.number, owed, NEW.amount; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER payment_applications_guard BEFORE INSERT OR UPDATE ON payment_applications FOR EACH ROW EXECUTE FUNCTION gl_payment_application_guard();

CREATE OR REPLACE FUNCTION gl_payment_application_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM gl_bill_recalculate(COALESCE(NEW.bill_id, OLD.bill_id));
    RETURN NULL;
END$$;
CREATE TRIGGER payment_applications_after AFTER INSERT OR UPDATE OR DELETE ON payment_applications FOR EACH ROW EXECUTE FUNCTION gl_payment_application_after();

CREATE OR REPLACE FUNCTION gl_vendor_credit_application_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE c vendor_credits%ROWTYPE; b bills%ROWTYPE; applied numeric(14,2); owed numeric(14,2);
BEGIN
    SELECT * INTO c FROM vendor_credits WHERE id = NEW.vendor_credit_id;
    SELECT * INTO b FROM bills WHERE id = NEW.bill_id;
    IF c.status <> 'issued' THEN RAISE EXCEPTION 'Vendor credit % is %, not issued', c.number, c.status; END IF;
    IF b.status NOT IN ('approved', 'partially_paid') THEN RAISE EXCEPTION 'Bill % is % and takes no credit', b.number, b.status; END IF;
    IF c.vendor_id <> b.vendor_id THEN RAISE EXCEPTION 'Vendor credit % is from another vendor than bill %', c.number, b.number; END IF;
    IF c.currency <> b.currency THEN RAISE EXCEPTION 'Vendor credit % and bill % are in different currencies', c.number, b.number; END IF;
    SELECT COALESCE(sum(amount), 0) INTO applied FROM vendor_credit_applications WHERE vendor_credit_id = NEW.vendor_credit_id AND bill_id <> NEW.bill_id;
    IF applied + NEW.amount > c.total THEN RAISE EXCEPTION 'Vendor credit % is %; % more would exceed it', c.number, c.total, NEW.amount; END IF;
    SELECT COALESCE(sum(amount), 0) INTO applied FROM vendor_credit_applications WHERE bill_id = NEW.bill_id AND vendor_credit_id <> NEW.vendor_credit_id;
    owed := b.total - b.amount_paid - applied;
    IF NEW.amount > owed THEN RAISE EXCEPTION 'Bill % is owed %; crediting % would exceed it', b.number, owed, NEW.amount; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER vendor_credit_applications_guard BEFORE INSERT OR UPDATE ON vendor_credit_applications FOR EACH ROW EXECUTE FUNCTION gl_vendor_credit_application_guard();

CREATE OR REPLACE FUNCTION gl_vendor_credit_application_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM gl_bill_recalculate(COALESCE(NEW.bill_id, OLD.bill_id));
    PERFORM gl_vendor_credit_recalculate(COALESCE(NEW.vendor_credit_id, OLD.vendor_credit_id));
    RETURN NULL;
END$$;
CREATE TRIGGER vendor_credit_applications_after AFTER INSERT OR UPDATE OR DELETE ON vendor_credit_applications FOR EACH ROW EXECUTE FUNCTION gl_vendor_credit_application_after();

-- ---------------------------------------------------------------------------------------------
-- Payments and runs: numbered at creation; a payment's life draft → approved → recorded (→ void); a run's the same,
-- carrying its payments with it; a run's total is its payments'.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION gl_payment_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE rs text;
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.number := COALESCE(NEW.number, next_document_number('payment'));
        IF NEW.status <> 'draft' THEN RAISE EXCEPTION 'A payment is born a draft, approved, then recorded'; END IF;
        IF NEW.payment_run_id IS NOT NULL THEN
            SELECT status INTO rs FROM payment_runs WHERE id = NEW.payment_run_id;
            IF rs <> 'draft' THEN RAISE EXCEPTION 'The payment run is %; payments join a draft run', rs; END IF;
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.status = 'void' THEN RAISE EXCEPTION 'Payment % is void and cannot be changed', OLD.number; END IF;
    IF OLD.status = 'recorded' THEN
        IF NEW.status = 'void' THEN NEW.voided_at := COALESCE(NEW.voided_at, now()); RETURN NEW; END IF;
        IF row(NEW.vendor_id, NEW.payment_date, NEW.amount, NEW.currency, NEW.method, NEW.bank_account_id, NEW.status, NEW.payment_run_id)
           IS DISTINCT FROM row(OLD.vendor_id, OLD.payment_date, OLD.amount, OLD.currency, OLD.method, OLD.bank_account_id, OLD.status, OLD.payment_run_id) THEN
            RAISE EXCEPTION 'Payment % is recorded and cannot change; void it', OLD.number;
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.status = 'approved' AND NEW.status = 'draft' THEN RAISE EXCEPTION 'Payment % is approved; void it rather than un-approve it', OLD.number; END IF;
    IF OLD.status = 'approved' AND row(NEW.vendor_id, NEW.amount, NEW.currency, NEW.bank_account_id) IS DISTINCT FROM row(OLD.vendor_id, OLD.amount, OLD.currency, OLD.bank_account_id) THEN
        RAISE EXCEPTION 'Payment % is approved: its vendor, amount and bank account are fixed', OLD.number;
    END IF;
    IF NEW.status = 'recorded' AND OLD.status <> 'approved' THEN RAISE EXCEPTION 'Payment % must be approved before it is recorded', OLD.number; END IF;
    IF NEW.status = 'void' THEN NEW.voided_at := COALESCE(NEW.voided_at, now()); END IF;
    IF NEW.payment_run_id IS DISTINCT FROM OLD.payment_run_id THEN
        IF OLD.status <> 'draft' THEN RAISE EXCEPTION 'Payment % is %; only a draft joins or leaves a run', OLD.number, OLD.status; END IF;
        IF NEW.payment_run_id IS NOT NULL THEN
            SELECT status INTO rs FROM payment_runs WHERE id = NEW.payment_run_id;
            IF rs <> 'draft' THEN RAISE EXCEPTION 'The payment run is %; payments join a draft run', rs; END IF;
        END IF;
        IF OLD.payment_run_id IS NOT NULL THEN
            SELECT status INTO rs FROM payment_runs WHERE id = OLD.payment_run_id;
            IF rs <> 'draft' THEN RAISE EXCEPTION 'The payment run is %; a payment leaves a draft run only', rs; END IF;
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER payments_guard BEFORE INSERT OR UPDATE ON payments FOR EACH ROW EXECUTE FUNCTION gl_payment_guard();

CREATE OR REPLACE FUNCTION gl_payment_no_delete() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'draft' THEN RAISE EXCEPTION 'Payment % is % and is never deleted; void it', OLD.number, OLD.status; END IF;
    RETURN OLD;
END$$;
CREATE TRIGGER payments_no_delete BEFORE DELETE ON payments FOR EACH ROW EXECUTE FUNCTION gl_payment_no_delete();

CREATE OR REPLACE FUNCTION gl_payment_run_total() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    UPDATE payment_runs r SET total = (SELECT COALESCE(sum(amount), 0) FROM payments WHERE payment_run_id = r.id AND status <> 'void')
     WHERE r.id IN (NEW.payment_run_id, OLD.payment_run_id);
    RETURN NULL;
END$$;
CREATE TRIGGER payments_run_total AFTER INSERT OR UPDATE OF payment_run_id, status, amount OR DELETE ON payments FOR EACH ROW EXECUTE FUNCTION gl_payment_run_total();

CREATE OR REPLACE FUNCTION gl_payment_run_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.number := COALESCE(NEW.number, next_document_number('payment_run'));
        IF NEW.status <> 'draft' THEN RAISE EXCEPTION 'A payment run is born a draft'; END IF;
        RETURN NEW;
    END IF;
    IF OLD.status = 'void' THEN RAISE EXCEPTION 'Payment run % is void and cannot be changed', OLD.number; END IF;
    IF OLD.status = 'recorded' AND NEW.status <> 'recorded' AND NEW.status <> 'void' THEN RAISE EXCEPTION 'Payment run % is recorded', OLD.number; END IF;
    IF OLD.status = 'approved' AND NEW.status = 'draft' THEN RAISE EXCEPTION 'Payment run % is approved; void it rather than un-approve it', OLD.number; END IF;
    IF NEW.status = 'recorded' AND OLD.status <> 'approved' THEN RAISE EXCEPTION 'Payment run % must be approved before it is recorded', OLD.number; END IF;
    IF NEW.status = 'approved' AND OLD.status = 'draft' AND NEW.total <= 0 THEN RAISE EXCEPTION 'Payment run % has no payments', OLD.number; END IF;
    IF OLD.status <> 'draft' AND row(NEW.bank_account_id, NEW.currency) IS DISTINCT FROM row(OLD.bank_account_id, OLD.currency) THEN
        RAISE EXCEPTION 'Payment run % is %; its bank account is fixed', OLD.number, OLD.status;
    END IF;
    IF NEW.status = 'void' THEN NEW.voided_at := COALESCE(NEW.voided_at, now()); END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER payment_runs_guard BEFORE INSERT OR UPDATE ON payment_runs FOR EACH ROW EXECUTE FUNCTION gl_payment_run_guard();

-- ---------------------------------------------------------------------------------------------
-- The writer's verbs. Approving a bill posts it. Recording a payment posts it (db/013 supplies the bank-aware body).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION gl_bill_approve(p_bill_id bigint, p_by bigint) RETURNS bills
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b bills%ROWTYPE; rule posting_rules%ROWTYPE; ap bigint; e journal_entries%ROWTYPE; n integer := 0; rec record;
BEGIN
    SELECT * INTO b FROM bills WHERE id = p_bill_id FOR UPDATE;
    IF b.id IS NULL THEN RAISE EXCEPTION 'No such bill'; END IF;
    IF b.status <> 'draft' THEN RAISE EXCEPTION 'Bill % is already %', b.number, b.status; END IF;
    IF NOT EXISTS (SELECT 1 FROM bill_lines WHERE bill_id = b.id) THEN RAISE EXCEPTION 'A bill needs at least one line'; END IF;
    IF b.total <= 0 THEN RAISE EXCEPTION 'A bill for nothing is not approved'; END IF;
    rule := gl_posting_rule(CASE WHEN b.source = 'ai_usage' THEN 'ai_usage' ELSE 'bill' END);
    IF rule.id IS NULL THEN RAISE EXCEPTION 'No posting rule for bills'; END IF;
    SELECT ap_account_id INTO ap FROM gl_settings WHERE id = 1;
    INSERT INTO journal_entries (entry_date, memo, source, source_type, source_id, currency, department_id, site_id, created_by)
    VALUES (b.bill_date, 'Bill ' || b.number || ' from ' || (SELECT name FROM vendors WHERE id = b.vendor_id) || COALESCE(' (' || b.vendor_ref || ')', ''),
            CASE WHEN b.source = 'ai_usage' THEN 'ai_usage' ELSE 'bill' END, 'bill', b.id, b.currency, b.department_id, b.site_id, p_by)
    RETURNING * INTO e;
    FOR rec IN SELECT expense_account_id, department_id, site_id, sum(line_total) AS s FROM bill_lines WHERE bill_id = b.id
                GROUP BY expense_account_id, department_id, site_id HAVING sum(line_total) <> 0 LOOP
        n := n + 1;
        INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, department_id, site_id)
        VALUES (e.id, n, rec.expense_account_id, 'Expense', CASE WHEN rec.s > 0 THEN rec.s ELSE 0 END, CASE WHEN rec.s < 0 THEN -rec.s ELSE 0 END,
                COALESCE(rec.department_id, b.department_id), COALESCE(rec.site_id, b.site_id));
    END LOOP;
    INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, department_id, site_id, vendor_id)
    VALUES (e.id, n + 1, COALESCE(rule.credit_account_id, ap), 'Payable', 0, b.total, b.department_id, b.site_id, b.vendor_id);
    PERFORM gl_entry_post(e.id, p_by);
    UPDATE bills SET status = 'approved', approved_at = now(), approved_by = p_by, journal_entry_id = e.id WHERE id = b.id RETURNING * INTO b;
    RETURN b;
END$$;

CREATE OR REPLACE FUNCTION gl_bill_void(p_bill_id bigint, p_by bigint, p_reason text) RETURNS bills
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b bills%ROWTYPE;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'Voiding a bill needs a reason'; END IF;
    SELECT * INTO b FROM bills WHERE id = p_bill_id FOR UPDATE;
    IF b.id IS NULL THEN RAISE EXCEPTION 'No such bill'; END IF;
    UPDATE bills SET status = 'void', voided_at = now(), voided_by = p_by, void_reason = p_reason WHERE id = b.id RETURNING * INTO b;
    IF b.journal_entry_id IS NOT NULL THEN
        PERFORM gl_entry_reverse(b.journal_entry_id, p_by, 'Bill ' || b.number || ' voided: ' || p_reason);
    END IF;
    RETURN b;
END$$;

CREATE OR REPLACE FUNCTION gl_vendor_credit_issue(p_id bigint, p_by bigint) RETURNS vendor_credits
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c vendor_credits%ROWTYPE; rule posting_rules%ROWTYPE; ap bigint; e journal_entries%ROWTYPE; n integer := 1; rec record;
BEGIN
    SELECT * INTO c FROM vendor_credits WHERE id = p_id FOR UPDATE;
    IF c.id IS NULL THEN RAISE EXCEPTION 'No such vendor credit'; END IF;
    IF c.status <> 'draft' THEN RAISE EXCEPTION 'Vendor credit % is already %', c.number, c.status; END IF;
    IF c.total <= 0 THEN RAISE EXCEPTION 'A vendor credit for nothing is not issued'; END IF;
    rule := gl_posting_rule('vendor_credit');
    IF rule.id IS NULL THEN RAISE EXCEPTION 'No posting rule for vendor credits'; END IF;
    SELECT ap_account_id INTO ap FROM gl_settings WHERE id = 1;
    INSERT INTO journal_entries (entry_date, memo, source, source_type, source_id, currency, department_id, site_id, created_by)
    VALUES (c.credit_date, 'Vendor credit ' || c.number || ' from ' || (SELECT name FROM vendors WHERE id = c.vendor_id), 'vendor_credit', 'vendor_credit', c.id, c.currency, c.department_id, c.site_id, p_by)
    RETURNING * INTO e;
    INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, department_id, site_id, vendor_id)
    VALUES (e.id, 1, COALESCE(rule.debit_account_id, ap), 'Payable reduced', c.total, 0, c.department_id, c.site_id, c.vendor_id);
    FOR rec IN SELECT expense_account_id, department_id, site_id, sum(line_total) AS s FROM vendor_credit_lines WHERE vendor_credit_id = c.id
                GROUP BY expense_account_id, department_id, site_id HAVING sum(line_total) <> 0 LOOP
        n := n + 1;
        INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, department_id, site_id)
        VALUES (e.id, n, rec.expense_account_id, 'Expense credited', CASE WHEN rec.s < 0 THEN -rec.s ELSE 0 END, CASE WHEN rec.s > 0 THEN rec.s ELSE 0 END,
                COALESCE(rec.department_id, c.department_id), COALESCE(rec.site_id, c.site_id));
    END LOOP;
    PERFORM gl_entry_post(e.id, p_by);
    UPDATE vendor_credits SET status = 'issued', issued_at = now(), issued_by = p_by, journal_entry_id = e.id WHERE id = c.id RETURNING * INTO c;
    RETURN c;
END$$;

CREATE OR REPLACE FUNCTION gl_payment_approve(p_payment_id bigint, p_by bigint) RETURNS payments
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p payments%ROWTYPE;
BEGIN
    SELECT * INTO p FROM payments WHERE id = p_payment_id FOR UPDATE;
    IF p.id IS NULL THEN RAISE EXCEPTION 'No such payment'; END IF;
    IF p.status <> 'draft' THEN RAISE EXCEPTION 'Payment % is already %', p.number, p.status; END IF;
    IF NOT EXISTS (SELECT 1 FROM payment_applications WHERE payment_id = p.id) THEN RAISE EXCEPTION 'Payment % is applied to no bill', p.number; END IF;
    UPDATE payments SET status = 'approved', approved_at = now(), approved_by = p_by WHERE id = p.id RETURNING * INTO p;
    RETURN p;
END$$;

CREATE OR REPLACE FUNCTION gl_payment_run_approve(p_run_id bigint, p_by bigint) RETURNS payment_runs
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r payment_runs%ROWTYPE; pid bigint;
BEGIN
    SELECT * INTO r FROM payment_runs WHERE id = p_run_id FOR UPDATE;
    IF r.id IS NULL THEN RAISE EXCEPTION 'No such payment run'; END IF;
    IF r.status <> 'draft' THEN RAISE EXCEPTION 'Payment run % is already %', r.number, r.status; END IF;
    FOR pid IN SELECT id FROM payments WHERE payment_run_id = r.id AND status = 'draft' LOOP
        PERFORM gl_payment_approve(pid, p_by);
    END LOOP;
    UPDATE payment_runs SET status = 'approved', approved_at = now(), approved_by = p_by WHERE id = r.id RETURNING * INTO r;
    RETURN r;
END$$;

REVOKE ALL ON FUNCTION gl_bill_recalculate(bigint), gl_vendor_credit_recalculate(bigint), gl_bill_approve(bigint, bigint), gl_bill_void(bigint, bigint, text),
    gl_vendor_credit_issue(bigint, bigint), gl_payment_approve(bigint, bigint), gl_payment_run_approve(bigint, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION gl_bill_recalculate(bigint), gl_vendor_credit_recalculate(bigint), gl_bill_approve(bigint, bigint), gl_bill_void(bigint, bigint, text),
    gl_vendor_credit_issue(bigint, bigint), gl_payment_approve(bigint, bigint), gl_payment_run_approve(bigint, bigint) TO gl_rw;
GRANT SELECT, INSERT, UPDATE, DELETE ON bills, bill_lines, vendor_credits, vendor_credit_lines, vendor_credit_applications, payment_runs, payments,
    payment_applications TO gl_rw;

COMMIT;
