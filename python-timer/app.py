"""Local web UI for the timer.

Serves a simple dashboard and job management pages.
Reads/writes jobs.json and status.db.
"""
from __future__ import annotations

import json
import os
import sqlite3
from datetime import datetime
from pathlib import Path

from flask import Flask, redirect, render_template, request, url_for

from dotenv import load_dotenv
load_dotenv()

import logging
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)

from scheduler import timer, JOBS_FILE, DB_FILE, load_jobs, record_error, recent_errors, clear_errors

app = Flask(__name__)
ROOT = Path(__file__).resolve().parent


def get_recent_runs(limit: int = 50):
    if not DB_FILE.exists():
        return []
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    rows = conn.execute(
        """
        SELECT job_id, job_name, started_at, finished_at, ok, message
        FROM runs
        ORDER BY started_at DESC
        LIMIT ?
        """,
        (limit,),
    ).fetchall()
    conn.close()
    return [dict(r) for r in rows]


def save_jobs(jobs: list):
    data = {"jobs": jobs}
    JOBS_FILE.write_text(json.dumps(data, indent=2), encoding="utf-8")


@app.route("/favicon.ico")
def favicon():
    return "", 204


@app.route("/instances")
def instances():
    from lease import heartbeat, instance_status

    try:
        heartbeat(paused=timer.paused)
        return {"ok": True, "instances": instance_status()}
    except Exception as e:
        record_error("instances", str(e))
        return {"ok": False, "instances": [], "error": str(e)}


