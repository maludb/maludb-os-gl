#!/usr/bin/env bash
# Proves the record picker in the General Ledger (the retrofit of ProcessCore's exemplar, 2026-10-09; docs/20-record-picker.md; app/picker.php, app/pickers.php,
# html/pick/index.php, shared/picker-rows.php, assets/js/record-picker.js; the design-system reference record-picker.md) on a SCRATCH database:
#   1. the code: php -l; the converted fields are pickers and no <select> of an account, vendor, customer, item or member remains in a form;
#   2. under php -S (tests/dev_router.php = the vhost's rewrites) with a fake kernel, people signed in through the real /sso receiver with a hand-off token
#      signed by the scratch key — the owner (admin), Bea (bookkeeper), Vera (viewer): the endpoint's refusals (anonymous, unknown source, a source whose right the
#      caller lacks), the first page in order with the chosen row marked, search, escaping, the 80-character cut, paging through "Show more", the account
#      source's declared params (types, flags), a param that is not a number, an undeclared param;
#   3. every converted form renders its picker under the old name and id with the chosen label (a record page, an edit form, a preselected "?vendor=");
#      an id that names nothing is the placeholder; one bill and one invoice are saved through the hidden inputs exactly as through the selects, a 422 keeps
#      its sentence (a GL refusal answers a flash, never a re-rendered form), a line's account is changed through its row form.
# The scratch environment: $GL_DEV_ENV (default /tmp/gl-dev.env, written by tests/setup_dev.sh, which this runs when the file is missing). It names the database
# (gl_dev*/…_prove* only — the script refuses anything else, the installed subello_gl above all), the ports, and the scratch key. Set GL_DEV_ENV to an env
# file with other ports when 8391-8396 are taken (a sibling checkout's proofs share them): the app is APP_INTERNAL_PORT, the fake kernel OS_INTERNAL_URL's port.
# Repeats: its rows are named "Picker …" and go first. Needs `sudo -n -u postgres` only for setup_dev.sh.
set -u
cd "$(dirname "$0")/.."
ENVF=${GL_DEV_ENV:-/tmp/gl-dev.env}
[ -f "$ENVF" ] || tests/setup_dev.sh >/dev/null || { echo "setup failed" >&2; exit 2; }
set -a; . "$ENVF"; set +a
case "$DB_NAME" in gl_dev*|*_prove*) ;; *) echo "refusing to run against $DB_NAME: a proof writes — only a scratch database (gl_dev*, *_prove*), never the installed one" >&2; exit 2;; esac
PORT=$APP_INTERNAL_PORT; KPORT=${OS_INTERNAL_URL##*:}
B="http://127.0.0.1:$PORT"
export PGPASSWORD=$DB_PASSWORD
STATE=$(mktemp -d); export FAKE_KERNEL_STATE=$STATE/kernel.json
pass=0; fail=0
ok()  { pass=$((pass+1)); echo "ok   $1"; }
bad() { fail=$((fail+1)); echo "FAIL $1"; }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }
sql() { psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -At -v ON_ERROR_STOP=1 -c "$1"; }
handoff() { php -r '$m=(int)$argv[1]; $k=getenv("ACTION_TOKEN_KEY"); $fx=json_decode(file_get_contents("bin/dev_directory.json"),true);
  $p=$m.".".(time()+60).".gl.".bin2hex(random_bytes(16)); $t=$p.".".hash_hmac("sha256","sso:".$p,$k);
  $c=rtrim(strtr(base64_encode(json_encode(($fx["claims"][(string)$m]??[])+["member_id"=>$m])),"+/","-_"),"="); echo "/sso?".http_build_query(["token"=>$t,"claims"=>$c.".".hash_hmac("sha256",$c,$k)]);' "$1"; }
PIDS=""
cleanup() { for p in $PIDS; do pkill -P "$p" 2>/dev/null; kill "$p" 2>/dev/null; done; rm -rf "$STATE" "$J" "$JB" "$JV" 2>/dev/null; }
trap cleanup EXIT
LOG=/tmp/gl-picker-php-$PORT.log

echo "== 1. the code"
for f in app/picker.php app/pickers.php html/pick/index.php app/views/shared/picker-rows.php app/views/layout.php app/bootstrap.php app/views/shared/{account,vendor,customer,party,item,member}-select.php \
         app/features/{accounts,vendors,customers,settings}/*.php; do php -l "$f" >/dev/null 2>&1 && ok "lint $f" || bad "lint $f"; done
check "the shell carries the one modal, its search box (with the spread) and the script" "grep -q 'id=\"record-picker\"' app/views/layout.php && grep -q 'hx-vals=\"js:{...recordPickerValues()}\"' app/views/layout.php && grep -q 'record-picker.js' app/views/layout.php"
check "the shell lets the picker's 403/404 fragments swap into the modal only" "grep -q \"e.detail.target.id === 'record-picker-results'\" app/views/layout.php"
check "app-overrides.css has the picker's section" "grep -q 'RECORD PICKER' html/assets/css/app-overrides.css"
check "node parses the script" "node --check html/assets/js/record-picker.js"
check "no <select> of a record remains in a view (accounts, vendors, customers, items, members, parties)" "! grep -rnE '<select[^>]*name=\"(vendor|customer|account|line_account\\[\\]|line_item|item|member|morning_note_member|income_account|expense_account|debit_account|credit_account|tax_account)\"' app/views"
check "the five helpers all call picker_field" "[ \$(grep -lc 'picker_field' app/views/shared/{account,vendor,customer,party,item,member}-select.php | wc -l) = 6 ]"
check "the action registry still matches the manifest" "php bin/build_action_registry.php --check >/dev/null 2>&1"

echo "== 2. the servers and the rows"
php -r '$f=json_decode(file_get_contents("bin/dev_directory.json"),true); file_put_contents($argv[1], json_encode(["feed"=>$f["feed"]]));' "$FAKE_KERNEL_STATE"
php -S 127.0.0.1:$KPORT tests/fake_kernel.php >"$STATE/kernel.log" 2>&1 & PIDS="$PIDS $!"
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:$PORT -t html tests/dev_router.php >"$LOG" 2>&1 & PIDS="$PIDS $!"
for i in $(seq 1 30); do curl -s -o /dev/null "$B/api/v1/health" && break; sleep 0.2; done
php bin/directory_sync.php --full >/dev/null 2>&1
sql "DELETE FROM journal_lines WHERE entry_id IN (SELECT id FROM journal_entries WHERE memo = 'picker proof' AND status = 'draft'); DELETE FROM journal_entries WHERE memo = 'picker proof' AND status = 'draft';
     DELETE FROM bill_lines WHERE bill_id IN (SELECT id FROM bills WHERE memo = 'picker proof'); DELETE FROM bills WHERE memo = 'picker proof';
     DELETE FROM invoice_lines WHERE invoice_id IN (SELECT id FROM invoices WHERE memo = 'picker proof'); DELETE FROM invoices WHERE memo = 'picker proof';
     DELETE FROM bill_lines WHERE bill_id IN (SELECT id FROM bills WHERE memo LIKE 'picker browser %'); DELETE FROM bills WHERE memo LIKE 'picker browser %'; DELETE FROM items WHERE name LIKE 'Picker Item %'; DELETE FROM vendors WHERE name LIKE 'Picker Vendor %'; DELETE FROM customers WHERE name LIKE 'Picker Customer %'; DELETE FROM accounts WHERE code ~ '^71[0-9][0-9]$';
     INSERT INTO vendors (name, kind, terms_days, currency, created_by) SELECT format('Picker Vendor %s', lpad(n::text, 2, '0')), 'vendor', 30, 'USD', 1 FROM generate_series(1, 30) n;
     INSERT INTO vendors (name, terms_days, currency, created_by, archived_at) VALUES ('Picker Vendor Old', 30, 'USD', 1, now());
     INSERT INTO customers (name, terms_days, currency, created_by) SELECT format('Picker Customer %s', lpad(n::text, 2, '0')), 15, 'USD', 1 FROM generate_series(1, 30) n;
     INSERT INTO items (code, name, kind, unit, unit_price) SELECT format('PI-%s', lpad(n::text, 2, '0')), format('Picker Item %s', lpad(n::text, 2, '0')), 'service', 'h', n * 10 FROM generate_series(1, 30) n;
     INSERT INTO accounts (code, name, account_type, subtype) SELECT format('71%s', lpad(n::text, 2, '0')), format('Picker Account %s', lpad(n::text, 2, '0')), 'expense', 'other' FROM generate_series(1, 30) n" >/dev/null
V1=$(sql "SELECT id FROM vendors WHERE name='Picker Vendor 01'"); V30=$(sql "SELECT id FROM vendors WHERE name='Picker Vendor 30'"); VOLD=$(sql "SELECT id FROM vendors WHERE name='Picker Vendor Old'")
C1=$(sql "SELECT id FROM customers WHERE name='Picker Customer 01'"); IT1=$(sql "SELECT id FROM items WHERE code='PI-01'"); IT2=$(sql "SELECT id FROM items WHERE code='PI-02'")
A_EXP=$(sql "SELECT id FROM accounts WHERE code='7101'"); A_REV=$(sql "SELECT id FROM accounts WHERE code='4100'"); A_BANK=$(sql "SELECT id FROM accounts WHERE code='1010'"); A_FEE=$(sql "SELECT id FROM accounts WHERE code='6500'")
sql "UPDATE accounts SET is_bank = true WHERE code = '1010'" >/dev/null
NV=$(sql "SELECT count(*) FROM mcp_vendors") 2>/dev/null; NV=$(sql "SELECT count(*) FROM vendors WHERE archived_at IS NULL")
NA=$(sql "SELECT count(*) FROM accounts WHERE archived_at IS NULL"); NEXP=$(sql "SELECT count(*) FROM accounts WHERE archived_at IS NULL AND account_type='expense'")
J=$(mktemp); JB=$(mktemp); JV=$(mktemp)
signin() { curl -s -o /dev/null -w '%{http_code}' -c "$1" -b "$1" "$B$(handoff "$2")"; }
check "the owner signs in through /sso ($(signin $J 1))" "[ \$(signin $(mktemp) 1) = 302 ] && [ -n \"\$(grep -c GLSID $J)\" ]"
signin $J 1 >/dev/null; signin $JB 27 >/dev/null; signin $JV 26 >/dev/null
get() { curl -s -b "$J" -c "$J" "$B$1"; }
code() { curl -s -o /tmp/gl-picker-body-$PORT.html -w '%{http_code}' -b "${2:-$J}" "$B$1"; }
BODY=/tmp/gl-picker-body-$PORT.html
rows() { grep -c 'class="list-group-item list-group-item-action record-picker-row' "$1"; }
check "anonymous: sent to the launcher, no rows" "[ \$(curl -s -o /dev/null -w '%{http_code}' \"$B/pick/?source=vendor\") = 302 ]"
check "an unknown source is 404 with a fragment, not a page" "[ \$(code '/pick/?source=nope') = 404 ] && grep -q 'That list does not exist' $BODY && ! grep -q '<html' $BODY"
check "a source key is cleaned before the lookup" "[ \$(code '/pick/?source=../etc') = 404 ] && [ \$(code '/pick/') = 404 ]"
check "the viewer may not choose a member (403, a fragment)" "[ \$(code '/pick/?source=member' $JV) = 403 ] && grep -q 'permission' $BODY && ! grep -q '<html' $BODY"
check "the viewer may choose a vendor, a customer, an account, an item (the rights their views demand)" "for s in vendor customer account item; do [ \$(code \"/pick/?source=\$s\" $JV) = 200 ] || exit 1; done"
check "the bookkeeper may choose a member" "[ \$(code '/pick/?source=member' $JB) = 200 ] && grep -q 'record-picker-row-' $BODY"
get '/pick/?source=vendor' > /tmp/gl-picker-p1-$PORT.html
check "the first page is 25 rows in name order ($NV live vendors, the first Picker Vendor 01)" "[ \$(rows /tmp/gl-picker-p1-$PORT.html) = 25 ] && grep -o 'data-label=\"[^\"]*\"' /tmp/gl-picker-p1-$PORT.html | head -1 | grep -q 'data-label=\"Picker Vendor 01\"'"
check "every row carries value, label and a detail line (kind · currency · terms)" "grep -q 'data-value=\"$V1\" data-label=\"Picker Vendor 01\"' /tmp/gl-picker-p1-$PORT.html && grep -q 'record-picker-detail\">Vendor · USD · Net 30' /tmp/gl-picker-p1-$PORT.html"
check "Show more asks for page 2 and says 25 of $NV shown" "grep -q 'id=\"record-picker-more\"' /tmp/gl-picker-p1-$PORT.html && grep -q 'page=2' /tmp/gl-picker-p1-$PORT.html && grep -q '25 of $NV shown' /tmp/gl-picker-p1-$PORT.html"
get '/pick/?source=vendor&page=2' > /tmp/gl-picker-p2-$PORT.html
check "page 2 is the rest, rows only, no list wrapper, no Show more" "[ \$(rows /tmp/gl-picker-p2-$PORT.html) = $((NV - 25)) ] && ! grep -q 'record-picker-list' /tmp/gl-picker-p2-$PORT.html && ! grep -q 'record-picker-more' /tmp/gl-picker-p2-$PORT.html"
check "an archived vendor is not offered; a page past the end still answers" "! grep -q 'Picker Vendor Old' /tmp/gl-picker-p1-$PORT.html /tmp/gl-picker-p2-$PORT.html && [ \$(code '/pick/?source=vendor&page=99') = 200 ]"
check "the chosen row is marked active and selected" "get '/pick/?source=vendor&selected=$V1' | grep -q 'record-picker-row active\" id=\"record-picker-row-$V1\" role=\"option\" aria-selected=\"true\"'"
check "search narrows by name" "[ \$(get '/pick/?source=vendor&q=vendor%2007' | grep -c 'id=\"record-picker-row-') = 1 ]"
check "no match says so with the words escaped" "get '/pick/?source=vendor&q=%3Cb%3Enothing' | grep -q 'No vendor matches “&lt;b&gt;nothing”'"
check "a LIKE wildcard in the search is a literal (50% matches nothing)" "get '/pick/?source=vendor&q=50%25' | grep -q 'No vendor matches'"
check "a search longer than 80 characters is cut, not refused" "[ \$(code \"/pick/?source=vendor&q=\$(printf 'a%.0s' \$(seq 1 120))\") = 200 ]"
check "customers: name order, currency and terms as the detail" "get '/pick/?source=customer' | grep -q 'data-value=\"$C1\" data-label=\"Picker Customer 01\"' && get '/pick/?source=customer' | grep -q 'record-picker-detail\">USD · Net 15'"
check "items: searched by code as well as name, the unit price as the detail" "get '/pick/?source=item&q=pi-02' | grep -q 'data-label=\"Picker Item 02\"' && get '/pick/?source=item&q=pi-02' | grep -q 'PI-02 · Service · 20.00 / h'"
check "accounts: 'code — name' by code, the type as the detail, $((NA+30)) of them in 25s" "get '/pick/?source=account' | grep -q 'data-label=\"1000 — Cash on hand\"' && get '/pick/?source=account' | grep -q 'record-picker-detail\">Assets' && get '/pick/?source=account' | grep -q 'of $NA shown\|of $((NA)) shown'"
check "accounts: searched by code or name" "[ \$(get '/pick/?source=account&q=7115' | grep -c 'id=\"record-picker-row-') = 1 ] && get '/pick/?source=account&q=cost%20of' | grep -q '5000 — Cost of goods sold'"
check "accounts: types=8 lists income accounts only" "get '/pick/?source=account&types=8' > /tmp/gl-picker-inc-$PORT.html; grep -q '4100 — Service revenue' /tmp/gl-picker-inc-$PORT.html && ! grep -q 'Cash on hand\|Cost of goods' /tmp/gl-picker-inc-$PORT.html"
check "accounts: types=17 (asset + expense) lists both, nothing else" "get '/pick/?source=account&types=17' > /tmp/gl-picker-ae-$PORT.html; grep -q 'Cash on hand' /tmp/gl-picker-ae-$PORT.html && grep -q 'Cost of goods' /tmp/gl-picker-ae-$PORT.html && ! grep -q 'Service revenue\|Owner equity' /tmp/gl-picker-ae-$PORT.html"
check "accounts: flags=1 lists bank accounts only (1010 here)" "[ \$(get '/pick/?source=account&flags=1' | grep -c 'id=\"record-picker-row-') = $(sql "SELECT count(*) FROM accounts WHERE is_bank AND archived_at IS NULL") ] && get '/pick/?source=account&flags=1' | grep -q '1010 — Business checking'"
check "accounts: types and flags together (asset, not a bank)" "get '/pick/?source=account&types=1&flags=2' > /tmp/gl-picker-nb-$PORT.html; grep -q '1200 — Accounts receivable' /tmp/gl-picker-nb-$PORT.html && ! grep -q '1010 — Business checking' /tmp/gl-picker-nb-$PORT.html && ! grep -q 'Cost of goods' /tmp/gl-picker-nb-$PORT.html"
check "accounts: expense accounts page past 25 (types=16: $((NEXP)) of them)" "get '/pick/?source=account&types=16' | grep -q '25 of $NEXP shown' && [ \$(get '/pick/?source=account&types=16&page=2' | grep -c 'id=\"record-picker-row-') = $((NEXP - 25)) ]"
check "a param that is not a number is ignored (every type)" "[ \$(code '/pick/?source=account&types=abc') = 200 ] && grep -q 'Cash on hand' $BODY"
check "an undeclared param changes nothing" "[ \$(get '/pick/?source=vendor&types=8&site_id=1&archived=1' | grep -c 'id=\"record-picker-row-') = 25 ]"
check "members: people and agents with a badge, the bookkeeper agent among them" "get '/pick/?source=member' > /tmp/gl-picker-mem-$PORT.html; grep -q 'data-label=\"SMOKE Owner\"' /tmp/gl-picker-mem-$PORT.html && grep -q 'data-label=\"SMOKE Bookkeeper\"' /tmp/gl-picker-mem-$PORT.html && grep -q '>Agent<' /tmp/gl-picker-mem-$PORT.html"
check "the pick endpoint logs nothing (a search is not an action)" "[ \$(sql \"SELECT count(*) FROM activity_log WHERE action LIKE 'pick%'\") = 0 ]"

echo "== 3. the forms"
PAGES="bills/new invoices/new payments/new receipts/new credit-notes/new vendor-credits/new journal/new settings/items/new settings/banks/new settings/general settings/posting-rules/new settings/recurring/new customers/new vendors/new bills invoices payments receipts journal"
for p in $PAGES; do
  c=$(code "/$p"); check "/$p renders (200)" "[ $c = 200 ]"
  check "/$p carries the modal once and the script once" "[ \$(grep -c 'id=\"record-picker\"' $BODY) = 1 ] && [ \$(grep -c 'record-picker.js' $BODY) = 1 ]"
done
for pair in "bills/new:bill-form-field-vendor:vendor" "invoices/new:invoice-form-field-customer:customer" "credit-notes/new:credit-note-form-field-customer:customer" "vendor-credits/new:vendor-credit-form-field-vendor:vendor" \
            "payments/new:payment-vendor-field:vendor" "receipts/new:receipt-customer-field:customer" "settings/recurring/new?kind=bill:recurring-form-field-vendor:vendor" "bills:bills-filter-vendor:vendor" \
            "invoices:invoices-filter-customer:customer" "payments:payments-filter-vendor:vendor" "receipts:receipts-filter-customer:customer" "journal:journal-filter-account:account" \
            "customers/new:customer-form-field-member:member" "vendors/new:vendor-form-field-member:member" "settings/general:settings-form-field-note:member" "settings/items/new:item-form-field-income:account" \
            "settings/banks/new:bank-form-field-account:account" "settings/posting-rules/new:posting-rule-form-field-debit:account" "vendors/new:vendor-form-field-expense:account" "customers/new:customer-form-field-income:account"; do
  IFS=: read -r pg id src <<<"$pair"; get "/$pg" > /tmp/gl-picker-form-$PORT.html
  check "/$pg: $id is a $src picker (hidden input under the old id, an opener, the source)" "grep -q 'id=\"$id-picker\" data-picker-source=\"$src\"' /tmp/gl-picker-form-$PORT.html && grep -q 'id=\"$id\" value=\"[0-9]*\" data-picker-value' /tmp/gl-picker-form-$PORT.html && grep -q 'id=\"$id-open\"' /tmp/gl-picker-form-$PORT.html"
done
get '/bills/new' > /tmp/gl-picker-bn-$PORT.html
check "the bill's vendor is required (no Clear), its lines' account and item are optional (Clear, placeholders)" "grep -q 'aria-required=\"true\"' /tmp/gl-picker-bn-$PORT.html && ! grep -q 'bill-form-field-vendor-clear' /tmp/gl-picker-bn-$PORT.html && grep -q 'record-picker-label text-muted\">Default<' /tmp/gl-picker-bn-$PORT.html && grep -q 'record-picker-label text-muted\">— none —<' /tmp/gl-picker-bn-$PORT.html"
check "the bill's lines are arrays under the select's names and ids, with form=\"bill-form\" on the hidden input" "grep -q 'name=\"line_account\[\]\" id=\"bill-form-line-0-account\" value=\"\" data-picker-value form=\"bill-form\"' /tmp/gl-picker-bn-$PORT.html && grep -q 'name=\"line_item\[\]\" id=\"bill-form-line-2-item\" value=\"\" data-picker-value form=\"bill-form\"' /tmp/gl-picker-bn-$PORT.html"
check "the line account's filter travels as declared params (expense + asset = 17)" "grep -q 'data-picker-params=\"{&quot;types&quot;:17}\"' /tmp/gl-picker-bn-$PORT.html"
get "/bills/new?vendor=$V1" > /tmp/gl-picker-bv-$PORT.html
check "?vendor=<id> pre-chooses: the label shows, the id is the value" "grep -q 'value=\"$V1\" data-picker-value' /tmp/gl-picker-bv-$PORT.html && grep -q 'record-picker-label\">Picker Vendor 01<' /tmp/gl-picker-bv-$PORT.html"
check "an id that names nothing is no choice: the placeholder and an empty value, never an error" "php -r 'require \"app/bootstrap.php\"; echo vendor_select(db(), \"vendor\", 99999999, \"\", \"Pick one\", \"x\");' | grep -q 'value=\"\" data-picker-value.*record-picker-label text-muted\">Pick one<'"
get '/journal/new' > /tmp/gl-picker-jn-$PORT.html
check "the journal form's account lines are pickers (any type) with form=\"entry-form\"" "grep -q 'name=\"line_account\[\]\" id=\"entry-form-line-0-account\" value=\"\" data-picker-value form=\"entry-form\"' /tmp/gl-picker-jn-$PORT.html && grep -q 'record-picker-label text-muted\">— account —<' /tmp/gl-picker-jn-$PORT.html"
get /settings/general > /tmp/gl-picker-gs-$PORT.html
check "the settings' default accounts must hold a value: required, no Clear; the morning-note member is optional, with Clear" "[ \$(grep -c 'aria-required=\"true\"' /tmp/gl-picker-gs-$PORT.html) -ge 4 ] && ! grep -q 'settings-form-field-ar-clear' /tmp/gl-picker-gs-$PORT.html && grep -q 'id=\"settings-form-field-note-clear\"' /tmp/gl-picker-gs-$PORT.html"
check "the item form's income account is typed (income = 8), its expense account (expense = 16)" "get /settings/items/new > /tmp/gl-picker-if-$PORT.html; grep -A1 'id=\"item-form-field-income-picker\"' /tmp/gl-picker-if-$PORT.html | grep -q 'types&quot;:8' ; grep 'id=\"item-form-field-expense-picker\"' /tmp/gl-picker-if-$PORT.html | grep -q 'types&quot;:16'"
check "the bank form's ledger account: every type as before (the list-form flag ['is_bank'] filtered nothing and still filters nothing)" "get /settings/banks/new | grep 'id=\"bank-form-field-account-picker\"' | grep -qv 'data-picker-params'"

echo "== 4. saves through the hidden inputs"
CSRF=$(get /bills/new | grep -o 'name="csrf-token" id="csrf-token-meta" content="[^"]*"' | sed 's/.*content="//;s/"//')
post() { curl -s -o /tmp/gl-picker-post-$PORT.out -w '%{http_code}' -b "$J" -c "$J" -H 'Accept: application/json' -H "X-CSRF-Token: $CSRF" "$@"; }
c=$(post -X POST --data-urlencode csrf_token="$CSRF" --data-urlencode vendor="$V1" --data-urlencode bill_date=2026-07-01 --data-urlencode memo="picker proof" \
      --data-urlencode "line_account[]=$A_EXP" --data-urlencode "line_item[]=$IT1" --data-urlencode "line_description[]=Picker line" --data-urlencode "line_quantity[]=2" --data-urlencode "line_unit_price[]=12.50" \
      --data-urlencode "line_account[]=" --data-urlencode "line_item[]=" --data-urlencode "line_description[]=" --data-urlencode "line_quantity[]=1" --data-urlencode "line_unit_price[]=" "$B/bills/save.php")
BILL=$(sql "SELECT id FROM bills WHERE memo='picker proof'")
check "a bill saves through the hidden inputs as it did through the selects ($c)" "[ $c = 200 ] && [ -n \"$BILL\" ] && [ \"\$(sql \"SELECT vendor_id || ' ' || (SELECT expense_account_id || ' ' || item_id FROM bill_lines WHERE bill_id=$BILL AND item_id IS NOT NULL) FROM bills WHERE id=$BILL\")\" = \"$V1 $A_EXP $IT1\" ]"
check "the bill's page shows the vendor's label in its edit form and the line editor keeps the account's" "get /bills/$BILL/edit | grep -q 'record-picker-label\">Picker Vendor 01<' && get /bills/$BILL | grep -q 'record-picker-label\">7101 — Picker Account 01<' && get /bills/$BILL | grep -q 'record-picker-label\">Picker Item 01<'"
c=$(post -X POST --data-urlencode csrf_token="$CSRF" --data-urlencode vendor="" --data-urlencode bill_date=2026-07-01 --data-urlencode memo="picker proof" "$B/bills/save.php")
check "a bill with no vendor is a 422 in the handler's own words, nothing saved ($c)" "[ $c = 422 ] && grep -q 'vendor is required' /tmp/gl-picker-post-$PORT.out && [ \$(sql \"SELECT count(*) FROM bills WHERE memo='picker proof'\") = 1 ]"
c=$(post -X POST --data-urlencode csrf_token="$CSRF" --data-urlencode customer="$C1" --data-urlencode issue_date=2026-07-01 --data-urlencode memo="picker proof" \
      --data-urlencode "line_item[]=$IT2" --data-urlencode "line_description[]=Picker invoice line" --data-urlencode "line_quantity[]=3" --data-urlencode "line_unit_price[]=20" "$B/invoices/save.php")
INV=$(sql "SELECT id FROM invoices WHERE memo='picker proof'")
check "an invoice saves through the hidden inputs ($c)" "[ $c = 200 ] && [ -n \"$INV\" ] && [ \"\$(sql \"SELECT customer_id || ' ' || (SELECT item_id FROM invoice_lines WHERE invoice_id=$INV) FROM invoices WHERE id=$INV\")\" = \"$C1 $IT2\" ]"
check "the invoice's line row shows the item and the income account as pickers bound to the line's own form" "get /invoices/$INV > /tmp/gl-picker-iv-$PORT.html; grep -q 'name=\"item\" id=\"invoice-line-[0-9]*-item\" value=\"$IT2\" data-picker-value form=\"invoice-line-form-' /tmp/gl-picker-iv-$PORT.html && grep -q 'record-picker-label\">Picker Item 02<' /tmp/gl-picker-iv-$PORT.html && grep -q 'name=\"income_account\"' /tmp/gl-picker-iv-$PORT.html"
LINE=$(sql "SELECT id FROM invoice_lines WHERE invoice_id=$INV")
c=$(post -X POST --data-urlencode csrf_token="$CSRF" --data-urlencode invoice="$INV" --data-urlencode line="$LINE" --data-urlencode item="$IT1" --data-urlencode description="Picker invoice line" --data-urlencode quantity=3 --data-urlencode unit_price=20 --data-urlencode income_account="$A_REV" "$B/invoices/line-save.php")
check "a line's item and account are changed through its row form's hidden inputs ($c)" "[ $c = 200 ] && [ \"\$(sql \"SELECT item_id || ' ' || income_account_id FROM invoice_lines WHERE id=$LINE\")\" = \"$IT1 $A_REV\" ]"
check "no PHP warning, notice or deprecation in the server log" "! grep -qiE 'PHP (Warning|Notice|Fatal|Deprecated)' $LOG"
echo "== 5. a draft journal entry, a bank line"
c=$(post -X POST --data-urlencode csrf_token="$CSRF" --data-urlencode start=2026-01-01 "$B/periods/save.php"); sql "SELECT count(*) FROM fiscal_years" >/dev/null
c=$(post -X POST --data-urlencode csrf_token="$CSRF" --data-urlencode entry_date=2026-07-02 --data-urlencode memo="picker proof" --data-urlencode "lines=[{\"account\":\"1200\",\"debit\":\"100.00\",\"customer\":\"$C1\"},{\"account\":\"4100\",\"credit\":\"100.00\"}]" "$B/journal/save.php")
JE=$(sql "SELECT id FROM journal_entries WHERE memo='picker proof'")
check "a journal draft saves ($c) and its page shows each line's account as a picker, the receivable line's customer as a picker bound to the line's form" "[ -n \"$JE\" ] && get /journal/$JE > /tmp/gl-picker-je-$PORT.html && grep -q 'record-picker-label\">1200 — Accounts receivable<' /tmp/gl-picker-je-$PORT.html && grep -q 'name=\"customer\" id=\"entry-line-[0-9]*-customer\" value=\"$C1\" data-picker-value form=\"line-form-' /tmp/gl-picker-je-$PORT.html && grep -q 'record-picker-label\">Picker Customer 01<' /tmp/gl-picker-je-$PORT.html"
check "the add row has account, customer and vendor pickers with their own placeholders" "grep -q 'record-picker-label text-muted\">— account —<' /tmp/gl-picker-je-$PORT.html && grep -q 'record-picker-label text-muted\">customer…<' /tmp/gl-picker-je-$PORT.html && grep -q 'record-picker-label text-muted\">vendor…<' /tmp/gl-picker-je-$PORT.html"
JL=$(sql "SELECT id FROM journal_lines WHERE entry_id=${JE:-0} AND debit > 0")
c=$(post -X POST --data-urlencode csrf_token="$CSRF" --data-urlencode entry="$JE" --data-urlencode line="$JL" --data-urlencode account="$A_BANK" --data-urlencode debit=100 "$B/journal/line-save.php")
check "a line's account changes through its row form's hidden input ($c)" "[ $c = 200 ] && [ \"\$(sql \"SELECT account_id FROM journal_lines WHERE id=$JL\")\" = \"$A_BANK\" ]"
BK=$(sql "SELECT id FROM bank_accounts WHERE account_id=$A_BANK AND archived_at IS NULL LIMIT 1")
[ -n "$BK" ] || BK=$(sql "INSERT INTO bank_accounts (name, kind, account_id, currency, opening_balance, opening_date, created_by) VALUES ('Picker Checking', 'checking', $A_BANK, 'USD', 0, '2026-01-01', 1) RETURNING id" | head -1)
c=$(post -X POST --data-urlencode csrf_token="$CSRF" --data-urlencode bank_account="$BK" --data-urlencode txn_date=2026-07-03 --data-urlencode amount=-15.00 --data-urlencode description="Picker fee $$" "$B/cash/line.php")
BL=$(sql "SELECT id FROM bank_transactions WHERE description='Picker fee $$'")
get "/cash/match?bank_account=$BK" > /tmp/gl-picker-bm-$PORT.html
check "the bank line's 'make an entry' account is a picker typed by the sign (a fee: expense = 16), required" "[ -n \"$BL\" ] && grep 'id=\"bank-line-$BL-entry-account-picker\"' /tmp/gl-picker-bm-$PORT.html | grep -q 'types&quot;:16' && grep -q 'id=\"bank-line-$BL-entry-account-open\"[^>]*aria-required=\"true\"' /tmp/gl-picker-bm-$PORT.html"
c=$(post -X POST --data-urlencode csrf_token="$CSRF" --data-urlencode bank_line="$BL" --data-urlencode account="$A_FEE" --data-urlencode memo="picker fee $$" "$B/cash/entry.php")
check "the entry posts through the hidden input ($c): the line is matched and the fee booked to the chosen account" "[ $c = 200 ] && [ \"\$(sql \"SELECT count(*) FROM journal_lines l JOIN journal_entries e ON e.id=l.entry_id WHERE e.memo='picker fee $$' AND l.account_id=$A_FEE AND l.debit=15\")\" = 1 ]"
sql "DELETE FROM bill_lines WHERE bill_id IN (SELECT id FROM bills WHERE memo = 'picker proof'); DELETE FROM bills WHERE memo = 'picker proof'; DELETE FROM invoice_lines WHERE invoice_id IN (SELECT id FROM invoices WHERE memo = 'picker proof'); DELETE FROM invoices WHERE memo = 'picker proof'" >/dev/null 2>&1
if [ -n "${BROWSER:-1}" ] && [ "${BROWSER:-1}" != 0 ] && [ -d /var/www/web/node_modules/playwright ]; then
  echo "== 6. the browser (headless Chromium: keyboard, the typed account filter, a bill saved through the pickers, the list filter, 375 px, dark)"
  out=$(S=${SHOTS:-/tmp/gl-picker-shots} BASE=$B node tests/picker/browser.mjs 2>&1); echo "$out" | sed 's/^/  /'
  bp=$(echo "$out" | grep -c '^ok '); bf=$(echo "$out" | grep -c '^FAIL ')
  pass=$((pass+bp)); fail=$((fail+bf)); [ "$bf" = 0 ] && ok "the browser pass ($bp checks)" || bad "the browser pass"
fi
sql "DELETE FROM bill_lines WHERE bill_id IN (SELECT id FROM bills WHERE memo LIKE 'picker browser %'); DELETE FROM bills WHERE memo LIKE 'picker browser %'" >/dev/null 2>&1
echo "== $pass passed, $fail failed"
[ $fail -eq 0 ]
