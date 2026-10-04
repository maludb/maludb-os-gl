-- 005: THE CHART OF ACCOUNTS and the POSTING RULES (design §6, D11).
--
-- An account has a type (the five), a subtype (what the statements group by — the cash flow statement classifies
-- balance-sheet movements by it), a normal balance derived from the type, and three flags: is_bank (a bank account
-- in db/013 stands behind it), is_control (a subledger total — AR, AP — that takes no line without its party, db/008),
-- is_system (seeded; renameable, never deleted). A posting rule says which accounts a SOURCE document finds when it
-- posts; the app reads the rules, never hard-codes an account (GL13). A source with no matching rule is skipped and
-- reported, never guessed.
BEGIN;

CREATE TABLE account_types (
    account_type   text PRIMARY KEY CHECK (account_type IN ('asset', 'liability', 'equity', 'income', 'expense')),
    label          text NOT NULL,
    normal_balance text NOT NULL CHECK (normal_balance IN ('debit', 'credit')),
    statement      text NOT NULL CHECK (statement IN ('balance_sheet', 'profit_and_loss')),
    sort_order     integer NOT NULL
);
INSERT INTO account_types VALUES
 ('asset',     'Assets',      'debit',  'balance_sheet',   1),
 ('liability', 'Liabilities', 'credit', 'balance_sheet',   2),
 ('equity',    'Equity',      'credit', 'balance_sheet',   3),
 ('income',    'Income',      'credit', 'profit_and_loss', 4),
 ('expense',   'Expenses',    'debit',  'profit_and_loss', 5);

