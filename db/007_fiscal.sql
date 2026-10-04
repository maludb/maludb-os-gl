-- 007: FISCAL TIME (design §6). A fiscal year is made with its twelve periods in one call; two periods never overlap
-- (EXCLUDE); a period is open or closed; closing and reopening are functions that take the person and the reason,
-- so the trail (GL10, GL12) is complete and a reopen is never silent. The year-end close (db/008) names the closing
-- entry here. Nothing seeds a year: the admin opens the first one on install (Phase 5).
BEGIN;

CREATE TABLE fiscal_years (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name             text NOT NULL UNIQUE,                   -- 'FY2026'
    start_date       date NOT NULL,
    end_date         date NOT NULL,
    status           text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'closed')),
    closing_entry_id bigint,                                 -- FK added in db/008
    closed_at        timestamptz,
    closed_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    reopened_at      timestamptz,
    reopened_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CHECK (end_date > start_date),
    EXCLUDE USING gist (daterange(start_date, end_date, '[]') WITH &&)
);
CREATE INDEX fiscal_years_closed_by_idx ON fiscal_years (closed_by);
CREATE INDEX fiscal_years_reopened_by_idx ON fiscal_years (reopened_by);
CREATE TRIGGER fiscal_years_touch BEFORE UPDATE ON fiscal_years FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE fiscal_periods (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    fiscal_year_id bigint NOT NULL REFERENCES fiscal_years(id) ON DELETE CASCADE,
    name           text NOT NULL UNIQUE,                     -- '2026-09'
    start_date     date NOT NULL,
    end_date       date NOT NULL,
    status         text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'closed')),
    closed_at      timestamptz,
    closed_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    reopened_at    timestamptz,
    reopened_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    reopen_reason  text,
    reopen_count   integer NOT NULL DEFAULT 0,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    CHECK (end_date >= start_date),
    EXCLUDE USING gist (daterange(start_date, end_date, '[]') WITH &&)
);
CREATE INDEX fiscal_periods_year_idx ON fiscal_periods (fiscal_year_id, start_date);
CREATE INDEX fiscal_periods_range_idx ON fiscal_periods (start_date, end_date);
CREATE INDEX fiscal_periods_closed_by_idx ON fiscal_periods (closed_by);
CREATE INDEX fiscal_periods_reopened_by_idx ON fiscal_periods (reopened_by);
CREATE TRIGGER fiscal_periods_touch BEFORE UPDATE ON fiscal_periods FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A period lies inside its year; a period of a closed year cannot be open.
CREATE OR REPLACE FUNCTION gl_period_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE y fiscal_years%ROWTYPE;
BEGIN
    SELECT * INTO y FROM fiscal_years WHERE id = NEW.fiscal_year_id;
    IF NEW.start_date < y.start_date OR NEW.end_date > y.end_date THEN
        RAISE EXCEPTION 'Period % (% to %) lies outside fiscal year % (% to %)', NEW.name, NEW.start_date, NEW.end_date, y.name, y.start_date, y.end_date;
    END IF;
    IF y.status = 'closed' AND NEW.status = 'open' THEN
        RAISE EXCEPTION 'Period % cannot be open: fiscal year % is closed — reopen the year first', NEW.name, y.name;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER fiscal_periods_guard BEFORE INSERT OR UPDATE ON fiscal_periods FOR EACH ROW EXECUTE FUNCTION gl_period_guard();

-- The period a date falls in (NULL when none exists — the posting trigger refuses the entry then).
CREATE OR REPLACE FUNCTION gl_period_for_date(p_date date) RETURNS bigint
    LANGUAGE sql STABLE AS $$
    SELECT id FROM fiscal_periods WHERE p_date BETWEEN start_date AND end_date LIMIT 1;
$$;

-- The fiscal year a date falls in.
CREATE OR REPLACE FUNCTION gl_year_for_date(p_date date) RETURNS fiscal_years
    LANGUAGE sql STABLE AS $$
    SELECT * FROM fiscal_years WHERE p_date BETWEEN start_date AND end_date LIMIT 1;
$$;

-- Open a fiscal year with its twelve monthly periods, from its first day. Name: 'FY' || the year the period ENDS in
-- (FY2026 = the year ending in 2026), or the name given.
CREATE OR REPLACE FUNCTION gl_fiscal_year_create(p_start date, p_name text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE yid bigint; d date; e date; nm text;
BEGIN
    IF EXTRACT(DAY FROM p_start) <> 1 THEN
        RAISE EXCEPTION 'A fiscal year starts on the first of a month, not %', p_start;
    END IF;
    e := (p_start + interval '1 year' - interval '1 day')::date;
    nm := COALESCE(p_name, 'FY' || EXTRACT(YEAR FROM e)::text);
    INSERT INTO fiscal_years (name, start_date, end_date) VALUES (nm, p_start, e) RETURNING id INTO yid;
    d := p_start;
    WHILE d <= e LOOP
        INSERT INTO fiscal_periods (fiscal_year_id, name, start_date, end_date)
        VALUES (yid, to_char(d, 'YYYY-MM'), d, (d + interval '1 month' - interval '1 day')::date);
        d := (d + interval '1 month')::date;
    END LOOP;
    RETURN yid;
END$$;

-- Close a period: refused when a draft entry is dated in it? No — a draft may stay; it cannot be posted until the
-- period is reopened. Refused when already closed.
CREATE OR REPLACE FUNCTION gl_period_close(p_period_id bigint, p_by bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p fiscal_periods%ROWTYPE;
BEGIN
    SELECT * INTO p FROM fiscal_periods WHERE id = p_period_id FOR UPDATE;
    IF p.id IS NULL THEN RAISE EXCEPTION 'No such period'; END IF;
    IF p.status = 'closed' THEN RAISE EXCEPTION 'Period % is already closed', p.name; END IF;
    UPDATE fiscal_periods SET status = 'closed', closed_at = now(), closed_by = p_by WHERE id = p_period_id;
END$$;

-- Reopen a period with a reason (never silent; the activity row and the audit page carry it).
CREATE OR REPLACE FUNCTION gl_period_reopen(p_period_id bigint, p_by bigint, p_reason text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p fiscal_periods%ROWTYPE;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'Reopening a period needs a reason'; END IF;
    SELECT * INTO p FROM fiscal_periods WHERE id = p_period_id FOR UPDATE;
    IF p.id IS NULL THEN RAISE EXCEPTION 'No such period'; END IF;
    IF p.status = 'open' THEN RAISE EXCEPTION 'Period % is open', p.name; END IF;
    UPDATE fiscal_periods SET status = 'open', reopened_at = now(), reopened_by = p_by, reopen_reason = p_reason,
                              reopen_count = reopen_count + 1
     WHERE id = p_period_id;
END$$;

REVOKE ALL ON FUNCTION gl_fiscal_year_create(date, text), gl_period_close(bigint, bigint), gl_period_reopen(bigint, bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION gl_fiscal_year_create(date, text), gl_period_close(bigint, bigint), gl_period_reopen(bigint, bigint, text) TO gl_rw;
GRANT EXECUTE ON FUNCTION gl_period_for_date(date), gl_year_for_date(date) TO gl_rw, gl_records_ro;
GRANT SELECT, INSERT, UPDATE, DELETE ON fiscal_years, fiscal_periods TO gl_rw;

COMMIT;
