"""8-Pillars section renderer.

Builds the section HTML from the pivot views and subcategory JSON.
Writes to uls_wptm_tbl_4 and sends template 26 only when the job write
flag is on and WRITE_COLUMNS is filled in.
"""
from __future__ import annotations

import json
import logging
from pathlib import Path

from emailer import send
from jobctx import writing
from jobs.rsi_score import _first_name
from jobs.tag_snapshot import PREFIX, _connect

logger = logging.getLogger(__name__)
DATA = Path(__file__).resolve().parent.parent / "data" / "pillar_subcategories.json"
RESPONSES = Path(__file__).resolve().parent.parent / "data" / "pillar_responses.json"
PILLARS_RESULTS_TEMPLATE = 26
USERS_TABLE = f"{PREFIX}wptm_tbl_4"
EMAIL_COL = "col2"  # -Users- column B

# key, label, score col, response col, section-table col. Order matches Excel 11-18.
PILLAR_FIELDS = [
    ("occupational", "Occupational", "col11", "col20", "col33"),
    ("social", "Social", "col12", "col21", "col34"),
    ("spiritual", "Spiritual", "col13", "col22", "col35"),
    ("mental", "Mental", "col14", "col23", "col36"),
    ("financial", "Financial", "col15", "col24", "col37"),
    ("environmental", "Environmental", "col16", "col25", "col38"),
    ("physical", "Physical", "col17", "col26", "col39"),
    ("emotional", "Emotional", "col18", "col27", "col40"),
]

# subsection narrative field, matched to the JSON section name
SUBSECTION_COLS = {
    "work-life balance": "col45",
    "job satisfaction": "col46",
    "confidence in work abilities": "col47",
    "workplace relationships & support": "col48",
    "career growth & work environment": "col49",
    "social satisfaction & comfort": "col50",
    "social anxiety": "col51",
    "emotional support": "col52",
    "communication": "col53",
    "taking part in activities": "col54",
    "spirituality": "col55",
    "mindful attention": "col56",
    "everyday practice frequency": "col57",
    "cognitive functioning": "col58",
    "curiosity": "col59",
    "growth mindset": "col60",
    "financial security and stability": "col61",
    "financial self-efficacy": "col62",
    "financial management habits": "col63",
    "financial reflection": "col64",
    "connection to nature": "col65",
    "environmental actions & behaviors": "col66",
    "environmental identity": "col67",
    "limiting radiation & device exposure": "col68",
    "overall physical health": "col69",
    "physical activity & daily movement": "col70",
    "daily habits & health behaviors": "col71",
    "health maintenance & medical beliefs": "col72",
    "emotion regulation": "col73",
    "emotional self-efficacy": "col74",
    "emotional self-awareness": "col75",
    "self-compassion": "col76",
    "resilience": "col77",
    "self-care": "col78",
    "boundaries & conflict resolution": "col79",
    "meaning and purpose": "col80",
    "self-efficacy": "col81",
    "financial control and self-efficacy": "col82",
}

# pillar -> list of (pivot column, section name) in form order
PILLARS = {
    "physical": [
        ("s296", "Overall Physical Health"),
        ("s297", "Physical Activity & Daily Movement"),
        ("s298", "Daily Habits & Health Behaviors"),
        ("s299", "Health Maintenance & Medical Beliefs"),
    ],
    "social": [
        ("s279", "Social Satisfaction & Comfort"),  # Social Anxiety rolled into this
        ("s280", "Emotional Support"),
        ("s281", "Communication"),
        ("s282", "Taking Part in Activities"),
        ("s283", "Boundaries & Conflict Resolution"),
    ],
    "emotional": [
        ("s289", "Emotion Regulation"),
        ("s290", "Emotional Self-Efficacy"),
        ("s291", "Emotional Self-Awareness"),
        ("s292", "Self-Compassion"),
        ("s293", "Resilience"),
        ("s294", "Self-Care"),
    ],
    "environmental": [
        ("s284", "Connection to Nature"),
        ("s285", "Environmental Actions & Behaviors"),
        ("s286", "Environmental Identity"),
        ("s287", "Limiting Radiation & Device Exposure"),
        # s288 is a one-question section missing from the subcategory file
    ],
    "occupational": [
        ("s269", "Work–Life Balance"),
        ("s270", "Job Satisfaction"),
        ("s271", "Confidence in Work Abilities"),
        ("s272", "Workplace Relationships & Support"),
        ("s273", "Career Growth & Work Environment"),
    ],
    "financial": [
        ("s274", "Financial Security and Stability"),
        ("s275", "Financial Self-Efficacy"),
        ("s276", "Financial Control and Self-Efficacy"),
        ("s277", "Financial Management Habits"),
        ("s278", "Financial Reflection"),
    ],
    "mental": [
        ("s260", "Cognitive Functioning"),
        ("s261", "Self-Efficacy"),
        ("s262", "Curiosity"),
        ("s263", "Growth Mindset"),
    ],
    "spiritual": [
        ("s265", "Meaning and Purpose"),
        ("s266", "Spirituality"),
        ("s267", "Mindful Attention"),
        ("s268", "Everyday Practice Frequency"),
    ],
}

