"""Place completed WooCommerce KBMO orders with the lab.

Reads vw_wc_orders_full, looks the buyer up in uls_ULS_CF_BIO, and posts
CreateShipping. Status lives in uls_bm_kbmo_orders so every timer instance
sees it. Write off logs only.
"""
from __future__ import annotations

import json
import logging
import os
from datetime import datetime, timedelta
from pathlib import Path

import requests

from jobctx import writing
from jobs.tag_snapshot import PREFIX, _connect

logger = logging.getLogger(__name__)
ROOT = Path(__file__).resolve().parents[1]
TEMPLATE = ROOT / "data" / "kbmo_shipping.json"
VIEW = os.environ.get("WC_ORDERS_VIEW", "vw_wc_orders_full")
BIO = f"{PREFIX}ULS_CF_BIO"
STATUS = f"{PREFIX}bm_kbmo_orders"
API = os.environ.get("KBMO_API_URL", "https://www.kbmoweb.com/kbmo/api/CreateShipping")
LEGACY_DAYS = int(os.environ.get("KBMO_LEGACY_DAYS", "2"))
NO_CLIENT_TEMPLATE = 27

# Woo product id -> KBMO product. Add new labs products here.
PRODUCTS = {
    "2507": {"id": 17, "name": "FIT 176 Panel"},
    "2515": {"id": 23, "name": "Gut Barrier"},
}

# 1-based positions matching the Excel sheet columns on vw_wc_orders_full.
COL = {
    "order_id": 1,
    "status": 3,
    "bstreet": 9,
    "bstreet2": 10,
    "bcity": 11,
    "bst": 12,
    "bzip": 13,
    "email": 15,
    "first_name": 17,
    "last_name": 18,
    "sstreet": 20,
    "sstreet2": 21,
    "scity": 22,
    "sst": 23,
    "szip": 24,
    "phone": 26,
    "product_id": 29,
}


def _ensure(conn) -> None:
    with conn.cursor() as cur:
        cur.execute(
            f"""
            CREATE TABLE IF NOT EXISTS {STATUS} (
                order_id BIGINT PRIMARY KEY,
                user_email VARCHAR(191) NULL,
                product_id VARCHAR(20) NULL,
                status VARCHAR(40) NOT NULL,
                message TEXT NULL,
                placed_at DATETIME NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """
        )
    conn.commit()


def _s(row: dict, key: str) -> str:
    value = row.get(key)
    return "" if value is None else str(value).strip()


def _bio(conn, email: str) -> dict | None:
    sql = f"""
        SELECT id, email, f_first_name, f_last_name, f_sex, f_gender, f_dateofbirth
        FROM {BIO}
        WHERE LOWER(email) = %s
        ORDER BY updated_at DESC
        LIMIT 1
    """
    with conn.cursor() as cur:
        cur.execute(sql, (email.lower(),))
        return cur.fetchone()


def _status_of(conn, order_id: str) -> str:
    with conn.cursor() as cur:
        cur.execute(f"SELECT status FROM {STATUS} WHERE order_id = %s", (order_id,))
        row = cur.fetchone()
    return (row or {}).get("status") or ""


def _save_status(conn, order_id, email, product_id, status, message) -> None:
    placed = datetime.now() if status == "placed" else None
    with conn.cursor() as cur:
        cur.execute(
            f"""
            INSERT INTO {STATUS}
                (order_id, user_email, product_id, status, message, placed_at, updated_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                user_email = VALUES(user_email),
                product_id = VALUES(product_id),
                status = VALUES(status),
                message = VALUES(message),
                placed_at = COALESCE(VALUES(placed_at), placed_at),
                updated_at = VALUES(updated_at)
            """,
            (order_id, email, product_id, status, message[:500], placed, datetime.now()),
        )
    conn.commit()


def _payload(order: dict, bio: dict) -> dict:
    body = json.loads(TEMPLATE.read_text(encoding="utf-8"))
    product = PRODUCTS[order["product_id"]]
    dob = bio.get("f_dateofbirth")
    dob_s = dob.strftime("%Y-%m-%d") if hasattr(dob, "strftime") else (str(dob) if dob else "")
    values = {
        "ppid": f"BM{int(bio.get('id') or 0):07d}" if False else "",
        "rid": order["order_id"],
        "productid": str(product["id"]),
        "productname": product["name"],
        "first_name": order["first_name"] or (bio.get("f_first_name") or ""),
        "last_name": order["last_name"] or (bio.get("f_last_name") or ""),
        "dob": dob_s,
        "gender": bio.get("f_sex") or bio.get("f_gender") or "",
        "phone": order["phone"],
        "email": order["email"],
        "bstreet": order["bstreet"],
        "bstreet2": order["bstreet2"],
        "bcity": order["bcity"],
        "bst": order["bst"],
        "bzip": order["bzip"],
        "sstreet": order["sstreet"] or order["bstreet"],
        "sstreet2": order["sstreet2"] or order["bstreet2"],
        "scity": order["scity"] or order["bcity"],
        "sst": order["sst"] or order["bst"],
        "szip": order["szip"] or order["bzip"],
    }
    # partnerPatientID uses the bio row id, same idea as Excel's BM0000000.
    text = json.dumps(body)
    for key, value in values.items():
        text = text.replace(f"[{key}]", str(value or ""))
    payload = json.loads(text)
    payload["productID"] = product["id"]
    payload["partnerPatientID"] = f"BM{int(bio.get('id') or 0):07d}"
    return payload


