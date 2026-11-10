"""Evaluate WP Fusion tag completion rules (from Excel UpdateTagsByRules).

Adds the completion tag when the job write flag is on. Adding KEY_COMPLETE
also sends the Key results email (template 25).
"""
from __future__ import annotations

import logging

from emailer import send
from jobctx import writing
from jobs.rsi_score import _first_name
from jobs.tag_snapshot import _connect, _normalize_tags, fetch_current_tags
from jobs.tags import write_tag_changes

logger = logging.getLogger(__name__)
KEY_RESULTS_TEMPLATE = 25

# (required tags, completion tag) — same rules as the Excel workbook
TAG_RULES = [
    (
        ["OCCUPATIONAL", "SOCIAL", "SPIRITUAL", "MENTAL", "FINANCIAL", "ENVIRONMENTAL", "PHYSICAL", "EMOTIONAL"],
        "9 COMPLETED",
    ),
    (
        ["REGISTERED", "AUTHORIZATION", "PRIVACY", "TERMS"],
        "3_ACCEPT",
    ),
    (
        ["BSI1", "BSI2", "BSI3", "BSI4", "BSI5", "BSI6", "BSI7", "BSI8", "BSI9"],
        "BSI_COMPLETE",
    ),
    (
        ["RSI11", "RSI12"],
        "RSI_COMPLETE",
    ),
    (
        ["KEYFLUID", "KEYFOOD", "KEYBREATH", "KEYMOVEMENT", "KEYMIND", "KEYSLEEP", "KEYNATURE"],
        "KEY_COMPLETE",
    ),
]


def run():
    mode = "write" if writing() else "read-only"
    logger.info("tag_rules starting (%s)", mode)
    try:
        conn = _connect()
    except Exception as e:
        logger.exception("DB connect failed")
        return {"ok": False, "message": f"connect: {e}"}

    try:
        users = fetch_current_tags(conn)
        would_add = []  # (email, user_id, completion, current list)

        for u in users:
            current = _normalize_tags(u["raw"])
            tags = set(current)
            email = u["user_email"]
            for required, completion in TAG_RULES:
                if completion in tags:
                    continue
                if all(r in tags for r in required):
                    would_add.append((email, u["user_id"], completion, current))
                    logger.info("%s would receive %s", email, completion)

        if not would_add:
            msg = "tag_rules: no completion tags pending"
            logger.info(msg)
            return {"ok": True, "message": msg, "pending": 0}

        added = 0
        mailed = 0
        if writing():
            seen = {}
            for email, uid, completion, current in would_add:
                base = seen.get(uid, current)
                new_tags = write_tag_changes(conn, uid, base, add=[completion], email=email)
                if completion in new_tags and completion not in base:
                    added += 1
                    seen[uid] = new_tags
                    logger.info("%s added %s", email, completion)
                    if completion == "KEY_COMPLETE":
                        result = send(
                            email, KEY_RESULTS_TEMPLATE, trigger="tag_rules",
                            first_name=_first_name(conn, email), allow_resend=True,
                        )
                        if result.get("ok") and "sent" in (result.get("message") or ""):
                            mailed += 1

        by_tag: dict[str, int] = {}
        for _, _, tag, _ in would_add:
            by_tag[tag] = by_tag.get(tag, 0) + 1
        summary = ", ".join(f"{t}:{n}" for t, n in sorted(by_tag.items()))
        if writing():
            msg = f"{len(would_add)} pending ({summary}), added {added}, emailed {mailed}"
        else:
            msg = f"{len(would_add)} pending ({summary}) (read-only)"
        logger.info("tag_rules: %s", msg)
        return {"ok": True, "message": msg, "pending": len(would_add) - added}
    except Exception as e:
        logger.exception("tag_rules failed")
        return {"ok": False, "message": str(e)}
    finally:
        conn.close()
