"""Copy a health-profile name into WordPress when the user has none.

Soft registration stores only email and password. Once uls_ULS_CF_BIO has a
name, write first_name and last_name into usermeta. Write off logs only.
"""
from __future__ import annotations

import logging

from jobctx import writing
from jobs.tag_snapshot import PREFIX, _connect

logger = logging.getLogger(__name__)
BIO = f"{PREFIX}ULS_CF_BIO"
USERS = f"{PREFIX}users"
META = f"{PREFIX}usermeta"


def run():
    do_write = writing()
    logger.info("name_fill starting (%s)", "write" if do_write else "read-only")
    try:
        conn = _connect()
    except Exception as e:
        logger.exception("DB connect failed")
        return {"ok": False, "message": f"connect: {e}"}
    try:
        sql = f"""
            SELECT u.ID AS user_id, u.user_email,
                   b.f_first_name, b.f_last_name
            FROM {USERS} u
            JOIN {BIO} b ON LOWER(b.email) = LOWER(u.user_email)
            LEFT JOIN {META} fn
              ON fn.user_id = u.ID AND fn.meta_key = 'first_name'
            WHERE (fn.meta_value IS NULL OR fn.meta_value = '')
              AND b.f_first_name IS NOT NULL AND b.f_first_name <> ''
        """
        with conn.cursor() as cur:
            cur.execute(sql)
            rows = cur.fetchall()
        pending = 0
        for row in rows:
            first = (row.get("f_first_name") or "").strip()
            last = (row.get("f_last_name") or "").strip()
            if not first:
                continue
            pending += 1
            logger.info("%s would set %s %s", row["user_email"], first, last)
            if not do_write:
                continue
            _upsert(conn, row["user_id"], "first_name", first)
            if last:
                _upsert(conn, row["user_id"], "last_name", last)
        conn.commit()
        if do_write:
            msg = f"name_fill: updated {pending}"
        elif pending:
            msg = f"name_fill: {pending} would be updated (read-only)"
        else:
            msg = "name_fill: no blank names with a health profile"
        logger.info(msg)
        return {"ok": True, "message": msg, "pending": pending}
    except Exception as e:
        logger.exception("name_fill failed")
        return {"ok": False, "message": str(e)}
    finally:
        conn.close()


def _upsert(conn, user_id: int, key: str, value: str) -> None:
    with conn.cursor() as cur:
        cur.execute(
            f"SELECT umeta_id FROM {META} WHERE user_id = %s AND meta_key = %s",
            (user_id, key),
        )
        existing = cur.fetchone()
        if existing:
            cur.execute(
                f"UPDATE {META} SET meta_value = %s WHERE umeta_id = %s",
                (value, existing["umeta_id"]),
            )
        else:
            cur.execute(
                f"INSERT INTO {META} (user_id, meta_key, meta_value) VALUES (%s, %s, %s)",
                (user_id, key, value),
            )
