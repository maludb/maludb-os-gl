-- 013: CASH MANAGEMENT (design §6, the kernel's retired bank half kept and completed): bank accounts over an is_bank
-- ledger account; bank lines imported from a CSV (the file never stored) or entered by hand; a line MATCHED to one
-- ledger item of the same amount (a receipt, a deposit, a payment, a transfer, a journal entry's line on the account)
-- or IGNORED; transfers between accounts; a reconciliation against a statement balance that REFUSES to complete while
-- the difference is not zero, and stamps the lines it covers so they are never unmatched afterwards.
--
-- Signs: a bank line's amount is signed from the holder's side (+ in, − out). For an ASSET account the bank's balance
-- is opening + Σ amounts; for a LIABILITY account (a card) the balance OWED is opening − Σ amounts. A ledger item's
-- signed effect on the account is Σ(debit − credit) of its line on the bank's ledger account — the same for both.
BEGIN;

CREATE TABLE bank_accounts (
    id                        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                      text NOT NULL,
    kind                      text NOT NULL DEFAULT 'checking' CHECK (kind IN ('checking', 'savings', 'credit_card', 'cash', 'other')),
    account_id                bigint NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
    institution               text,
    account_last4             text,
    currency                  char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    opening_balance           numeric(14,2) NOT NULL DEFAULT 0,                  -- the bank's balance at the END of opening_date
    opening_date              date NOT NULL DEFAULT current_date,
    last_reconciled_through   date,
    last_reconciled_balance   numeric(14,2),
    archived_at               timestamptz,
    created_by                bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at                timestamptz NOT NULL DEFAULT now(),
    updated_at                timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX bank_accounts_name_live ON bank_accounts (lower(name)) WHERE archived_at IS NULL;
CREATE UNIQUE INDEX bank_accounts_account_live ON bank_accounts (account_id) WHERE archived_at IS NULL;
CREATE INDEX bank_accounts_created_by_idx ON bank_accounts (created_by);
CREATE TRIGGER bank_accounts_touch BEFORE UPDATE ON bank_accounts FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION gl_bank_account_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE a accounts%ROWTYPE;
BEGIN
    SELECT * INTO a FROM accounts WHERE id = NEW.account_id;
    IF NOT a.is_bank THEN RAISE EXCEPTION 'Account % % is not a bank account in the chart (is_bank)', a.code, a.name; END IF;
    IF NEW.kind = 'credit_card' AND a.account_type <> 'liability' THEN RAISE EXCEPTION 'A credit card stands behind a liability account, not %', a.account_type; END IF;
    IF NEW.kind <> 'credit_card' AND a.account_type <> 'asset' THEN RAISE EXCEPTION 'A % account stands behind an asset account, not %', NEW.kind, a.account_type; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER bank_accounts_guard BEFORE INSERT OR UPDATE ON bank_accounts FOR EACH ROW EXECUTE FUNCTION gl_bank_account_guard();

-- The foreign keys the earlier files left open.
ALTER TABLE gl_settings ADD CONSTRAINT gl_settings_default_bank_fk FOREIGN KEY (default_bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL;
CREATE INDEX gl_settings_default_bank_idx ON gl_settings (default_bank_account_id);
ALTER TABLE deposits ADD CONSTRAINT deposits_bank_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT;
ALTER TABLE receipts ADD CONSTRAINT receipts_bank_fk FOREIGN KEY (deposit_to_bank_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT;
ALTER TABLE payments ADD CONSTRAINT payments_bank_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT;
ALTER TABLE payment_runs ADD CONSTRAINT payment_runs_bank_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT;

CREATE TABLE bank_imports (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    bank_account_id  bigint NOT NULL REFERENCES bank_accounts(id) ON DELETE CASCADE,
    filename         text,
    row_count        integer NOT NULL DEFAULT 0,
    inserted_count   integer NOT NULL DEFAULT 0,
    skipped_count    integer NOT NULL DEFAULT 0,
    imported_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    deleted_at       timestamptz,
    created_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX bank_imports_account_idx ON bank_imports (bank_account_id, created_at DESC);
CREATE INDEX bank_imports_by_idx ON bank_imports (imported_by);

CREATE TABLE bank_reconciliations (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    bank_account_id    bigint NOT NULL REFERENCES bank_accounts(id) ON DELETE CASCADE,
    statement_date     date NOT NULL,
    statement_balance  numeric(14,2) NOT NULL,
    opening_balance    numeric(14,2) NOT NULL DEFAULT 0,                          -- the previous completed statement's balance, or the account's opening
    cleared_balance    numeric(14,2) NOT NULL DEFAULT 0,
    difference         numeric(14,2) NOT NULL DEFAULT 0,
    ledger_balance     numeric(14,2),
    status             text NOT NULL DEFAULT 'in_progress' CHECK (status IN ('in_progress', 'completed', 'reopened')),
    started_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    completed_at       timestamptz,
    completed_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    reopened_at        timestamptz,
    reopened_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    reopen_reason      text,
    note               text,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX bank_reconciliations_account_idx ON bank_reconciliations (bank_account_id, statement_date DESC);
CREATE UNIQUE INDEX bank_reconciliations_one_open ON bank_reconciliations (bank_account_id) WHERE status = 'in_progress';
CREATE INDEX bank_reconciliations_started_by_idx ON bank_reconciliations (started_by);
CREATE INDEX bank_reconciliations_completed_by_idx ON bank_reconciliations (completed_by);
CREATE INDEX bank_reconciliations_reopened_by_idx ON bank_reconciliations (reopened_by);
CREATE TRIGGER bank_reconciliations_touch BEFORE UPDATE ON bank_reconciliations FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE transfers (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number               text NOT NULL UNIQUE,                                   -- TRF-, at creation
    from_bank_account_id bigint NOT NULL REFERENCES bank_accounts(id) ON DELETE RESTRICT,
    to_bank_account_id   bigint NOT NULL REFERENCES bank_accounts(id) ON DELETE RESTRICT,
    transfer_date        date NOT NULL DEFAULT current_date,
    amount               numeric(14,2) NOT NULL CHECK (amount > 0),
    currency             char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    memo                 text,
    status               text NOT NULL DEFAULT 'recorded' CHECK (status IN ('recorded', 'void')),
    journal_entry_id     bigint REFERENCES journal_entries(id) ON DELETE SET NULL,
    recorded_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    voided_at            timestamptz,
    voided_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    void_reason          text,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    CHECK (from_bank_account_id <> to_bank_account_id)
);
CREATE INDEX transfers_from_idx ON transfers (from_bank_account_id, transfer_date DESC);
CREATE INDEX transfers_to_idx ON transfers (to_bank_account_id, transfer_date DESC);
CREATE INDEX transfers_entry_idx ON transfers (journal_entry_id);
CREATE INDEX transfers_recorded_by_idx ON transfers (recorded_by);
CREATE INDEX transfers_voided_by_idx ON transfers (voided_by);
CREATE TRIGGER transfers_touch BEFORE UPDATE ON transfers FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE bank_transactions (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    bank_account_id     bigint NOT NULL REFERENCES bank_accounts(id) ON DELETE CASCADE,
    import_id           bigint REFERENCES bank_imports(id) ON DELETE SET NULL,
    txn_date            date NOT NULL,
    amount              numeric(14,2) NOT NULL CHECK (amount <> 0),              -- signed: + in, − out
    description         text NOT NULL,
    counterparty        text,
    external_ref        text,                                                    -- the bank's own id: the duplicate guard
    balance_after       numeric(14,2),
    status              text NOT NULL DEFAULT 'unmatched' CHECK (status IN ('unmatched', 'matched', 'ignored')),
    matched_type        text CHECK (matched_type IN ('receipt', 'deposit', 'payment', 'transfer', 'journal_entry')),
    matched_id          bigint,
    matched_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    matched_at          timestamptz,
    ignore_reason       text,
    reconciliation_id   bigint REFERENCES bank_reconciliations(id) ON DELETE SET NULL,
    entered_by          bigint REFERENCES members(id) ON DELETE SET NULL,       -- a line typed in by hand
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK ((status = 'matched') = (matched_type IS NOT NULL AND matched_id IS NOT NULL))
);
CREATE UNIQUE INDEX bank_transactions_external_idx ON bank_transactions (bank_account_id, external_ref) WHERE external_ref IS NOT NULL;
CREATE UNIQUE INDEX bank_transactions_match_once ON bank_transactions (matched_type, matched_id) WHERE status = 'matched';
CREATE INDEX bank_transactions_open_idx ON bank_transactions (bank_account_id, txn_date DESC) WHERE status = 'unmatched';
CREATE INDEX bank_transactions_account_date_idx ON bank_transactions (bank_account_id, txn_date);
CREATE INDEX bank_transactions_import_idx ON bank_transactions (import_id);
CREATE INDEX bank_transactions_recon_idx ON bank_transactions (reconciliation_id);
CREATE INDEX bank_transactions_matched_by_idx ON bank_transactions (matched_by);
CREATE INDEX bank_transactions_entered_by_idx ON bank_transactions (entered_by);
CREATE TRIGGER bank_transactions_touch BEFORE UPDATE ON bank_transactions FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A reconciled line never changes or goes away.
CREATE OR REPLACE FUNCTION gl_bank_transaction_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.reconciliation_id IS NOT NULL THEN RAISE EXCEPTION 'Bank line % of % is reconciled and is never deleted', OLD.id, OLD.txn_date; END IF;
        IF OLD.status = 'matched' THEN RAISE EXCEPTION 'Bank line % is matched; unmatch it first', OLD.id; END IF;
        RETURN OLD;
    END IF;
    IF OLD.reconciliation_id IS NOT NULL AND row(NEW.txn_date, NEW.amount, NEW.status, NEW.matched_type, NEW.matched_id, NEW.bank_account_id)
       IS DISTINCT FROM row(OLD.txn_date, OLD.amount, OLD.status, OLD.matched_type, OLD.matched_id, OLD.bank_account_id)
       AND NEW.reconciliation_id IS NOT NULL THEN
        RAISE EXCEPTION 'Bank line % of % is reconciled; reopen the reconciliation first', OLD.id, OLD.txn_date;
    END IF;
    IF OLD.status = 'matched' AND NEW.status = 'matched' AND row(NEW.txn_date, NEW.amount, NEW.bank_account_id) IS DISTINCT FROM row(OLD.txn_date, OLD.amount, OLD.bank_account_id) THEN
        RAISE EXCEPTION 'Bank line % is matched; unmatch it before changing it', OLD.id;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER bank_transactions_guard BEFORE UPDATE OR DELETE ON bank_transactions FOR EACH ROW EXECUTE FUNCTION gl_bank_transaction_guard();

-- ---------------------------------------------------------------------------------------------
-- Balances.
-- ---------------------------------------------------------------------------------------------
-- The ledger's balance of a bank account as of a date, in the bank's own sense (an asset: what we hold; a card: what we owe).
-- The ledger alone: the opening balance entry (source 'opening') is what carries the bank's balance into the books, and it must
-- equal bank_accounts.opening_balance for the ledger and the bank to agree.
CREATE OR REPLACE FUNCTION gl_bank_ledger_balance(p_bank_account_id bigint, p_as_of date DEFAULT current_date) RETURNS numeric
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((
        SELECT sum(CASE WHEN a.account_type = 'asset' THEN l.debit - l.credit ELSE l.credit - l.debit END)
          FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.status IN ('posted', 'reversed')
         WHERE l.account_id = b.account_id AND e.entry_date <= p_as_of), 0)
      FROM bank_accounts b JOIN accounts a ON a.id = b.account_id WHERE b.id = p_bank_account_id;
$$;

-- What has cleared the bank per the lines we hold and have matched, as of a date: the bank's opening balance (its balance at the
-- END of opening_date) plus every matched line after that day.
CREATE OR REPLACE FUNCTION gl_bank_cleared_balance(p_bank_account_id bigint, p_as_of date DEFAULT current_date) RETURNS numeric
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT b.opening_balance + COALESCE((
        SELECT sum(CASE WHEN a.account_type = 'asset' THEN t.amount ELSE -t.amount END)
          FROM bank_transactions t WHERE t.bank_account_id = b.id AND t.status = 'matched' AND t.txn_date > b.opening_date AND t.txn_date <= p_as_of), 0)
      FROM bank_accounts b JOIN accounts a ON a.id = b.account_id WHERE b.id = p_bank_account_id;
$$;

-- The signed effect a ledger item has on a bank account: what a bank line must equal to match it. NULL = no such item here.
CREATE OR REPLACE FUNCTION gl_bank_item_amount(p_bank_account_id bigint, p_type text, p_id bigint) RETURNS numeric
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE amt numeric; acct bigint;
BEGIN
    SELECT account_id INTO acct FROM bank_accounts WHERE id = p_bank_account_id;
    CASE p_type
        WHEN 'receipt' THEN SELECT amount INTO amt FROM receipts WHERE id = p_id AND deposit_to_bank_account_id = p_bank_account_id AND status = 'recorded';
        WHEN 'deposit' THEN SELECT total INTO amt FROM deposits WHERE id = p_id AND bank_account_id = p_bank_account_id AND status = 'recorded';
        WHEN 'payment' THEN SELECT -amount INTO amt FROM payments WHERE id = p_id AND bank_account_id = p_bank_account_id AND status = 'recorded';
        WHEN 'transfer' THEN SELECT CASE WHEN from_bank_account_id = p_bank_account_id THEN -amount ELSE amount END INTO amt FROM transfers
                              WHERE id = p_id AND status = 'recorded' AND p_bank_account_id IN (from_bank_account_id, to_bank_account_id);
        WHEN 'journal_entry' THEN SELECT sum(l.debit - l.credit) INTO amt FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.status = 'posted'
                                   WHERE e.id = p_id AND l.account_id = acct;
        ELSE RAISE EXCEPTION 'Unknown item type %', p_type;
    END CASE;
    RETURN amt;
END$$;

-- Candidates for an unmatched line: same bank account, the same amount to the cent, within ±5 days, not matched yet.
CREATE OR REPLACE FUNCTION gl_bank_candidates(p_txn_id bigint)
    RETURNS TABLE (item_type text, item_id bigint, number text, item_date date, amount numeric, description text, days_apart integer)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE t bank_transactions%ROWTYPE; acct bigint;
BEGIN
    SELECT * INTO t FROM bank_transactions WHERE id = p_txn_id;
    IF t.id IS NULL THEN RETURN; END IF;
    SELECT account_id INTO acct FROM bank_accounts WHERE id = t.bank_account_id;
    RETURN QUERY
        SELECT 'receipt'::text, r.id, r.number, r.received_on, r.amount, 'Receipt from ' || c.name, abs(r.received_on - t.txn_date)
          FROM receipts r JOIN customers c ON c.id = r.customer_id
         WHERE r.deposit_to_bank_account_id = t.bank_account_id AND r.status = 'recorded' AND r.amount = t.amount
           AND abs(r.received_on - t.txn_date) <= 5 AND NOT EXISTS (SELECT 1 FROM bank_transactions m WHERE m.status = 'matched' AND m.matched_type = 'receipt' AND m.matched_id = r.id)
        UNION ALL
        SELECT 'deposit', d.id, d.number, d.deposit_date, d.total, 'Deposit of ' || (SELECT count(*) FROM receipts WHERE deposit_id = d.id) || ' receipts', abs(d.deposit_date - t.txn_date)
          FROM deposits d
         WHERE d.bank_account_id = t.bank_account_id AND d.status = 'recorded' AND d.total = t.amount
           AND abs(d.deposit_date - t.txn_date) <= 5 AND NOT EXISTS (SELECT 1 FROM bank_transactions m WHERE m.status = 'matched' AND m.matched_type = 'deposit' AND m.matched_id = d.id)
        UNION ALL
        SELECT 'payment', p.id, p.number, p.payment_date, -p.amount, 'Payment to ' || v.name || COALESCE(' #' || p.cheque_number, ''), abs(p.payment_date - t.txn_date)
          FROM payments p JOIN vendors v ON v.id = p.vendor_id
         WHERE p.bank_account_id = t.bank_account_id AND p.status = 'recorded' AND -p.amount = t.amount
           AND abs(p.payment_date - t.txn_date) <= 5 AND NOT EXISTS (SELECT 1 FROM bank_transactions m WHERE m.status = 'matched' AND m.matched_type = 'payment' AND m.matched_id = p.id)
        UNION ALL
        SELECT 'transfer', x.id, x.number, x.transfer_date, CASE WHEN x.from_bank_account_id = t.bank_account_id THEN -x.amount ELSE x.amount END,
               'Transfer ' || COALESCE(x.memo, ''), abs(x.transfer_date - t.txn_date)
          FROM transfers x
         WHERE x.status = 'recorded' AND t.bank_account_id IN (x.from_bank_account_id, x.to_bank_account_id)
           AND CASE WHEN x.from_bank_account_id = t.bank_account_id THEN -x.amount ELSE x.amount END = t.amount
           AND abs(x.transfer_date - t.txn_date) <= 5
           AND NOT EXISTS (SELECT 1 FROM bank_transactions m WHERE m.status = 'matched' AND m.matched_type = 'transfer' AND m.matched_id = x.id AND m.bank_account_id = t.bank_account_id)
        UNION ALL
        SELECT 'journal_entry', e.id, e.number, e.entry_date, s.amt, COALESCE(e.memo, 'Journal entry'), abs(e.entry_date - t.txn_date)
          FROM journal_entries e
          JOIN LATERAL (SELECT sum(l.debit - l.credit) AS amt FROM journal_lines l WHERE l.entry_id = e.id AND l.account_id = acct) s ON true
         WHERE e.status = 'posted' AND e.source IN ('manual', 'bank_fee', 'bank_interest', 'opening', 'recurring', 'import', 'payroll') AND s.amt = t.amount
           AND abs(e.entry_date - t.txn_date) <= 5 AND NOT EXISTS (SELECT 1 FROM bank_transactions m WHERE m.status = 'matched' AND m.matched_type = 'journal_entry' AND m.matched_id = e.id)
        ORDER BY 7, 4;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Matching.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION gl_bank_match(p_txn_id bigint, p_type text, p_id bigint, p_by bigint) RETURNS bank_transactions
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t bank_transactions%ROWTYPE; expected numeric;
BEGIN
    SELECT * INTO t FROM bank_transactions WHERE id = p_txn_id FOR UPDATE;
    IF t.id IS NULL THEN RAISE EXCEPTION 'No such bank line'; END IF;
    IF t.status <> 'unmatched' THEN RAISE EXCEPTION 'Bank line % is %', t.id, t.status; END IF;
    expected := gl_bank_item_amount(t.bank_account_id, p_type, p_id);
    IF expected IS NULL THEN RAISE EXCEPTION 'No recorded % % on this bank account', p_type, p_id; END IF;
    IF expected <> t.amount THEN RAISE EXCEPTION 'The % is % but the bank line is %: a match is to the cent', p_type, expected, t.amount; END IF;
    UPDATE bank_transactions SET status = 'matched', matched_type = p_type, matched_id = p_id, matched_by = p_by, matched_at = now() WHERE id = t.id RETURNING * INTO t;
    RETURN t;
END$$;

CREATE OR REPLACE FUNCTION gl_bank_unmatch(p_txn_id bigint) RETURNS bank_transactions
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t bank_transactions%ROWTYPE;
BEGIN
    SELECT * INTO t FROM bank_transactions WHERE id = p_txn_id FOR UPDATE;
    IF t.id IS NULL THEN RAISE EXCEPTION 'No such bank line'; END IF;
    IF t.reconciliation_id IS NOT NULL THEN RAISE EXCEPTION 'Bank line % is reconciled; reopen the reconciliation first', t.id; END IF;
    UPDATE bank_transactions SET status = 'unmatched', matched_type = NULL, matched_id = NULL, matched_by = NULL, matched_at = NULL, ignore_reason = NULL
     WHERE id = t.id RETURNING * INTO t;
    RETURN t;
END$$;

CREATE OR REPLACE FUNCTION gl_bank_ignore(p_txn_id bigint, p_by bigint, p_reason text) RETURNS bank_transactions
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t bank_transactions%ROWTYPE;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'Ignoring a bank line needs a reason'; END IF;
    SELECT * INTO t FROM bank_transactions WHERE id = p_txn_id FOR UPDATE;
    IF t.id IS NULL THEN RAISE EXCEPTION 'No such bank line'; END IF;
    IF t.status <> 'unmatched' THEN RAISE EXCEPTION 'Bank line % is %', t.id, t.status; END IF;
    UPDATE bank_transactions SET status = 'ignored', ignore_reason = p_reason, matched_by = p_by, matched_at = now() WHERE id = t.id RETURNING * INTO t;
    RETURN t;
END$$;

-- A fee, interest or any other line the ledger does not yet hold: one posted entry against the account named, matched at once.
CREATE OR REPLACE FUNCTION gl_bank_entry_from_line(p_txn_id bigint, p_account_id bigint, p_by bigint, p_memo text DEFAULT NULL,
                                                   p_department_id bigint DEFAULT NULL, p_site_id bigint DEFAULT NULL) RETURNS journal_entries
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t bank_transactions%ROWTYPE; b bank_accounts%ROWTYPE; e journal_entries%ROWTYPE; src text; a accounts%ROWTYPE;
BEGIN
    SELECT * INTO t FROM bank_transactions WHERE id = p_txn_id FOR UPDATE;
    IF t.id IS NULL THEN RAISE EXCEPTION 'No such bank line'; END IF;
    IF t.status <> 'unmatched' THEN RAISE EXCEPTION 'Bank line % is %', t.id, t.status; END IF;
    SELECT * INTO b FROM bank_accounts WHERE id = t.bank_account_id;
    SELECT * INTO a FROM accounts WHERE id = p_account_id;
    IF a.id IS NULL THEN RAISE EXCEPTION 'No such account'; END IF;
    IF a.is_control THEN RAISE EXCEPTION 'Account % is a control account; record a receipt or a payment instead', a.code; END IF;
    src := CASE WHEN a.account_type = 'expense' AND t.amount < 0 THEN 'bank_fee' WHEN a.account_type = 'income' AND t.amount > 0 THEN 'bank_interest' ELSE 'manual' END;
    INSERT INTO journal_entries (entry_date, memo, source, source_type, source_id, currency, department_id, site_id, created_by)
    VALUES (t.txn_date, COALESCE(p_memo, t.description), src, 'bank_transaction', t.id, b.currency, p_department_id, p_site_id, p_by)
    RETURNING * INTO e;
    IF t.amount > 0 THEN
        INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, department_id, site_id) VALUES
            (e.id, 1, b.account_id, b.name, t.amount, 0, p_department_id, p_site_id),
            (e.id, 2, p_account_id, t.description, 0, t.amount, p_department_id, p_site_id);
    ELSE
        INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, department_id, site_id) VALUES
            (e.id, 1, p_account_id, t.description, -t.amount, 0, p_department_id, p_site_id),
            (e.id, 2, b.account_id, b.name, 0, -t.amount, p_department_id, p_site_id);
    END IF;
    e := gl_entry_post(e.id, p_by);
    PERFORM gl_bank_match(t.id, 'journal_entry', e.id, p_by);
    RETURN e;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Reconciliation.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION gl_reconciliation_recompute(p_id bigint) RETURNS bank_reconciliations
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r bank_reconciliations%ROWTYPE;
BEGIN
    SELECT * INTO r FROM bank_reconciliations WHERE id = p_id FOR UPDATE;
    IF r.id IS NULL THEN RAISE EXCEPTION 'No such reconciliation'; END IF;
    UPDATE bank_reconciliations
       SET cleared_balance = gl_bank_cleared_balance(r.bank_account_id, r.statement_date),
           difference = r.statement_balance - gl_bank_cleared_balance(r.bank_account_id, r.statement_date),
           ledger_balance = gl_bank_ledger_balance(r.bank_account_id, r.statement_date)
     WHERE id = r.id RETURNING * INTO r;
    RETURN r;
END$$;

CREATE OR REPLACE FUNCTION gl_reconciliation_start(p_bank_account_id bigint, p_statement_date date, p_statement_balance numeric, p_by bigint) RETURNS bank_reconciliations
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r bank_reconciliations%ROWTYPE; b bank_accounts%ROWTYPE; opening numeric;
BEGIN
    SELECT * INTO b FROM bank_accounts WHERE id = p_bank_account_id FOR UPDATE;
    IF b.id IS NULL THEN RAISE EXCEPTION 'No such bank account'; END IF;
    IF EXISTS (SELECT 1 FROM bank_reconciliations WHERE bank_account_id = b.id AND status = 'in_progress') THEN
        RAISE EXCEPTION 'A reconciliation of % is already in progress', b.name;
    END IF;
    IF b.last_reconciled_through IS NOT NULL AND p_statement_date <= b.last_reconciled_through THEN
        RAISE EXCEPTION '% is reconciled through %; a new statement is dated after it', b.name, b.last_reconciled_through;
    END IF;
    opening := COALESCE(b.last_reconciled_balance, b.opening_balance);
    INSERT INTO bank_reconciliations (bank_account_id, statement_date, statement_balance, opening_balance, started_by)
    VALUES (b.id, p_statement_date, p_statement_balance, opening, p_by) RETURNING * INTO r;
    RETURN gl_reconciliation_recompute(r.id);
END$$;

CREATE OR REPLACE FUNCTION gl_reconciliation_complete(p_id bigint, p_by bigint, p_note text DEFAULT NULL) RETURNS bank_reconciliations
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r bank_reconciliations%ROWTYPE;
BEGIN
    r := gl_reconciliation_recompute(p_id);
    IF r.status <> 'in_progress' THEN RAISE EXCEPTION 'Reconciliation % is %', r.id, r.status; END IF;
    IF r.difference <> 0 THEN
        RAISE EXCEPTION 'The reconciliation is out by %: the statement says % and the matched lines say %. Match or ignore every line through % first',
            r.difference, r.statement_balance, r.cleared_balance, r.statement_date;
    END IF;
    UPDATE bank_transactions SET reconciliation_id = r.id
     WHERE bank_account_id = r.bank_account_id AND status IN ('matched', 'ignored') AND txn_date <= r.statement_date AND reconciliation_id IS NULL;
    UPDATE bank_reconciliations SET status = 'completed', completed_at = now(), completed_by = p_by, note = COALESCE(p_note, note) WHERE id = r.id RETURNING * INTO r;
    UPDATE bank_accounts SET last_reconciled_through = r.statement_date, last_reconciled_balance = r.statement_balance WHERE id = r.bank_account_id;
    RETURN r;
END$$;

CREATE OR REPLACE FUNCTION gl_reconciliation_reopen(p_id bigint, p_by bigint, p_reason text) RETURNS bank_reconciliations
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r bank_reconciliations%ROWTYPE; prev bank_reconciliations%ROWTYPE;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'Reopening a reconciliation needs a reason'; END IF;
    SELECT * INTO r FROM bank_reconciliations WHERE id = p_id FOR UPDATE;
    IF r.id IS NULL THEN RAISE EXCEPTION 'No such reconciliation'; END IF;
    IF r.status <> 'completed' THEN RAISE EXCEPTION 'Reconciliation % is %', r.id, r.status; END IF;
    IF EXISTS (SELECT 1 FROM bank_reconciliations WHERE bank_account_id = r.bank_account_id AND status = 'completed' AND statement_date > r.statement_date) THEN
        RAISE EXCEPTION 'A later reconciliation is completed; reopen the latest first';
    END IF;
    IF EXISTS (SELECT 1 FROM bank_reconciliations WHERE bank_account_id = r.bank_account_id AND status = 'in_progress') THEN
        RAISE EXCEPTION 'A reconciliation is in progress; finish or abandon it first';
    END IF;
    UPDATE bank_transactions SET reconciliation_id = NULL WHERE reconciliation_id = r.id;
    UPDATE bank_reconciliations SET status = 'reopened', reopened_at = now(), reopened_by = p_by, reopen_reason = p_reason WHERE id = r.id RETURNING * INTO r;
    SELECT * INTO prev FROM bank_reconciliations WHERE bank_account_id = r.bank_account_id AND status = 'completed' ORDER BY statement_date DESC LIMIT 1;
    UPDATE bank_accounts SET last_reconciled_through = prev.statement_date, last_reconciled_balance = prev.statement_balance WHERE id = r.bank_account_id;
    RETURN r;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The postings that needed a bank account: a receipt straight to a bank, a deposit, a payment, a transfer.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION gl_receipt_post(p_receipt_id bigint, p_by bigint) RETURNS receipts
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r receipts%ROWTYPE; rule posting_rules%ROWTYPE; ar bigint; e journal_entries%ROWTYPE; dr bigint; drname text;
BEGIN
    SELECT * INTO r FROM receipts WHERE id = p_receipt_id FOR UPDATE;
    IF r.id IS NULL THEN RAISE EXCEPTION 'No such receipt'; END IF;
    IF r.journal_entry_id IS NOT NULL THEN RETURN r; END IF;
    IF r.status <> 'recorded' THEN RAISE EXCEPTION 'Receipt % is void', r.number; END IF;
    rule := gl_posting_rule('receipt');
    IF rule.id IS NULL THEN RAISE EXCEPTION 'No posting rule for receipts'; END IF;
    SELECT ar_account_id INTO ar FROM gl_settings WHERE id = 1;
    IF r.deposit_to_bank_account_id IS NOT NULL THEN
        SELECT account_id, name INTO dr, drname FROM bank_accounts WHERE id = r.deposit_to_bank_account_id;
    ELSE
        dr := rule.debit_account_id; drname := 'Undeposited funds';
    END IF;
    INSERT INTO journal_entries (entry_date, memo, source, source_type, source_id, currency, created_by)
    VALUES (r.received_on, 'Receipt ' || r.number || ' from ' || (SELECT name FROM customers WHERE id = r.customer_id), 'receipt', 'receipt', r.id, r.currency, p_by)
    RETURNING * INTO e;
    INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, customer_id) VALUES
        (e.id, 1, dr, drname, r.amount, 0, NULL),
        (e.id, 2, COALESCE(rule.credit_account_id, ar), 'Receivable', 0, r.amount, r.customer_id);
    PERFORM gl_entry_post(e.id, p_by);
    UPDATE receipts SET journal_entry_id = e.id WHERE id = r.id RETURNING * INTO r;
    RETURN r;
END$$;

CREATE OR REPLACE FUNCTION gl_deposit_record(p_deposit_id bigint, p_by bigint) RETURNS deposits
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d deposits%ROWTYPE; rule posting_rules%ROWTYPE; e journal_entries%ROWTYPE; bank bigint; bname text; uf bigint;
BEGIN
    SELECT * INTO d FROM deposits WHERE id = p_deposit_id FOR UPDATE;
    IF d.id IS NULL THEN RAISE EXCEPTION 'No such deposit'; END IF;
    IF d.status <> 'draft' THEN RAISE EXCEPTION 'Deposit % is already %', d.number, d.status; END IF;
    IF d.total <= 0 THEN RAISE EXCEPTION 'Deposit % has no receipts', d.number; END IF;
    rule := gl_posting_rule('deposit');
    SELECT undeposited_funds_account_id INTO uf FROM gl_settings WHERE id = 1;
    SELECT account_id, name INTO bank, bname FROM bank_accounts WHERE id = d.bank_account_id;
    INSERT INTO journal_entries (entry_date, memo, source, source_type, source_id, currency, created_by)
    VALUES (d.deposit_date, 'Deposit ' || d.number || ' to ' || bname, 'deposit', 'deposit', d.id, d.currency, p_by) RETURNING * INTO e;
    INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit) VALUES
        (e.id, 1, bank, bname, d.total, 0),
        (e.id, 2, COALESCE(rule.credit_account_id, uf), 'Undeposited funds', 0, d.total);
    PERFORM gl_entry_post(e.id, p_by);
    UPDATE deposits SET status = 'recorded', recorded_at = now(), recorded_by = p_by, journal_entry_id = e.id WHERE id = d.id RETURNING * INTO d;
    RETURN d;
END$$;

CREATE OR REPLACE FUNCTION gl_deposit_void(p_deposit_id bigint, p_by bigint, p_reason text) RETURNS deposits
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d deposits%ROWTYPE;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'Voiding a deposit needs a reason'; END IF;
    SELECT * INTO d FROM deposits WHERE id = p_deposit_id FOR UPDATE;
    IF d.id IS NULL THEN RAISE EXCEPTION 'No such deposit'; END IF;
    IF EXISTS (SELECT 1 FROM bank_transactions WHERE status = 'matched' AND matched_type = 'deposit' AND matched_id = d.id) THEN
        RAISE EXCEPTION 'Deposit % is matched to a bank line; unmatch it first', d.number;
    END IF;
    UPDATE deposits SET status = 'void', voided_at = now(), voided_by = p_by, void_reason = p_reason WHERE id = d.id RETURNING * INTO d;
    UPDATE receipts SET deposit_id = NULL WHERE deposit_id = d.id;   -- the receipts return to undeposited funds (the guard allows: leaving a... void deposit)
    IF d.journal_entry_id IS NOT NULL THEN PERFORM gl_entry_reverse(d.journal_entry_id, p_by, 'Deposit ' || d.number || ' voided: ' || p_reason); END IF;
    RETURN d;
END$$;

CREATE OR REPLACE FUNCTION gl_payment_record(p_payment_id bigint, p_by bigint) RETURNS payments
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p payments%ROWTYPE; rule posting_rules%ROWTYPE; ap bigint; e journal_entries%ROWTYPE; bank bigint; bname text; bid bigint;
BEGIN
    SELECT * INTO p FROM payments WHERE id = p_payment_id FOR UPDATE;
    IF p.id IS NULL THEN RAISE EXCEPTION 'No such payment'; END IF;
    IF p.status <> 'approved' THEN RAISE EXCEPTION 'Payment % is %; it must be approved before it is recorded', p.number, p.status; END IF;
    rule := gl_posting_rule('payment');
    SELECT ap_account_id INTO ap FROM gl_settings WHERE id = 1;
    SELECT account_id, name INTO bank, bname FROM bank_accounts WHERE id = p.bank_account_id;
    INSERT INTO journal_entries (entry_date, memo, source, source_type, source_id, currency, created_by)
    VALUES (p.payment_date, 'Payment ' || p.number || ' to ' || (SELECT name FROM vendors WHERE id = p.vendor_id) || COALESCE(' #' || p.cheque_number, ''), 'payment', 'payment', p.id, p.currency, p_by)
    RETURNING * INTO e;
    INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, vendor_id) VALUES
        (e.id, 1, COALESCE(rule.debit_account_id, ap), 'Payable', p.amount, 0, p.vendor_id),
        (e.id, 2, bank, bname, 0, p.amount, NULL);
    PERFORM gl_entry_post(e.id, p_by);
    UPDATE payments SET status = 'recorded', recorded_at = now(), recorded_by = p_by, journal_entry_id = e.id WHERE id = p.id RETURNING * INTO p;
    FOR bid IN SELECT bill_id FROM payment_applications WHERE payment_id = p.id LOOP PERFORM gl_bill_recalculate(bid); END LOOP;
    RETURN p;
END$$;

CREATE OR REPLACE FUNCTION gl_payment_run_record(p_run_id bigint, p_by bigint) RETURNS payment_runs
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r payment_runs%ROWTYPE; pid bigint;
BEGIN
    SELECT * INTO r FROM payment_runs WHERE id = p_run_id FOR UPDATE;
    IF r.id IS NULL THEN RAISE EXCEPTION 'No such payment run'; END IF;
    IF r.status <> 'approved' THEN RAISE EXCEPTION 'Payment run % is %; it must be approved before it is recorded', r.number, r.status; END IF;
    FOR pid IN SELECT id FROM payments WHERE payment_run_id = r.id AND status = 'approved' LOOP
        PERFORM gl_payment_record(pid, p_by);
    END LOOP;
    UPDATE payment_runs SET status = 'recorded', recorded_at = now(), recorded_by = p_by WHERE id = r.id RETURNING * INTO r;
    RETURN r;
END$$;

CREATE OR REPLACE FUNCTION gl_payment_void(p_payment_id bigint, p_by bigint, p_reason text) RETURNS payments
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p payments%ROWTYPE; bid bigint;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'Voiding a payment needs a reason'; END IF;
    SELECT * INTO p FROM payments WHERE id = p_payment_id FOR UPDATE;
    IF p.id IS NULL THEN RAISE EXCEPTION 'No such payment'; END IF;
    IF EXISTS (SELECT 1 FROM bank_transactions WHERE status = 'matched' AND matched_type = 'payment' AND matched_id = p.id) THEN
        RAISE EXCEPTION 'Payment % is matched to a bank line; unmatch it first', p.number;
    END IF;
    UPDATE payments SET status = 'void', voided_by = p_by, void_reason = p_reason WHERE id = p.id RETURNING * INTO p;
    IF p.journal_entry_id IS NOT NULL THEN PERFORM gl_entry_reverse(p.journal_entry_id, p_by, 'Payment ' || p.number || ' voided: ' || p_reason); END IF;
    FOR bid IN SELECT bill_id FROM payment_applications WHERE payment_id = p.id LOOP PERFORM gl_bill_recalculate(bid); END LOOP;
    RETURN p;
END$$;

CREATE OR REPLACE FUNCTION gl_transfer_record(p_from bigint, p_to bigint, p_date date, p_amount numeric, p_memo text, p_by bigint) RETURNS transfers
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE x transfers%ROWTYPE; e journal_entries%ROWTYPE; f bank_accounts%ROWTYPE; t bank_accounts%ROWTYPE;
BEGIN
    SELECT * INTO f FROM bank_accounts WHERE id = p_from; SELECT * INTO t FROM bank_accounts WHERE id = p_to;
    IF f.id IS NULL OR t.id IS NULL THEN RAISE EXCEPTION 'No such bank account'; END IF;
    IF f.currency <> t.currency THEN RAISE EXCEPTION 'A transfer between currencies is not recorded here'; END IF;
    INSERT INTO transfers (number, from_bank_account_id, to_bank_account_id, transfer_date, amount, currency, memo, recorded_by)
    VALUES (next_document_number('transfer'), p_from, p_to, p_date, p_amount, f.currency, p_memo, p_by) RETURNING * INTO x;
    INSERT INTO journal_entries (entry_date, memo, source, source_type, source_id, currency, created_by)
    VALUES (p_date, 'Transfer ' || x.number || ' ' || f.name || ' → ' || t.name || COALESCE(': ' || p_memo, ''), 'transfer', 'transfer', x.id, f.currency, p_by) RETURNING * INTO e;
    INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit) VALUES
        (e.id, 1, t.account_id, t.name, p_amount, 0),
        (e.id, 2, f.account_id, f.name, 0, p_amount);
    PERFORM gl_entry_post(e.id, p_by);
    UPDATE transfers SET journal_entry_id = e.id WHERE id = x.id RETURNING * INTO x;
    RETURN x;
