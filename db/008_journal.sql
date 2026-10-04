-- 008: THE JOURNAL (design §6) — what the database enforces, so PHP never re-implements it:
--   · an entry is born a draft; its period follows its date while it is a draft;
--   · posting needs lines, a balance to the cent, an open period, a control-account line's party; it writes the
--     totals, the number (JE-, on post, D10) and the stamp;
--   · a posted entry is IMMUTABLE — lines and head — except to be marked reversed by its reversal; posted → draft is
--     refused; posted → void is refused (reverse it instead); a draft may be voided;
--   · one entry per source document (the partial unique index), so nothing posts twice;
--   · a reversal is a new entry (source 'reversal') with the sides swapped, posted in an open period, that names the
--     original and is named by it; the year-end close is an entry of source 'closing' that moves the year's income
--     and expense to retained earnings, reversed when the year is reopened.
BEGIN;

CREATE TABLE journal_entries (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number             text UNIQUE,                            -- next_document_number('journal_entry') on post
    entry_date         date NOT NULL,
    period_id          bigint REFERENCES fiscal_periods(id) ON DELETE RESTRICT,
    memo               text,
    source             text NOT NULL DEFAULT 'manual'
                           CHECK (source IN ('manual', 'invoice', 'credit_note', 'receipt', 'deposit', 'write_off',
                                             'bill', 'vendor_credit', 'payment', 'transfer', 'bank_fee', 'bank_interest',
                                             'ai_usage', 'payroll', 'recurring', 'reversal', 'closing', 'opening', 'import')),
    source_type        text,                                   -- invoice | bill | receipt | deposit | payment | transfer | credit_note | vendor_credit | ai_statement | recurring_run | …
    source_id          bigint,
    status             text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'posted', 'reversed', 'void')),
    reversal_of        bigint REFERENCES journal_entries(id) ON DELETE RESTRICT,
    reversed_by        bigint REFERENCES journal_entries(id) ON DELETE SET NULL,
    currency           char(3) NOT NULL DEFAULT 'USD' CHECK (currency ~ '^[A-Z]{3}$'),
    total_debit        numeric(16,2) NOT NULL DEFAULT 0 CHECK (total_debit >= 0),
    total_credit       numeric(16,2) NOT NULL DEFAULT 0 CHECK (total_credit >= 0),
    department_id      bigint REFERENCES departments(id) ON DELETE SET NULL,
    site_id            bigint REFERENCES sites(id) ON DELETE SET NULL,
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    posted_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    posted_at          timestamptz,
    voided_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    voided_at          timestamptz,
    void_reason        text,
    reversal_reason    text,                                   -- on the reversal entry
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'posted' OR number IS NOT NULL),
    CHECK ((source = 'reversal') = (reversal_of IS NOT NULL)),
    CHECK ((source_type IS NULL) = (source_id IS NULL))
);
CREATE INDEX journal_entries_date_idx    ON journal_entries (entry_date DESC);
CREATE INDEX journal_entries_period_idx  ON journal_entries (period_id, status);
CREATE INDEX journal_entries_status_idx  ON journal_entries (status) WHERE status = 'draft';
CREATE UNIQUE INDEX journal_entries_source_live ON journal_entries (source_type, source_id) WHERE source_type IS NOT NULL AND status <> 'void';
CREATE INDEX journal_entries_dept_idx    ON journal_entries (department_id);
CREATE INDEX journal_entries_site_idx    ON journal_entries (site_id);
CREATE INDEX journal_entries_reversal_idx ON journal_entries (reversal_of);
CREATE INDEX journal_entries_reversed_by_idx ON journal_entries (reversed_by);
CREATE INDEX journal_entries_created_by_idx ON journal_entries (created_by);
CREATE INDEX journal_entries_posted_by_idx ON journal_entries (posted_by);
CREATE INDEX journal_entries_voided_by_idx ON journal_entries (voided_by);

ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_closing_entry_fk
    FOREIGN KEY (closing_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL;
CREATE INDEX fiscal_years_closing_entry_idx ON fiscal_years (closing_entry_id);

CREATE TABLE journal_lines (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    entry_id        bigint NOT NULL REFERENCES journal_entries(id) ON DELETE CASCADE,
    line_no         integer NOT NULL,
    account_id      bigint NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
    description     text,
    debit           numeric(16,2) NOT NULL DEFAULT 0 CHECK (debit >= 0),
    credit          numeric(16,2) NOT NULL DEFAULT 0 CHECK (credit >= 0),
    department_id   bigint REFERENCES departments(id) ON DELETE SET NULL,
    site_id         bigint REFERENCES sites(id) ON DELETE SET NULL,
    customer_id     bigint,                                    -- FK added in db/009 (the party on a control-account line)
    vendor_id       bigint,                                    -- FK added in db/009
    tax_rate_id     bigint REFERENCES tax_rates(id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (entry_id, line_no),
    CHECK ((debit > 0) <> (credit > 0))                        -- exactly one side per line
);
CREATE INDEX journal_lines_account_idx ON journal_lines (account_id);
CREATE INDEX journal_lines_dept_idx ON journal_lines (department_id);
CREATE INDEX journal_lines_site_idx ON journal_lines (site_id);
CREATE INDEX journal_lines_customer_idx ON journal_lines (customer_id);
CREATE INDEX journal_lines_vendor_idx ON journal_lines (vendor_id);
CREATE INDEX journal_lines_tax_idx ON journal_lines (tax_rate_id);

-- ---------------------------------------------------------------------------------------------
-- The head: born a draft; the period follows the date while draft; posting checks and stamps; posted is immutable.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION gl_entry_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE d numeric(16,2); c numeric(16,2); pstat text; pname text; bad text; cur char(3);
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'draft' THEN
            RAISE EXCEPTION 'A journal entry is born a draft and posted afterwards';
        END IF;
        NEW.period_id := gl_period_for_date(NEW.entry_date);
        NEW.number := NULL;
        NEW.total_debit := 0; NEW.total_credit := 0;
        NEW.posted_at := NULL; NEW.posted_by := NULL;
        RETURN NEW;
    END IF;

    -- UPDATE
    IF OLD.status = 'posted' THEN
        IF NEW.status = 'draft' THEN
            RAISE EXCEPTION 'Entry % is posted and cannot return to draft; reverse it instead', OLD.number;
        END IF;
        IF NEW.status = 'void' THEN
            RAISE EXCEPTION 'Entry % is posted and cannot be voided; reverse it instead', OLD.number;
        END IF;
        -- the only change a posted entry accepts: being marked reversed by its reversal
        IF NEW.status = 'reversed' AND NEW.reversed_by IS NOT NULL
           AND row(NEW.number, NEW.entry_date, NEW.period_id, NEW.memo, NEW.source, NEW.source_type, NEW.source_id, NEW.reversal_of,
                   NEW.currency, NEW.total_debit, NEW.total_credit, NEW.department_id, NEW.site_id, NEW.created_by, NEW.posted_by, NEW.posted_at)
             IS NOT DISTINCT FROM
               row(OLD.number, OLD.entry_date, OLD.period_id, OLD.memo, OLD.source, OLD.source_type, OLD.source_id, OLD.reversal_of,
                   OLD.currency, OLD.total_debit, OLD.total_credit, OLD.department_id, OLD.site_id, OLD.created_by, OLD.posted_by, OLD.posted_at) THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'Entry % is posted and cannot be changed', OLD.number;
    END IF;
    IF OLD.status IN ('reversed', 'void') THEN
        RAISE EXCEPTION 'Entry % is % and cannot be changed', COALESCE(OLD.number, OLD.id::text), OLD.status;
    END IF;

    -- OLD is a draft
    IF NEW.status = 'reversed' THEN
        RAISE EXCEPTION 'A draft cannot be reversed; void it';
    END IF;
    IF NEW.status = 'void' THEN
        NEW.voided_at := COALESCE(NEW.voided_at, now());
        RETURN NEW;
    END IF;
    NEW.period_id := gl_period_for_date(NEW.entry_date);
    IF NEW.status = 'posted' THEN
        SELECT COALESCE(sum(debit), 0), COALESCE(sum(credit), 0) INTO d, c FROM journal_lines WHERE entry_id = NEW.id;
        IF d = 0 AND c = 0 THEN
            RAISE EXCEPTION 'Entry % has no lines', COALESCE(NEW.memo, NEW.id::text);
        END IF;
        IF d <> c THEN
            RAISE EXCEPTION 'Entry does not balance: debits %, credits % (difference %)', d, c, d - c;
        END IF;
        IF NEW.period_id IS NULL THEN
            RAISE EXCEPTION 'No fiscal period covers % — open the fiscal year first', NEW.entry_date;
        END IF;
        SELECT status, name INTO pstat, pname FROM fiscal_periods WHERE id = NEW.period_id;
        IF pstat <> 'open' THEN
            RAISE EXCEPTION 'Period % is closed; nothing posts into it until it is reopened', pname;
        END IF;
        SELECT a.code INTO bad FROM journal_lines l JOIN accounts a ON a.id = l.account_id
         WHERE l.entry_id = NEW.id AND a.archived_at IS NOT NULL LIMIT 1;
        IF bad IS NOT NULL THEN
            RAISE EXCEPTION 'Account % is archived and takes no new line', bad;
        END IF;
        SELECT a.code INTO bad FROM journal_lines l JOIN accounts a ON a.id = l.account_id
         WHERE l.entry_id = NEW.id AND a.is_control
           AND ((a.subtype = 'receivable' AND l.customer_id IS NULL) OR (a.subtype = 'payable' AND l.vendor_id IS NULL)) LIMIT 1;
        IF bad IS NOT NULL THEN
            RAISE EXCEPTION 'Account % is a control account: every line on it names its customer or vendor', bad;
        END IF;
        NEW.total_debit := d;
        NEW.total_credit := c;
        NEW.posted_at := COALESCE(NEW.posted_at, now());
        NEW.number := COALESCE(NEW.number, next_document_number('journal_entry'));
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER journal_entries_guard BEFORE INSERT OR UPDATE ON journal_entries FOR EACH ROW EXECUTE FUNCTION gl_entry_guard();

CREATE OR REPLACE FUNCTION gl_entry_no_delete() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'draft' THEN
        RAISE EXCEPTION 'Entry % is % and is never deleted', COALESCE(OLD.number, OLD.id::text), OLD.status;
    END IF;
    RETURN OLD;
END$$;
CREATE TRIGGER journal_entries_no_delete BEFORE DELETE ON journal_entries FOR EACH ROW EXECUTE FUNCTION gl_entry_no_delete();
CREATE TRIGGER journal_entries_touch BEFORE UPDATE ON journal_entries FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Lines never change once the entry has left draft.
CREATE OR REPLACE FUNCTION gl_lines_immutable() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE s text; n text;
BEGIN
    SELECT status, number INTO s, n FROM journal_entries WHERE id = COALESCE(NEW.entry_id, OLD.entry_id);
    IF s IS DISTINCT FROM 'draft' THEN
        RAISE EXCEPTION 'Lines of entry % cannot be changed: it is %', COALESCE(n, COALESCE(NEW.entry_id, OLD.entry_id)::text), s;
    END IF;
    RETURN COALESCE(NEW, OLD);
END$$;
CREATE TRIGGER journal_lines_immutable BEFORE INSERT OR UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION gl_lines_immutable();

-- An archived account with a balance is refused (the balance must be moved first).
CREATE OR REPLACE FUNCTION gl_account_archive_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE bal numeric;
BEGIN
    IF NEW.archived_at IS NOT NULL AND OLD.archived_at IS NULL THEN
        SELECT COALESCE(sum(l.debit - l.credit), 0) INTO bal
          FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.status IN ('posted', 'reversed')
         WHERE l.account_id = NEW.id;
        IF bal <> 0 THEN
            RAISE EXCEPTION 'Account % % has a balance of % and cannot be archived until it is moved', NEW.code, NEW.name, bal;
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER accounts_archive_guard BEFORE UPDATE ON accounts FOR EACH ROW EXECUTE FUNCTION gl_account_archive_guard();

-- ---------------------------------------------------------------------------------------------
-- The writer's verbs. PHP and the actions server call these; the trigger does the checking.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION gl_entry_post(p_entry_id bigint, p_by bigint) RETURNS journal_entries
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE e journal_entries%ROWTYPE;
BEGIN
    UPDATE journal_entries SET status = 'posted', posted_by = p_by WHERE id = p_entry_id RETURNING * INTO e;
    IF e.id IS NULL THEN RAISE EXCEPTION 'No such entry'; END IF;
    RETURN e;
END$$;

-- Reverse a posted entry: a new entry, sides swapped, dated p_date (default the original's date — in an open period,
-- else the caller passes today), posted at once; the original is marked reversed. Returns the reversal.
CREATE OR REPLACE FUNCTION gl_entry_reverse(p_entry_id bigint, p_by bigint, p_reason text, p_date date DEFAULT NULL) RETURNS journal_entries
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o journal_entries%ROWTYPE; r journal_entries%ROWTYPE;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'A reversal needs a reason'; END IF;
    SELECT * INTO o FROM journal_entries WHERE id = p_entry_id FOR UPDATE;
    IF o.id IS NULL THEN RAISE EXCEPTION 'No such entry'; END IF;
    IF o.status <> 'posted' THEN RAISE EXCEPTION 'Entry % is %, not posted; only a posted entry is reversed', COALESCE(o.number, o.id::text), o.status; END IF;
    INSERT INTO journal_entries (entry_date, memo, source, reversal_of, currency, department_id, site_id, created_by, reversal_reason)
    VALUES (COALESCE(p_date, o.entry_date), 'Reversal of ' || o.number || COALESCE(': ' || o.memo, ''), 'reversal', o.id, o.currency,
            o.department_id, o.site_id, p_by, p_reason)
    RETURNING * INTO r;
    INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit, department_id, site_id, customer_id, vendor_id, tax_rate_id)
    SELECT r.id, line_no, account_id, description, credit, debit, department_id, site_id, customer_id, vendor_id, tax_rate_id
      FROM journal_lines WHERE entry_id = o.id;
    UPDATE journal_entries SET status = 'posted', posted_by = p_by WHERE id = r.id RETURNING * INTO r;
    UPDATE journal_entries SET status = 'reversed', reversed_by = r.id WHERE id = o.id;
    RETURN r;
END$$;

CREATE OR REPLACE FUNCTION gl_entry_void(p_entry_id bigint, p_by bigint, p_reason text) RETURNS journal_entries
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE e journal_entries%ROWTYPE;
BEGIN
    UPDATE journal_entries SET status = 'void', voided_by = p_by, void_reason = p_reason WHERE id = p_entry_id RETURNING * INTO e;
    IF e.id IS NULL THEN RAISE EXCEPTION 'No such entry'; END IF;
    RETURN e;
END$$;

-- The year-end close: one entry dated the year's last day, source 'closing', that moves every income and expense
-- account's balance for the year to retained earnings; the year is marked closed and names the entry. Every period
-- of the year must already be closed. Reopening the year reverses the entry and reopens the year (its periods stay
-- closed; reopen the one you need).
CREATE OR REPLACE FUNCTION gl_year_close(p_year_id bigint, p_by bigint) RETURNS journal_entries
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE y fiscal_years%ROWTYPE; e journal_entries%ROWTYPE; re bigint; n integer := 0; net numeric(16,2); rec record; last_period bigint;
BEGIN
    SELECT * INTO y FROM fiscal_years WHERE id = p_year_id FOR UPDATE;
    IF y.id IS NULL THEN RAISE EXCEPTION 'No such fiscal year'; END IF;
    IF y.status = 'closed' THEN RAISE EXCEPTION 'Fiscal year % is already closed', y.name; END IF;
    IF EXISTS (SELECT 1 FROM fiscal_periods WHERE fiscal_year_id = y.id AND status = 'open') THEN
        RAISE EXCEPTION 'Every period of % must be closed before the year is', y.name;
    END IF;
    IF EXISTS (SELECT 1 FROM journal_entries WHERE status = 'draft' AND entry_date BETWEEN y.start_date AND y.end_date) THEN
        RAISE EXCEPTION 'Draft entries are dated in %; post or void them first', y.name;
    END IF;
    SELECT retained_earnings_account_id INTO re FROM gl_settings WHERE id = 1;
    IF re IS NULL THEN RAISE EXCEPTION 'No retained earnings account is set'; END IF;
    -- the closing entry posts into the year's last period: open it for the entry, then close it again
    SELECT id INTO last_period FROM fiscal_periods WHERE fiscal_year_id = y.id ORDER BY end_date DESC LIMIT 1;
    UPDATE fiscal_periods SET status = 'open' WHERE id = last_period;
    INSERT INTO journal_entries (entry_date, memo, source, source_type, source_id, currency, created_by)
    VALUES (y.end_date, 'Year-end close ' || y.name, 'closing', 'fiscal_year', y.id, gl_base_currency(), p_by)
    RETURNING * INTO e;
    net := 0;
    FOR rec IN
        SELECT l.account_id, sum(l.debit - l.credit) AS bal
          FROM journal_lines l
          JOIN journal_entries je ON je.id = l.entry_id AND je.status IN ('posted', 'reversed') AND je.source <> 'closing'
           AND NOT EXISTS (SELECT 1 FROM journal_entries o WHERE o.id = je.reversal_of AND o.source = 'closing')
          JOIN accounts a ON a.id = l.account_id AND a.account_type IN ('income', 'expense')
         WHERE je.entry_date BETWEEN y.start_date AND y.end_date
         GROUP BY l.account_id HAVING sum(l.debit - l.credit) <> 0
    LOOP
        n := n + 1;
        INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit)
        VALUES (e.id, n, rec.account_id, 'Close to retained earnings',
                CASE WHEN rec.bal < 0 THEN -rec.bal ELSE 0 END, CASE WHEN rec.bal > 0 THEN rec.bal ELSE 0 END);
        net := net + rec.bal;
    END LOOP;
    IF n = 0 THEN
        DELETE FROM journal_entries WHERE id = e.id;
        UPDATE fiscal_periods SET status = 'closed' WHERE id = last_period;
        UPDATE fiscal_years SET status = 'closed', closed_at = now(), closed_by = p_by, closing_entry_id = NULL WHERE id = y.id;
        RETURN NULL;
    END IF;
    INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit)
    VALUES (e.id, n + 1, re, 'Net ' || CASE WHEN net < 0 THEN 'income' ELSE 'loss' END || ' for ' || y.name,
            CASE WHEN net > 0 THEN net ELSE 0 END, CASE WHEN net < 0 THEN -net ELSE 0 END);
    UPDATE journal_entries SET status = 'posted', posted_by = p_by WHERE id = e.id RETURNING * INTO e;
    UPDATE fiscal_periods SET status = 'closed' WHERE id = last_period;
    UPDATE fiscal_years SET status = 'closed', closed_at = now(), closed_by = p_by, closing_entry_id = e.id WHERE id = y.id;
    RETURN e;
