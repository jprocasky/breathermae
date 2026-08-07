#!/usr/bin/env python3
"""
BioVoicePrint – parameterized comparison CLI.

Wraps BioVoice_BSI_Comparison.py without modifying the original:
  - Accepts one BVP-style session directory (task_code filenames)
  - Selects the longer of phonation_1 / phonation_2
  - Supplies wellness answers from JSON (no interactive prompts)
  - Loads an existing baseline_reference.json
  - Writes the same Stage 4–7 JSON/CSV outputs the original engine produces

Example:
  python run_comparison_cli.py \\
    --participant-id 12345 \\
    --device-type "iPhone 15 - Safari" \\
    --session-dir /data/comparison_group1 \\
    --baseline-reference /data/results/12345/JSON\\ files/baseline_reference.json \\
    --wellness-file /data/wellness.json \\
    --output-dir /data/results/12345/comparison \\
    --engine-script ./BioVoice_BSI_Comparison.py
"""

from __future__ import annotations

import argparse
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

from biovoice_cli_common import (
    copy_outputs,
    load_wellness,
    patch_script_config,
    stage_session_folder,
    write_wellness_override_module,
)


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="Run BioVoice BSI comparison from a BVP session folder (non-interactive)."
    )
    p.add_argument("--participant-id", required=True, help="User id or stable label")
    p.add_argument(
        "--device-type",
        default="unknown",
        help='Device label stored in results (e.g. "iPhone 15 - Safari")',
    )
    p.add_argument(
        "--session-dir",
        required=True,
        help="Path to one completed BVP comparison session-group folder. "
        "Expects files named by task_code: silence_pre.*, phonation_1.*, phonation_2.*, "
        "count_natural.*, count_slow.*, reading.*, silence_post.*",
    )
    p.add_argument(
        "--baseline-reference",
        required=True,
        help="Path to baseline_reference.json produced by the baseline CLI / engine",
    )
    p.add_argument(
        "--wellness-file",
        help="JSON file with the five 1-5 wellness keys",
    )
    p.add_argument(
        "--wellness-json",
        help="Inline JSON string with the five wellness keys",
    )
    p.add_argument(
        "--output-dir",
        required=True,
        help="Where to copy the final comparison results (CSV/JSON/WAV tree)",
    )
    p.add_argument(
        "--engine-script",
        default=None,
        help="Path to BioVoice_BSI_Comparison.py (default: same directory as this CLI)",
    )
    p.add_argument(
        "--work-dir",
        default=None,
        help="Optional persistent work directory (default: system temp)",
    )
    p.add_argument(
        "--keep-work-dir",
        action="store_true",
        help="Do not delete the work directory after a successful run",
    )
    p.add_argument(
        "--python",
        default=sys.executable,
        help="Python interpreter used to run the engine",
    )
    return p.parse_args()


def main() -> int:
    args = parse_args()

    wellness = load_wellness(args.wellness_file, args.wellness_json)

    engine_script = (
        Path(args.engine_script)
        if args.engine_script
        else Path(__file__).resolve().parent / "BioVoice_BSI_Comparison.py"
    )
    if not engine_script.is_file():
        print(f"ERROR: engine script not found: {engine_script}", file=sys.stderr)
        print("Pass --engine-script /path/to/BioVoice_BSI_Comparison.py", file=sys.stderr)
        return 2

    baseline_ref = Path(args.baseline_reference)
    if not baseline_ref.is_file():
        print(f"ERROR: baseline reference not found: {baseline_ref}", file=sys.stderr)
        return 2

    session_dir = Path(args.session_dir)
    if not session_dir.is_dir():
        print(f"ERROR: session dir not found: {session_dir}", file=sys.stderr)
        return 2

    output_dir = Path(args.output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    own_work = args.work_dir is None
    work = Path(args.work_dir) if args.work_dir else Path(tempfile.mkdtemp(prefix="biovoice_comparison_"))
    work.mkdir(parents=True, exist_ok=True)

    print(f"Work directory: {work}")
    print(f"Participant:    {args.participant_id}")
    print(f"Session:        {session_dir}")
    print(f"Baseline:       {baseline_ref}")
    print(f"Engine:         {engine_script}")

    try:
        data_raw = work / "data" / "raw"
        data_raw.mkdir(parents=True, exist_ok=True)

        dest = data_raw / f"{args.participant_id} Comparison Session 1"
        print(f"Staging {session_dir} → {dest}")
        stage_session_folder(
            session_dir=session_dir,
            dest_folder=dest,
            participant_label=args.participant_id,
            session_label="Comparison 1",
        )

        results_root = work / "results"
        results_root.mkdir(parents=True, exist_ok=True)

        baseline_copy = work / "baseline_reference.json"
        shutil.copy2(baseline_ref, baseline_copy)

        session_id = dest.name
        wellness_by_session = {session_id: wellness}

        wellness_mod_path = work / "cli_wellness.py"
        write_wellness_override_module(
            wellness_mod_path,
            wellness_by_session=wellness_by_session,
            default_wellness=wellness,
        )

        patched = work / "BioVoice_BSI_Comparison_cli_patched.py"
        print(f"Patching engine → {patched}")
        patch_script_config(
            original_script=engine_script,
            patched_script=patched,
            participant_id=args.participant_id,
            device_type=args.device_type,
            session_folder_lines=[],
            results_root=results_root,
            is_baseline=False,
            comparison_folder_line=f"Path({str(dest.resolve())!r})",
            baseline_reference_line=f"Path({str(baseline_copy.resolve())!r})",
            wellness_module_import="cli_wellness",
        )

        env_pythonpath = str(work)
        if "PYTHONPATH" in os.environ:
            env_pythonpath = env_pythonpath + os.pathsep + os.environ["PYTHONPATH"]

        cmd = [args.python, str(patched)]
        print(f"Running: {' '.join(cmd)}")
        completed = subprocess.run(
            cmd,
            cwd=str(work),
            env={**os.environ, "PYTHONPATH": env_pythonpath},
            check=False,
        )
        if completed.returncode != 0:
            print(f"ERROR: engine exited with code {completed.returncode}", file=sys.stderr)
            print(f"Work directory left at: {work}", file=sys.stderr)
            return completed.returncode

        engine_results = results_root / args.participant_id / "BioVoice BSI comparison"
        print(f"Copying results {engine_results} → {output_dir}")
        copy_outputs(engine_results, output_dir)

        report = output_dir / "JSON files" / "stage7_plain_language_report.json"
        payload = output_dir / "JSON files" / "stage7_bsi_pattern_payload.json"
        if report.exists():
            print(f"Plain-language report: {report}")
        else:
            print("WARNING: stage7_plain_language_report.json not found", file=sys.stderr)
        if payload.exists():
            print(f"Stage 7 payload:       {payload}")

        print("Comparison CLI run complete.")
        return 0

    finally:
        if own_work and not args.keep_work_dir and (output_dir / "JSON files").exists():
            shutil.rmtree(work, ignore_errors=True)
        elif own_work:
            print(f"Work directory retained at: {work}")


if __name__ == "__main__":
    raise SystemExit(main())