END$$;

CREATE OR REPLACE FUNCTION gl_transfer_void(p_id bigint, p_by bigint, p_reason text) RETURNS transfers
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE x transfers%ROWTYPE;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'Voiding a transfer needs a reason'; END IF;
    SELECT * INTO x FROM transfers WHERE id = p_id FOR UPDATE;
    IF x.id IS NULL THEN RAISE EXCEPTION 'No such transfer'; END IF;
    IF x.status = 'void' THEN RAISE EXCEPTION 'Transfer % is already void', x.number; END IF;
    IF EXISTS (SELECT 1 FROM bank_transactions WHERE status = 'matched' AND matched_type = 'transfer' AND matched_id = x.id) THEN
        RAISE EXCEPTION 'Transfer % is matched to a bank line; unmatch it first', x.number;
    END IF;
    UPDATE transfers SET status = 'void', voided_at = now(), voided_by = p_by, void_reason = p_reason WHERE id = x.id RETURNING * INTO x;
    IF x.journal_entry_id IS NOT NULL THEN PERFORM gl_entry_reverse(x.journal_entry_id, p_by, 'Transfer ' || x.number || ' voided: ' || p_reason); END IF;
    RETURN x;
END$$;

REVOKE ALL ON FUNCTION gl_bank_match(bigint, text, bigint, bigint), gl_bank_unmatch(bigint), gl_bank_ignore(bigint, bigint, text),
    gl_bank_entry_from_line(bigint, bigint, bigint, text, bigint, bigint), gl_reconciliation_recompute(bigint),
    gl_reconciliation_start(bigint, date, numeric, bigint), gl_reconciliation_complete(bigint, bigint, text), gl_reconciliation_reopen(bigint, bigint, text),
    gl_receipt_post(bigint, bigint), gl_deposit_record(bigint, bigint), gl_deposit_void(bigint, bigint, text), gl_payment_record(bigint, bigint),
    gl_payment_run_record(bigint, bigint), gl_payment_void(bigint, bigint, text), gl_transfer_record(bigint, bigint, date, numeric, text, bigint),
    gl_transfer_void(bigint, bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION gl_bank_match(bigint, text, bigint, bigint), gl_bank_unmatch(bigint), gl_bank_ignore(bigint, bigint, text),
    gl_bank_entry_from_line(bigint, bigint, bigint, text, bigint, bigint), gl_reconciliation_recompute(bigint),
    gl_reconciliation_start(bigint, date, numeric, bigint), gl_reconciliation_complete(bigint, bigint, text), gl_reconciliation_reopen(bigint, bigint, text),
    gl_receipt_post(bigint, bigint), gl_deposit_record(bigint, bigint), gl_deposit_void(bigint, bigint, text), gl_payment_record(bigint, bigint),
    gl_payment_run_record(bigint, bigint), gl_payment_void(bigint, bigint, text), gl_transfer_record(bigint, bigint, date, numeric, text, bigint),
    gl_transfer_void(bigint, bigint, text) TO gl_rw;
GRANT EXECUTE ON FUNCTION gl_bank_ledger_balance(bigint, date), gl_bank_cleared_balance(bigint, date), gl_bank_item_amount(bigint, text, bigint),
    gl_bank_candidates(bigint) TO gl_rw, gl_records_ro;
GRANT SELECT, INSERT, UPDATE, DELETE ON bank_accounts, bank_imports, bank_reconciliations, transfers, bank_transactions TO gl_rw;

COMMIT;
