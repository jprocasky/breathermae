"""Lease and instance heartbeat for timer redundancy.

One lease row decides who may run jobs. Instance rows say who is alive.
A primary never steals a live lease. A secondary claims only after expiry
plus a grace period. A secondary that holds the lease will hand it back
to a healthy primary after handback_minutes.
"""
from __future__ import annotations

import json
import logging
import uuid
from datetime import datetime, timedelta
from pathlib import Path

from jobs.tag_snapshot import PREFIX, _connect

logger = logging.getLogger("lease")
ROOT = Path(__file__).resolve().parent
SETTINGS = ROOT / "settings.json"
VERSION = "0.3.0"
LEASE_SECONDS = 180
GRACE_SECONDS = 120
OFFLINE_SECONDS = 180
DEFAULT_HANDBACK_MINUTES = 60


def load_settings() -> dict:
    if not SETTINGS.exists():
        data = {"instance_id": str(uuid.uuid4()), "name": "timer-1", "role": "primary"}
        SETTINGS.write_text(json.dumps(data, indent=2), encoding="utf-8")
        return data
    data = json.loads(SETTINGS.read_text(encoding="utf-8"))
    if not data.get("instance_id"):
        data["instance_id"] = str(uuid.uuid4())
        save_settings(data)
    if "handback_minutes" not in data:
        data["handback_minutes"] = DEFAULT_HANDBACK_MINUTES
    return data


def save_settings(data: dict) -> None:
    SETTINGS.write_text(json.dumps(data, indent=2), encoding="utf-8")


def _ensure(conn) -> None:
    with conn.cursor() as cur:
        cur.execute(
            f"""
            CREATE TABLE IF NOT EXISTS {PREFIX}bm_timer_instances (
                instance_id VARCHAR(64) PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                role VARCHAR(20) NOT NULL,
                version VARCHAR(40) NULL,
                last_seen DATETIME NOT NULL,
                restart_required TINYINT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """
        )
        # Add columns on existing tables.
        for col, ddl in (
            ("restart_required", "TINYINT NOT NULL DEFAULT 0"),
            ("paused", "TINYINT NOT NULL DEFAULT 0"),
        ):
            try:
                cur.execute(
                    f"ALTER TABLE {PREFIX}bm_timer_instances ADD COLUMN {col} {ddl}"
                )
            except Exception:
                pass  # already exists
        cur.execute(
            f"""
            CREATE TABLE IF NOT EXISTS {PREFIX}bm_timer_lease (
                id TINYINT PRIMARY KEY,
                owner_id VARCHAR(64) NULL,
                owner_name VARCHAR(80) NULL,
                expires DATETIME NULL,
                taken_at DATETIME NULL,
                emergency_pause TINYINT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """
        )
        try:
            cur.execute(
                f"ALTER TABLE {PREFIX}bm_timer_lease ADD COLUMN emergency_pause TINYINT NOT NULL DEFAULT 0"
            )
        except Exception:
            pass
        cur.execute(f"INSERT IGNORE INTO {PREFIX}bm_timer_lease (id) VALUES (1)")
    conn.commit()


def heartbeat(paused: bool = False) -> dict:
    """Write this instance's row and renew or claim the lease. Returns status."""
    settings = load_settings()
    now = datetime.utcnow()
    expires = now + timedelta(seconds=LEASE_SECONDS)
    needs_restart = (ROOT / "restart.required").exists()
    conn = _connect()
    try:
        _ensure(conn)
        with conn.cursor() as cur:
            cur.execute(
                f"""
                INSERT INTO {PREFIX}bm_timer_instances
                    (instance_id, name, role, version, last_seen, restart_required, paused)
                VALUES (%s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name), role = VALUES(role),
                    version = VALUES(version), last_seen = VALUES(last_seen),
                    restart_required = VALUES(restart_required),
                    paused = VALUES(paused)
                """,
                (settings["instance_id"], settings["name"], settings["role"],
                 VERSION, now, 1 if needs_restart else 0,
                 1 if paused else 0),
            )
            # Renew only when not paused. A paused instance keeps heartbeating
            # but must not hold the lease.
            held = False
            if not paused:
                cur.execute(
                    f"""
                    UPDATE {PREFIX}bm_timer_lease
                    SET expires = %s
                    WHERE id = 1 AND owner_id = %s
                    """,
                    (expires, settings["instance_id"]),
                )
                held = cur.rowcount > 0
            else:
                # Clear ownership so a standby can claim.
                cur.execute(
                    f"""
                    UPDATE {PREFIX}bm_timer_lease
                    SET owner_id = NULL, owner_name = NULL, expires = NULL, taken_at = NULL
                    WHERE id = 1 AND owner_id = %s
                    """,
                    (settings["instance_id"],),
                )
            if not held and not paused:
                cutoff = now if settings.get("role") == "primary" else now - timedelta(seconds=GRACE_SECONDS)
                cur.execute(
                    f"""
                    UPDATE {PREFIX}bm_timer_lease
                    SET owner_id = %s, owner_name = %s, expires = %s, taken_at = %s
                    WHERE id = 1 AND (owner_id IS NULL OR expires IS NULL OR expires < %s)
                    """,
                    (settings["instance_id"], settings["name"], expires, now, cutoff),
                )
                held = cur.rowcount > 0
            # Hand lease back to a healthy primary after the configured delay.
            if held and settings.get("role") == "secondary":
                minutes = int(settings.get("handback_minutes") or DEFAULT_HANDBACK_MINUTES)
                cur.execute(
                    f"SELECT taken_at FROM {PREFIX}bm_timer_lease WHERE id = 1 AND owner_id = %s",
                    (settings["instance_id"],),
                )
                lease_row = cur.fetchone() or {}
                taken = lease_row.get("taken_at")
                if isinstance(taken, datetime) and (now - taken) >= timedelta(minutes=minutes):
                    cur.execute(
                        f"""
                        SELECT last_seen, paused FROM {PREFIX}bm_timer_instances
                        WHERE role = 'primary' AND instance_id <> %s
                        ORDER BY last_seen DESC LIMIT 1
                        """,
                        (settings["instance_id"],),
                    )
                    primary = cur.fetchone()
                    if primary and not primary.get("paused"):
                        last = primary.get("last_seen")
                        if isinstance(last, datetime) and (now - last) < timedelta(seconds=OFFLINE_SECONDS):
                            cur.execute(
                                f"""
                                UPDATE {PREFIX}bm_timer_lease
                                SET expires = %s
                                WHERE id = 1 AND owner_id = %s
                                """,
                                (now - timedelta(seconds=1), settings["instance_id"]),
                            )
                            held = False
                            logger.info("Handed lease back to primary after %s min", minutes)
        conn.commit()
        return {"holding": held, "name": settings["name"], "role": settings["role"]}
    finally:
        conn.close()




