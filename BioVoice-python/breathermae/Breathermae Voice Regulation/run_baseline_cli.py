#!/usr/bin/env python3
"""
BioVoicePrint – parameterized baseline CLI.

Wraps BioVoice_Baseline.py without modifying the original:
  - Accepts BVP-style session directories (task_code filenames)
  - Selects the longer of phonation_1 / phonation_2
  - Supplies wellness answers from JSON (no interactive prompts)
  - Writes the same results tree the original engine produces

Example:
  python run_baseline_cli.py \\
    --participant-id 12345 \\
    --device-type "iPhone 15 - Safari" \\
    --session-dir /data/group1 \\
    --session-dir /data/group2 \\
    --session-dir /data/group3 \\
    --wellness-file /data/wellness.json \\
    --output-dir /data/results/12345 \\
    --engine-script ./BioVoice_Baseline.py
"""

from __future__ import annotations

import argparse
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

from biovoice_cli_common import (
    load_wellness,
    patch_script_config,
    stage_session_folder,
    write_wellness_override_module,
    copy_outputs,
)


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="Run BioVoice baseline build from BVP session folders (non-interactive)."
    )
    p.add_argument("--participant-id", required=True, help="User id or stable label")
    p.add_argument(
        "--device-type",
        default="unknown",
        help='Device label stored in baseline JSON (e.g. "iPhone 15 - Safari")',
    )
    p.add_argument(
        "--session-dir",
        action="append",
        dest="session_dirs",
        required=True,
        help="Path to one completed BVP session-group folder (repeat for each group). "
        "Expects files named by task_code: silence_pre.*, phonation_1.*, phonation_2.*, "
        "count_natural.*, count_slow.*, reading.*, silence_post.*",
    )
    p.add_argument(
        "--wellness-file",
        help="JSON file with the five 1-5 wellness keys "
        "(balanced_centered, mental_clarity_focus, physical_energy, "
        "recording_comfort_readiness, restored_recovered). "
        "Same answers are applied to every session unless --wellness-by-session is used.",
    )
    p.add_argument(
        "--wellness-json",
        help="Inline JSON string with the five wellness keys (alternative to --wellness-file)",
    )
    p.add_argument(
        "--wellness-by-session",
        help="Optional JSON file mapping session folder name → wellness object. "
        "Overrides the default wellness for specific sessions.",
    )
    p.add_argument(
        "--output-dir",
        required=True,
        help="Where to copy the final baseline results (CSV/JSON/WAV tree)",
    )
    p.add_argument(
        "--engine-script",
        default=None,
        help="Path to BioVoice_Baseline.py (default: same directory as this CLI)",
    )
    p.add_argument(
        "--work-dir",
        default=None,
        help="Optional persistent work directory (default: system temp, deleted after run)",
    )
    p.add_argument(
        "--keep-work-dir",
        action="store_true",
        help="Do not delete the work directory after a successful run",
    )
    p.add_argument(
        "--python",
        default=sys.executable,
        help="Python interpreter used to run the engine (default: current interpreter)",
    )
    return p.parse_args()


