"""Sync with a shared folder, and optionally push to a VPS via SFTP.

Local source_path (OneDrive etc.) is used for Windows-to-Windows sync.
If sftp_host is set, newer files are also pushed to the VPS.
settings.json, .env, and status.db stay on the machine.
A code change raises the restart flag.
"""
from __future__ import annotations

import hashlib
import logging
import shutil
from pathlib import Path

from jobctx import writing
from lease import ROOT, load_settings

logger = logging.getLogger(__name__)
SKIP_NAMES = {"settings.json", "status.db", ".env"}
SKIP_DIRS = {".venv", "__pycache__", ".git"}
SHARED_JSON = {"jobs.json", "data/email_templates.json"}


def _files(root: Path) -> list[Path]:
    out = []
    for path in root.rglob("*"):
        if not path.is_file():
            continue
        rel = path.relative_to(root)
        if rel.name in SKIP_NAMES or any(part in SKIP_DIRS for part in rel.parts):
            continue
        if str(rel).replace("\\", "/") in SHARED_JSON:
            continue
        if path.suffix not in {".py", ".html", ".css", ".js", ".json", ".txt", ".md"}:
            continue
        out.append(rel)
    return out


def _hash(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def _newer(src: Path, dest: Path) -> bool:
    """True when src should win. Missing dest means pull. Equal time keeps dest."""
    if not dest.exists():
        return True
    return src.stat().st_mtime > dest.stat().st_mtime + 2


def _copy(src: Path, dest: Path) -> None:
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(src, dest)


def _sync_pair(src: Path, dest: Path, label: str, do_write: bool) -> str | None:
    if not src.exists() and not dest.exists():
        return None
    if src.exists() and dest.exists() and _hash(src) == _hash(dest):
        return None
    pull = src.exists() and _newer(src, dest)
    direction = "pull" if pull else "push"
    if do_write:
        if pull:
            _copy(src, dest)
        elif dest.exists():
            _copy(dest, src)
    return f"{label} {direction}"


def _sftp_push(settings: dict, local_root: Path, do_write: bool) -> list[str]:
    """Push newer files from local_root to the VPS. Returns list of notes."""
    import paramiko  # type: ignore
    host = settings["sftp_host"]
    port = int(settings.get("sftp_port") or 22)
    user = settings["sftp_user"]
    password = settings.get("sftp_password") or None
    key_path = settings.get("sftp_key") or None
    remote_base = (settings.get("sftp_path") or ".").rstrip("/")

    notes = []
    transport = paramiko.Transport((host, port))
    try:
        if key_path:
            pkey = paramiko.RSAKey.from_private_key_file(key_path)
            transport.connect(username=user, pkey=pkey)
        else:
            transport.connect(username=user, password=password)
        sftp = paramiko.SFTPClient.from_transport(transport)

        def ensure_dir(rdir: str) -> None:
            try:
                sftp.stat(rdir)
            except FileNotFoundError:
                parent = str(Path(rdir).parent).replace("\\", "/")
                if parent and parent != rdir and parent != ".":
                    ensure_dir(parent)
                sftp.mkdir(rdir)

        code_pushed = False
        for rel in _files(local_root) + [Path(p) for p in SHARED_JSON]:
            local = local_root / rel
            if not local.exists():
                continue
            remote = f"{remote_base}/{rel.as_posix()}"
            try:
                rattr = sftp.stat(remote)
                if local.stat().st_mtime <= rattr.st_mtime + 2:
                    continue  # remote is same or newer
            except FileNotFoundError:
                pass
            notes.append(f"{rel.as_posix()} push-vps")
            is_code = rel.suffix in {".py", ".html", ".css", ".js"}
            if do_write:
                ensure_dir(str(Path(remote).parent).replace("\\", "/"))
                sftp.put(str(local), remote)
                # preserve mtime roughly
                sftp.utime(remote, (local.stat().st_atime, local.stat().st_mtime))
                if is_code:
                    code_pushed = True

        # Tell the VPS it needs a restart if any real code changed.
        if do_write and code_pushed:
            flag = f"{remote_base}/restart.required"
            with sftp.open(flag, "w") as f:
                f.write("1")
            notes.append("restart.required push-vps")

        sftp.close()
    finally:
        transport.close()
    return notes


def run():
    do_write = writing()
    logger.info("code_sync starting (%s)", "write" if do_write else "read-only")
    settings = load_settings()
    source = Path(settings.get("source_path") or "")

    notes = []
    code_changed = False

    # Local folder sync (Windows OneDrive etc.)
    if source.is_dir():
        seen = set()
        for rel in _files(source) + _files(ROOT):
            key = str(rel).replace("\\", "/")
            if key in seen:
                continue
            seen.add(key)
            note = _sync_pair(source / rel, ROOT / rel, key, do_write)
            if note:
                notes.append(note)
                if note.endswith("pull") or note.endswith("push"):
                    code_changed = True

        for rel_s in SHARED_JSON:
            note = _sync_pair(source / rel_s, ROOT / rel_s, rel_s, do_write)
            if note:
                notes.append(note)

    # Optional push to VPS
    if settings.get("sftp_host"):
        try:
            vps_notes = _sftp_push(settings, ROOT, do_write)
            notes.extend(vps_notes)
            if any(n.endswith("push-vps") for n in vps_notes):
                code_changed = True
        except Exception as e:
            msg = f"sftp push failed: {e}"
            logger.exception(msg)
            notes.append(msg)

    pulled_code = any(
        n.endswith(" pull") and not n.startswith("jobs.json") and not n.startswith("data/email_templates.json")
        for n in notes
    )
    if do_write and (pulled_code or any("push-vps" in n for n in notes)):
        (ROOT / "restart.required").write_text("1", encoding="utf-8")

    if not notes:
        msg = "code_sync: already current"
    elif do_write:
        msg = "code_sync: " + "; ".join(notes)
        if code_changed:
            msg += ". Restart if code changed."
    else:
        msg = "code_sync: would " + "; ".join(notes) + " (read-only)"
    logger.info(msg)
    return {"ok": True, "message": msg, "pending": len(notes)}
