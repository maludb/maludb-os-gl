-- 014: RECURRING templates and their runs; TALKING to people (the in-app bell, preferences, the outbox — email through
-- MaluMail and texts through the kernel's K6 as independent rows, Help Desk's pattern); DISPATCHING to agents.
BEGIN;

CREATE TABLE recurring_templates (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name         text NOT NULL,
    kind         text NOT NULL CHECK (kind IN ('journal', 'bill', 'invoice')),
    cadence      text NOT NULL CHECK (cadence IN ('weekly', 'monthly', 'quarterly', 'yearly')),
    every_n      integer NOT NULL DEFAULT 1 CHECK (every_n BETWEEN 1 AND 24),
    next_date    date NOT NULL,
    end_date     date,
    template     jsonb NOT NULL,                                  -- the document to draft, in the slice's own shape
    last_run_at  timestamptz,
    run_count    integer NOT NULL DEFAULT 0,
    active       boolean NOT NULL DEFAULT true,
    created_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    CHECK (end_date IS NULL OR end_date >= next_date)
);
CREATE UNIQUE INDEX recurring_templates_name_live ON recurring_templates (lower(name)) WHERE active;
CREATE INDEX recurring_templates_due_idx ON recurring_templates (next_date) WHERE active;
CREATE INDEX recurring_templates_created_by_idx ON recurring_templates (created_by);
CREATE TRIGGER recurring_templates_touch BEFORE UPDATE ON recurring_templates FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE recurring_runs (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    template_id   bigint NOT NULL REFERENCES recurring_templates(id) ON DELETE CASCADE,
    run_date      date NOT NULL,
    record_type   text CHECK (record_type IN ('journal_entry', 'bill', 'invoice')),
    record_id     bigint,
    status        text NOT NULL CHECK (status IN ('drafted', 'failed', 'skipped')),
    detail        text,
    created_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (template_id, run_date)
);
CREATE INDEX recurring_runs_template_idx ON recurring_runs (template_id, run_date DESC);

-- The date after this one on the template's cadence.
CREATE OR REPLACE FUNCTION gl_recurring_next(p_date date, p_cadence text, p_every integer) RETURNS date
    LANGUAGE sql IMMUTABLE AS $$
    SELECT (p_date + CASE p_cadence WHEN 'weekly' THEN (p_every || ' weeks')::interval
                                    WHEN 'monthly' THEN (p_every || ' months')::interval
                                    WHEN 'quarterly' THEN (p_every * 3 || ' months')::interval
                                    ELSE (p_every || ' years')::interval END)::date;
$$;

