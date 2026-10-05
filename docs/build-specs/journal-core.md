# Build spec: journal core — THE EXEMPLAR (slice 2)

Built by the planning-class model. Every later slice replicates its files, ids, gates, flow and proof style. This is the
application's spine: an entry drafted by a person or an agent, its lines edited with the balance strip, posted into an open period
by a person, immutable from then on, reversed when wrong; a document's entry reached from the document and the document from the
entry; attachments and notes on any record; the timeline from the activity log.
Schema: `journal_entries`, `journal_lines`, `gl_entry_guard()`, `gl_lines_immutable()`, `gl_entry_post()`, `gl_entry_reverse()`,
`gl_entry_void()` (db/008); `gl_period_for_date()` (db/007); `attachments`, `notes` (db/009); the views `mcp_journal_entries`,
`mcp_journal_lines`, `mcp_attachments`, `mcp_notes`, `mcp_activity_log` (db/016); `gl_unposted_summary()`, `gl_posted_through()`
(db/015). Never modify them.
**The database is the referee**: born a draft, the period from the date, the balance, the open period, the control-account party,
the number on post, immutability, reversal and void live in db/008's triggers and functions; the handlers decide WHAT to ask for and
translate a refusal (`SQLSTATE P0001`) into its sentence as a 422. **A handler never sums lines to decide whether an entry balances:
it asks the database to post and shows what it said; the balance strip is a courtesy, the trigger is the gate.**

## Screens (designed at 375 px first; the list and the detail usable at 375 px, designed at 1280 px)

| Screen id | Canonical URL | Purpose |
|---|---|---|
| `journal-list` | `/journal/` | The entries as a ledger: drafts first (with "balances" / "out by …"), then by date desc; filters `period`, `status`, `source`, `account`, `from`, `to`, `q` (number or memo); 50 a page; a totals footer per currency |
| `journal-entry-add` | `/journal/new` | A manual entry: date, memo, dimensions, the lines editor with the balance strip (`date`, `reverse_of`, `template`) |
| `journal-entry-view` | `/journal/{id}` | One entry: head, lines, the source document, the reversal link, attachments, notes, the timeline, the actions by status |
| `journal-entry-edit` | `/journal/{id}/edit` | A draft's date, memo, dimensions; the lines editor |

## The list
- Each row (`entry-row` partial, a table on the desktop; `entry-card` on a phone): number (or **Draft** chip), date, period (chip; `warning` when
  reopened, `dark` when closed), source as words ("Invoice", "Receipt", "Manual", "Reversal of JE-000120", "Year-end close"), memo, total (debits;
  `fmt_money()`), status badge (**draft** secondary · **posted** success · **reversed** warning · **void** dark), a draft's balance state
  ("balances" `success` / "out by 12.00" `danger`), the creator (an agent shows a chip **agent**), attachments count. Sort: drafts first, then
  `entry_date desc, id desc`; `?sort=` from an allow-list (`entry_date`, `number`, `total_debit`).
- Region `#entry-list` (Pattern B, `hx-push-url` explicit) listens to `journalEntryChanged`. Filters row: `period` (select of periods), `status`,
  `source`, `account` (the account picker), `from`/`to`, `q`. **Post all balanced drafts** button (`journal_entries_post_batch` over the listed
  drafts; confirm) for `journal.write`.
- Empty: *"No entries yet. The first is usually the opening balances."* with **New entry**.

