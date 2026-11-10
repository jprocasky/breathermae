"""Send a templated email through the WordPress custom-email endpoint.

Event-driven first slice. Jobs call send(). The write flag gates the POST.
"""
from __future__ import annotations

import json
import logging
import os
from datetime import datetime
from pathlib import Path

import requests

from jobctx import writing
from jobs.tag_snapshot import PREFIX, _connect, _normalize_tags

logger = logging.getLogger(__name__)
DATA = Path(__file__).resolve().parent / "data" / "email_templates.json"
API_URL = os.environ.get(
    "EMAIL_API_URL", "https://breathermae.com/wp-json/custom-email/v1/send"
)

HEADER = (
    "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Welcome to Breathermae</title></head>"
    "<body style='margin:0; padding:0; background-color:#f5f1f1; font-family:Arial, sans-serif;'>"
    "<table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>"
    "<tr><td align='center' valign='top'>"
    "<table role='presentation' width='100%' style='max-width:900px;' cellspacing='0' cellpadding='0' border='0'>"
    "<tr><td width='100%' valign='top' style='padding:0;' align='center'>"
    "<div style='background-image:url(https://breathermae.com/wp-content/uploads/2026/06/Article-5-Cover-Lightened.jpg);"
    " background-size:cover; background-position:center; padding:20px;'>"
    "<table role='presentation' width='600' cellspacing='0' cellpadding='0' border='0'"
    " style='background:#ffffff; border-radius:8px;'>"
    "<tr><td align='center' style='padding:20px;'>"
    "<img style='height:75px;width:auto;display:block;' "
    "src='https://breathermae.com/wp-content/uploads/2025/11/breathermae-6_1_black-on-white-background-scaled-e1763257543312.jpg' "
    "width='150' alt='Breathermae'>"
    "</td></tr>"
    "<tr><td style='padding:20px;'>"
    "<p><i>Your Journey Toward Greater Awareness, Insight, and Well-Being Begins Here</i></p>"
    "</td></tr>"
)
FOOTER = (
    "<tr><td align='center' style='padding:30px;'>"
    "<p>Breathermae, Inc.<br>"
    "<a href='https://breathermae.com' style='color:#001D50;'>www.breathermae.com</a><br>"
    "&copy; 2026 Breathermae, Inc. All Rights Reserved.</p>"
    "</td></tr></table></div></td></tr></table></td></tr></table></body></html>"
)


BLOCK_TAG = "NOMAIL"


def _blocked(conn, email: str) -> bool:
    sql = f"""
        SELECT um.meta_value
        FROM {PREFIX}users u
        JOIN {PREFIX}usermeta um ON um.user_id = u.ID
        WHERE u.user_email = %s AND um.meta_key IN ('zoho_tags', 'multi_tags')
    """
    with conn.cursor() as cur:
        cur.execute(sql, (email,))
        for row in cur.fetchall():
            if BLOCK_TAG in {t.upper() for t in _normalize_tags(row.get("meta_value"))}:
                return True
    return False


def load_template(template_id: int) -> dict | None:
    templates = json.loads(DATA.read_text(encoding="utf-8"))
    for t in templates:
        if int(t.get("id")) == int(template_id):
            return t
    return None


def _cta(url: str, label: str) -> str:
    return (
        f"<p style='text-align:center;margin:24px 0;'>"
        f"<a href='{url}' style='background:#001D50;color:#ffffff;text-decoration:none;"
        f"padding:12px 22px;border-radius:6px;display:inline-block;'>{label}</a></p>"
    )


def render(template: dict, first_name: str) -> tuple[str, str]:
    body = template.get("body") or ""
    body = body.replace("[first_name]", first_name or "there")
    cta = ""
    if template.get("cta_url"):
        cta = _cta(template["cta_url"], template.get("cta_label") or "Continue")
    body = body.replace("[cta_link]", cta)
    html = HEADER + "<tr><td align='left' style='padding:20px;'>" + body + "</td></tr>" + FOOTER
    return template.get("subject") or "", html