END$$;

CREATE OR REPLACE FUNCTION gl_year_reopen(p_year_id bigint, p_by bigint, p_reason text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE y fiscal_years%ROWTYPE; last_period bigint;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'Reopening a year needs a reason'; END IF;
    SELECT * INTO y FROM fiscal_years WHERE id = p_year_id FOR UPDATE;
    IF y.id IS NULL THEN RAISE EXCEPTION 'No such fiscal year'; END IF;
    IF y.status = 'open' THEN RAISE EXCEPTION 'Fiscal year % is open', y.name; END IF;
    IF EXISTS (SELECT 1 FROM fiscal_years WHERE start_date > y.end_date AND status = 'closed') THEN
        RAISE EXCEPTION 'A later fiscal year is closed; reopen the years in order';
    END IF;
    UPDATE fiscal_years SET status = 'open', reopened_at = now(), reopened_by = p_by WHERE id = y.id;
    IF y.closing_entry_id IS NOT NULL THEN
        SELECT id INTO last_period FROM fiscal_periods WHERE fiscal_year_id = y.id ORDER BY end_date DESC LIMIT 1;
        UPDATE fiscal_periods SET status = 'open' WHERE id = last_period;
        PERFORM gl_entry_reverse(y.closing_entry_id, p_by, 'Year ' || y.name || ' reopened: ' || p_reason);
        UPDATE fiscal_periods SET status = 'closed' WHERE id = last_period;
        UPDATE fiscal_years SET closing_entry_id = NULL WHERE id = y.id;
    END IF;
END$$;

REVOKE ALL ON FUNCTION gl_entry_post(bigint, bigint), gl_entry_reverse(bigint, bigint, text, date), gl_entry_void(bigint, bigint, text),
    gl_year_close(bigint, bigint), gl_year_reopen(bigint, bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION gl_entry_post(bigint, bigint), gl_entry_reverse(bigint, bigint, text, date), gl_entry_void(bigint, bigint, text),
    gl_year_close(bigint, bigint), gl_year_reopen(bigint, bigint, text) TO gl_rw;
GRANT SELECT, INSERT, UPDATE, DELETE ON journal_entries, journal_lines TO gl_rw;

COMMIT;
