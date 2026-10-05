# Build spec: Phase 2 — sign-on, the mirror, the shell (before any slice)

What exists at the end: a person clicks General Ledger on the launcher — or types `gl.<domain>` on a phone — and lands,
signed in, on a home screen that looks finished though it holds no books yet; the mirror refreshes every minute; every
request is logged; the command bar answers through the kernel. The kit is in the repo already (Phase 0: Help Desk's
identity, roles, sign-on, handler, HTTP and MCP common files, un-ticketed) and is proven without a kernel
(`tests/phase0/run.sh`, 15 checks); this phase wires the shell around it and proves it.
Schema: `members`, `departments`, `department_members`, `sites`, `directory_sync_state`, `sso_nonces`, `member_sessions` (db/001),
`activity_log`, `activity_ingest_state` (db/002), `mcp_access_tokens` + `mcp_resolve_token()` (db/003), `gl_rights`, `gl_roles`,
`gl_role_rights`, `gl_has_right()`, `gl_is_admin()`, `mcp_app_roles` (db/004), `gl_settings` (db/006), `notification_prefs`, `notifications`
(db/014), `mcp_members`, `mcp_settings`, `mcp_notifications`, `mcp_my_tokens`, `mcp_activity_log` (db/016), `gl_unposted_summary()`,
`gl_cash_position()`, `gl_posted_through()` (db/015). Never modify them.

## Files
**Already in the repo (Phase 0, proven):** `app/bootstrap.php` (env from `config/.env` or real environment variables, session cookie
`GLSID`, `json_mode_begin()`, the acting member on the PDO connection, the action-token / approval-replay path, the per-request mirror
re-check), `app/db.php`, `app/http.php`, `app/handler.php` (`gl_handler_begin()`, `req_val()`, `request_list()`, `gl_yes()`, `gl_int()`,
`gl_ref()`, `gl_guard()`, `gl_refuse_fields()`, `gl_land()`, `gl_done()`, `gl_diff()`, `members_for_pick()`, `find_live_departments()`,
`find_live_sites()`, `require_any_right()`), `app/auth.php` (`has_right()`, `require_right()`, `is_gl_admin()`, `require_admin()`,
`require_visible()`, `require_human()`, the verifiers), `app/directory.php` (the mirror appliers incl. `mirror_apply_roles()` and `access[]`),
`app/activity.php` (`log_activity()` with the audit keys), `app/partial_update.php`, `app/mail.php`, `app/api/bootstrap.php`,
`app/features/shell/nav.php` (the one menu table, five groups), `html/sso.php`, `html/sso/logout.php`, `html/api/v1/health.php`,
`app/views/sso/refused.php`, `bin/directory_sync.php` (`--full`, `--from-file`), `bin/mint_mcp_token.php`, `bin/build_action_registry.php`,
`bin/dev_handoff.php` + `bin/dev_directory.json`, `mcp/activity_ingest.py`, `tests/dev_router.php`, `tests/setup_dev.sh`, `deploy/*`.
**Phase 2 adds:** `app/views/layout.php` (the nxl shell, phone first), `app/views/shared/{assistant-bar,tab-bar,header,message,money}.php`
(`money.php`: the ONE formatter — amount with two decimals and the currency, negative in parentheses, `fmt_money($amount, $currency)`),
`app/views/home/dashboard.php`, `html/index.php` (screen `dashboard`), `html/login.php` (302 to `OS_LAUNCHER_URL?app=gl`), `html/logout.php`
(own sign-out: POST + CSRF, end this session, back to the launcher), `html/notifications.php` + `html/settings/notifications/read.php`,
`html/settings/index.php` (screen `settings`, tab "How I am told") + `html/settings/prefs.php`, `html/settings/tokens/index.php` + `tokens/mint.php`
+ `tokens/revoke.php`, `html/activity.php`, `html/assistant/ask.php` (the bar's POST → the kernel's chat endpoint), `app/features/home/queries.php`,
`app/features/settings/{queries,present}.php`, `app/features/activity/{queries,present}.php` (`activity_sentence()`: a row in words — slices
extend its table), `html/assets/` (the design system's `examples/assets/` verbatim + `htmx.min.js`), `html/manifest.webmanifest` + two icons
(installable on a phone; no service worker, no push), `tests/phase2/` (the proofs). Every other menu item of `nav.php` answers a placeholder
page (`render_nav_stub()`) saying which slice builds it; the registry builder reads a placeholder as unbuilt.

## The ledger is not Help Desk
- **Nobody by default** (D3): a member with no capability is sent to the launcher; there is no "requester" role that everyone holds. The
  five roles (Viewer `user`, Bookkeeper, Controller, Accountant, GL admin) come from the claims; a member whose roles are still `{}` reads
  from the capability (`admin` → admin, else `user`). The shell's badge shows the highest role held (admin › controller › accountant ›
  bookkeeper › user).
