"""Remove copied user rows that no longer have a WordPress user.

uls_wptm_tbl_4 is a derived table. If the email is gone from uls_users,
the row should go too. Write off lists them. Write on deletes them.
"""
from __future__ import annotations

import logging

from jobctx import writing
from jobs.tag_snapshot import PREFIX, _connect

logger = logging.getLogger(__name__)
USERS = f"{PREFIX}wptm_tbl_4"
WP_USERS = f"{PREFIX}users"
SNAPSHOTS = f"{PREFIX}bm_tag_snapshots"


def run():
    do_write = writing()
    logger.info("user_cleanup starting (%s)", "write" if do_write else "read-only")
    try:
        conn = _connect()
    except Exception as e:
        logger.exception("DB connect failed")
        return {"ok": False, "message": f"connect: {e}"}
    try:
        sql = f"""
            SELECT t.col2 AS email
            FROM {USERS} t
            LEFT JOIN {WP_USERS} u ON LOWER(u.user_email) = LOWER(t.col2)
            WHERE t.col2 LIKE '%@%' AND u.ID IS NULL
        """
        with conn.cursor() as cur:
            cur.execute(sql)
            rows = cur.fetchall()
        emails = sorted({(r.get("email") or "").strip() for r in rows if r.get("email")})
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT s.user_email FROM {SNAPSHOTS} s
                LEFT JOIN {WP_USERS} u ON u.ID = s.user_id
                WHERE u.ID IS NULL
                """
            )
            snaps = cur.fetchall()
        snap_emails = sorted({(r.get("user_email") or "").strip() for r in snaps if r.get("user_email")})
        for email in emails:
            logger.info("orphan %s", email)
        for email in snap_emails:
            logger.info("snapshot orphan %s", email)
        if do_write and (emails or snap_emails):
            with conn.cursor() as cur:
                cur.execute(
                    f"""
                    DELETE t FROM {USERS} t
                    LEFT JOIN {WP_USERS} u ON LOWER(u.user_email) = LOWER(t.col2)
                    WHERE t.col2 LIKE '%@%' AND u.ID IS NULL
                    """
                )
                removed = cur.rowcount
                cur.execute(
                    f"""
                    DELETE s FROM {SNAPSHOTS} s
                    LEFT JOIN {WP_USERS} u ON u.ID = s.user_id
                    WHERE u.ID IS NULL
                    """
                )
                removed_snaps = cur.rowcount
            conn.commit()
            msg = f"user_cleanup: removed {removed} user row(s), {removed_snaps} snapshot(s)"
        elif emails or snap_emails:
            msg = (
                f"user_cleanup: {len(emails)} user row(s), {len(snap_emails)} snapshot(s) "
                "would be removed (read-only)"
            )
        else:
            msg = "user_cleanup: no orphans"
        logger.info(msg)
        return {"ok": True, "message": msg, "pending": len(emails)}
    except Exception as e:
        logger.exception("user_cleanup failed")
        return {"ok": False, "message": str(e)}
    finally:
        conn.close()
