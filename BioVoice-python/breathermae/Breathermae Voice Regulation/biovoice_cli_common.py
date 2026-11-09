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


def _parse_ffprobe_duration(stdout: str) -> float | None:
    """Parse ffprobe duration output; treat N/A / empty as unknown."""
    text = (stdout or "").strip()
    if not text or text.upper() == "N/A":
        return None
    # Some builds print multiple lines; take the first parseable float.
    for line in text.splitlines():
        line = line.strip()
        if not line or line.upper() == "N/A":
            continue
        try:
            return float(line)
        except ValueError:
            continue
    return None


def probe_duration_seconds(audio_path: Path) -> float:
    """
    Return duration in seconds using ffprobe.

    Browser MediaRecorder webm/mp4 often lack a reliable format duration
    (ffprobe prints N/A). We try format, then stream, then packet-based estimate.
    """
    if not audio_path.is_file():
        raise RuntimeError(f"Audio file missing: {audio_path}")

    probes = [
        # Container-level duration
        [
            "ffprobe", "-v", "error",
            "-show_entries", "format=duration",
            "-of", "default=noprint_wrappers=1:nokey=1",
            str(audio_path),
        ],
        # Stream-level duration (sometimes present when format is not)
        [
            "ffprobe", "-v", "error",
            "-select_streams", "a:0",
            "-show_entries", "stream=duration",
            "-of", "default=noprint_wrappers=1:nokey=1",
            str(audio_path),
        ],
    ]

    last_err: Exception | None = None
    for cmd in probes:
        try:
            result = subprocess.run(cmd, check=True, capture_output=True, text=True)
            val = _parse_ffprobe_duration(result.stdout)
            if val is not None and val > 0:
                return val
        except (subprocess.CalledProcessError, ValueError) as exc:
            last_err = exc

    # Packet timestamps: last packet pts_time as a rough duration (webm-friendly).
    try:
        cmd = [
            "ffprobe", "-v", "error",
            "-select_streams", "a:0",
            "-show_entries", "packet=pts_time",
            "-of", "csv=p=0",
            str(audio_path),
        ]
        result = subprocess.run(cmd, check=True, capture_output=True, text=True)
        last = None
        for line in (result.stdout or "").splitlines():
            line = line.strip().rstrip(",")
            if not line or line.upper() == "N/A":
                continue
            try:
                last = float(line)
            except ValueError:
                continue
        if last is not None and last > 0:
            return last
    except (subprocess.CalledProcessError, ValueError) as exc:
        last_err = exc

    raise RuntimeError(
        f"Could not read duration for {audio_path}"
        + (f": {last_err}" if last_err else " (ffprobe returned N/A)")
    )


