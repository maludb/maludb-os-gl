"""Proof — the kernel's Actions MCP, with the KERNEL'S OWN application_actions.py (read from /var/www/mcp, never modified), fed General Ledger's registry exactly as the installer writes it
(deploy/kernel-registry-gl.json's `resolve` + mcp/action_registry.json) and a STUBBED approval hook and application. No live kernel, no live application: what is proved is the
contract — the 124 actions become 124 tools; the 69 with an approval category ask the hook FIRST with their own log event and the handler URL, and never reach the handler when the hook
pauses, refuses, or records; the other 55 never ask the hook; names become ids through OUR find_* tools. Run by tests/phase4/hook.php with the fixture ids in the environment."""
import asyncio, json, os, pathlib, sys, tempfile

sys.path.insert(0, "/var/www/mcp")
import application_actions as aa                     # the kernel's, unmodified
from mcp.server.fastmcp import FastMCP

APP = pathlib.Path("/srv/apps/gl")
passes = fails = 0


def ok(cond, label):
    global passes, fails
    if cond:
        passes += 1
    else:
        fails += 1
    print(("  ok   " if cond else "  FAIL ") + label)


reg = json.loads((APP / "mcp/action_registry.json").read_text())
wrap = json.loads((APP / "deploy/kernel-registry-gl.json").read_text())
manifest = json.loads((APP / "maludb-os.json").read_text())
tmp = pathlib.Path(tempfile.mkdtemp())
(tmp / "gl.json").write_text(json.dumps({
    "schema": "maludb-os.registry/1", "app_key": "gl", "name": "General Ledger", "base_url": "http://app.stub.invalid",
    "records_url": f"http://127.0.0.1:{os.environ.get('P4_REC_PORT', '8295')}/mcp", "resolve": wrap["resolve"], "registry": reg}))
aa.REGISTRIES_DIR = tmp
TOKEN = os.environ.get("P4_TOKEN", "")
E = os.environ
state = {"hook": "pending", "calls": []}


async def app_post(path, fields, base=None):
    state["calls"].append({"path": path, "fields": dict(fields), "base": base})
    if path == "/approvals/hook.php":
        h = state["hook"]
        if h == "pending":
            return {"status": "pending_approval", "approval_request_id": 17, "did": "waits"}
        if h == "success":
            return {"status": "success"}
        if h == "recorded":
            return {"status": "recorded"}
        return {"status": "error", "message": "hook down"}
    return {"status": "success", "did": "done", "record_id": 5}


async def call(mcp, name, args):
    state["calls"] = []
    res = await mcp.call_tool(name, {"params": args})
    content = res[0] if isinstance(res, tuple) else res
    text = content[0].text if content else "{}"
    try:
        return json.loads(text)
    except ValueError:
        return {"raw": text}


RESOLVED = {p for v in wrap["resolve"].values() for p in v["params"]}


def sample(action):
    """Digits for every resolved entity and every repeated param, plain words for the rest — nothing to look up."""
    a = {}
    for p in action["params"]:
        if p.get("name") and p.get("required"):
            a[p["name"]] = "1" if p["name"] in RESOLVED or p.get("repeated") else "2026-10-09" if "date" in p["name"] or p["name"].endswith("_at") else "x"
    if action.get("confirm"):
        a["confirmed"] = True
    return a


