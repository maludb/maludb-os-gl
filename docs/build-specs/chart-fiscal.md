# Build spec: the chart and fiscal time — slice 1 (the CRUD exemplar for workers)

Built first and copied by every later CRUD slice: cards for named things, full-page create/edit, the gate in SQL and mirrored in PHP,
JSON mode on every handler, the database's refusal shown in its own words. What exists at the end: the admin shapes the books — the
chart of accounts, the fiscal years with their periods, tax rates, items, posting rules, sites, the ledger's settings and numbering —
and the controller closes and reopens periods. Nothing here writes a journal entry yet (slice 2) except the year-end close, which is a
function of db/008 this slice merely calls.
Schema: `account_types`, `accounts`, `posting_rules`, `gl_posting_rule()`, `gl_normal_balance()` (db/005); `gl_settings`, `document_sequences`,
`tax_rates`, `items`, `gl_base_currency()` (db/006); `fiscal_years`, `fiscal_periods`, `gl_fiscal_year_create()`, `gl_period_for_date()`,
`gl_period_close()`, `gl_period_reopen()` (db/007); `gl_year_close()`, `gl_year_reopen()`, the archive guard (db/008); `sites` (db/001); the
views `mcp_accounts`, `mcp_posting_rules`, `mcp_fiscal_years`, `mcp_fiscal_periods`, `mcp_tax_rates`, `mcp_items`, `mcp_sites`, `mcp_settings`
(db/016); `gl_account_balance()`, `gl_account_activity()` (db/015). Never modify them.

## Screens

| Screen id | Canonical URL | Purpose |
|---|---|---|
| `accounts-list` | `/accounts/` | The chart as cards grouped by type, balances as of today (`type`, `include_archived`, `as_of`) |
| `account-add` | `/accounts/new` | Add an account (`type`, `parent`) |
| `account-view` | `/accounts/{id}` | One account: balance, activity with a running balance, children (`from`, `to`, `department`, `site`) |
| `account-edit` | `/accounts/{id}/edit` | Rename, re-parent, describe |
| `periods-list` | `/periods/` | The fiscal years and their periods as cards |
| `fiscal-year-add` | `/periods/new` | Open a fiscal year (`start`) |
| `period-view` | `/periods/{id}` | One period: state, history, what posted, what is still a draft, the close checklist |
| `tax-rates` | `/settings/tax-rates/` | The tax rates |
| `tax-rate-add` / `tax-rate-edit` | `/settings/tax-rates/new`, `/{id}/edit` | A rate |
| `items-list` | `/settings/items/` | Products and services (`q`, `kind`) |
| `item-add` / `item-edit` | `/settings/items/new`, `/{id}/edit` | An item |
| `posting-rules` | `/settings/posting-rules/` | Which source posts where |
| `posting-rule-edit` | `/settings/posting-rules/{id}/edit` | A rule's accounts |
| `sites-list` | `/settings/sites/` | The sites |
| `site-add` / `site-edit` | `/settings/sites/new`, `/{id}/edit` | A site |
| `general-settings` | `/settings/general` | The ledger's settings |
| `numbering` | `/settings/numbering` | Prefixes and next numbers |
| `agents` | `/settings/agents` | Which agents keep books here (read-only) |

