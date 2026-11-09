"""Write WP Fusion tags the same way Excel does.

zoho_tags and multi_tags are PHP-serialized string arrays in usermeta.
Order of existing tags is preserved. Added tags are appended.
"""
from __future__ import annotations

import logging

from jobs.tag_snapshot import PREFIX, TAG_META_KEYS, _normalize_tags

logger = logging.getLogger(__name__)


def _php_serialize(tags: list[str]) -> str:
    parts = ["a:%d:{" % len(tags)]
    for i, tag in enumerate(tags):
        raw = tag.encode("utf-8")
        parts.append(f'i:{i};s:{len(raw)}:"{tag}";')
    parts.append("}")
    return "".join(parts)


def _upsert_meta(conn, user_id: int, meta_key: str, value: str) -> None:
    sql_find = f"""
        SELECT umeta_id FROM {PREFIX}usermeta
        WHERE user_id = %s AND meta_key = %s
        ORDER BY umeta_id DESC LIMIT 1
    """
    with conn.cursor() as cur:
        cur.execute(sql_find, (user_id, meta_key))
        row = cur.fetchone()
        if row:
            cur.execute(
                f"UPDATE {PREFIX}usermeta SET meta_value = %s WHERE umeta_id = %s",
                (value, row["umeta_id"]),
            )
        else:
            cur.execute(
                f"INSERT INTO {PREFIX}usermeta (user_id, meta_key, meta_value) VALUES (%s, %s, %s)",
                (user_id, meta_key, value),
            )


def set_user_tags(conn, user_id: int, tags: list[str]) -> None:
    """Replace zoho_tags and multi_tags with the given list."""
    payload = _php_serialize(tags)
    for key in TAG_META_KEYS:
        _upsert_meta(conn, user_id, key, payload)
    conn.commit()


def apply_tag_changes(conn, user_id: int, current: list[str], remove: list[str] | None = None, add: list[str] | None = None) -> list[str]:
    """Return the new tag list after removals and additions. Does not write."""
    remove_set = {t.upper() for t in (remove or [])}
    kept = [t for t in current if t.upper() not in remove_set]
    have = {t.upper() for t in kept}
    for tag in add or []:
        if tag.upper() not in have:
            kept.append(tag)
            have.add(tag.upper())
    return kept


def write_tag_changes(conn, user_id: int, current: list[str], remove: list[str] | None = None, add: list[str] | None = None) -> list[str]:
    """Apply changes and write both meta keys. Returns the new list."""
    new_tags = apply_tag_changes(conn, user_id, current, remove, add)
    if new_tags == current:
        return current
    set_user_tags(conn, user_id, new_tags)
    logger.info("user %s tags %s -> %s", user_id, len(current), len(new_tags))
    return new_tags
