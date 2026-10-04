-- 004: General Ledger's roles and the rights they give (kernel db/145; roles-and-rights.md; the design §3, D2).
--
-- Published to the kernel by app_roles on the records MCP (os.app-roles/1). The kernel grants a SET of roles; the
-- claims and the feed carry them back into members.roles. What a role lets its holder do is the ledger's to enforce:
-- gl_has_right(right) answers from the catalogue and the mirror. The base role's KEY IS `user` on purpose (the
-- installer's convention) — but the ledger is NEVER installed with --grant-standing-departments (D3, K14): the
-- books are granted person by person.
--
-- THERE IS NO "MY" JOURNAL ENTRY (design §3): the books are the business's, and a holder of a role sees every
-- record the role admits, business-wide. Departments and sites are FILTERS on the statements, never walls — a trial
-- balance with rows missing is worse than none (decision taken while building, recorded in the design §16).
BEGIN;

CREATE TABLE gl_rights (
    right_key   text PRIMARY KEY CHECK (right_key ~ '^[a-z][a-z0-9_.]{0,59}$'),
    description text NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0
);
CREATE TABLE gl_roles (
    role_key    text PRIMARY KEY CHECK (role_key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    description text NOT NULL,
    capability  text NOT NULL CHECK (capability IN ('read', 'write', 'admin')),
    is_admin    boolean NOT NULL DEFAULT false,
    sort_order  integer NOT NULL DEFAULT 0,
    CHECK (NOT is_admin OR capability = 'admin')
);
CREATE UNIQUE INDEX gl_roles_one_admin ON gl_roles (is_admin) WHERE is_admin;
CREATE TABLE gl_role_rights (
    role_key  text NOT NULL REFERENCES gl_roles(role_key) ON DELETE CASCADE,
    right_key text NOT NULL REFERENCES gl_rights(right_key) ON DELETE CASCADE,
    PRIMARY KEY (role_key, right_key)
);

INSERT INTO gl_rights (right_key, description, sort_order) VALUES
 ('reports.view',       'Read the statements, the aging, the dashboards and account activity', 1),
 ('parties.view',       'See customers and vendors (names, terms, balances — not their contact details)', 2),
 ('documents.view',     'See invoices, bills, receipts, payments and the journal', 3),
 ('journal.write',      'Draft journal entries, post them, reverse one''s own posted entries', 4),
 ('ar.write',           'Customers and their contact details; invoices, credit notes, receipts, deposits; send invoices, statements and reminders', 5),
 ('ap.write',           'Vendors and their contact details; bills, vendor credits; record payments that a person made', 6),
 ('bank.write',         'Import and enter bank lines, match and unmatch them, transfers, start a reconciliation', 7),
 ('attachments.write',  'Attach documents to any record', 8),
 ('ap.approve',         'Approve bills and payment runs', 9),
 ('bank.reconcile',     'Complete and reopen a bank reconciliation', 10),
 ('periods.manage',     'Close and reopen fiscal periods, run the year-end close', 11),
 ('journal.override',   'Reverse any posted entry, void a draft, write off, post into a reopened period', 12),
 ('exports.download',   'Download every statement and ledger as a file', 13),
 ('settings.manage',    'Run the ledger: the chart, fiscal years, numbering, tax rates, posting rules, items, bank accounts, recurring templates, sites, settings', 14);

INSERT INTO gl_roles (role_key, name, description, capability, is_admin, sort_order) VALUES
 ('user',       'Viewer',     'Reads the statements, the aging and the documents. A manager, or anyone the business lets see its figures.', 'read',  false, 1),
 ('bookkeeper', 'Bookkeeper', 'Keeps the books: journals, invoices, bills, receipts, recorded payments, the bank match; drafts a reconciliation.', 'write', false, 2),
 ('controller', 'Controller', 'Approves bills and payment runs, completes reconciliations, closes and reopens periods, reverses, voids, writes off.', 'write', false, 3),
 ('accountant', 'Accountant', 'The outside accountant: reads everything, adjusts, exports, closes periods — but never approves money out.', 'write', false, 4),
 ('admin',      'GL admin',   'Runs the ledger: everything a Controller does, plus the settings. A super-admin holds this role.', 'admin', true, 5);

INSERT INTO gl_role_rights (role_key, right_key)
SELECT 'user', r FROM unnest(ARRAY['reports.view','parties.view','documents.view']) r
UNION ALL SELECT 'bookkeeper', r FROM unnest(ARRAY['reports.view','parties.view','documents.view',
                                                   'journal.write','ar.write','ap.write','bank.write','attachments.write']) r
UNION ALL SELECT 'controller', r FROM unnest(ARRAY['reports.view','parties.view','documents.view',
                                                   'journal.write','ar.write','ap.write','bank.write','attachments.write',
                                                   'ap.approve','bank.reconcile','periods.manage','journal.override','exports.download']) r
UNION ALL SELECT 'accountant', r FROM unnest(ARRAY['reports.view','parties.view','documents.view',
                                                   'journal.write','ar.write','ap.write','bank.write','attachments.write',
                                                   'bank.reconcile','periods.manage','journal.override','exports.download']) r
UNION ALL SELECT 'admin', right_key FROM gl_rights;

-- The rule: a right the member's roles give. The member must be active and admitted. A member the kernel has not yet
-- sent roles for (roles = '{}') is read from the capability: admin = admin, anything else = user. A super-admin holds
-- every right (the kernel lists the admin role for them; this is the belt to that brace).
CREATE OR REPLACE FUNCTION gl_has_right(p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM members m
          JOIN gl_role_rights rr ON rr.right_key = p_right
               AND (rr.role_key = ANY (m.roles)
                    OR (cardinality(m.roles) = 0 AND rr.role_key = CASE m.capability WHEN 'admin' THEN 'admin' ELSE 'user' END)
                    OR (m.member_kind = 'human' AND m.business_role = 'super_admin'))
         WHERE m.id = app_current_member_id() AND m.status = 'active' AND m.capability IS NOT NULL);
END$$;

-- Runs the ledger: the super-admin, or a human holding settings.manage.
CREATE OR REPLACE FUNCTION gl_is_admin() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_super_admin() OR (app_member_kind() = 'human' AND gl_has_right('settings.manage'));
END$$;

-- What app_roles answers, in one read.
CREATE OR REPLACE VIEW mcp_app_roles AS
SELECT r.role_key, r.name, r.description, r.capability, r.is_admin, r.sort_order,
       COALESCE((SELECT array_agg(rr.right_key ORDER BY g.sort_order) FROM gl_role_rights rr
                   JOIN gl_rights g ON g.right_key = rr.right_key WHERE rr.role_key = r.role_key), '{}') AS rights
FROM gl_roles r;

GRANT SELECT ON gl_rights, gl_roles, gl_role_rights, mcp_app_roles TO gl_rw, gl_records_ro;
GRANT EXECUTE ON FUNCTION gl_has_right(text), gl_is_admin() TO gl_rw, gl_records_ro, gl_activity_ro;

COMMIT;
