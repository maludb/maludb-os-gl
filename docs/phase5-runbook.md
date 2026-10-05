# Phase 5 — putting General Ledger live (the owner's runbook)

2026-10-05. Everything Phases 0–4 could prove is proven on scratch databases (`tests/phase*/run.sh`, the whole story in `tests/phase5/e2e.php`, 23 checks). What is left needs root, DNS, a mail key and people — it is yours, in this order.
The classifier refuses `apply` for the worker; the commands below are for you. **Applied 2026-10-05 14:17** (steps 0–2 done, twice: `subello_gl`, application 59, the vhost, the eight units, the roles read back, both agents hired; the AI poll has pulled two months); steps 3–5 remain — the MaluMail key, the Bookkeeper's duty, the grants to people, DNS/TLS at the proxy, the settings' blanks (business name and address, the control accounts, the morning-note recipient), the first entries and the live proof.

## 0. Before anything (read-only)
```
php /var/www/bin/app_install.php plan /srv/apps/gl --by edward.honour@gmail.com --domain subello.com
```
The plan lists 87 steps and no stop. **It proposes ports 8107–8109; the decision (D14) is 8184 / 8829 / 8830 — pin them first (step 1).**

## 1. Pin the ports (root) — only if `/srv/apps/gl/config/.env` does not exist
```
sudo bash /srv/apps/gl/deploy/ROOT_STEPS.sh apply     # step 0 only: writes config/.env with the three ports, group www-data, mode 640
php /var/www/bin/app_install.php plan /srv/apps/gl --domain subello.com | grep -E '^(todo|done) +ports'     # must say 8184, 8829, 8830
```

## 2. Apply (root) — twice
```
sudo php /var/www/bin/app_install.php apply /srv/apps/gl --by edward.honour@gmail.com --domain subello.com --hire-agents
```
**Never `--grant-standing-departments`** (D3: the books are granted person by person). Run it a second time once the records MCP answers: the roles are read from `app_roles` only then.
What it does that you should know about: creates `subello_gl` with the **20** migrations (db/019 is the template end-date fix, db/020 the three shared tools), installs the vhost and eight units (worker timer every minute, directory sync, ingest, the two MCP servers),
registers the application, mints its token, imports the seven skills, writes `mcp/registries/gl.json` and **restarts `certstudy-actions-mcp`**. That restart loads the kernel's `application_actions.py`, which had a missing `import re` until commit 4635a06 (fixed 2026-10-05): confirm the kernel is at that commit or later before you apply.

## 3. Keys and decisions (any time after 2)
- MaluMail: `MALUMAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME` into `/srv/apps/gl/config/.env` (invoices, statements, reminders and the morning note's email go through it; the worker sends once a minute). **Since 2026-10-05 the installer writes them**: put the key in `~/.malumail` (one line) and run step 2's `apply` once more — its `mail` step fills the three keys (the pages and the worker read `config/.env` on every request and pass, so nothing needs a restart); the key is never printed.
- Texts (optional): `php /var/www/bin/notify_endpoint_set.php` if no application has set the notification number yet.
- DNS and TLS for `gl.subello.com` at the proxy (until then `/etc/hosts` maps it to this host).
- Grants, in the kernel (super-admin): Viewer, Bookkeeper, Controller, Accountant — **and one person with the admin role** (settings, tokens). Nobody has the books by default.
- Hire/confirm the two agents (expert, Bookkeeper); the Bookkeeper's duty is `30 6 * * *` — set it in Agent HR until the installer reads `agents[].duty`.
- Owed in the kernel, not blocking: K14 (installer guard), K15 (the Accounting agents' grants); in HR: H1.

## 4. First entries
1. **Fiscal year** (Periods → New) — the year the books start in.
2. **Settings** — business name and address, base currency, the control accounts (the standard chart seeds 1200, 2000, 1050), terms, reminder days (default 7/21/45; empty turns the worker's reminders off), who receives the morning note, whether the AI poll is on.
3. **Bank accounts** (Settings → Bank accounts) with their opening balance and date, then **the opening entry** (the bank form links to it, prefilled: Dr the bank account / Cr 3000 Owner equity). Customers and vendors with balances: enter the open invoices and bills as documents dated when they were issued, not as journal lines — a control account refuses a line that names no customer or vendor.
4. First **bank import** (CSV) and a **first reconciliation** to the last statement.

## 5. The live end-to-end proof (design §10) — the same story as `tests/phase5/e2e.php`, with real people and a real customer
Tick each, with the number it produced:
- [ ] An invoice is **issued** (numbered, posted), **sent** (MaluMail delivered it — Outbox row `sent`), **viewed** on its link (status `viewed`), the customer **claims** payment, the Bookkeeper **confirms** it (receipt, invoice paid), and it is **deposited** (a recorded deposit).
- [ ] The deposit **matches one bank line** (Cash → Match: exactly one candidate to the cent).
- [ ] A **bill** is entered with its document, **approved** by the Controller, **paid in a payment run** (approved, recorded), and its payment matches its bank line.
- [ ] The kernel's **closed month** is pulled, imported as one bill per provider, approved, and **the check agrees** with the statement.
- [ ] The month is **reconciled** (zero difference, completed by the Controller) and **closed**; a late posting into it is refused.
- [ ] The **trial balance balances**, the **balance sheet holds**, and the **cash flow statement explains the change in cash** (Check row 0.00).
- [ ] The command bar answers "who owes us over 60 days" (it calls `ar_aging`); an agent's `journal_entry_post` **pauses** for approval in the kernel; the Bookkeeper's morning duty **posts nothing, pays nothing, sends nothing** (it drafts, imports, matches, reminds — the reminder pauses as an external send).
- [ ] **Health**: `/api/v1/health` is ok; `systemctl status 'gl-*'` is green; the worker report (`journal` → `worker.pass`) shows no errors for ten minutes.

## 6. If something is wrong
- The application is separate from the kernel: stop it with `systemctl stop gl-records-mcp gl-activity-mcp gl-worker.timer`; nothing else depends on it (the kernel's registry entry `mcp/registries/gl.json` can be removed and `certstudy-actions-mcp` restarted).
- Never edit an applied migration; add the next number. Never run the scratch proofs against `subello_gl` (they create `SMOKE …` rows).
- The open owner question from slice 3: per-currency statements (the db/015 functions carry no currency) — one base currency until you decide.
