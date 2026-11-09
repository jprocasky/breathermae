"""Date-driven emails from template rules.

Only templates with a "rule" object are considered. The 3-day floor
applies here and nowhere else. First matching rule (by order) wins.
"""
from __future__ import annotations

import json
import logging
from datetime import datetime, timedelta
from pathlib import Path

from emailer import DATA, send
from jobctx import writing
from jobs.rsi_score import _first_name
from jobs.tag_snapshot import PREFIX, _connect, _normalize_tags, fetch_current_tags

logger = logging.getLogger(__name__)
FLOOR_DAYS = 3
VISIT_META = "_breathermae_last_active"


def _rules() -> list[dict]:
    templates = json.loads(Path(DATA).read_text(encoding="utf-8"))
    ruled = [t for t in templates if t.get("rule")]
    return sorted(ruled, key=lambda t: t["rule"].get("order", 100))


def _last_email(conn) -> dict[str, datetime]:
    sql = f"""
        SELECT user_email, MAX(sent_at) AS last_at
        FROM {PREFIX}email_log
        WHERE trigger_name <> 'test'
        GROUP BY user_email
    """
    out = {}
    with conn.cursor() as cur:
        try:
            cur.execute(sql)
        except Exception:
            return out
        for row in cur.fetchall():
            email = (row.get("user_email") or "").strip().lower()
            when = row.get("last_at")
            if email and isinstance(when, datetime):
                out[email] = when
    return out


def _send_counts(conn) -> dict[tuple[str, int], int]:
    sql = f"""
        SELECT user_email, template_id, COUNT(*) AS n
        FROM {PREFIX}email_log
        WHERE trigger_name <> 'test' AND status = 'sent'
        GROUP BY user_email, template_id
    """
    out = {}
    with conn.cursor() as cur:
        try:
            cur.execute(sql)
        except Exception:
            return out
        for row in cur.fetchall():
            email = (row.get("user_email") or "").strip().lower()
            out[(email, int(row["template_id"]))] = int(row["n"])
    return out


def _visits(conn) -> dict[str, datetime]:
    sql = f"""
        SELECT u.user_email, um.meta_value
        FROM {PREFIX}users u
        JOIN {PREFIX}usermeta um ON um.user_id = u.ID AND um.meta_key = %s
    """
    out = {}
    with conn.cursor() as cur:
        cur.execute(sql, (VISIT_META,))
        for row in cur.fetchall():
            email = (row.get("user_email") or "").strip().lower()
            raw = row.get("meta_value")
            if not email or not raw:
                continue
            text = str(raw)[:19]
            try:
                if text.isdigit():
                    out[email] = datetime.fromtimestamp(int(text))
                else:
                    out[email] = datetime.fromisoformat(text.replace(" ", "T"))
            except ValueError:
                continue
    return out


def _registered(conn) -> dict[str, datetime]:
    sql = f"SELECT user_email, user_registered FROM {PREFIX}users"
    out = {}
    with conn.cursor() as cur:
        cur.execute(sql)
        for row in cur.fetchall():
            email = (row.get("user_email") or "").strip().lower()
            when = row.get("user_registered")
            if email and isinstance(when, datetime):
                out[email] = when
    return out


def _events(conn) -> dict[tuple[str, str], datetime]:
    sql = f"""
        SELECT user_email, event_key, last_updated
        FROM {PREFIX}user_event_log
    """
    out = {}
    with conn.cursor() as cur:
        try:
            cur.execute(sql)
        except Exception:
            logger.warning("event log not readable")
            return out
        for row in cur.fetchall():
            email = (row.get("user_email") or "").strip().lower()
            key = (row.get("event_key") or "").strip()
            when = row.get("last_updated")
            if email and key and isinstance(when, datetime):
                out[(email, key)] = when
    return out


def _matches(rule: dict, tags: set[str], visit, counts: dict, email: str, template_id: int, registered, events) -> bool:
    need = {t.upper() for t in rule.get("tags_required") or []}
    missing = {t.upper() for t in rule.get("tags_missing") or []}
    have = {t.upper() for t in tags}
    if need and not need.issubset(have):
        return False
    if missing and missing & have:
        return False
    for prior in rule.get("requires_sent") or []:
        if counts.get((email, int(prior)), 0) < 1:
            return False
    cap = rule.get("max_sends")
    if cap is not None and counts.get((email, template_id), 0) >= int(cap):
        return False
    days = rule.get("days_since_visit")
    if days is not None:
        if visit is None:
            return False
        if (datetime.now() - visit).days <= int(days):
            return False
    age = rule.get("max_days_since_register")
    if age is not None:
        if registered is None:
            return False
        if (datetime.now() - registered).days > int(age):
            return False
    minimum = rule.get("min_days_since_register")
    if minimum is not None:
        if registered is None:
            return False
        if (datetime.now() - registered).days < int(minimum):
            return False
    key = (rule.get("event_key") or "").strip()
    mode = rule.get("event_mode") or ""
    if key and mode:
        seen = events.get((email, key))
        if mode == "seen" and seen is None:
            return False
        if mode == "missing" and seen is not None:
            return False
        since = rule.get("days_since_event")
        if since is not None and mode == "seen":
            if seen is None or (datetime.now() - seen).days < int(since):
                return False
    return True


def run():
    mode = "write" if writing() else "read-only"
    logger.info("email_rules starting (%s)", mode)
    rules = _rules()
    if not rules:
        return {"ok": True, "message": "no date rules", "pending": 0}
    try:
        conn = _connect()
    except Exception as e:
        logger.exception("DB connect failed")
        return {"ok": False, "message": f"connect: {e}"}
    try:
        users = fetch_current_tags(conn)
        last_email = _last_email(conn)
        counts = _send_counts(conn)
        visits = _visits(conn)
        registered = _registered(conn)
        events = _events(conn)
        floor = datetime.now() - timedelta(days=FLOOR_DAYS)
        pending = 0
        mailed = 0
        for u in users:
            email = (u.get("user_email") or "").strip().lower()
            if not email:
                continue
            if last_email.get(email, datetime.min) > floor:
                continue
            tags = set(_normalize_tags(u["raw"]))
            visit = visits.get(email)
            for template in rules:
                rule = template["rule"]
                if _matches(rule, tags, visit, counts, email, int(template["id"]), registered.get(email), events):
                    pending += 1
                    logger.info("%s would get template %s (%s)", email, template["id"], template["name"])
                    if writing():
                        result = send(
                            email, int(template["id"]), trigger="email_rules",
                            first_name=_first_name(conn, email),
                        )
                        if result.get("ok") and "sent" in (result.get("message") or ""):
                            mailed += 1
                    break
        if writing():
            msg = f"email_rules: {pending} matched, emailed {mailed}"
        else:
            msg = f"email_rules: {pending} would be sent (read-only)"
        logger.info(msg)
        return {"ok": True, "message": msg, "pending": pending}
    except Exception as e:
        logger.exception("email_rules failed")
        return {"ok": False, "message": str(e)}
    finally:
        conn.close()