def main() -> int:
    args = parse_args()

    if len(args.session_dirs) < 1:
        print("ERROR: provide at least one --session-dir", file=sys.stderr)
        return 2

    default_wellness = load_wellness(args.wellness_file, args.wellness_json)

    wellness_by_session: dict[str, dict[str, int]] = {}
    if args.wellness_by_session:
        from biovoice_cli_common import load_json

        raw = load_json(args.wellness_by_session)
        if not isinstance(raw, dict):
            print("ERROR: --wellness-by-session must be a JSON object", file=sys.stderr)
            return 2
        for key, value in raw.items():
            wellness_by_session[str(key)] = load_wellness(None, inline_json=__import__("json").dumps(value))

    engine_script = Path(args.engine_script) if args.engine_script else Path(__file__).resolve().parent / "BioVoice_Baseline.py"
    if not engine_script.is_file():
        print(f"ERROR: engine script not found: {engine_script}", file=sys.stderr)
        print("Pass --engine-script /path/to/BioVoice_Baseline.py", file=sys.stderr)
        return 2

    output_dir = Path(args.output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    own_work = args.work_dir is None
    work = Path(args.work_dir) if args.work_dir else Path(tempfile.mkdtemp(prefix="biovoice_baseline_"))
    work.mkdir(parents=True, exist_ok=True)

    print(f"Work directory: {work}")
    print(f"Participant:    {args.participant_id}")
    print(f"Sessions:       {len(args.session_dirs)}")
    print(f"Engine:         {engine_script}")

    try:
        staged_folders: list[Path] = []
        session_folder_lines: list[str] = []
        data_raw = work / "data" / "raw"
        data_raw.mkdir(parents=True, exist_ok=True)

        for index, session_dir in enumerate(args.session_dirs, start=1):
            session_dir = Path(session_dir)
            if not session_dir.is_dir():
                print(f"ERROR: session dir not found: {session_dir}", file=sys.stderr)
                return 2

            label = f"Session {index}"
            dest = data_raw / f"{args.participant_id} {label}"
            print(f"Staging {session_dir} → {dest}")
            stage_session_folder(
                session_dir=session_dir,
                dest_folder=dest,
                participant_label=args.participant_id,
                session_label=str(index),
            )
            staged_folders.append(dest)
            session_folder_lines.append(f'Path({str(dest.resolve())!r})')

            session_id = dest.name
            if session_id not in wellness_by_session:
                if session_dir.name in wellness_by_session:
                    wellness_by_session[session_id] = wellness_by_session[session_dir.name]
                else:
                    wellness_by_session[session_id] = default_wellness

        results_root = work / "results"
        results_root.mkdir(parents=True, exist_ok=True)

        wellness_mod_path = work / "cli_wellness.py"
        write_wellness_override_module(
            wellness_mod_path,
            wellness_by_session=wellness_by_session,
            default_wellness=default_wellness,
        )

        patched = work / "BioVoice_Baseline_cli_patched.py"
        print(f"Patching engine → {patched}")
        patch_script_config(
            original_script=engine_script,
            patched_script=patched,
            participant_id=args.participant_id,
            device_type=args.device_type,
            session_folder_lines=session_folder_lines,
            results_root=results_root,
            is_baseline=True,
            wellness_module_import="cli_wellness",
        )

        env_pythonpath = str(work)
        if "PYTHONPATH" in __import__("os").environ:
            env_pythonpath = env_pythonpath + __import__("os").pathsep + __import__("os").environ["PYTHONPATH"]

        cmd = [args.python, str(patched)]
        print(f"Running: {' '.join(cmd)}")
        completed = subprocess.run(
            cmd,
            cwd=str(work),
            env={**__import__("os").environ, "PYTHONPATH": env_pythonpath},
            check=False,
        )
        if completed.returncode != 0:
            print(f"ERROR: engine exited with code {completed.returncode}", file=sys.stderr)
            print(f"Work directory left at: {work}", file=sys.stderr)
            return completed.returncode

        engine_results = results_root / args.participant_id / "BioVoice baseline"
        print(f"Copying results {engine_results} → {output_dir}")
        copy_outputs(engine_results, output_dir)

        baseline_json = output_dir / "JSON files" / "baseline_reference.json"
        if baseline_json.exists():
            print(f"Baseline reference: {baseline_json}")
        else:
            print("WARNING: baseline_reference.json not found in output", file=sys.stderr)

        print("Baseline CLI run complete.")
        return 0

    finally:
        if own_work and not args.keep_work_dir:
            pass
        if own_work and not args.keep_work_dir and (output_dir / "JSON files").exists():
            shutil.rmtree(work, ignore_errors=True)
        elif own_work:
            print(f"Work directory retained at: {work}")


if __name__ == "__main__":
    raise SystemExit(main())
