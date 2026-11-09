"""Per-run write flag for jobs.

The scheduler sets this before calling a job. Jobs that mutate data
must check writing() and skip the write when it is false.

    from jobctx import writing

    if not writing():
        return {"ok": True, "message": "read-only, skipped write"}
"""
from __future__ import annotations

from contextvars import ContextVar

_write: ContextVar[bool] = ContextVar("job_write", default=False)


def set_write(enabled: bool) -> None:
    _write.set(bool(enabled))


def writing() -> bool:
    """True only when this run was started with the job's write flag on."""
    return _write.get()
