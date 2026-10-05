-- Slice 8: a template that runs past its end date turns itself off with next_date beyond end_date (gl_recurring_advance, db/014). The original CHECK refused that row, so the last run of
-- every template with an end date would have failed. The check now binds only a template that is on.
BEGIN;
ALTER TABLE recurring_templates DROP CONSTRAINT IF EXISTS recurring_templates_check;
ALTER TABLE recurring_templates ADD CONSTRAINT recurring_templates_end_date_check CHECK (NOT active OR end_date IS NULL OR end_date >= next_date);
COMMIT;