## The chart
- `accounts-list`: five groups in type order (Assets, Liabilities, Equity, Income, Expenses), each a stack of `RecordCard`s (`account-card-{id}`):
  code and name, the subtype as a chip, flags as chips (**Bank**, **Control**, **System**), the balance as of `as_of` (today) through
  `gl_account_balance()` in the account's normal sense, formatted by `fmt_money()` in the base currency, a child indented under its parent.
  Archived accounts hidden unless `include_archived`. **Add account** for `settings.manage` (prefilled with the group's type). Empty: never (seeded).
- `account-view`: the facts; **Activity** — `gl_account_activity()` for the period (default: this fiscal year to date; `from`/`to`;
  `department`/`site` selects) as a table: date, entry number (link to `/journal/{id}`, slice 2 — a plain number until then), source, memo,
  description, debit, credit, running balance; the opening balance row first; a CSV with `format=csv` (logged `export.download`); children listed;
  **Edit** and **Archive** (`settings.manage`); a control account says *"Takes no line without its customer or vendor"*; a bank account links to
  its bank account (`/cash/banks/{id}`, slice 6).
- `account-add` / `account-edit` (one `form.php`): code (immutable after create for a system account; otherwise editable — the database keeps it
  unique), name, type (radio; immutable for a system account), subtype (a select narrowed by type), parent (a select of live accounts of the same
  type, "None"), is bank (checkbox; refused with control), description. The handler calls nothing but the INSERT/UPDATE; the guard trigger's RAISE
  (`cannot sit under`, `keeps its type`, `cannot be both`) → 422 on the field.
- **Archive**: `archived = yes` sets `archived_at`; the archive guard's RAISE (*"Account 6100 Rent has a balance of 100.00 and cannot be archived
  until it is moved"*) → 422 in those words. A system account archives (it is renameable and hideable, never deleted).

## Fiscal time
- `periods-list`: one card per fiscal year (`fiscal-year-card-{id}`): name, dates, status, closed by/at; inside, its twelve periods as a compact
  grid of chips — **open** (`success`), **closed** (`dark`), **reopened N×** (`warning` when `reopen_count > 0` and open) — each a link to
  `period-view`; posted and draft counts under each. **Open a fiscal year** for `settings.manage`. Empty: *"No fiscal year yet — open the first
  one to start posting."* (the dashboard's banner links here).
- `fiscal-year-add`: start date (a month picker; the first of the month), name (derived `FY<year of the end>` when blank). `gl_fiscal_year_create()`;
  its RAISEs (overlap → *"Two fiscal years never overlap"* from the exclusion's `conflict`; not the first → its sentence) → 422.
- `period-view`: name, dates, status, the history (closed by/at, reopened by/at/reason, reopen count — from the row and `mcp_activity_log`),
  **What posted** (count and total by source; links to `/journal/?period=`), **Still a draft** (count; link), the **close checklist**
  (slice 8's `month-end-close` items as read-only lines with ✓/✗: drafts, the AI statement, undeposited receipts older than the month, bank
  reconciled through the month's end, control tie-out, recurring due — each computed by `period_checklist()`), and the buttons: **Close**
  (`periods.manage`, confirm), **Reopen** (a reason field, confirm), **Close the year** (on the year's last period when every period is closed:
  `year_end_close`), **Reopen the year** (a reason).
- A period's close is `gl_period_close()`; reopen `gl_period_reopen()` (a blank reason → its RAISE → 422 *"Reopening a period needs a reason"*).
  The year's close is `gl_year_close()`: its RAISEs (*"Every period of FY2026 must be closed before the year is"*, *"Draft entries are dated in
  FY2026; post or void them first"*) → 422; on success the closing entry's number is shown and linked. The year's reopen `gl_year_reopen()`.

## Tax rates, items, posting rules, sites, settings, numbering
- `tax-rates`: rows (name, rate, default badge, archived); add/edit full-page; archiving the default → 422 *"Make another rate the default first."*
  (the one-default index would refuse un-defaulting; the handler says it in words). Making one default un-defaults the other in the same transaction.
- `items-list`: cards (`item-card-{id}`): name, code, kind chip, unit price, the income and expense accounts by code, the tax rate; search; filter by kind.
  Add/edit: code (unique live), name, kind, description, unit, unit price, income account (a select of income accounts), expense account (expense),
  tax rate. Archive keeps it off the pickers.
- `posting-rules`: one row per rule in source order: source (in words: "Invoice issued"), match key (empty = default), debit account, credit account,
  tax account, note, active. Edit: the three account selects (narrowed: a debit of an invoice rule is the receivable; the form lets any account be
  chosen and warns in a muted line when the type is unusual), note, active. `rule` on `posting_rule_save` is the rule id or the source name (a new
  keyed rule is made by naming a source and a match key).
- `sites-list`: cards (`site-card-{id}`): name, address, the kernel location chip when pinned. Add/edit: name, kernel location id (a number;
  *"from the OS's Work locations"*), address. Archive.
- `general-settings` (`/settings/general`, GET the form, POST = `settings_save`): the business's name, address and tax id (on the invoice), base
  currency (read-only once any document exists — a note says so), fiscal year start month, time zone, the control accounts (selects narrowed by
  type: AR = asset, AP = liability, undeposited = asset, sales tax = liability, AI usage = expense, retained earnings = equity, bad debt = expense),
  the default bank account (slice 6; a select of bank accounts, empty until then), invoice and bill terms, the link's life, reminder days (a list
  field), the AI statement poll on/off, the morning note's recipient (a member select), the invoice footer.
- `numbering` (`/settings/numbering`, GET the table, POST = `numbering_save` per row): kind, prefix, next value (never lowered: 422 *"The next
  number cannot go below the current"*), padding, a preview ("INV-00042").
- `agents` (`/settings/agents`, read-only): one card per OS agent the mirror knows with a capability: name, roles, the last dispatch (kind, via,
  when, status) from `mcp_agent_dispatches`, a link to the kernel's agent page. Empty: *"No agent keeps books here yet — the installer proposes two."*

## Files (exactly these)
- `html/accounts/index.php` · `form.php` · `view.php` · `save.php` · `archive.php`
- `html/periods/index.php` · `form.php` · `view.php` · `save.php` · `close.php` · `reopen.php` · `year-close.php` · `year-reopen.php`
- `html/settings/tax-rates/index.php` · `form.php` · `save.php` · `archive.php`
- `html/settings/items/index.php` · `form.php` · `save.php` · `archive.php`
- `html/settings/posting-rules/index.php` · `form.php` · `save.php`
- `html/settings/sites/index.php` · `form.php` · `save.php` · `archive.php`
- `html/settings/general.php` · `html/settings/numbering.php` · `html/settings/agents.php`
- `app/features/accounts/queries.php` · `present.php` — `app/features/periods/queries.php` · `present.php` — `app/features/settings/queries.php` (extends Phase 2's) · `present.php`
- `app/views/accounts/list.php` · `form.php` · `view.php` · `partials/account-card.php` · `activity-table.php` — `app/views/periods/list.php` · `form.php` · `view.php` · `partials/year-card.php` · `period-chip.php` · `checklist.php` —
  `app/views/settings/tax-rates.php` · `tax-rate-form.php` · `items.php` · `item-form.php` · `partials/item-card.php` · `posting-rules.php` · `posting-rule-form.php` · `sites.php` · `site-form.php` · `general.php` · `numbering.php` · `agents.php`
- `app/views/shared/account-select.php` (the ONE account picker: `account_select($name, $selected, $types = [], $flags = [])` over `mcp_accounts`, "code — name", used by every later slice)

## Query functions (signatures fixed)
- `find_accounts(PDO, array $filters = []): array` (mcp_accounts + balance as of; `type`, `include_archived`, `as_of`, `q`) · `find_account(PDO, int $id): ?array` · `find_account_by_code(PDO, string $code): ?array` ·
  `account_activity(PDO, int $id, string $from, string $to, ?int $dept, ?int $site): array` (`gl_account_activity()`) · `save_account(PDO, ?int $id, array $fields, int $by): int` · `archive_account(PDO, int $id, bool $archived, int $by): void` ·
  `accounts_for_select(PDO, array $types = [], array $flags = []): array`
- `find_fiscal_years(PDO): array` (with periods) · `find_period(PDO, int $id): ?array` · `period_summary(PDO, int $id): array` (posted by source, drafts) · `period_checklist(PDO, int $id): array` ·
  `create_fiscal_year(PDO, string $start, ?string $name, int $by): int` · `close_period(PDO, int $id, int $by): void` · `reopen_period(PDO, int $id, int $by, string $reason): void` ·
  `close_year(PDO, int $yearId, int $by): ?array` (the closing entry) · `reopen_year(PDO, int $yearId, int $by, string $reason): void`
- `find_tax_rates(PDO, bool $includeArchived = false): array` · `save_tax_rate(PDO, ?int $id, array $fields): int` · `archive_tax_rate(PDO, int $id, bool $archived): void`
- `find_items(PDO, array $filters = []): array` · `find_item(PDO, int $id): ?array` · `save_item(PDO, ?int $id, array $fields): int` · `archive_item(PDO, int $id, bool $archived): void`
- `find_posting_rules(PDO): array` · `save_posting_rule(PDO, string|int $rule, array $fields): int`
- `find_sites(PDO, bool $includeArchived = false): array` · `save_site(PDO, ?int $id, array $fields): int` · `archive_site(PDO, int $id, bool $archived): void`
- `find_settings(PDO): array` · `save_settings(PDO, array $fields): array` (returns changed keys) · `find_numbering(PDO): array` · `save_numbering(PDO, string $kind, array $fields): array` · `find_agents_here(PDO): array`

## Handlers (every one: `gl_handler_begin()`; the gate; one transaction; `log_activity` with the audit keys; `emit_action_status`; a create's `location` ends in the id)
- `accounts/save.php` (`account_save`): `require_right('settings.manage')`; `gl_guard()` turns the guard trigger's RAISE into a 422 field error; log `account.save` (`before`/`after` of changed fields).
  `archive.php` (`account_archive`): the archive guard's sentence; log `account.archive` (`after.archived`).
- `periods/save.php` (`fiscal_year_create`): `settings.manage`; log `fiscal_year.create` (`after.name`, `after.start`, `after.end`, `after.periods` = 12). `close.php` / `reopen.php` (`period_close` / `period_reopen`): `require_right('periods.manage')`;
  log `period.close` / `period.reopen` with `period_id`, `after.name`, `after.reason`, `after.reopen_count`. `year-close.php` / `year-reopen.php`: `periods.manage`; log `fiscal_year.close` (`after.closing_entry_number`, `after.amount`, `after.currency`, `journal_entry_id`) / `fiscal_year.reopen` (`after.reason`).
- `settings/tax-rates/*`, `items/*`, `posting-rules/save.php`, `sites/*`: `require_right('settings.manage')`; unique-name refusals in words (`23505` → *"A rate named X already exists."*); log `tax_rate.save|archive`, `item.save|archive`, `posting_rule.save`, `site.save|archive`.
- `settings/general.php` (POST = `settings_save`): `settings.manage`; `before`/`after` of changed keys only; the base currency refused once a document exists (*"The base currency is fixed once the books hold a document."*); "Nothing changed" logs nothing.
  `settings/numbering.php` (POST = `numbering_save`): `settings.manage`; log `numbering.save`.

## Action manifest entries
- Screens: the nineteen above. Actions: `account_save`, `account_archive`, `fiscal_year_create`, `period_close`, `period_reopen`, `year_end_close`, `year_reopen`, `tax_rate_save`, `tax_rate_archive`,
  `item_save`, `item_archive`, `posting_rule_save`, `site_save`, `site_archive`, `settings_save`, `numbering_save`. Agent approvals: `other` on every configuration save and archive and on the period actions
  (the default policy refuses `period_close`, `period_reopen`, `year_reopen` to agents); `money_out` on `year_end_close`.

## Activity log events
`account.save`, `account.archive`, `fiscal_year.create`, `period.close`, `period.reopen`, `fiscal_year.close`, `fiscal_year.reopen`, `tax_rate.save`, `tax_rate.archive`, `item.save`, `item.archive`,
`posting_rule.save`, `site.save`, `site.archive`, `settings.save`, `numbering.save`, `export.download` (an account's CSV), `screen.view` (each screen). Configuration rows carry `before`/`after` of the
changed fields only (`config_change_log` reads them); period rows carry `period_id`.

## Out of scope for this slice
Writing or posting a journal entry (2); the statements (3); bank accounts' settings (6 — the settings' default bank account select is empty until then); the close checklist's live items beyond what
`period_checklist()` can compute from the schema (the AI statement row reads `ai_statements`, the bank row `bank_accounts.last_reconciled_through`, the recurring row `recurring_templates` — all tables
exist; the slices fill the data).

## Proof (`tests/phase3/slice1/run.sh`, scratch database, `php -S` + headless Chromium at 375 × 740 and 1280 × 800)
**Built and proven 2026-10-05: 219 checks green** (rights 13, chart 33, fiscal 30, settings 48, json 17, browser 77, the registry); the Phase 2 suite (251) and Phase 0 (15 + 151) still green;
the registry reads the 16 actions and 21 screens as built (`92 screens (26 built), 124 actions (20 built)`).
- [x] Rights: a Viewer → the chart and the periods list (200) and 403 on every form, archive, close and setting with the right's sentence (*"You may not change the ledger's settings."*,
  *"You may not close or reopen a period."*); a Bookkeeper the same; the Controller closes and reopens; the admin everything.
- [x] The chart: an account added under a parent of another type → 422 in the trigger's words; a bank+control account → 422; a system account's type change → 422; archiving an account with a
  balance → 422 naming the balance; archiving an empty one hides it from the pickers and `include_archived` shows it; the cards' balances equal `gl_account_balance()`; the activity table's running
  balance ends at the balance; the CSV logged `export.download`.
- [x] Fiscal time: a year made with twelve periods; a second overlapping → 422; closing a period, posting into it refused by slice 2's function (proven here by SQL: `gl_entry_post` RAISE); reopening
  without a reason → 422; with one → `reopen_count` 1 and the chip says so; the year's close refused while a period is open and while a draft exists, then made with its entry shown; the year's reopen
  reverses it (`gl_account_balance(3900)` back to 0).
- [x] Settings: the base currency editable before any document and refused after an invoice exists (fixture by SQL); the control accounts narrowed by type; `settings_save` logs before/after of the
  changed keys only; numbering's next value cannot go below the current; the prefix shows in the preview and numbers the next document (SQL).
- [x] Tax rates and items: one default at a time; archiving the default refused; an item's accounts narrowed; the posting rule's accounts saved and read back by `gl_posting_rule()`.
- [x] JSON mode: every handler answers `{ok, did, record_id, location}` to an action token and 422 `{error: {code: invalid, errors[], fields{}}}` on a bad form; the kernel's `_partial=1` fills the base row for
  `account_save`, `item_save`, `site_save`; a run token's rows are source `agent` with the run's request id and the rights hold.
- [x] `php bin/build_action_registry.php --check` passes and the 16 actions read as built; every screen at 375 and 1280 with `scrollWidth` = viewport; every button 44 px; a refused save lands in `#flash`
  and keeps the form; JavaScript off the forms post and land.

## Decisions taken while building (2026-10-05) — the CRUD pattern every later slice copies
- **The handler validates the shape, the database the rule.** A handler checks types, lengths, references and the field names (`gl_ref()`, `gl_int()`, `gl_yes()`, `request_list()`),
  then calls the INSERT/UPDATE or the `gl_*` writer inside `gl_guard()`, whose job is to turn a `DomainException` (our sentence) or the database's `P0001` RAISE into the 422 in
  those words (`23505` → "already taken", `23P01` → "overlaps", `23503` → "still in use"). A system account's type change is left to the trigger ("keeps its type"): the handler
  resets a subtype only when the type changes and no subtype came.
- **The one money formatter is loaded by the bootstrap** (`app/views/shared/money.php` after `handler.php`): `fmt_money()`, `fmt_money_cell()` (a `<td>` with the sign class),
  `parse_money()` (commas, parentheses, a leading sign). The one account picker is `account_select($pdo, $name, $selected, $types, $flags, $attrs, $empty, $id)` over `mcp_accounts`.
- **Balances are "as of"**: the chart's cards read `gl_account_balance(id, as_of)` (today by default); an activity table starts with an opening row (`gl_account_balance` the day
  before `from`) and `gl_account_activity()` carries the running balance; a year-end closing entry is dated the year's last day, so its effect shows only as of that day or later.
- **A closed period keeps its drafts**: `gl_period_close()` refuses nothing but a second close; the draft stays until the period is reopened. The year's close refuses an open
  period and a draft dated in the year, in the function's words; the reopen reverses the closing entry (`gl_entry_reverse` under the hood) and leaves the periods closed.
- **The close checklist** (`period_checklist()`) is six read-only lines computed from the schema (drafts, the AI statement by `ai_statements.status` in `imported|agrees`, undeposited
  receipts older than the month, every live bank reconciled through the month's end, `gl_control_check(end_date)` tied out, recurring templates due); a line that has nothing to
  judge (no bank account yet, no statement pulled) shows "–".
- **Settings**: `save_settings()` answers before/after of the changed keys only and "Nothing changed" logs nothing; the control-account selects are narrowed by type AND the handler
  refuses another type in words ("Accounts receivable is an asset account."); the base currency is refused once any journal entry, invoice, bill, receipt or payment exists
  (`books_hold_a_document()`); `reminder_days` is a list field (comma-separated or `[]`), de-duplicated and sorted. `numbering_save` is one row per POST (`kind` + the changed fields).
- **Tax rates**: one default — a new default un-defaults the others in the same transaction; un-defaulting or archiving the default is "Make another rate the default first."
- **Posting rules**: `rule` is an existing id, or a source name with `match_key` (the default rule when empty): the same source + key updates the one rule; `gl_posting_rule(source, key)`
  falls to the default when the keyed rule is off.
- **Proof helpers that later slices reuse** (`tests/phase3/slice1/lib.php`): `as_member()`, `act()`, `act_html()`, `screen()`, `act_token()`, `run_token()`, `post_entry()` (transactional:
  a refused post leaves no draft), `balance_of()`, `account_id()`, `period_id()`, `fy_id()`.

## Open questions
- (none)