# form label -> subcategory narrative name, when they differ
NARRATIVE_ALIAS = {}

# Full HTML only for pillars still being checked. Empty = one-line summaries.
VERBOSE = set()


def _score_html(score: float) -> str:
    if score >= 80:
        color, label = "#0070c0", "HIGH"
    elif score >= 40:
        color, label = "#3b7e23", "MODERATE"
    else:
        color, label = "red", "LOW"
    return (
        f"<td style='background-color: {color}; padding-right:10px;padding-left:10px;'>"
        f"<center><span style='color:white;font-size:0.875em'>{label}</span></center></td>"
    )


def _band(score: float) -> str:
    if score >= 80:
        return "80"
    if score >= 40:
        return "40"
    return "0"


def _section_table(row: dict, named: list[tuple[str, dict]]) -> str:
    parts = [
        "<table ><style>td {padding: 0 10px;}</style>",
        "<th><center>Section</center></th><th><center>Score</center></th>",
        "<th><center>Result</center></th><th><center>Info</center></th>",
    ]
    for col, name, sec in named:
        raw = row.get(col)
        if raw in (None, ""):
            continue
        score = float(raw)
        if score <= 1:
            score = round(score * 100)
        label = sec["section"] if sec else name
        info = sec.get("bands", {}).get(_band(score), "") if sec else ""
        parts.append(
            f"<tr><td>{label}</td>"
            f"<td><center>{int(score)}% </center></td>"
            f"{_score_html(score)}"
            f"<td>{info}</td></tr>"
        )
    parts.append("</table>")
    return "".join(parts)


def _load_sections(pillar: str) -> list[dict]:
    data = json.loads(DATA.read_text(encoding="utf-8"))
    return [s for s in data.get(pillar, []) if s.get("bands")]


def _lookup(sections: list[dict], name: str) -> dict | None:
    want = NARRATIVE_ALIAS.get(name.lower(), name.lower())
    for s in sections:
        if s["section"].split("(")[0].strip().lower() == want:
            return s
    return None


def _render_pillar(conn, pillar: str, mapping: list[tuple[str, str]]) -> int:
    sections = _load_sections(pillar)
    named = []
    for col, name in mapping:
        sec = _lookup(sections, name)
        if not sec:
            logger.warning("%s: no narrative for %s", pillar, name)
        named.append((col, name, sec))
    view = f"{PREFIX}vw_bm_{pillar}_pivot"
    logger.info("querying %s", view)
    with conn.cursor() as cur:
        cur.execute(f"SELECT * FROM {view} LIMIT 3")
        rows = cur.fetchall()
    if not rows:
        logger.info("no rows in %s", view)
        return 0
    shown = 0
    for row in rows:
        email = row.get("user_email") or "?"
        if pillar in VERBOSE:
            logger.info("%s %s total=%s", pillar, email, row.get("total"))
            logger.info("%s", _section_table(row, named))
        shown += 1
    if pillar not in VERBOSE:
        logger.info("%s: %s row(s) ok", pillar, shown)
    return shown


def _norm(name: str) -> str:
    return name.split("(")[0].strip().lower().replace("–", "-").replace("—", "-")


def _subsection_col(name: str) -> str | None:
    return SUBSECTION_COLS.get(_norm(name))


def _more_link(field: str) -> str:
    return (
        "<center><a style='color:blue;' class='my-button' href='#' "
        "data-bm-lazy-popup='1' data-template-id='9545' "
        f"data-param-field='{field}'><i>More</i></a></center>"
    )


def _narrative_html(text: str) -> str:
    body = (text or "").replace("\r\n", "\n").replace("\n", "<br>")
    return f"<span style=\"font-size:13pt;color:#000000;\">{body}</span>"


def _pillar_score(row: dict) -> int | None:
    avg = row.get("average")
    if avg not in (None, ""):
        try:
            n = float(avg)
            return round(n * 100) if n <= 1 else round(n)
        except (TypeError, ValueError):
            pass
    total = row.get("total")
    if total in (None, ""):
        return None
    try:
        return round(float(total))
    except (TypeError, ValueError):
        return None


def _response_html(responses: list[dict], survey: str, score: int) -> str:
    for r in responses:
        if r["survey"].lower() != survey.lower():
            continue
        low, high = int(r["low"]), int(r["high"])
        if score >= low and (score < high or (high == 100 and score <= 100)):
            return r["html"]
    return ""


