-- 006: the business's ledger SETTINGS (one row), DOCUMENT NUMBERING (D10), TAX RATES and ITEMS.
--
-- Numbering is a TABLE updated transactionally, not a SEQUENCE: a rolled-back issue returns the number, which is
-- what makes an invoice's sequence gapless. Invoices and credit notes take their number on ISSUE; journal entries on
-- POST; bills, receipts, payments, deposits, runs and transfers at creation (an internal reference, a hole explains
-- nothing). Prefixes are settings.
BEGIN;

CREATE TABLE gl_settings (
    id                          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    business_name               text,                                             -- on the invoice header
    business_address            text,
    business_tax_id             text,
    base_currency               char(3) NOT NULL DEFAULT 'USD' CHECK (base_currency ~ '^[A-Z]{3}$'),
    fiscal_year_start_month     smallint NOT NULL DEFAULT 1 CHECK (fiscal_year_start_month BETWEEN 1 AND 12),
    timezone                    text NOT NULL DEFAULT 'UTC',
    ar_account_id               bigint REFERENCES accounts(id) ON DELETE RESTRICT,
    ap_account_id               bigint REFERENCES accounts(id) ON DELETE RESTRICT,
    undeposited_funds_account_id bigint REFERENCES accounts(id) ON DELETE RESTRICT,
    sales_tax_account_id        bigint REFERENCES accounts(id) ON DELETE RESTRICT,
    ai_usage_account_id         bigint REFERENCES accounts(id) ON DELETE RESTRICT,
    retained_earnings_account_id bigint REFERENCES accounts(id) ON DELETE RESTRICT,
    bad_debt_account_id         bigint REFERENCES accounts(id) ON DELETE RESTRICT,
    default_bank_account_id     bigint,                                           -- FK added in db/013
    invoice_terms_days          integer NOT NULL DEFAULT 30 CHECK (invoice_terms_days BETWEEN 0 AND 365),
    bill_terms_days             integer NOT NULL DEFAULT 30 CHECK (bill_terms_days BETWEEN 0 AND 365),
    link_lifetime_days          integer NOT NULL DEFAULT 180 CHECK (link_lifetime_days BETWEEN 1 AND 3650),   -- D9
    reminder_days               integer[] NOT NULL DEFAULT '{7,21,45}',          -- days past due a reminder is drafted
    ai_statement_poll           boolean NOT NULL DEFAULT true,                    -- the worker pulls the kernel's closed months
    morning_note_member_id      bigint REFERENCES members(id) ON DELETE SET NULL, -- who receives the Bookkeeper's note (D6)
    invoice_footer              text,
    updated_at                  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO gl_settings (id, ar_account_id, ap_account_id, undeposited_funds_account_id, sales_tax_account_id, ai_usage_account_id,
                         retained_earnings_account_id, bad_debt_account_id)
SELECT 1, (SELECT id FROM accounts WHERE code = '1200'), (SELECT id FROM accounts WHERE code = '2000'),
          (SELECT id FROM accounts WHERE code = '1050'), (SELECT id FROM accounts WHERE code = '2100'),
          (SELECT id FROM accounts WHERE code = '6050'), (SELECT id FROM accounts WHERE code = '3900'),
          (SELECT id FROM accounts WHERE code = '6600')
ON CONFLICT DO NOTHING;
CREATE TRIGGER gl_settings_touch BEFORE UPDATE ON gl_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION gl_base_currency() RETURNS char(3)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT base_currency FROM gl_settings WHERE id = 1 $$;

-- ---------------------------------------------------------------------------------------------
-- Numbering
-- ---------------------------------------------------------------------------------------------
CREATE TABLE document_sequences (
    kind        text PRIMARY KEY CHECK (kind IN ('invoice', 'credit_note', 'journal_entry', 'bill', 'vendor_credit', 'receipt',
                                                 'payment', 'deposit', 'payment_run', 'transfer')),
    prefix      text NOT NULL CHECK (prefix ~ '^[A-Z][A-Z0-9]{0,7}-?$'),
    next_value  bigint NOT NULL DEFAULT 1 CHECK (next_value > 0),
    padding     smallint NOT NULL DEFAULT 5 CHECK (padding BETWEEN 1 AND 12),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO document_sequences (kind, prefix, padding) VALUES
    ('invoice', 'INV-', 5), ('credit_note', 'CN-', 5), ('journal_entry', 'JE-', 6), ('bill', 'BILL-', 6),
    ('vendor_credit', 'VC-', 5), ('receipt', 'RCT-', 6), ('payment', 'PAY-', 6), ('deposit', 'DEP-', 6),
    ('payment_run', 'RUN-', 5), ('transfer', 'TRF-', 6)
ON CONFLICT DO NOTHING;

-- Row lock via UPDATE ... RETURNING: concurrent callers serialize; a rolled-back caller returns the number.
CREATE OR REPLACE FUNCTION next_document_number(p_kind text) RETURNS text
    LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
    UPDATE document_sequences
       SET next_value = next_value + 1, updated_at = now()
     WHERE kind = p_kind
    RETURNING prefix || lpad((next_value - 1)::text, padding, '0');
$$;
REVOKE ALL ON FUNCTION next_document_number(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION next_document_number(text) TO gl_rw;

-- ---------------------------------------------------------------------------------------------
-- Tax rates (a percentage; rounded per line, never on the document total) and items (what an invoice or a bill
-- line is made of: a product or a service with its default accounts, price and tax).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE tax_rates (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name         text NOT NULL,
    rate         numeric(7,4) NOT NULL CHECK (rate >= 0 AND rate < 100),
    is_default   boolean NOT NULL DEFAULT false,
    archived_at  timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX tax_rates_name_live ON tax_rates (lower(name)) WHERE archived_at IS NULL;
CREATE UNIQUE INDEX tax_rates_one_default ON tax_rates ((true)) WHERE is_default AND archived_at IS NULL;
CREATE TRIGGER tax_rates_touch BEFORE UPDATE ON tax_rates FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
INSERT INTO tax_rates (name, rate, is_default) VALUES ('No tax', 0, true);

CREATE TABLE items (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code                text,
    name                text NOT NULL,
    kind                text NOT NULL DEFAULT 'service' CHECK (kind IN ('product', 'service')),
    description         text,
    unit                text,                                              -- 'hour', 'each', 'month'
    unit_price          numeric(14,2) NOT NULL DEFAULT 0 CHECK (unit_price >= 0),
    income_account_id   bigint REFERENCES accounts(id) ON DELETE SET NULL,  -- when sold
    expense_account_id  bigint REFERENCES accounts(id) ON DELETE SET NULL,  -- when bought
    tax_rate_id         bigint REFERENCES tax_rates(id) ON DELETE SET NULL,
    archived_at         timestamptz,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX items_code_live ON items (lower(code)) WHERE code IS NOT NULL AND archived_at IS NULL;
CREATE INDEX items_name_trgm_idx ON items USING gin (name gin_trgm_ops);
CREATE INDEX items_income_idx ON items (income_account_id);
CREATE INDEX items_expense_idx ON items (expense_account_id);
CREATE INDEX items_tax_idx ON items (tax_rate_id);
CREATE TRIGGER items_touch BEFORE UPDATE ON items FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A document line's money, rounded per line: subtotal = qty × price; tax = subtotal × rate / 100; total.
CREATE OR REPLACE FUNCTION gl_line_money(p_qty numeric, p_price numeric, p_rate numeric,
                                         OUT line_subtotal numeric, OUT line_tax numeric, OUT line_total numeric)
    LANGUAGE sql IMMUTABLE AS $$
    SELECT round(p_qty * p_price, 2),
           round(round(p_qty * p_price, 2) * COALESCE(p_rate, 0) / 100, 2),
           round(p_qty * p_price, 2) + round(round(p_qty * p_price, 2) * COALESCE(p_rate, 0) / 100, 2);
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON gl_settings, document_sequences, tax_rates, items TO gl_rw;
GRANT EXECUTE ON FUNCTION gl_base_currency(), gl_line_money(numeric, numeric, numeric) TO gl_rw, gl_records_ro;

COMMIT;
