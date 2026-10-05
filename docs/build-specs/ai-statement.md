# Build spec: the kernel's AI statement — slice 7

What exists at the end: the kernel's monthly token statement pulled through the ledger feed, kept verbatim, shown by provider and department,
imported as one draft bill per provider when the month is closed, checked against the bills to the cent, and the worker's hourly poll that
does the pull alone. The bills are slice 5's; the posting is `bill_approve`'s.
Schema: `ai_statements`, `ai_statement_lines`, `ai_statement_bills`, `gl_ai_statement_load()`, `gl_ai_provider_vendor()`, `gl_ai_statement_import()`,
`gl_ai_statement_check()`, `gl_ai_statement_mark_checked()` (db/012); the views `mcp_ai_statements`, `mcp_ai_statement_lines`, `mcp_ai_statement_bills`,
`mcp_bills` (db/016). Never modify them. The kernel: `GET {OS_INTERNAL_URL}/api/v1/ledger/periods.php` (the months and their status) and
`?period=YYYY-MM` (the `os.ledger-period/1` document), with `OS_APPLICATION_TOKEN` (kernel A5).

## Screens

| Screen id | Canonical URL | Purpose |
|---|---|---|
| `ai-statements` | `/ap/ai/` | One card per month (`ai-statement-card-{id}`), newest first: the period, **open in the OS** / **closed**, pulled at, the ledger's state (**pulled** · **imported** · **agrees** `success` · **differs** `danger`), totals per currency, late calls, the bills (numbers and statuses); months the kernel lists but the ledger has not pulled as muted cards; **Pull now** |
| `ai-statement-view` | `/ap/ai/{id}` | The month: the document's header facts (business, period, generated at, exchange); the lines as a table grouped by **provider** (default), **department**, **agent** or **model** (`?group_by=`), with calls, late calls, tokens, amount; the **bills** panel (one row per provider: bill number, status, total, the statement's amount, the difference — the check); **Import** (closed and not yet imported), **Check** (imported); attachments; notes; the timeline |

## Pulling
- `ai_statement_pull` (`pull.php`): `ap.write`; `pull_ai_periods()` lists the months; for `period` given, or for every month listed, `pull_ai_period($period)`
  GETs the document and calls `gl_ai_statement_load()` (an imported month with a different hash → the RAISE → 422 *"Statement 2026-09 was imported from a different
  document …"* shown and logged `ai_statement.pull` with `after.changed = refused`); log `ai_statement.pull` (`after.period`, `after.kernel_status`, `after.changed`).
  The kernel unreachable → 502 in its words (*"The OS did not answer: …"*); 401 → *"The application token is not accepted by the OS"*.
- **The worker's poll** (slice 8's `bin/worker.php`, step `ai`): once an hour when `gl_settings.ai_statement_poll`, the same `pull_ai_periods()` and, for each
  month whose status or hash changed, `pull_ai_period()`; `source = cron`; a closed month newly pulled → a notice `ai_statement_imported`? No — a notice
  **`ai_statement_closed`** is not in the kinds list: the Bookkeeper's duty reads `ai_statement_status` each morning (D6); the worker only pulls. (The kinds list
  has `ai_statement_imported` and `ai_statement_differs`, written at import and at check.)

## Importing and checking
- `ai_statement_import` (`import.php`): `ap.write`; `gl_ai_statement_import($id, me)` — the RAISEs → 422 (*"… is still open in the OS"*, *"… is already imported"*,
  *"No AI usage expense account is set"*); the bills made are listed in `did` ("Imported 2026-09: 2 bills — BILL-000012 Anthropic 20.01 USD, BILL-000013 OpenAI 3.00 USD")
  and linked; log `ai_statement.import` (`after.period`, `after.bills[]`); a notice `ai_statement_imported` to members whose prefs include it (the controller: the
  bills await approval). **Not paused for an agent** (it makes drafts; approval is the pause).
- `ai_statement_check` (`check.php`): `reports.view`; `gl_ai_statement_check()` rendered; `gl_ai_statement_mark_checked()` records the verdict; log `ai_statement.check`
  (`after.verdict`, `after.differences[]`); a `differs` verdict queues `ai_statement_differs` to the controller.
- A provider's bill voided (slice 5) → the check differs by that provider's whole amount and says so; importing again is refused (once per month): the
  controller un-voids nothing — they enter the bill by hand with `source = ai_usage` and the check names the difference until the totals agree (the page says
  *"Enter a bill for Anthropic of 20.01 USD with reference 'anthropic 2026-09' and the check will agree"*; the bill form prefilled through `?copy_of=` the voided one).
- The vendor made for a provider (`kind = ai_provider`, `provider_key`) carries pay method `card` and terms 0; the admin may rename it and set its contact.

