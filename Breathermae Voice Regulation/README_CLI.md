# BioVoicePrint CLI wrappers

Non-interactive entry points for the original BioVoice engines.  
**Original scripts are not modified.**

| File | Role |
|------|------|
| `biovoice_cli_common.py` | Shared helpers (task mapping, longer-phonation rule, staging, patching) |
| `run_baseline_cli.py` | Build / update personal baseline from 1–N session groups |
| `run_comparison_cli.py` | Compare one session group against a saved baseline |
| `sample_wellness.json` | Example wellness payload |

## BVP task codes expected in each session folder

| Filename stem | Required |
|---------------|----------|
| `silence_pre` | yes |
| `phonation_1` | at least one of the two |
| `phonation_2` | at least one of the two |
| `count_natural` | yes |
| `count_slow` | yes |
| `reading` | yes |
| `silence_post` | yes |

Supported extensions: `.webm`, `.m4a`, `.wav`, `.mp3`, `.ogg`, `.mp4`, `.aac`, `.caf`

**Phonation rule (B):** when both `phonation_1` and `phonation_2` exist, the longer duration is used for `step2_sustained_phonation`.

## Wellness JSON

```json
{
  "balanced_centered": 4,
  "mental_clarity_focus": 4,
  "physical_energy": 3,
  "recording_comfort_readiness": 5,
  "restored_recovered": 4
}
```

Each value is an integer 1–5.

## Baseline example

```bash
python run_baseline_cli.py \
  --participant-id 12345 \
  --device-type "iPhone 15 - Safari" \
  --session-dir /path/to/group1 \
  --session-dir /path/to/group2 \
  --session-dir /path/to/group3 \
  --wellness-file sample_wellness.json \
  --output-dir /path/to/results/12345/baseline \
  --engine-script ./BioVoice_Baseline.py \
  --keep-work-dir
```

Output of interest:
- `JSON files/baseline_reference.json` → feed this to comparison runs and store per user

## Comparison example

```bash
python run_comparison_cli.py \
  --participant-id 12345 \
  --device-type "iPhone 15 - Safari" \
  --session-dir /path/to/comparison_group \
  --baseline-reference /path/to/results/12345/baseline/JSON\ files/baseline_reference.json \
  --wellness-file sample_wellness.json \
  --output-dir /path/to/results/12345/comparison \
  --engine-script ./BioVoice_BSI_Comparison.py
```

Outputs of interest (already match BVP report fixtures):
- `JSON files/stage7_plain_language_report.json`
- `JSON files/stage7_bsi_pattern_payload.json`

## Dependencies (engine side)

Same as the original scripts:

- Python 3.10+
- `numpy`, `scipy`, `soundfile`, `parselmouth` (Praat)
- `ffmpeg` / `ffprobe` on PATH

The CLI itself only needs the stdlib plus `ffprobe` for duration checks when choosing the longer phonation file.

## How it works

1. Stages each BVP session folder into the filename pattern the original engines discover by keyword.
2. Writes a small `cli_wellness.py` module with the pre-collected answers.
3. Creates a **temporary patched copy** of the original engine (participant id, paths, wellness collectors).
4. Runs that patched copy.
5. Copies the result tree to `--output-dir`.

Original `BioVoice_Baseline.py` and `BioVoice_BSI_Comparison.py` stay untouched on disk.