- **One public door** (`/i/{token}`, slice 4): no handler in Phase 2; the vhost's rewrite lands on 404.
- **Money on every screen** goes through `fmt_money()`; a figure never appears without its currency; nothing is summed in PHP across
  currencies.

## The receiver (`/sso`) — in order (as the kit does it)
1. `verify_sso_token($token, APP_KEY)` and `verify_sso_claims($claims)` — constant-time, both must pass, member ids must match.
2. Expiry, audience (`gl`), then the nonce: `INSERT INTO sso_nonces … ON CONFLICT DO NOTHING`; zero rows = replay → refuse.
3. In one transaction: `mirror_apply_member()` (the row with `capability`), `mirror_apply_roles()` from `claims.roles`.
4. Open the session: `session_regenerate_id(true)`, `$_SESSION['member_id']`, fresh CSRF token; `member_sessions` row.
5. `log_activity('member.sign_on', 'member', $id, ['after' => ['capability', 'roles']])`; redirect to `/`.
6. Any failure: one page `sso/refused.php` (*"This sign-on link has expired. Open General Ledger from app.<domain> again."*),
   `member.sign_on.refused` with `after.reason` — never shown.

## Every request (bootstrap, as the kit does it)
- Cookie `GLSID`, `SameSite=Lax`, `Secure` when HTTPS, `HttpOnly`, strict mode. A signed-in request re-checks the mirror row and the session
  list; otherwise the session is destroyed (`ended_by = 'directory'`) and the visitor sent to the launcher with `?app=gl`.
- Action token (`X-Action-Token` + `X-Action-Relay`, or `X-Approval-Replay`): acts as that member for one request; `Set-Cookie` removed;
  an id with no mirror row is refused and logged `member.refused`; a known, active agent with no capability is admitted at first contact
  only when the kernel's run-facts call vouches for it. `SET app.member_id` before any query; `log_screen_view()` on every rendered GET.
- `/sso/logout` (POST from the kernel): `verify_sso_logout_notice` → every session of the member ended (`ended_by = 'kernel'`); 204 always.

## The shell
- Design-system skeleton, exactly. **Phone first**: 375 px is the design. The bottom tab bar carries **Home, Statements, Invoices, Bills,
  More**; the header shows the person's name and role badge, the bell (slice 8 fills it) and the command bar; `#page-content` is the HTMX
  target; CSRF meta + `htmx:configRequest` listener; `htmx:afterSwap` re-init; the layout re-stamps `data-screen`/`data-entity`/`data-record-id`
  from `X-Screen`/`X-Entity`/`X-Record-Id` (app/http.php).
- Sidebar from 992 px (and as **More** on a phone): the five groups of `nav_groups()` — **Books**, **Receivables**, **Payables**, **Cash**,
  **Settings** — plus **Me** (Notifications, Tokens, Activity) shown to everyone. An item shows only when the person holds its right (`|` = any one).
  A Viewer therefore sees Books, the two agings, the parties and the cash cards, and no settings.