CREATE TABLE accounts (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code           text NOT NULL UNIQUE CHECK (code ~ '^[0-9]{3,8}(\.[0-9]{1,4})?$'),   -- '1200', '1200.10'
    name           text NOT NULL,
    account_type   text NOT NULL REFERENCES account_types(account_type),
    subtype        text NOT NULL DEFAULT 'other'
                   CHECK (subtype IN ('cash', 'bank', 'undeposited', 'receivable', 'inventory', 'prepaid', 'current_asset', 'fixed',
                                      'other_asset', 'payable', 'credit_card', 'tax', 'payroll', 'accrued', 'current_liability',
                                      'loan', 'other_liability', 'owner', 'retained', 'other_equity',
                                      'revenue', 'other_income', 'cogs', 'operating', 'bad_debt', 'other_expense', 'other')),
    parent_id      bigint REFERENCES accounts(id) ON DELETE RESTRICT,
    is_bank        boolean NOT NULL DEFAULT false,
    is_control     boolean NOT NULL DEFAULT false,
    is_system      boolean NOT NULL DEFAULT false,
    description    text,
    archived_at    timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX accounts_type_idx   ON accounts (account_type, code) WHERE archived_at IS NULL;
CREATE INDEX accounts_parent_idx ON accounts (parent_id);
CREATE INDEX accounts_name_trgm_idx ON accounts USING gin (name gin_trgm_ops);
CREATE TRIGGER accounts_touch BEFORE UPDATE ON accounts FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A parent is of the same type; a system account keeps its type and is never deleted; a control account is never
-- a bank and a bank is never a control.
CREATE OR REPLACE FUNCTION gl_account_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE pt text;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.is_system THEN
            RAISE EXCEPTION 'Account % % is a system account: rename or archive it, never delete it', OLD.code, OLD.name;
        END IF;
        RETURN OLD;
    END IF;
    IF NEW.is_bank AND NEW.is_control THEN
        RAISE EXCEPTION 'Account % cannot be both a bank account and a control account', NEW.code;
    END IF;
    IF NEW.parent_id IS NOT NULL THEN
        IF NEW.parent_id = NEW.id THEN
            RAISE EXCEPTION 'Account % cannot be its own parent', NEW.code;
        END IF;
        SELECT account_type INTO pt FROM accounts WHERE id = NEW.parent_id;
        IF pt IS DISTINCT FROM NEW.account_type THEN
            RAISE EXCEPTION 'Account % (%) cannot sit under a % account', NEW.code, NEW.account_type, pt;
        END IF;
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.is_system AND NEW.account_type <> OLD.account_type THEN
        RAISE EXCEPTION 'Account % is a system account and keeps its type (%)', OLD.code, OLD.account_type;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER accounts_guard BEFORE INSERT OR UPDATE OR DELETE ON accounts FOR EACH ROW EXECUTE FUNCTION gl_account_guard();

-- Normal balance of an account, from its type.
CREATE OR REPLACE FUNCTION gl_normal_balance(p_type text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN p_type IN ('asset', 'expense') THEN 'debit' ELSE 'credit' END;
$$;

-- ---------------------------------------------------------------------------------------------
-- Posting rules: source × match key → the accounts. The NULL match key is the default for the source; a keyed row
-- (an item id, a vendor id, a provider key) overrides it. tax_account_id is where the document's tax goes when the
-- source carries tax (invoice, credit_note); NULL = no tax line (D11: purchase tax posts gross).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE posting_rules (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source            text NOT NULL CHECK (source IN ('invoice', 'credit_note', 'receipt', 'deposit', 'write_off',
                                                      'bill', 'vendor_credit', 'payment', 'ai_usage', 'payroll',
                                                      'bank_fee', 'bank_interest', 'transfer', 'opening')),
    match_key         text,
    debit_account_id  bigint REFERENCES accounts(id) ON DELETE RESTRICT,
    credit_account_id bigint REFERENCES accounts(id) ON DELETE RESTRICT,
    tax_account_id    bigint REFERENCES accounts(id) ON DELETE RESTRICT,
    note              text,
    active            boolean NOT NULL DEFAULT true,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX posting_rules_key ON posting_rules (source, COALESCE(match_key, ''));
CREATE INDEX posting_rules_debit_idx  ON posting_rules (debit_account_id);
CREATE INDEX posting_rules_credit_idx ON posting_rules (credit_account_id);
CREATE INDEX posting_rules_tax_idx    ON posting_rules (tax_account_id);
CREATE TRIGGER posting_rules_touch BEFORE UPDATE ON posting_rules FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The rule a source finds: the keyed row, else the default. NULL when neither exists (the caller skips and reports).
CREATE OR REPLACE FUNCTION gl_posting_rule(p_source text, p_match_key text DEFAULT NULL)
    RETURNS posting_rules LANGUAGE sql STABLE AS $$
    SELECT r.* FROM posting_rules r
     WHERE r.source = p_source AND r.active
       AND (r.match_key = p_match_key OR r.match_key IS NULL)
     ORDER BY (r.match_key IS NOT NULL) DESC
     LIMIT 1;
$$;

-- ---------------------------------------------------------------------------------------------
-- The seeded chart (D11): the kernel's retired 21 accounts plus Undeposited funds, Credit card, Bad debt and Other
-- income. Renameable; system accounts undeletable. US-style sales tax: output tax to 2100; purchase tax posts gross.
-- ---------------------------------------------------------------------------------------------
INSERT INTO accounts (code, name, account_type, subtype, is_bank, is_control, is_system) VALUES
    ('1000', 'Cash on hand',               'asset',     'cash',          true,  false, true),
    ('1010', 'Business checking',          'asset',     'bank',          true,  false, true),
    ('1050', 'Undeposited funds',          'asset',     'undeposited',   false, false, true),
    ('1200', 'Accounts receivable',        'asset',     'receivable',    false, true,  true),
    ('1400', 'Inventory',                  'asset',     'inventory',     false, false, true),
    ('1500', 'Fixed assets',               'asset',     'fixed',         false, false, true),
    ('2000', 'Accounts payable',           'liability', 'payable',       false, true,  true),
    ('2100', 'Sales tax payable',          'liability', 'tax',           false, false, true),
    ('2200', 'Payroll liabilities',        'liability', 'payroll',       false, false, true),
    ('2300', 'Credit card',                'liability', 'credit_card',   true,  false, true),
    ('3000', 'Owner equity',               'equity',    'owner',         false, false, true),
    ('3900', 'Retained earnings',          'equity',    'retained',      false, false, true),
    ('4000', 'Sales revenue',              'income',    'revenue',       false, false, true),
    ('4100', 'Service revenue',            'income',    'revenue',       false, false, true),
    ('5000', 'Cost of goods sold',         'expense',   'cogs',          false, false, true),
    ('6000', 'Salaries and wages',         'expense',   'operating',     false, false, true),
    ('6050', 'AI and model usage',         'expense',   'operating',     false, false, true),
    ('6100', 'Rent',                       'expense',   'operating',     false, false, true),
    ('6200', 'Software and subscriptions', 'expense',   'operating',     false, false, true),
    ('6300', 'Professional services',      'expense',   'operating',     false, false, true),
    ('6400', 'Advertising and marketing',  'expense',   'operating',     false, false, true),
    ('6500', 'Bank and payment fees',      'expense',   'operating',     false, false, true),
    ('6600', 'Bad debt',                   'expense',   'bad_debt',      false, false, true),
    ('6900', 'Other expenses',             'expense',   'other_expense', false, false, true),
    ('7000', 'Other income',               'income',    'other_income',  false, false, true)
ON CONFLICT (code) DO NOTHING;

INSERT INTO posting_rules (source, match_key, debit_account_id, credit_account_id, tax_account_id, note)
SELECT v.source, NULL,
       (SELECT id FROM accounts WHERE code = v.debit),
       (SELECT id FROM accounts WHERE code = v.credit),
       (SELECT id FROM accounts WHERE code = v.tax), v.note
FROM (VALUES
    ('invoice',       '1200', '4100', '2100', 'Invoice issued: receivable against service revenue; the tax to sales tax payable'),
    ('credit_note',   '4100', '1200', '2100', 'Credit note issued: revenue reversed; the tax out of sales tax payable'),
    ('receipt',       '1050', '1200', NULL,   'Customer money received: undeposited funds against receivable (a bank deposit-to overrides the debit)'),
    ('deposit',       '1010', '1050', NULL,   'Receipts deposited: bank against undeposited funds'),
    ('write_off',     '6600', '1200', NULL,   'Invoice written off: bad debt against receivable'),
    ('bill',          '6900', '2000', NULL,   'Bill approved: expense (each line names its own account) against payable, gross'),
    ('vendor_credit', '2000', '6900', NULL,   'Vendor credit issued: payable reduced'),
    ('payment',       '2000', '1010', NULL,   'Vendor paid: payable against the bank account the payment names'),
    ('ai_usage',      '6050', '2000', NULL,   'The kernel''s AI statement: AI and model usage by department against payable, one bill per provider'),
    ('payroll',       '6000', '2200', NULL,   'Pay run: wages against payroll liabilities'),
    ('bank_fee',      '6500', '1010', NULL,   'Bank or card fee seen on the statement'),
    ('bank_interest', '1010', '7000', NULL,   'Interest seen on the statement'),
    ('transfer',      '1010', '1010', NULL,   'Between two bank accounts (the transfer names both; the rule is a placeholder)'),
    ('opening',       '1010', '3000', NULL,   'Opening balances against owner equity')
) AS v(source, debit, credit, tax, note)
ON CONFLICT DO NOTHING;

GRANT SELECT, INSERT, UPDATE, DELETE ON accounts, posting_rules TO gl_rw;
GRANT SELECT ON account_types TO gl_rw, gl_records_ro;
GRANT EXECUTE ON FUNCTION gl_normal_balance(text), gl_posting_rule(text, text) TO gl_rw, gl_records_ro;

COMMIT;
