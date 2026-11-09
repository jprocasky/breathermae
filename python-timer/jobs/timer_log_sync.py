"""Sync recent timer runs to uls_breathermae_process_log.

Appends new rows (with machine name) and prunes the table to ~1000 rows.
"""
from __future__ import annotations

import logging
import sqlite3
from pathlib import Path

from jobctx import writing
from jobs.tag_snapshot import PREFIX, _connect
from lease import load_settings

logger = logging.getLogger(__name__)
DB_FILE = Path(__file__).resolve().parent.parent / "status.db"
MAX_ROWS = 150          # how many recent local runs to consider
KEEP_ROWS = 1000        # prune WP table to this many rows


def _recent_runs(limit: int = MAX_ROWS) -> list[dict]:
    if not DB_FILE.exists():
        return []
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    rows = conn.execute(
        """
        SELECT job_name, started_at, ok, message
        FROM runs
        ORDER BY started_at DESC
        LIMIT ?
        """,
        (limit,),
    ).fetchall()
    conn.close()
    return [dict(r) for r in rows]


def run():
    logger.info("timer_log_sync starting")
    settings = load_settings()
    machine = settings.get("name") or settings.get("instance_id") or "unknown"
    runs = _recent_runs()
    if not runs:
        return {"ok": True, "message": "no runs to sync", "pending": 0}

    try:
        conn = _connect()
    except Exception as e:
        logger.exception("DB connect failed")
        return {"ok": False, "message": f"connect: {e}"}

    table = f"{PREFIX}breathermae_process_log"
    try:
        with conn.cursor() as cur:
            # Newest row we already stored for this machine
            cur.execute(
                f"""
                SELECT MAX(log_datetime) AS latest
                FROM {table}
                WHERE machine = %s
                """,
                (machine,),
            )
            row = cur.fetchone() or {}
            latest = row.get("latest")
            latest_str = latest.strftime("%Y-%m-%d %H:%M:%S") if latest else "1970-01-01 00:00:00"

            payload = []
            for r in runs:
                method = (r.get("job_name") or r.get("job_id") or "")[:190]
                when = (r.get("started_at") or "")[:19].replace("T", " ")
                if not when or when <= latest_str:
                    continue
                status = r.get("message") or ("OK" if r.get("ok") else "FAIL")
                payload.append((method, when, status[:500], machine))

            if not writing():
                msg = f"read-only, would append {len(payload)} row(s) for {machine}"
                logger.info("timer_log_sync: %s", msg)
                return {"ok": True, "message": msg, "pending": len(payload)}

            if payload:
                sql = f"""
                    INSERT INTO {table}
                        (method, log_datetime, status, machine)
                    VALUES (%s, %s, %s, %s)
                """
                cur.executemany(sql, payload)

            # Prune to KEEP_ROWS newest overall
            cur.execute(
                f"""
                DELETE FROM {table}
                WHERE id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM {table}
                        ORDER BY log_datetime DESC
                        LIMIT {KEEP_ROWS}
                    ) AS keep
                )
                """
            )
            pruned = cur.rowcount

        conn.commit()
        msg = f"timer_log_sync: appended {len(payload)} row(s) for {machine}, pruned {pruned}"
        logger.info(msg)
        return {"ok": True, "message": msg, "pending": len(payload)}
    except Exception as e:
        logger.exception("timer_log_sync failed")
        return {"ok": False, "message": str(e)}
    finally:
        conn.close()