def _build_user(pillar_rows: dict, responses: list[dict]) -> dict:
    """pillar_rows: pillar key -> latest pivot row. Returns column updates."""
    updates: dict[str, str] = {}
    scores: list[tuple[str, int]] = []
    for key, label, score_col, resp_col, section_col in PILLAR_FIELDS:
        row = pillar_rows.get(key)
        if not row:
            continue
        score = _pillar_score(row)
        if score is None:
            continue
        scores.append((label, score))
        updates[score_col] = str(score)
        updates[resp_col] = _response_html(responses, label, score)

        sections = _load_sections(key)
        named = []
        for col, name in PILLARS[key]:
            sec = _lookup(sections, name)
            named.append((col, name, sec))
        parts = [
            "<table ><style>td {padding: 0 10px;}</style>",
            "<th><center>Section</center></th><th><center>Score</center></th>",
            "<th><center>Result</center></th><th><center>Info</center></th>",
        ]
        for col, name, sec in named:
            raw = row.get(col)
            if raw in (None, "") or not sec:
                continue
            sec_score = float(raw)
            if sec_score <= 1:
                sec_score = round(sec_score * 100)
            field = _subsection_col(sec["section"])
            if not field:
                continue
            updates[field] = _narrative_html(sec.get("bands", {}).get(_band(sec_score), ""))
            parts.append(
                f"<tr><td>{sec['section']}</td>"
                f"<td><center>{int(sec_score)}% </center></td>"
                f"{_score_html(sec_score)}"
                f"<td>{_more_link(field)}</td></tr>"
            )
        parts.append("</table>")
        updates[section_col] = "".join(parts)

    if scores:
        low = min(scores, key=lambda s: s[1])
        high = max(scores, key=lambda s: s[1])
        updates["col5"] = f"{low[0]} | {low[1]}%"
        updates["col6"] = f"{high[0]} | {high[1]}%"
    return updates


def _latest_by_email(conn, pillar: str) -> dict[str, dict]:
    view = f"{PREFIX}vw_bm_{pillar}_pivot"
    sql = f"""
        SELECT v.* FROM {view} v
        INNER JOIN (
            SELECT user_email, MAX(submitted_date) AS d
            FROM {view}
            GROUP BY user_email
        ) m ON m.user_email = v.user_email AND m.d = v.submitted_date
    """
    with conn.cursor() as cur:
        cur.execute(sql)
        rows = cur.fetchall()
    out = {}
    for row in rows:
        email = (row.get("user_email") or "").strip().lower()
        if email:
            out[email] = row
    return out


def _send_results(conn, email: str) -> bool:
    result = send(
        email,
        PILLARS_RESULTS_TEMPLATE,
        trigger="pillars_render",
        first_name=_first_name(conn, email),
        allow_resend=True,
    )
    return bool(result.get("ok") and "sent" in (result.get("message") or ""))


def _needs_write(conn, email: str) -> bool:
    """True when the section columns are still empty, so this is a fresh result."""
    sql = f"""
        SELECT col33 FROM {USERS_TABLE}
        WHERE LOWER({EMAIL_COL}) = %s
        LIMIT 1
    """
    with conn.cursor() as cur:
        cur.execute(sql, (email,))
        row = cur.fetchone()
    if not row:
        return False
    return not (row.get("col33") or "").strip()


def _write_user(conn, email: str, updates: dict) -> bool:
    if not updates:
        return False
    sets = ", ".join(f"{col} = %s" for col in updates)
    sql = f"UPDATE {USERS_TABLE} SET {sets} WHERE LOWER({EMAIL_COL}) = %s"
    with conn.cursor() as cur:
        cur.execute(sql, list(updates.values()) + [email])
        changed = cur.rowcount
    conn.commit()
    return changed > 0


def run():
    mode = "write" if writing() else "read-only"
    logger.info("pillars_render starting (%s)", mode)
    try:
        conn = _connect()
    except Exception as e:
        logger.exception("DB connect failed")
        return {"ok": False, "message": f"connect: {e}"}
    try:
        responses = json.loads(RESPONSES.read_text(encoding="utf-8"))
        by_pillar = {}
        for key, *_rest in PILLAR_FIELDS:
            by_pillar[key] = _latest_by_email(conn, key)
            logger.info("%s: %s user(s)", key, len(by_pillar[key]))

        emails = set()
        for rows in by_pillar.values():
            emails.update(rows)
        complete = [
            e for e in sorted(emails)
            if all(e in by_pillar[key] for key, *_r in PILLAR_FIELDS)
        ]
        written = 0
        mailed = 0
        missing = 0
        for email in complete:
            pillar_rows = {key: by_pillar[key][email] for key, *_r in PILLAR_FIELDS}
            updates = _build_user(pillar_rows, responses)
            logger.info("%s: %s field(s) ready", email, len(updates))
            if not writing():
                continue
            if _write_user(conn, email, updates):
                written += 1
                if _send_results(conn, email):
                    mailed += 1
            else:
                missing += 1
                logger.warning("no %s row for %s", USERS_TABLE, email)

        if writing():
            msg = f"pillars_render: {len(complete)} complete, wrote {written}, emailed {mailed}, missing {missing}"
        else:
            msg = f"pillars_render: {len(complete)} complete user(s) would be written"
        logger.info(msg)
        return {"ok": True, "message": msg, "pending": len(complete) - written}
    except Exception as e:
        logger.exception("pillars_render failed")
        return {"ok": False, "message": str(e)}
    finally:
        conn.close()
