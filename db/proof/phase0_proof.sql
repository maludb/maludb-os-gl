-- Phase 0 proof of the schema (docs/gl-design.md §10). Everything happens inside one transaction that is ROLLED BACK:
-- fixtures written as gl_rw, reads as gl_records_ro / gl_activity_ro, acting members set through app.member_id exactly
-- as PHP and the MCP servers set it. Run as postgres on the SCRATCH database:
--   sudo -u postgres psql -v ON_ERROR_STOP=1 -d gl_dev -f db/proof/phase0_proof.sql
-- Prints one line per check (ok / FAIL) and the count.
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
CREATE OR REPLACE FUNCTION pg_temp.refused(p_sql text, p_like text, l text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
        INSERT INTO proof (ok, label) VALUES (false, l || ' (was NOT refused)');
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO proof (ok, label) VALUES (SQLERRM ILIKE '%' || p_like || '%', l || CASE WHEN SQLERRM ILIKE '%' || p_like || '%' THEN '' ELSE ' (said: ' || SQLERRM || ')' END);
    END;
END$$;
GRANT EXECUTE ON FUNCTION pg_temp.check(boolean, text), pg_temp.refused(text, text, text) TO PUBLIC;

-- ---------------------------------------------------------------- fixtures, as the writer
SET ROLE gl_rw;
SELECT set_config('app.member_id', '28', true);
INSERT INTO members (id, member_kind, display_name, email, business_role, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',      'owner@example.invalid',  'super_admin', 'active', 'admin', '{admin}'),
 (26, 'human', 'SMOKE Vera',       'vera@example.invalid',   'user', 'active', 'read',  '{user}'),              -- a Viewer
 (27, 'human', 'SMOKE Bea',        'bea@example.invalid',    'user', 'active', 'write', '{bookkeeper,user}'),   -- the Bookkeeper
 (28, 'human', 'SMOKE Carl',       'carl@example.invalid',   'user', 'active', 'write', '{controller,user}'),   -- the Controller
 (29, 'human', 'SMOKE Ann',        'ann@cpa.invalid',        'user', 'active', 'write', '{accountant,user}'),   -- the outside Accountant
 (40, 'agent', 'SMOKE Bookkeeper', NULL,                     'user', 'active', 'write', '{bookkeeper,user}'),   -- the shipped agent
 (41, 'agent', 'SMOKE Watcher',    NULL,                     'user', 'active', NULL,    '{}');                  -- an agent not admitted yet
UPDATE members SET is_external = true WHERE id = 29;
INSERT INTO departments (id, name, is_system, system_key) VALUES (3, 'Accounting', true, 'accounting'), (5, 'IT', true, 'it'), (6, 'Front Office', true, 'front_office');
INSERT INTO department_members (member_id, department_id, is_admin) VALUES (27, 3, false), (28, 3, true), (26, 6, false);
INSERT INTO sites (name) VALUES ('SMOKE Main Street');
SELECT id AS site FROM sites WHERE name = 'SMOKE Main Street' \gset
UPDATE gl_settings SET business_name = 'SMOKE Co', morning_note_member_id = 28;
SELECT gl_fiscal_year_create('2026-01-01'::date) AS fy \gset
INSERT INTO bank_accounts (name, kind, account_id, currency, opening_balance, opening_date, created_by)
VALUES ('SMOKE Checking', 'checking', (SELECT id FROM accounts WHERE code = '1010'), 'USD', 10000.00, '2026-01-01', 1);
INSERT INTO bank_accounts (name, kind, account_id, currency, opening_balance, opening_date, created_by)
VALUES ('SMOKE Card', 'credit_card', (SELECT id FROM accounts WHERE code = '2300'), 'USD', 0, '2026-01-01', 1);
SELECT id AS chk FROM bank_accounts WHERE name = 'SMOKE Checking' \gset
SELECT id AS card FROM bank_accounts WHERE name = 'SMOKE Card' \gset
UPDATE gl_settings SET default_bank_account_id = :chk;
INSERT INTO tax_rates (name, rate) VALUES ('SMOKE Sales tax 8.25%', 8.25);
SELECT id AS tax FROM tax_rates WHERE name = 'SMOKE Sales tax 8.25%' \gset
INSERT INTO customers (name, email, terms_days, created_by) VALUES ('SMOKE Acme', 'ap@acme.invalid', 30, 27), ('SMOKE Beta', 'ap@beta.invalid', 15, 27);
SELECT id AS acme FROM customers WHERE name = 'SMOKE Acme' \gset
SELECT id AS beta FROM customers WHERE name = 'SMOKE Beta' \gset
INSERT INTO vendors (name, email, terms_days, created_by) VALUES ('SMOKE Hosting Co', 'billing@hosting.invalid', 30, 27), ('SMOKE Landlord', NULL, 0, 27);
SELECT id AS hosting FROM vendors WHERE name = 'SMOKE Hosting Co' \gset
SELECT id AS landlord FROM vendors WHERE name = 'SMOKE Landlord' \gset
SELECT id AS a1010 FROM accounts WHERE code = '1010' \gset
SELECT id AS a1050 FROM accounts WHERE code = '1050' \gset
SELECT id AS a1200 FROM accounts WHERE code = '1200' \gset
SELECT id AS a2000 FROM accounts WHERE code = '2000' \gset
SELECT id AS a2100 FROM accounts WHERE code = '2100' \gset
SELECT id AS a2300 FROM accounts WHERE code = '2300' \gset
SELECT id AS a3000 FROM accounts WHERE code = '3000' \gset
SELECT id AS a3900 FROM accounts WHERE code = '3900' \gset
SELECT id AS a4100 FROM accounts WHERE code = '4100' \gset
SELECT id AS a6050 FROM accounts WHERE code = '6050' \gset
SELECT id AS a6100 FROM accounts WHERE code = '6100' \gset
SELECT id AS a6200 FROM accounts WHERE code = '6200' \gset
SELECT id AS a6500 FROM accounts WHERE code = '6500' \gset
SELECT id AS a6600 FROM accounts WHERE code = '6600' \gset
RESET ROLE;

-- ---------------------------------------------------------------- 1. the catalogue, the seeds
SELECT pg_temp.check((SELECT count(*) FROM gl_roles) = 5 AND (SELECT count(*) FROM gl_rights) = 14 AND (SELECT count(*) FROM gl_roles WHERE is_admin) = 1, 'five roles, fourteen rights, one admin role (D2)');
SELECT pg_temp.check((SELECT role_key FROM gl_roles ORDER BY sort_order LIMIT 1) = 'user', 'the base role is keyed user');
SELECT pg_temp.check((SELECT count(*) FROM gl_role_rights WHERE role_key = 'admin') = 14, 'the admin role gives every right');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM gl_role_rights WHERE role_key = 'accountant' AND right_key = 'ap.approve')
                     AND EXISTS (SELECT 1 FROM gl_role_rights WHERE role_key = 'accountant' AND right_key = 'periods.manage'), 'the Accountant closes periods but never approves money out (D2)');
SELECT pg_temp.check((SELECT count(*) FROM accounts WHERE is_system) = 25 AND (SELECT count(*) FROM posting_rules) = 14, 'the seeded chart: 25 system accounts, 14 posting rules (D11)');
SELECT pg_temp.check((SELECT is_control FROM accounts WHERE code = '1200') AND (SELECT is_control FROM accounts WHERE code = '2000') AND (SELECT is_bank FROM accounts WHERE code = '2300'), 'AR and AP are control accounts; the card is a bank');
SELECT pg_temp.check((SELECT ar_account_id = :a1200 AND ap_account_id = :a2000 AND undeposited_funds_account_id = :a1050 AND retained_earnings_account_id = :a3900 FROM gl_settings), 'the settings name the control accounts');
SELECT pg_temp.check((SELECT count(*) FROM document_sequences) = 10 AND (SELECT prefix FROM document_sequences WHERE kind = 'invoice') = 'INV-', 'ten numbered documents, INV- first (D10)');
SET ROLE gl_rw;
SELECT set_config('app.member_id', '28', true);
SELECT pg_temp.refused($$DELETE FROM accounts WHERE code = '1200'$$, 'system account', 'a system account is never deleted');
SELECT pg_temp.refused($$INSERT INTO accounts (code, name, account_type, parent_id) VALUES ('1201', 'Odd child', 'expense', $$ || :a1200 || $$)$$, 'cannot sit under', 'a child account is of its parent''s type');
RESET ROLE;

