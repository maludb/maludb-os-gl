-- 018: A TRANSFER MATCHES A LINE ON EACH OF ITS TWO BANK ACCOUNTS (slice 6; found by the transfers proof).
-- db/013 made bank_transactions_match_once unique on (matched_type, matched_id) among matched lines, so a transfer — which
-- appears on BOTH accounts' statements, signed each way (gl_bank_candidates offers it on both; gl_bank_item_amount reads
-- each side) — could be matched on one account only. The second match died on the index, not on a rule. The key now
-- includes the bank account: a receipt, deposit or payment sits on one account only, so nothing else changes; a transfer
-- may be matched once PER account. Additive: an index replaced, no column touched.
BEGIN;
DROP INDEX IF EXISTS bank_transactions_match_once;
CREATE UNIQUE INDEX bank_transactions_match_once ON bank_transactions (matched_type, matched_id, bank_account_id) WHERE status = 'matched';
COMMIT;