-- Record a run and advance the template (the drafting itself is the slice's; it calls this inside the same transaction).
CREATE OR REPLACE FUNCTION gl_recurring_advance(p_template_id bigint, p_run_date date, p_record_type text, p_record_id bigint, p_status text, p_detail text DEFAULT NULL) RETURNS recurring_templates
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t recurring_templates%ROWTYPE; nd date;
BEGIN
    SELECT * INTO t FROM recurring_templates WHERE id = p_template_id FOR UPDATE;
    IF t.id IS NULL THEN RAISE EXCEPTION 'No such template'; END IF;
    INSERT INTO recurring_runs (template_id, run_date, record_type, record_id, status, detail) VALUES (t.id, p_run_date, p_record_type, p_record_id, p_status, p_detail);
    nd := gl_recurring_next(t.next_date, t.cadence, t.every_n);
    UPDATE recurring_templates SET next_date = nd, last_run_at = now(), run_count = run_count + 1,
                                   active = CASE WHEN end_date IS NOT NULL AND nd > end_date THEN false ELSE active END
     WHERE id = t.id RETURNING * INTO t;
    RETURN t;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Talking.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE notifications (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind         text NOT NULL CHECK (kind IN ('payment_run_awaiting', 'bill_awaiting', 'reconciliation_out', 'morning_note', 'claim_submitted',
                                               'invoice_overdue', 'bill_due', 'ai_statement_imported', 'ai_statement_differs', 'period_closed',
                                               'period_reopened', 'bill_attached', 'mentioned', 'agent_drafted')),
    record_type  text,
    record_id    bigint,
    title        text NOT NULL,
    body         text,
    read_at      timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notifications_member_idx ON notifications (member_id, created_at DESC) WHERE read_at IS NULL;
CREATE INDEX notifications_record_idx ON notifications (record_type, record_id);

CREATE TABLE notification_prefs (
    member_id      bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    email_enabled  boolean NOT NULL DEFAULT true,
    text_enabled   boolean NOT NULL DEFAULT false,                -- K6: the kernel keeps the opt-out; this is the person's choice here
    kinds          text[] NOT NULL DEFAULT '{payment_run_awaiting,bill_awaiting,reconciliation_out,morning_note,claim_submitted,ai_statement_differs,period_reopened}',
    text_kinds     text[] NOT NULL DEFAULT '{payment_run_awaiting,reconciliation_out}',
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER notification_prefs_touch BEFORE UPDATE ON notification_prefs FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One row per recipient AND channel; a K6 refusal is a SKIP with the code and the email still goes. A customer gets
-- email only: an invoice, a statement, a reminder — each carrying the secure link, never a note.
CREATE TABLE notification_outbox (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel        text NOT NULL CHECK (channel IN ('email', 'text')),
    member_id      bigint REFERENCES members(id) ON DELETE CASCADE,
    customer_id    bigint REFERENCES customers(id) ON DELETE CASCADE,
    to_email       citext,                                                 -- the customer's address at send time
    kind           text NOT NULL,
    record_type    text,
    record_id      bigint,
    dedupe_key     text,
    subject        text,
    body           text NOT NULL,
    body_html      text,
    status         text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'skipped', 'failed')),
    attempts       integer NOT NULL DEFAULT 0,
    detail         text,
    provider_ref   text,                                                   -- MaluMail's message id
    send_after     timestamptz NOT NULL DEFAULT now(),
    sent_at        timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    CHECK ((member_id IS NULL) <> (customer_id IS NULL)),
    CHECK (channel = 'email' OR member_id IS NOT NULL)
);
CREATE UNIQUE INDEX notification_outbox_dedupe ON notification_outbox (dedupe_key) WHERE dedupe_key IS NOT NULL;
CREATE INDEX notification_outbox_queue_idx ON notification_outbox (status, send_after) WHERE status = 'queued';
CREATE INDEX notification_outbox_member_idx ON notification_outbox (member_id, created_at DESC);
CREATE INDEX notification_outbox_customer_idx ON notification_outbox (customer_id, created_at DESC);
CREATE INDEX notification_outbox_record_idx ON notification_outbox (record_type, record_id);

-- What was handed to an agent (design §5): a duty's pick-up is the agent's own; a dispatch is the ledger calling the
-- kernel's chat endpoint for one turn, or — K8/K16, owed — the wake call.
CREATE TABLE agent_dispatches (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type      text,
    record_id        bigint,
    agent_member_id  bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind             text NOT NULL CHECK (kind IN ('bill_attached', 'reconciliation_out', 'statement_closed', 'claim_submitted', 'ask')),
    via              text NOT NULL CHECK (via IN ('chat', 'wake', 'duty')),
    acting_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    run_id           bigint,
    request_id       text,
    status           text NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'answered', 'refused', 'failed', 'awaiting_approval')),
    reply_excerpt    text,                                                 -- at most 200 characters
    detail           text,
    attempts         integer NOT NULL DEFAULT 1,
    created_at       timestamptz NOT NULL DEFAULT now(),
    answered_at      timestamptz
);
CREATE INDEX agent_dispatches_record_idx ON agent_dispatches (record_type, record_id, created_at DESC);
CREATE INDEX agent_dispatches_agent_idx ON agent_dispatches (agent_member_id, created_at DESC);
CREATE INDEX agent_dispatches_acting_idx ON agent_dispatches (acting_member_id);

REVOKE ALL ON FUNCTION gl_recurring_advance(bigint, date, text, bigint, text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION gl_recurring_advance(bigint, date, text, bigint, text, text) TO gl_rw;
GRANT EXECUTE ON FUNCTION gl_recurring_next(date, text, integer) TO gl_rw, gl_records_ro;
GRANT SELECT, INSERT, UPDATE, DELETE ON recurring_templates, recurring_runs, notifications, notification_prefs, notification_outbox, agent_dispatches TO gl_rw;

COMMIT;
