-- 017: voiding a recorded deposit returns its receipts to undeposited funds (a fix found building slice 4).
--
-- gl_deposit_void() (db/013) voids the deposit and then sets receipts.deposit_id to NULL ("the receipts return to undeposited funds"),
-- but db/010's gl_receipt_guard() let a receipt leave only a DRAFT deposit — so the void always failed with "The deposit is void; a
-- receipt leaves a draft deposit only". A receipt may leave a draft deposit (taken out by a person) or a VOID one (released by the
-- void); it still cannot leave a recorded deposit by itself. Nothing else in the function changes. Additive: CREATE OR REPLACE only.
BEGIN;

CREATE OR REPLACE FUNCTION gl_receipt_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE dstat text;
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.number := COALESCE(NEW.number, next_document_number('receipt'));
        IF NEW.status <> 'recorded' THEN RAISE EXCEPTION 'A receipt is recorded when it is made'; END IF;
        IF NEW.deposit_id IS NOT NULL THEN RAISE EXCEPTION 'A receipt joins a deposit afterwards'; END IF;
        RETURN NEW;
    END IF;
    IF OLD.status = 'void' THEN RAISE EXCEPTION 'Receipt % is void and cannot be changed', OLD.number; END IF;
    IF NEW.status = 'void' THEN
        IF OLD.deposit_id IS NOT NULL THEN RAISE EXCEPTION 'Receipt % is in deposit; take it out of the deposit first', OLD.number; END IF;
        IF EXISTS (SELECT 1 FROM receipt_applications WHERE receipt_id = OLD.id) THEN RAISE EXCEPTION 'Receipt % is applied to an invoice; unapply it first', OLD.number; END IF;
        NEW.voided_at := COALESCE(NEW.voided_at, now());
        RETURN NEW;
    END IF;
    IF row(NEW.customer_id, NEW.received_on, NEW.amount, NEW.currency, NEW.number) IS DISTINCT FROM row(OLD.customer_id, OLD.received_on, OLD.amount, OLD.currency, OLD.number) THEN
        IF EXISTS (SELECT 1 FROM receipt_applications WHERE receipt_id = OLD.id) OR OLD.journal_entry_id IS NOT NULL OR OLD.deposit_id IS NOT NULL THEN
            RAISE EXCEPTION 'Receipt % is applied, posted or deposited: void it and record it again', OLD.number;
        END IF;
    END IF;
    IF NEW.deposit_id IS NOT NULL AND NEW.deposit_id IS DISTINCT FROM OLD.deposit_id THEN
        IF NEW.deposit_to_bank_account_id IS NOT NULL THEN RAISE EXCEPTION 'Receipt % went straight to a bank account; only undeposited receipts join a deposit', OLD.number; END IF;
        SELECT status INTO dstat FROM deposits WHERE id = NEW.deposit_id;
        IF dstat <> 'draft' THEN RAISE EXCEPTION 'The deposit is %; receipts join a draft deposit', dstat; END IF;
    END IF;
    IF OLD.deposit_id IS NOT NULL AND NEW.deposit_id IS DISTINCT FROM OLD.deposit_id THEN
        SELECT status INTO dstat FROM deposits WHERE id = OLD.deposit_id;
        IF dstat NOT IN ('draft', 'void') THEN RAISE EXCEPTION 'The deposit is %; a receipt leaves a draft deposit only', dstat; END IF;   -- 017: a void deposit releases its receipts
    END IF;
    RETURN NEW;
END$$;

COMMIT;
