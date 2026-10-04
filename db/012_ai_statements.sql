-- 012: THE KERNEL'S AI STATEMENT (design §8, D5): the closed month's `os.ledger-period/1` document kept VERBATIM, and
-- its import into ONE DRAFT BILL PER PROVIDER (and currency), lines by department, the provider a vendor of kind
-- ai_provider, for the controller to approve like any bill. AI2's check compares the statement's lines to the bills,
-- to the cent: the amounts arrive at four decimals and are rounded ONCE, per department line, the remainder put on
-- the largest line so the bill equals the statement.
BEGIN;

CREATE TABLE ai_statements (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    period         text NOT NULL UNIQUE CHECK (period ~ '^[0-9]{4}-[0-9]{2}$'),   -- the kernel's period, '2026-09'
    period_start   date NOT NULL,
    period_end     date NOT NULL,
    kernel_status  text NOT NULL CHECK (kernel_status IN ('open', 'closed')),
    document       jsonb NOT NULL,                                               -- the kernel's document, as sent
    document_hash  text NOT NULL,                                                -- sha256 of the document text
    pulled_at      timestamptz NOT NULL DEFAULT now(),
    status         text NOT NULL DEFAULT 'pulled' CHECK (status IN ('pulled', 'imported', 'agrees', 'differs')),
    imported_at    timestamptz,
    imported_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    late_calls     integer NOT NULL DEFAULT 0,
    checked_at     timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ai_statements_imported_by_idx ON ai_statements (imported_by);
CREATE TRIGGER ai_statements_touch BEFORE UPDATE ON ai_statements FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE ai_statement_lines (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    statement_id       bigint NOT NULL REFERENCES ai_statements(id) ON DELETE CASCADE,
    line_no            integer NOT NULL,
    provider           text NOT NULL,
    model              text,
    department         text,                                                     -- the kernel's department NAME
    department_id      bigint REFERENCES departments(id) ON DELETE SET NULL,     -- resolved against the mirror by name
    agent              text,
    application        text,
    calls              integer NOT NULL DEFAULT 0,
    late_calls         integer NOT NULL DEFAULT 0,
    input_tokens       bigint NOT NULL DEFAULT 0,
    output_tokens      bigint NOT NULL DEFAULT 0,
    cache_read_tokens  bigint NOT NULL DEFAULT 0,
    cache_write_tokens bigint NOT NULL DEFAULT 0,
    amount             numeric(16,4) NOT NULL DEFAULT 0,
    currency           char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    note               text,
    UNIQUE (statement_id, line_no)
);
CREATE INDEX ai_statement_lines_provider_idx ON ai_statement_lines (statement_id, provider, currency);
CREATE INDEX ai_statement_lines_dept_idx ON ai_statement_lines (department_id);

CREATE TABLE ai_statement_bills (
    statement_id  bigint NOT NULL REFERENCES ai_statements(id) ON DELETE CASCADE,
    provider      text NOT NULL,
    currency      char(3) NOT NULL,
    bill_id       bigint NOT NULL REFERENCES bills(id) ON DELETE RESTRICT,
    PRIMARY KEY (statement_id, provider, currency)
);
CREATE INDEX ai_statement_bills_bill_idx ON ai_statement_bills (bill_id);

-- Keep (or refresh) a pulled document. An imported statement's document is never replaced by a different one:
-- the kernel's closed month does not change, and if it did the books must say so, not quietly follow.
CREATE OR REPLACE FUNCTION gl_ai_statement_load(p_document jsonb) RETURNS ai_statements
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s ai_statements%ROWTYPE; h text; per text; ks text; n integer := 0; l jsonb;
BEGIN
    IF p_document->>'schema' IS DISTINCT FROM 'os.ledger-period/1' THEN
        RAISE EXCEPTION 'Not an os.ledger-period/1 document (schema %)', p_document->>'schema';
    END IF;
    per := p_document->>'period';
    ks := p_document->>'status';
    h := encode(sha256(convert_to(p_document::text, 'UTF8')), 'hex');
    SELECT * INTO s FROM ai_statements WHERE period = per FOR UPDATE;
    IF s.id IS NOT NULL AND s.status <> 'pulled' AND s.document_hash <> h THEN
        RAISE EXCEPTION 'Statement % was imported from a different document (hash % now %); the books do not follow a changed statement', per, s.document_hash, h;
    END IF;
    IF s.id IS NULL THEN
        INSERT INTO ai_statements (period, period_start, period_end, kernel_status, document, document_hash)
        VALUES (per, (p_document->>'period_start')::date, (p_document->>'period_end')::date, ks, p_document, h)
        RETURNING * INTO s;
    ELSIF s.document_hash <> h OR s.kernel_status <> ks THEN
        UPDATE ai_statements SET kernel_status = ks, document = p_document, document_hash = h, pulled_at = now() WHERE id = s.id RETURNING * INTO s;
    ELSE
        UPDATE ai_statements SET pulled_at = now() WHERE id = s.id RETURNING * INTO s;
        RETURN s;
    END IF;
    DELETE FROM ai_statement_lines WHERE statement_id = s.id;
    FOR l IN SELECT * FROM jsonb_array_elements(COALESCE(p_document->'lines', '[]'::jsonb)) LOOP
        n := n + 1;
        INSERT INTO ai_statement_lines (statement_id, line_no, provider, model, department, department_id, agent, application, calls, late_calls,
                                        input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, amount, currency, note)
        VALUES (s.id, n, l->>'provider', l->>'model', l->>'department',
                (SELECT d.id FROM departments d WHERE lower(d.name) = lower(l->>'department') AND d.archived_at IS NULL LIMIT 1),
                l->>'agent', l->>'application', COALESCE((l->>'calls')::int, 0), COALESCE((l->>'late_calls')::int, 0),
                COALESCE((l->>'input_tokens')::bigint, 0), COALESCE((l->>'output_tokens')::bigint, 0),
                COALESCE((l->>'cache_read_tokens')::bigint, 0), COALESCE((l->>'cache_write_tokens')::bigint, 0),
                COALESCE((l->>'amount')::numeric, 0), COALESCE(l->>'currency', 'USD'), l->>'note');
    END LOOP;
    UPDATE ai_statements SET late_calls = (SELECT COALESCE(sum(late_calls), 0) FROM ai_statement_lines WHERE statement_id = s.id) WHERE id = s.id RETURNING * INTO s;
    RETURN s;
END$$;

-- The vendor for a provider: by provider_key, made on first sight.
CREATE OR REPLACE FUNCTION gl_ai_provider_vendor(p_provider text, p_by bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE vid bigint;
BEGIN
    SELECT id INTO vid FROM vendors WHERE provider_key = p_provider AND archived_at IS NULL;
    IF vid IS NULL THEN
        INSERT INTO vendors (name, kind, provider_key, created_by, pay_method, terms_days)
        VALUES (initcap(p_provider) || ' (AI provider)', 'ai_provider', p_provider, p_by, 'card', 0)
        RETURNING id INTO vid;
    END IF;
    RETURN vid;
END$$;

-- Import a CLOSED statement: one draft bill per provider × currency, lines by department, rounded once.
CREATE OR REPLACE FUNCTION gl_ai_statement_import(p_statement_id bigint, p_by bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s ai_statements%ROWTYPE; pv record; dp record; vid bigint; bid bigint; acct bigint; terms integer;
        target numeric(14,2); placed numeric(14,2); n integer := 0; made integer := 0; biggest bigint; biggest_amt numeric(14,2);
BEGIN
    SELECT * INTO s FROM ai_statements WHERE id = p_statement_id FOR UPDATE;
    IF s.id IS NULL THEN RAISE EXCEPTION 'No such statement'; END IF;
    IF s.kernel_status <> 'closed' THEN RAISE EXCEPTION 'Statement % is still open in the OS; only a closed month becomes bills', s.period; END IF;
    IF s.status <> 'pulled' THEN RAISE EXCEPTION 'Statement % is already imported', s.period; END IF;
    SELECT ai_usage_account_id, bill_terms_days INTO acct, terms FROM gl_settings WHERE id = 1;
    IF acct IS NULL THEN RAISE EXCEPTION 'No AI usage expense account is set'; END IF;
    FOR pv IN SELECT provider, currency, round(sum(amount), 2) AS total, sum(calls) AS calls, sum(late_calls) AS late
                FROM ai_statement_lines WHERE statement_id = s.id GROUP BY provider, currency HAVING round(sum(amount), 2) <> 0 ORDER BY provider, currency LOOP
        vid := gl_ai_provider_vendor(pv.provider, p_by);
        INSERT INTO bills (vendor_id, vendor_ref, bill_date, due_date, currency, source, memo, created_by)
        VALUES (vid, pv.provider || ' ' || s.period, s.period_end, s.period_end + COALESCE(terms, 0), pv.currency, 'ai_usage',
                'AI usage ' || s.period || ' per the OS statement: ' || pv.calls || ' calls' || CASE WHEN pv.late > 0 THEN ' (' || pv.late || ' late calls folded in)' ELSE '' END, p_by)
        RETURNING id INTO bid;
        target := pv.total; placed := 0; n := 0; biggest := NULL; biggest_amt := NULL;
        FOR dp IN SELECT department, department_id, round(sum(amount), 2) AS amt, sum(calls) AS calls, sum(late_calls) AS late
                    FROM ai_statement_lines WHERE statement_id = s.id AND provider = pv.provider AND currency = pv.currency
                   GROUP BY department, department_id ORDER BY sum(amount) DESC, department LOOP
            IF dp.amt = 0 THEN CONTINUE; END IF;
            n := n + 1;
            INSERT INTO bill_lines (bill_id, sort_order, description, quantity, unit_price, line_subtotal, line_tax, line_total, expense_account_id, department_id)
            VALUES (bid, n, COALESCE(dp.department, 'No department') || ' — ' || dp.calls || ' calls' || CASE WHEN dp.late > 0 THEN ' (' || dp.late || ' late)' ELSE '' END,
                    1, dp.amt, dp.amt, 0, dp.amt, acct, dp.department_id);
            placed := placed + dp.amt;
            IF biggest IS NULL OR dp.amt > biggest_amt THEN
                SELECT id INTO biggest FROM bill_lines WHERE bill_id = bid AND sort_order = n; biggest_amt := dp.amt;
            END IF;
        END LOOP;
        IF placed <> target AND biggest IS NOT NULL THEN
            UPDATE bill_lines SET unit_price = unit_price + (target - placed) WHERE id = biggest;   -- the remainder, once
        END IF;
        INSERT INTO ai_statement_bills (statement_id, provider, currency, bill_id) VALUES (s.id, pv.provider, pv.currency, bid);
        made := made + 1;
    END LOOP;
    UPDATE ai_statements SET status = 'imported', imported_at = now(), imported_by = p_by WHERE id = s.id;
    RETURN made;
END$$;

-- AI2: do the bills agree with the statement, provider by provider, to the cent? A pure reader.
CREATE OR REPLACE FUNCTION gl_ai_statement_check(p_statement_id bigint)
    RETURNS TABLE (provider text, currency char(3), statement_amount numeric(14,2), bills_amount numeric(14,2), difference numeric(14,2),
                   bill_id bigint, bill_number text, bill_status text, agrees boolean)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH st AS (
        SELECT l.provider, l.currency, round(sum(l.amount), 2) AS amt
          FROM ai_statement_lines l WHERE l.statement_id = p_statement_id GROUP BY l.provider, l.currency
    ), bl AS (
        SELECT ab.provider, ab.currency, b.id, b.number, b.status, CASE WHEN b.status = 'void' THEN 0 ELSE b.total END AS total
          FROM ai_statement_bills ab JOIN bills b ON b.id = ab.bill_id WHERE ab.statement_id = p_statement_id
    )
    SELECT COALESCE(st.provider, bl.provider), COALESCE(st.currency, bl.currency)::char(3),
           COALESCE(st.amt, 0), COALESCE(bl.total, 0), COALESCE(st.amt, 0) - COALESCE(bl.total, 0),
           bl.id, bl.number, bl.status, COALESCE(st.amt, 0) = COALESCE(bl.total, 0)
      FROM st FULL JOIN bl ON bl.provider = st.provider AND bl.currency = st.currency
     ORDER BY 1, 2;
$$;

-- Record the check's verdict on the statement (the worker, the screen's button).
CREATE OR REPLACE FUNCTION gl_ai_statement_mark_checked(p_statement_id bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE ok boolean; st text;
BEGIN
    SELECT status INTO st FROM ai_statements WHERE id = p_statement_id;
    IF st = 'pulled' THEN RETURN 'pulled'; END IF;
    SELECT bool_and(agrees) INTO ok FROM gl_ai_statement_check(p_statement_id);
    UPDATE ai_statements SET status = CASE WHEN COALESCE(ok, false) THEN 'agrees' ELSE 'differs' END, checked_at = now() WHERE id = p_statement_id RETURNING status INTO st;
    RETURN st;
END$$;

REVOKE ALL ON FUNCTION gl_ai_statement_load(jsonb), gl_ai_provider_vendor(text, bigint), gl_ai_statement_import(bigint, bigint), gl_ai_statement_mark_checked(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION gl_ai_statement_load(jsonb), gl_ai_provider_vendor(text, bigint), gl_ai_statement_import(bigint, bigint), gl_ai_statement_mark_checked(bigint) TO gl_rw;
GRANT EXECUTE ON FUNCTION gl_ai_statement_check(bigint) TO gl_rw, gl_records_ro;
GRANT SELECT, INSERT, UPDATE, DELETE ON ai_statements, ai_statement_lines, ai_statement_bills TO gl_rw;

COMMIT;
