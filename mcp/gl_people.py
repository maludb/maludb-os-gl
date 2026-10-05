"""General Ledger — people, dimensions and the settings (tool surface, "People and dimensions"). Reads mcp_members, mcp_departments, mcp_sites, mcp_settings, mcp_accounts.
Every caller sees names; an address only for oneself (the view)."""
from __future__ import annotations

from pydantic import Field

from gl_common import RO, Where, _Base, dumps, lim, q_alone, word_start, words


class FindMembersIn(_Base):
    q: str | None = Field(None, description="Part of a name, matched at the start of a word. `q` alone answers a plain list (member_id, display_name)")
    kind: str | None = Field(None, description="human or agent")
    limit: int = Field(25, ge=1, le=100)


class FindDimIn(_Base):
    q: str | None = Field(None, description="Name (word start). `q` alone answers a plain list")
    include_archived: bool = Field(False)
    limit: int = Field(50, ge=1, le=100)


def register(mcp, fetch) -> None:
    @mcp.tool(name="find_members", annotations={"title": "Find people and agents", **RO})
    async def find_members(params: FindMembersIn) -> str:
        """Look up a person or an agent by name — who approved something, who receives the morning note, an employee who is a vendor. `q` alone answers a
        plain list (member_id, display_name). Names, kind, roles and job title; an address only for yourself."""
        w = Where().raw("m.status = 'active'").raw("m.capability IS NOT NULL")
        for word in words(params.q):
            w.add("m.display_name ~* {}", word_start(word))
        if params.kind in ("human", "agent"):
            w.add("m.member_kind = {}", params.kind)
        rows = await fetch(f"SELECT m.member_id, m.display_name, m.member_kind, m.job_title, m.roles, m.timezone, m.email FROM mcp_members m WHERE {w.sql()} ORDER BY lower(m.display_name) LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"member_id": r["member_id"], "display_name": r["display_name"]} for r in rows])
        return dumps({"members": rows})

    @mcp.tool(name="find_departments", annotations={"title": "Find departments", **RO})
    async def find_departments(params: FindDimIn) -> str:
        """The departments (the ledger's first dimension) by name, live ones first. Call before putting a department on a line. `q` alone answers a plain list (department_id, name)."""
        w = Where()
        if not params.include_archived:
            w.raw("d.archived_at IS NULL")
        for word in words(params.q):
            w.add("d.name ~* {}", word_start(word))
        rows = await fetch(f"SELECT d.department_id, d.name, d.description, d.parent_id, d.manager_member_id, d.is_system, d.archived_at FROM mcp_departments d WHERE {w.sql()} ORDER BY (d.archived_at IS NOT NULL), lower(d.name) LIMIT {lim(params.limit, 50)}", *w.args)
        if q_alone(params):
            return dumps([{"department_id": r["department_id"], "name": r["name"]} for r in rows])
        return dumps({"departments": rows})

    @mcp.tool(name="find_sites", annotations={"title": "Find sites", **RO})
    async def find_sites(params: FindDimIn) -> str:
        """The sites (the ledger's second dimension) by name, with the kernel location when one is pinned. `q` alone answers a plain list (site_id, name)."""
        w = Where()
        if not params.include_archived:
            w.raw("s.archived_at IS NULL")
        for word in words(params.q):
            w.add("s.name ~* {}", word_start(word))
        rows = await fetch(f"SELECT s.site_id, s.name, s.kernel_location_id, s.address, s.archived_at FROM mcp_sites s WHERE {w.sql()} ORDER BY (s.archived_at IS NOT NULL), lower(s.name) LIMIT {lim(params.limit, 50)}", *w.args)
        if q_alone(params):
            return dumps([{"site_id": r["site_id"], "name": r["name"]} for r in rows])
        return dumps({"sites": rows})

    @mcp.tool(name="gl_settings", annotations={"title": "The ledger's settings", **RO})
    async def gl_settings() -> str:
        """The ledger's public facts: business name and address, base currency, fiscal year start month, timezone, the control and default accounts (by code and name),
        invoice and bill terms, the secure link's life, reminder days, whether the AI statement poll is on, and who receives the morning note."""
        s = await fetch("SELECT * FROM mcp_settings")
        if not s:
            return dumps({"error": "The settings are not readable by this caller."})
        s = s[0]
        accts = {r["account_id"]: r for r in await fetch("SELECT account_id, code, name FROM mcp_accounts")}
        out = {k: v for k, v in s.items() if not k.endswith("_account_id")}
        for k, v in s.items():
            if k.endswith("_account_id"):
                a = accts.get(v)
                out[k[:-3]] = {"account_id": v, "code": a["code"], "name": a["name"]} if a else None
        if s.get("morning_note_member_id"):
            m = await fetch("SELECT display_name FROM mcp_members WHERE member_id = $1", s["morning_note_member_id"])
            out["morning_note_member_name"] = m[0]["display_name"] if m else None
        return dumps(out)