@app.route("/orders")
def orders():
    from jobs.kbmo_orders import STATUS, VIEW
    from jobs.tag_snapshot import _connect

    try:
        conn = _connect()
    except Exception as e:
        return {"ok": False, "orders": [], "error": str(e)}
    try:
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT s.order_id, s.user_email, s.product_id, s.status,
                       DATE(MAX(v.order_date)) AS order_date
                FROM {STATUS} s
                LEFT JOIN {VIEW} v ON v.order_id = s.order_id
                GROUP BY s.order_id, s.user_email, s.product_id, s.status, s.updated_at
                ORDER BY s.updated_at DESC
                LIMIT 100
                """
            )
            rows = cur.fetchall()
        out = []
        for r in rows:
            d = r.get("order_date")
            out.append({
                "order_id": r.get("order_id"),
                "email": r.get("user_email") or "",
                "product_id": r.get("product_id") or "",
                "status": r.get("status") or "",
                "order_date": d.strftime("%Y-%m-%d") if hasattr(d, "strftime") else (str(d) if d else ""),
            })
        return {"ok": True, "orders": out}
    except Exception as e:
        return {"ok": False, "orders": [], "error": str(e)}
    finally:
        conn.close()



@app.context_processor
def inject_instance():
    """Make the current instance available to the header."""
    try:
        from lease import instance_status
        mine = next((i for i in instance_status() if i.get("mine")), None)
    except Exception:
        mine = None
    return {"current_instance": mine}


@app.route("/heartbeat")
def heartbeat():
    """Lightweight endpoint for the live clock."""
    from datetime import datetime
    return {
        "ok": True,
        "time": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "jobs_loaded": len(load_jobs()),
        "restart_required": (ROOT / "restart.required").exists(),
        "paused": timer.paused,
    }


@app.route("/errors/clear", methods=["POST"])
def errors_clear():
    clear_errors()
    return redirect(url_for("index"))


@app.route("/control/<action>", methods=["POST"])
def control(action):
    if action == "pause":
        timer.pause()
    elif action == "start":
        timer.resume()
    elif action == "exit":
        # Local only. Give the response a moment, then exit.
        import os, threading
        def _die():
            import time
            time.sleep(0.4)
            os._exit(0)
        threading.Thread(target=_die, daemon=True).start()
        return redirect(url_for("index"))
    return redirect(url_for("index"))


@app.route("/")
def index():
    jobs = load_jobs()
    runs = get_recent_runs(40)
    next_runs = {j.get("id"): timer.next_run_label(j) for j in jobs}
    emergency = False
    try:
        from lease import instance_status, emergency_paused
        instances = instance_status()
        emergency = emergency_paused()
    except Exception as e:
        record_error("instances", str(e))
        instances = []
    return render_template(
        "index.html",
        jobs=jobs,
        runs=runs,
        next_runs=next_runs,
        instances=instances,
        errors=recent_errors(),
        now=datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        paused=timer.paused,
        emergency=emergency,
    )


@app.route("/jobs")
def jobs_page():
    jobs = load_jobs()
    return render_template("jobs.html", jobs=jobs)


@app.route("/jobs/move/<job_id>/<direction>", methods=["POST"])
def move_job(job_id, direction):
    jobs = load_jobs()
    idx = next((i for i, j in enumerate(jobs) if j.get("id") == job_id), None)
    if idx is None:
        return redirect(url_for("jobs_page"))
    if direction == "up" and idx > 0:
        jobs[idx - 1], jobs[idx] = jobs[idx], jobs[idx - 1]
    elif direction == "down" and idx < len(jobs) - 1:
        jobs[idx + 1], jobs[idx] = jobs[idx], jobs[idx + 1]
    save_jobs(jobs)
    return redirect(url_for("jobs_page"))


@app.route("/jobs/write/<job_id>", methods=["POST"])
def toggle_write(job_id):
    jobs = load_jobs()
    for j in jobs:
        if j.get("id") == job_id:
            j["write"] = not j.get("write", False)
            break
    save_jobs(jobs)
    return redirect(url_for("jobs_page"))


@app.route("/jobs/toggle/<job_id>", methods=["POST"])
def toggle_job(job_id):
    jobs = load_jobs()
    for j in jobs:
        if j.get("id") == job_id:
            j["enabled"] = not j.get("enabled", True)
            break
    save_jobs(jobs)
    return redirect(url_for("jobs_page"))


@app.route("/jobs/delete/<job_id>", methods=["POST"])
def delete_job(job_id):
    jobs = [j for j in load_jobs() if j.get("id") != job_id]
    save_jobs(jobs)
    return redirect(url_for("jobs_page"))


@app.route("/jobs/edit/<job_id>", methods=["GET", "POST"])
def edit_job(job_id):
    jobs = load_jobs()
    job = next((j for j in jobs if j.get("id") == job_id), None)
    if not job:
        return redirect(url_for("jobs_page"))
    if request.method == "POST":
        job["name"] = request.form["name"].strip()
        job["callable"] = request.form["callable"].strip()
        job["enabled"] = request.form.get("enabled") == "on"
        job["write"] = request.form.get("write") == "on"
        job["local"] = request.form.get("local") == "on"
        job["interval_minutes"] = int(request.form.get("interval_minutes") or 60)
        job["window_start"] = request.form.get("window_start") or None
        job["window_end"] = request.form.get("window_end") or None
        job["description"] = request.form.get("description", "").strip()
        if not job["callable"]:
            return "callable is required", 400
        save_jobs(jobs)
        return redirect(url_for("jobs_page"))
    return render_template("add_job.html", job=job)


@app.route("/jobs/add", methods=["GET", "POST"])
def add_job():
    if request.method == "POST":
        new = {
            "id": request.form["id"].strip().replace(" ", "_").lower(),
            "name": request.form["name"].strip(),
            "enabled": request.form.get("enabled") == "on",
            "write": request.form.get("write") == "on",
            "local": request.form.get("local") == "on",
            "interval_minutes": int(request.form.get("interval_minutes") or 60),
            "window_start": request.form.get("window_start") or None,
            "window_end": request.form.get("window_end") or None,
            "callable": request.form["callable"].strip(),
            "description": request.form.get("description", "").strip(),
        }
        if not new["id"] or not new["callable"]:
            return "id and callable are required", 400
        jobs = load_jobs()
        if any(j.get("id") == new["id"] for j in jobs):
            return "Job id already exists", 400
        jobs.append(new)
        save_jobs(jobs)
        return redirect(url_for("jobs_page"))
    return render_template("add_job.html")


TEMPLATES_FILE = ROOT / "data" / "email_templates.json"


def load_templates():
    if not TEMPLATES_FILE.exists():
        return []
    return json.loads(TEMPLATES_FILE.read_text(encoding="utf-8"))


def save_templates(templates: list):
    TEMPLATES_FILE.write_text(json.dumps(templates, indent=2), encoding="utf-8")


def _csv(value: str) -> list[str]:
    return [p.strip() for p in (value or "").split(",") if p.strip()]


def _ints(value: str) -> list[int]:
    out = []
    for p in _csv(value):
        try:
            out.append(int(p))
        except ValueError:
            continue
    return out


def _optional_int(value: str):
    value = (value or "").strip()
    return int(value) if value else None


def _template_from_form(existing: dict | None = None) -> dict:
    item = {
        "id": int(request.form["id"]),
        "name": request.form["name"].strip(),
        "subject": request.form["subject"].strip(),
        "body": request.form.get("body", ""),
        "cta_url": request.form.get("cta_url", "").strip(),
        "cta_label": request.form.get("cta_label", "").strip(),
        "bcc": request.form.get("bcc", "").strip(),
    }
    if request.form.get("has_rule") == "on":
        rule = {"order": int(request.form.get("order") or 100)}
        for key, parser in (
            ("tags_required", _csv),
            ("tags_missing", _csv),
            ("requires_sent", _ints),
        ):
            vals = parser(request.form.get(key, ""))
            if vals:
                rule[key] = vals
        for key in ("days_since_visit", "max_days_since_register", "min_days_since_register", "max_sends", "days_since_event"):
            n = _optional_int(request.form.get(key, ""))
            if n is not None:
                rule[key] = n
        event_key = request.form.get("event_key", "").strip()
        if event_key:
            rule["event_key"] = event_key
        event_mode = request.form.get("event_mode", "").strip()
        if event_mode:
            rule["event_mode"] = event_mode
        item["rule"] = rule
    return item


@app.route("/emails")
def emails_page():
    templates = sorted(load_templates(), key=lambda t: (t.get("rule", {}).get("order", 999), t.get("id", 0)))
    return render_template("emails.html", templates=templates)


@app.route("/emails/edit/<int:template_id>", methods=["GET", "POST"])
def edit_email(template_id):
    templates = load_templates()
    item = next((t for t in templates if int(t.get("id")) == template_id), None)
    if not item:
        return redirect(url_for("emails_page"))
    if request.method == "POST":
        updated = _template_from_form(item)
        for i, t in enumerate(templates):
            if int(t.get("id")) == template_id:
                templates[i] = updated
                break
        save_templates(templates)
        return redirect(url_for("emails_page"))
    return render_template(
        "email_edit.html",
        template=item,
        notice=request.args.get("notice"),
        test_email=os.environ.get("TEST_EMAIL", ""),
    )


@app.route("/emails/add", methods=["GET", "POST"])
def add_email():
    templates = load_templates()
    next_id = max((int(t.get("id") or 0) for t in templates), default=0) + 1
    if request.method == "POST":
        new = _template_from_form()
        if any(int(t.get("id")) == new["id"] for t in templates):
            return "Template id already exists", 400
        templates.append(new)
        save_templates(templates)
        return redirect(url_for("emails_page"))
    return render_template("email_edit.html", template=None, next_id=next_id)


@app.route("/emails/test/<int:template_id>", methods=["POST"])
def test_email(template_id):
    to = (request.form.get("to") or os.environ.get("TEST_EMAIL") or "").strip()
    if not to:
        return redirect(url_for("edit_email", template_id=template_id, notice="No test address"))
    from emailer import send
    from jobctx import set_write

    set_write(True)
    result = send(to, template_id, trigger="test", first_name="Test")
    return redirect(url_for("edit_email", template_id=template_id, notice=result.get("message")))



@app.route("/emergency/<action>", methods=["POST"])
def emergency(action):
    from lease import set_emergency_pause
    set_emergency_pause(action == "on")
    return redirect(url_for("index"))


@app.route("/settings", methods=["GET", "POST"])
def settings_page():
    from lease import load_settings, release_lease, save_settings

    settings = load_settings()
    notice = request.args.get("notice")
    if request.method == "POST":
        if request.form.get("action") == "release":
            release_lease()
            return redirect(url_for("settings_page", notice="Lease released"))
        settings["name"] = request.form.get("name", "").strip() or settings["name"]
        settings["role"] = request.form.get("role") if request.form.get("role") in ("primary", "secondary") else "secondary"
        settings["source_path"] = request.form.get("source_path", "").strip()
        try:
            settings["handback_minutes"] = max(1, int(request.form.get("handback_minutes") or 60))
        except ValueError:
            settings["handback_minutes"] = 60
        save_settings(settings)
        return redirect(url_for("settings_page", notice="Saved"))
    return render_template("settings.html", settings=settings, notice=notice)


@app.route("/run/<job_id>", methods=["POST"])
def run_now(job_id):
    """Trigger a job immediately (for testing)."""
    from scheduler import make_job_runner, load_jobs

    for job in load_jobs():
        if job.get("id") == job_id:
            from lease import holding_lease

            try:
                if job_id != "code_sync" and not job.get("local") and not holding_lease():
                    record_error("run", f"{job_id}: this instance does not hold the lease")
                    return redirect(url_for("index"))
                make_job_runner(job, force=True)()
            except Exception as e:
                logger.exception("Run now failed")
                record_error(job_id, str(e))
            break
    return redirect(url_for("index"))


def create_app():
    timer.start()
    return app


if __name__ == "__main__":
    timer.start()
    # Bind to localhost only for safety
    app.run(host="127.0.0.1", port=5050, debug=False, use_reloader=False)
