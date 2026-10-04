-- 002: activity memory -- the one funnel (memory.md §2). Exists before the first feature ships; it cannot be
-- backfilled. Every row is shipped to the tenant's one MaluDB as an `activity` episode by mcp/activity_ingest.py
-- (payload key "application": "gl" first). A RECORD'S HISTORY IS THIS TABLE (design §6): there is no audit table;
-- `before`/`after` carry what the timeline shows, and every MONEY EVENT carries `amount` and `currency` in `after`
-- — the audit questions (GL12, AR10, AP8, AI2) are answered from exactly these rows. A secure token is never here;
-- a customer's or vendor's email never beyond the party card.
BEGIN;

CREATE TABLE activity_log (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    occurred_at     timestamptz NOT NULL DEFAULT now(),
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    actor_customer_id bigint,                      -- a customer acting through the secure link (no FK: db/009 comes after)
    source          text NOT NULL DEFAULT 'web'
                    CHECK (source IN ('web', 'assistant', 'mcp', 'cron', 'agent', 'desk', 'webhook', 'portal', 'api', 'application')),
    action          text NOT NULL,                 -- entity.verb
    screen          text,
    route           text,                          -- "METHOD /path"
    entity_type     text,
    entity_id       bigint,
    before          jsonb,
    after           jsonb,                         -- changed fields only; amount + currency on every money event
    request_id      text,
    session_id      text,
    ip_address      inet,
    agent_run_id    bigint,                        -- the kernel's run id when an agent acted (no FK)
    department_id   bigint,
    site_id         bigint,
    journal_entry_id bigint,                       -- denormalised for every entry-scoped event (post, reverse, a line…)
    period_id       bigint,                        -- the fiscal period the event concerns (close, reopen, a posting into it)
    customer_id     bigint,                        -- the party an AR event concerns (no FK — history outlives a party)
    vendor_id       bigint,                        -- the party an AP event concerns
    bank_account_id bigint,                        -- the bank account a cash event concerns
    kernel_request_id text,                        -- the kernel's request id for a call that crossed to it
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX activity_log_actor_idx   ON activity_log (actor_member_id, occurred_at DESC);
CREATE INDEX activity_log_entity_idx  ON activity_log (entity_type, entity_id, occurred_at DESC);
CREATE INDEX activity_log_action_idx  ON activity_log (action, occurred_at DESC);
CREATE INDEX activity_log_time_idx    ON activity_log (occurred_at DESC);
CREATE INDEX activity_log_request_idx ON activity_log (request_id);
CREATE INDEX activity_log_entry_idx   ON activity_log (journal_entry_id, occurred_at DESC);
CREATE INDEX activity_log_period_idx  ON activity_log (period_id, occurred_at DESC);
CREATE INDEX activity_log_customer_idx ON activity_log (customer_id, occurred_at DESC);
CREATE INDEX activity_log_vendor_idx  ON activity_log (vendor_id, occurred_at DESC);
CREATE INDEX activity_log_bank_idx    ON activity_log (bank_account_id, occurred_at DESC);

GRANT INSERT, SELECT ON activity_log TO gl_rw;
REVOKE ALL ON activity_log FROM gl_records_ro, gl_activity_ro;

-- The ingest checkpoint: only ever moves forward.
CREATE TABLE activity_ingest_state (
    id          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    last_id     bigint NOT NULL DEFAULT 0,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO activity_ingest_state (id, last_id) VALUES (1, 0) ON CONFLICT DO NOTHING;
GRANT SELECT, UPDATE ON activity_ingest_state TO gl_rw;

COMMIT;
