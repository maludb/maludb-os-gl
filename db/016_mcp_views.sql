-- 016: WHO SEES WHAT, and the mcp_* visibility views — the only thing the two read roles see (memory.md §1). Every WHERE
-- clause IS the row rule; the servers never filter in Python. Caller checks are scalar subqueries, tested once per
-- statement. The rule (design §3, db/004): the books are the business's — a holder of a right sees every row the
-- right admits, business-wide; departments and sites are filters, never walls. Contact details of a party are shown
-- to those who write that ledger (ar.write for customers, ap.write for vendors). A person's own notifications and
-- tokens are theirs alone. Plus the two functions the MCP servers need at first contact: mcp_admit_agent() and
-- mcp_member_kind().
BEGIN;

CREATE OR REPLACE FUNCTION mcp_admit_agent(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE members SET capability = 'write', synced_at = now()
     WHERE id = p_member_id AND member_kind = 'agent' AND status = 'active' AND capability IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;
REVOKE ALL ON FUNCTION mcp_admit_agent(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_admit_agent(bigint) TO gl_records_ro, gl_activity_ro, gl_rw;

CREATE OR REPLACE FUNCTION mcp_member_kind(p_member_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT member_kind FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL;
$$;
REVOKE ALL ON FUNCTION mcp_member_kind(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_member_kind(bigint) TO gl_records_ro, gl_activity_ro, gl_rw;

-- ---------------------------------------------------------------------------------------------
-- People, departments, sites, settings: every admitted member.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_members WITH (security_barrier = true) AS
SELECT m.id AS member_id, m.display_name, m.member_kind, m.business_role, m.is_external, m.status, m.capability, m.roles, m.job_title, m.timezone,
       CASE WHEN m.id = app_current_member_id() THEN m.email::text END AS email,
       (SELECT array_agg(dm.department_id) FROM department_members dm WHERE dm.member_id = m.id AND dm.left_at IS NULL) AS department_ids
  FROM members m
 WHERE (SELECT app_is_active_member()) AND m.status = 'active';

CREATE OR REPLACE VIEW mcp_departments WITH (security_barrier = true) AS
SELECT d.id AS department_id, d.name, d.description, d.parent_id, d.manager_member_id, d.is_system, d.system_key, d.archived_at
  FROM departments d WHERE (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_sites WITH (security_barrier = true) AS
SELECT s.id AS site_id, s.name, s.kernel_location_id, s.address, s.archived_at
  FROM sites s WHERE (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.business_name, s.business_address, s.base_currency, s.fiscal_year_start_month, s.timezone, s.ar_account_id, s.ap_account_id,
       s.undeposited_funds_account_id, s.sales_tax_account_id, s.ai_usage_account_id, s.retained_earnings_account_id, s.bad_debt_account_id,
       s.default_bank_account_id, s.invoice_terms_days, s.bill_terms_days, s.link_lifetime_days, s.reminder_days, s.ai_statement_poll,
       s.morning_note_member_id, s.updated_at
  FROM gl_settings s WHERE s.id = 1 AND (SELECT app_is_active_member());

-- ---------------------------------------------------------------------------------------------
-- The chart, the rules, fiscal time, tax, items: reports.view (every role has it).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_accounts WITH (security_barrier = true) AS
SELECT a.id AS account_id, a.code, a.name, a.account_type, t.label AS type_label, gl_normal_balance(a.account_type) AS normal_balance, a.subtype,
       a.parent_id, a.is_bank, a.is_control, a.is_system, a.description, a.archived_at, a.created_at, a.updated_at
  FROM accounts a JOIN account_types t ON t.account_type = a.account_type
 WHERE (SELECT gl_has_right('reports.view'));

CREATE OR REPLACE VIEW mcp_posting_rules WITH (security_barrier = true) AS
SELECT r.id AS posting_rule_id, r.source, r.match_key, r.debit_account_id, da.code AS debit_account_code, da.name AS debit_account_name,
       r.credit_account_id, ca.code AS credit_account_code, ca.name AS credit_account_name, r.tax_account_id, ta.code AS tax_account_code,
       r.note, r.active, r.updated_at
  FROM posting_rules r
  LEFT JOIN accounts da ON da.id = r.debit_account_id
  LEFT JOIN accounts ca ON ca.id = r.credit_account_id
  LEFT JOIN accounts ta ON ta.id = r.tax_account_id
 WHERE (SELECT gl_has_right('reports.view'));

CREATE OR REPLACE VIEW mcp_fiscal_years WITH (security_barrier = true) AS
SELECT y.id AS fiscal_year_id, y.name, y.start_date, y.end_date, y.status, y.closing_entry_id, y.closed_at, y.closed_by, y.reopened_at, y.reopened_by,
       (SELECT count(*) FROM fiscal_periods p WHERE p.fiscal_year_id = y.id AND p.status = 'open') AS open_periods
  FROM fiscal_years y WHERE (SELECT gl_has_right('reports.view'));

CREATE OR REPLACE VIEW mcp_fiscal_periods WITH (security_barrier = true) AS
SELECT p.id AS fiscal_period_id, p.fiscal_year_id, y.name AS fiscal_year_name, p.name, p.start_date, p.end_date, p.status, p.closed_at, p.closed_by,
       cb.display_name AS closed_by_name, p.reopened_at, p.reopened_by, rb.display_name AS reopened_by_name, p.reopen_reason, p.reopen_count,
       (SELECT count(*) FROM journal_entries e WHERE e.period_id = p.id AND e.status IN ('posted', 'reversed')) AS posted_entries,
       (SELECT count(*) FROM journal_entries e WHERE e.period_id = p.id AND e.status = 'draft') AS draft_entries
  FROM fiscal_periods p
  JOIN fiscal_years y ON y.id = p.fiscal_year_id
  LEFT JOIN members cb ON cb.id = p.closed_by
  LEFT JOIN members rb ON rb.id = p.reopened_by
 WHERE (SELECT gl_has_right('reports.view'));

CREATE OR REPLACE VIEW mcp_tax_rates WITH (security_barrier = true) AS
SELECT t.id AS tax_rate_id, t.name, t.rate, t.is_default, t.archived_at FROM tax_rates t WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_items WITH (security_barrier = true) AS
SELECT i.id AS item_id, i.code, i.name, i.kind, i.description, i.unit, i.unit_price, i.income_account_id, i.expense_account_id, i.tax_rate_id, i.archived_at
  FROM items i WHERE (SELECT gl_has_right('documents.view'));

-- ---------------------------------------------------------------------------------------------
-- The journal: documents.view.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_journal_entries WITH (security_barrier = true) AS
SELECT e.id AS journal_entry_id, e.number, e.entry_date, e.period_id, p.name AS period_name, p.status AS period_status, e.memo, e.source,
       e.source_type, e.source_id, e.status, e.reversal_of, ro.number AS reversal_of_number, e.reversed_by, rb.number AS reversed_by_number,
       e.currency, e.total_debit, e.total_credit, e.department_id, d.name AS department_name, e.site_id, s.name AS site_name,
       e.created_by, cm.display_name AS created_by_name, cm.member_kind AS created_by_kind, e.posted_by, pm.display_name AS posted_by_name, e.posted_at,
       e.voided_by, e.voided_at, e.void_reason, e.reversal_reason, e.created_at, e.updated_at,
       (SELECT count(*) FROM journal_lines l WHERE l.entry_id = e.id) AS line_count,
       (SELECT count(*) FROM attachments a WHERE a.record_type = 'journal_entry' AND a.record_id = e.id) AS attachment_count
  FROM journal_entries e
  LEFT JOIN fiscal_periods p ON p.id = e.period_id
  LEFT JOIN journal_entries ro ON ro.id = e.reversal_of
  LEFT JOIN journal_entries rb ON rb.id = e.reversed_by
  LEFT JOIN departments d ON d.id = e.department_id
  LEFT JOIN sites s ON s.id = e.site_id
  LEFT JOIN members cm ON cm.id = e.created_by
  LEFT JOIN members pm ON pm.id = e.posted_by
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_journal_lines WITH (security_barrier = true) AS
SELECT l.id AS journal_line_id, l.entry_id AS journal_entry_id, e.number AS entry_number, e.entry_date, e.status AS entry_status, e.source,
       l.line_no, l.account_id, a.code AS account_code, a.name AS account_name, a.account_type, l.description, l.debit, l.credit,
       l.department_id, d.name AS department_name, l.site_id, s.name AS site_name, l.customer_id, c.name AS customer_name, l.vendor_id, v.name AS vendor_name, l.tax_rate_id
  FROM journal_lines l
  JOIN journal_entries e ON e.id = l.entry_id
  JOIN accounts a ON a.id = l.account_id
  LEFT JOIN departments d ON d.id = l.department_id
  LEFT JOIN sites s ON s.id = l.site_id
  LEFT JOIN customers c ON c.id = l.customer_id
  LEFT JOIN vendors v ON v.id = l.vendor_id
 WHERE (SELECT gl_has_right('documents.view'));

-- ---------------------------------------------------------------------------------------------
-- Parties: parties.view; contact details to the ledger's writers.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_customers WITH (security_barrier = true) AS
SELECT c.id AS customer_id, c.name, c.legal_name,
       CASE WHEN (SELECT gl_has_right('ar.write')) THEN c.email::text END AS email,
       CASE WHEN (SELECT gl_has_right('ar.write')) THEN c.phone END AS phone,
       CASE WHEN (SELECT gl_has_right('ar.write')) THEN c.billing_address END AS billing_address,
       CASE WHEN (SELECT gl_has_right('ar.write')) THEN c.shipping_address END AS shipping_address,
       CASE WHEN (SELECT gl_has_right('ar.write')) THEN c.tax_id END AS tax_id,
       c.terms_days, c.currency, c.income_account_id, c.tax_rate_id, c.member_id,
       CASE WHEN (SELECT gl_has_right('ar.write')) THEN c.notes END AS notes,
       c.archived_at, c.created_at, c.updated_at,
       (SELECT COALESCE(sum(i.balance_due), 0) FROM invoices i WHERE i.customer_id = c.id AND i.status IN ('issued', 'sent', 'viewed', 'partially_paid')) AS balance_due,
       (SELECT count(*) FROM invoices i WHERE i.customer_id = c.id AND i.status IN ('issued', 'sent', 'viewed', 'partially_paid')) AS open_invoices,
       (SELECT max(i.issue_date) FROM invoices i WHERE i.customer_id = c.id AND i.status <> 'draft') AS last_invoiced_on
  FROM customers c WHERE (SELECT gl_has_right('parties.view'));

CREATE OR REPLACE VIEW mcp_vendors WITH (security_barrier = true) AS
SELECT v.id AS vendor_id, v.name, v.legal_name, v.kind, v.provider_key,
       CASE WHEN (SELECT gl_has_right('ap.write')) THEN v.email::text END AS email,
       CASE WHEN (SELECT gl_has_right('ap.write')) THEN v.phone END AS phone,
       CASE WHEN (SELECT gl_has_right('ap.write')) THEN v.remit_address END AS remit_address,
       CASE WHEN (SELECT gl_has_right('ap.write')) THEN v.tax_id END AS tax_id,
       v.terms_days, v.currency, v.expense_account_id, v.pay_method, v.member_id,
       CASE WHEN (SELECT gl_has_right('ap.write')) THEN v.notes END AS notes,
       v.archived_at, v.created_at, v.updated_at,
       (SELECT COALESCE(sum(b.balance_due), 0) FROM bills b WHERE b.vendor_id = v.id AND b.status IN ('approved', 'partially_paid')) AS balance_due,
       (SELECT count(*) FROM bills b WHERE b.vendor_id = v.id AND b.status IN ('approved', 'partially_paid')) AS open_bills,
       (SELECT max(b.bill_date) FROM bills b WHERE b.vendor_id = v.id AND b.status <> 'draft') AS last_billed_on
  FROM vendors v WHERE (SELECT gl_has_right('parties.view'));

CREATE OR REPLACE VIEW mcp_party_contacts WITH (security_barrier = true) AS
SELECT pc.id AS party_contact_id, pc.party_kind, pc.party_id, pc.name, pc.email::text AS email, pc.phone, pc.role, pc.is_primary
  FROM party_contacts pc
 WHERE (pc.party_kind = 'customer' AND (SELECT gl_has_right('ar.write'))) OR (pc.party_kind = 'vendor' AND (SELECT gl_has_right('ap.write')));

-- ---------------------------------------------------------------------------------------------
-- Receivables: documents.view.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_invoices WITH (security_barrier = true) AS
SELECT i.id AS invoice_id, i.number, i.customer_id, c.name AS customer_name, i.issue_date, i.due_date, i.currency, i.status,
       i.subtotal, i.tax_total, i.total, i.amount_paid, i.amount_credited, i.balance_due,
       (i.status IN ('issued', 'sent', 'viewed', 'partially_paid') AND i.due_date < current_date) AS overdue,
       CASE WHEN i.status IN ('issued', 'sent', 'viewed', 'partially_paid') AND i.due_date < current_date THEN current_date - i.due_date ELSE 0 END AS days_overdue,
       i.reference, i.memo, i.terms_text, i.department_id, d.name AS department_name, i.site_id, s.name AS site_name, i.journal_entry_id, i.write_off_entry_id,
       i.issued_at, i.issued_by, i.sent_at, i.viewed_at, i.paid_at, i.voided_at, i.voided_by, i.void_reason, i.written_off_at, i.written_off_by, i.write_off_reason,
       i.last_reminder_at, i.reminder_count, i.created_by, cm.display_name AS created_by_name, cm.member_kind AS created_by_kind, i.created_at, i.updated_at,
       (SELECT count(*) FROM receipt_claims rc WHERE rc.invoice_id = i.id AND rc.status = 'submitted') AS open_claims,
       (SELECT count(*) FROM attachments a WHERE a.record_type = 'invoice' AND a.record_id = i.id) AS attachment_count,
       EXISTS (SELECT 1 FROM invoice_links_secure l WHERE l.invoice_id = i.id AND l.rotated_at IS NULL AND (l.expires_at IS NULL OR l.expires_at > now())) AS has_live_link
  FROM invoices i
  JOIN customers c ON c.id = i.customer_id
  LEFT JOIN departments d ON d.id = i.department_id
  LEFT JOIN sites s ON s.id = i.site_id
  LEFT JOIN members cm ON cm.id = i.created_by
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_invoice_lines WITH (security_barrier = true) AS
SELECT l.id AS invoice_line_id, l.invoice_id, l.sort_order, l.item_id, l.description, l.quantity, l.unit_price, l.tax_rate_id, l.tax_rate,
       l.line_subtotal, l.line_tax, l.line_total, l.income_account_id, a.code AS income_account_code, l.department_id, l.site_id
  FROM invoice_lines l JOIN accounts a ON a.id = l.income_account_id
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_credit_notes WITH (security_barrier = true) AS
SELECT c.id AS credit_note_id, c.number, c.customer_id, cu.name AS customer_name, c.invoice_id, i.number AS invoice_number, c.issue_date, c.currency, c.status,
       c.subtotal, c.tax_total, c.total, c.amount_applied, c.balance, c.reason, c.department_id, c.site_id, c.journal_entry_id,
       c.issued_at, c.issued_by, c.voided_at, c.voided_by, c.void_reason, c.created_by, c.created_at, c.updated_at
  FROM credit_notes c JOIN customers cu ON cu.id = c.customer_id LEFT JOIN invoices i ON i.id = c.invoice_id
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_credit_note_lines WITH (security_barrier = true) AS
SELECT l.id AS credit_note_line_id, l.credit_note_id, l.sort_order, l.item_id, l.description, l.quantity, l.unit_price, l.tax_rate_id, l.tax_rate,
       l.line_subtotal, l.line_tax, l.line_total, l.income_account_id, l.department_id, l.site_id
  FROM credit_note_lines l WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_credit_applications WITH (security_barrier = true) AS
SELECT ca.credit_note_id, c.number AS credit_note_number, ca.invoice_id, i.number AS invoice_number, ca.amount, ca.applied_by, ca.applied_at
  FROM credit_applications ca JOIN credit_notes c ON c.id = ca.credit_note_id JOIN invoices i ON i.id = ca.invoice_id
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_receipts WITH (security_barrier = true) AS
SELECT r.id AS receipt_id, r.number, r.customer_id, c.name AS customer_name, r.received_on, r.amount, r.currency, r.method, r.reference, r.memo,
       r.deposit_to_bank_account_id, b.name AS deposit_to_bank_name, r.deposit_id, d.number AS deposit_number, r.status, r.journal_entry_id,
       r.amount - COALESCE((SELECT sum(ra.amount) FROM receipt_applications ra WHERE ra.receipt_id = r.id), 0) AS unapplied,
       r.recorded_by, rm.display_name AS recorded_by_name, rm.member_kind AS recorded_by_kind, r.voided_by, r.voided_at, r.void_reason, r.created_at, r.updated_at
  FROM receipts r
  JOIN customers c ON c.id = r.customer_id
  LEFT JOIN bank_accounts b ON b.id = r.deposit_to_bank_account_id
  LEFT JOIN deposits d ON d.id = r.deposit_id
  LEFT JOIN members rm ON rm.id = r.recorded_by
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_receipt_applications WITH (security_barrier = true) AS
SELECT ra.receipt_id, r.number AS receipt_number, ra.invoice_id, i.number AS invoice_number, ra.amount, ra.applied_by, ra.applied_at
  FROM receipt_applications ra JOIN receipts r ON r.id = ra.receipt_id JOIN invoices i ON i.id = ra.invoice_id
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_receipt_claims WITH (security_barrier = true) AS
SELECT rc.id AS receipt_claim_id, rc.invoice_id, i.number AS invoice_number, i.customer_id, c.name AS customer_name, rc.claimed_on, rc.method, rc.reference,
       rc.amount, rc.note, rc.status, rc.receipt_id, rc.decided_by, rc.decided_at, rc.created_at
  FROM receipt_claims rc JOIN invoices i ON i.id = rc.invoice_id JOIN customers c ON c.id = i.customer_id
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_deposits WITH (security_barrier = true) AS
SELECT d.id AS deposit_id, d.number, d.bank_account_id, b.name AS bank_account_name, d.deposit_date, d.currency, d.total, d.memo, d.status, d.journal_entry_id,
       d.recorded_by, d.recorded_at, d.voided_by, d.voided_at, d.void_reason, d.created_by, d.created_at,
       (SELECT count(*) FROM receipts r WHERE r.deposit_id = d.id) AS receipt_count
  FROM deposits d JOIN bank_accounts b ON b.id = d.bank_account_id
 WHERE (SELECT gl_has_right('documents.view'));

-- ---------------------------------------------------------------------------------------------
-- Payables: documents.view.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_bills WITH (security_barrier = true) AS
SELECT b.id AS bill_id, b.number, b.vendor_id, v.name AS vendor_name, v.kind AS vendor_kind, b.vendor_ref, b.bill_date, b.due_date, b.currency, b.status,
       b.subtotal, b.tax_total, b.total, b.amount_paid, b.amount_credited, b.balance_due,
       (b.status IN ('approved', 'partially_paid') AND b.due_date < current_date) AS overdue,
       CASE WHEN b.status IN ('approved', 'partially_paid') AND b.due_date < current_date THEN current_date - b.due_date ELSE 0 END AS days_overdue,
       b.memo, b.source, b.department_id, d.name AS department_name, b.site_id, s.name AS site_name, b.journal_entry_id,
       b.approved_at, b.approved_by, am.display_name AS approved_by_name, b.paid_at, b.voided_at, b.voided_by, b.void_reason,
       b.created_by, cm.display_name AS created_by_name, cm.member_kind AS created_by_kind, b.created_at, b.updated_at,
       (SELECT count(*) FROM attachments a WHERE a.record_type = 'bill' AND a.record_id = b.id) AS attachment_count,
       EXISTS (SELECT 1 FROM attachments a WHERE a.record_type = 'bill' AND a.record_id = b.id) AS has_attachment
  FROM bills b
  JOIN vendors v ON v.id = b.vendor_id
  LEFT JOIN departments d ON d.id = b.department_id
  LEFT JOIN sites s ON s.id = b.site_id
  LEFT JOIN members am ON am.id = b.approved_by
  LEFT JOIN members cm ON cm.id = b.created_by
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_bill_lines WITH (security_barrier = true) AS
SELECT l.id AS bill_line_id, l.bill_id, l.sort_order, l.item_id, l.description, l.quantity, l.unit_price, l.tax_rate_id, l.tax_rate,
       l.line_subtotal, l.line_tax, l.line_total, l.expense_account_id, a.code AS expense_account_code, a.name AS expense_account_name,
       l.department_id, d.name AS department_name, l.site_id
  FROM bill_lines l JOIN accounts a ON a.id = l.expense_account_id LEFT JOIN departments d ON d.id = l.department_id
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_vendor_credits WITH (security_barrier = true) AS
SELECT c.id AS vendor_credit_id, c.number, c.vendor_id, v.name AS vendor_name, c.bill_id, b.number AS bill_number, c.vendor_ref, c.credit_date, c.currency, c.status,
       c.subtotal, c.tax_total, c.total, c.amount_applied, c.balance, c.reason, c.department_id, c.site_id, c.journal_entry_id,
       c.issued_at, c.issued_by, c.voided_at, c.voided_by, c.void_reason, c.created_by, c.created_at, c.updated_at
  FROM vendor_credits c JOIN vendors v ON v.id = c.vendor_id LEFT JOIN bills b ON b.id = c.bill_id
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_vendor_credit_lines WITH (security_barrier = true) AS
SELECT l.id AS vendor_credit_line_id, l.vendor_credit_id, l.sort_order, l.description, l.quantity, l.unit_price, l.tax_rate_id, l.tax_rate,
       l.line_subtotal, l.line_tax, l.line_total, l.expense_account_id, l.department_id, l.site_id
  FROM vendor_credit_lines l WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_vendor_credit_applications WITH (security_barrier = true) AS
SELECT va.vendor_credit_id, c.number AS vendor_credit_number, va.bill_id, b.number AS bill_number, va.amount, va.applied_by, va.applied_at
  FROM vendor_credit_applications va JOIN vendor_credits c ON c.id = va.vendor_credit_id JOIN bills b ON b.id = va.bill_id
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_payments WITH (security_barrier = true) AS
SELECT p.id AS payment_id, p.number, p.vendor_id, v.name AS vendor_name, p.payment_date, p.amount, p.currency, p.method, p.cheque_number,
       p.bank_account_id, b.name AS bank_account_name, p.reference, p.memo, p.status, p.payment_run_id, r.number AS payment_run_number, p.journal_entry_id,
       p.approved_at, p.approved_by, p.recorded_at, p.recorded_by, rm.display_name AS recorded_by_name, rm.member_kind AS recorded_by_kind,
       p.voided_at, p.voided_by, p.void_reason, p.created_by, cm.display_name AS created_by_name, cm.member_kind AS created_by_kind, p.created_at, p.updated_at,
       (SELECT COALESCE(sum(pa.amount), 0) FROM payment_applications pa WHERE pa.payment_id = p.id) AS amount_applied
  FROM payments p
  JOIN vendors v ON v.id = p.vendor_id
  JOIN bank_accounts b ON b.id = p.bank_account_id
  LEFT JOIN payment_runs r ON r.id = p.payment_run_id
  LEFT JOIN members rm ON rm.id = p.recorded_by
  LEFT JOIN members cm ON cm.id = p.created_by
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_payment_applications WITH (security_barrier = true) AS
SELECT pa.payment_id, p.number AS payment_number, pa.bill_id, b.number AS bill_number, b.vendor_ref, pa.amount, pa.applied_by, pa.applied_at
  FROM payment_applications pa JOIN payments p ON p.id = pa.payment_id JOIN bills b ON b.id = pa.bill_id
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_payment_runs WITH (security_barrier = true) AS
SELECT r.id AS payment_run_id, r.number, r.pay_date, r.bank_account_id, b.name AS bank_account_name, r.currency, r.status, r.total, r.memo,
       r.approved_at, r.approved_by, am.display_name AS approved_by_name, r.recorded_at, r.recorded_by, r.voided_at, r.voided_by, r.created_by, r.created_at,
       (SELECT count(*) FROM payments p WHERE p.payment_run_id = r.id AND p.status <> 'void') AS payment_count
  FROM payment_runs r JOIN bank_accounts b ON b.id = r.bank_account_id LEFT JOIN members am ON am.id = r.approved_by
 WHERE (SELECT gl_has_right('documents.view'));

-- ---------------------------------------------------------------------------------------------
-- The kernel's AI statement: reports.view (the document itself is read by the writer; its lines are here).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_ai_statements WITH (security_barrier = true) AS
SELECT s.id AS ai_statement_id, s.period, s.period_start, s.period_end, s.kernel_status, s.document_hash, s.pulled_at, s.status, s.imported_at, s.imported_by,
       s.late_calls, s.checked_at,
       (SELECT jsonb_object_agg(x.currency, x.amt) FROM (SELECT currency, round(sum(amount), 2) AS amt FROM ai_statement_lines l WHERE l.statement_id = s.id GROUP BY currency) x) AS totals,
       (SELECT count(*) FROM ai_statement_lines l WHERE l.statement_id = s.id) AS line_count,
       (SELECT count(*) FROM ai_statement_bills ab WHERE ab.statement_id = s.id) AS bill_count
  FROM ai_statements s WHERE (SELECT gl_has_right('reports.view'));

CREATE OR REPLACE VIEW mcp_ai_statement_lines WITH (security_barrier = true) AS
SELECT l.id AS ai_statement_line_id, l.statement_id AS ai_statement_id, s.period, l.line_no, l.provider, l.model, l.department, l.department_id, l.agent, l.application,
       l.calls, l.late_calls, l.input_tokens, l.output_tokens, l.cache_read_tokens, l.cache_write_tokens, l.amount, l.currency, l.note
  FROM ai_statement_lines l JOIN ai_statements s ON s.id = l.statement_id
 WHERE (SELECT gl_has_right('reports.view'));

CREATE OR REPLACE VIEW mcp_ai_statement_bills WITH (security_barrier = true) AS
SELECT ab.statement_id AS ai_statement_id, s.period, ab.provider, ab.currency, ab.bill_id, b.number AS bill_number, b.status AS bill_status, b.total AS bill_total
  FROM ai_statement_bills ab JOIN ai_statements s ON s.id = ab.statement_id JOIN bills b ON b.id = ab.bill_id
 WHERE (SELECT gl_has_right('reports.view'));

-- ---------------------------------------------------------------------------------------------
-- Cash: documents.view.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_bank_accounts WITH (security_barrier = true) AS
SELECT b.id AS bank_account_id, b.name, b.kind, b.account_id, a.code AS account_code, b.institution, b.account_last4, b.currency, b.opening_balance, b.opening_date,
       b.last_reconciled_through, b.last_reconciled_balance, b.archived_at,
       gl_bank_ledger_balance(b.id) AS ledger_balance, gl_bank_cleared_balance(b.id) AS cleared_balance,
       (SELECT count(*) FROM bank_transactions t WHERE t.bank_account_id = b.id AND t.status = 'unmatched') AS unmatched_lines,
       (SELECT r.id FROM bank_reconciliations r WHERE r.bank_account_id = b.id AND r.status = 'in_progress') AS reconciliation_in_progress_id
  FROM bank_accounts b JOIN accounts a ON a.id = b.account_id
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_bank_imports WITH (security_barrier = true) AS
SELECT i.id AS bank_import_id, i.bank_account_id, i.filename, i.row_count, i.inserted_count, i.skipped_count, i.imported_by, i.deleted_at, i.created_at
  FROM bank_imports i WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_bank_transactions WITH (security_barrier = true) AS
SELECT t.id AS bank_transaction_id, t.bank_account_id, b.name AS bank_account_name, t.import_id, t.txn_date, t.amount, t.description, t.counterparty, t.external_ref,
       t.balance_after, t.status, t.matched_type, t.matched_id, t.matched_by, t.matched_at, t.ignore_reason, t.reconciliation_id, t.entered_by, t.created_at
  FROM bank_transactions t JOIN bank_accounts b ON b.id = t.bank_account_id
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_transfers WITH (security_barrier = true) AS
SELECT x.id AS transfer_id, x.number, x.from_bank_account_id, f.name AS from_bank_name, x.to_bank_account_id, t.name AS to_bank_name, x.transfer_date, x.amount, x.currency,
       x.memo, x.status, x.journal_entry_id, x.recorded_by, x.voided_at, x.voided_by, x.void_reason, x.created_at
  FROM transfers x JOIN bank_accounts f ON f.id = x.from_bank_account_id JOIN bank_accounts t ON t.id = x.to_bank_account_id
 WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_bank_reconciliations WITH (security_barrier = true) AS
SELECT r.id AS bank_reconciliation_id, r.bank_account_id, b.name AS bank_account_name, r.statement_date, r.statement_balance, r.opening_balance, r.cleared_balance,
       r.difference, r.ledger_balance, r.status, r.started_by, r.completed_at, r.completed_by, cm.display_name AS completed_by_name, r.reopened_at, r.reopened_by,
       r.reopen_reason, r.note, r.created_at, r.updated_at,
       (SELECT count(*) FROM bank_transactions t WHERE t.reconciliation_id = r.id) AS line_count
  FROM bank_reconciliations r JOIN bank_accounts b ON b.id = r.bank_account_id LEFT JOIN members cm ON cm.id = r.completed_by
 WHERE (SELECT gl_has_right('documents.view'));

-- ---------------------------------------------------------------------------------------------
-- Recurring, attachments, notes, notifications, dispatches, tokens.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_recurring_templates WITH (security_barrier = true) AS
SELECT t.id AS recurring_template_id, t.name, t.kind, t.cadence, t.every_n, t.next_date, t.end_date, t.template, t.last_run_at, t.run_count, t.active, t.created_by, t.created_at
  FROM recurring_templates t WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_recurring_runs WITH (security_barrier = true) AS
SELECT r.id AS recurring_run_id, r.template_id AS recurring_template_id, t.name AS template_name, r.run_date, r.record_type, r.record_id, r.status, r.detail, r.created_at
  FROM recurring_runs r JOIN recurring_templates t ON t.id = r.template_id WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_attachments WITH (security_barrier = true) AS
SELECT a.id AS attachment_id, a.record_type, a.record_id, a.filename, a.mime_type, a.byte_size, a.sha256, a.uploaded_by, m.display_name AS uploaded_by_name, a.created_at
  FROM attachments a LEFT JOIN members m ON m.id = a.uploaded_by WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_notes WITH (security_barrier = true) AS
SELECT n.id AS note_id, n.record_type, n.record_id, n.member_id, m.display_name AS member_name, m.member_kind, n.body, n.created_at
  FROM notes n LEFT JOIN members m ON m.id = n.member_id WHERE (SELECT gl_has_right('documents.view'));

CREATE OR REPLACE VIEW mcp_notifications WITH (security_barrier = true) AS
SELECT n.id AS notification_id, n.kind, n.record_type, n.record_id, n.title, n.body, n.read_at, n.created_at
  FROM notifications n WHERE n.member_id = app_current_member_id();

CREATE OR REPLACE VIEW mcp_agent_dispatches WITH (security_barrier = true) AS
SELECT d.id AS agent_dispatch_id, d.record_type, d.record_id, d.agent_member_id, m.display_name AS agent_name, d.kind, d.via, d.acting_member_id, d.run_id, d.request_id,
       d.status, d.reply_excerpt, d.detail, d.attempts, d.created_at, d.answered_at
  FROM agent_dispatches d JOIN members m ON m.id = d.agent_member_id
 WHERE (SELECT gl_has_right('reports.view'));

CREATE OR REPLACE VIEW mcp_my_tokens WITH (security_barrier = true) AS
SELECT t.id AS token_id, t.label, t.scope, t.last_used_at, t.expires_at, t.revoked_at, t.created_at
  FROM mcp_access_tokens t WHERE t.member_id = app_current_member_id();

-- Activity: one's own rows always; every row to reports.view (the books are business-wide).
CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT l.id AS activity_id, l.occurred_at, l.actor_member_id, am.display_name AS actor_name, am.member_kind AS actor_kind, l.actor_customer_id, l.source, l.action,
       l.entity_type, l.entity_id, l.journal_entry_id, l.period_id, l.customer_id, l.vendor_id, l.bank_account_id, l.department_id, l.site_id,
       l.before, l.after, l.agent_run_id, l.request_id
  FROM activity_log l
  LEFT JOIN members am ON am.id = l.actor_member_id
 WHERE l.actor_member_id = app_current_member_id()
    OR (SELECT gl_has_right('reports.view'));

GRANT SELECT ON mcp_members, mcp_departments, mcp_sites, mcp_settings, mcp_accounts, mcp_posting_rules, mcp_fiscal_years, mcp_fiscal_periods, mcp_tax_rates, mcp_items,
    mcp_journal_entries, mcp_journal_lines, mcp_customers, mcp_vendors, mcp_party_contacts, mcp_invoices, mcp_invoice_lines, mcp_credit_notes, mcp_credit_note_lines,
    mcp_credit_applications, mcp_receipts, mcp_receipt_applications, mcp_receipt_claims, mcp_deposits, mcp_bills, mcp_bill_lines, mcp_vendor_credits,
    mcp_vendor_credit_lines, mcp_vendor_credit_applications, mcp_payments, mcp_payment_applications, mcp_payment_runs, mcp_ai_statements, mcp_ai_statement_lines,
    mcp_ai_statement_bills, mcp_bank_accounts, mcp_bank_imports, mcp_bank_transactions, mcp_transfers, mcp_bank_reconciliations, mcp_recurring_templates,
    mcp_recurring_runs, mcp_attachments, mcp_notes, mcp_notifications, mcp_agent_dispatches, mcp_my_tokens
    TO gl_records_ro, gl_rw;
GRANT SELECT ON mcp_activity_log TO gl_activity_ro, gl_rw;
GRANT SELECT ON mcp_members, mcp_departments, mcp_journal_entries, mcp_invoices, mcp_bills TO gl_activity_ro;   -- names and document facts beside an activity row

COMMIT;