## Files (exactly these)
- `html/ap/ai/index.php` · `view.php` · `pull.php` · `import.php` · `check.php`
- `app/features/ai/queries.php` · `present.php` · `write.php` · `kernel.php` (`pull_ai_periods()`, `pull_ai_period()` over `app/http.php`'s kernel client)
- `app/views/ai/list.php` · `view.php` · `partials/statement-card.php` · `lines-table.php` · `bills-check.php`

## Query functions (signatures fixed)
- `pull_ai_periods(): array` (the kernel's months: period, status) · `pull_ai_period(string $period): array` (the document) · `load_ai_statement(PDO, array $document): array` (`gl_ai_statement_load()`)
- `find_ai_statements(PDO): array` (the ledger's rows + the kernel's list merged) · `find_ai_statement(PDO, int $id): ?array` · `ai_statement_lines(PDO, int $id, string $groupBy): array` · `import_ai_statement(PDO, int $id, int $by): array` (the bills) · `check_ai_statement(PDO, int $id, int $by): array`

## Handlers (`gl_handler_begin()`; the gate; one transaction per statement; `log_activity`; `emit_action_status`; `HX-Trigger: aiStatementChanged`)
As listed above.

## Action manifest entries
- Screens: `ai-statements`, `ai-statement-view`. Actions (3): `ai_statement_pull`, `ai_statement_import`, `ai_statement_check`. No agent approvals (the bills' approval is the pause).

## Activity log events
`ai_statement.pull`, `ai_statement.import`, `ai_statement.check`, `screen.view`.

## Out of scope for this slice
Approving and paying the bills (5); the worker's timer (8 — this slice writes `ai_poll_pass()` for it to call); the Bookkeeper's duty (Phase 4).

## Proof (`tests/phase3/slice7/run.sh`: `tests/fake_kernel.php` answering `/api/v1/ledger/periods.php` from a state file with an open and a closed month)
**Built and proven 2026-10-05 by the worker: 148 checks green** under `php -S` and under a real Apache (pull 28, import 43, group 16, poll 9, json 19, browser 33, the registry); slices 1–6, Phase 2 and Phase 0 still green. The registry reads **2 screens and 3 actions** as built (`92 screens (86 built), 124 actions (119 built)`). No schema change.
- [x] Pull: the two months pulled, the open one importable → 422; the closed one imported → two draft bills with lines by department rounded to the cent (the Phase 0 proof's figures), the vendors made, `ai_statement.import` with the bills; the controller approves one; the check agrees per provider; the other voided → differs, the page's sentence; a changed document for the imported month → 422 and logged as refused; the kernel down → 502 in words; a bad token → the sentence.
- [x] The group-by table's totals equal the document's totals; late calls shown; a Viewer reads the page and 403 on import; JSON mode; 375 and 1280; the registry reads 3 actions and 2 screens.

## Decisions taken while building (2026-10-05)
- **The kernel client is `app/features/ai/kernel.php`** over `kernel_call()`: `pull_ai_periods()` (the months, newest first; schema `os.ledger-periods/1`) and `pull_ai_period($period)` (`os.ledger-period/1`) throw an `AiKernelError` carrying the status the screen answers: unreachable, a 5xx or a body that is not JSON → **502 "The OS did not answer: …"**; a 401 or 403 → **502 "The application token is not accepted by the OS."** (the caller's own authority is not what failed, so not 401); another 4xx → **422 "The OS refused: …"**. `tests/fake_kernel.php` answers `/api/v1/ledger/periods.php` from `state.ledger_periods` / `state.ledger_documents[period]` with `state.ledger_mode` ok | unauthorized | down (the proofs write it with `ledger_state()`).
- **`ai_statement_pull`** (`period` or every listed month): each month in its own transaction, logged `ai_statement.pull` (`after.period`, `kernel_status`, `changed` = new | updated | unchanged | refused, `reason` on a refusal — logged after the rollback, so a refusal leaves its trace). A single month refused is 422 in the database's words; pulling every month with one refusal answers 200 with `refused[]` and the others pulled (422 only when every month was refused). A different document for a month NOT yet imported is taken ("updated"); for an imported one, refused. The page asks the kernel on every load: if it does not answer the alert says so and the ledger's own months are still shown.
- **The statement a handler names** is `ai_statement` (its id), `statement` (the manifest's name: an id, or the month itself) or `period` (`YYYY-MM`). Rights: pull and import `ap.write`; check `reports.view` (a check changes only the recorded verdict, so a Viewer may press it).
- **`ai_statement_import`'s answer** lists the bills ("Imported 2026-09: 2 bills — BILL-000012 Anthropic 20.01 USD, BILL-000013 OpenAI 3.00 USD"; `ai_provider_label()` writes Anthropic, OpenAI, Google …) and carries `bills[]`; the approvers (`ap.approve`) get an in-app `ai_statement_imported` notice, and a `differs` check an `ai_statement_differs` notice with the sentences (email is slice 8's).
- **The check's sentences** (`ai_difference_sentence()`): a voided bill → "The bill for OpenAI (BILL-…) is void: the check differs by its whole 3.00 USD. Enter a bill for OpenAI of 3.00 USD with reference 'openai 2026-09' and the check will agree." with a link `?copy_of=` the voided bill; a changed amount → "Anthropic's bill … is 20.01 USD and the statement says 20.50 USD (short by 0.49)". **A bill entered for the provider with the reference "<provider> <period>" takes the voided bill's place in the check** — `link_ai_replacement_bill()` (in `bills/write.php`, called by `draft_bill`) points `ai_statement_bills` at it and marks it `source = ai_usage` (the schema's check reads only that link; without this the page's promise could not come true). Nothing happens unless the statement's bill for that provider and currency is void.
- **The poll** (`ai_poll_pass()` in `app/features/ai/write.php`, `php bin/ai_poll.php` runs it by hand, advisory-locked): when `gl_settings.ai_statement_poll`, it lists the months and **fetches every one** (the list carries no hash; a closed month that changed is the thing to catch, and the load keeps an unchanged one as it is); a new or updated month, a refusal and a failure are logged with source cron and no actor, an unchanged month is not; a changed imported month is refused by the database and the pass goes on. Slice 8's worker calls it once an hour.
- **The month's page:** the lines grouped by `provider` (default), `department` (a line with none is "No department"), `agent` or `model` per currency with calls, late calls, tokens and the amount at four decimals, and a total row per currency equal to the document's; the header facts (business, generated at, exchange note, late calls, hash) come from the kept document; the bills panel is `gl_ai_statement_check()` row by row.
- **Phase 2's browser hop** to a not-yet-built screen now goes to `/settings/recurring/` (slice 8).

## Open questions
- (none)