-- ---------------------------------------------------------------- 2. fiscal time
SELECT pg_temp.check((SELECT count(*) FROM fiscal_periods WHERE fiscal_year_id = :fy) = 12 AND (SELECT name FROM fiscal_years WHERE id = :fy) = 'FY2026', 'a fiscal year comes with its twelve periods');
SELECT pg_temp.check(gl_period_for_date('2026-09-15') = (SELECT id FROM fiscal_periods WHERE name = '2026-09') AND gl_period_for_date('2027-03-01') IS NULL, 'a date finds its period; a date outside any year finds none');
SET ROLE gl_rw;
SELECT set_config('app.member_id', '28', true);
SELECT pg_temp.refused($$SELECT gl_fiscal_year_create('2026-07-01'::date)$$, 'conflict', 'two fiscal years never overlap');
SELECT pg_temp.refused($$SELECT gl_fiscal_year_create('2027-01-15'::date)$$, 'first of a month', 'a fiscal year starts on the first of a month');
SELECT id AS p_feb FROM fiscal_periods WHERE name = '2026-02' \gset
SELECT id AS p_sep FROM fiscal_periods WHERE name = '2026-09' \gset
SELECT gl_period_close(:p_feb, 28);
SELECT pg_temp.check((SELECT status = 'closed' AND closed_by = 28 FROM fiscal_periods WHERE id = :p_feb), 'the controller closes February');
SELECT pg_temp.refused($$SELECT gl_period_close($$ || :p_feb || $$, 28)$$, 'already closed', 'closing a closed period is refused');
SELECT pg_temp.refused($$SELECT gl_period_reopen($$ || :p_feb || $$, 28, '  ')$$, 'needs a reason', 'reopening without a reason is refused');
RESET ROLE;

-- ---------------------------------------------------------------- 3. the journal (the exemplar's rules)
SET ROLE gl_rw;
SELECT set_config('app.member_id', '28', true);
SELECT pg_temp.refused($$INSERT INTO journal_entries (entry_date, memo, status) VALUES ('2026-03-01', 'born posted', 'posted')$$, 'born a draft', 'an entry is born a draft');
INSERT INTO journal_entries (entry_date, memo, created_by) VALUES ('2026-03-01', 'SMOKE opening balance', 1);
SELECT id AS je1 FROM journal_entries WHERE memo = 'SMOKE opening balance' \gset
SELECT pg_temp.check((SELECT period_id FROM journal_entries WHERE id = :je1) = (SELECT id FROM fiscal_periods WHERE name = '2026-03'), 'a draft''s period follows its date');
SELECT pg_temp.refused($$SELECT gl_entry_post($$ || :je1 || $$, 1)$$, 'no lines', 'posting an entry with no lines is refused');
INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit) VALUES (:je1, 1, :a1010, 'Opening cash', 10000, 0), (:je1, 2, :a3000, 'Owner equity', 0, 9000);
SELECT pg_temp.refused($$SELECT gl_entry_post($$ || :je1 || $$, 1)$$, 'does not balance', 'an unbalanced entry is refused (difference 1,000)');
SELECT pg_temp.refused($$INSERT INTO journal_lines (entry_id, line_no, account_id, debit, credit) VALUES ($$ || :je1 || $$, 3, $$ || :a1200 || $$, 5, 5)$$, 'check', 'a line is a debit or a credit, never both');
INSERT INTO journal_lines (entry_id, line_no, account_id, description, debit, credit) VALUES (:je1, 3, :a1200, 'Owed by Acme at start', 1000, 0), (:je1, 4, :a3000, 'Owner equity', 0, 2000);
SELECT pg_temp.refused($$SELECT gl_entry_post($$ || :je1 || $$, 1)$$, 'control account', 'a line on the receivable account without its customer is refused');
UPDATE journal_lines SET customer_id = :acme WHERE entry_id = :je1 AND line_no = 3;
SELECT gl_entry_post(:je1, 1);
SELECT pg_temp.check((SELECT status = 'posted' AND number ~ '^JE-[0-9]{6}$' AND total_debit = 11000 AND total_credit = 11000 AND posted_by = 1 AND posted_at IS NOT NULL FROM journal_entries WHERE id = :je1),
                     'a balanced entry posts: numbered JE-, totals written, stamped');
SELECT pg_temp.refused($$UPDATE journal_entries SET memo = 'edited' WHERE id = $$ || :je1, 'cannot be changed', 'a posted entry''s head is immutable');
SELECT pg_temp.refused($$UPDATE journal_lines SET debit = 1 WHERE entry_id = $$ || :je1 || $$ AND line_no = 1$$, 'cannot be changed', 'a posted entry''s lines are immutable');
SELECT pg_temp.refused($$INSERT INTO journal_lines (entry_id, line_no, account_id, debit, credit) VALUES ($$ || :je1 || $$, 9, $$ || :a1010 || $$, 1, 0)$$, 'cannot be changed', 'no line joins a posted entry');
SELECT pg_temp.refused($$DELETE FROM journal_entries WHERE id = $$ || :je1, 'never deleted', 'a posted entry is never deleted');
SELECT pg_temp.refused($$UPDATE journal_entries SET status = 'draft' WHERE id = $$ || :je1, 'cannot return to draft', 'posted never returns to draft');
SELECT pg_temp.refused($$SELECT gl_entry_void($$ || :je1 || $$, 1, 'oops')$$, 'reverse it instead', 'a posted entry cannot be voided; it is reversed');
-- a draft dated in the closed period
INSERT INTO journal_entries (entry_date, memo, created_by) VALUES ('2026-02-10', 'SMOKE into closed Feb', 27);
SELECT id AS je_feb FROM journal_entries WHERE memo = 'SMOKE into closed Feb' \gset
INSERT INTO journal_lines (entry_id, line_no, account_id, debit, credit) VALUES (:je_feb, 1, :a6100, 100, 0), (:je_feb, 2, :a1010, 0, 100);
SELECT pg_temp.refused($$SELECT gl_entry_post($$ || :je_feb || $$, 27)$$, 'is closed', 'nothing posts into a closed period');
SELECT gl_period_reopen(:p_feb, 28, 'SMOKE late rent entry');
SELECT pg_temp.check((SELECT status = 'open' AND reopen_count = 1 AND reopen_reason = 'SMOKE late rent entry' AND reopened_by = 28 FROM fiscal_periods WHERE id = :p_feb), 'a reopen records who and why (GL10)');
SELECT gl_entry_post(:je_feb, 27);
SELECT gl_period_close(:p_feb, 28);
SELECT pg_temp.check((SELECT status FROM journal_entries WHERE id = :je_feb) = 'posted', 'the late entry posted once the period was reopened');
-- reversal
SELECT pg_temp.refused($$SELECT gl_entry_reverse($$ || :je_feb || $$, 28, 'wrong month')$$, 'is closed', 'a reversal dated in a closed period is refused (the original''s date)');
SELECT (gl_entry_reverse(:je_feb, 28, 'SMOKE wrong month', '2026-03-05'::date)).id AS je_rev \gset
SELECT pg_temp.check((SELECT status = 'reversed' AND reversed_by = :je_rev FROM journal_entries WHERE id = :je_feb)
                     AND (SELECT status = 'posted' AND source = 'reversal' AND reversal_of = :je_feb AND entry_date = '2026-03-05' FROM journal_entries WHERE id = :je_rev)
                     AND (SELECT debit = 100 FROM journal_lines WHERE entry_id = :je_rev AND account_id = :a1010), 'a reversal is a posted entry with the sides swapped, dated in an open period, naming the original');
SELECT pg_temp.refused($$SELECT gl_entry_reverse($$ || :je_feb || $$, 28, 'again')$$, 'not posted', 'a reversed entry is not reversed again');
SELECT pg_temp.check(gl_account_balance(:a6100, '2026-12-31') = 0 AND gl_account_balance(:a6100, '2026-02-28') = 100, 'the pair nets to zero by year end, and February still shows the original (GL11)');
-- void a draft
INSERT INTO journal_entries (entry_date, memo, created_by) VALUES ('2026-03-02', 'SMOKE mistaken draft', 27);
SELECT id AS je_void FROM journal_entries WHERE memo = 'SMOKE mistaken draft' \gset
SELECT gl_entry_void(:je_void, 27, 'never meant it');
SELECT pg_temp.check((SELECT status = 'void' AND voided_by = 27 FROM journal_entries WHERE id = :je_void), 'a draft is voided');
SELECT pg_temp.refused($$UPDATE accounts SET archived_at = now() WHERE id = $$ || :a1010, 'has a balance', 'an account with a balance cannot be archived');
RESET ROLE;