def _ensure_log(conn) -> None:
    sql = f"""
        CREATE TABLE IF NOT EXISTS {PREFIX}email_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_email VARCHAR(190) NOT NULL,
            template_id INT NOT NULL,
            subject VARCHAR(255) NULL,
            trigger_name VARCHAR(64) NULL,
            status VARCHAR(32) NULL,
            sent_at DATETIME NOT NULL,
            KEY email_template (user_email, template_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """
    with conn.cursor() as cur:
        cur.execute(sql)
    conn.commit()


def already_sent(conn, email: str, template_id: int, within_days: int | None = None) -> bool:
    """True if this template was sent to the user.

    within_days limits the check to recent sends (None = any time).
    """
    sql = f"""
        SELECT 1 FROM {PREFIX}email_log
        WHERE user_email = %s AND template_id = %s
          AND trigger_name <> 'test'
          AND status = 'sent'
    """
    params: list = [email, template_id]
    if within_days is not None:
        sql += " AND sent_at >= DATE_SUB(NOW(), INTERVAL %s DAY)"
        params.append(within_days)
    sql += " LIMIT 1"
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return cur.fetchone() is not None


def _log(conn, email: str, template_id: int, subject: str, trigger: str, status: str) -> None:
    sql = f"""
        INSERT INTO {PREFIX}email_log
            (user_email, template_id, subject, trigger_name, status, sent_at)
        VALUES (%s, %s, %s, %s, %s, %s)
    """
    with conn.cursor() as cur:
        cur.execute(
            sql,
            (email, template_id, subject[:250], trigger, status, datetime.now().strftime("%Y-%m-%d %H:%M:%S")),
        )
    conn.commit()


def _post(to: str, subject: str, body: str, bcc: str = "") -> tuple[bool, str]:
    key = os.environ.get("EMAIL_API_KEY", "")
    if not key:
        return False, "EMAIL_API_KEY is not set"
    payload = {"to": to, "subject": subject, "body": body}
    if bcc:
        payload["bcc"] = bcc
    try:
        r = requests.post(
            API_URL,
            json=payload,
            headers={"x-api-key": key, "Content-Type": "application/json"},
            timeout=30,
        )
    except requests.RequestException as e:
        return False, str(e)
    if r.status_code == 200:
        return True, "sent"
    return False, f"{r.status_code}: {r.text[:200]}"


def send(email: str, template_id: int, trigger: str = "event", first_name: str = "", allow_resend: bool = False) -> dict:
    """Send one template. Read-only unless the job write flag is on.

    trigger='test' bypasses the already-sent check and is logged as a test.
    allow_resend=True also bypasses it, for cases gated by empty fields
    (a reset clears the fields, the next completion should mail again).
    """
    template = load_template(template_id)
    if not template:
        return {"ok": False, "message": f"unknown template {template_id}"}

    subject, html = render(template, first_name or "there")
    is_test = trigger == "test"

    conn = _connect()
    try:
        _ensure_log(conn)
        if _blocked(conn, email):
            msg = f"skipped {email}: {BLOCK_TAG}"
            logger.info(msg)
            return {"ok": True, "message": msg, "pending": 0}
        # allow_resend still respects a 3-day floor so a stuck reset
        # cannot mail the same template every cycle.
        window = 3 if allow_resend else None
        if not is_test and already_sent(conn, email, template_id, within_days=window):
            msg = f"already sent template {template_id} to {email}"
            logger.info(msg)
            return {"ok": True, "message": msg, "pending": 0}

        if not writing():
            msg = f"read-only, would send template {template_id} to {email}"
            logger.info(msg)
            return {"ok": True, "message": msg, "pending": 1}

        ok, detail = _post(email, subject, html, (template.get("bcc") or "").strip())
        status = "sent" if ok else "fail"
        _log(conn, email, template_id, subject, trigger, status)
        msg = f"template {template_id} to {email}: {detail}"
        logger.info(msg)
        return {"ok": ok, "message": msg, "pending": 0 if ok else 1}
    finally:
        conn.close()