## The entry page
- **Header**: number (or Draft), date and period chip, status badge, source, the dimensions (department, site), created by (chip for an agent),
  posted by/at; the SOURCE DOCUMENT as a link when `source_type` is set ("From invoice INV-00042", "From bill BILL-000007" — the link's target is
  the document's page; a slice not yet built shows the number without a link); **Reverses JE-000120** / **Reversed by JE-000131** links.
- **Lines** (`lines` partial): a table — line no, account (code — name), description, debit, credit, department, site, customer or vendor (name),
  tax rate; a footer with totals and the difference. For a DRAFT the table is the **lines editor** (below). A posted entry's table is read-only
  with a muted line *"Posted entries never change — reverse it to correct it."*
- **The balance strip** (`balance-strip` partial, draft only): total debits, total credits, difference — `success` at zero, `danger` otherwise;
  **Post** disabled while the difference is not zero or the period is closed or there are no lines (the endpoint refuses anyway).
- **Actions by status**: draft → **Post** (confirm), **Edit**, **Void** (a reason; confirm); posted → **Reverse** (a reason, an optional date;
  confirm; `journal.write` for one's own entries — `created_by` = me — and `journal.override` for any), **Make a template** (slice 8); reversed and
  void → none. A document-born entry (`source <> manual`) shows *"Reverse it from the document"* instead of Reverse: the invoice's void, the bill's
  void, the receipt's void are what reverse it — so the document and its entry never disagree. (The exception: `source = recurring` and
  `import`, which have no document, reverse here.)
- **Attachments** (`attachments` partial, shared by every record page): chips (name · size) linking to `/attachments/get.php?id=`
  (served through `require_visible()` on the record's view; `X-Content-Type-Options: nosniff`; inline for images and PDFs, download otherwise);
  **Add** (multipart; ≤ `ATTACHMENT_MAX_BYTES`, default 5 MB; a mime allow-list: images, PDF, text, CSV, office documents; stored at
  `storage/attachments/<record_type>/<record_id>/<id>-<sha256 prefix>`; the mime from `finfo`); **Delete** for `journal.override` (confirm).
- **Notes** (`notes` partial, shared): the notes newest first with author and time (an agent chipped); add a note (plain text).
- **Timeline** (`timeline` partial, shared): `find_record_activity('journal_entry', id)` in sentences — "Bea drafted it", "Bea changed line 2:
  6200 Software 240.00 → 259.80", "Carl posted it (1,608.24 USD) into 2026-04", "Carl reversed it with JE-000131: wrong month", "Bookkeeper (agent)
  attached hosting-april.pdf"; newest first.
- `data-screen="journal-entry-view" data-entity="journal_entry" data-record-id="{id}"`; an entry the member may not see → 404 "Entry not found."
  (every role sees every entry — the sentence serves a wrong id).

## The lines editor (the pattern every document's lines copy — invoices, bills, credits)
- The exemplar's inline child-row pattern (`hx-include="closest tr"`, region re-render, never a `<form>` in a `<tr>`): one row per line with
  **Account** (the shared `account_select()`, live accounts, "code — name", searchable by typing), **Description**, **Debit**, **Credit** (entering
  one clears the other in the re-render; the CHECK refuses both), **Department** (select, default the entry's), **Site** (select), **Customer** /
  **Vendor** (a party select shown only when the chosen account is a control account of that subtype — AR shows the customer select, AP the vendor;
  the trigger refuses a post without it), **Tax rate**, **Remove**. A blank "add a line" row at the bottom. **Every line write re-renders the whole
  lines region AND the balance strip**, because both change together (`#entry-lines` + `#entry-balance`, `hx-swap-oob`).
- A line's POST is `line-save.php` (`journal_line_save`; `line` present = update) and `line-remove.php`; the entry must be a draft — the
  immutability trigger's RAISE (*"Lines of entry JE-000124 cannot be changed: it is posted"*) → 422 in those words.
- The money fields accept `1,608.24`, `1608.24` and `(100.00)` (a negative debit becomes a credit); two decimals; a zero line is refused
  (*"A line is a debit or a credit."*).
- An agent (`journal_entry_draft` with `lines` as JSON rows) gets the same validation; a row that names an account by code or name resolves
  through `find_account_by_code()` / the resolver; an unknown one → 422 naming the row.

## Drafting, posting, reversing, voiding
- `journal_entry_draft` (`save.php` without `entry`): `journal.write`; INSERT the head (the trigger sets the period and keeps status draft); the
  `lines` JSON rows inserted in order; `reverse_of` prefills a draft with the swapped lines of a posted entry (a manual reversal with its own date —
  the automatic one is `journal_entry_reverse`); `template` prefills from a recurring template (slice 8). Log `journal_entry.draft`. Location `/journal/{id}`.
- `journal_entry_update` (`save.php` with `entry`): a draft's date (the period follows), memo, dimensions; `journal.write`; the guard's RAISE on a
  non-draft → 422. Log `journal_entry.update` (`before`/`after` of changed fields).
- `journal_entry_post` (`post.php`): `journal.write`; `require_visible()`; `gl_entry_post($id, me)`; the RAISEs → 422 in their words: *"Entry has
  no lines"* · *"Entry does not balance: debits 11000.00, credits 10000.00 (difference 1000.00)"* · *"No fiscal period covers 2027-03-01 — open the
  fiscal year first"* · *"Period 2026-02 is closed; nothing posts into it until it is reopened"* · *"Account 1200 is a control account: every line on
  it names its customer or vendor"* · *"Account 6100 is archived and takes no new line"*; on success log `journal_entry.post` (`after.number`,
  `after.amount` = total_debit, `after.currency`, `after.period`, `after.reopened_period` = the period's `reopen_count > 0`, `period_id`,
  `journal_entry_id`); `did: "Posted JE-000124 (1,608.24 USD)"`. **Pauses for an agent** (`money_out`).
- `journal_entries_post_batch` (`post-batch.php`): `journal.write`; each entry in its own savepoint; the report lists posted numbers and each
  refusal with its sentence; log `journal_entry.post_batch` (`after.posted`, `after.refused[]`); 200 even when some refused (the `did` says
  "Posted 3 of 5; 2 refused: …"). **Pauses for an agent** (`money_out`).
- `journal_entry_reverse` (`reverse.php`): posted only; `journal.write` on one's own (`created_by` = me) else `journal.override`; a document-born entry
  → 422 *"Reverse it from the invoice (void it) so the document and the books agree."*; `gl_entry_reverse($id, me, $reason, $date)`; a blank reason
  → its RAISE; a date in a closed period → the posting RAISE (the form offers "today" when the original's period is closed); log `journal_entry.reverse`
  on the original (`after.reversal_id`, `after.reversal_number`, `after.reason`, `after.amount`); location `/journal/{reversal}`. **Pauses** (`money_out`).
- `journal_entry_void` (`void.php`): a draft only; `journal.write`; `gl_entry_void()`; log `journal_entry.void` (`after.reason`). **Pauses** (`deletion`).
- `attachment_add` / `attachment_delete` / `note_add` (`/attachments/save.php`, `/attachments/delete.php`, `/notes/save.php`): generic over
  `record_type`/`record_id`; the record must be visible through its view (`RECORD_VIEWS` map in `app/features/records/views.php`: type → view, id
  column, the right to write it); `attachments.write` / `journal.override` / `documents.view`; log `attachment.add` (`after.filename`, `byte_size`,
  `mime_type`, the record keys) / `attachment.delete` / `note.add` (`after.excerpt`).

## Files (exactly these)
- `html/journal/index.php` · `form.php` (`journal-entry-add`, `journal-entry-edit`) · `view.php` · `save.php` · `line-save.php` · `line-remove.php` · `post.php` · `post-batch.php` · `reverse.php` · `void.php`
- `html/attachments/save.php` · `delete.php` · `get.php` — `html/notes/save.php`
- `app/features/journal/queries.php` · `present.php` · `write.php` · `handler.php` (the handlers' shared prelude: load the entry, the gate, `entry_refused()`)
- `app/features/records/views.php` (`RECORD_VIEWS`, `record_visible()`, `record_label()`, `record_url()` — the map every later slice extends) · `app/features/records/attachments.php` · `notes.php`
- `app/views/journal/list.php` · `view.php` · `form.php` · `partials/entry-row.php` · `entry-card.php` · `lines.php` · `line-row.php` · `balance-strip.php` · `source-link.php`
- `app/views/shared/attachments.php` · `notes.php` · `timeline.php` · `party-select.php` (`party_select($kind, $name, $selected)` over `mcp_customers` / `mcp_vendors`)

## Query functions (signatures fixed)
- `find_entries(PDO, array $filters, int $limit = 50, int $offset = 0): array` (rows from `mcp_journal_entries` + a draft's balance state) · `count_entries(PDO, array $filters): int` · `entry_totals(PDO, array $filters): array` (per currency)
- `find_entry(PDO, int $id): ?array` (mcp_journal_entries + lines, attachments count, the source document's number and url through `record_label()`/`record_url()`) · `find_entry_by_number(PDO, string $number): ?array` · `entry_lines(PDO, int $id): array` · `entry_balance(PDO, int $id): array` (debits, credits, difference — from the lines, for the strip only)
- `draft_entry(PDO, array $head, array $lines, int $by): int` · `update_entry(PDO, int $id, array $head, int $by): array` (before/after) · `save_line(PDO, int $entryId, ?int $lineId, array $f): int` · `remove_line(PDO, int $lineId): bool`
- `post_entry(PDO, int $id, int $by): array` (`gl_entry_post`; the row) · `post_entries(PDO, array $ids, int $by): array` (posted[], refused[]) · `reverse_entry(PDO, int $id, int $by, string $reason, ?string $date): array` (the reversal row) · `void_entry(PDO, int $id, int $by, ?string $reason): array`
- `reversal_prefill(PDO, int $id): array` (the swapped lines for `?reverse_of=`)
- `record_visible(PDO, string $type, int $id): bool` · `record_label(PDO, string $type, int $id): ?string` · `record_url(string $type, int $id): string` · `store_attachment(PDO, string $type, int $id, array $file, int $by): int` · `attachment_path(PDO, int $attachmentId): ?array` · `delete_attachment(PDO, int $id): void` · `find_attachments(PDO, string $type, int $id): array` · `add_note(PDO, string $type, int $id, string $body, int $by): int` · `find_notes(PDO, string $type, int $id): array`
- `entry_refused(Throwable $e): never` (in `handler.php`: a `P0001` → 422 with the database's sentence as the flash and, when it names a line's account, as that field's error)

## Handlers (every one: `gl_handler_begin()`; the gate; `require_visible('mcp_journal_entries', 'journal_entry_id', $id, 'Entry')`; one transaction; `log_activity` with `journal_entry_id` (+ `period_id` on post/reverse); `emit_action_status`; `HX-Trigger: journalEntryChanged`)
- As listed under "Drafting, posting, reversing, voiding"; `line-save.php` and `line-remove.php` log `journal_entry.line_save` / `.line_remove` with the line's account code and amounts (before/after of a changed line).
- `attachments/get.php`: GET; `record_visible()`; `nosniff`; not an action.

## Action manifest entries
- Screens: `journal-list`, `journal-entry-add`, `journal-entry-view`, `journal-entry-edit`.
- Actions (11): `journal_entry_draft`, `journal_entry_update`, `journal_line_save`, `journal_line_remove`, `journal_entry_post`, `journal_entry_reverse`, `journal_entry_void`, `journal_entries_post_batch`,
  `attachment_add`, `attachment_delete`, `note_add`. Agent approvals: `journal_entry_post`, `journal_entry_reverse`, `journal_entries_post_batch` (`money_out`); `journal_entry_void`, `attachment_delete` (`deletion`).
  `PARTIAL_UPDATE_TARGETS` gains `/journal/save.php => ['journal_entries', 'entry', 'mcp_journal_entries', 'journal_entry_id']`.

## Activity log events (every row: `journal_entry_id`; post, reverse: `period_id` too)
`screen.view` (each screen; on `journal-entry-view` `after.record_type`/`record_id`), `journal_entry.draft`, `journal_entry.update`, `journal_entry.line_save`, `journal_entry.line_remove`, `journal_entry.post`,
`journal_entry.post_batch`, `journal_entry.reverse`, `journal_entry.void`, `attachment.add`, `attachment.delete`, `note.add` — payload keys per the tool surface. **Every money event carries `after.amount` and `after.currency`.**

## Status vocabulary
- Status badges: draft `secondary` · posted `success` · reversed `warning` · void `dark`. Period chips: open `success` · reopened `warning` · closed `dark`. A draft's balance: "balances" `success` · "out by …" `danger`.
  Source words: manual "Manual" · invoice "Invoice" · credit_note "Credit note" · receipt "Receipt" · deposit "Deposit" · write_off "Write-off" · bill "Bill" · vendor_credit "Vendor credit" · payment "Payment" · transfer "Transfer" ·
  bank_fee "Bank fee" · bank_interest "Interest" · ai_usage "AI usage" · payroll "Payroll" · recurring "Recurring" · reversal "Reversal" · closing "Year-end close" · opening "Opening balances" · import "Import".
- Ids: `entry-row-{id}`, `entry-card-{id}`, `entry-lines`, `entry-line-{id}`, `entry-balance`, `entry-source`, `entry-attachments`, `entry-notes`, `entry-timeline`, `journal-filters`, `entry-list`.

## Out of scope for this slice
The statements (3); every document that posts (4–7 — their entries appear here as rows with a source link once they exist); recurring templates and "Make a template" (8); the home's counts are Phase 2's.

## Proof (`tests/phase3/slice2/run.sh`: the scratch database, members through dev hand-offs, curl with signed action and run tokens, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
- [ ] **Draft, lines, the strip.** Bea drafts an entry with two lines out by 1,000: the strip says "out by 1,000.00" and Post is disabled; the endpoint called anyway → 422 in the trigger's words; a line with both
  sides → 422 *"A line is a debit or a credit."*; `(100.00)` in debit becomes a credit; a third and fourth line balance it; the strip says "balances"; the period chip follows the date; a date with no fiscal
  year → the period chip says "no period" and posting → *"No fiscal period covers …"*.
- [ ] **Post.** Posted: numbered `JE-000001`, totals, posted by Bea, `journal_entry.post` with amount, currency, period, `reopened_period false`; a second draft dated in a period the Controller then closes →
  *"Period 2026-02 is closed; …"*; reopened with a reason → posts with `reopened_period true`, and `period_activity` would show it; a line on 1200 without a customer → 422 naming the account; with a customer → posts;
  the entry page read-only afterwards; `line-save.php` on it → 422 *"… cannot be changed: it is posted"*; `save.php` on it → 422; delete → refused.
- [ ] **Batch.** Five drafts, two unbalanced: "Posted 3 of 5; 2 refused: …" with each sentence; the three numbered in order; the two still drafts.
- [ ] **Reverse and void.** Bea reverses her own posted entry: the reversal's lines are swapped, dated the original's date, posted, linked both ways, `journal_entry.reverse` on the original; a reversal of a
  reversed entry → 422; Bea on Carl's entry → 403 (`journal.override`); Carl may; the original's period closed → the form offers today and the reversal lands in the open period; a document-born entry
  (fixture by SQL: an issued invoice) → 422 *"Reverse it from the invoice …"*; a draft voided with a reason; voiding a posted one → 422 *"… reverse it instead"*.
- [ ] **Attachments and notes.** A PDF on the entry stored under `storage/attachments/journal_entry/<id>/`, served inline with `nosniff`; the limit + 1 byte → 422; an `.exe` → 422; delete as Carl (`override`)
  removes the file; Bea → 403; a note by the agent shows the chip; the timeline in words with amounts; an unknown record type → 422; a record id the member cannot see (slice 4's customer with a wrong id) → 404.
- [ ] **The list.** Drafts first with their balance state; filters by period, status, source, account (an entry with a line on 6200 found), `q` by number and memo; the totals footer per currency; 50 a page; the
  CSV of the list logged `export.download`.
- [ ] **Agents.** Under the Bookkeeper's run token: `journal_entry_draft` with `lines` as JSON (accounts by code) → a draft, source `agent` with the run's request id; `journal_entry_post` → the handler runs
  (the pause is the kernel's; proven in Phase 4 against the stubbed hook); an unknown account code in a row → 422 naming the row; `is_eval` → nothing written (the kit refuses).
- [ ] **JSON mode.** Every handler under a signed action token: `{ok, did, record_id, location}`; 422 `{error: {code: invalid, fields}}`; 404 in one sentence; `_partial=1` keeps the head's fields; the relay
  missing → 401; the entry and the list answer JSON.
- [ ] **375 × 740 and 1280 × 800**: cards on the phone, a table on the desktop, the lines editor scrolls in `.table-responsive`, the strip stays readable, the detail's panels stacked, `scrollWidth` = viewport,
  every button ≥ 44 px, no console errors, the flows through the real screens (draft, add lines, post, reverse), JavaScript off; the registry reads the 11 actions and 4 screens as built.

## Open questions
- **Reversing a document-born entry from the journal page.** The spec refuses it (the document's void does it). Should `journal.override` be allowed to reverse any entry from here regardless, leaving the
  document's status untouched? Proposed: no — the document and its entry must never disagree.
- **Manual lines on a bank account's ledger account.** Allowed (an opening balance, a correction), and they appear as match candidates for the bank's lines (db/013). Confirm.