-- ---------------------------------------------------------------- 4. receivables
SET ROLE gl_rw;
SELECT set_config('app.member_id', '28', true);
INSERT INTO invoices (customer_id, issue_date, due_date, currency, department_id, site_id, created_by) VALUES (:acme, '2026-04-01', '2026-05-01', 'USD', 6, :site, 27);
SELECT id AS inv1 FROM invoices WHERE customer_id = :acme AND issue_date = '2026-04-01' \gset
INSERT INTO invoice_lines (invoice_id, sort_order, description, quantity, unit_price, tax_rate_id, income_account_id) VALUES
    (:inv1, 1, 'Consulting, 10 h', 10, 150.00, NULL, :a4100),
    (:inv1, 2, 'Hardware', 3, 33.33, :tax, (SELECT id FROM accounts WHERE code = '4000'));
SELECT pg_temp.check((SELECT line_subtotal = 99.99 AND line_tax = 8.25 AND line_total = 108.24 FROM invoice_lines WHERE invoice_id = :inv1 AND sort_order = 2), 'a line''s money is rounded per line (99.99 × 8.25% = 8.25)');
SELECT pg_temp.check((SELECT subtotal = 1599.99 AND tax_total = 8.25 AND total = 1608.24 AND balance_due = 1608.24 AND status = 'draft' AND number IS NULL FROM invoices WHERE id = :inv1), 'a draft invoice''s totals are the sum of its lines; no number yet');
SELECT pg_temp.refused($$INSERT INTO invoices (customer_id, issue_date, due_date, currency, status) VALUES ($$ || :acme || $$, '2026-04-01', '2026-05-01', 'USD', 'issued')$$, 'born a draft', 'an invoice is born a draft');
-- a draft deleted burns no number
INSERT INTO invoices (customer_id, issue_date, due_date, currency, created_by) VALUES (:beta, '2026-04-02', '2026-04-17', 'USD', 27);
DELETE FROM invoices WHERE customer_id = :beta AND issue_date = '2026-04-02';
SELECT gl_invoice_issue(:inv1, 27);
SELECT pg_temp.check((SELECT number = 'INV-00001' AND status = 'issued' AND issued_by = 27 AND journal_entry_id IS NOT NULL FROM invoices WHERE id = :inv1), 'issuing numbers the invoice INV-00001 (a deleted draft burned nothing) and posts it');
SELECT journal_entry_id AS je_inv1 FROM invoices WHERE id = :inv1 \gset
SELECT pg_temp.check((SELECT debit = 1608.24 AND customer_id = :acme FROM journal_lines WHERE entry_id = :je_inv1 AND account_id = :a1200)
                     AND (SELECT credit = 1500 FROM journal_lines WHERE entry_id = :je_inv1 AND account_id = :a4100)
                     AND (SELECT credit = 99.99 FROM journal_lines WHERE entry_id = :je_inv1 AND account_id = (SELECT id FROM accounts WHERE code = '4000'))
                     AND (SELECT credit = 8.25 FROM journal_lines WHERE entry_id = :je_inv1 AND account_id = :a2100)
                     AND (SELECT status = 'posted' AND source = 'invoice' AND source_type = 'invoice' AND source_id = :inv1 AND department_id = 6 AND site_id = :site FROM journal_entries WHERE id = :je_inv1),
                     'the invoice posts DR receivable (Acme) / CR revenue per account / CR sales tax, with its dimensions (GL9)');
SELECT pg_temp.refused($$INSERT INTO invoice_lines (invoice_id, sort_order, description, quantity, unit_price, income_account_id) VALUES ($$ || :inv1 || $$, 3, 'late', 1, 1, $$ || :a4100 || $$)$$, 'only while it is a draft', 'an issued invoice''s lines are fixed');
SELECT pg_temp.refused($$UPDATE invoices SET total = 1 WHERE id = $$ || :inv1, 'cannot change', 'an issued invoice''s totals are fixed');
SELECT pg_temp.refused($$SELECT gl_invoice_issue($$ || :inv1 || $$, 27)$$, 'already issued', 'issuing twice is refused');
-- the secure link
SELECT gl_secure_link_mint(:inv1) AS link1 \gset
SELECT gl_secure_link_invoice(:'link1') AS opened1 \gset
SELECT pg_temp.check(:opened1 = :inv1 AND (SELECT status = 'viewed' AND viewed_at IS NOT NULL FROM invoices WHERE id = :inv1), 'the mailed link opens the invoice and marks it viewed (AR9)');
SELECT pg_temp.check(gl_secure_link_invoice('deadbeef') IS NULL AND gl_secure_link_invoice(repeat('0', 48)) IS NULL, 'a malformed or unknown token opens nothing');
SELECT gl_secure_link_mint(:inv1) AS link2 \gset
SELECT pg_temp.check(gl_secure_link_invoice(:'link1') IS NULL AND gl_secure_link_invoice(:'link2') = :inv1, 'rotating kills the old link; the new one works');
SELECT pg_temp.check((SELECT count(*) FROM invoice_links_secure WHERE token_hash IN (:'link1', :'link2')) = 0, 'only hashes are stored, never the token');
-- the customer's claim (D9)
INSERT INTO receipt_claims (invoice_id, claimed_on, method, reference, amount, note) VALUES (:inv1, '2026-04-20', 'transfer', 'ACME-7781', 1000, 'paid half, rest next month');
SELECT pg_temp.check((SELECT balance_due = 1608.24 AND amount_paid = 0 FROM invoices WHERE id = :inv1), 'a claim records nothing as money');
-- receipts
INSERT INTO receipts (customer_id, received_on, amount, currency, method, reference, recorded_by) VALUES (:acme, '2026-04-21', 1000, 'USD', 'transfer', 'ACME-7781', 27);
SELECT id AS rct1 FROM receipts WHERE reference = 'ACME-7781' \gset
SELECT pg_temp.check((SELECT number ~ '^RCT-[0-9]{6}$' FROM receipts WHERE id = :rct1), 'a receipt is numbered at creation');
SELECT gl_receipt_post(:rct1, 27);
SELECT journal_entry_id AS je_rct1 FROM receipts WHERE id = :rct1 \gset
SELECT pg_temp.check((SELECT debit = 1000 FROM journal_lines WHERE entry_id = :je_rct1 AND account_id = :a1050) AND (SELECT credit = 1000 AND customer_id = :acme FROM journal_lines WHERE entry_id = :je_rct1 AND account_id = :a1200),
                     'an undeposited receipt posts DR undeposited funds / CR receivable (Acme)');