def _ffmpeg_can_open(audio_path: Path) -> bool:
    """True if ffmpeg can demux at least one audio frame (rejects corrupt webm)."""
    if not audio_path.is_file() or audio_path.stat().st_size < 500:
        return False
    cmd = [
        "ffmpeg",
        "-v",
        "error",
        "-i",
        str(audio_path),
        "-f",
        "null",
        "-",
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    return result.returncode == 0


def select_longer_phonation(phonation_1: Path, phonation_2: Path) -> Path:
    """
    Rule B: use the longer of the two sustained-phonation takes.

    If one file is corrupt/unreadable, fall back to the other. If both work,
    prefer longer duration (then larger size).
    """
    candidates: list[Path] = []
    for p in (phonation_1, phonation_2):
        if not p or not p.is_file():
            continue
        if _ffmpeg_can_open(p):
            candidates.append(p)
        else:
            print(f"  note: phonation unreadable, skipping → {p.name}")

    if not candidates:
        raise RuntimeError(
            f"Both phonation takes are missing or corrupt: {phonation_1}, {phonation_2}"
        )
    if len(candidates) == 1:
        print(f"  note: using sole readable phonation → {candidates[0].name}")
        return candidates[0]

    # Both readable — Rule B by duration, then size.
    scored: list[tuple[float, int, Path]] = []
    for p in candidates:
        try:
            dur = probe_duration_seconds(p)
        except RuntimeError:
            dur = 0.0
        scored.append((dur, p.stat().st_size, p))
    scored.sort(key=lambda t: (t[0], t[1]), reverse=True)
    chosen = scored[0][2]
    print(f"  note: Rule B phonation → {chosen.name}")
    return chosen


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


def _ffmpeg_to_wav(src: Path, dest_wav: Path) -> Path:
    """Convert any ffmpeg-readable audio to mono 44.1 kHz 16-bit PCM WAV."""
    dest_wav.parent.mkdir(parents=True, exist_ok=True)
    cmd = [
        "ffmpeg",
        "-y",
        "-i",
        str(src),
        "-ac",
        "1",
        "-ar",
        "44100",
        "-sample_fmt",
        "s16",
        str(dest_wav),
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0 or not dest_wav.is_file():
        err = (result.stderr or result.stdout or "").strip()
        raise RuntimeError(
            f"ffmpeg failed converting {src.name} → {dest_wav.name}"
            + (f": {err[-500:]}" if err else "")
        )
    return dest_wav


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

    Non-WAV sources (webm/m4a/mp3/…) are converted to WAV so the original
    engine file discovery + analysis path stays reliable.
    """
    files = resolve_session_files(session_dir, manifest)
    dest_folder = Path(dest_folder)
    dest_folder.mkdir(parents=True, exist_ok=True)

    for engine_key, src in files.items():
        keyword_name = ENGINE_FILENAME_KEYWORDS[engine_key]
        # Always stage as .wav — BioVoice_Baseline discovery historically
        # matched Frank-style names and ffmpeg-normalized WAVs.
        dest_name = f"{participant_label} {session_label}_ {keyword_name}.wav"
        dest_path = dest_folder / dest_name
        if src.suffix.lower() == ".wav":
            if not _ffmpeg_can_open(src):
                raise RuntimeError(f"Staged WAV is unreadable: {src}")
            shutil.copy2(src, dest_path)
        else:
            print(f"  convert {src.name} → {dest_name}")
            try:
                _ffmpeg_to_wav(src, dest_path)
            except RuntimeError as exc:
                # Phonation already preferred a readable take in resolve_session_files;
                # for other tasks surface a clear error (worker/DB status later).
                raise RuntimeError(
                    f"Corrupt or unreadable recording for {engine_key} "
                    f"({src.name}). User may need to retake this step. ({exc})"
                ) from exc

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


def _wellness_import_block(wellness_module_import: str) -> str:
    """Import block that loads CLI wellness answers (no function defs)."""
    return (
        "\n# --- CLI wellness override (import) ---\n"
        "import importlib as _CLI_importlib\n"
        f"_cli_wellness_mod = _CLI_importlib.import_module({wellness_module_import!r})\n"
        "_CLI_WELLNESS = _cli_wellness_mod.WELLNESS\n"
        "# --- end CLI wellness import ---\n"
    )


def _collect_pre_recording_override_source() -> str:
    """Non-interactive replacement for collect_pre_recording_assessment.

    Built line-by-line with no backslash-escape sequences in the generated
    source (those break when passed through re.sub replacement strings).
    """
    lines = [
        "def collect_pre_recording_assessment(session_id, session_processed_timestamp):",
        '    """CLI override: use pre-collected wellness answers (no interactive prompts)."""',
        '    print("-" * 60)',
        '    print("Pre-recording context (CLI-supplied) for " + str(session_id))',
        '    print("-" * 60)',
        '    answers = _CLI_WELLNESS["by_session"].get(session_id) or _CLI_WELLNESS.get("default") or {}',
        "    if not answers:",
        "        raise RuntimeError(",
        '            "No CLI wellness answers for session " + str(session_id) + ". Pass --wellness-file."',
        "        )",
        "    responses = {",
        '        "session_id": session_id,',
        '        "session_processed_timestamp": session_processed_timestamp,',
        "    }",
        "    for field_name in assessment_questions:",
        "        if field_name not in answers:",
        "            raise RuntimeError(",
        '                "Wellness missing key " + repr(field_name) + " for " + str(session_id)',
        "            )",
        "        responses[field_name] = int(answers[field_name])",
        '        print("  " + str(field_name) + ": " + str(responses[field_name]))',
        "    return add_wellness_anchor_scores(responses)",
        "",
    ]
    return "\n".join(lines) + "\n"


def _comparison_assessment_override_source() -> str:
    lines = [
        "def get_or_collect_comparison_assessment(session_id, session_processed_timestamp):",
        '    """CLI override: always use supplied wellness (never prompt)."""',
        "    return collect_pre_recording_assessment(session_id, session_processed_timestamp)",
        "",
    ]
    return "\n".join(lines) + "\n"


def _replace_top_level_function(source: str, func_name: str, new_def: str) -> str:
    """
    Replace a top-level `def func_name(...):` through the line before the next
    top-level def/class or end of file. Indentation-safe (no nested stub leftovers).

    Uses a callable replacement so re.sub does not reinterpret backslashes in
    the new source (e.g. turning \\n into a real newline and breaking strings).
    """
    pattern = re.compile(
        rf"(?m)^def {re.escape(func_name)}\s*\(.*?\):.*?(?=^(?:def |class )|\Z)",
        re.DOTALL,
    )
    new_block = new_def if new_def.endswith("\n") else new_def + "\n"
    new_block = new_block + "\n"

    def _repl(_match: re.Match) -> str:
        return new_block

    replaced, n = pattern.subn(_repl, source, count=1)
    if n == 0:
        # Soft fail: leave source unchanged (engine variants may rename the helper).
        return source
    return replaced


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

        # Load CLI wellness module, then *replace* interactive collectors so the
        # original def cannot win and prompt in the terminal.
        anchor = "future_comparison_domain_weights = {"
        if anchor in patched:
            patched = patched.replace(
                anchor,
                _wellness_import_block(wellness_module_import) + anchor,
                1,
            )
        else:
            # Fallback: prepend import near top after the last import line.
            patched = _wellness_import_block(wellness_module_import) + patched

        patched = _replace_top_level_function(
            patched,
            "collect_pre_recording_assessment",
            _collect_pre_recording_override_source(),
        )

        # Fully replace load_existing_csv_rows so the original body is not left
        # under a broken nested def (that caused IndentationError on the next line).
        patched = _replace_top_level_function(
            patched,
            "load_existing_csv_rows",
            "def load_existing_csv_rows(csv_path):\n"
            "    return []  # CLI: always process staged sessions fresh\n",
        )
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
            patched = patched.replace(
                anchor,
                _wellness_import_block(wellness_module_import) + anchor,
                1,
            )
        else:
            patched = _wellness_import_block(wellness_module_import) + patched

        patched = _replace_top_level_function(
            patched,
            "collect_pre_recording_assessment",
            _collect_pre_recording_override_source(),
        )
        patched = _replace_top_level_function(
            patched,
            "get_or_collect_comparison_assessment",
            _comparison_assessment_override_source(),
        )

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
