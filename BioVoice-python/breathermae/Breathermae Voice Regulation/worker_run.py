#!/usr/bin/env python3
"""
BioVoicePrint analysis worker (pull-based).

Polls WordPress REST /bmf-biovoice/v1/worker/queue, downloads audio,
runs run_baseline_cli.py / run_comparison_cli.py, posts JSON results back.

Auth: WP Application Password for an admin (or user with bmf_biovoice_worker).

Example:
  set BIOVOICE_WP_URL=https://breathermae.com
  set BIOVOICE_WP_USER=worker
  set BIOVOICE_WP_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx
  python worker_run.py --once
  python worker_run.py --loop --interval 300
"""

from __future__ import annotations

import argparse
import base64
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

HERE = Path(__file__).resolve().parent
DEFAULT_ENGINE_DIR = HERE  # same folder as run_*_cli.py when deployed together


def env(name: str, default: str = "") -> str:
    return os.environ.get(name, default).strip()


class WPClient:
    def __init__(
        self,
        base_url: str,
        user: str = "",
        app_password: str = "",
        api_key: str = "",
    ):
        self.base = base_url.rstrip("/")
        self.api_key = (api_key or "").strip()
        self.user = (user or "").strip()
        self.app_password = (app_password or "").replace(" ", "")
        self.basic_auth = ""
        if self.user and self.app_password:
            token = base64.b64encode(
                f"{self.user}:{self.app_password}".encode("utf-8")
            ).decode("ascii")
            self.basic_auth = f"Basic {token}"

    def _url(self, path: str) -> str:
        if path.startswith("http"):
            return path
        if not path.startswith("/"):
            path = "/" + path
        if path.startswith("/wp-json"):
            return self.base + path
        return self.base + "/wp-json" + path

    def request(
        self,
        method: str,
        path: str,
        data: dict | None = None,
        binary: bool = False,
    ) -> Any:
        url = self._url(path)
        body = None
        headers = {
            "Accept": "application/json" if not binary else "*/*",
            "User-Agent": "BioVoiceWorker/0.1",
        }
        # Prefer worker API key — hosts often strip Basic Authorization.
        if self.api_key:
            headers["X-BMF-BioVoice-Key"] = self.api_key
        elif self.basic_auth:
            headers["Authorization"] = self.basic_auth
        if data is not None:
            body = json.dumps(data).encode("utf-8")
            headers["Content-Type"] = "application/json"
        req = urllib.request.Request(url, data=body, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=120) as resp:
                raw = resp.read()
                if binary:
                    return raw
                if not raw:
                    return {}
                return json.loads(raw.decode("utf-8"))
        except urllib.error.HTTPError as e:
            detail = e.read().decode("utf-8", errors="replace")
            raise RuntimeError(f"HTTP {e.code} {url}: {detail}") from e

    def get_queue(self, limit: int = 10) -> dict:
        return self.request("GET", f"/bmf-biovoice/v1/worker/queue?limit={limit}")

    def download_audio(self, download_url: str, dest: Path) -> Path:
        # download_url is a full REST URL from the queue payload
        path = download_url
        if download_url.startswith(self.base):
            path = download_url[len(self.base) :]
        elif "wp-json" in download_url:
            idx = download_url.find("/wp-json")
            path = download_url[idx:]
        raw = self.request("GET", path, binary=True)
        dest.parent.mkdir(parents=True, exist_ok=True)
        # Guard against HTML/JSON error bodies saved as "audio".
        head = raw[:64] if raw else b""
        if head.lstrip().startswith(b"{") or head.lstrip().startswith(b"<"):
            preview = raw[:200].decode("utf-8", errors="replace")
            raise RuntimeError(
                f"Download does not look like audio ({path}): {preview!r}"
            )
        if len(raw) < 500:
            raise RuntimeError(
                f"Download too small ({len(raw)} bytes) for {path} — likely empty/corrupt on server"
            )
        dest.write_bytes(raw)
        return dest


    def post_results(
        self,
        user_id: int,
        session_group_id: int,
        plain_report: dict | None,
        pattern_payload: dict | None,
    ) -> dict:
        return self.request(
            "POST",
            "/bmf-biovoice/v1/worker/results",
            {
                "user_id": user_id,
                "session_group_id": session_group_id,
                "plain_report": plain_report,
                "pattern_payload": pattern_payload,
                "source": "engine",
            },
        )

    def post_baseline(
        self,
        user_id: int,
        reference: dict,
        group_ids: list[int] | None = None,
    ) -> dict:
        body: dict[str, Any] = {
            "user_id": user_id,
            "baseline_reference": reference,
            "source": "engine",
        }
        if group_ids:
            body["group_ids"] = list(group_ids)
        return self.request("POST", "/bmf-biovoice/v1/worker/baseline", body)

    def post_error(
        self,
        *,
        user_id: int,
        job_type: str,
        message: str,
        session_group_id: int | None = None,
        group_ids: list[int] | None = None,
        task_codes: list[str] | None = None,
        detail: str = "",
    ) -> dict:
        body: dict[str, Any] = {
            "user_id": user_id,
            "job_type": job_type,
            "message": message[:500],
            "detail": (detail or "")[:4000],
        }
        if session_group_id:
            body["session_group_id"] = int(session_group_id)
        if group_ids:
            body["group_ids"] = list(group_ids)
        if task_codes:
            body["task_codes"] = list(task_codes)
        return self.request("POST", "/bmf-biovoice/v1/worker/errors", body)


