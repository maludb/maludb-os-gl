# The record picker (2026-10-09)

The owner's decision (the builder plugin 0.8.0, `design-system/references/record-picker.md`; the plan in the plugin's `docs/record-picker-plan.md`): a
form chooses a **record** — an account, a vendor, a customer, an item, a person — through one searchable modal, never a `<select>` that lists every
row. ProcessCore's purchase order was the exemplar, the cidery the first retrofit; the General Ledger is a mechanical retrofit from the reference.
A chart of accounts runs to hundreds of rows and a bill has a dozen lines, each with an account and an item: the selects loaded the whole list once per line.

- **Added:** `app/picker.php` (`picker_field()`, `picker_source()`, `picker_label()`, plus `picker_paged_query()`, `picker_like()` and `picker_attrs()`),
  `app/pickers.php` (the registry), `html/pick/index.php`, `app/views/shared/picker-rows.php`, `html/assets/js/record-picker.js`, the RECORD PICKER
  section of `app-overrides.css`, the `#record-picker` modal and the script in `layout.php` (and a `htmx:beforeSwap` hook that lets the picker's own 403/404
  fragments swap into the modal — the shell's `responseHandling` swaps no other 4xx). The GL's shell has no unsaved-changes guard, so there is none to teach.
- **Sources (`app/pickers.php`):** `account` (`code — name`; params `types` and `flags`), `vendor`, `customer`, `item`, `member`. Each `search` runs the feature's
  own view with the list screen's ILIKE, paged 25 a page (`accounts_pick_page()`, `vendors_pick_page()`, `customers_pick_page()`, `items_pick_page()`,
  `members_pick_page()` beside the list queries) — not `find_vendors()` itself: a list card carries every row's balance, a picker must not compute them for all.
  `label` finds one record, archived or not, so a saved choice keeps its name. **The gate is a right, not a role:** `rights` (any one admits) is the right the
  source's `mcp_*` view already demands — `account` reports.view, `vendor` and `customer` parties.view, `item` documents.view, `member` ar.write / ap.write /
  settings.manage. (The reference's `roles` + `user_can()` become `rights` + `has_right()`.)
- **The account filter** (`accounts_for_select($types, $flags)`) travels as two integers: `types` = the sum of asset 1, liability 2, equity 4, income 8, expense 16
  (none = every type); `flags` = is_bank yes 1 / no 2, is_control yes 4 / no 8. `account_pick_masks()` makes them from the helper's arguments, `accounts_pick_page()`
  reads them. A flag listed without a truth value — the bank form's `['is_bank']` — filtered nothing before and still filters nothing; fixing it is a one-word change
  (`['is_bank' => true]`) for the owner to decide.
- **Converted once, in the helpers, so every caller changed together** (signatures kept; `$attrs` still carries `form="…"` and `required`, a class is dropped;
  `$empty` is the placeholder, and `null` means the choice is required — no Clear): `account_select()` (20 uses: the journal's lines and filter, the bill, vendor
  credit, invoice and recurring lines, the item, customer, vendor, bank, posting-rule and settings forms, a bank line's entry), `vendor_select()` (8),
  `customer_select()` (8), `party_select()` (4, the journal line's customer and vendor). New helpers beside them, `item_select()` and `member_select()`:
  the item of a bill, invoice and document line (`line_item[]`, `item`) and the member of a customer, a vendor and the morning note. Plus the report filters
  (customer of a statement and the receivables aging, vendor of the payables aging). All six helpers load from the bootstrap now; a handler no longer has to remember.
  The hidden input keeps the old name and id (`line_account[]`, `form="bill-form"`…), so no handler, `hx-include`, smoke or manifest row changed.
- **Still selects, by the rule (D7):** banks and bank accounts, departments, sites, tax rates, periods, statuses, kinds, methods, formats, date formats,
  the account form's parent and subtype (a tree and a dependent list). **Owed or left on purpose:** the "Apply to" lists — a vendor's open bills, a customer's
  open invoices, the deposit's receipts, the payment run's draft payments (`vendor_credits/view.php`, `credit_notes/view.php`, `receipts/view.php`,
  `payments/view.php`, `deposits/view.php`, `payment_runs/partials/run-payments.php`) — each a short list filtered by a record already chosen, never searched by name;
  they would be a dependent source each (the bill or invoice with its owner as a declared param) when a business's lists outgrow a select.
- **What a picker cannot do that a select did:** the browser's native `required` check — a hidden input is exempt — so the handlers' own refusals ("vendor is required.",
  a 422 flash) are what stops an empty vendor now; the "Who is being paid?" and "Who paid?" chooser forms submit an empty choice back to themselves.
  And a GL refusal never re-renders a form (a 422 is a flash over the form the person still sees), so the picked label cannot be lost; a **first** render and an
  edit form look the label up (`?vendor=`, `/bills/{id}/edit`, a draft's line rows).
- **Proven:** `scripts/prove-picker.sh` on a scratch database (`gl_dev*`; it refuses the installed one) — the endpoint's refusals (anonymous, unknown source, a
  right the caller lacks), search, escaping, paging, the account filter, every converted form, one bill, one invoice, a journal line and a bank-line entry saved
  through the hidden inputs — and `tests/picker/browser.mjs` (headless Chromium: keyboard, the typed account filter, a bill saved through the UI, the list filter,
  375 px, dark). Set `GL_DEV_ENV` to an env file on other ports when 8391–8396 are shared with a sibling checkout.
