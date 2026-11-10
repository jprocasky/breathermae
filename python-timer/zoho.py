"""Zoho CRM helper for Multi_Tags updates.

WP Fusion treats Zoho as the source of truth. Writing only usermeta
gets overwritten on the next sync, so tag changes must also PATCH
the Contact Multi_Tags field.

Env:
  ZOHO_CLIENT_ID
  ZOHO_CLIENT_SECRET
  ZOHO_REFRESH_TOKEN
  ZOHO_ACCOUNTS_URL   (default https://accounts.zoho.com)
  ZOHO_API_URL        (default https://www.zohoapis.com)
"""
from __future__ import annotations

import logging
import os
from datetime import datetime, timedelta

import requests

logger = logging.getLogger(__name__)

_token: str | None = None
_token_exp: datetime | None = None


def _env(name: str, default: str = "") -> str:
    return os.environ.get(name, default).strip()


def _configured() -> bool:
    return bool(_env("ZOHO_CLIENT_ID") and _env("ZOHO_CLIENT_SECRET") and _env("ZOHO_REFRESH_TOKEN"))


def _access_token() -> str:
    """Return a cached access token, refreshing if needed."""
    global _token, _token_exp
    if _token and _token_exp and datetime.now() < _token_exp:
        return _token
    url = _env("ZOHO_ACCOUNTS_URL", "https://accounts.zoho.com") + "/oauth/v2/token"
    r = requests.post(
        url,
        data={
            "grant_type": "refresh_token",
            "client_id": _env("ZOHO_CLIENT_ID"),
            "client_secret": _env("ZOHO_CLIENT_SECRET"),
            "refresh_token": _env("ZOHO_REFRESH_TOKEN"),
        },
        timeout=20,
    )
    r.raise_for_status()
    data = r.json()
    _token = data["access_token"]
    # Zoho tokens last ~1 hour; refresh a bit early.
    _token_exp = datetime.now() + timedelta(seconds=int(data.get("expires_in", 3600)) - 60)
    return _token


def contact_id(email: str) -> str | None:
    """Look up the Zoho contact ID for an email. None if not found."""
    url = _env("ZOHO_API_URL", "https://www.zohoapis.com") + "/crm/v2/Contacts/search"
    r = requests.get(
        url,
        params={"email": email},
        headers={"Authorization": f"Zoho-oauthtoken {_access_token()}"},
        timeout=20,
    )
    if r.status_code == 204:
        return None
    r.raise_for_status()
    data = r.json()
    rows = data.get("data") or []
    return rows[0].get("id") if rows else None


def set_multi_tags(email: str, tags: list[str]) -> bool:
    """PATCH Multi_Tags on the contact. Returns True if updated."""
    if not _configured():
        logger.warning("Zoho not configured; skipped Multi_Tags for %s", email)
        return False
    cid = contact_id(email)
    if not cid:
        logger.warning("Zoho contact not found for %s", email)
        return False
    url = _env("ZOHO_API_URL", "https://www.zohoapis.com") + "/crm/v2/Contacts"
    body = {"data": [{"id": cid, "Multi_Tags": tags}]}
    r = requests.patch(
        url,
        json=body,
        headers={
            "Authorization": f"Zoho-oauthtoken {_access_token()}",
            "Content-Type": "application/json",
        },
        timeout=20,
    )
    if r.status_code == 200:
        logger.info("Zoho Multi_Tags updated for %s (%d tags)", email, len(tags))
        return True
    logger.warning("Zoho Multi_Tags failed for %s: %s", email, r.text[:200])
    return False