def stage_group_files(client: WPClient, group: dict, dest_dir: Path) -> Path:
    """Download task files into dest_dir using BVP task_code filenames."""
    dest_dir.mkdir(parents=True, exist_ok=True)
    for f in group.get("files") or []:
        code = f.get("task_code") or "unknown"
        url = f.get("download_url")
        if not url:
            continue
        # Prefer extension from original filename
        orig = f.get("original_filename") or ""
        suffix = Path(orig).suffix.lower() if orig else ".m4a"
        if suffix not in {".m4a", ".wav", ".mp3", ".webm", ".ogg", ".mp4", ".aac", ".caf"}:
            suffix = ".m4a"
        dest = dest_dir / f"{code}{suffix}"
        print(f"  download {code} → {dest.name}")
        client.download_audio(url, dest)
    return dest_dir


def write_wellness(path: Path, wellness: dict) -> Path:
    path.write_text(json.dumps(wellness, indent=2), encoding="utf-8")
    return path


def run_cmd(cmd: list[str], cwd: Path | None = None) -> None:
    print("  $", " ".join(cmd))
    proc = subprocess.run(cmd, cwd=str(cwd) if cwd else None)
    if proc.returncode != 0:
        raise RuntimeError(f"Command failed ({proc.returncode}): {' '.join(cmd)}")


def _cleanup_job_dir(job_dir: Path, *, keep: bool) -> None:
    if keep:
        print(f"  work dir retained: {job_dir}")
        return
    try:
        if job_dir.exists():
            shutil.rmtree(job_dir, ignore_errors=True)
            print(f"  cleaned work dir: {job_dir.name}")
    except OSError as exc:
        print(f"  warn: could not remove {job_dir}: {exc}", file=sys.stderr)


def _infer_task_codes_from_error(message: str) -> list[str]:
    """Best-effort extract task_code names from error text."""
    found: list[str] = []
    for code in (
        "silence_pre",
        "silence_post",
        "phonation_1",
        "phonation_2",
        "count_natural",
        "count_slow",
        "reading",
    ):
        if code in message:
            found.append(code)
    return found


def process_baseline(client: WPClient, job: dict, engine_dir: Path, work_root: Path) -> Path:
    user_id = int(job["user_id"])
    participant = str(job.get("participant_id") or user_id)
    device = job.get("device_type") or "Unknown"
    groups = job.get("groups") or []
    group_ids = [int(g.get("group_id") or 0) for g in groups if int(g.get("group_id") or 0) > 0]
    if len(groups) < 3:
        raise RuntimeError(f"Baseline job user {user_id} has only {len(groups)} groups")

    job_dir = work_root / f"baseline_{user_id}"
    if job_dir.exists():
        shutil.rmtree(job_dir)
    job_dir.mkdir(parents=True)

    session_dirs: list[Path] = []
    # One wellness file — use first group with answers, else merge defaults
    wellness = {}
    for i, g in enumerate(groups[:3], start=1):
        sdir = job_dir / f"s{i}"
        stage_group_files(client, g, sdir)
        session_dirs.append(sdir)
        w = g.get("wellness") or {}
        if w and not wellness:
            wellness = w

    if not wellness:
        # Fallback neutral mid-scale if missing (should not happen in production)
        wellness = {
            "balanced_centered": 3,
            "mental_clarity_focus": 3,
            "physical_energy": 3,
            "recording_comfort_readiness": 3,
            "restored_recovered": 3,
        }
    wellness_path = write_wellness(job_dir / "wellness.json", wellness)
    out_dir = job_dir / "out"

    cmd = [
        sys.executable,
        str(engine_dir / "run_baseline_cli.py"),
        "--participant-id",
        participant,
        "--device-type",
        device,
        "--wellness-file",
        str(wellness_path),
        "--output-dir",
        str(out_dir),
    ]
    for sdir in session_dirs:
        cmd.extend(["--session-dir", str(sdir)])

    run_cmd(cmd, cwd=engine_dir)

    ref_path = out_dir / "JSON files" / "baseline_reference.json"
    if not ref_path.is_file():
        # Search tree
        matches = list(out_dir.rglob("baseline_reference.json"))
        if not matches:
            raise RuntimeError("baseline_reference.json not produced")
        ref_path = matches[0]

    reference = json.loads(ref_path.read_text(encoding="utf-8"))
    resp = client.post_baseline(user_id, reference, group_ids=group_ids)
    print(f"  posted baseline reference result_id={resp.get('result_id')}")
    return job_dir


