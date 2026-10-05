-- 020: THE THREE SHARES (Phase 4, K7 — maludb-os.json `shares[]`). Another application reads these through the kernel over a super-admin-approved connection; the Records MCP
-- offers them to the kernel's own token ONLY (mcp/server_common.py KERNEL_ONLY) and calls these functions. They answer totals and no person, no party, no document: the ledger by
-- account, the amounts due to vendors per week with no vendor named, the receivables by aging bucket with no customer named. SECURITY DEFINER reading the base tables like the
-- statement functions (db/015) — but with no per-member right check, because the caller is the kernel, not a member; the read role may run them and nothing else may.
BEGIN;

CREATE OR REPLACE FUNCTION gl_share_account_balances(p_as_of date DEFAULT current_date)
    RETURNS TABLE (code text, name text, account_type text, subtype text, balance numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT b.code, b.account_name, b.account_type, b.subtype,
           sum(CASE WHEN b.account_type IN ('asset', 'expense') THEN b.debit - b.credit ELSE b.credit - b.debit END)
      FROM gl_book_lines b WHERE b.entry_date <= p_as_of
     GROUP BY b.code, b.account_name, b.account_type, b.subtype
    HAVING sum(b.debit - b.credit) <> 0
     ORDER BY b.code;
$$;

-- Bills still owing, by when they fall due: 'overdue' (due before today), then one row per week from today (week 1 = today to today + 6 days …), per currency.
CREATE OR REPLACE FUNCTION gl_share_ap_due(p_weeks integer DEFAULT 8)
    RETURNS TABLE (bucket text, from_date date, to_date date, currency char(3), amount numeric, bills integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH owing AS (SELECT b.currency, b.due_date, b.balance_due FROM bills b WHERE b.status IN ('approved', 'partially_paid') AND b.balance_due > 0),
         weeks AS (SELECT w, current_date + (w - 1) * 7 AS f, current_date + (w - 1) * 7 + 6 AS t FROM generate_series(1, greatest(1, least(coalesce(p_weeks, 8), 26))) w)
    SELECT 'overdue', NULL::date, current_date - 1, o.currency::char(3), sum(o.balance_due), count(*)::integer FROM owing o WHERE o.due_date < current_date GROUP BY o.currency
    UNION ALL
    SELECT 'week ' || w.w, w.f, w.t, o.currency::char(3), sum(o.balance_due), count(*)::integer FROM weeks w JOIN owing o ON o.due_date BETWEEN w.f AND w.t GROUP BY w.w, w.f, w.t, o.currency
    ORDER BY 2 NULLS FIRST, 4;
$$;

-- Receivables by aging bucket per currency (the same arithmetic as gl_ar_aging, db/015, summed over customers).
CREATE OR REPLACE FUNCTION gl_share_ar_aging(p_as_of date DEFAULT current_date)
    RETURNS TABLE (currency char(3), current_due numeric, days_1_30 numeric, days_31_60 numeric, days_61_90 numeric, days_over_90 numeric, total numeric, open_invoices integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH owed AS (
        SELECT i.id, i.currency, i.due_date,
               i.total
               - COALESCE((SELECT sum(ra.amount) FROM receipt_applications ra JOIN receipts r ON r.id = ra.receipt_id AND r.status = 'recorded' WHERE ra.invoice_id = i.id AND r.received_on <= p_as_of), 0)
               - COALESCE((SELECT sum(ca.amount) FROM credit_applications ca JOIN credit_notes c ON c.id = ca.credit_note_id AND c.status = 'issued' WHERE ca.invoice_id = i.id AND c.issue_date <= p_as_of), 0) AS bal
          FROM invoices i
         WHERE i.status NOT IN ('draft', 'void') AND i.issue_date <= p_as_of AND (i.status <> 'written_off' OR i.written_off_at::date > p_as_of))
    SELECT o.currency::char(3),
           COALESCE(sum(o.bal) FILTER (WHERE p_as_of <= o.due_date), 0), COALESCE(sum(o.bal) FILTER (WHERE p_as_of - o.due_date BETWEEN 1 AND 30), 0),
           COALESCE(sum(o.bal) FILTER (WHERE p_as_of - o.due_date BETWEEN 31 AND 60), 0), COALESCE(sum(o.bal) FILTER (WHERE p_as_of - o.due_date BETWEEN 61 AND 90), 0),
           COALESCE(sum(o.bal) FILTER (WHERE p_as_of - o.due_date > 90), 0), sum(o.bal), count(*)::integer
      FROM owed o WHERE o.bal > 0 GROUP BY o.currency ORDER BY 1;
$$;

REVOKE ALL ON FUNCTION gl_share_account_balances(date), gl_share_ap_due(integer), gl_share_ar_aging(date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION gl_share_account_balances(date), gl_share_ap_due(integer), gl_share_ar_aging(date) TO gl_records_ro;

COMMIT;
