"""Pull current WP Fusion tags from usermeta and upsert the snapshot table.

First version: reads zoho_tags / multi_tags, normalizes to a sorted list,
and writes wp_bm_tag_snapshots. Later the poller will diff against this.
"""
from __future__ import annotations

import hashlib
import json
import logging
import os
from datetime import datetime

import pymysql

logger = logging.getLogger(__name__)

# Table prefix — set WP_DB_PREFIX in .env (default uls_)
PREFIX = os.environ.get("WP_DB_PREFIX", "uls_")

# Meta keys WP Fusion uses for the tag list (adjust if your CRM key differs)
TAG_META_KEYS = ("zoho_tags", "multi_tags")


def _connect():
    return pymysql.connect(
        host=os.environ.get("WP_DB_HOST", "127.0.0.1"),
        port=int(os.environ.get("WP_DB_PORT", "3306")),
        user=os.environ.get("WP_DB_USER", ""),
        password=os.environ.get("WP_DB_PASSWORD", ""),
        database=os.environ.get("WP_DB_NAME", ""),
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        connect_timeout=15,
    )


def _normalize_tags(raw) -> list[str]:
    """Turn usermeta value into a sorted unique list of tag strings."""
    if raw is None:
        return []
    if isinstance(raw, (bytes, bytearray)):
        raw = raw.decode("utf-8", errors="replace")
    # PHP serialized arrays are common; try JSON first, then a light unserialize
    if isinstance(raw, str):
        raw = raw.strip()
        if raw.startswith("[") or raw.startswith("{"):
            try:
                raw = json.loads(raw)
            except json.JSONDecodeError:
                pass
        if isinstance(raw, str) and raw.startswith("a:"):
            raw = _unserialize_php_array(raw)
    if isinstance(raw, dict):
        raw = list(raw.values())
    if not isinstance(raw, (list, tuple)):
        return []
    tags = []
    for item in raw:
        if isinstance(item, str):
            t = item.strip()
            if t:
                tags.append(t)
    return sorted(set(tags))


def _unserialize_php_array(s: str) -> list:
    """Minimal extractor for PHP serialized string arrays (a:N:{i:0;s:L:"tag";...})."""
    import re
    return re.findall(r's:\d+:"([^"]*)"', s)


def _hash_tags(tags: list[str]) -> str:
    blob = json.dumps(tags, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(blob.encode("utf-8")).hexdigest()


def fetch_current_tags(conn) -> list[dict]:
    """Return one row per user that has tag meta."""
    placeholders = ",".join(["%s"] * len(TAG_META_KEYS))
    sql = f"""
        SELECT u.ID AS user_id,
               u.user_email,
               um.meta_key,
               um.meta_value
        FROM {PREFIX}users u
        JOIN {PREFIX}usermeta um ON um.user_id = u.ID
        WHERE um.meta_key IN ({placeholders})
          AND um.meta_value IS NOT NULL
          AND um.meta_value != ''
    """
    with conn.cursor() as cur:
        cur.execute(sql, TAG_META_KEYS)
        rows = cur.fetchall()

    # Prefer zoho_tags if both exist; otherwise take whatever we got
    by_user: dict[int, dict] = {}
    for r in rows:
        uid = r["user_id"]
        entry = by_user.setdefault(
            uid, {"user_id": uid, "user_email": r["user_email"], "raw": None, "key": None}
        )
        # zoho_tags wins
        if entry["key"] != "zoho_tags":
            entry["raw"] = r["meta_value"]
            entry["key"] = r["meta_key"]
    return list(by_user.values())


def upsert_snapshots(conn, users: list[dict]) -> tuple[int, int]:
    """Write snapshots. Returns (inserted_or_updated, unchanged)."""
    now = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
    changed = 0
    same = 0
    sql = f"""
        INSERT INTO {PREFIX}bm_tag_snapshots
            (user_id, user_email, tags_json, tags_hash, tag_count, updated_at)
        VALUES (%s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            user_email = VALUES(user_email),
            tags_json = IF(tags_hash <> VALUES(tags_hash), VALUES(tags_json), tags_json),
            tags_hash = IF(tags_hash <> VALUES(tags_hash), VALUES(tags_hash), tags_hash),
            tag_count = IF(tags_hash <> VALUES(tags_hash), VALUES(tag_count), tag_count),
            updated_at = IF(tags_hash <> VALUES(tags_hash), VALUES(updated_at), updated_at)
    """
    with conn.cursor() as cur:
        for u in users:
            tags = _normalize_tags(u["raw"])
            h = _hash_tags(tags)
            payload = json.dumps(tags, ensure_ascii=False)
            cur.execute(
                sql,
                (u["user_id"], u["user_email"], payload, h, len(tags), now),
            )
            # rowcount 1 = insert, 2 = update, 0 = no change (MySQL)
            if cur.rowcount == 0:
                same += 1
            else:
                changed += 1
    conn.commit()
    return changed, same


def run():
    """Entry point for the timer."""
    logger.info("tag_snapshot starting")
    try:
        conn = _connect()
    except Exception as e:
        logger.exception("DB connect failed")
        return {"ok": False, "message": f"connect: {e}"}

    try:
        # quick visibility: which tag meta keys actually exist
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT meta_key, COUNT(*) AS n
                FROM {PREFIX}usermeta
                WHERE meta_key IN ('zoho_tags', 'multi_tags')
                   OR meta_key LIKE '%%_tags'
                GROUP BY meta_key
                ORDER BY n DESC
                LIMIT 20
                """
            )
            key_counts = cur.fetchall()
        logger.info("tag meta keys found: %s", key_counts)

        users = fetch_current_tags(conn)
        logger.info("users with tag meta: %s", len(users))
        if users:
            sample = users[0]
            logger.info(
                "sample user %s (%s) key=%s",
                sample["user_id"],
                sample["user_email"],
                sample["key"],
            )

        changed, same = upsert_snapshots(conn, users)
        msg = f"snapshots: {len(users)} users, {changed} written, {same} unchanged"
        logger.info(msg)
        return {
            "ok": True,
            "message": msg,
            "users": len(users),
            "changed": changed,
            "keys": [dict(r) for r in key_counts],
        }
    except Exception as e:
        logger.exception("snapshot update failed")
        return {"ok": False, "message": str(e)}
    finally:
        conn.close()