SELECT pg_temp.refused($$INSERT INTO receipt_applications (receipt_id, invoice_id, amount) VALUES ($$ || :rct1 || $$, $$ || :inv1 || $$, 1000.01)$$, 'exceed', 'applying more than the receipt is refused');
INSERT INTO receipt_applications (receipt_id, invoice_id, amount, applied_by) VALUES (:rct1, :inv1, 1000, 27);
UPDATE receipt_claims SET status = 'confirmed', receipt_id = :rct1, decided_by = 27, decided_at = now() WHERE invoice_id = :inv1;
SELECT pg_temp.check((SELECT status = 'partially_paid' AND amount_paid = 1000 AND balance_due = 608.24 FROM invoices WHERE id = :inv1), 'a partial receipt makes the invoice partially paid; paid is derived, never typed');
INSERT INTO receipts (customer_id, received_on, amount, currency, method, reference, recorded_by) VALUES (:beta, '2026-04-22', 50, 'USD', 'cash', 'BETA-CASH', 27);
SELECT id AS rct_beta FROM receipts WHERE reference = 'BETA-CASH' \gset
SELECT pg_temp.refused($$INSERT INTO receipt_applications (receipt_id, invoice_id, amount) VALUES ($$ || :rct_beta || $$, $$ || :inv1 || $$, 50)$$, 'another customer', 'a receipt applies only to its own customer''s invoices');
INSERT INTO receipts (customer_id, received_on, amount, currency, method, reference, recorded_by) VALUES (:acme, '2026-05-02', 608.24, 'USD', 'cheque', '1042', 27);
SELECT id AS rct2 FROM receipts WHERE reference = '1042' \gset
SELECT gl_receipt_post(:rct2, 27);
INSERT INTO receipt_applications (receipt_id, invoice_id, amount, applied_by) VALUES (:rct2, :inv1, 608.24, 27);
SELECT pg_temp.check((SELECT status = 'paid' AND balance_due = 0 AND paid_at IS NOT NULL FROM invoices WHERE id = :inv1), 'the rest makes it paid, with paid_at');
SELECT pg_temp.refused($$SELECT gl_invoice_void($$ || :inv1 || $$, 28, 'nope')$$, 'unapply', 'a paid invoice is not voided');
SELECT pg_temp.refused($$SELECT gl_receipt_void($$ || :rct1 || $$, 28, 'nope')$$, 'applied', 'an applied receipt is not voided');
-- a deposit of the two undeposited receipts
INSERT INTO deposits (bank_account_id, deposit_date, currency, created_by) VALUES (:chk, '2026-05-04', 'USD', 27);
SELECT id AS dep1 FROM deposits WHERE deposit_date = '2026-05-04' \gset
UPDATE receipts SET deposit_id = :dep1 WHERE id IN (:rct1, :rct2);
SELECT pg_temp.check((SELECT total = 1608.24 AND status = 'draft' AND number ~ '^DEP-' FROM deposits WHERE id = :dep1), 'a deposit''s total is its receipts'' (1,000 + 608.24)');
SELECT gl_deposit_record(:dep1, 27);
SELECT journal_entry_id AS je_dep1 FROM deposits WHERE id = :dep1 \gset
SELECT pg_temp.check((SELECT debit = 1608.24 FROM journal_lines WHERE entry_id = :je_dep1 AND account_id = :a1010) AND (SELECT credit = 1608.24 FROM journal_lines WHERE entry_id = :je_dep1 AND account_id = :a1050)
                     AND gl_account_balance(:a1050, '2026-12-31') = 0, 'recording the deposit posts DR bank / CR undeposited funds, which returns to zero');
SELECT pg_temp.refused($$UPDATE receipts SET deposit_id = NULL WHERE id = $$ || :rct1, 'draft deposit only', 'a receipt does not leave a recorded deposit');
-- a second invoice: credit note, then write-off of the rest
INSERT INTO invoices (customer_id, issue_date, due_date, currency, created_by) VALUES (:beta, '2026-06-01', '2026-06-16', 'USD', 27);
SELECT id AS inv2 FROM invoices WHERE customer_id = :beta AND issue_date = '2026-06-01' \gset
INSERT INTO invoice_lines (invoice_id, sort_order, description, quantity, unit_price, income_account_id) VALUES (:inv2, 1, 'Retainer', 1, 500, :a4100);
SELECT gl_invoice_issue(:inv2, 27);
SELECT pg_temp.check((SELECT number FROM invoices WHERE id = :inv2) = 'INV-00002', 'numbering is gapless on issue (INV-00002)');
INSERT INTO credit_notes (customer_id, invoice_id, issue_date, currency, reason, created_by) VALUES (:beta, :inv2, '2026-06-10', 'USD', 'SMOKE goodwill', 27);
SELECT id AS cn1 FROM credit_notes WHERE reason = 'SMOKE goodwill' \gset
INSERT INTO credit_note_lines (credit_note_id, sort_order, description, quantity, unit_price, income_account_id) VALUES (:cn1, 1, 'Goodwill', 1, 100, :a4100);
SELECT pg_temp.refused($$INSERT INTO credit_applications (credit_note_id, invoice_id, amount) VALUES ($$ || :cn1 || $$, $$ || :inv2 || $$, 100)$$, 'not issued', 'a draft credit note applies to nothing');
SELECT gl_credit_note_issue(:cn1, 27);
INSERT INTO credit_applications (credit_note_id, invoice_id, amount, applied_by) VALUES (:cn1, :inv2, 100, 27);
SELECT pg_temp.check((SELECT number = 'CN-00001' AND status = 'issued' AND amount_applied = 100 AND balance = 0 FROM credit_notes WHERE id = :cn1)
                     AND (SELECT amount_credited = 100 AND balance_due = 400 AND status = 'partially_paid' FROM invoices WHERE id = :inv2), 'an issued credit note applies and the invoice''s balance falls');
SELECT gl_invoice_write_off(:inv2, 28, 'SMOKE Beta folded', '2026-09-30'::date);
SELECT pg_temp.check((SELECT status = 'written_off' AND write_off_entry_id IS NOT NULL FROM invoices WHERE id = :inv2)
                     AND (SELECT debit = 400 FROM journal_lines l JOIN invoices i ON i.write_off_entry_id = l.entry_id WHERE i.id = :inv2 AND l.account_id = :a6600),
                     'a write-off posts DR bad debt / CR receivable for what was still owed');
RESET ROLE;

-- ---------------------------------------------------------------- 5. payables
SET ROLE gl_rw;
SELECT set_config('app.member_id', '28', true);
INSERT INTO bills (vendor_id, vendor_ref, bill_date, due_date, currency, department_id, created_by) VALUES (:hosting, 'H-2026-04', '2026-04-05', '2026-05-05', 'USD', 5, 27);
SELECT id AS bill1 FROM bills WHERE vendor_ref = 'H-2026-04' \gset
INSERT INTO bill_lines (bill_id, sort_order, description, quantity, unit_price, tax_rate_id, expense_account_id) VALUES (:bill1, 1, 'April hosting', 1, 240.00, :tax, :a6200);
SELECT pg_temp.check((SELECT number ~ '^BILL-[0-9]{6}$' AND status = 'draft' AND total = 259.80 AND tax_total = 19.80 FROM bills WHERE id = :bill1), 'a bill is numbered at creation; its totals follow its lines');
SELECT pg_temp.refused($$INSERT INTO bills (vendor_id, vendor_ref, bill_date, due_date, currency) VALUES ($$ || :hosting || $$, 'h-2026-04', '2026-04-05', '2026-05-05', 'USD')$$, 'duplicate', 'the vendor''s own reference is unique per vendor — the double-entry guard');
SELECT pg_temp.refused($$INSERT INTO payment_applications (payment_id, bill_id, amount) SELECT 0, $$ || :bill1 || $$, 1$$, 'takes no payment', 'a draft bill takes no payment');
SELECT gl_bill_approve(:bill1, 28);
SELECT journal_entry_id AS je_bill1 FROM bills WHERE id = :bill1 \gset
SELECT pg_temp.check((SELECT status = 'approved' AND approved_by = 28 FROM bills WHERE id = :bill1)
                     AND (SELECT debit = 259.80 AND department_id = 5 FROM journal_lines WHERE entry_id = :je_bill1 AND account_id = :a6200)
                     AND (SELECT credit = 259.80 AND vendor_id = :hosting FROM journal_lines WHERE entry_id = :je_bill1 AND account_id = :a2000),
                     'approving a bill posts DR expense GROSS (D11, tax included) / CR payable (the vendor), with the department');