def process_comparison(client: WPClient, job: dict, engine_dir: Path, work_root: Path) -> Path:
    user_id = int(job["user_id"])
    participant = str(job.get("participant_id") or user_id)
    device = job.get("device_type") or "Unknown"
    group = job.get("group") or {}
    group_id = int(job.get("session_group_id") or group.get("group_id") or 0)
    if group_id < 1:
        raise RuntimeError("comparison job missing session_group_id")

    baseline_ref = job.get("baseline_reference")
    if not isinstance(baseline_ref, dict):
        raise RuntimeError(f"user {user_id}: no baseline_reference on job (run baseline first)")

    job_dir = work_root / f"comparison_{group_id}"
    if job_dir.exists():
        shutil.rmtree(job_dir)
    job_dir.mkdir(parents=True)

    sdir = job_dir / "session"
    stage_group_files(client, group, sdir)

    wellness = group.get("wellness") or {
        "balanced_centered": 3,
        "mental_clarity_focus": 3,
        "physical_energy": 3,
        "recording_comfort_readiness": 3,
        "restored_recovered": 3,
    }
    wellness_path = write_wellness(job_dir / "wellness.json", wellness)
    baseline_path = job_dir / "baseline_reference.json"
    baseline_path.write_text(json.dumps(baseline_ref, indent=2), encoding="utf-8")
    out_dir = job_dir / "out"

    cmd = [
        sys.executable,
        str(engine_dir / "run_comparison_cli.py"),
        "--participant-id",
        participant,
        "--device-type",
        device,
        "--session-dir",
        str(sdir),
        "--baseline-reference",
        str(baseline_path),
        "--wellness-file",
        str(wellness_path),
        "--output-dir",
        str(out_dir),
    ]
    run_cmd(cmd, cwd=engine_dir)

    plain_path = None
    pattern_path = None
    for p in out_dir.rglob("*.json"):
        if p.name == "stage7_plain_language_report.json":
            plain_path = p
        elif p.name == "stage7_bsi_pattern_payload.json":
            pattern_path = p

    if not plain_path and not pattern_path:
        raise RuntimeError("No stage7 JSON produced")

    plain = json.loads(plain_path.read_text(encoding="utf-8")) if plain_path else None
    pattern = json.loads(pattern_path.read_text(encoding="utf-8")) if pattern_path else None
    resp = client.post_results(user_id, group_id, plain, pattern)
    print(f"  posted stage7 result_id={resp.get('result_id')} group={group_id}")
    return job_dir