def set_emergency_pause(on: bool) -> None:
    """Set or clear the shared emergency pause. All instances honor it."""
    conn = _connect()
    try:
        _ensure(conn)
        with conn.cursor() as cur:
            cur.execute(
                f"UPDATE {PREFIX}bm_timer_lease SET emergency_pause = %s WHERE id = 1",
                (1 if on else 0,),
            )
            if on:
                # Force the current holder to drop the lease.
                cur.execute(
                    f"""
                    UPDATE {PREFIX}bm_timer_lease
                    SET owner_id = NULL, owner_name = NULL,
                        expires = NULL, taken_at = NULL
                    WHERE id = 1
                    """
                )
        conn.commit()
        logger.info("Emergency pause %s", "ON" if on else "OFF")
    finally:
        conn.close()


def emergency_paused() -> bool:
    conn = _connect()
    try:
        _ensure(conn)
        with conn.cursor() as cur:
            cur.execute(
                f"SELECT emergency_pause FROM {PREFIX}bm_timer_lease WHERE id = 1"
            )
            row = cur.fetchone() or {}
        return bool(row.get("emergency_pause"))
    finally:
        conn.close()


def holding_lease() -> bool:
    settings = load_settings()
    conn = _connect()
    try:
        _ensure(conn)
        with conn.cursor() as cur:
            cur.execute(
                f"SELECT owner_id, expires FROM {PREFIX}bm_timer_lease WHERE id = 1"
            )
            row = cur.fetchone()
        if not row or row.get("owner_id") != settings["instance_id"]:
            return False
        expires = row.get("expires")
        return isinstance(expires, datetime) and expires > datetime.utcnow()
    finally:
        conn.close()


def release_lease() -> None:
    settings = load_settings()
    conn = _connect()
    try:
        _ensure(conn)
        with conn.cursor() as cur:
            cur.execute(
                f"""
                UPDATE {PREFIX}bm_timer_lease
                SET expires = %s
                WHERE id = 1 AND owner_id = %s
                """,
                (datetime.utcnow() - timedelta(seconds=1), settings["instance_id"]),
            )
        conn.commit()
    finally:
        conn.close()


def instance_status() -> list[dict]:
    now = datetime.utcnow()
    conn = _connect()
    try:
        _ensure(conn)
        with conn.cursor() as cur:
            cur.execute(f"SELECT owner_id FROM {PREFIX}bm_timer_lease WHERE id = 1")
            lease = cur.fetchone() or {}
            cur.execute(
                f"""
                SELECT instance_id, name, role, version, last_seen, restart_required, paused
                FROM {PREFIX}bm_timer_instances
                ORDER BY name
                """
            )
            rows = cur.fetchall()
        owner = lease.get("owner_id")
        out = []
        for row in rows:
            seen = row.get("last_seen")
            age = (now - seen).total_seconds() if isinstance(seen, datetime) else 9999
            if age > OFFLINE_SECONDS:
                status = "red"
            elif row.get("paused"):
                status = "blue"
            elif row["instance_id"] == owner:
                status = "green"
            else:
                status = "yellow"
            out.append({
                "name": row["name"],
                "role": row["role"],
                "version": row.get("version") or "",
                "last_seen": seen.strftime("%Y-%m-%d %H:%M:%S") if isinstance(seen, datetime) else "—",
                "status": status,
                "mine": row["instance_id"] == load_settings()["instance_id"],
                "restart_required": bool(row.get("restart_required")),
            })
        return out
    finally:
        conn.close()
