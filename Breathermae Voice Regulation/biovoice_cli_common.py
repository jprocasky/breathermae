#!/usr/bin/env python3
"""
Shared helpers for BioVoicePrint CLI wrappers.

Maps BVP plugin task_codes to engine internal keys, selects the longer
phonation file, stages session folders with keyword-friendly filenames,
and loads wellness JSON for non-interactive runs.
"""

from __future__ import annotations

import json
import re
import shutil
import subprocess
from pathlib import Path
from typing import Any

# Engine expects these six keys (see BioVoice_Baseline.py / Comparison.py).
ENGINE_TASK_KEYS = [
    "step1_silence_pre",
    "step2_sustained_phonation",
    "step3a_counting_natural",
    "step3b_counting_slow",
    "step4_reading",
    "step5_silence_post",
]

# Keyword fragments the original engines use for filename discovery.
ENGINE_FILENAME_KEYWORDS = {
    "step1_silence_pre": "Step 1 - Silent Capture - 10 sec",
    "step2_sustained_phonation": "Step 2 - Sustained Phonation",
    "step3a_counting_natural": "Step 3A - Rhythmic Counting 1 - 10",
    "step3b_counting_slow": "Step 3B - Slower Controlled Pace 1 - 10",
    "step4_reading": "Step 4 - Standardized Reading Passage",
    "step5_silence_post": "Step 5 - Post Capture Silence - 10 sec",
}

BVP_PHONATION_CODES = ("phonation_1", "phonation_2")
BVP_REQUIRED_CODES = (
    "silence_pre",
    "count_natural",
    "count_slow",
    "reading",
    "silence_post",
)

WELLNESS_KEYS = (
    "balanced_centered",
    "mental_clarity_focus",
    "physical_energy",
    "recording_comfort_readiness",
    "restored_recovered",
)

SUPPORTED_AUDIO_SUFFIXES = {
    ".m4a",
    ".wav",
    ".mp3",
    ".webm",
    ".ogg",
    ".mp4",
    ".aac",
    ".caf",
}


def load_json(path: Path | str) -> Any:
    path = Path(path)
    if not path.exists():
        raise FileNotFoundError(f"JSON not found: {path}")
    return json.loads(path.read_text(encoding="utf-8"))


def load_wellness(path: Path | str | None, inline_json: str | None = None) -> dict[str, int]:
    """Load the five 1-5 wellness answers from a file or inline JSON string."""
    if inline_json:
        data = json.loads(inline_json)
    elif path:
        data = load_json(path)
    else:
        raise ValueError("Provide --wellness-file or --wellness-json")

    if not isinstance(data, dict):
        raise ValueError("Wellness payload must be a JSON object")

    out: dict[str, int] = {}
    missing = []
    for key in WELLNESS_KEYS:
        if key not in data:
            missing.append(key)
            continue
        try:
            value = int(data[key])
        except (TypeError, ValueError) as exc:
            raise ValueError(f"Wellness key '{key}' must be an integer 1-5") from exc
        if value < 1 or value > 5:
            raise ValueError(f"Wellness key '{key}' must be 1-5, got {value}")
        out[key] = value

    if missing:
        raise ValueError(f"Wellness JSON missing keys: {', '.join(missing)}")
    return out


def probe_duration_seconds(audio_path: Path) -> float:
    """Return duration in seconds using ffprobe (no Python audio deps required)."""
    cmd = [
        "ffprobe",
        "-v",
        "error",
        "-show_entries",
        "format=duration",
        "-of",
        "default=noprint_wrappers=1:nokey=1",
        str(audio_path),
    ]
    try:
        result = subprocess.run(cmd, check=True, capture_output=True, text=True)
        return float(result.stdout.strip())
    except (subprocess.CalledProcessError, ValueError) as exc:
        raise RuntimeError(f"Could not read duration for {audio_path}: {exc}") from exc


def select_longer_phonation(phonation_1: Path, phonation_2: Path) -> Path:
    """Rule B: use the longer of the two sustained-phonation takes."""
    d1 = probe_duration_seconds(phonation_1)
    d2 = probe_duration_seconds(phonation_2)
    return phonation_1 if d1 >= d2 else phonation_2


def find_audio_in_dir(session_dir: Path, task_code: str) -> Path | None:
    """
    Find an audio file for a BVP task_code inside a session directory.

    Accepts exact stem, prefix, or substring match on the filename.
    """
    if not session_dir.is_dir():
        return None

    candidates: list[Path] = []
    for path in session_dir.iterdir():
        if not path.is_file():
            continue
        if path.suffix.lower() not in SUPPORTED_AUDIO_SUFFIXES:
            continue
        name = path.stem.lower()
        code = task_code.lower()
        if name == code or name.startswith(code + "_") or code in name:
            candidates.append(path)

    if not candidates:
        return None
    candidates.sort(key=lambda p: (0 if p.stem.lower() == task_code.lower() else 1, len(p.name)))
    return candidates[0]


