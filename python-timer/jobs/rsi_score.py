"""RSI scoring.

Computes the UpdateRSIScores fields for complete rows that have not been
scored. Writes the row and sends template 20 only when the job write flag
is on. Otherwise it logs what it would do.
"""
from __future__ import annotations

import logging

from emailer import send
from jobctx import writing
from jobs.tag_snapshot import PREFIX, _connect

logger = logging.getLogger(__name__)
RSI_READY_TEMPLATE = 20


def _band_label(score: float, kind: str) -> str:
    if kind == "rsi":
        labels = ("Maintain", "Monitor", "Correct", "Stabilize")
        colors = ("#92996c", "#f2c94c", "#e97132", "#cc5854")
    else:
        labels = ("Peak", "Taxed", "Strained", "Depleted")
        colors = ("#92996c", "#f2c94c", "#e97132", "#cc5854")
    if score <= 0.24:
        i = 0
    elif score <= 0.49:
        i = 1
    elif score <= 0.74:
        i = 2
    else:
        i = 3
    return f"<SPAN style='color:{colors[i]};'>{labels[i]}</SPAN>"


def _readiness_text(n: int) -> str:
    if n < 3:
        return "<SPAN style='color:#cc5854;'>Stabilization Needed</SPAN>"
    if n < 5:
        return "<SPAN style='color:#e97132;'>Recovery Priority</SPAN>"
    if n < 8:
        return "<SPAN style='color:#f2c94c;'>Functional but Strained</SPAN>"
    return "<SPAN style='color:#92996c;'>Strong Capacity</SPAN>"


def score_row(r11, r12, r12_s6, readiness) -> dict:
    si = (float(r11) / 100) * 0.85
    pi = (float(r12) / 100) * 0.15
    c_adj = (float(r12_s6) / 100) / 4
    r_score = si + c_adj
    p_score = pi + c_adj
    master = ((r_score * 0.85) + (p_score * 0.15)) * 100
    return {
        "R11_final": round(r_score * 100, 4),
        "R12_final": round(p_score * 100, 4),
        "master_score": round(master, 4),
        "R11_engagement": _band_label(r_score, "rsi"),
        "R12_engagement": _band_label(p_score, "perf"),
        "mode": _band_label(master / 100, "rsi"),
        "R12_Notes": _readiness_text(int(readiness)),
    }


def _first_name(conn, email: str) -> str:
    sql = f"""
        SELECT um.meta_value AS first_name, u.display_name
        FROM {PREFIX}users u
        LEFT JOIN {PREFIX}usermeta um
          ON um.user_id = u.ID AND um.meta_key = 'first_name'
        WHERE u.user_email = %s
        LIMIT 1
    """
    with conn.cursor() as cur:
        cur.execute(sql, (email,))
        row = cur.fetchone()
    if not row:
        return email.split("@")[0]
    return (row.get("first_name") or row.get("display_name") or email.split("@")[0]).strip()


def _write_row(conn, row_id, scored: dict) -> None:
    sql = f"""
        UPDATE {PREFIX}bm_rsi_results
        SET R11_final = %s,
            R12_final = %s,
            master_score = %s,
            R11_engagement = %s,
            R12_engagement = %s,
            mode = %s,
            R12_Notes = %s
        WHERE id = %s
    """
    with conn.cursor() as cur:
        cur.execute(
            sql,
            (
                scored["R11_final"],
                scored["R12_final"],
                scored["master_score"],
                scored["R11_engagement"],
                scored["R12_engagement"],
                scored["mode"],
                scored["R12_Notes"],
                row_id,
            ),
        )
    conn.commit()


def run():
    mode = "write" if writing() else "read-only"
    logger.info("rsi_score starting (%s)", mode)
    sql = f"""
        SELECT id, user_email, R11, R12, R12_S6, readiness_score, master_score
        FROM {PREFIX}bm_rsi_results
        WHERE R11 IS NOT NULL AND R11 <> ''
          AND R12 IS NOT NULL AND R12 <> ''
          AND R12_S6 IS NOT NULL AND R12_S6 <> ''
          AND readiness_score IS NOT NULL AND readiness_score <> ''
          AND (master_score IS NULL OR master_score = 0 OR master_score = '')
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
        if not rows:
            msg = "rsi_score: no unscored complete rows"
            logger.info(msg)
            return {"ok": True, "message": msg, "pending": 0}

        written = 0
        mailed = 0
        for row in rows:
            scored = score_row(row["R11"], row["R12"], row["R12_S6"], row["readiness_score"])
            email = row["user_email"]
            logger.info(
                "%s id=%s master=%s R11_final=%s R12_final=%s",
                email, row["id"], scored["master_score"],
                scored["R11_final"], scored["R12_final"],
            )
            if not writing():
                continue
            _write_row(conn, row["id"], scored)
            written += 1
            result = send(email, RSI_READY_TEMPLATE, trigger="rsi_score", first_name=_first_name(conn, email))
            if result.get("ok") and "sent" in (result.get("message") or ""):
                mailed += 1

        if writing():
            msg = f"rsi_score: scored {written}, emailed {mailed}"
        else:
            msg = f"rsi_score: {len(rows)} row(s) would be scored"
        logger.info(msg)
        return {"ok": True, "message": msg, "pending": len(rows) - written}
    except Exception as e:
        logger.exception("rsi_score failed")
        return {"ok": False, "message": str(e)}
    finally:
        conn.close()
