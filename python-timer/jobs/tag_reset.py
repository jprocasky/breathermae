"""Read-only reset check: log form tags that would be removed after N days.

Leaves completion tags in place. Does not write tags or update tables.
Matches the Excel reset routines, collapsed into one config-driven pass.
"""
from __future__ import annotations

import logging
from datetime import datetime, timedelta

from emailer import send
from jobctx import writing
from jobs.rsi_score import _first_name
from jobs.tag_snapshot import PREFIX, _connect, _normalize_tags, fetch_current_tags
from jobs.tags import write_tag_changes

logger = logging.getLogger(__name__)

# completion tag is listed only so we never treat it as removable
RESET_RULES = [
    {
        "name": "RSI",
        "table": f"{PREFIX}bm_rsi_results",
        "email_col": "user_email",
        "date_col": "updated_at",
        "final_only": True,
        "days": 7,
        "remove": ["RSI11", "RSI12"],
        "keep": "RSI_COMPLETE",
        "template_id": 11,
        "reminder": "RSI Available",
    },
    {
        "name": "BSI",
        "table": f"{PREFIX}bm_bsi_results",
        "email_col": "user_email",
        "date_col": "updated_at",
        "final_only": True,
        "days": 90,
        "remove": ["BSI1", "BSI2", "BSI3", "BSI4", "BSI5", "BSI6", "BSI7", "BSI8", "BSI9"],
        "keep": "BSI_COMPLETE",
        "template_id": 19,
        "reminder": "BSI Available",
    },
    {
        "name": "KEYS",
        "table": f"{PREFIX}key_essentials",
        "email_col": "user_email",
        "date_col": "datetime",
        "final_only": False,  # max(datetime) across the 7 form rows
        "days": 14,
        "remove": [
            "KEYFLUID",
            "KEYFOOD",
            "KEYBREATH",
            "KEYMOVEMENT",
            "KEYMIND",
            "KEYSLEEP",
            "KEYNATURE",
        ],
        "keep": "KEY_COMPLETE",
        "template_id": 22,
        "reminder": "Key Life Essentials Available",
    },
    {
        "name": "PILLARS",
        "table": f"{PREFIX}vw_bm_8_pillars_pivot",
        "email_col": "user_email",
        "date_col": "submitted_date",
        "final_only": False,
        "days": 90,
        "remove": [
            "OCCUPATIONAL",
            "SOCIAL",
            "SPIRITUAL",
            "MENTAL",
            "FINANCIAL",
            "ENVIRONMENTAL",
            "PHYSICAL",
            "EMOTIONAL",
        ],
        "keep": "9 COMPLETED",
        "template_id": 23,
        "reminder": "8 Pillars of Wellness Available",
        # Cleared so the next complete set can be written and mailed.
        "clear_columns": (
            ["col5", "col6", "col42"]
            + [f"col{n}" for n in range(11, 19)]
            + [f"col{n}" for n in range(20, 28)]
            + [f"col{n}" for n in range(33, 41)]
            + [f"col{n}" for n in range(45, 82)]
        ),
    },
    {
        "name": "RAPID",
        "table": f"{PREFIX}bm_pillars_results",
        "email_col": "user_email",
        "date_col": "results_date",
        "final_only": False,
        "extra_where": "series = 'rapid'",
        "days": 7,
        # Excel ResetRapid8Pillars removes the PILLARS tag after 7 days.
        "remove": ["PILLARS"],
        "keep": "PILLARS",
        "template_id": 24,
        "reminder": "Rapid 8 Pillars of Wellness Available",
    },
]


def _latest_dates(conn, rule: dict) -> dict[str, datetime]:
    """email -> date of the single newest row for that user.

    Newest row only. Older rows are ignored even if they are final.
    For RSI/BSI the newest row must also be is_final=1 to count.
    """
    table = rule["table"]
    email_col = rule["email_col"]
    date_col = rule["date_col"]
    extra = rule.get("extra_where")
    where = f"WHERE {extra}" if extra else ""
    if rule["final_only"]:
        sql = f"""
            SELECT t.{email_col} AS email, t.{date_col} AS latest
            FROM {table} t
            INNER JOIN (
                SELECT {email_col} AS email, MAX({date_col}) AS latest
                FROM {table}
                {where}
                GROUP BY {email_col}
            ) m ON m.email = t.{email_col} AND m.latest = t.{date_col}
            WHERE t.is_final = 1
        """
    else:
        sql = f"""
            SELECT {email_col} AS email, MAX({date_col}) AS latest
            FROM {table}
            {where}
            GROUP BY {email_col}
        """
    out: dict[str, datetime] = {}
    with conn.cursor() as cur:
        cur.execute(sql)
        for row in cur.fetchall():
            email = (row.get("email") or "").strip().lower()
            latest = row.get("latest")
            if not email or latest is None:
                continue
            if isinstance(latest, datetime):
                out[email] = latest
            else:
                text = str(latest)[:19]
                try:
                    out[email] = datetime.fromisoformat(text)
                except ValueError:
                    continue
    return out