async def main():
    mcp = FastMCP("t")
    n = aa.register(mcp, app_post, lambda: TOKEN, set())
    actions = reg["actions"]
    built = [a for a in actions.values() if a.get("built")]
    tools = {t.name for t in await mcp.list_tools()}
    ok(len(actions) == 124 and len(built) == 124 and n == 124 and tools == set(actions), f"the kernel's register() turns the registry's {len(actions)} actions into {n} tools, every name the registry's")
    pausing = {k: a for k, a in actions.items() if a.get("approval")}
    ok(len(pausing) == 69, f"{len(pausing)} actions carry an approval category")
    cats = {a["action"]: a["category"] for a in manifest["approvals"]}
    ok(set(cats) == set(pausing) and all(cats[k] == a["approval"] for k, a in pausing.items()), "each pausing action's category equals maludb-os.json approvals[]")
    ext = sorted(k for k, a in pausing.items() if a["approval"] == "external_send")
    ok(len(ext) == 3 and "invoice_send" in ext and "invoice_remind" in ext, "external_send: " + ", ".join(ext))
    ok(all(pausing[k]["log_event"] for k in pausing), "each pausing action names its log event")
    free = ["customer_save", "vendor_save", "invoice_draft", "invoice_line_save", "bill_draft", "journal_entry_draft", "journal_line_save", "note_add", "ai_statement_pull", "ai_statement_import", "recurring_run", "morning_note_send", "bank_transaction_match"]
    ok(all(k in actions and not actions[k].get("approval") for k in free), "an agent drafts, imports, matches and notes without a pause")

    for name, a in sorted(pausing.items()):
        args = sample(a)
        state["hook"] = "pending"
        r = await call(mcp, name, args)
        c = state["calls"]
        ok(r.get("status") == "pending_approval" and [x["path"] for x in c] == ["/approvals/hook.php"], f"{name}: the hook pauses it -> pending_approval; the handler is NEVER posted")
        h = c[0]["fields"] if c else {}
        ok(h.get("action_key") == name and h.get("log_event") == a["log_event"] and h.get("handler_url") == "http://app.stub.invalid" + a["endpoint"], f"{name}: the hook is asked with its key, its log event ({a['log_event']}) and the handler URL")
        state["hook"] = "success"
        r = await call(mcp, name, args)
        ok([x["path"] for x in state["calls"]] == ["/approvals/hook.php", a["endpoint"]] and r.get("status") == "success", f"{name}: hook says go -> the handler is posted after it")
    for mode, label in (("recorded", "an evaluation (hook: recorded)"), ("error", "the hook failing")):
        state["hook"] = mode
        bad = 0
        for name, a in pausing.items():
            await call(mcp, name, sample(a))
            if any(x["path"] == a["endpoint"] for x in state["calls"]):
                bad += 1
        ok(bad == 0, f"{label}: none of the {len(pausing)} reaches its handler")
    state["hook"] = "pending"
    others = {k: a for k, a in actions.items() if not a.get("approval")}
    hooked, unposted = [], []
    for name, a in others.items():
        await call(mcp, name, sample(a))
        paths = [x["path"] for x in state["calls"]]
        if "/approvals/hook.php" in paths:
            hooked.append(name)
        if paths != [a["endpoint"] if a["endpoint"].startswith("/") else "/" + a["endpoint"]]:
            unposted.append((name, paths))
    ok(len(others) == 55 and hooked == [] and unposted == [], f"the other {len(others)} never ask the hook and post their handler straight away" + (f" — NOT: {hooked} {unposted[:3]}" if hooked or unposted else ""))
    conf = [k for k, a in actions.items() if a.get("confirm")]
    r = await call(mcp, conf[0], {k: v for k, v in sample(actions[conf[0]]).items() if k != "confirmed"})
    ok(r.get("status") == "needs_confirmation", f"a destructive action ({conf[0]}) asks for confirmation before anything is posted ({len(conf)} of them do)")

    # names become ids through OUR tools (the caller's own token, a real records server)
    if TOKEN:
        state["hook"] = "success"

        async def fields_of(name, args):
            r = await call(mcp, name, args)
            return r, (state["calls"][-1]["fields"] if state["calls"] else {})

        r, f = await fields_of("invoice_remind", {"invoice": E["P4_INVOICE_NUMBER"], "confirmed": True})
        ok(f.get("invoice") == E["P4_INVOICE"] and [c["path"] for c in state["calls"]][0] == "/approvals/hook.php", f"invoice_remind 'invoice {E['P4_INVOICE_NUMBER']}' -> its id ({f.get('invoice')}) — and it asked the hook first")
        ok(r.get("resolved", {}).get("invoice", "").startswith(E["P4_INVOICE_NUMBER"]), "and the answer says which invoice it resolved: " + json.dumps(r.get("resolved")))
        r, f = await fields_of("bill_approve", {"bill": "H-2026-10", "confirmed": True})
        ok(f.get("bill") == E["P4_BILL"], f"bill_approve: the vendor's reference 'H-2026-10' becomes the bill's id ({f.get('bill')}) through find_bills")
        r, f = await fields_of("journal_entry_post", {"entry": E["P4_ENTRY_NUMBER"], "confirmed": True})
        ok(f.get("entry") == E["P4_ENTRY"], f"journal_entry_post: entry '{E['P4_ENTRY_NUMBER']}' -> {f.get('entry')} through find_journal_entries")
        r, f = await fields_of("invoice_draft", {"customer": "Acme"})
        ok(f.get("customer") == E["P4_ACME"] and not any(c["path"] == "/approvals/hook.php" for c in state["calls"]), f"invoice_draft: customer 'Acme' -> {f.get('customer')} through find_customers; a draft never pauses")
        r, f = await fields_of("receipt_record", {"customer": "Beta", "amount": "5.00", "method": "cash", "invoice": E["P4_INVOICE_NUMBER"], "confirmed": True})
        ok(f.get("customer") == E["P4_BETA"] and f.get("invoice") == E["P4_INVOICE"], f"receipt_record: customer 'Beta' and invoice '{E['P4_INVOICE_NUMBER']}' by name -> ids ({f.get('customer')}, {f.get('invoice')})")
        r, f = await fields_of("transfer_record", {"from_bank_account": "Checking", "to_bank_account": "Petty", "amount": "1.00", "confirmed": True})
        ok(f.get("from_bank_account") == E["P4_BANK"] and f.get("to_bank_account") == E["P4_CASH"], f"transfer_record: both bank accounts by name -> ids ({f.get('from_bank_account')}, {f.get('to_bank_account')}) through find_bank_accounts")
        r, f = await fields_of("bank_transaction_match", {"bank_line": "SMOKE-REF-75", "item_type": "receipt", "item": "1"})
        ok(f.get("bank_line") == E["P4_LINE"] and f.get("item") == "1", f"bank_transaction_match: the line by its bank reference -> {f.get('bank_line')} through unmatched_bank_lines; item (a ledger item's id from match_candidates) passes through as digits")
        r, f = await fields_of("period_close", {"period": "2026-02", "confirmed": True})
        ok(f.get("period") == E["P4_PERIOD"], f"period_close: period '2026-02' -> {f.get('period')} through find_periods")
        r, f = await fields_of("ai_statement_import", {"statement": "2026-09", "confirmed": True})
        ok(f.get("statement") == E["P4_AI"], f"ai_statement_import: statement '2026-09' -> {f.get('statement')} through ai_statement_status")
        r, f = await fields_of("recurring_run", {"template": "rent"})
        ok(f.get("template") == E["P4_TEMPLATE"], f"recurring_run: template 'rent' -> {f.get('template')} through find_recurring")
        r, f = await fields_of("morning_note_send", {"body": "Hello", "to_member": "Carl"})
        ok(f.get("to_member") == E["P4_MEMBER"], f"morning_note_send: to_member 'Carl' -> {f.get('to_member')} through find_members")
        r, f = await fields_of("account_save", {"account": "6200", "code": "6200", "name": "Software", "account_type": "expense"})
        ok(f.get("account") == "6200", f"account_save: a 4-digit account CODE passes straight through as digits ({f.get('account')}) — the handler reads a 3-8 digit string as a code first; names ('Software') go through find_accounts")
        r, f = await fields_of("account_save", {"account": "Software and subscriptions", "code": "6200", "name": "Software", "account_type": "expense"})
        ok(f.get("account") == E["P4_ACCOUNT"], f"account_save: the account's name 'Software and subscriptions' -> {f.get('account')} through find_accounts")
        r, f = await fields_of("journal_entries_post_batch", {"entries": "1,2,3", "confirmed": True})
        ok(f.get("entries[]") == ["1", "2", "3"], "a repeated param (entries '1,2,3') is a list of ids the handler reads — not looked up as a name" + ("" if f.get("entries[]") == ["1", "2", "3"] else f" — got {json.dumps(f)[:160]} / {json.dumps(r)[:160]}"))
        r, f = await fields_of("invoice_send", {"invoice": "SMOKE", "confirmed": True})
        ok(r.get("status") == "error" and "Several match" in r.get("message", ""), "'SMOKE' (a customer's name) matches many invoices: the kernel asks which one — " + r.get("message", "")[:70])
        ok(not any(c["path"] == "/invoices/send.php" for c in state["calls"]), "…and nothing was posted")
        r, f = await fields_of("invoice_draft", {"customer": "ZZZ nothing here"})
        ok(r.get("status") == "error" and "No customer matching" in r.get("message", "") and state["calls"] == [], "a name nobody has: 'No customer matching' — nothing posted")
        r, f = await fields_of("note_add", {"record_type": "invoice", "record_id": E["P4_INVOICE"], "body": "SMOKE"})
        ok(f.get("record_id") == E["P4_INVOICE"] and r.get("status") == "success", "a digit id passes straight through (no lookup)")
    print(f"{'all ' + str(passes) + ' passed' if not fails else str(fails) + ' FAILED (' + str(passes) + ' passed)'}")
    sys.exit(1 if fails else 0)

asyncio.run(main())