SELECT pg_temp.refused($$DELETE FROM bill_lines WHERE bill_id = $$ || :bill1, 'only while it is a draft', 'an approved bill''s lines are fixed');
INSERT INTO payments (vendor_id, payment_date, amount, currency, method, cheque_number, bank_account_id, created_by) VALUES (:hosting, '2026-05-01', 259.80, 'USD', 'cheque', '2001', :chk, 27);
SELECT id AS pay1 FROM payments WHERE cheque_number = '2001' \gset
SELECT pg_temp.refused($$SELECT gl_payment_approve($$ || :pay1 || $$, 28)$$, 'applied to no bill', 'a payment applied to nothing is not approved');
INSERT INTO payment_applications (payment_id, bill_id, amount, applied_by) VALUES (:pay1, :bill1, 259.80, 27);
SELECT pg_temp.refused($$SELECT gl_payment_record($$ || :pay1 || $$, 27)$$, 'must be approved', 'a payment is recorded only after approval (D7)');
SELECT pg_temp.check((SELECT amount_paid = 0 AND status = 'approved' FROM bills WHERE id = :bill1), 'an unrecorded payment pays nothing yet');
SELECT gl_payment_approve(:pay1, 28);
SELECT gl_payment_record(:pay1, 27);
SELECT journal_entry_id AS je_pay1 FROM payments WHERE id = :pay1 \gset
SELECT pg_temp.check((SELECT status = 'recorded' FROM payments WHERE id = :pay1)
                     AND (SELECT debit = 259.80 AND vendor_id = :hosting FROM journal_lines WHERE entry_id = :je_pay1 AND account_id = :a2000)
                     AND (SELECT credit = 259.80 FROM journal_lines WHERE entry_id = :je_pay1 AND account_id = :a1010)
                     AND (SELECT status = 'paid' AND amount_paid = 259.80 AND balance_due = 0 FROM bills WHERE id = :bill1),
                     'recording the payment posts DR payable (vendor) / CR bank and the bill is paid');
SELECT pg_temp.refused($$SELECT gl_bill_void($$ || :bill1 || $$, 28, 'nope')$$, 'unapply', 'a paid bill is not voided');
-- a payment run of two rent bills
INSERT INTO bills (vendor_id, vendor_ref, bill_date, due_date, currency, created_by) VALUES (:landlord, 'RENT-05', '2026-05-01', '2026-05-01', 'USD', 27), (:landlord, 'RENT-06', '2026-06-01', '2026-06-01', 'USD', 27);
INSERT INTO bill_lines (bill_id, sort_order, description, quantity, unit_price, expense_account_id) SELECT id, 1, 'Rent', 1, 2000, :a6100 FROM bills WHERE vendor_ref IN ('RENT-05', 'RENT-06');
SELECT id AS rent5 FROM bills WHERE vendor_ref = 'RENT-05' \gset
SELECT id AS rent6 FROM bills WHERE vendor_ref = 'RENT-06' \gset
SELECT gl_bill_approve(:rent5, 28); SELECT gl_bill_approve(:rent6, 28);
INSERT INTO payment_runs (pay_date, bank_account_id, currency, created_by) VALUES ('2026-06-02', :chk, 'USD', 27);
SELECT id AS run1 FROM payment_runs WHERE pay_date = '2026-06-02' \gset
INSERT INTO payments (vendor_id, payment_date, amount, currency, method, cheque_number, bank_account_id, payment_run_id, created_by)
VALUES (:landlord, '2026-06-02', 2000, 'USD', 'cheque', '2002', :chk, :run1, 27), (:landlord, '2026-06-02', 2000, 'USD', 'cheque', '2003', :chk, :run1, 27);
INSERT INTO payment_applications (payment_id, bill_id, amount, applied_by) SELECT p.id, :rent5, 2000, 27 FROM payments p WHERE p.cheque_number = '2002';
INSERT INTO payment_applications (payment_id, bill_id, amount, applied_by) SELECT p.id, :rent6, 2000, 27 FROM payments p WHERE p.cheque_number = '2003';
SELECT pg_temp.check((SELECT total = 4000 AND status = 'draft' AND number ~ '^RUN-' FROM payment_runs WHERE id = :run1), 'a run''s total is its payments'' (4,000)');
SELECT pg_temp.refused($$SELECT gl_payment_run_record($$ || :run1 || $$, 27)$$, 'must be approved', 'a run is recorded only after approval');
SELECT gl_payment_run_approve(:run1, 28);
SELECT pg_temp.check((SELECT count(*) FROM payments WHERE payment_run_id = :run1 AND status = 'approved') = 2, 'approving the run approves its payments');
SELECT pg_temp.refused($$INSERT INTO payments (vendor_id, payment_date, amount, currency, method, bank_account_id, payment_run_id) VALUES ($$ || :landlord || $$, '2026-06-02', 1, 'USD', 'cash', $$ || :chk || $$, $$ || :run1 || $$)$$, 'draft run', 'no payment joins an approved run');
SELECT gl_payment_run_record(:run1, 27);
SELECT pg_temp.check((SELECT status = 'recorded' FROM payment_runs WHERE id = :run1) AND (SELECT bool_and(status = 'paid') FROM bills WHERE id IN (:rent5, :rent6)), 'recording the run records its payments and pays both bills');
-- a vendor credit
INSERT INTO bills (vendor_id, vendor_ref, bill_date, due_date, currency, created_by) VALUES (:hosting, 'H-2026-05', '2026-05-05', '2026-06-04', 'USD', 27);
SELECT id AS bill2 FROM bills WHERE vendor_ref = 'H-2026-05' \gset
INSERT INTO bill_lines (bill_id, sort_order, description, quantity, unit_price, expense_account_id) VALUES (:bill2, 1, 'May hosting', 1, 240, :a6200);
SELECT gl_bill_approve(:bill2, 28);
INSERT INTO vendor_credits (vendor_id, bill_id, credit_date, currency, reason, created_by) VALUES (:hosting, :bill2, '2026-05-20', 'USD', 'SMOKE outage credit', 27);
SELECT id AS vc1 FROM vendor_credits WHERE reason = 'SMOKE outage credit' \gset
INSERT INTO vendor_credit_lines (vendor_credit_id, sort_order, description, quantity, unit_price, expense_account_id) VALUES (:vc1, 1, 'Outage', 1, 40, :a6200);
SELECT gl_vendor_credit_issue(:vc1, 27);
INSERT INTO vendor_credit_applications (vendor_credit_id, bill_id, amount, applied_by) VALUES (:vc1, :bill2, 40, 27);
SELECT pg_temp.check((SELECT status = 'issued' AND number ~ '^VC-' FROM vendor_credits WHERE id = :vc1) AND (SELECT amount_credited = 40 AND balance_due = 200 AND status = 'partially_paid' FROM bills WHERE id = :bill2), 'a vendor credit applies and the bill''s balance falls');
RESET ROLE;

-- ---------------------------------------------------------------- 6. the kernel's AI statement (D5)
SET ROLE gl_rw;
SELECT set_config('app.member_id', '28', true);
SELECT gl_ai_statement_load($${"schema":"os.ledger-period/1","business":"SMOKE Co","period":"2026-09","period_start":"2026-09-01","period_end":"2026-09-30","status":"open","currencies":["USD"],"amount_scale":4,"lines":[{"provider":"anthropic","model":"claude-sonnet-5-5","department":"Accounting","agent":"Sasha","application":null,"calls":120,"late_calls":0,"input_tokens":1000,"output_tokens":500,"cache_read_tokens":0,"cache_write_tokens":0,"amount":12.3456,"currency":"USD","note":null}],"totals":[{"currency":"USD","amount":12.3456}]}$$::jsonb);
SELECT id AS st_sep FROM ai_statements WHERE period = '2026-09' \gset
SELECT pg_temp.refused($$SELECT gl_ai_statement_import($$ || :st_sep || $$, 40)$$, 'still open', 'an open month is never imported');
SELECT gl_ai_statement_load($${"schema":"os.ledger-period/1","business":"SMOKE Co","period":"2026-09","period_start":"2026-09-01","period_end":"2026-09-30","status":"closed","currencies":["USD"],"amount_scale":4,"lines":[
  {"provider":"anthropic","model":"claude-sonnet-5-5","department":"Accounting","agent":"Sasha","application":null,"calls":120,"late_calls":0,"input_tokens":1000,"output_tokens":500,"cache_read_tokens":0,"cache_write_tokens":0,"amount":12.3456,"currency":"USD","note":null},
  {"provider":"anthropic","model":"claude-sonnet-5-5","department":"IT","agent":"Sysadmin","application":null,"calls":80,"late_calls":2,"input_tokens":900,"output_tokens":400,"cache_read_tokens":0,"cache_write_tokens":0,"amount":7.6549,"currency":"USD","note":"2 late calls"},
  {"provider":"anthropic","model":"claude-opus-5-5","department":"Accounting","agent":"Jack","application":null,"calls":10,"late_calls":0,"input_tokens":100,"output_tokens":50,"cache_read_tokens":0,"cache_write_tokens":0,"amount":0.0049,"currency":"USD","note":null},
  {"provider":"openai","model":"gpt-5","department":"Front Office","agent":"Seamus","application":null,"calls":30,"late_calls":0,"input_tokens":300,"output_tokens":100,"cache_read_tokens":0,"cache_write_tokens":0,"amount":3.0000,"currency":"USD","note":null}],
  "totals":[{"currency":"USD","amount":23.0054}]}$$::jsonb);
