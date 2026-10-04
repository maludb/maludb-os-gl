-- 015: THE STATEMENTS as SQL functions over the posted lines (design §6), so a screen, a tool and a CSV never disagree.
-- "In the books" = an entry in status posted OR reversed (a reversal is its own posted entry; the pair nets to zero).
-- The year-end closing entries (and their reversals) are a reclassification to retained earnings with no cash effect:
-- the P&L for a range and the cash flow statement leave them out; the balance sheet and the trial balance keep them.
-- Every function answers only a caller with reports.view (the same nothing otherwise — a tool and a screen alike).
BEGIN;

CREATE OR REPLACE VIEW gl_book_lines AS
SELECT l.id AS line_id, l.entry_id, e.number AS entry_number, e.entry_date, e.status AS entry_status, e.source, e.memo,
       l.line_no, l.account_id, a.code, a.name AS account_name, a.account_type, a.subtype, a.is_bank, a.is_control,
       l.description, l.debit, l.credit, l.department_id, l.site_id, l.customer_id, l.vendor_id, e.currency,
       (e.source = 'closing' OR EXISTS (SELECT 1 FROM journal_entries o WHERE o.id = e.reversal_of AND o.source = 'closing')) AS is_closing
  FROM journal_lines l
  JOIN journal_entries e ON e.id = l.entry_id AND e.status IN ('posted', 'reversed')
  JOIN accounts a ON a.id = l.account_id;
-- not granted to the read roles: the functions below read it as the definer

-- An account's balance in its NORMAL sense (an asset or expense: debits − credits; the rest: credits − debits).
CREATE OR REPLACE FUNCTION gl_account_balance(p_account_id bigint, p_as_of date DEFAULT current_date, p_department_id bigint DEFAULT NULL, p_site_id bigint DEFAULT NULL)
    RETURNS numeric LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE WHEN NOT gl_has_right('reports.view') THEN NULL ELSE COALESCE((
        SELECT sum(CASE WHEN b.account_type IN ('asset', 'expense') THEN b.debit - b.credit ELSE b.credit - b.debit END)
          FROM gl_book_lines b
         WHERE b.account_id = p_account_id AND b.entry_date <= p_as_of
           AND (p_department_id IS NULL OR b.department_id = p_department_id)
           AND (p_site_id IS NULL OR b.site_id = p_site_id)), 0) END;
$$;

