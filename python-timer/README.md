# Python Timer (staging scheduler)

Simple file-backed scheduler with a local web UI. Designed as the first step off the Excel Timer.

## What it does

- Loads job definitions from `jobs.json`
- Respects `enabled` flag and optional daily time windows
- Runs jobs on an interval (minutes)
- Records every run in `status.db` (SQLite)
- Local web dashboard at http://127.0.0.1:5050
- Console logging for deeper status (like VBA Debug.Print)
- Add / enable / disable / delete jobs from the web UI (writes back to `jobs.json`)

## Quick start (home machine)

```bash
cd python-timer
python3 -m venv .venv
source .venv/bin/activate          # Windows: .venv\Scripts\activate
pip install -r requirements.txt

cp .env.example .env
# edit .env with your SiteGround DB credentials
# (the IP of this machine must already be whitelisted)

python app.py
```

Open http://127.0.0.1:5050

- Dashboard auto-refreshes every 30 seconds.
- Use **Run now** to test a job immediately.
- Jobs page lets you toggle enable/disable, delete, or add.

## Adding a real procedure

1. Create `jobs/my_job.py` with a `run()` function that returns `{"ok": True/False, "message": "..."}`.
2. Add an entry via the web UI (or edit `jobs.json`) with `"callable": "jobs.my_job:run"`.
3. The scheduler picks up the change within ~30 seconds.

## Notes

- Binds to localhost only.
- Timezone for scheduling is America/New_York (edit in `scheduler.py` if needed).
- First included job is `db_ping` — it only needs the `.env` values and a network path to SiteGround.
- Excel can keep running in parallel while you validate.

## Files

- `app.py` — Flask web UI + starts the scheduler
- `scheduler.py` — APScheduler + status DB
- `jobs.json` — job definitions (edited by the UI)
- `jobs/` — callable modules
- `status.db` — run history (created on first start)
- `templates/`, `static/` — UI