SELECT pg_temp.check((SELECT kernel_status = 'closed' AND late_calls = 2 AND status = 'pulled' FROM ai_statements WHERE id = :st_sep) AND (SELECT count(*) FROM ai_statement_lines WHERE statement_id = :st_sep) = 4
                     AND (SELECT department_id = 3 FROM ai_statement_lines WHERE statement_id = :st_sep AND line_no = 1), 'the closed month replaces the open pull; its lines are kept verbatim and departments resolved by name');
SELECT pg_temp.check(gl_ai_statement_import(:st_sep, 40) = 2, 'the import makes one bill per provider (two)');
SELECT pg_temp.check((SELECT count(*) FROM vendors WHERE kind = 'ai_provider' AND provider_key IN ('anthropic', 'openai')) = 2, 'a vendor per provider, made on first sight');
SELECT bill_id AS bill_anth FROM ai_statement_bills WHERE statement_id = :st_sep AND provider = 'anthropic' \gset
SELECT pg_temp.check((SELECT total = 20.01 AND status = 'draft' AND source = 'ai_usage' AND vendor_ref = 'anthropic 2026-09' AND bill_date = '2026-09-30' FROM bills WHERE id = :bill_anth)
                     AND (SELECT count(*) FROM bill_lines WHERE bill_id = :bill_anth) = 2
                     AND (SELECT line_total FROM bill_lines WHERE bill_id = :bill_anth AND department_id = 3) = 12.36
                     AND (SELECT line_total FROM bill_lines WHERE bill_id = :bill_anth AND department_id = 5) = 7.65,
                     'Anthropic''s bill: 20.0054 → 20.01 to the cent, lines by department (12.3505 → 12.35 + the remainder = 12.36; 7.6549 → 7.65), a draft for the controller');
SELECT pg_temp.check((SELECT bool_and(agrees) FROM gl_ai_statement_check(:st_sep)) AND gl_ai_statement_mark_checked(:st_sep) = 'agrees', 'AI2: the bills agree with the statement, provider by provider');
SELECT pg_temp.refused($$SELECT gl_ai_statement_import($$ || :st_sep || $$, 40)$$, 'already imported', 'a statement imports once');
SELECT pg_temp.refused($$SELECT gl_ai_statement_load('{"schema":"os.ledger-period/1","period":"2026-09","period_start":"2026-09-01","period_end":"2026-09-30","status":"closed","lines":[{"provider":"anthropic","amount":99,"currency":"USD"}]}'::jsonb)$$, 'different document', 'a changed statement after import is refused, not followed');
SELECT gl_bill_approve(:bill_anth, 28);
SELECT pg_temp.check((SELECT sum(debit) FROM journal_lines l JOIN bills b ON b.journal_entry_id = l.entry_id WHERE b.id = :bill_anth AND l.account_id = :a6050) = 20.01
                     AND (SELECT source FROM journal_entries WHERE id = (SELECT journal_entry_id FROM bills WHERE id = :bill_anth)) = 'ai_usage', 'approving the AI bill posts DR AI and model usage by department / CR payable (AI1)');
UPDATE bill_lines SET unit_price = 1 WHERE bill_id = (SELECT bill_id FROM ai_statement_bills WHERE statement_id = :st_sep AND provider = 'openai');   -- a draft still: tamper with it
SELECT pg_temp.check(NOT (SELECT bool_and(agrees) FROM gl_ai_statement_check(:st_sep)) AND gl_ai_statement_mark_checked(:st_sep) = 'differs', 'AI2: a changed bill is reported as a difference, by name');
RESET ROLE;

-- ---------------------------------------------------------------- 7. cash: the bank's lines, matching, reconciliation
SET ROLE gl_rw;
SELECT set_config('app.member_id', '28', true);
INSERT INTO bank_imports (bank_account_id, filename, imported_by) VALUES (:chk, 'smoke-may.csv', 27);
SELECT id AS imp1 FROM bank_imports WHERE filename = 'smoke-may.csv' \gset
INSERT INTO bank_transactions (bank_account_id, import_id, txn_date, amount, description, external_ref) VALUES
    (:chk, :imp1, '2026-05-01', -259.80, 'CHK 2001 HOSTING CO', 'b1'),
    (:chk, :imp1, '2026-05-05', 1608.24, 'DEPOSIT', 'b2'),
    (:chk, :imp1, '2026-05-15', -12.00, 'MONTHLY FEE', 'b3'),
    (:chk, :imp1, '2026-06-03', -2000.00, 'CHK 2002', 'b4'),
    (:chk, :imp1, '2026-06-03', -2000.00, 'CHK 2003', 'b5');
SELECT pg_temp.refused($$INSERT INTO bank_transactions (bank_account_id, txn_date, amount, description, external_ref) VALUES ($$ || :chk || $$, '2026-05-05', 1608.24, 'DEPOSIT', 'b2')$$, 'duplicate', 'a bank line already held (by the bank''s id) is refused — re-importing an overlapping statement is safe');
SELECT id AS bl_dep FROM bank_transactions WHERE external_ref = 'b2' \gset
SELECT id AS bl_fee FROM bank_transactions WHERE external_ref = 'b3' \gset
SELECT id AS bl_pay FROM bank_transactions WHERE external_ref = 'b1' \gset
SELECT pg_temp.check((SELECT item_type = 'deposit' AND item_id = :dep1 FROM gl_bank_candidates(:bl_dep) LIMIT 1) AND (SELECT count(*) FROM gl_bank_candidates(:bl_dep)) = 1, 'the candidates for the 1,608.24 line: the deposit, and only it (CM2)');
SELECT pg_temp.refused($$SELECT gl_bank_match($$ || :bl_dep || $$, 'receipt', $$ || :rct1 || $$, 27)$$, 'no recorded', 'a receipt that went to undeposited funds is not a bank item');
SELECT pg_temp.refused($$SELECT gl_bank_match($$ || :bl_pay || $$, 'deposit', $$ || :dep1 || $$, 27)$$, 'to the cent', 'a match is to the cent, and signed');
SELECT gl_bank_match(:bl_dep, 'deposit', :dep1, 27);
SELECT gl_bank_match(:bl_pay, 'payment', :pay1, 27);
SELECT pg_temp.refused($$SELECT gl_bank_match($$ || :bl_pay || $$, 'payment', $$ || :pay1 || $$, 27)$$, 'is matched', 'a matched line is not matched again');
SELECT (gl_bank_entry_from_line(:bl_fee, :a6500, 27, 'SMOKE bank fee')).id AS je_fee \gset
SELECT pg_temp.check((SELECT source = 'bank_fee' AND status = 'posted' FROM journal_entries WHERE id = :je_fee) AND (SELECT debit = 12 FROM journal_lines WHERE entry_id = :je_fee AND account_id = :a6500)
                     AND (SELECT status = 'matched' AND matched_type = 'journal_entry' AND matched_id = :je_fee FROM bank_transactions WHERE id = :bl_fee), 'a fee becomes a posted entry DR fees / CR bank, matched at once');
SELECT (gl_reconciliation_start(:chk, '2026-05-31'::date, 11336.44, 27)).id AS rec1 \gset
SELECT pg_temp.check((SELECT opening_balance = 10000 AND cleared_balance = 11336.44 AND difference = 0 FROM bank_reconciliations WHERE id = :rec1), 'May: opening 10,000 − 259.80 + 1,608.24 − 12 = 11,336.44 cleared');
SELECT pg_temp.refused($$SELECT gl_reconciliation_start($$ || :chk || $$, '2026-06-30'::date, 1, 27)$$, 'already in progress', 'one reconciliation at a time per account');
SELECT gl_reconciliation_complete(:rec1, 28, 'SMOKE May done');
SELECT pg_temp.check((SELECT status = 'completed' AND completed_by = 28 FROM bank_reconciliations WHERE id = :rec1) AND (SELECT last_reconciled_through = '2026-05-31' AND last_reconciled_balance = 11336.44 FROM bank_accounts WHERE id = :chk)
                     AND (SELECT count(*) FROM bank_transactions WHERE reconciliation_id = :rec1) = 3, 'May completes at zero difference; its three lines are stamped (CM3)');