def process_job(
    client: WPClient,
    job: dict,
    engine_dir: Path,
    work_root: Path,
    *,
    keep_work_dir: bool = False,
) -> None:
    jtype = job.get("type")
    print(f"\n=== Job {jtype} user={job.get('user_id')} ===")
    job_dir: Path | None = None
    try:
        if jtype == "baseline":
            job_dir = process_baseline(client, job, engine_dir, work_root)
            _cleanup_job_dir(job_dir, keep=keep_work_dir)
        elif jtype == "comparison":
            job_dir = process_comparison(client, job, engine_dir, work_root)
            _cleanup_job_dir(job_dir, keep=keep_work_dir)
        else:
            print(f"  skip unknown job type: {jtype}")
    except Exception as exc:  # noqa: BLE001
        msg = str(exc)
        print(f"  ERROR: {msg}", file=sys.stderr)
        if job_dir is not None:
            print(f"  work dir retained for debug: {job_dir}", file=sys.stderr)
        # Report to WP so group is marked failed, tag + email fire.
        try:
            user_id = int(job.get("user_id") or 0)
            group_ids: list[int] = []
            session_group_id = None
            if jtype == "comparison":
                g = job.get("group") or {}
                session_group_id = int(job.get("session_group_id") or g.get("group_id") or 0) or None
                if session_group_id:
                    group_ids = [session_group_id]
            elif jtype == "baseline":
                for g in job.get("groups") or []:
                    gid = int(g.get("group_id") or 0)
                    if gid > 0:
                        group_ids.append(gid)
            client.post_error(
                user_id=user_id,
                job_type=str(jtype or "unknown"),
                message=msg[:500],
                session_group_id=session_group_id,
                group_ids=group_ids,
                task_codes=_infer_task_codes_from_error(msg),
                detail=msg,
            )
            print("  reported error to WordPress (/worker/errors)")
        except Exception as report_exc:  # noqa: BLE001
            print(f"  warn: failed to report error to WP: {report_exc}", file=sys.stderr)
        raise

def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="BioVoicePrint pull-based analysis worker")
    p.add_argument("--url", default=env("BIOVOICE_WP_URL"), help="Site base URL")
    p.add_argument(
        "--api-key",
        default=env("BIOVOICE_WP_API_KEY"),
        help="Worker API key (header X-BMF-BioVoice-Key). Preferred.",
    )
    p.add_argument("--user", default=env("BIOVOICE_WP_USER"), help="WP username (fallback)")
    p.add_argument(
        "--app-password",
        default=env("BIOVOICE_WP_APP_PASSWORD"),
        help="WP Application Password (fallback; often blocked by host)",
    )
    p.add_argument(
        "--engine-dir",
        default=env("BIOVOICE_ENGINE_DIR", str(DEFAULT_ENGINE_DIR)),
        help="Folder containing run_baseline_cli.py / run_comparison_cli.py",
    )
    p.add_argument("--limit", type=int, default=5, help="Max jobs per poll")
    p.add_argument("--once", action="store_true", help="Single poll then exit")
    p.add_argument("--loop", action="store_true", help="Poll forever")
    p.add_argument("--interval", type=int, default=300, help="Seconds between polls in --loop")
    p.add_argument(
        "--work-dir",
        default=env("BIOVOICE_WORK_DIR", str(Path(tempfile.gettempdir()) / "biovoice_worker")),
        help="Local work directory for downloads + CLI output",
    )
    p.add_argument(
        "--keep-work-dir",
        action="store_true",
        help="Do not delete worker job folders after successful POST",
    )
    return p.parse_args()


def main() -> int:
    args = parse_args()
    if not args.url:
        print("Set --url or BIOVOICE_WP_URL", file=sys.stderr)
        return 2
    if not args.api_key and not (args.user and args.app_password):
        print(
            "Set BIOVOICE_WP_API_KEY (recommended) or "
            "BIOVOICE_WP_USER + BIOVOICE_WP_APP_PASSWORD",
            file=sys.stderr,
        )
        return 2

    engine_dir = Path(args.engine_dir)
    if not (engine_dir / "run_baseline_cli.py").is_file():
        print(f"Engine dir missing run_baseline_cli.py: {engine_dir}", file=sys.stderr)
        return 2

    client = WPClient(
        args.url,
        user=args.user,
        app_password=args.app_password,
        api_key=args.api_key,
    )
    work_root = Path(args.work_dir)
    work_root.mkdir(parents=True, exist_ok=True)

    def poll_once() -> int:
        queue = client.get_queue(args.limit)
        jobs = queue.get("jobs") or []
        print(f"Queue: {len(jobs)} job(s) at {queue.get('generated_at')}")
        for job in jobs:
            try:
                process_job(
                    client,
                    job,
                    engine_dir,
                    work_root,
                    keep_work_dir=args.keep_work_dir,
                )
            except Exception as exc:  # noqa: BLE001 — keep loop alive
                # process_job already reported to WP and printed ERROR
                print(f"  job failed: {exc}", file=sys.stderr)
        return len(jobs)
    if args.loop:
        while True:
            poll_once()
            time.sleep(max(30, args.interval))
    else:
        poll_once()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