def _add_reminder(conn, email: str, user_id, subject: str) -> bool:
    """Always insert a new reminder. Past-dated rows are ignored by the frontend."""
    start = (datetime.now() + timedelta(days=7)).strftime("%Y-%m-%d 00:00:00")
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    desc = (
        f"{subject.replace(' Available', '')} is now available to be taken again. "
        "<br><a href='\\welcomesurveys\\'><strong>Click here for Assessment Page</strong></a>"
    )
    with conn.cursor() as cur:
        cur.execute(
            f"""
            INSERT INTO {PREFIX}member_appointments
                (member_email, member_user_id, start_at, subject, description,
                 created_by, created_at, is_deleted)
            VALUES (%s, %s, %s, %s, %s, 1, %s, 0)
            """,
            (email, user_id, start, subject, desc, now),
        )
    conn.commit()
    return True


def _clear_pillar_fields(conn, email: str, columns: list[str]) -> bool:
    sets = ", ".join(f"{col} = ''" for col in columns)
    sql = f"UPDATE {PREFIX}wptm_tbl_4 SET {sets} WHERE LOWER(col2) = %s"
    with conn.cursor() as cur:
        cur.execute(sql, (email,))
        changed = cur.rowcount
    conn.commit()
    return changed > 0


def run():
    logger.info("tag_reset starting (read-only)")
    try:
        conn = _connect()
    except Exception as e:
        logger.exception("DB connect failed")
        return {"ok": False, "message": f"connect: {e}"}

    try:
        users = fetch_current_tags(conn)
        by_email = {}
        user_ids = {}
        for u in users:
            email = (u.get("user_email") or "").strip().lower()
            if email:
                by_email[email] = _normalize_tags(u["raw"])
                user_ids[email] = u.get("user_id")

        cutoff_cache = {}
        would = []  # (email, rule_name, tags)

        for rule in RESET_RULES:
            try:
                latest = _latest_dates(conn, rule)
            except Exception as e:
                logger.error("%s query failed: %s", rule["name"], e)
                continue
            cutoff = datetime.now() - timedelta(days=rule["days"])
            cutoff_cache[rule["name"]] = cutoff
            for email, tags in by_email.items():
                tagset = set(tags)
                # subscription only — free users do not retake
                if "ART" not in tagset and "S360" not in tagset:
                    continue
                # completed tag must already be present
                if rule["keep"] not in tagset:
                    continue
                # every child tag must still be present (incomplete next set = skip)
                if not all(t in tagset for t in rule["remove"]):
                    continue
                last = latest.get(email)
                if last is None or last >= cutoff:
                    continue
                would.append((email, rule["name"], list(rule["remove"]), last))
                logger.info(
                    "%s %s would remove %s (last %s, >%s days)",
                    email,
                    rule["name"],
                    rule["remove"],
                    last.strftime("%Y-%m-%d"),
                    rule["days"],
                )

        if not would:
            msg = "tag_reset: nothing to remove"
            logger.info(msg)
            return {"ok": True, "message": msg, "pending": 0}

        by_rule: dict[str, int] = {}
        for _, name, _, _ in would:
            by_rule[name] = by_rule.get(name, 0) + 1
        summary = ", ".join(f"{k}:{v}" for k, v in sorted(by_rule.items()))
        cleared = 0
        retagged = 0
        reminded = 0
        mailed = 0
        if writing():
            rules = {r["name"]: r for r in RESET_RULES}
            for email, name, remove, _last in would:
                rule = rules[name]
                cols = rule.get("clear_columns")
                if cols and _clear_pillar_fields(conn, email, cols):
                    cleared += 1
                    logger.info("%s %s cleared %s field(s)", email, name, len(cols))
                uid = user_ids.get(email)
                current = by_email.get(email, [])
                if uid and current:
                    new_tags = write_tag_changes(conn, uid, current, remove=remove)
                    if new_tags != current:
                        retagged += 1
                        by_email[email] = new_tags
                        logger.info("%s %s removed %s", email, name, remove)
                subject = rule.get("reminder")
                if subject and _add_reminder(conn, email, uid, subject):
                    reminded += 1
                template_id = rule.get("template_id")
                if template_id:
                    result = send(
                        email, template_id, trigger="tag_reset",
                        first_name=_first_name(conn, email), allow_resend=True,
                    )
                    if result.get("ok") and "sent" in (result.get("message") or ""):
                        mailed += 1
        msg = f"{len(would)} pending ({summary})"
        if writing():
            msg += f", cleared {cleared}, tags {retagged}, reminders {reminded}, emailed {mailed}"
        else:
            msg += " (read-only)"
        logger.info("tag_reset: %s", msg)
        return {"ok": True, "message": msg, "pending": len(would)}
    except Exception as e:
        logger.exception("tag_reset failed")
        return {"ok": False, "message": str(e)}
    finally:
        conn.close()
