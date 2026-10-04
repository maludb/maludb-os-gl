-- 009: PARTIES — customers and vendors — plus the two things every record carries: ATTACHMENTS and NOTES (design §4, §6).
--
-- A party is the ledger's own record, never a member and never signed in (D1 of the help desk, kept here): a customer
-- reaches one invoice through its secure link (db/010); a vendor is a record whose bill is an attachment. `member_id`
-- is nullable on both: a vendor who is an employee being reimbursed, a customer who is also an invited external
-- member. A vendor of kind `ai_provider` is made by the AI statement import (db/012), keyed by the provider.
BEGIN;

CREATE TABLE customers (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name               text NOT NULL,
    legal_name         text,
    email              citext,
    phone              text,
    billing_address    text,
    shipping_address   text,
    tax_id             text,
    terms_days         integer CHECK (terms_days BETWEEN 0 AND 365),           -- NULL = the business default
    currency           char(3) CHECK (currency ~ '^[A-Z]{3}$'),                 -- NULL = the base currency
    income_account_id  bigint REFERENCES accounts(id) ON DELETE SET NULL,       -- the default revenue account for their lines
    tax_rate_id        bigint REFERENCES tax_rates(id) ON DELETE SET NULL,
    member_id          bigint REFERENCES members(id) ON DELETE SET NULL,
    notes              text,
    archived_at        timestamptz,
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX customers_name_live ON customers (lower(name)) WHERE archived_at IS NULL;
CREATE INDEX customers_name_trgm_idx ON customers USING gin (name gin_trgm_ops);
CREATE INDEX customers_email_idx ON customers (email);
CREATE INDEX customers_member_idx ON customers (member_id);
CREATE INDEX customers_income_idx ON customers (income_account_id);
CREATE INDEX customers_tax_idx ON customers (tax_rate_id);
CREATE INDEX customers_created_by_idx ON customers (created_by);
CREATE TRIGGER customers_touch BEFORE UPDATE ON customers FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE vendors (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name               text NOT NULL,
    legal_name         text,
    kind               text NOT NULL DEFAULT 'vendor' CHECK (kind IN ('vendor', 'ai_provider', 'employee', 'tax_authority')),
    provider_key       text,                                                    -- the kernel's provider key for an ai_provider
    email              citext,
    phone              text,
    remit_address      text,
    tax_id             text,
    terms_days         integer CHECK (terms_days BETWEEN 0 AND 365),
    currency           char(3) CHECK (currency ~ '^[A-Z]{3}$'),
    expense_account_id bigint REFERENCES accounts(id) ON DELETE SET NULL,       -- the default expense account for their lines
    pay_method         text CHECK (pay_method IN ('cheque', 'ach', 'card', 'cash', 'other')),
    member_id          bigint REFERENCES members(id) ON DELETE SET NULL,
    notes              text,
    archived_at        timestamptz,
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK ((kind = 'ai_provider') = (provider_key IS NOT NULL))
);
CREATE UNIQUE INDEX vendors_name_live ON vendors (lower(name)) WHERE archived_at IS NULL;
CREATE UNIQUE INDEX vendors_provider_live ON vendors (provider_key) WHERE provider_key IS NOT NULL AND archived_at IS NULL;
CREATE INDEX vendors_name_trgm_idx ON vendors USING gin (name gin_trgm_ops);
CREATE INDEX vendors_email_idx ON vendors (email);
CREATE INDEX vendors_member_idx ON vendors (member_id);
CREATE INDEX vendors_expense_idx ON vendors (expense_account_id);
CREATE INDEX vendors_created_by_idx ON vendors (created_by);
CREATE TRIGGER vendors_touch BEFORE UPDATE ON vendors FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE party_contacts (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    party_kind  text NOT NULL CHECK (party_kind IN ('customer', 'vendor')),
    party_id    bigint NOT NULL,
    name        text NOT NULL,
    email       citext,
    phone       text,
    role        text,                                                           -- 'Accounts payable', 'Owner'
    is_primary  boolean NOT NULL DEFAULT false,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX party_contacts_party_idx ON party_contacts (party_kind, party_id);
CREATE UNIQUE INDEX party_contacts_one_primary ON party_contacts (party_kind, party_id) WHERE is_primary;
CREATE TRIGGER party_contacts_touch BEFORE UPDATE ON party_contacts FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The party on a control-account line (db/008 gl_entry_guard).
ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT;
ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_vendor_fk FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT;

-- ---------------------------------------------------------------------------------------------
-- Attachments: a file on any record, under storage/attachments/<record_type>/<record_id>/, served through the gate.
-- A bill without one is flagged on the AP screens (AP6).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE attachments (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL CHECK (record_type IN ('journal_entry', 'invoice', 'credit_note', 'receipt', 'deposit', 'bill', 'vendor_credit',
                                                       'payment', 'payment_run', 'transfer', 'reconciliation', 'customer', 'vendor', 'ai_statement')),
    record_id     bigint NOT NULL,
    filename      text NOT NULL,
    mime_type     text NOT NULL,
    byte_size     bigint NOT NULL CHECK (byte_size >= 0),
    sha256        text NOT NULL,
    storage_path  text NOT NULL,
    uploaded_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX attachments_record_idx ON attachments (record_type, record_id);
CREATE INDEX attachments_uploaded_by_idx ON attachments (uploaded_by);

CREATE TABLE notes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL,
    record_id     bigint NOT NULL,
    member_id     bigint REFERENCES members(id) ON DELETE SET NULL,
    body          text NOT NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notes_record_idx ON notes (record_type, record_id, created_at DESC);
CREATE INDEX notes_member_idx ON notes (member_id);

GRANT SELECT, INSERT, UPDATE, DELETE ON customers, vendors, party_contacts, attachments, notes TO gl_rw;

COMMIT;