def _post(payload: dict) -> tuple[bool, str]:
    key = os.environ.get("KBMO_API_KEY", "")
    if not key:
        return False, "KBMO_API_KEY is not set"
    r = requests.post(
        API,
        json=payload,
        headers={"x-api-key": key, "Accept": "*/*"},
        timeout=30,
    )
    if r.status_code != 200:
        return False, f"{r.status_code}: {r.text[:200]}"
    return True, "sent"


def run():
    do_write = writing()
    logger.info("kbmo_orders starting (%s)", "write" if do_write else "read-only")
    try:
        conn = _connect()
    except Exception as e:
        logger.exception("DB connect failed")
        return {"ok": False, "message": f"connect: {e}"}
    try:
        _ensure(conn)
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT order_id, order_date, order_status, product_id,
                       billing_email, billing_first_name, billing_last_name, billing_phone,
                       billing_address_1, billing_address_2, billing_city, billing_state, billing_postcode,
                       shipping_first_name, shipping_last_name, shipping_phone,
                       shipping_address_1, shipping_address_2, shipping_city, shipping_state, shipping_postcode
                FROM {VIEW}
                WHERE order_status LIKE %s
                """,
                ("%complete%",),
            )
            rows = cur.fetchall()

        pending = placed = skipped = 0
        seen = set()
        for row in rows:
            product_id = _s(row, "product_id")
            if product_id not in PRODUCTS:
                continue
            order_id = _s(row, "order_id")
            if order_id in seen:
                continue
            seen.add(order_id)
            existing = _status_of(conn, order_id)
            if existing in ("placed", "legacy"):
                skipped += 1
                continue
            email = _s(row, "billing_email").lower()
            order_date = row.get("order_date")
            if isinstance(order_date, datetime) and order_date < datetime.now() - timedelta(days=LEGACY_DAYS):
                skipped += 1
                logger.info("%s legacy (ordered %s), not sent", order_id, order_date.date())
                if do_write:
                    _save_status(conn, order_id, email, product_id, "legacy", "Ordered before cutoff, not sent")
                continue
            order = {
                "order_id": order_id,
                "product_id": product_id,
                "email": email,
                "first_name": _s(row, "shipping_first_name") or _s(row, "billing_first_name"),
                "last_name": _s(row, "shipping_last_name") or _s(row, "billing_last_name"),
                "phone": _s(row, "shipping_phone") or _s(row, "billing_phone"),
                "bstreet": _s(row, "billing_address_1"),
                "bstreet2": _s(row, "billing_address_2"),
                "bcity": _s(row, "billing_city"),
                "bst": _s(row, "billing_state"),
                "bzip": _s(row, "billing_postcode"),
                "sstreet": _s(row, "shipping_address_1"),
                "sstreet2": _s(row, "shipping_address_2"),
                "scity": _s(row, "shipping_city"),
                "sst": _s(row, "shipping_state"),
                "szip": _s(row, "shipping_postcode"),
            }
            bio = _bio(conn, email)
            if not bio:
                pending += 1
                logger.info("%s no client info (%s)", order_id, email)
                if do_write:
                    _save_status(conn, order_id, email, product_id, "no_client", "No client info")
                    from emailer import send
                    result = send(
                        email, NO_CLIENT_TEMPLATE, trigger="kbmo_no_client",
                        first_name=order["first_name"],
                    )
                    logger.info("%s template %s: %s", order_id, NO_CLIENT_TEMPLATE, result.get("message"))
                else:
                    logger.info("%s would email template %s", order_id, NO_CLIENT_TEMPLATE)
                continue
            payload = _payload(order, bio)
            if not do_write:
                pending += 1
                logger.info("%s would place %s for %s", order_id, PRODUCTS[product_id]["name"], email)
                continue
            ok, detail = _post(payload)
            if ok:
                placed += 1
                _save_status(conn, order_id, email, product_id, "placed", detail)
                logger.info("%s placed", order_id)
            else:
                pending += 1
                _save_status(conn, order_id, email, product_id, "error", detail)
                logger.error("%s failed: %s", order_id, detail)
        if do_write:
            msg = f"kbmo_orders: placed {placed}, pending {pending}, skipped {skipped}"
        else:
            msg = f"kbmo_orders: {pending} would be placed, {skipped} already placed (read-only)"
        logger.info(msg)
        return {"ok": True, "message": msg, "pending": pending}
    except Exception as e:
        logger.exception("kbmo_orders failed")
        return {"ok": False, "message": str(e)}
    finally:
        conn.close()