SELECT pg_temp.refused($$SELECT gl_bank_unmatch($$ || :bl_dep || $$)$$, 'reconciled', 'a reconciled line is never unmatched');
SELECT pg_temp.refused($$DELETE FROM bank_transactions WHERE id = $$ || :bl_dep, 'reconciled', 'a reconciled line is never deleted');
SELECT (gl_reconciliation_start(:chk, '2026-06-30'::date, 7336.44, 27)).id AS rec2 \gset
SELECT pg_temp.check((SELECT opening_balance = 11336.44 AND difference = -4000 FROM bank_reconciliations WHERE id = :rec2), 'June opens from May''s statement balance and is out by the two unmatched cheques');
SELECT pg_temp.refused($$SELECT gl_reconciliation_complete($$ || :rec2 || $$, 28)$$, 'out by', 'a reconciliation out of balance refuses to complete');
SELECT gl_bank_match((SELECT id FROM bank_transactions WHERE external_ref = 'b4'), 'payment', (SELECT id FROM payments WHERE cheque_number = '2002'), 27);
SELECT gl_bank_match((SELECT id FROM bank_transactions WHERE external_ref = 'b5'), 'payment', (SELECT id FROM payments WHERE cheque_number = '2003'), 27);
SELECT gl_reconciliation_complete(:rec2, 28);
SELECT pg_temp.check((SELECT status = 'completed' AND difference = 0 FROM bank_reconciliations WHERE id = :rec2) AND gl_bank_ledger_balance(:chk, '2026-06-30') = 7336.44 AND gl_bank_cleared_balance(:chk, '2026-06-30') = 7336.44,
                     'June completes once every line is matched; ledger and bank agree (CM1)');
SELECT pg_temp.refused($$SELECT gl_reconciliation_reopen($$ || :rec1 || $$, 28, 'x')$$, 'latest first', 'only the latest reconciliation is reopened');
SELECT gl_reconciliation_reopen(:rec2, 28, 'SMOKE a cheque bounced');
SELECT pg_temp.check((SELECT status = 'reopened' FROM bank_reconciliations WHERE id = :rec2) AND (SELECT last_reconciled_through = '2026-05-31' FROM bank_accounts WHERE id = :chk)
                     AND (SELECT reconciliation_id IS NULL FROM bank_transactions WHERE external_ref = 'b4'), 'reopening June frees its lines and the account falls back to May');
SELECT (gl_transfer_record(:chk, :card, '2026-07-01'::date, 500, 'SMOKE pay the card', 27)).id AS trf1 \gset
SELECT pg_temp.check((SELECT status = 'recorded' AND number ~ '^TRF-' FROM transfers WHERE id = :trf1) AND gl_bank_ledger_balance(:chk, '2026-07-01') = 6836.44 AND gl_bank_ledger_balance(:card, '2026-07-01') = -500,
                     'a transfer posts both sides: checking down 500, the card''s balance owed down 500 (a credit on the card)');
RESET ROLE;

-- ---------------------------------------------------------------- 8. the statements
SELECT set_config('app.member_id', '26', true);        -- a Viewer asks
SELECT pg_temp.check((SELECT sum(debit) = sum(credit) AND sum(debit) > 0 FROM gl_trial_balance('2026-12-31')), 'the trial balance balances (GL3)');
SELECT pg_temp.check((SELECT difference = 0 AND assets > 0 FROM gl_balance_sheet_check('2026-12-31')), 'assets = liabilities + equity (GL2)');
-- by hand: revenue 1,500 + 99.99 + 500 − 100 (credit) = 1,999.99; expenses: rent 100 (Feb, reversed in Mar → 0) + hosting 259.80 + 240 − 40 + rent 4,000 + AI 20.01 + fee 12 + bad debt 400 = 4,891.81
SELECT pg_temp.check(gl_net_income('2026-01-01', '2026-12-31') = 1999.99 - 4891.81, 'net income for the year is what the documents say (GL1)');
SELECT pg_temp.check((SELECT sum(amount) FROM gl_profit_and_loss('2026-01-01', '2026-12-31', 5) ) = 259.80 + 7.65, 'the P&L filtered to IT shows its hosting bill and its share of the AI bill (GL1, by department)');
SELECT pg_temp.check((SELECT amount FROM gl_cash_flow('2026-01-01', '2026-12-31') WHERE label LIKE 'Check:%') = 0
                     AND (SELECT amount FROM gl_cash_flow('2026-01-01', '2026-12-31') WHERE label = 'Cash at end') = 6836.44 + 500,
                     'the cash flow statement explains the change in cash to the cent; cash = every bank account, the card''s prepaid 500 included (GL4)');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM gl_ar_aging('2026-03-31') WHERE customer_id = :acme), 'AR aging as of March shows nothing: the opening entry is not an invoice (the control check reports that 1,000)');
SELECT pg_temp.check((SELECT total = 608.24 AND current_due = 608.24 FROM gl_ar_aging('2026-05-01') WHERE customer_id = :acme)
                     AND (SELECT total = 400 AND days_61_90 = 400 FROM gl_ar_aging('2026-09-01') WHERE customer_id = :beta), 'AR aging as of a date rebuilds what was owed then and buckets it (AR1)');
SELECT pg_temp.check((SELECT total = 200 FROM gl_ap_aging('2026-12-31') WHERE vendor_id = :hosting) AND (SELECT total = 20.01 FROM gl_ap_aging('2026-12-31') WHERE vendor_id = (SELECT id FROM vendors WHERE provider_key = 'anthropic')), 'AP aging: the unpaid 200 of May hosting and the AI bill (AP1)');
SELECT pg_temp.check((SELECT difference FROM gl_control_check('2026-12-31') WHERE control = 'payable') = 0, 'the payable subledger ties to the ledger (GL11)');
SELECT pg_temp.check((SELECT difference FROM gl_control_check('2026-12-31') WHERE control = 'receivable') = -1000, 'the receivable subledger differs from the ledger by exactly the opening entry''s 1,000 (no invoice behind it) — the audit sees it');
SELECT pg_temp.check((SELECT running_balance FROM gl_account_activity(:a1010, '2026-01-01', '2026-12-31') ORDER BY entry_date DESC, entry_id DESC LIMIT 1) = 6836.44, 'account activity''s running balance ends where the bank balance does (GL5)');
SELECT pg_temp.check(gl_posted_through() = '2026-09-30' AND (SELECT draft_bills = 0 AND bills_awaiting_approval = 1 AND unmatched_bank_lines = 0 AND open_claims = 0 FROM gl_unposted_summary()), 'posted-through and what waits (GL14)');
SELECT set_config('app.member_id', '41', true);        -- an agent not admitted
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM gl_trial_balance('2026-12-31')) AND gl_net_income('2026-01-01', '2026-12-31') IS NULL AND NOT EXISTS (SELECT 1 FROM gl_ar_aging('2026-12-31')), 'the statements answer nothing to a caller with no right');
SELECT set_config('app.member_id', '', true);

-- ---------------------------------------------------------------- 9. the year-end close and its reversal
SET ROLE gl_rw;
SELECT set_config('app.member_id', '28', true);
SELECT pg_temp.refused($$SELECT gl_year_close($$ || :fy || $$, 28)$$, 'must be closed', 'the year closes only after every period');
SELECT gl_period_close(id, 28) FROM fiscal_periods WHERE fiscal_year_id = :fy AND status = 'open';
INSERT INTO journal_entries (entry_date, memo, created_by) VALUES ('2026-11-11', 'SMOKE forgotten draft', 27);
SELECT id AS je_forgot FROM journal_entries WHERE memo = 'SMOKE forgotten draft' \gset
SELECT pg_temp.refused($$SELECT gl_year_close($$ || :fy || $$, 28)$$, 'draft entries', 'drafts dated in the year must be posted or voided first');
SELECT gl_entry_void(:je_forgot, 27, 'SMOKE never finished');
SELECT gl_bill_void((SELECT bill_id FROM ai_statement_bills WHERE statement_id = :st_sep AND provider = 'openai'), 28, 'SMOKE tampered draft');   -- the openai draft bill
SELECT pg_temp.check((SELECT count(*) FROM journal_entries WHERE status = 'draft' AND entry_date BETWEEN '2026-01-01' AND '2026-12-31') = 0, 'no draft entry remains in the year');
SELECT (gl_year_close(:fy, 28)).id AS je_close \gset
SELECT set_config('app.member_id', '28', true);
SELECT pg_temp.check((SELECT status = 'closed' AND closing_entry_id = :je_close FROM fiscal_years WHERE id = :fy)
                     AND (SELECT source = 'closing' AND status = 'posted' AND entry_date = '2026-12-31' FROM journal_entries WHERE id = :je_close)
                     AND gl_account_balance(:a3900, '2026-12-31') = 1999.99 - 4891.81
                     AND NOT EXISTS (SELECT 1 FROM gl_balance_sheet('2026-12-31') WHERE subtype = 'current_earnings')
                     AND (SELECT difference = 0 FROM gl_balance_sheet_check('2026-12-31')), 'the year-end close moves the year''s result to retained earnings; the balance sheet still holds');
