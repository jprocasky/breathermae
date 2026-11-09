"""File-backed job scheduler with APScheduler.

Loads jobs from jobs.json, respects enabled flag and optional time windows,
writes run history to SQLite, and logs detailed status to the console.
"""
from __future__ import annotations

import json
import logging
import os
import sqlite3
import threading
from datetime import datetime, time as dtime
from pathlib import Path
from typing import Any

from apscheduler.schedulers.background import BackgroundScheduler
from apscheduler.triggers.interval import IntervalTrigger

logger = logging.getLogger("timer")

ROOT = Path(__file__).resolve().parent
JOBS_FILE = ROOT / "jobs.json"
DB_FILE = ROOT / "status.db"
RELOAD_INTERVAL_SEC = 30


def setup_logging():
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )


def init_db():
    conn = sqlite3.connect(DB_FILE)
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id TEXT NOT NULL,
            job_name TEXT,
            started_at TEXT NOT NULL,
            finished_at TEXT,
            ok INTEGER,
            message TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_runs_job ON runs(job_id, started_at DESC)"
    )
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS errors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            seen_at TEXT NOT NULL,
            source TEXT,
            message TEXT
        )
        """
    )
    conn.commit()
    conn.close()


def load_jobs() -> list[dict[str, Any]]:
    if not JOBS_FILE.exists():
        return []
    try:
        data = json.loads(JOBS_FILE.read_text(encoding="utf-8"))
        return data.get("jobs", [])
    except Exception as e:
        logger.error("Failed to load jobs.json: %s", e)
        return []


def in_window(job: dict) -> bool:
    start_s = job.get("window_start")
    end_s = job.get("window_end")
    if not start_s and not end_s:
        return True
    now = datetime.now().time()
    try:
        start = dtime.fromisoformat(start_s) if start_s else dtime.min
        end = dtime.fromisoformat(end_s) if end_s else dtime.max
    except ValueError:
        return True
    if start <= end:
        return start <= now <= end
    # overnight window
    return now >= start or now <= end


def record_error(source: str, message: str) -> None:
    conn = sqlite3.connect(DB_FILE)
    conn.execute(
        "INSERT INTO errors (seen_at, source, message) VALUES (?, ?, ?)",
        (datetime.now().isoformat(timespec="seconds"), source, message[:500]),
    )
    conn.execute("DELETE FROM errors WHERE id NOT IN (SELECT id FROM errors ORDER BY id DESC LIMIT 50)")
    conn.commit()
    conn.close()


def clear_errors() -> None:
    conn = sqlite3.connect(DB_FILE)
    conn.execute("DELETE FROM errors")
    conn.commit()
    conn.close()


def recent_errors(limit: int = 10) -> list[dict]:
    if not DB_FILE.exists():
        return []
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    rows = conn.execute(
        "SELECT seen_at, source, message FROM errors ORDER BY id DESC LIMIT ?",
        (limit,),
    ).fetchall()
    conn.close()
    return [dict(r) for r in rows]


def record_run(job_id: str, job_name: str, started: datetime, result: dict):
    finished = datetime.now()
    ok = 1 if result.get("ok") else 0
    message = result.get("message", "")[:500]
    conn = sqlite3.connect(DB_FILE)
    conn.execute(
        """
        INSERT INTO runs (job_id, job_name, started_at, finished_at, ok, message)
        VALUES (?, ?, ?, ?, ?, ?)
        """,
        (
            job_id,
            job_name,
            started.isoformat(timespec="seconds"),
            finished.isoformat(timespec="seconds"),
            ok,
            message,
        ),
    )
    conn.commit()
    conn.close()


def resolve_callable(spec: str):
    """spec is 'module.path:function'"""
    mod_name, func_name = spec.split(":")
    import importlib

    mod = importlib.import_module(mod_name)
    return getattr(mod, func_name)


def make_job_runner(job: dict, force: bool = False):
    job_id = job["id"]
    job_name = job.get("name", job_id)
    callable_spec = job.get("callable")

    def runner():
        if not force and not job.get("enabled", True):
            logger.info("Skipping disabled job %s", job_id)
            return
        if not force and not in_window(job):
            logger.info("Skipping %s — outside window", job_id)
            return
        started = datetime.now()
        write_on = bool(job.get("write", False))
        mode = "write" if write_on else "read-only"
        logger.info(
            "Starting %s (%s)%s [%s]",
            job_name,
            job_id,
            " [manual]" if force else "",
            mode,
        )
        from jobctx import set_write

        set_write(write_on)
        try:
            fn = resolve_callable(callable_spec)
            result = fn() or {}
            if not isinstance(result, dict):
                result = {"ok": True, "message": str(result)}
            result.setdefault("ok", True)
            result.setdefault("message", "completed")
        except Exception as e:
            logger.exception("Job %s failed", job_id)
            result = {"ok": False, "message": str(e)}
            record_error(job_id, str(e))
        record_run(job_id, job_name, started, result)
        level = logging.INFO if result.get("ok") else logging.ERROR
        logger.log(level, "Finished %s — %s", job_name, result.get("message"))

    return runner


class TimerApp:
    def __init__(self):
        self.scheduler = BackgroundScheduler(timezone="America/New_York")
        self._lock = threading.Lock()
        self._last_run: dict[str, datetime] = {}
        self.paused = False

    def pause(self):
        """Stop job execution but keep heartbeats. Release the lease so a standby can take over."""
        from lease import release_lease
        self.paused = True
        try:
            release_lease()
        except Exception:
            logger.exception("Lease release on pause failed")
        logger.info("Timer paused. Heartbeats continue.")

    def resume(self):
        self.paused = False
        logger.info("Timer resumed.")

    def start(self):
        init_db()
        flag = ROOT / "restart.required"
        if flag.exists():
            flag.unlink()
        self.scheduler.start()
        # Single tick walks jobs.json in list order so order is execution order.
        self.scheduler.add_job(
            self.tick,
            IntervalTrigger(seconds=60),
            id="__tick__",
            replace_existing=True,
            max_instances=1,
            coalesce=True,
        )
        logger.info("Timer started. Jobs run in list order. Watching %s", JOBS_FILE)

    def shutdown(self):
        self.scheduler.shutdown(wait=False)

    def tick(self):
        """Heartbeat always. Run due jobs only when not paused and holding the lease."""
        from lease import heartbeat

        try:
            state = heartbeat(paused=self.paused)
        except Exception as e:
            logger.exception("Lease heartbeat failed — skipping jobs")
            record_error("lease", str(e))
            return
        if self.paused:
            logger.info("Paused. Heartbeat only.")
            return
        if not state.get("holding"):
            logger.info("Standby (%s). Running local jobs only.", state.get("name"))
        with self._lock:
            now = datetime.now()
            for job in load_jobs():
                jid = job.get("id")
                if not jid or not job.get("enabled", True):
                    continue
                if not state.get("holding") and not job.get("local") and jid != "code_sync":
                    continue
                if not in_window(job):
                    continue
                minutes = int(job.get("interval_minutes") or 60)
                last = self._last_run.get(jid)
                if last is not None and (now - last).total_seconds() < minutes * 60:
                    continue
                logger.info("Due: %s (order preserved)", jid)
                make_job_runner(job)()
                self._last_run[jid] = datetime.now()

    def next_run_label(self, job: dict) -> str:
        jid = job.get("id")
        if not job.get("enabled", True):
            return "disabled"
        minutes = int(job.get("interval_minutes") or 60)
        last = self._last_run.get(jid)
        if last is None:
            return "due"
        nxt = last.timestamp() + minutes * 60
        return datetime.fromtimestamp(nxt).strftime("%Y-%m-%d %H:%M")


# Singleton used by the web app
timer = TimerApp()
