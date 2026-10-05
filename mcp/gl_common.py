"""Shared pieces for General Ledger's tool modules (docs/gl-mcp-tool-surface.md): annotations, the input base, the where-builder, periods and dates, the resolver shape.
Money arrives from the views as a numeric string with its own scale (db.py); a date is YYYY-MM-DD; a period is from/to or a name (2026-09, FY2026, this_month, last_month, this_year, last_year)."""
from __future__ import annotations

import datetime
import re

from pydantic import BaseModel, ConfigDict

import db

RO = {"readOnlyHint": True, "openWorldHint": False}


class _Base(BaseModel):
    model_config = ConfigDict(str_strip_whitespace=True, extra="forbid", populate_by_name=True)


def dumps(obj) -> str:
    return db.to_json(obj)


def err(message: str, **extra) -> str:
    return dumps({"error": message, **extra})


def lim(n: int | None, default: int = 25, top: int = 100) -> int:
    return max(1, min(int(n or default), top))


class Where:
    """Builds `a = $1 AND b = ANY($2)` with positional parameters in order."""

    def __init__(self) -> None:
        self.parts: list[str] = []
        self.args: list = []

    def p(self, value) -> str:
        self.args.append(value)
        return f"${len(self.args)}"

    def add(self, sql: str, *values) -> "Where":
        """`sql` uses {} for each value in order, e.g. add('b.vendor_id = {}', 4)."""
        self.parts.append(sql.format(*[self.p(v) for v in values]))
        return self

    def raw(self, sql: str) -> "Where":
        self.parts.append(sql)
        return self

    def sql(self) -> str:
        return " AND ".join(self.parts) if self.parts else "TRUE"


def words(q: str | None) -> list[str]:
    return [w for w in re.split(r"\s+", (q or "").strip()) if w][:6]


def word_start(w: str) -> str:
    """A regex that matches a word beginning with w (names match at the start of a word)."""
    return "\\m" + re.escape(w[:40])


def q_alone(p) -> bool:
    """True when only `q` (and perhaps `limit`) was given — the kernel's entity resolver's call, answered as a plain list of {id, label} rows."""
    return bool(getattr(p, "q", None)) and set(p.model_fields_set) <= {"q", "limit"}


def to_date(s: str | datetime.date | None, name: str = "date") -> datetime.date | None:
    if s is None or s == "":
        return None
    if isinstance(s, datetime.date):
        return s
    try:
        return datetime.date.fromisoformat(str(s)[:10])
    except ValueError:
        raise ValueError(f"{name} is written YYYY-MM-DD.")


def as_of(s: str | None) -> datetime.date:
    return to_date(s, "as_of") or datetime.date.today()


def like_escape(s: str) -> str:
    return s.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_")


def month_end(d: datetime.date) -> datetime.date:
    return (d.replace(day=1) + datetime.timedelta(days=32)).replace(day=1) - datetime.timedelta(days=1)


async def period_range(fetch, period: str | None, date_from: str | None, date_to: str | None) -> dict:
    """{from, to, label} as dates. Explicit dates win; then a name; else the fiscal period that covers today, else this calendar month."""
    f, t = to_date(date_from, "from"), to_date(date_to, "to")
    today = datetime.date.today()
    if f or t:
        t = t or today
        f = f or t.replace(day=1)
    else:
        p = (period or "").strip()
        pl = p.lower()
        if pl in ("this_month", "month"):
            f, t, label = today.replace(day=1), month_end(today), today.strftime("%Y-%m")
        elif pl == "last_month":
            t = today.replace(day=1) - datetime.timedelta(days=1)
            f = t.replace(day=1)
        elif pl in ("this_year", "year"):
            f, t = datetime.date(today.year, 1, 1), datetime.date(today.year, 12, 31)
        elif pl == "last_year":
            f, t = datetime.date(today.year - 1, 1, 1), datetime.date(today.year - 1, 12, 31)
        elif pl in ("this_quarter", "quarter"):
            q = (today.month - 1) // 3
            f = datetime.date(today.year, q * 3 + 1, 1)
            t = month_end(datetime.date(today.year, q * 3 + 3, 1))
        elif p:
            rows = await fetch("SELECT start_date, end_date, name FROM mcp_fiscal_periods WHERE lower(name) = $1", pl)
            if not rows:
                rows = await fetch("SELECT start_date, end_date, name FROM mcp_fiscal_years WHERE lower(name) = $1", pl)
            if rows:
                f, t = rows[0]["start_date"], rows[0]["end_date"]
            elif re.fullmatch(r"\d{4}-\d{2}", p):
                f = datetime.date.fromisoformat(p + "-01"); t = month_end(f)
            elif re.fullmatch(r"\d{4}", p):
                f, t = datetime.date(int(p), 1, 1), datetime.date(int(p), 12, 31)
            else:
                raise ValueError(f"No fiscal period, year or named range is called '{p}'.")
        else:
            rows = await fetch("SELECT start_date, end_date FROM mcp_fiscal_periods WHERE CURRENT_DATE BETWEEN start_date AND end_date")
            f, t = (rows[0]["start_date"], rows[0]["end_date"]) if rows else (today.replace(day=1), month_end(today))
    if t < f:
        raise ValueError("The period ends before it starts.")
    if (t - f).days > 800:
        raise ValueError("A period is at most two years.")
    return {"from": f, "to": t, "label": (period or f"{f} to {t}")}


def prior_range(r: dict, mode: str | None) -> dict | None:
    """The comparison range: previous (the same length, immediately before) or last_year (the same dates a year earlier)."""
    if mode == "last_year":
        def yr(d):
            try:
                return d.replace(year=d.year - 1)
            except ValueError:
                return d.replace(year=d.year - 1, day=28)
        return {"from": yr(r["from"]), "to": yr(r["to"])}
    if mode == "previous":
        days = (r["to"] - r["from"]).days + 1
        if r["from"].day == 1 and r["to"] == month_end(r["from"]):
            t = r["from"] - datetime.timedelta(days=1)
            return {"from": t.replace(day=1), "to": t}
        return {"from": r["from"] - datetime.timedelta(days=days), "to": r["from"] - datetime.timedelta(days=1)}
    return None


async def has_right(fetch, right: str) -> bool:
    rows = await fetch("SELECT gl_has_right($1) AS ok", right)
    return bool(rows and rows[0]["ok"])


def doc_label(prefix_col: str, party_col: str, total_col: str = "total", cur_col: str = "currency", id_col: str = "") -> str:
    """The resolver's label for a document: 'INV-00042 — Acme — 1608.24 USD' (a draft: 'Draft #12')."""
    return f"COALESCE({prefix_col}, 'Draft #' || {id_col}) || ' — ' || {party_col} || ' — ' || {total_col} || ' ' || {cur_col}"
