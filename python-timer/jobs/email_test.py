"""Send template 20 to TEST_EMAIL to prove the WordPress path.

Honors the write flag. Logged as trigger=test so it does not count as a user send.
"""
from __future__ import annotations

import logging
import os

from emailer import send

logger = logging.getLogger(__name__)


def run():
    to = os.environ.get("TEST_EMAIL", "")
    if not to:
        return {"ok": False, "message": "TEST_EMAIL is not set"}
    logger.info("email_test sending template 20 to %s", to)
    return send(to, 20, trigger="test", first_name="Test")