def resolve_session_files(
    session_dir: Path,
    manifest: dict[str, str] | None = None,
) -> dict[str, Path]:
    """
    Resolve BVP task files for one session group.

    Returns a dict keyed by engine task keys. Phonation picks the longer
    of phonation_1 / phonation_2.
    """
    session_dir = Path(session_dir)
    if not session_dir.is_dir():
        raise FileNotFoundError(f"Session directory not found: {session_dir}")

    bvp_map: dict[str, Path] = {}

    if manifest:
        for code, raw in manifest.items():
            p = Path(raw)
            if not p.is_file():
                p = session_dir / raw
            if not p.is_file():
                raise FileNotFoundError(f"Manifest file for '{code}' not found: {raw}")
            bvp_map[code] = p
    else:
        for code in list(BVP_REQUIRED_CODES) + list(BVP_PHONATION_CODES):
            found = find_audio_in_dir(session_dir, code)
            if found:
                bvp_map[code] = found

    missing = [c for c in BVP_REQUIRED_CODES if c not in bvp_map]
    if missing:
        raise FileNotFoundError(
            f"Session {session_dir.name} missing required task files: {', '.join(missing)}"
        )

    p1 = bvp_map.get("phonation_1")
    p2 = bvp_map.get("phonation_2")
    if p1 and p2:
        phonation = select_longer_phonation(p1, p2)
    elif p1:
        phonation = p1
    elif p2:
        phonation = p2
    else:
        raise FileNotFoundError(
            f"Session {session_dir.name} needs at least one of phonation_1 / phonation_2"
        )

    return {
        "step1_silence_pre": bvp_map["silence_pre"],
        "step2_sustained_phonation": phonation,
        "step3a_counting_natural": bvp_map["count_natural"],
        "step3b_counting_slow": bvp_map["count_slow"],
        "step4_reading": bvp_map["reading"],
        "step5_silence_post": bvp_map["silence_post"],
    }


def stage_session_folder(
    session_dir: Path,
    dest_folder: Path,
    participant_label: str,
    session_label: str,
    manifest: dict[str, str] | None = None,
) -> Path:
    """
    Copy resolved audio into dest_folder using the keyword filenames
    the original BioVoice engines discover by.
    """
    files = resolve_session_files(session_dir, manifest)
    dest_folder = Path(dest_folder)
    dest_folder.mkdir(parents=True, exist_ok=True)

    for engine_key, src in files.items():
        keyword_name = ENGINE_FILENAME_KEYWORDS[engine_key]
        dest_name = f"{participant_label} {session_label}_ {keyword_name}{src.suffix.lower()}"
        dest_path = dest_folder / dest_name
        shutil.copy2(src, dest_path)

    return dest_folder


def write_wellness_override_module(
    path: Path,
    wellness_by_session: dict[str, dict[str, int]],
    default_wellness: dict[str, int] | None = None,
) -> None:
    """Write a tiny Python module the patched engines import for wellness answers."""
    payload = {
        "by_session": wellness_by_session,
        "default": default_wellness or {},
    }
    path = Path(path)
    path.write_text(
        "# Auto-generated by BioVoice CLI – do not edit\n"
        f"WELLNESS = {json.dumps(payload, indent=2)}\n",
        encoding="utf-8",
    )


def _wellness_override_block(wellness_module_import: str, include_comparison_helper: bool = False) -> str:
    """Build the non-interactive wellness override source block."""
    lines = [
        "",
        "# --- CLI wellness override ---",
        "import importlib as _CLI_importlib",
        f"_cli_wellness_mod = _CLI_importlib.import_module({wellness_module_import!r})",
        "_CLI_WELLNESS = _cli_wellness_mod.WELLNESS",
        "",
        "def collect_pre_recording_assessment(session_id, session_processed_timestamp):",
        '    """CLI override: use pre-collected wellness answers (no input())."""',
        '    print("\\n------------------------------------------------------------")',
        '    print(f"Pre-recording context (CLI-supplied) for {session_id}")',
        '    print("------------------------------------------------------------")',
        '    answers = _CLI_WELLNESS["by_session"].get(session_id) or _CLI_WELLNESS.get("default") or {}',
        "    if not answers:",
        "        raise RuntimeError(",
        '            f"No CLI wellness answers for session {session_id}. "',
        '            "Pass --wellness-file."',
        "        )",
        "    responses = {",
        '        "session_id": session_id,',
        '        "session_processed_timestamp": session_processed_timestamp,',
        "    }",
        "    for field_name in assessment_questions:",
        "        if field_name not in answers:",
        "            raise RuntimeError(",
        "                f\"Wellness missing key '{field_name}' for {session_id}\"",
        "            )",
        "        responses[field_name] = int(answers[field_name])",
        '        print(f"  {field_name}: {responses[field_name]}")',
        "    return add_wellness_anchor_scores(responses)",
        "",
    ]
    if include_comparison_helper:
        lines.extend(
            [
                "def get_or_collect_comparison_assessment(session_id, session_processed_timestamp):",
                '    """CLI override: always use supplied wellness (never prompt)."""',
                "    return collect_pre_recording_assessment(session_id, session_processed_timestamp)",
                "",
            ]
        )
    lines.append("# --- end CLI wellness override ---")
    lines.append("")
    return "\n".join(lines)