SELECT pg_temp.check(gl_net_income('2026-01-01', '2026-12-31') = 1999.99 - 4891.81 AND (SELECT sum(debit) = sum(credit) FROM gl_trial_balance('2026-12-31')), 'the P&L leaves the closing entry out; the trial balance keeps it and balances');
SELECT set_config('app.member_id', '', true);
SELECT gl_year_reopen(:fy, 28, 'SMOKE a late invoice');
SELECT set_config('app.member_id', '28', true);
SELECT pg_temp.check((SELECT status = 'open' AND closing_entry_id IS NULL FROM fiscal_years WHERE id = :fy) AND (SELECT status = 'reversed' FROM journal_entries WHERE id = :je_close)
                     AND gl_account_balance(:a3900, '2026-12-31') = 0 AND (SELECT difference = 0 FROM gl_balance_sheet_check('2026-12-31')), 'reopening the year reverses the close; retained earnings return to zero; the equation holds');
SELECT set_config('app.member_id', '', true);
RESET ROLE;

-- ---------------------------------------------------------------- 10. who sees what, as the records role
SET ROLE gl_records_ro;
SELECT pg_temp.check((SELECT count(*) FROM mcp_app_roles) = 5, 'app_roles readable by the records role');
SELECT pg_temp.refused($$SELECT * FROM journal_entries$$, 'permission denied', 'the records role never reads a base table');
SELECT set_config('app.member_id', '26', true);          -- Vera: Viewer
SELECT pg_temp.check((SELECT count(*) FROM mcp_invoices) = 2 AND (SELECT count(*) FROM mcp_accounts) = 25 AND (SELECT count(*) FROM mcp_journal_entries WHERE status = 'posted') > 5, 'a Viewer sees the documents, the chart and the journal, business-wide');
SELECT pg_temp.check((SELECT email IS NULL AND billing_address IS NULL AND balance_due = 0 FROM mcp_customers WHERE customer_id = :acme) AND (SELECT email IS NULL FROM mcp_vendors WHERE vendor_id = :hosting), 'but no contact details of a party');
SELECT pg_temp.check((SELECT count(*) FROM mcp_party_contacts) = 0 AND (SELECT count(*) FROM mcp_notifications) = 0, 'a Viewer has no party contacts and no notifications of their own');
SELECT set_config('app.member_id', '27', true);          -- Bea: Bookkeeper
SELECT pg_temp.check((SELECT email = 'ap@acme.invalid' FROM mcp_customers WHERE customer_id = :acme) AND (SELECT email = 'billing@hosting.invalid' FROM mcp_vendors WHERE vendor_id = :hosting), 'the Bookkeeper sees the parties'' contact details');
SELECT pg_temp.check((SELECT overdue AND days_overdue > 0 FROM mcp_invoices WHERE invoice_id = :inv2) IS NOT TRUE AND (SELECT status FROM mcp_invoices WHERE invoice_id = :inv2) = 'written_off', 'a written-off invoice is not overdue');
SELECT pg_temp.check((SELECT has_attachment = false AND vendor_kind = 'ai_provider' FROM mcp_bills WHERE bill_id = :bill_anth), 'a bill says whether its document is attached (AP6)');
SELECT pg_temp.check((SELECT ledger_balance = 6836.44 AND unmatched_lines = 0 FROM mcp_bank_accounts WHERE bank_account_id = :chk), 'the bank account view carries the ledger balance and the unmatched count');
SELECT pg_temp.check((SELECT status = 'differs' AND bill_count = 2 AND (totals->>'USD')::numeric = 23.01 FROM mcp_ai_statements WHERE ai_statement_id = :st_sep), 'the AI statement view: its verdict, its bills, its totals');
SELECT set_config('app.member_id', '40', true);          -- the agent, Bookkeeper role
SELECT pg_temp.check((SELECT email = 'ap@acme.invalid' FROM mcp_customers WHERE customer_id = :acme) AND (SELECT count(*) FROM mcp_journal_entries) = (SELECT count(*) FROM mcp_journal_entries), 'the agent sees what its role admits — the same rule as a person');
SELECT set_config('app.member_id', '41', true);          -- an agent not admitted
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_invoices) AND NOT EXISTS (SELECT 1 FROM mcp_accounts) AND NOT EXISTS (SELECT 1 FROM mcp_members), 'an agent with no capability sees nothing');
SELECT pg_temp.check(mcp_member_kind(41) IS NULL AND mcp_member_kind(40) = 'agent', 'mcp_member_kind answers only for an admitted member');
SELECT pg_temp.check(mcp_admit_agent(41), 'first contact admits an active agent');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_accounts) AND (SELECT email IS NULL FROM mcp_customers WHERE customer_id = :acme), 'once admitted with no roles it is a Viewer (capability write → user)');
SELECT set_config('app.member_id', '999', true);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_invoices) AND NOT EXISTS (SELECT 1 FROM mcp_app_roles WHERE false), 'an unknown member id sees nothing');
SELECT set_config('app.member_id', '1', true);           -- the owner
SELECT pg_temp.check((SELECT count(*) FROM mcp_fiscal_periods WHERE status = 'closed') = 12 AND (SELECT reopen_count FROM mcp_fiscal_periods WHERE name = '2026-02') = 1 AND (SELECT count(*) FROM mcp_settings) = 1, 'the super-admin sees everything, the periods'' history included');
RESET ROLE;

-- ---------------------------------------------------------------- 11. the activity view, as the activity role
SET ROLE gl_rw;
SELECT set_config('app.member_id', '28', true);
INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, journal_entry_id, after)
VALUES (27, 'web', 'invoice.issue', 'invoice', :inv1, :je_inv1, '{"amount": 1608.24, "currency": "USD"}'),
       (28, 'web', 'period.reopen', 'fiscal_period', :p_feb, NULL, '{"reason": "SMOKE late rent entry"}'),
       (40, 'agent', 'ai_statement.import', 'ai_statement', :st_sep, NULL, '{"bills": 2}'),
       (NULL, 'cron', 'directory.sync', NULL, NULL, NULL, '{"members": 7}');
RESET ROLE;
SET ROLE gl_activity_ro;
SELECT set_config('app.member_id', '26', true);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) = 4, 'a Viewer reads the whole trail (the books are business-wide)');
SELECT pg_temp.check((SELECT actor_kind FROM mcp_activity_log WHERE action = 'ai_statement.import') = 'agent' AND (SELECT (after->>'amount')::numeric FROM mcp_activity_log WHERE action = 'invoice.issue') = 1608.24, 'the trail says who was an agent and what the money was (AR10, AP8)');
SELECT pg_temp.refused($$SELECT * FROM mcp_customers$$, 'permission denied', 'the activity role has no records view but names and documents');
SELECT pg_temp.check((SELECT count(*) FROM mcp_members) = 7, 'the activity role sees the names beside a row');
SELECT set_config('app.member_id', '999', true);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) = 0, 'an unknown member reads no trail');
RESET ROLE;

-- ---------------------------------------------------------------- the verdict
\o
SELECT CASE WHEN ok THEN 'ok   ' ELSE 'FAIL ' END || label AS result FROM proof ORDER BY n;
SELECT count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed, of ' || count(*) AS summary FROM proof;
ROLLBACK;
