# Build spec: recurring, notifications and the worker — slice 8

What exists at the end: recurring templates that draft an entry, a bill or an invoice on their cadence; the one timer worker that sends the
outbox (email through MaluMail, texts through the kernel's K6), polls the kernel's AI statement, runs the templates, drafts reminders for
invoices past their terms, expires secure links, and retries dispatches; the bell filled; the morning note delivered.
Schema: `recurring_templates`, `recurring_runs`, `gl_recurring_next()`, `gl_recurring_advance()`, `notifications`, `notification_prefs`,
`notification_outbox`, `agent_dispatches` (db/014); `invoices.last_reminder_at`, `invoice_links_secure.expires_at` (db/010); `gl_settings.reminder_days`,
`link_lifetime_days`, `ai_statement_poll`, `morning_note_member_id` (db/006); the views `mcp_recurring_templates`, `mcp_recurring_runs`, `mcp_notifications`,
`mcp_agent_dispatches` (db/016). Never modify them.

## Screens

| Screen id | Canonical URL | Purpose |
|---|---|---|
| `recurring-list` | `/settings/recurring/` | Cards (`recurring-card-{id}`): name, kind chip, cadence in words ("Monthly", "Every 2 weeks"), next date, last run, run count, on/off |
| `recurring-add` / `recurring-edit` | `/settings/recurring/new`, `/{id}/edit` | Name, kind, cadence, every N, next date, end date, active; **the document** as the kind's own form: a journal template = date offset + the lines editor; a bill template = vendor + lines; an invoice template = customer + lines; `?from=` copies an existing entry, bill or invoice into the template |
| `recurring-view` | `/settings/recurring/{id}` | The template's document rendered read-only, its runs (date, what it drafted with a link, status), **Run now** |
| `notifications` | `/notifications` | Phase 2's shape filled: kind icon, title, body, the record link, read/unread |

## Templates
- `template` jsonb shape (the kind's form writes it; an agent sends it): journal `{memo, department, site, currency, day_offset, lines: [{account, description, debit, credit, department, site, customer, vendor}]}`;
  bill `{vendor, vendor_ref_pattern ("RENT-{YYYY-MM}"), terms_days, memo, department, site, lines: [{expense_account, description, quantity, unit_price, tax_rate, department, site}]}`;
  invoice `{customer, reference_pattern, memo, department, site, lines: [{item, description, quantity, unit_price, tax_rate, income_account}]}`. Accounts and parties are ids
  in the template (names resolve at save; a deleted or archived one makes the run fail with the sentence).
- `recurring_save` (`settings.manage`): validation of the shape (an unknown key, a line with both sides, a missing account → 422 naming the line); log `recurring.save`.
  `recurring_toggle`, `recurring_delete` (its runs kept by cascade? no — `recurring_runs` cascade; the drafted documents stay). **`recurring_run`** (`journal.write`):
  `run_template()` drafts the document dated `run_date` (default the template's next date; a journal's `day_offset` from it), in one transaction with
  `gl_recurring_advance()`; a failure records a `failed` run with the sentence and advances nothing; log `recurring.run` (`after.record_type`, `after.record_id`, `after.run_date`).
  The drafted document is a DRAFT: a person posts, approves or issues it (D7).

## The worker — `bin/worker.php`, one pass a minute from `gl-worker.timer`
`php bin/worker.php [--only=send,ai,recurring,reminders,links,claims,dispatch,housekeeping] [--limit=200]`. Advisory-locked (`hashtext('gl_worker')`); each step
its own try; the report as one JSON line and logged once `worker.pass` (counts, never a body); `GL_WORKER_NOW` fakes the clock outside production.
1. **send** — `outbox_send_batch()`: queued rows whose `send_after` passed; **email** through MaluMail (`app/mail.php`): to a member's mirror email or a
   customer's `to_email`; the invoice, reminder and statement mails from slice 4's `mail.php` (the raw secure token is in the row's body already); five attempts
   then `failed` with the provider's sentence; **text** through the kernel's K6 (`POST /api/v1/notify/sms.php` with `member_id`, 480 chars, a reference): every
   refusal (`no_sender`, `not_held`, `no_verified_phone`, `opted_out`, `rate_limited`) is a `skipped` with the code and the email row stands; log `notification.send`
   (`after.channel`, `after.kind`, `after.status` — never the body or the address).
2. **ai** — once an hour (`ai_last_poll_at` in a `worker_state` row? no new table: the pass checks the newest `ai_statement.pull` activity row's time), when
   `ai_statement_poll`: slice 7's `ai_poll_pass()`.
3. **recurring** — every active template with `next_date <= today`: `run_template()`; a `failed` run notifies the admin (`agent_drafted`? no: kind `morning_note`?
   — a failure is written to the morning note's material; the Bookkeeper reports it; the admin sees it on the template's card as "last run failed: …").
4. **reminders** — invoices past due by one of `reminder_days` exactly (today − due = N) and not reminded in the last 7 days → `remind_invoice()` as the worker
   (`source = cron`, the outbox row; log `invoice.remind_due`). D7 says an agent's reminder pauses; the worker is not an agent — it is the business's own clock,
   and the owner turns it off by emptying `reminder_days`. (Open question below.)
5. **links** — a paid or written-off or void invoice whose link has no `expires_at` → `expires_at = paid_at + link_lifetime_days`; log `invoice.link_expired` when
   a link passes its expiry (once).
6. **claims** — a claim submitted and untouched for 3 days → a notice `claim_submitted` again to the Bookkeeper's kind (dedupe keyed by claim and day).
7. **dispatch** — `agent_dispatches` rows `failed` with `attempts < 3` → retried through the chat endpoint (the kit's `dispatch_to_agent()`, slice 4 does not
   use it; this slice adds `app/features/agents/dispatch.php` for the "bill attached" and "reconciliation out" kinds: a person's action on the bill page **Ask the
   Bookkeeper** → a dispatch via chat with the acting person).
8. **housekeeping** — `sso_nonces` expired rows, `member_sessions` ended rows older than 30 days, `notification_outbox` sent rows older than 90 days, orphan
   attachment files (none expected).

## The bell and the notice kinds
`notify_member(PDO, int $memberId, string $kind, string $title, ?string $body, ?string $recordType, ?int $recordId, ?string $dedupe): void` — the in-app row
always; an outbox email row when the member's prefs include the kind; a text row when `text_enabled` and the kind is in `text_kinds`. `notify_customer(PDO, int
$customerId, string $kind, string $toEmail, string $subject, string $body, string $html, ?string $dedupe)`. Kinds and who: `payment_run_awaiting` and
`bill_awaiting` (approvers: members holding `ap.approve` by role — read from `members.roles`), `reconciliation_out` (holders of `bank.reconcile`), `morning_note`
(the member the settings name), `claim_submitted` (holders of `ar.write`), `invoice_overdue` (`ar.write`, weekly digest? no — one per invoice at the first reminder
day), `bill_due` (`ap.write`, at 3 days before due), `ai_statement_imported` / `ai_statement_differs` (`ap.approve`), `period_closed` / `period_reopened`
(`periods.manage`), `bill_attached` (the Bookkeeper agent's dispatch), `mentioned` (a note naming a member with `@`), `agent_drafted` (the person who asked the
expert, when it drafted something).
- **The morning note** (`morning_note_send`, `/settings/agents/morning-note.php`; `journal.write`; the Bookkeeper's tool): `notify_member()` to `to_member` or the
  settings' member with kind `morning_note`, the body as given (plain text, ≤ 4,000 chars); log `morning_note.send` (`after.to_member_id`, `after.length`). The
  home's region shows the newest.

## Files (exactly these)
- `html/settings/recurring/index.php` · `form.php` · `view.php` · `save.php` · `toggle.php` · `run.php` · `delete.php` — `html/settings/agents/morning-note.php` — `html/bills/ask-bookkeeper.php` (`bill_ask_bookkeeper`? not in the manifest → it is a dispatch, not an action: `dispatch.php` under `/settings/agents/`, logged `agent.dispatch`; the button on the bill page)
- `bin/worker.php` — `app/features/recurring/queries.php` · `write.php` · `templates.php` (`run_template()`, the three kinds) — `app/features/notify/queries.php` · `write.php` (`notify_member()`, `notify_customer()`, `outbox_send_batch()`, the K6 client) — `app/features/worker/steps.php` — `app/features/agents/dispatch.php`
- `app/views/settings/recurring.php` · `recurring-form.php` · `recurring-view.php` · `partials/recurring-card.php` · `template-journal.php` · `template-bill.php` · `template-invoice.php` — `app/views/notifications.php` (filled) · `app/views/shared/bell.php`

## Query functions (signatures fixed)
- `find_templates(PDO, array $filters): array` · `find_template(PDO, int $id): ?array` · `save_template(PDO, ?int $id, array $f, array $template, int $by): int` · `toggle_template(…)` · `delete_template(…)` · `template_from(PDO, string $kind, int $recordId): array` · `run_template(PDO, int $id, ?string $runDate, int $by, string $source = 'web'): array` · `templates_due(PDO, string $asOf): array`
- `notify_member(…)` · `notify_customer(…)` · `members_with_right(PDO, string $right): array` · `outbox_send_batch(PDO, int $limit): array` · `send_email_row(array $row): array` · `send_text_row(array $row): array` (K6) · `find_my_notifications(…)` (Phase 2's, now filled)
- `worker_pass(PDO, array $only, int $limit, string $now): array` and the eight step functions in `steps.php`
- `dispatch_to_agent(PDO, string $recordType, int $recordId, int $agentId, string $kind, ?int $actingMemberId): array`

## Action manifest entries
- Screens: `recurring-list`, `recurring-add`, `recurring-edit`, `recurring-view`, `notifications` (filled). Actions (5): `recurring_save`, `recurring_toggle`, `recurring_run`, `recurring_delete`, `morning_note_send`. Agent approvals: `recurring_save`, `recurring_toggle` (`other`); `recurring_delete` (`deletion`).

## Activity log events
`recurring.save|toggle|run|delete`, `morning_note.send`, `notification.send`, `invoice.remind_due`, `invoice.link_expired`, `agent.dispatch`, `worker.pass`, `screen.view`.

## Out of scope for this slice
The AI statement's import (7 — the worker only pulls); the agents' duties (Phase 4); K8 wakes (owed); editable mail templates (Extended).

## Proof (`tests/phase3/slice8/run.sh`: `tests/fake_malumail.php`, `tests/fake_kernel.php` (K6 and the ledger feed), `GL_WORKER_NOW`)
- [x] A monthly journal template from an existing entry: `run_template()` drafts it on the 1st dated the 1st with the lines, advances to the next month, records the run; the worker drafts it when due and not before; a template whose account was archived → a failed run with the sentence, nothing advanced; a bill template with `RENT-{YYYY-MM}` → a draft bill with that reference; an invoice template → a draft invoice; Run now by a Bookkeeper; the end date deactivates.
- [x] The outbox: an invoice mail sent through the fake MaluMail with the link in the body, `notification.send` without the body; a failure retried five times then failed; a text to a member with `text_enabled` through the fake K6, a `no_verified_phone` refusal → skipped and the email stands; dedupe keys hold.
- [x] Reminders: an invoice 7 days past due → one reminder row with step-1 wording, `invoice.remind_due`; not again within 7 days; at 21 days step 2; a paid invoice none; `reminder_days` empty → none.
- [x] Links: a paid invoice's link expires `link_lifetime_days` later (the clock faked past it → 404 on `/i/`, `invoice.link_expired` once).
- [x] The bell: a bill drafted → the controller's bell row and email; a payment run → a text when chosen; the morning note sent by the agent's action → the home shows it; `period_reopened` to the holders of `periods.manage`.
- [x] The worker pass locked against a second; `--only`; the report logged once; housekeeping removes expired nonces.
- [x] JSON mode; 375 and 1280; the registry reads 5 actions and 5 screens.

## Built and proven 2026-10-05 by the worker: 174 checks green (recurring 33, outbox 28, reminders 17, bell 19, worker 29, json 22, browser 26)
The registry reads **4 new screens and 5 actions** as built (`92 screens (90 built), 124 actions (124 built)`; the bell page itself was slice 1). **Schema:** `db/019_recurring_end_date_check.sql` only.

## Decisions taken while building (2026-10-05)
- **db/019.** `gl_recurring_advance()` sets `next_date` past `end_date` when it turns a template off; db/014's `CHECK (end_date >= next_date)` refused that row. The check is now `NOT active OR end_date IS NULL OR end_date >= next_date`.
- **A recurring entry has `source = recurring` and no `source_type`/`source_id`.** db/008's unique index on `(source_type, source_id)` would allow one entry per template; `recurring_runs.record_type/record_id` is the link.
- **`run_template()`** (`app/features/recurring/templates.php`) re-validates the saved template at every run (an archived account or party is a failed run naming its line, in a savepoint so nothing half-made stays), drafts through the slice's own `draft_entry`/`draft_bill`/`draft_invoice`, and calls `gl_recurring_advance()` in the same transaction. Patterns in a vendor or invoice reference: `{YYYY-MM}`, `{YYYY}`, `{MM}`, `{DD}`, `{MON}`. A journal template's `day_offset` dates the entry from the run date. A failed run is recorded `failed`; the next try for that date replaces it.
- **The worker reads as the ledger's administrator** (the first active super-admin, else a human holding admin): the `mcp_*` views answer nothing to nobody. Every write is logged `source = cron` with no actor. `GL_WORKER_NOW` is honoured only when `APP_ENV` is not `prod`; the database's own `now()` is the database's, so the proofs move dates and `paid_at` rather than the clock.
- **The links step** re-aims a closed (paid, written-off, void) invoice's live link at its closing time plus `link_lifetime_days` (slice 4 had stamped the settings' life at minting) — a no-op once it does — and logs `invoice.link_expired` once per link after it passes.
- **The AI step runs once an hour**, judged from the newest `worker.pass` whose report ran `ai`; no new table. **Reminders** run as the business's own clock (decided 2026-10-05): `remind_invoice()` for an invoice exactly a `reminder_days` count past due and not reminded in 7 days, `invoice.remind_due` with source cron, and the first reminder tells the holders of `ar.write` (`invoice_overdue`).
- **The outbox**: a retry waits one minute per attempt; the fifth failure leaves the row failed with MaluMail's sentence and is logged once (`notification.send`: channel, kind, status, attempts — never an address or a body). A suppressed or rejected address fails at once. A K6 refusal is a skip with its code; only a kernel 5xx other than 503, or no answer, retries a text. `notify_member()` dedupes per member through `dedupe_key` (a key ending in a date means once that day).
- **Ask the Bookkeeper** is `html/bills/ask-bookkeeper.php`, a dispatch and not a manifest action: `dispatch_to_agent()` (`app/features/agents/dispatch.php`) writes `agent_dispatches`, makes one chat turn as the acting person, and keeps 200 characters of the reply; a refusal is shown in the kernel's words and not retried; a failure is retried by the worker up to three attempts. The button sits in the bill's document card, so a draft bill with its document shows it.
- **Period notices**: closing or reopening a period tells the holders of `periods.manage` (`period_closed`, `period_reopened`).
- **Not built, by the spec's own list**: K8 wakes, the agents' duties (Phase 4), editable mail templates. `reconciliation_out` has no trigger yet (a reconciliation is out of balance only while a person is on it); the kind and the dispatch exist for Phase 4's duty.

## Open questions
**Decided 2026-10-05:** the worker sends first reminders on its own once `reminder_days` is set; agents' reminders still pause.

*(As proposed:)*
- **Reminders by the worker without a person**: the clock sends step-1 reminders on its own once `reminder_days` is set (the owner's setting is the consent). Or should the worker only DRAFT the reminder as an outbox row with `send_after` null for a person to release? Proposed: the worker sends — the owner set the days.