def _replace_function_with_stub(source: str, func_name: str, stub_body: str) -> str:
    """Replace an entire top-level function definition with a short stub."""
    pattern = rf"(^def {re.escape(func_name)}\(.*?
)(.*?)(?=^def |\Z)"
    match = re.search(pattern, source, flags=re.MULTILINE | re.DOTALL)
    if not match:
        return source
    return source[: match.start()] + stub_body + source[match.end() :]


def patch_script_config(
    original_script: Path,
    patched_script: Path,
    *,
    participant_id: str,
    device_type: str,
    session_folder_lines: list[str],
    results_root: Path,
    is_baseline: bool,
    comparison_folder_line: str | None = None,
    baseline_reference_line: str | None = None,
    wellness_module_import: str = "cli_wellness",
) -> None:
    """
    Create a patched copy of the original engine script with CLI config injected.

    Replaces hard-coded participant/device/paths and overrides the interactive
    wellness collectors. Science functions are left untouched.
    """
    source = original_script.read_text(encoding="utf-8")
    folders_literal = "[\n" + "".join(f"    {line},\n" for line in session_folder_lines) + "]"
    patched = source

    if 'participant_id = "Frank"' in patched:
        patched = patched.replace(
            'participant_id = "Frank"',
            f"participant_id = {participant_id!r}  # CLI",
            1,
        )
    if 'device_type = "MacBook Microphone"' in patched:
        patched = patched.replace(
            'device_type = "MacBook Microphone"',
            f"device_type = {device_type!r}  # CLI",
            1,
        )

    if is_baseline:
        start_marker = "baseline_session_folders = ["
        if start_marker in patched:
            start = patched.index(start_marker)
            depth = 0
            end = None
            for i, ch in enumerate(patched[start:], start=start):
                if ch == "[":
                    depth += 1
                elif ch == "]":
                    depth -= 1
                    if depth == 0:
                        end = i + 1
                        break
            if end is not None:
                patched = (
                    patched[:start]
                    + f"baseline_session_folders = {folders_literal}  # CLI"
                    + patched[end:]
                )

        results_marker = 'results_dir = Path("results") / participant_id / "BioVoice baseline"'
        if results_marker in patched:
            new_results = (
                f"results_dir = Path({str(results_root)!r}) / participant_id / "
                f'"BioVoice baseline"  # CLI'
            )
            patched = patched.replace(results_marker, new_results, 1)

        anchor = "future_comparison_domain_weights = {"
        if anchor in patched:
            inject = _wellness_override_block(wellness_module_import, include_comparison_helper=False)
            patched = patched.replace(anchor, inject + anchor, 1)

        # Force fresh processing of staged sessions (no resume from prior CSVs).
        stub = (
            "def load_existing_csv_rows(csv_path):\n"
            "    return []  # CLI: always process staged sessions fresh\n\n"
        )
        patched = _replace_function_with_stub(patched, "load_existing_csv_rows", stub)
    else:
        if not comparison_folder_line or not baseline_reference_line:
            raise ValueError("comparison_folder_line and baseline_reference_line required")

        cmp_marker = 'comparison_session_folder = Path("data/raw/Frank Comparison Session 1")'
        if cmp_marker in patched:
            patched = patched.replace(
                cmp_marker,
                f"comparison_session_folder = {comparison_folder_line}  # CLI",
                1,
            )
        else:
            for line in source.splitlines():
                if line.strip().startswith("comparison_session_folder ="):
                    patched = patched.replace(
                        line,
                        f"comparison_session_folder = {comparison_folder_line}  # CLI",
                        1,
                    )
                    break

        for line in source.splitlines():
            if line.strip().startswith("baseline_reference_path ="):
                patched = patched.replace(
                    line,
                    f"baseline_reference_path = {baseline_reference_line}  # CLI",
                    1,
                )
                break

        cmp_results_marker = (
            'comparison_results_dir = Path("results") / participant_id / "BioVoice BSI comparison"'
        )
        if cmp_results_marker in patched:
            new_cmp_results = (
                f"comparison_results_dir = Path({str(results_root)!r}) / participant_id / "
                f'"BioVoice BSI comparison"  # CLI'
            )
            patched = patched.replace(cmp_results_marker, new_cmp_results, 1)

        anchor = "bsi_framework_weights = {"
        if anchor in patched:
            inject = _wellness_override_block(wellness_module_import, include_comparison_helper=True)
            patched = patched.replace(anchor, inject + anchor, 1)

    patched_script.write_text(patched, encoding="utf-8")


def copy_outputs(src_results: Path, dest_dir: Path) -> None:
    """Copy engine result tree into the user-requested output directory."""
    dest_dir = Path(dest_dir)
    dest_dir.mkdir(parents=True, exist_ok=True)
    if not src_results.exists():
        raise FileNotFoundError(f"Engine did not produce results at {src_results}")
    for item in src_results.iterdir():
        target = dest_dir / item.name
        if item.is_dir():
            if target.exists():
                shutil.rmtree(target)
            shutil.copytree(item, target)
        else:
            shutil.copy2(item, target)
