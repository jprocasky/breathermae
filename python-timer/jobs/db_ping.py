"""Simple DB connectivity check. First real job to prove the path."""
import os
import logging
from datetime import datetime

logger = logging.getLogger(__name__)

def run():
    """Connect to SiteGround MariaDB and return a simple status."""
    try:
        import pymysql
    except ImportError:
        msg = "pymysql not installed"
        logger.error(msg)
        return {"ok": False, "message": msg}

    host = os.environ.get("WP_DB_HOST", "127.0.0.1")
    port = int(os.environ.get("WP_DB_PORT", "3306"))
    user = os.environ.get("WP_DB_USER", "")
    password = os.environ.get("WP_DB_PASSWORD", "")
    database = os.environ.get("WP_DB_NAME", "")

    if not user or not database:
        msg = "Missing WP_DB_USER or WP_DB_NAME in environment"
        logger.warning(msg)
        return {"ok": False, "message": msg}

    try:
        conn = pymysql.connect(
            host=host,
            port=port,
            user=user,
            password=password,
            database=database,
            connect_timeout=10,
            charset="utf8mb4",
        )
        with conn.cursor() as cur:
            cur.execute("SELECT 1 AS ping, NOW() AS server_time")
            row = cur.fetchone()
        conn.close()
        msg = f"OK — server time {row[1]}"
        logger.info(msg)
        return {"ok": True, "message": msg, "server_time": str(row[1])}
    except Exception as e:
        msg = f"DB error: {e}"
        logger.exception(msg)
        return {"ok": False, "message": msg}
