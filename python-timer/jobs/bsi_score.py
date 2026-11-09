"""BSI scoring.

Computes the UpdateBSIScores fields. Governor is F9. Writes the row and
sends template 21 only when the job write flag is on.
"""
from __future__ import annotations

import logging

from emailer import send
from jobctx import writing
from jobs.rsi_score import _first_name
from jobs.tag_snapshot import PREFIX, _connect

logger = logging.getLogger(__name__)
BSI_READY_TEMPLATE = 21


def _num(v):
    if v is None or v == "":
        return None
    try:
        return float(v)
    except (TypeError, ValueError):
        return None


def _mod_driver(d: float) -> float:
    if d <= 0.24:
        return 0.95
    if d <= 0.49:
        return 1.0
    if d <= 0.74:
        return 1.1
    return 1.2


def _mod_ac(g: float) -> float:
    if g <= 0.24:
        return 0.85
    if g <= 0.49:
        return 1.0
    if g <= 0.74:
        return 1.15
    return 1.3


def score_row(f) -> dict | None:
    """f is a dict of F1..F9 floats. Returns None if any are missing/zero."""
    keys = [f"F{i}" for i in range(1, 10)]
    vals = [_num(f.get(k)) for k in keys]
    if any(v is None or v == 0 for v in vals):
        return None
    F1, F2, F3, F4, F5, F6, F7, F8, F9 = vals
    m = F2 * 0.35 + F3 * 0.25 + F5 * 0.4
    d = F4 * 0.4 + F7 * 0.3 + F8 * 0.3
    g = F9  # governor — confirmed F9, not F5
    o = F1 * 0.6 + F6 * 0.4
    base = m * 0.55 + o * 0.45
    final = base * _mod_driver(d) * _mod_ac(g)
    return {
        "mscore": round(m, 4),
        "dscore": round(d, 4),
        "gscore": round(g, 4),
        "oscore": round((d + m) / 2, 4),
        "base_sci_score": round(base, 4),
        "final_sci_score": round(final, 4),
    }


def _write_row(conn, row_id, scored: dict) -> None:
    sql = f"""
        UPDATE {PREFIX}bm_bsi_results
        SET mscore = %s,
            dscore = %s,
            gscore = %s,
            oscore = %s,
            base_sci_score = %s,
            final_sci_score = %s
        WHERE id = %s
    """
    with conn.cursor() as cur:
        cur.execute(
            sql,
            (
                scored["mscore"],
                scored["dscore"],
                scored["gscore"],
                scored["oscore"],
                scored["base_sci_score"],
                scored["final_sci_score"],
                row_id,
            ),
        )
    conn.commit()


def run():
    mode = "write" if writing() else "read-only"
    logger.info("bsi_score starting (%s)", mode)
    sql = f"""
        SELECT id, user_email, F1, F2, F3, F4, F5, F6, F7, F8, F9, base_sci_score
        FROM {PREFIX}bm_bsi_results
        WHERE (base_sci_score IS NULL OR base_sci_score = 0 OR base_sci_score = '')
          AND F1 IS NOT NULL AND F1 <> '' AND F1 <> 0
          AND F9 IS NOT NULL AND F9 <> '' AND F9 <> 0
    """
    try:
        conn = _connect()
    except Exception as e:
        logger.exception("DB connect failed")
        return {"ok": False, "message": f"connect: {e}"}

    try:
        with conn.cursor() as cur:
            cur.execute(sql)
            rows = cur.fetchall()
        pending = 0
        written = 0
        mailed = 0
        for row in rows:
            scored = score_row(row)
            if not scored:
                continue
            pending += 1
            email = row["user_email"]
            logger.info(
                "%s id=%s base=%s final=%s m=%s d=%s g=%s",
                email, row["id"], scored["base_sci_score"], scored["final_sci_score"],
                scored["mscore"], scored["dscore"], scored["gscore"],
            )
            if not writing():
                continue
            _write_row(conn, row["id"], scored)
            written += 1
            result = send(email, BSI_READY_TEMPLATE, trigger="bsi_score", first_name=_first_name(conn, email))
            if result.get("ok") and "sent" in (result.get("message") or ""):
                mailed += 1
        if not pending:
            msg = "bsi_score: no unscored complete rows"
        elif writing():
            msg = f"bsi_score: scored {written}, emailed {mailed}"
        else:
            msg = f"bsi_score: {pending} row(s) would be scored"
        logger.info(msg)
        return {"ok": True, "message": msg, "pending": pending - written}
    except Exception as e:
        logger.exception("bsi_score failed")
        return {"ok": False, "message": str(e)}
    finally:
        conn.close()