-- The trial balance as of a date: every account with a balance, debit or credit column, cumulative from the start.
CREATE OR REPLACE FUNCTION gl_trial_balance(p_as_of date DEFAULT current_date)
    RETURNS TABLE (account_id bigint, code text, name text, account_type text, subtype text, debit numeric, credit numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT b.account_id, b.code, b.account_name, b.account_type, b.subtype,
           CASE WHEN sum(b.debit - b.credit) > 0 THEN sum(b.debit - b.credit) ELSE 0 END,
           CASE WHEN sum(b.debit - b.credit) < 0 THEN -sum(b.debit - b.credit) ELSE 0 END
      FROM gl_book_lines b
     WHERE gl_has_right('reports.view') AND b.entry_date <= p_as_of
     GROUP BY b.account_id, b.code, b.account_name, b.account_type, b.subtype
    HAVING sum(b.debit - b.credit) <> 0
     ORDER BY b.code;
$$;

-- The profit and loss for a range, by account; income and expense each positive in their own sense.
CREATE OR REPLACE FUNCTION gl_profit_and_loss(p_from date, p_to date, p_department_id bigint DEFAULT NULL, p_site_id bigint DEFAULT NULL)
    RETURNS TABLE (account_type text, subtype text, account_id bigint, code text, name text, amount numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT b.account_type, b.subtype, b.account_id, b.code, b.account_name,
           sum(CASE WHEN b.account_type = 'income' THEN b.credit - b.debit ELSE b.debit - b.credit END)
      FROM gl_book_lines b
     WHERE gl_has_right('reports.view') AND b.account_type IN ('income', 'expense') AND NOT b.is_closing
       AND b.entry_date BETWEEN p_from AND p_to
       AND (p_department_id IS NULL OR b.department_id = p_department_id)
       AND (p_site_id IS NULL OR b.site_id = p_site_id)
     GROUP BY b.account_type, b.subtype, b.account_id, b.code, b.account_name
    HAVING sum(CASE WHEN b.account_type = 'income' THEN b.credit - b.debit ELSE b.debit - b.credit END) <> 0
     ORDER BY CASE b.account_type WHEN 'income' THEN 1 ELSE 2 END, b.code;
$$;

-- Net income for a range (the P&L's bottom line).
CREATE OR REPLACE FUNCTION gl_net_income(p_from date, p_to date, p_department_id bigint DEFAULT NULL, p_site_id bigint DEFAULT NULL)
    RETURNS numeric LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE WHEN NOT gl_has_right('reports.view') THEN NULL ELSE COALESCE((
        SELECT sum(CASE WHEN b.account_type = 'income' THEN b.credit - b.debit ELSE -(b.debit - b.credit) END)
          FROM gl_book_lines b
         WHERE b.account_type IN ('income', 'expense') AND NOT b.is_closing AND b.entry_date BETWEEN p_from AND p_to
           AND (p_department_id IS NULL OR b.department_id = p_department_id)
           AND (p_site_id IS NULL OR b.site_id = p_site_id)), 0) END;
$$;

-- The balance sheet as of a date: assets, liabilities, equity, each positive in its own sense, plus the earnings not
-- yet closed to retained earnings (what sits in the income and expense accounts as of the date).
CREATE OR REPLACE FUNCTION gl_balance_sheet(p_as_of date DEFAULT current_date)
    RETURNS TABLE (section text, subtype text, account_id bigint, code text, name text, balance numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT u.section, u.subtype, u.account_id, u.code, u.name, u.balance FROM (
        SELECT b.account_type AS section, b.subtype, b.account_id, b.code, b.account_name AS name,
               sum(CASE WHEN b.account_type = 'asset' THEN b.debit - b.credit ELSE b.credit - b.debit END) AS balance
          FROM gl_book_lines b
         WHERE gl_has_right('reports.view') AND b.account_type IN ('asset', 'liability', 'equity') AND b.entry_date <= p_as_of
         GROUP BY b.account_type, b.subtype, b.account_id, b.code, b.account_name
        HAVING sum(b.debit - b.credit) <> 0
        UNION ALL
        SELECT 'equity', 'current_earnings', NULL, NULL, 'Current earnings (not yet closed)',
               sum(b.credit - b.debit)
          FROM gl_book_lines b
         WHERE gl_has_right('reports.view') AND b.account_type IN ('income', 'expense') AND b.entry_date <= p_as_of
        HAVING sum(b.credit - b.debit) <> 0
    ) u
    ORDER BY CASE u.section WHEN 'asset' THEN 1 WHEN 'liability' THEN 2 ELSE 3 END, u.code NULLS LAST;
$$;

-- The accounting equation as of a date; difference must be zero.
CREATE OR REPLACE FUNCTION gl_balance_sheet_check(p_as_of date DEFAULT current_date)
    RETURNS TABLE (assets numeric, liabilities numeric, equity numeric, difference numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(sum(balance) FILTER (WHERE section = 'asset'), 0),
           COALESCE(sum(balance) FILTER (WHERE section = 'liability'), 0),
           COALESCE(sum(balance) FILTER (WHERE section = 'equity'), 0),
           COALESCE(sum(balance) FILTER (WHERE section = 'asset'), 0)
             - COALESCE(sum(balance) FILTER (WHERE section = 'liability'), 0)
             - COALESCE(sum(balance) FILTER (WHERE section = 'equity'), 0)
      FROM gl_balance_sheet(p_as_of);
$$;

-- The cash flow statement, indirect (D12): net income, then the change in every non-cash balance-sheet account over
-- the range (an asset's increase uses cash; a liability's or equity's increase provides it), by section; then the net
-- change in cash and the cash at the start and the end. "Cash" = every account a bank account stands behind (is_bank).
CREATE OR REPLACE FUNCTION gl_cash_flow(p_from date, p_to date)
    RETURNS TABLE (section text, line_no integer, label text, account_id bigint, code text, amount numeric)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE ni numeric; rec record; op numeric := 0; inv numeric := 0; fin numeric := 0; n integer := 1; cash_start numeric; cash_end numeric;
BEGIN
    IF NOT gl_has_right('reports.view') THEN RETURN; END IF;
    ni := gl_net_income(p_from, p_to);
    section := 'operating'; line_no := n; label := 'Net income'; account_id := NULL; code := NULL; amount := ni; RETURN NEXT;
    op := ni;
    FOR rec IN
        SELECT b.account_id AS aid, b.code AS acode, b.account_name, b.account_type, b.subtype,
               sum(CASE WHEN b.account_type = 'asset' THEN -(b.debit - b.credit) ELSE (b.credit - b.debit) END) AS chg
          FROM gl_book_lines b
         WHERE b.account_type IN ('asset', 'liability', 'equity') AND NOT b.is_bank AND NOT b.is_closing
           AND b.entry_date BETWEEN p_from AND p_to
         GROUP BY b.account_id, b.code, b.account_name, b.account_type, b.subtype
        HAVING sum(b.debit - b.credit) <> 0
         ORDER BY b.code
    LOOP
        n := n + 1;
        section := CASE WHEN rec.subtype IN ('fixed', 'other_asset') THEN 'investing'
                        WHEN rec.subtype IN ('owner', 'retained', 'other_equity', 'loan') OR rec.account_type = 'equity' THEN 'financing'
                        ELSE 'operating' END;
        line_no := n; label := 'Change in ' || rec.account_name; account_id := rec.aid; code := rec.acode; amount := rec.chg;
        IF section = 'operating' THEN op := op + rec.chg; ELSIF section = 'investing' THEN inv := inv + rec.chg; ELSE fin := fin + rec.chg; END IF;
        RETURN NEXT;
    END LOOP;
    section := 'summary'; n := n + 1; line_no := n; label := 'Cash from operations'; account_id := NULL; code := NULL; amount := op; RETURN NEXT;
    n := n + 1; line_no := n; label := 'Cash from investing'; amount := inv; RETURN NEXT;
    n := n + 1; line_no := n; label := 'Cash from financing'; amount := fin; RETURN NEXT;
    n := n + 1; line_no := n; label := 'Net change in cash'; amount := op + inv + fin; RETURN NEXT;
    SELECT COALESCE(sum(CASE WHEN b.account_type = 'asset' THEN b.debit - b.credit ELSE -(b.credit - b.debit) END), 0) INTO cash_start
      FROM gl_book_lines b WHERE b.is_bank AND b.entry_date < p_from;
    SELECT COALESCE(sum(CASE WHEN b.account_type = 'asset' THEN b.debit - b.credit ELSE -(b.credit - b.debit) END), 0) INTO cash_end
      FROM gl_book_lines b WHERE b.is_bank AND b.entry_date <= p_to;
    n := n + 1; line_no := n; label := 'Cash at start'; amount := cash_start; RETURN NEXT;
    n := n + 1; line_no := n; label := 'Cash at end'; amount := cash_end; RETURN NEXT;
    n := n + 1; line_no := n; label := 'Check: end − start − net change (must be zero)'; amount := cash_end - cash_start - (op + inv + fin); RETURN NEXT;
    RETURN;
END$$;

-- One account's activity over a range with a running balance in the account's normal sense.
CREATE OR REPLACE FUNCTION gl_account_activity(p_account_id bigint, p_from date, p_to date, p_department_id bigint DEFAULT NULL, p_site_id bigint DEFAULT NULL)
    RETURNS TABLE (entry_id bigint, entry_number text, entry_date date, source text, memo text, description text, debit numeric, credit numeric,
                   running_balance numeric, department_id bigint, site_id bigint, customer_id bigint, vendor_id bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH acct AS (SELECT account_type FROM accounts WHERE id = p_account_id),
         opening AS (SELECT COALESCE(gl_account_balance(p_account_id, p_from - 1, p_department_id, p_site_id), 0) AS bal)
    SELECT b.entry_id, b.entry_number, b.entry_date, b.source, b.memo, b.description, b.debit, b.credit,
           (SELECT bal FROM opening) + sum(CASE WHEN (SELECT account_type FROM acct) IN ('asset', 'expense') THEN b.debit - b.credit ELSE b.credit - b.debit END)
               OVER (ORDER BY b.entry_date, b.entry_id, b.line_no ROWS UNBOUNDED PRECEDING),
           b.department_id, b.site_id, b.customer_id, b.vendor_id
      FROM gl_book_lines b
     WHERE gl_has_right('reports.view') AND b.account_id = p_account_id AND b.entry_date BETWEEN p_from AND p_to
       AND (p_department_id IS NULL OR b.department_id = p_department_id)
       AND (p_site_id IS NULL OR b.site_id = p_site_id)
     ORDER BY b.entry_date, b.entry_id, b.line_no;
$$;

-- Receivables aging as of a date, per customer and currency, from the documents (what was owed on that day).
CREATE OR REPLACE FUNCTION gl_ar_aging(p_as_of date DEFAULT current_date)
    RETURNS TABLE (customer_id bigint, customer_name text, currency char(3), current_due numeric, days_1_30 numeric, days_31_60 numeric,
                   days_61_90 numeric, days_over_90 numeric, total numeric, open_invoices integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH owed AS (
        SELECT i.id, i.customer_id, i.currency, i.due_date,
               i.total
               - COALESCE((SELECT sum(ra.amount) FROM receipt_applications ra JOIN receipts r ON r.id = ra.receipt_id AND r.status = 'recorded'
                            WHERE ra.invoice_id = i.id AND r.received_on <= p_as_of), 0)
               - COALESCE((SELECT sum(ca.amount) FROM credit_applications ca JOIN credit_notes c ON c.id = ca.credit_note_id AND c.status = 'issued'
                            WHERE ca.invoice_id = i.id AND c.issue_date <= p_as_of), 0) AS bal
          FROM invoices i
         WHERE gl_has_right('reports.view') AND i.status NOT IN ('draft', 'void') AND i.issue_date <= p_as_of
           AND (i.status <> 'written_off' OR i.written_off_at::date > p_as_of)
    )
    SELECT o.customer_id, c.name, o.currency::char(3),
           COALESCE(sum(o.bal) FILTER (WHERE p_as_of <= o.due_date), 0),
           COALESCE(sum(o.bal) FILTER (WHERE p_as_of - o.due_date BETWEEN 1 AND 30), 0),
           COALESCE(sum(o.bal) FILTER (WHERE p_as_of - o.due_date BETWEEN 31 AND 60), 0),
           COALESCE(sum(o.bal) FILTER (WHERE p_as_of - o.due_date BETWEEN 61 AND 90), 0),
           COALESCE(sum(o.bal) FILTER (WHERE p_as_of - o.due_date > 90), 0),
           sum(o.bal), count(*)::integer
      FROM owed o JOIN customers c ON c.id = o.customer_id
     WHERE o.bal > 0
     GROUP BY o.customer_id, c.name, o.currency
     ORDER BY c.name;
$$;

-- Payables aging as of a date, per vendor and currency.
CREATE OR REPLACE FUNCTION gl_ap_aging(p_as_of date DEFAULT current_date)
    RETURNS TABLE (vendor_id bigint, vendor_name text, currency char(3), current_due numeric, days_1_30 numeric, days_31_60 numeric,
                   days_61_90 numeric, days_over_90 numeric, total numeric, open_bills integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH owed AS (
        SELECT b.id, b.vendor_id, b.currency, b.due_date,
               b.total
               - COALESCE((SELECT sum(pa.amount) FROM payment_applications pa JOIN payments p ON p.id = pa.payment_id AND p.status = 'recorded'
                            WHERE pa.bill_id = b.id AND p.payment_date <= p_as_of), 0)
               - COALESCE((SELECT sum(va.amount) FROM vendor_credit_applications va JOIN vendor_credits v ON v.id = va.vendor_credit_id AND v.status = 'issued'
                            WHERE va.bill_id = b.id AND v.credit_date <= p_as_of), 0) AS bal
          FROM bills b
         WHERE gl_has_right('reports.view') AND b.status NOT IN ('draft', 'void') AND b.bill_date <= p_as_of
    )
    SELECT o.vendor_id, v.name, o.currency::char(3),
           COALESCE(sum(o.bal) FILTER (WHERE p_as_of <= o.due_date), 0),
           COALESCE(sum(o.bal) FILTER (WHERE p_as_of - o.due_date BETWEEN 1 AND 30), 0),
           COALESCE(sum(o.bal) FILTER (WHERE p_as_of - o.due_date BETWEEN 31 AND 60), 0),
           COALESCE(sum(o.bal) FILTER (WHERE p_as_of - o.due_date BETWEEN 61 AND 90), 0),
           COALESCE(sum(o.bal) FILTER (WHERE p_as_of - o.due_date > 90), 0),
           sum(o.bal), count(*)::integer
      FROM owed o JOIN vendors v ON v.id = o.vendor_id
     WHERE o.bal > 0
     GROUP BY o.vendor_id, v.name, o.currency
     ORDER BY v.name;
$$;

-- The subledger against the ledger (GL11, the control-account tie-out): AR per the invoices vs the receivable account;
-- AP per the bills vs the payable account. Each pair must agree.
CREATE OR REPLACE FUNCTION gl_control_check(p_as_of date DEFAULT current_date)
    RETURNS TABLE (control text, subledger numeric, ledger numeric, difference numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT 'receivable', COALESCE((SELECT sum(total) FROM gl_ar_aging(p_as_of)), 0),
           COALESCE(gl_account_balance((SELECT ar_account_id FROM gl_settings WHERE id = 1), p_as_of), 0),
           COALESCE((SELECT sum(total) FROM gl_ar_aging(p_as_of)), 0) - COALESCE(gl_account_balance((SELECT ar_account_id FROM gl_settings WHERE id = 1), p_as_of), 0)
     WHERE gl_has_right('reports.view')
    UNION ALL
    SELECT 'payable', COALESCE((SELECT sum(total) FROM gl_ap_aging(p_as_of)), 0),
           COALESCE(gl_account_balance((SELECT ap_account_id FROM gl_settings WHERE id = 1), p_as_of), 0),
           COALESCE((SELECT sum(total) FROM gl_ap_aging(p_as_of)), 0) - COALESCE(gl_account_balance((SELECT ap_account_id FROM gl_settings WHERE id = 1), p_as_of), 0)
     WHERE gl_has_right('reports.view');
$$;

-- Through what date the books are posted, and what waits.
CREATE OR REPLACE FUNCTION gl_posted_through() RETURNS date
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE WHEN gl_has_right('reports.view') THEN (SELECT max(entry_date) FROM journal_entries WHERE status IN ('posted', 'reversed')) END;
$$;

CREATE OR REPLACE FUNCTION gl_unposted_summary()
    RETURNS TABLE (draft_entries integer, unbalanced_entries integer, oldest_draft date, draft_invoices integer, draft_bills integer, bills_awaiting_approval integer,
                   payments_awaiting_approval integer, unmatched_bank_lines integer, open_claims integer, undeposited_receipts integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT (SELECT count(*)::int FROM journal_entries WHERE status = 'draft'),
           (SELECT count(*)::int FROM journal_entries e WHERE e.status = 'draft'
              AND (SELECT COALESCE(sum(debit - credit), 0) FROM journal_lines WHERE entry_id = e.id) <> 0),
           (SELECT min(entry_date) FROM journal_entries WHERE status = 'draft'),
           (SELECT count(*)::int FROM invoices WHERE status = 'draft'),
           (SELECT count(*)::int FROM bills WHERE status = 'draft' AND source <> 'ai_usage'),
           (SELECT count(*)::int FROM bills WHERE status = 'draft'),
           (SELECT count(*)::int FROM payments WHERE status = 'draft'),
           (SELECT count(*)::int FROM bank_transactions WHERE status = 'unmatched'),
           (SELECT count(*)::int FROM receipt_claims WHERE status = 'submitted'),
           (SELECT count(*)::int FROM receipts WHERE status = 'recorded' AND deposit_to_bank_account_id IS NULL AND deposit_id IS NULL)
     WHERE gl_has_right('reports.view');
$$;

-- The cash position: every bank account's ledger balance, cleared balance and last reconciliation.
CREATE OR REPLACE FUNCTION gl_cash_position(p_as_of date DEFAULT current_date)
    RETURNS TABLE (bank_account_id bigint, name text, kind text, currency char(3), ledger_balance numeric, cleared_balance numeric,
                   last_reconciled_through date, last_reconciled_balance numeric, unmatched_lines integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT b.id, b.name, b.kind, b.currency::char(3), gl_bank_ledger_balance(b.id, p_as_of), gl_bank_cleared_balance(b.id, p_as_of),
           b.last_reconciled_through, b.last_reconciled_balance,
           (SELECT count(*)::int FROM bank_transactions t WHERE t.bank_account_id = b.id AND t.status = 'unmatched')
      FROM bank_accounts b
     WHERE gl_has_right('reports.view') AND b.archived_at IS NULL
     ORDER BY b.name;
$$;

GRANT EXECUTE ON FUNCTION gl_account_balance(bigint, date, bigint, bigint), gl_trial_balance(date), gl_profit_and_loss(date, date, bigint, bigint),
    gl_net_income(date, date, bigint, bigint), gl_balance_sheet(date), gl_balance_sheet_check(date), gl_cash_flow(date, date),
    gl_account_activity(bigint, date, date, bigint, bigint), gl_ar_aging(date), gl_ap_aging(date), gl_control_check(date), gl_posted_through(),
    gl_unposted_summary(), gl_cash_position(date) TO gl_rw, gl_records_ro;

COMMIT;
