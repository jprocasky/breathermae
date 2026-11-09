"""Detect tag changes by comparing live usermeta to uls_bm_tag_snapshots.

Logs added/removed tags per user. Does not modify the snapshot
(tag_snapshot job owns that). Safe to run on its own interval or via Run now.
"""
from __future__ import annotations

import json
import logging
import os

import pymysql

from jobs.tag_snapshot import (
    PREFIX,
    _connect,
    _hash_tags,
    _normalize_tags,
    fetch_current_tags,
)

logger = logging.getLogger(__name__)


def _load_snapshots(conn) -> dict[int, dict]:
    sql = f"""
        SELECT user_id, user_email, tags_json, tags_hash
        FROM {PREFIX}bm_tag_snapshots
    """
    with conn.cursor() as cur:
        cur.execute(sql)
        rows = cur.fetchall()
    out = {}
    for r in rows:
        try:
            tags = json.loads(r["tags_json"]) if r["tags_json"] else []
        except (TypeError, json.JSONDecodeError):
            tags = []
        out[r["user_id"]] = {
            "user_email": r["user_email"],
            "tags": set(tags),
            "hash": r["tags_hash"],
        }
    return out


def run():
    logger.info("tag_diff starting")
    try:
        conn = _connect()
    except Exception as e:
        logger.exception("DB connect failed")
        return {"ok": False, "message": f"connect: {e}"}

    try:
        live = fetch_current_tags(conn)
        stored = _load_snapshots(conn)

        changes = []
        for u in live:
            uid = u["user_id"]
            email = u["user_email"]
            current = set(_normalize_tags(u["raw"]))
            prev = stored.get(uid)

            if prev is None:
                changes.append(
                    {
                        "user_id": uid,
                        "user_email": email,
                        "added": sorted(current),
                        "removed": [],
                        "note": "new user",
                    }
                )
                continue

            if _hash_tags(sorted(current)) == prev["hash"]:
                continue

            added = sorted(current - prev["tags"])
            removed = sorted(prev["tags"] - current)
            if added or removed:
                changes.append(
                    {
                        "user_id": uid,
                        "user_email": email,
                        "added": added,
                        "removed": removed,
                    }
                )

        # users who disappeared from live tag meta
        live_ids = {u["user_id"] for u in live}
        for uid, prev in stored.items():
            if uid not in live_ids and prev["tags"]:
                changes.append(
                    {
                        "user_id": uid,
                        "user_email": prev["user_email"],
                        "added": [],
                        "removed": sorted(prev["tags"]),
                        "note": "no longer has tag meta",
                    }
                )

        if not changes:
            msg = "tag_diff: no changes"
            logger.info(msg)
            return {"ok": True, "message": msg, "changes": 0}

        # log a compact summary; full detail at INFO per user
        logger.info("tag_diff: %s user(s) changed", len(changes))
        for c in changes:
            logger.info(
                "%s (%s) +%s -%s %s",
                c["user_email"],
                c["user_id"],
                c["added"] or "—",
                c["removed"] or "—",
                c.get("note", ""),
            )

        # keep the status message short for the dashboard
        sample = changes[0]
        msg = (
            f"{len(changes)} changed; e.g. {sample['user_email']} "
            f"+{len(sample['added'])}/-{len(sample['removed'])}"
        )
        return {"ok": True, "message": msg, "changes": len(changes)}
    except Exception as e:
        logger.exception("tag_diff failed")
        return {"ok": False, "message": str(e)}
    finally:
        conn.close()