- **The command bar** (`#assistant-bar`, chat-actions): `html/assistant/ask.php` POSTs `{utterance, screen, context, conversation_id}` to
  `{OS_INTERNAL_URL}/api/v1/agents/chat.php?agent=expert` with `OS_APPLICATION_TOKEN` and `X-Acting-Member`; renders `reply`, lists
  `actions` (a paused one as "waits for approval"), follows nothing blindly; `wait` 60, then `GET ?run=` while `finished` is false; logs
  `assistant.ask` (the utterance's length and the run id, never the text). A kernel refusal (403/404/409) is shown in the kernel's words.
- Home (`/`, screen `dashboard`): the regions as empty states naming the slice that fills them — **Cash** (`gl_cash_position()`; slice 6
  fills the cards), **Who owes us / Whom we owe** (slices 4 and 5; the aging functions answer zero rows now), **What waits**
  (`gl_unposted_summary()` as count cards each a link — drafts to `/journal/?status=draft`, bills awaiting approval to `/bills/?status=draft`,
  unmatched lines to `/cash/match`, claims to `/receipts/claims`), **The AI bill** (slice 7), **Posted through** (`gl_posted_through()`, "nothing
  posted yet"), **The morning note** (the newest `morning_note` notification of the member; slice 8). A fiscal-year banner when no fiscal year
  exists: *"Open your first fiscal year to start posting"* → `/periods/new` (slice 1; the placeholder until then).
- `/settings/` (screen `settings`): tab **How I am told** — email on/off, text on/off (a note that texts need a phone verified in the OS,
  linking `<OS_LAUNCHER_URL>/settings/channels`), the event kinds as checkboxes, the text kinds — `prefs_save` (upsert `notification_prefs`).
- `/settings/tokens/` (screen `tokens`): my tokens as rows (label, scope, last used, expiry); **Mint** shows the raw value once; **Revoke** confirms.
- `/notifications` (screen `notifications`): the empty shape ("Nothing yet"), `notification_read` wired (marks one or all).
- `/activity` (screen `activity`): the member's own trail from `mcp_activity_log` in sentences, 50 a page; `?entity_type=&entity_id=` is a
  record's history through the view's rule (slices link to it).

## Query functions (signatures fixed)
- `home_summary(PDO): array` — `gl_unposted_summary()`, `gl_posted_through()`, `gl_cash_position()`, the fiscal-year flag, the newest morning note.
- `find_prefs(PDO, int $memberId): array` (defaults when no row) · `save_prefs(PDO, int $memberId, array $prefs): void`
- `find_my_tokens(PDO, int $memberId): array` · `mint_token(PDO, int $memberId, string $label, string $scope): string` (raw, shown once) ·
  `revoke_token(PDO, int $memberId, int $tokenId): bool`
- `find_my_activity(PDO, int $memberId, int $page): array` · `find_record_activity(PDO, string $type, int $id, int $limit = 100): array` ·
  `activity_sentence(array $row): string` · `find_my_notifications(PDO, int $memberId, bool $unreadOnly): array` · `mark_notifications_read(PDO, int $memberId, ?int $id): int`

## Handlers (every one: `gl_handler_begin()`; the gate; one transaction; `log_activity`; `emit_action_status`)
- `settings/prefs.php` (`prefs_save`): own row; log `prefs.save` (`before`/`after` of the changed keys).
- `settings/tokens/mint.php` (`token_mint`): `require_human()`; log `token.mint` (`after.label`, `after.scope`, the id — never the value);
  `location` ends in the token id. `tokens/revoke.php` (`token_revoke`): own token only (another's → 404 "Token not found."); log `token.revoke`.
- `settings/notifications/read.php` (`notification_read`): own rows; log `notification.read` (`after.count`).
- `logout.php`: own session ended (`ended_by = 'member'`), log `member.sign_out`, 302 to the launcher.
- `assistant/ask.php`: `require_login(); require_human();` not an action — the kernel runs the agent; log `assistant.ask`.

## Action manifest entries
- Screens: `dashboard`, `settings`, `tokens`, `notifications`, `activity`. Actions: `prefs_save`, `token_mint`, `token_revoke`, `notification_read`.
  No agent approvals.

## Activity log events
`member.sign_on`, `member.sign_on.refused`, `member.sign_out`, `member.refused`, `directory.sync`, `directory.sync.failed`, `screen.view`
(dashboard, settings, tokens, notifications, activity), `prefs.save`, `token.mint`, `token.revoke`, `notification.read`, `assistant.ask`.

## Out of scope for this phase
Every ledger screen (slices 1–9); the public door's handler (4); the bell's contents (8); the two MCP servers (Phase 4 — `maludb-os.json` lists
no MCP endpoint until then).

## Proof before Phase 3 (`tests/phase2/run.sh` on a scratch database, against `php -S` with `tests/dev_router.php` and — `GL_APP=apache` — against a private Apache serving `deploy/apache-gl.conf` rendered as the installer renders it)
**Built and proven 2026-10-05: 251 checks, green under both servers** (sso 43, gates 83 — the 36-screen × 5-role matrix among them —, sync 25, ingest 12, kernel_compat 9, vhost 27, browser 52; the registry check); the Phase 0 suite (15 + 151) still green; the installer's `plan` clean.
- [x] *(Phase 0, `tests/phase0/run.sh`, 15 checks)* a hand-off minted the kernel's way opens a session once; replay, another audience, an unknown member and a
  tampered signature → 403 with one page and the reason logged; the sign-out notice → 204 and `ended_by = 'kernel'`; a bad notice → 204; health 200.
- [x] The kernel-compat mint: a token from the kernel's own `mint_sso_token()`/`sign_sso_claims()` (its `app/auth.php` in a CLI) opens a session;
  `claims.roles = ['bookkeeper','user']` lands in `members.roles` and `has_right('journal.write')` is true; `['user']` → false; `{}` with capability
  `admin` → every right; capability `null` → the launcher (`tests/phase2/kernel_compat.php`).
- [x] The dashboard at 375 × 740 and 1280 × 800 (headless Chromium): `scrollWidth` = viewport, the tab bar on the phone and the sidebar on the desktop,
  the command bar thumb-reachable, HTMX navigation pushing URL and title, no console errors; the page whole with JavaScript off; a Viewer sees Books,
  Receivables (customers, invoices, the aging), Payables, Cash and Me and no Settings; a Bookkeeper sees Match the bank; an admin sees Settings — every
  other URL → 403 in the right's sentence (`browser.mjs`; the screens × 5 roles matrix in `gates.php`).
- [x] `bin/directory_sync.php --full` applies the fixture; an `access[]` row with `capability: null` ends the member's sessions and their next request
  → 302 to the launcher; a role change reaches `members.roles` in one pass; the cursor advances; a second run applies 0 (`sync.php`).
- [x] The ingest ships rows tagged `gl` with the audit keys and advances the checkpoint only past what a fake MaluDB accepted; `/api/v1/health`
  answers `ingest_lag` and the sync state (`ingest.php`).
- [x] The command bar against a stubbed kernel (`tests/fake_kernel.php` answering `/api/v1/agents/chat.php`): a reply rendered, a paused action shown
  as "waits for approval" with the request number, a 404 and a 409 shown in the kernel's words; `assistant.ask` logged with the length and run id, never the text.
- [x] `token_mint` shows the value once and `my_tokens` never; `mcp_resolve_token()` by SQL resolves the minted token and not the revoked one.
- [x] The home page with no fiscal year shows the banner; `fmt_money('-1608.24', 'USD')` renders `(1,608.24) USD`; a figure never renders without a currency (grep of the rendered pages).
- [x] `php bin/build_action_registry.php --check` passes; the placeholders read as unbuilt (5 screens built, 4 actions); `php /var/www/bin/app_install.php plan /srv/apps/gl` — no stop.
- [ ] ▲ (the installed application on the live kernel — the owner's `apply`): a real launch from `app.<domain>`, the return trip with `?app=gl`.

**Decisions taken while building (2026-10-05):** the kit's Phase 2 files are Help Desk's, un-ticketed (the shell, the command bar, the settings, tokens, notifications, activity, the proofs' library and
fake servers); the **Settings group shows "My settings" to every role** (the item is the person's own; a Viewer therefore sees Books, Receivables, Payables, Cash, Settings and Me, with no ledger setting
in it); the thirty placeholders are real controllers calling `render_nav_stub()` (200 after the right, 501 to JSON and to a POST), generated from `nav_groups()`; the activity screen's record history is
`?entity_type=&entity_id=` and matches the audit keys too (an entry's rows by `journal_entry_id`, a customer's by `customer_id`…), readable by every role — the books are business-wide; `activity_sentence()`
puts the number, the amount with its currency and the reason into the sentence; the money formatter also carries `parse_money()` (commas, parentheses, a leading sign); the home's "What waits" is six
count cards each a link into the filtered list and the fiscal-year banner links to `/periods/new` only for `settings.manage`; `notification_record_url()` maps a notice's record to its page; the command
bar's `{entity}Changed` families are the ledger's (`journalEntry`, `invoice`, `bill`, `bank`, `period`, `aiStatement`…); the scratch ports are 8391 (the app), 8392 (the fake kernel), 8393 (the fake
MaluDB), 8396 (the fake MaluMail), 8397 (the Apache variant's internal port); the fixture's cast is the Phase 0 proof's (Vera, Bea, Carl, Ann, the owner, the Bookkeeper agent).

## Open questions
**Decided 2026-10-05:** the stand-in icon until the owner's artwork.

*(As proposed:)*
- The theme's logo: General Ledger needs its own mark (the owner's artwork); `feather-book-open` stands in.
