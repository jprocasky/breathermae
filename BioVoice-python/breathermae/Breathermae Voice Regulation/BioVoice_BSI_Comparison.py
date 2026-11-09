import csv                                                                                  # lets Python save table-style outputs such as CSV summaries
import hashlib                                                                              # lets Python create a de-identified participant token
import json                                                                                 # lets Python read and write structured JSON outputs
import subprocess                                                                           # lets Python call ffmpeg for audio conversion
from datetime import datetime                                                               # lets Python timestamp comparison runs
from pathlib import Path                                                                    # makes folder and file paths safer to work with

import numpy as np                                                                          # provides array math, averages, standard deviations, and signal summaries
import parselmouth                                                                          # provides Praat-based voice measures such as local jitter
import soundfile as sf                                                                      # reads standardized WAV files into Python
from scipy.signal import butter, filtfilt, hilbert                                          # provides filtering and analytic-signal tools


print("Processing BioVoice BSI-pattern comparison...")                                      # prints a progress message when the comparison script begins

# ------------------------------------------------------------------
# USER CONFIGURATION
# ------------------------------------------------------------------

participant_id = "Frank"                                                                    # sets the participant whose baseline will be used for comparison
device_type = "MacBook Microphone"                                                          # records the microphone/device used for the comparison recording
comparison_session_folder = Path("data/raw/Frank Comparison Session 1")                     # points to the future non-baseline comparison session folder
baseline_reference_path = Path("results") / participant_id / "BioVoice baseline" / "JSON files" / "baseline_reference.json"  # points to the saved personal baseline

comparison_results_dir = Path("results") / participant_id / "BioVoice BSI comparison"       # defines the participant-specific comparison results folder
csv_dir = comparison_results_dir / "CSV files"                                              # defines the subfolder for CSV outputs
json_dir = comparison_results_dir / "JSON files"                                            # defines the subfolder for JSON outputs
wav_dir = comparison_results_dir / "WAV files"                                              # defines the subfolder for converted WAV recordings
comparison_model_version = "bsi_comparison_zscore_range_v4_reliability_flags"               # labels the current comparison logic so old history rows do not mix with new calculations
for folder in (csv_dir, json_dir, wav_dir):                                                 # loops through every required output folder
    folder.mkdir(parents=True, exist_ok=True)                                               # creates the folder and any missing parent folders

tokenized_id = hashlib.sha256(participant_id.encode()).hexdigest()[:12]                     # creates a short de-identified participant token

assessment_questions = {                                                                    # defines the five neutral pre-recording context questions
    "balanced_centered": {                                                                  # opens the first context-question definition
        "question": "How balanced or centered do you feel right now?",                      # stores the first client-facing question
        "scale": "1 = Very Unbalanced, 5 = Very Balanced",                                  # stores the low and high scale anchors for question one
    },                                                                                      # closes the first context-question definition
    "mental_clarity_focus": {                                                               # opens the second context-question definition
        "question": "How mentally clear and focused do you feel at this moment?",           # stores the second client-facing question
        "scale": "1 = Very Unclear, 5 = Very Clear",                                        # stores the low and high scale anchors for question two
    },                                                                                      # closes the second context-question definition
    "physical_energy": {                                                                    # opens the third context-question definition
        "question": "How physically energized do you feel right now?",                      # stores the third client-facing question
        "scale": "1 = Very Low Energy, 5 = Very High Energy",                               # stores the low and high scale anchors for question three
    },                                                                                      # closes the third context-question definition
    "recording_comfort_readiness": {                                                        # opens the fourth context-question definition
        "question": "How comfortable and ready do you feel for this recording?",            # stores the fourth client-facing question
        "scale": "1 = Not Comfortable/Ready, 5 = Very Comfortable/Ready",                   # stores the low and high scale anchors for question four
    },                                                                                      # closes the fourth context-question definition
    "restored_recovered": {                                                                 # opens the fifth context-question definition
        "question": "How restored or recovered do you feel today overall?",                 # stores the fifth client-facing question
        "scale": "1 = Not Restored, 5 = Fully Restored",                                    # stores the low and high scale anchors for question five
    },                                                                                      # closes the fifth context-question definition
}                                                                                           # closes the full assessment-question dictionary

bsi_framework_weights = {                                                                   # stores the neutral BSI-style weighting framework used for voice-pattern comparison
    "variability_consistency": 0.20,                                                        # weights feature shifts related to vocal variability
    "harmonic_structure_consistency": 0.15,                                                 # weights feature shifts related to harmonic and spectral structure
    "transition_continuity": 0.15,                                                          # weights feature shifts related to breath-to-voice transition continuity
    "micro_modulation_consistency": 0.15,                                                   # weights feature shifts related to fine-grain modulation patterns
    "signal_clarity": 0.10,                                                                 # weights feature shifts related to signal clarity
    "distribution_balance": 0.10,                                                           # weights feature shifts related to spectral and formant balance
    "sustained_phonation_consistency": 0.15,                                                # weights feature shifts related to sustained phonation consistency
}                                                                                           # closes the BSI-style weighting dictionary

voice_task_keys = {                                                                         # defines the voiced recordings used for baseline-relative comparison
    "step2_sustained_phonation",                                                            # includes the sustained-vowel recording
    "step3a_counting_natural",                                                              # includes the natural counting recording
    "step3b_counting_slow",                                                                 # includes the slower controlled counting recording
    "step4_reading",                                                                        # includes the standardized reading recording
}                                                                                           # closes the voiced-task set

baseline_feature_names = [                                                                  # lists the acoustic features used in baseline-relative comparison
    "f0_mean_hz",                                                                           # average fundamental frequency
    "hnr_db",                                                                               # harmonic-to-noise ratio
    "spectral_centroid_hz",                                                                 # spectral center of gravity
    "spectral_bandwidth_hz",                                                                # spectral spread
    "jitter_local",                                                                         # cycle-timing variation
    "shimmer_local",                                                                        # cycle-amplitude variation
    "zero_crossing_rate",                                                                   # waveform sign-change rate
    "envelope_variance",                                                                    # amplitude-envelope variability
    "tremor_band_power_3_12hz",                                                             # low-frequency modulation power
    "approximate_entropy",                                                                  # first irregularity estimate
    "sample_entropy",                                                                       # second irregularity estimate
    "fractal_dimension",                                                                    # geometric-complexity estimate
    "lyapunov_proxy",                                                                       # short-window divergence proxy
    "resp_sync_lag_sec",                                                                    # breath-to-voice synchronization lag
    "resp_amp_variance",                                                                    # respiratory-scale amplitude variability
    "phonation_stability_exhalation",                                                       # speech-envelope spread during exhale-proxy regions
]                                                                                           # closes the baseline-feature name list

feature_specific_task_sources = {                                                          # defines feature-specific task sources so comparison matches the baseline reference
    "jitter_local": {"step2_sustained_phonation"},                                          # measures cycle-timing variation only from sustained AHH because connected speech can create false zeros
    "shimmer_local": {"step2_sustained_phonation"},                                         # measures cycle-amplitude variation only from sustained AHH because it needs a steady vowel
    "tremor_band_power_3_12hz": {"step2_sustained_phonation"},                              # measures low-frequency vocal modulation from sustained AHH where tremor-like modulation is cleaner
    "phonation_stability_exhalation": {"step2_sustained_phonation"},                        # measures sustained voice steadiness from sustained AHH
    "resp_sync_lag_sec": {"step3a_counting_natural", "step3b_counting_slow", "step4_reading"},  # estimates breath-to-voice timing from connected speech tasks
    "resp_amp_variance": {"step3a_counting_natural", "step3b_counting_slow", "step4_reading"},  # estimates respiratory-scale amplitude variation from connected speech tasks
    "envelope_variance": {"step3a_counting_natural", "step3b_counting_slow", "step4_reading"},  # measures vocal energy variation from connected speech tasks
    "approximate_entropy": {"step3a_counting_natural", "step3b_counting_slow", "step4_reading"},  # measures pattern complexity from connected speech tasks
    "sample_entropy": {"step3a_counting_natural", "step3b_counting_slow", "step4_reading"},  # measures adaptive variability from connected speech tasks
    "fractal_dimension": {"step3a_counting_natural", "step3b_counting_slow", "step4_reading"},  # measures pattern organization from connected speech tasks
    "lyapunov_proxy": {"step3a_counting_natural", "step3b_counting_slow", "step4_reading"},  # measures dynamic stability from connected speech tasks
}                                                                                           # closes the feature-specific task-source dictionary


def task_source_for_feature(feature_name):                                                   # returns the correct recording-task set for a given feature
    return feature_specific_task_sources.get(feature_name, voice_task_keys)                  # uses a specific task source when defined, otherwise all voiced tasks


def feature_value_is_valid(row, feature_name):                                               # checks whether a feature row should contribute to scoring
    if feature_name == "jitter_local":                                                       # applies jitter-specific reliability filtering
        return bool(row.get("jitter_local_valid"))                                           # keeps only Praat jitter values marked valid
    if feature_name == "resp_sync_lag_sec":                                                  # applies respiratory timing reliability filtering
        return bool(row.get("resp_sync_lag_valid"))                                          # keeps only measurable audio-envelope lag values
    if feature_name in {"f0_mean_hz", "hnr_db", "shimmer_local", "phonation_stability_exhalation"}:  # applies reliability filtering for zero-prone acoustic features
        return row.get(f"{feature_name}_scoring_status") in {"valid", "low_confidence"}     # keeps usable values and traceable low-confidence values
    return row.get(feature_name) not in ("", None)                                           # keeps non-empty values for all other features


# ------------------------------------------------------------------
# BASIC HELPERS
# ------------------------------------------------------------------


def ask_integer_response(question, scale_text):                                             # defines a helper that safely collects one 1-5 response
    while True:                                                                             # keeps asking until the user enters a valid response
        raw_value = input(f"{question}\nScale: {scale_text}\nEnter 1-5: ").strip()          # prints the question and scale anchors, then reads the answer
        if raw_value in {"1", "2", "3", "4", "5"}:                                          # checks whether the answer is one of the allowed values
            return int(raw_value)                                                           # returns the valid answer as an integer
        print("Please enter a whole number from 1 to 5.")                                   # tells the user how to correct an invalid answer


def collect_pre_recording_assessment(session_id, session_processed_timestamp):              # defines a helper that collects all five context responses for one comparison session
    print("\n------------------------------------------------------------")                 # prints a visual divider before the questionnaire
    print(f"Pre-recording context questions for {session_id}")                              # prints which session the answers belong to
    print("These answers provide context only and do not score the audio.")                 # explains that the answers are contextual, not scoring inputs
    print("------------------------------------------------------------")                   # prints a closing visual divider for the heading
    responses = {                                                                           # starts the response record with session tracking metadata
        "session_id": session_id,                                                           # stores the matching session folder name
        "session_processed_timestamp": session_processed_timestamp,                         # stores when this comparison session began processing
    }                                                                                       # closes the response-record metadata dictionary
    for field_name, prompt in assessment_questions.items():                                 # loops through the five questionnaire items
        responses[field_name] = ask_integer_response(prompt["question"], prompt["scale"])   # stores the validated 1-5 answer for the current question
        print()                                                                             # adds a blank line so the terminal questionnaire is easier to read
    return add_wellness_anchor_scores(responses)                                            # returns the completed response record with its wellness-anchor score


def load_existing_csv_rows(csv_path):                                                       # loads saved comparison questionnaire rows when they exist
    if not csv_path.exists():                                                               # checks whether the CSV exists yet
        return []                                                                           # returns no rows when nothing has been saved
    with open(csv_path, "r", newline="", encoding="utf-8") as file:                         # opens the saved CSV
        return list(csv.DictReader(file))                                                   # returns every saved row as a dictionary


def write_csv_rows(csv_path, rows):                                                         # writes a list of dictionaries to CSV with complete columns
    if not rows:                                                                            # checks whether there is anything to write
        return                                                                              # skips empty CSV writes
    fieldnames = sorted({key for row in rows for key in row.keys()})                        # builds a stable complete header from all rows
    with open(csv_path, "w", newline="", encoding="utf-8") as file:                         # opens the CSV for overwrite output
        writer = csv.DictWriter(file, fieldnames=fieldnames)                                # creates the CSV writer
        writer.writeheader()                                                                # writes the header row
        writer.writerows(rows)                                                              # writes all questionnaire rows


def get_or_collect_comparison_assessment(session_id, session_processed_timestamp):           # reuses saved comparison answers or collects them once
    assessment_path = csv_dir / "pre_recording_assessment.csv"                              # defines where comparison check-ins are stored
    assessment_rows = load_existing_csv_rows(assessment_path)                               # loads any prior comparison check-ins
    for index, row in enumerate(assessment_rows):                                           # loops through saved rows looking for this comparison session
        if row.get("session_id") == session_id:                                             # checks whether the current comparison session was already answered
            print(f"Reusing saved pre-recording context answers for {session_id}.")         # explains that the script will not re-ask stale questions
            assessment_rows[index] = add_wellness_anchor_scores(dict(row))                  # backfills current wellness-anchor fields if needed
            write_csv_rows(assessment_path, assessment_rows)                                # rewrites the CSV so older rows gain new calculated fields
            return assessment_rows[index]                                                   # returns the saved and enriched row
    assessment_row = collect_pre_recording_assessment(session_id, session_processed_timestamp)  # collects answers when this comparison session is new
    assessment_rows.append(assessment_row)                                                   # adds the new comparison check-in
    write_csv_rows(assessment_path, assessment_rows)                                        # saves the comparison check-in for future reruns
    return assessment_row                                                                   # returns the new row


def safe_int(value, default=0):                                                             # safely converts questionnaire values to integers
    try:                                                                                    # starts protected conversion
        return int(float(value))                                                            # returns the numeric value even if loaded from CSV/JSON text
    except (TypeError, ValueError):                                                         # catches missing or invalid values
        return int(default)                                                                 # returns the default when conversion fails


def add_wellness_anchor_scores(response_row):                                               # adds total and 0-100 wellness-anchor scores to one questionnaire row
    total_score = sum(safe_int(response_row.get(field), 0) for field in assessment_questions)  # totals the five 1-to-5 answers
    max_score = len(assessment_questions) * 5                                               # calculates the maximum possible total
    response_row["wellness_anchor_total_score"] = total_score                               # stores the raw total out of 25
    response_row["wellness_anchor_score_0_100"] = round((total_score / max_score) * 100.0, 6) if max_score else 0.0  # stores normalized self-report score
    response_row["wellness_anchor_band"] = wellness_anchor_band(response_row["wellness_anchor_score_0_100"])  # stores the subjective wellness band
    response_row["wellness_anchor_direction"] = "higher = stronger self-reported pre-recording wellness"  # stores the score direction
    return response_row                                                                     # returns the enriched questionnaire row


def wellness_anchor_band(score):                                                            # maps a 0-100 self-report wellness score to neutral display language
    score = float(score or 0.0)                                                             # converts missing values to zero for stable banding
    if score >= 85.0:                                                                       # checks for very strong pre-recording wellness
        return "Strong Subjective Wellness"                                                 # returns the strongest self-report band
    if score >= 70.0:                                                                       # checks for generally positive pre-recording wellness
        return "Generally Steady Subjective Wellness"                                       # returns the steady self-report band
    if score >= 50.0:                                                                       # checks for mixed or watch-zone self-report
        return "Mixed Subjective Wellness"                                                  # returns the mixed self-report band
    return "Lower Subjective Wellness"                                                      # returns the lower self-report band


def get_baseline_wellness_anchor(baseline_reference):                                       # extracts or rebuilds the baseline self-report anchor from the saved baseline JSON
    assessment_block = baseline_reference.get("pre_recording_assessment", {})               # reads the baseline questionnaire block
    saved_anchor = assessment_block.get("wellness_anchor_reference", {})                    # reads the newer explicit anchor summary when present
    if saved_anchor.get("baseline_wellness_anchor_score_0_100") is not None:                # checks whether the newer baseline summary is available
        return saved_anchor                                                                 # returns the saved anchor directly
    response_rows = assessment_block.get("responses_by_session", [])                        # falls back to raw baseline questionnaire rows
    scored_rows = [add_wellness_anchor_scores(dict(row)) for row in response_rows]          # backfills per-session wellness scores for older baseline files
    scores = [float(row["wellness_anchor_score_0_100"]) for row in scored_rows]             # collects the baseline self-report scores
    if not scores:                                                                          # checks whether baseline self-report data exists
        return {                                                                            # returns a traceable unavailable baseline anchor
            "baseline_wellness_anchor_score_0_100": None,                                  # stores no score when unavailable
            "baseline_wellness_anchor_band": "Unavailable",                                # stores an unavailable band
            "score_range": [],                                                              # stores no range
            "n_sessions": 0,                                                                # stores zero contributing sessions
            "score_direction": "higher = stronger self-reported pre-recording wellness",    # stores the score direction
            "interpretation_note": "No baseline pre-recording wellness anchor was available for comparison.",  # explains missing context
        }                                                                                   # closes unavailable anchor
    return {                                                                                # returns a rebuilt baseline anchor
        "baseline_wellness_anchor_score_0_100": round(float(np.mean(scores)), 6),           # stores the average baseline wellness anchor
        "baseline_wellness_anchor_band": wellness_anchor_band(float(np.mean(scores))),      # stores the average baseline wellness band
        "score_range": [round(float(np.min(scores)), 6), round(float(np.max(scores)), 6)],  # stores the baseline self-report range
        "n_sessions": len(scores),                                                          # stores the number of baseline check-ins
        "score_direction": "higher = stronger self-reported pre-recording wellness",        # stores the score direction
        "interpretation_note": "Rebuilt from baseline pre-recording responses because the explicit wellness-anchor reference was not present.",  # explains compatibility handling
    }                                                                                       # closes rebuilt anchor


def nullable_round(value, digits=2):                                                        # rounds optional report values without turning missing data into zero
    return round(float(value), digits) if value not in (None, "") and np.isfinite(float(value)) else None


def get_baseline_biovoiceprint(baseline_reference):                                        # extracts the saved baseline RDI distribution when available
    saved_reference = baseline_reference.get("baseline_biovoiceprint", {})
    if saved_reference.get("mean_rdi_score_0_100") is not None:
        return saved_reference
    return {
        "mean_rdi_score_0_100": None,
        "rdi_standard_deviation": None,
        "rdi_score_range": [],
        "n_sessions": 0,
        "session_rdi_scores": [],
        "score_direction": "higher = greater baseline-relative voice-pattern shift",
        "interpretation_note": "No saved BioVoicePrint baseline RDI distribution was available. Re-run the baseline builder to populate this field.",
    }


def current_wellness_anchor_items(assessment_row):                                         # stores the five Wellness Anchor items separately for descriptive reporting
    return {
        field: {
            "raw_score_1_5": safe_int(assessment_row.get(field), 0),
            "normalized_score_0_100": round(safe_int(assessment_row.get(field), 0) / 5.0 * 100.0, 6),
        }
        for field in assessment_questions
    }


def wellness_anchor_item_pattern(current_items, baseline_reference):                        # identifies which check-in items are lowest or most changed without mapping them to voice features
    item_baselines = baseline_reference.get("pre_recording_assessment", {}).get("reference_summary", {})
    pattern = []
    for field, item in current_items.items():
        baseline_item = item_baselines.get(field, {})
        baseline_mean = baseline_item.get("mean_normalized_score_0_100")
        current_score = item["normalized_score_0_100"]
        pattern.append({
            "item": field,
            "question": assessment_questions[field]["question"],
            "current_score_0_100": current_score,
            "baseline_mean_0_100": baseline_mean,
            "change_from_baseline": nullable_round(current_score - float(baseline_mean), 6) if baseline_mean is not None else None,
        })
    return sorted(pattern, key=lambda row: row["change_from_baseline"] if row["change_from_baseline"] is not None else 0.0)


def classify_wellness_alignment(subjective_strain_z, objective_shift_z):                    # classifies direction using within-person standardized movement
    subjective_stable = abs(subjective_strain_z) < 0.5
    objective_stable = abs(objective_shift_z) < 0.5
    subjective_elevated = subjective_strain_z >= 0.5
    objective_elevated = objective_shift_z >= 0.5
    subjective_improved = subjective_strain_z <= -0.5
    objective_improved = objective_shift_z <= -0.5
    if subjective_stable and objective_stable:
        return {"code": "BROADLY_ALIGNED_STABLE", "label": "Broadly Aligned and Stable", "meaning": "The person feels close to their usual wellness baseline, and the voice pattern also remains within its usual baseline-relative variation."}
    if subjective_elevated and objective_elevated:
        return {"code": "CONVERGENT_SHIFT", "label": "Shared Baseline-Relative Shift", "meaning": "Both the wellness check-in and BioVoicePrint changed in the same general direction. This is a pattern to monitor, not evidence of a cause or diagnosis."}
    if subjective_stable and objective_elevated:
        return {"code": "VOICE_PATTERN_LEADING", "label": "Voice-Pattern Shift With Stable Self-Report", "meaning": "The person feels close to their usual state, while the voice pattern shows greater-than-usual change. This may reflect early variation, recording context, vocal use, hydration, fatigue, or another unassigned influence."}
    if subjective_elevated and objective_stable:
        return {"code": "SELF_REPORT_LEADING", "label": "Self-Reported Shift With Stable Voice Pattern", "meaning": "The person reports feeling different from usual, while the voice pattern remains close to its normal range. The lived experience remains important even when it is not mirrored in this voice recording."}
    if (subjective_elevated and objective_improved) or (subjective_improved and objective_elevated):
        return {"code": "DIVERGENT_DIRECTION", "label": "Different Direction of Change", "meaning": "The wellness check-in and BioVoicePrint moved in different directions. This difference should be observed over time rather than interpreted from one session."}
    return {"code": "MIXED_PATTERN", "label": "Mixed Baseline-Relative Pattern", "meaning": "The two measurements show a mixed pattern that requires additional sessions for meaningful interpretation."}


def determine_comparison_strength(wellness_baseline_sessions, voice_baseline_sessions, paired_comparison_sessions, recording_quality_passed):  # labels maturity without unsupported probability language
    if not recording_quality_passed:
        return {"level": "Insufficient", "reason": "The current voice recording did not meet quality requirements."}
    if wellness_baseline_sessions < 3 or voice_baseline_sessions < 3:
        return {"level": "Insufficient", "reason": "A minimum personal baseline has not yet been established."}
    if paired_comparison_sessions < 5:
        return {"level": "Preliminary", "reason": "The comparison is based on a limited number of paired sessions and should be used for awareness only."}
    if paired_comparison_sessions < 10:
        return {"level": "Developing", "reason": "A personal pattern is beginning to form, but additional paired sessions will improve interpretation."}
    return {"level": "Established Longitudinal Pattern", "reason": "Enough paired sessions are available to interpret repeated within-person patterns, subject to recording quality and contextual factors."}


def build_session_quality(stage4_metrics):                                                  # exposes interpretation gates and context placeholders
    recording_quality_passed = bool(stage4_metrics.get("rdi_score_0_100") is not None)
    return {
        "recording_quality_passed": recording_quality_passed,
        "noise_floor_passed": True,
        "microphone_consistency_passed": True,
        "protocol_completion_passed": True,
        "vocal_discomfort_reported": False,
        "acute_context_flags": [],
        "available_context_flags": [
            "recent_strenuous_exercise",
            "high_vocal_use",
            "dehydration_possible",
            "poor_sleep",
            "caffeine_recent",
            "respiratory_symptoms",
            "different_recording_device",
            "unusual_environment",
        ],
        "interpretation_allowed": recording_quality_passed,
        "quality_note": "Context flags do not automatically change the score. They explain conditions that may affect interpretation.",
    }


def trend_status_from_values(values, minimum_sessions=5):                                   # gives simple trend direction when enough paired sessions exist
    if len(values) < minimum_sessions:
        return "Not Yet Established"
    slope = trend_slope(values)
    if abs(slope) < 0.5:
        return "Stable"
    return "Increasing" if slope > 0 else "Decreasing"


def build_alignment_trend(paired_history, current_alignment_type):                          # summarizes longitudinal congruence maturity
    rdi_values = [row.get("rdi_score_0_100") for row in paired_history if row.get("rdi_score_0_100") is not None]
    return {
        "paired_sessions_available": len(paired_history),
        "trend_status": "Not Yet Established" if len(paired_history) < 5 else "Trend Tracking Active",
        "subjective_trend": "Not Yet Established",
        "biovoiceprint_trend": trend_status_from_values(rdi_values),
        "alignment_pattern": current_alignment_type.get("label", "Not Yet Established"),
        "voice_pattern_shift_with_stable_self_report_count": 1 if current_alignment_type.get("code") == "VOICE_PATTERN_LEADING" else 0,
        "self_report_shift_with_stable_voice_pattern_count": 1 if current_alignment_type.get("code") == "SELF_REPORT_LEADING" else 0,
        "minimum_sessions_for_trend": 5,
        "trend_note": "Do not interpret a single mismatch as physiology preceding awareness. That can only become a testable hypothesis after repeated paired sessions.",
    }


def generate_alignment_summary(current_wellness, current_rdi, baseline_wellness, baseline_rdi, alignment_type, comparison_strength):  # creates human wording for the congruence section
    wellness_phrase = f"Your wellness check-in was {round_1(current_wellness)}"
    if baseline_wellness is not None:
        wellness_phrase += f" compared with your usual baseline of {round_1(baseline_wellness)}"
    voice_phrase = f"Your BioVoicePrint RDI was {round_1(current_rdi)}"
    if baseline_rdi is not None:
        voice_phrase += f" compared with your usual baseline RDI of {round_1(baseline_rdi)}"
    return f"{wellness_phrase}. {voice_phrase}. {alignment_type['meaning']} This comparison is {comparison_strength['level'].lower()} and becomes more meaningful when the same directional pattern repeats across valid paired sessions."


def build_subjective_objective_alignment(assessment_row, baseline_reference, stage4_metrics, customer_scores, paired_history):  # builds the baseline-relative Wellness Congruence layer
    current_wellness = float(assessment_row.get("wellness_anchor_score_0_100", 0.0))
    baseline_anchor = get_baseline_wellness_anchor(baseline_reference)
    baseline_biovoiceprint = get_baseline_biovoiceprint(baseline_reference)
    baseline_wellness = baseline_anchor.get("baseline_wellness_anchor_score_0_100")
    wellness_std = baseline_anchor.get("standard_deviation")
    current_rdi = float(stage4_metrics["rdi_score_0_100"])
    baseline_rdi = baseline_biovoiceprint.get("mean_rdi_score_0_100")
    baseline_rdi_std = baseline_biovoiceprint.get("rdi_standard_deviation")
    baseline_alignment = float(customer_scores["baseline_alignment"]["score"])
    subjective_change = current_wellness - float(baseline_wellness) if baseline_wellness is not None else None
    objective_rdi_change = current_rdi - float(baseline_rdi) if baseline_rdi is not None else None
    can_calculate_subjective_z = wellness_std not in (None, "") and float(wellness_std) > 0 and baseline_anchor.get("n_sessions", 0) >= 3
    can_calculate_objective_z = baseline_rdi_std not in (None, "") and float(baseline_rdi_std) > 0 and baseline_biovoiceprint.get("n_sessions", 0) >= 3
    subjective_strain_z = (float(baseline_wellness) - current_wellness) / float(wellness_std) if can_calculate_subjective_z else None
    objective_shift_z = (current_rdi - float(baseline_rdi)) / float(baseline_rdi_std) if can_calculate_objective_z else None
    alignment_type = classify_wellness_alignment(subjective_strain_z, objective_shift_z) if subjective_strain_z is not None and objective_shift_z is not None else {"code": "DESCRIPTIVE_ONLY", "label": "Descriptive Comparison Only", "meaning": "There are not yet enough stable baseline observations to standardize the comparison."}
    session_quality = build_session_quality(stage4_metrics)
    comparison_strength = determine_comparison_strength(baseline_anchor.get("n_sessions", 0), baseline_biovoiceprint.get("n_sessions", 0), len(paired_history), session_quality["recording_quality_passed"])
    descriptive_index_gap = current_wellness - baseline_alignment
    current_items = current_wellness_anchor_items(assessment_row)
    return {
        "section_title": "Wellness Congruence",
        "current_session_wellness_anchor": {
            "score_0_100": round(current_wellness, 6),
            "raw_total_0_25": assessment_row.get("wellness_anchor_total_score"),
            "band": assessment_row.get("wellness_anchor_band", wellness_anchor_band(current_wellness)),
            "items": current_items,
            "item_pattern": wellness_anchor_item_pattern(current_items, baseline_reference),
        },
        "baseline_wellness_anchor": {
            "score_0_100": baseline_wellness,
            "standard_deviation": wellness_std,
            "band": baseline_anchor.get("baseline_wellness_anchor_band"),
            "score_range": baseline_anchor.get("score_range", []),
            "n_sessions": baseline_anchor.get("n_sessions", 0),
        },
        "current_biovoiceprint": {
            "rdi_score_0_100": round(current_rdi, 6),
            "rdi_band": stage4_metrics.get("rdi_band"),
            "baseline_alignment_score_0_100": round(baseline_alignment, 6),
        },
        "baseline_biovoiceprint": {
            "mean_rdi_score_0_100": baseline_rdi,
            "rdi_standard_deviation": baseline_rdi_std,
            "rdi_score_range": baseline_biovoiceprint.get("rdi_score_range", []),
            "n_sessions": baseline_biovoiceprint.get("n_sessions", 0),
        },
        "baseline_relative_comparison": {
            "subjective_change_points": nullable_round(subjective_change, 6),
            "objective_rdi_change_points": nullable_round(objective_rdi_change, 6),
            "subjective_strain_z": nullable_round(subjective_strain_z),
            "objective_shift_z": nullable_round(objective_shift_z),
            "comparison_direction": "Positive standardized values represent greater baseline-relative strain or voice-pattern shift.",
        },
        "descriptive_index_comparison": {
            "index_gap": round(descriptive_index_gap, 6),
            "interpretation_limit": "The Wellness Anchor and BioVoicePrint alignment scores are different constructs. Their raw difference is descriptive only and is not treated as a physiological mismatch.",
        },
        "comparison_metrics": {
            "subjective_change_from_baseline": nullable_round(subjective_change, 6),
            "objective_rdi_change_from_baseline": nullable_round(objective_rdi_change, 6),
            "subjective_objective_gap": round(descriptive_index_gap, 6),
            "descriptive_index_gap": round(descriptive_index_gap, 6),
        },
        "alignment_type": alignment_type,
        "comparison_strength": comparison_strength,
        "session_quality": session_quality,
        "longitudinal_alignment": build_alignment_trend(paired_history, alignment_type),
        "summary": generate_alignment_summary(current_wellness, current_rdi, baseline_wellness, baseline_rdi, alignment_type, comparison_strength),
        "monitoring_note": "Because both the wellness check-in and BioVoicePrint are compared with their own baselines first, this result strengthens future interpretation without treating the two 0-100 scores as equivalent.",
        "boundary": "This section compares a self-reported wellness snapshot with a baseline-relative voice-pattern signal. It does not diagnose stress, autonomic dysfunction, disease, or a medical or mental health condition, and it does not assign a cause to either measurement.",
    }


def weighted_mean(values, weights):                                                         # defines a helper for weighted 0-100 composite calculations
    filtered_pairs = [                                                                      # starts a cleaned list of valid value-weight pairs
        (float(value), float(weight))                                                       # converts each valid value and weight to floats
        for value, weight in zip(values, weights)                                           # loops through values and weights together
        if value not in (None, "") and np.isfinite(float(value)) and float(weight) > 0      # keeps only numeric values with positive weights
    ]                                                                                       # closes the cleaned pair list
    if not filtered_pairs:                                                                  # checks whether any valid values remain
        return 0.0                                                                          # returns zero when no usable weighted mean can be calculated
    values = np.asarray([pair[0] for pair in filtered_pairs], dtype=np.float64)             # converts cleaned values to a numeric array
    weights = np.asarray([pair[1] for pair in filtered_pairs], dtype=np.float64)            # converts cleaned weights to a numeric array
    if len(values) == 0 or len(weights) == 0 or np.sum(weights) == 0:                       # checks whether the weighted mean can be calculated safely
        return 0.0                                                                          # returns zero when no usable weighted mean can be calculated
    return float(np.sum(values * weights) / np.sum(weights))                                # returns sum(value times weight) divided by sum(weights)


def round_optional(value, digits=6):                                                        # rounds a value only when it is available and numeric
    return round(float(value), digits) if value not in (None, "") and np.isfinite(float(value)) else None  # returns None for unavailable values


def clip_0_100(value):                                                                      # defines a helper that keeps display values on the 0-100 scale
    return float(np.clip(value, 0.0, 100.0))                                                # clips values below 0 or above 100


def pattern_band(score):                                                                    # maps a 0-100 baseline-relative shift score to neutral display language
    return customer_shift_band(score)                                                       # uses the current six-level BioVoice severity scale


def pattern_color(score):                                                                   # maps the neutral pattern band to familiar display colors
    return customer_shift_color(score)                                                      # uses the current six-level BioVoice color scale


def customer_shift_band(score):                                                            # maps internal shift values to tighter customer-facing baseline-alignment language
    score = clip_0_100(score)                                                               # keeps the incoming shift score on the 0-100 scale
    if score <= 12.5:                                                                       # assigns 0-12.5 to the baseline-consistent category
        return "Baseline Consistent"                                                        # returns the baseline-consistent label
    if score <= 25.0:                                                                       # assigns values greater than 12.5 through 25 to the minimal-shift category
        return "Minimal Shift"                                                              # returns the minimal-shift label
    if score <= 37.5:                                                                       # assigns values greater than 25 through 37.5 to the mild-shift category
        return "Mild Pattern Shift"                                                         # returns the mild-shift label
    if score <= 50.0:                                                                       # assigns values greater than 37.5 through 50 to the moderate-shift category
        return "Moderate Pattern Shift"                                                     # returns the moderate-shift label
    if score <= 75.0:                                                                       # assigns values greater than 50 through 75 to the significant-shift category
        return "Significant Pattern Shift"                                                  # returns the significant-shift label
    return "Extreme Pattern Shift"                                                          # assigns values greater than 75 to the extreme-shift category


def customer_shift_color(score):                                                           # maps tighter customer bands to display colors
    score = clip_0_100(score)                                                               # keeps the incoming shift score on the 0-100 scale
    if score <= 12.5:                                                                       # assigns 0-12.5 to the baseline-consistent color
        return "green"                                                                      # returns green for baseline-consistent display
    if score <= 25.0:                                                                       # assigns values greater than 12.5 through 25 to the minimal-shift color
        return "light_green"                                                                # returns light green for minimal pattern shift
    if score <= 37.5:                                                                       # assigns values greater than 25 through 37.5 to the mild-shift color
        return "yellow"                                                                     # returns yellow for mild pattern shift
    if score <= 50.0:                                                                       # assigns values greater than 37.5 through 50 to the moderate-shift color
        return "orange"                                                                     # returns orange for moderate pattern shift
    if score <= 75.0:                                                                       # assigns values greater than 50 through 75 to the significant-shift color
        return "red"                                                                        # returns red for significant pattern shift
    return "deep_red"                                                                       # returns deep red for extreme pattern shift


def sd_severity_band(abs_z_score):                                                          # maps personal standard-deviation distance to statistically grounded severity language
    if abs_z_score <= 0.50:                                                                 # assigns values within one-half SD of personal mean to baseline consistency
        return "Baseline Consistent"                                                        # returns the baseline-consistent SD label
    if abs_z_score <= 1.00:                                                                 # assigns values greater than 0.50 through 1.00 SD to minimal shift
        return "Minimal Shift"                                                              # returns the minimal-shift SD label
    if abs_z_score <= 1.50:                                                                 # assigns values greater than 1.00 through 1.50 SD to mild shift
        return "Mild Pattern Shift"                                                         # returns the mild-shift SD label
    if abs_z_score <= 2.00:                                                                 # assigns values greater than 1.50 through 2.00 SD to moderate shift
        return "Moderate Pattern Shift"                                                     # returns the moderate-shift SD label
    if abs_z_score <= 3.00:                                                                 # assigns values greater than 2.00 through 3.00 SD to significant shift
        return "Significant Pattern Shift"                                                  # returns the significant-shift SD label
    return "Extreme Pattern Shift"                                                          # assigns values greater than 3.00 SD to the extreme-shift SD label


def sd_severity_color(abs_z_score):                                                         # maps SD severity bands to the six visual severity colors
    if abs_z_score <= 0.50:                                                                 # checks whether the value is within one-half SD of personal mean
        return "green"                                                                      # returns green for baseline consistency
    if abs_z_score <= 1.00:                                                                 # checks whether the value is greater than 0.50 through 1.00 SD
        return "light_green"                                                                # returns light green for minimal shift
    if abs_z_score <= 1.50:                                                                 # checks whether the value is greater than 1.00 through 1.50 SD
        return "yellow"                                                                     # returns yellow for mild shift
    if abs_z_score <= 2.00:                                                                 # checks whether the value is greater than 1.50 through 2.00 SD
        return "orange"                                                                     # returns orange for moderate shift
    if abs_z_score <= 3.00:                                                                 # checks whether the value is greater than 2.00 through 3.00 SD
        return "red"                                                                        # returns red for significant shift
    return "deep_red"                                                                       # returns deep red for extreme shift


def sd_shift_score(abs_z_score):                                                            # converts absolute Z-score distance into a backend 0-100 shift score
    return clip_0_100(abs_z_score * 25.0)                                                   # maps 0.5 SD to 12.5, 1 SD to 25, 2 SD to 50, and 3 SD to 75


def severity_rank(label):                                                                   # converts severity labels from either SD or range logic into comparable ranks
    ranks = {                                                                               # opens the label-to-rank lookup
        "Baseline Consistent": 0,                                                           # ranks baseline consistency as lowest severity
        "Minimal Shift": 1,                                                                 # ranks minimal shift above baseline consistency
        "Mild Pattern Shift": 2,                                                            # ranks mild shift above minimal shift
        "Moderate Pattern Shift": 3,                                                        # ranks moderate shift above mild shift
        "Significant Pattern Shift": 4,                                                     # ranks significant shift above moderate shift
        "Extreme Pattern Shift": 5,                                                         # ranks extreme shift as highest severity
    }                                                                                       # closes the label-to-rank lookup
    return ranks.get(label, 0)                                                              # returns baseline-consistent rank when an unexpected label appears


def final_feature_severity(sd_band, sd_color, sd_score, range_band, range_color, range_score):  # chooses the final feature label/color from the worse severity source
    sd_rank = severity_rank(sd_band)                                                        # converts the SD severity label into a comparable rank
    range_rank = severity_rank(range_band)                                                  # converts the range severity label into a comparable rank
    if sd_rank > range_rank:                                                                # checks whether SD severity is worse than range severity
        return sd_band, sd_color, "sd_z_score"                                              # returns SD label/color/source when SD is worse
    if range_rank > sd_rank:                                                                # checks whether range severity is worse than SD severity
        return range_band, range_color, "personal_min_max_range"                            # returns range label/color/source when range is worse
    if sd_score >= range_score:                                                             # breaks equal-rank ties using the larger numeric score
        return sd_band, sd_color, "sd_z_score"                                              # returns SD label/color/source when SD score is larger or equal
    return range_band, range_color, "personal_min_max_range"                                # returns range label/color/source when range score is larger


def customer_voice_shift_label(score):                                                      # creates the simplest customer-facing voice-pattern shift label
    score = clip_0_100(score)                                                               # keeps the incoming shift score on the 0-100 scale
    return customer_shift_band(score)                                                       # uses the same six-level score bands as the rest of the comparison layer


def customer_strength_label(score):                                                         # maps 0-100 customer strength scores to simple labels
    score = clip_0_100(score)                                                               # keeps the incoming strength score on the 0-100 scale
    if score >= 90.0:                                                                       # checks whether the score is very close to baseline consistency
        return "Strong"                                                                     # returns the strongest customer label
    if score >= 80.0:                                                                       # checks whether the score remains stable overall
        return "Stable"                                                                     # returns the stable customer label
    if score >= 65.0:                                                                       # checks whether the score is still acceptable but worth watching
        return "Watch"                                                                      # returns a watch label without implying pathology
    if score >= 50.0:                                                                       # checks whether the score shows more visible variability
        return "Variable"                                                                   # returns a variable-pattern label
    return "Shifted"                                                                        # returns a larger-shift label


def trend_direction_label(stage6_trends):                                                   # summarizes longitudinal trend direction when enough comparison sessions exist
    if stage6_trends["comparison_session_count"] < 5:                                      # checks whether there are enough comparison sessions for a directional trend
        return "Not enough sessions yet"                                                    # returns a provisional trend label
    slope = stage6_trends["overall_pattern_shift_slope"]                                   # reads the overall pattern-shift slope
    if slope < -1.0:                                                                        # checks whether baseline-relative shift is decreasing over time
        return "Improving"                                                                  # returns improving because lower shift means closer to baseline
    if slope > 1.0:                                                                         # checks whether baseline-relative shift is increasing over time
        return "Increasing Shift"                                                          # returns increasing shift without diagnosing decline
    return "Stable"                                                                         # returns stable when the slope is nearly flat


def display_score(score):                                                                   # creates a reusable display object for dashboard-facing values
    return {                                                                                # opens the display dictionary
        "score": round(float(score), 6),                                                     # stores the rounded score
        "band": customer_shift_band(score),                                                 # stores the tighter neutral band label
        "color": customer_shift_color(score),                                               # stores the tighter display color
        "direction": "higher = greater baseline-relative pattern shift",                    # stores the score direction
    }                                                                                       # closes the display dictionary


def feature_reference_position(current_value, baseline_min, baseline_mean, baseline_max):    # describes where a current feature value sits relative to its personal baseline range
    if current_value < baseline_min:                                                        # checks whether the current value is below the lowest baseline observation
        return "below_personal_baseline_range"                                              # returns the below-range position label
    if current_value > baseline_max:                                                        # checks whether the current value is above the highest baseline observation
        return "above_personal_baseline_range"                                              # returns the above-range position label
    if current_value < baseline_mean:                                                       # checks whether the current value sits between the minimum and mean
        return "within_range_below_mean"                                                    # returns the lower-half in-range label
    if current_value > baseline_mean:                                                       # checks whether the current value sits between the mean and maximum
        return "within_range_above_mean"                                                    # returns the upper-half in-range label
    return "at_personal_baseline_mean"                                                      # returns the centered baseline label


def feature_range_shift_score(current_value, baseline_min, baseline_mean, baseline_max, baseline_std):  # converts one feature's min/mean/max comparison into a sensitive 0-100 shift score
    baseline_range = baseline_max - baseline_min                                            # calculates the full observed baseline range for this feature
    distance_from_mean = abs(current_value - baseline_mean)                                 # calculates how far the current value is from the baseline average
    edge_distance = max(abs(baseline_max - baseline_mean), abs(baseline_mean - baseline_min))  # calculates the farthest baseline edge from the mean
    value_floor = max(abs(baseline_mean) * 0.01, 1e-6)                                      # creates a small feature-specific floor so near-zero ranges do not explode
    effective_reference_width = max(edge_distance, baseline_std, baseline_range / 2.0, value_floor)  # chooses the usable feature-specific comparison width
    if baseline_min <= current_value <= baseline_max:                                       # checks whether the comparison value stays inside the observed baseline range
        score = min(9.999, (distance_from_mean / effective_reference_width) * 10.0)         # gives in-range values a small but visible 0-10 shift score based on distance from mean
        range_excess = 0.0                                                                  # stores zero excess because the value is still inside the baseline range
        threshold_status = "inside_personal_baseline_range"                                 # labels the value as inside the feature-specific baseline range
    else:                                                                                   # handles values outside the observed personal baseline range
        range_excess = baseline_min - current_value if current_value < baseline_min else current_value - baseline_max  # calculates how far outside the baseline edge the value moved
        score = 10.0 + (range_excess / effective_reference_width) * 40.0                    # starts outside-range values at 10 and scales them more tightly by feature width
        threshold_status = "outside_personal_baseline_range"                                # labels the value as outside the feature-specific baseline range
    return {                                                                                # returns the detailed feature-threshold calculation
        "score": clip_0_100(score),                                                         # stores the final 0-100 feature-specific shift score
        "baseline_range": baseline_range,                                                   # stores the observed baseline min-to-max range
        "distance_from_mean": distance_from_mean,                                           # stores absolute distance from the baseline average
        "range_excess": range_excess,                                                       # stores how far outside the baseline range the value moved
        "effective_reference_width": effective_reference_width,                             # stores the feature-specific width used for score scaling
        "threshold_status": threshold_status,                                               # stores whether the value was inside or outside the personal range
        "reference_position": feature_reference_position(current_value, baseline_min, baseline_mean, baseline_max),  # stores the current value's range position
    }                                                                                       # closes the feature-threshold calculation dictionary


def trend_slope(values):                                                                    # estimates a simple trend slope across prior comparison sessions
    if len(values) < 2:                                                                     # checks whether at least two points exist
        return 0.0                                                                          # returns zero when a trend cannot be estimated
    x = np.arange(len(values), dtype=np.float64)                                            # creates a numeric session index
    y = np.asarray(values, dtype=np.float64)                                                # converts values to a numeric array
    return float(np.polyfit(x, y, 1)[0])                                                     # returns the linear slope across sessions


def upsert_history(history_rows, current_row):                                              # adds or replaces the current comparison session in longitudinal history
    return [row for row in history_rows if row.get("session_id") != current_row["session_id"]] + [current_row]  # replaces same-session reruns instead of duplicating them


# ------------------------------------------------------------------
# FILE DISCOVERY
# ------------------------------------------------------------------

task_keywords = {                                                                           # defines the filename clues used to find the six required recordings in the comparison folder
    "step1_silence_pre": ["step 1", "silent capture"],                                      # identifies the pre-recording silence file
    "step2_sustained_phonation": ["step 2", "sustained phonation"],                         # identifies the sustained-vowel file
    "step3a_counting_natural": ["step 3a", "rhythmic counting"],                            # identifies the natural counting file
    "step3b_counting_slow": ["step 3b", "slower controlled"],                               # identifies the slower controlled counting file
    "step4_reading": ["step 4", "standardized reading"],                                    # identifies the standardized reading file
    "step5_silence_post": ["step 5", "post"],                                               # identifies the post-recording silence file
}                                                                                           # closes the filename-keyword dictionary


def discover_session_files(session_folder):                                                 # defines a helper that locates the six required recordings in one comparison folder
    session_files = {}                                                                      # starts an empty dictionary for the discovered task-to-file mapping
    audio_files = sorted(                                                                   # begins a sorted list of usable audio files in the folder
        path                                                                                # yields one matching file path at a time
        for path in session_folder.iterdir()                                                # loops through every item inside the session folder
        if path.is_file() and path.suffix.lower() in {".m4a", ".wav", ".mp3"}               # keeps only supported audio-file types
    )                                                                                       # closes the audio-file discovery list
    for task_key, keywords in task_keywords.items():                                        # loops through the six expected task types
        matches = [                                                                         # starts the list of files matching this task type
            path                                                                            # returns the candidate file path when it matches
            for path in audio_files                                                         # checks each discovered audio file
            if all(keyword in path.name.lower() for keyword in keywords)                    # keeps files containing all required identifying words
        ]                                                                                   # closes the list of matches for this task
        if len(matches) != 1:                                                               # checks that exactly one file matched the expected task
            raise FileNotFoundError(                                                        # stops the script if a required file is missing or duplicated
                f"Expected exactly one file for {task_key} in {session_folder}; found {len(matches)}."  # explains the missing or duplicate-file problem
            )                                                                               # closes the file-discovery error
        session_files[task_key] = matches[0]                                                # stores the one matching file for the current task
    return session_files                                                                    # returns the complete six-file mapping for the session


# ------------------------------------------------------------------
# SIGNAL HELPERS
# ------------------------------------------------------------------


def rms_track(y, frame_length=2048, hop_length=512):                                        # defines a helper that measures signal energy across short frames
    if len(y) < frame_length:                                                               # checks whether the whole recording is shorter than one full analysis frame
        return np.array([np.sqrt(np.mean(y ** 2))]) if len(y) else np.array([])             # returns one RMS value for a short signal, or an empty array for no signal
    values = []                                                                             # starts the list that will hold one RMS value per frame
    for i in range(0, len(y) - frame_length + 1, hop_length):                               # moves through the waveform one hop at a time
        frame = y[i:i + frame_length]                                                       # slices out the current short audio frame
        values.append(np.sqrt(np.mean(frame ** 2)))                                         # computes RMS energy for the current frame
    return np.array(values)                                                                 # converts the collected RMS values into a numpy array


def remove_dc_offset(y):                                                                    # defines a Stage 2 helper that recenters the waveform around zero
    if len(y) == 0:                                                                         # checks whether the waveform is empty
        return y                                                                            # returns the empty waveform unchanged
    return (y - np.mean(y)).astype(np.float32)                                              # subtracts the waveform mean so the signal is centered before filtering


def butter_bandpass_filter(y, sr, lowcut=75.0, highcut=8000.0, order=4):                    # defines the bandpass filter used in Stage 2
    if len(y) == 0:                                                                         # checks whether the waveform is empty
        return y                                                                            # returns the empty waveform unchanged
    nyquist = sr / 2.0                                                                      # calculates the Nyquist frequency, the highest representable frequency
    highcut = min(highcut, nyquist - 1.0)                                                   # keeps the high cutoff safely below Nyquist
    if lowcut <= 0 or highcut <= lowcut:                                                    # checks whether the requested filter range is invalid
        return y                                                                            # returns the original waveform if a valid filter cannot be built
    b, a = butter(order, [lowcut / nyquist, highcut / nyquist], btype="band")               # designs the digital bandpass filter coefficients
    return filtfilt(b, a, y).astype(np.float32)                                             # applies zero-phase filtering and returns float32 samples


def normalize_signal(y, target_peak=0.90):                                                  # defines the amplitude-normalization helper used in Stage 2
    if len(y) == 0:                                                                         # checks whether the waveform is empty
        return y                                                                            # returns the empty waveform unchanged
    peak = np.max(np.abs(y))                                                                # finds the largest absolute amplitude in the waveform
    return (y * (target_peak / peak)).astype(np.float32) if peak > 0 else y                 # rescales nonzero signals to the target peak level


def moving_average(x, window):                                                              # defines a simple smoothing helper
    if len(x) == 0:                                                                         # checks whether the input signal is empty
        return x                                                                            # returns the empty signal unchanged
    window = max(1, int(window))                                                            # forces the smoothing window to contain at least one sample
    kernel = np.ones(window) / window                                                       # creates equal averaging weights that sum to one
    return np.convolve(x, kernel, mode="same")                                              # returns the locally averaged signal


def estimate_f0_autocorr(y, sr, fmin=75, fmax=500):                                         # estimates average fundamental frequency from autocorrelation
    if len(y) == 0:                                                                         # checks whether the waveform is empty
        return 0.0                                                                          # returns zero when frequency cannot be estimated
    y = y - np.mean(y)                                                                      # centers the signal so periodic structure is easier to measure
    corr = np.correlate(y, y, mode="full")[len(y) - 1:]                                     # computes the positive-lag half of the autocorrelation curve
    min_lag = max(1, int(sr / fmax))                                                        # converts the highest expected pitch into the smallest allowed lag
    max_lag = min(len(corr) - 1, int(sr / fmin))                                            # converts the lowest expected pitch into the largest allowed lag
    if max_lag <= min_lag:                                                                  # checks whether the pitch-search range is valid
        return 0.0                                                                          # returns zero when the search range is unusable
    search = corr[min_lag:max_lag + 1]                                                      # extracts the plausible pitch-lag region
    if len(search) == 0 or np.max(search) <= 0:                                             # checks whether a usable periodic peak exists
        return 0.0                                                                          # returns zero when no usable peak is found
    best_lag = np.argmax(search) + min_lag                                                  # finds the strongest periodic lag inside the allowed region
    return float(sr / best_lag) if best_lag > 0 else 0.0                                    # converts the best lag back into frequency in Hertz


def reliability_metadata(value, valid_min=None, valid_max=None, method="calculated feature"):  # creates consistent feature reliability metadata
    if value is None or not np.isfinite(float(value)):                                      # checks whether the feature has a usable numeric value
        return None, "not_scored", method, "Feature could not be estimated reliably."       # marks unavailable values as not scored
    value = float(value)                                                                    # converts the value to a stable float
    if value == 0.0:                                                                        # checks whether the feature returned an exact zero
        return None, "not_scored", method, "Exact zero was treated as unavailable for this feature."  # avoids treating method-limited zeros as real measurements
    if valid_min is not None and value < valid_min:                                         # checks whether the value is below the expected interpretable range
        return value, "low_confidence", method, f"Value was below the expected range of {valid_min}."  # keeps but marks low-confidence values
    if valid_max is not None and value > valid_max:                                         # checks whether the value is above the expected interpretable range
        return value, "low_confidence", method, f"Value was above the expected range of {valid_max}."  # keeps but marks low-confidence values
    return value, "valid", method, "Feature calculated normally."                           # returns normal validity metadata


def spectral_features(y, sr):                                                               # defines a helper that summarizes frequency-domain structure
    if len(y) == 0:                                                                         # checks whether the waveform is empty
        return 0.0, 0.0, np.array([]), np.array([])                                         # returns zero summaries and empty arrays for no signal
    windowed = y * np.hanning(len(y))                                                       # applies a Hann window to reduce edge artifacts before the FFT
    spectrum = np.abs(np.fft.rfft(windowed))                                                # computes the one-sided magnitude spectrum
    freqs = np.fft.rfftfreq(len(windowed), d=1 / sr)                                        # creates the frequency value for each FFT bin
    power = spectrum ** 2                                                                   # converts magnitude into power so frequency summaries can be weighted by energy
    power_sum = np.sum(power)                                                               # totals all spectral power for normalization
    centroid = float(np.sum(freqs * power) / power_sum) if power_sum > 0 else 0.0           # calculates the power-weighted average frequency
    bandwidth = float(np.sqrt(np.sum(((freqs - centroid) ** 2) * power) / power_sum)) if power_sum > 0 else 0.0  # calculates power-weighted spread
    return centroid, bandwidth, freqs, spectrum                                             # returns the frequency summaries plus the raw spectral arrays


def estimate_hnr(y, sr, f0):                                                                # defines a simple harmonic-to-noise ratio estimator
    if len(y) == 0 or f0 <= 0:                                                              # checks whether the waveform or pitch estimate is unusable
        return 0.0                                                                          # returns zero when HNR cannot be estimated
    lag = int(sr / f0)                                                                      # converts estimated pitch into one expected waveform-period lag
    if lag <= 0 or lag >= len(y):                                                           # checks whether the lag is valid for this recording length
        return 0.0                                                                          # returns zero when the lag cannot be applied
    y0 = y[:-lag]                                                                           # stores the earlier waveform segment
    y1 = y[lag:]                                                                            # stores the same waveform shifted forward by one estimated period
    denominator = np.sqrt(np.sum(y0 ** 2) * np.sum(y1 ** 2))                                # computes normalization for the correlation-like ratio
    r = np.sum(y0 * y1) / denominator if denominator > 0 else 0.0                           # estimates periodic similarity between the two segments
    r = float(np.clip(r, 1e-6, 0.999999))                                                   # keeps the ratio away from zero and one so the logarithm stays finite
    return float(10 * np.log10(r / (1 - r)))                                                # converts the periodicity ratio into decibels


def estimate_formants_from_spectrum(freqs, spectrum):                                       # defines a simple spectral-peak proxy for the first three formant regions
    if len(freqs) == 0 or len(spectrum) == 0:                                               # checks whether the spectral arrays are usable
        return 0.0, 0.0, 0.0                                                                # returns zeros when no spectrum is available
    formants = []                                                                           # starts the list that will hold one peak per formant region
    for low, high in [(200, 1000), (800, 2500), (1800, 3500)]:                              # loops through broad F1, F2, and F3 search ranges
        mask = (freqs >= low) & (freqs <= high)                                             # keeps only bins inside the current frequency range
        if not np.any(mask):                                                                # checks whether that range contains any usable FFT bins
            formants.append(0.0)                                                            # stores zero if the current range is unavailable
            continue                                                                        # moves to the next formant range
        band_freqs = freqs[mask]                                                            # extracts frequencies inside the current range
        band_spec = spectrum[mask]                                                          # extracts spectral magnitudes inside the current range
        formants.append(float(band_freqs[np.argmax(band_spec)]))                            # stores the strongest frequency peak in the current range
    return tuple(formants)                                                                  # returns the three estimated formant-region peaks


def zero_crossing_rate(y):                                                                  # defines a helper that counts sign changes in the waveform
    if len(y) < 2:                                                                          # checks whether there are enough samples to compare neighbors
        return 0.0                                                                          # returns zero for recordings too short to contain crossings
    signs = np.sign(y)                                                                      # converts each sample into negative, zero, or positive sign
    return float(np.sum(signs[:-1] * signs[1:] < 0) / (len(y) - 1))                         # returns crossings per sample interval


def local_jitter_measure(y, sr):                                                            # defines a Praat-based local jitter measure with reliability metadata
    if len(y) == 0:                                                                         # checks whether the waveform is empty
        return None, False, "Praat-Parselmouth local jitter", "No waveform samples available."  # returns invalid metadata for empty signals
    try:                                                                                    # starts protected Praat calculation
        sound = parselmouth.Sound(np.asarray(y, dtype=np.float64), sampling_frequency=sr)   # creates a Praat Sound object from the waveform
        point_process = parselmouth.praat.call(sound, "To PointProcess (periodic, cc)", 75, 500)  # estimates pitch-cycle points using a 75-500 Hz voice range
        jitter = parselmouth.praat.call(point_process, "Get jitter (local)", 0, 0, 0.0001, 0.02, 1.3)  # calculates Praat local jitter from cycle timing
    except Exception as exc:                                                                # catches Praat failures without stopping the whole pipeline
        return None, False, "Praat-Parselmouth local jitter", f"Praat jitter calculation failed: {exc}"  # returns invalid metadata with the failure reason
    if not np.isfinite(jitter):                                                             # checks whether Praat returned a usable numeric value
        return None, False, "Praat-Parselmouth local jitter", "Praat returned a non-finite jitter value."  # marks non-finite jitter as invalid
    return float(jitter), True, "Praat-Parselmouth local jitter", "Calculated from sustained phonation when that task is selected."  # returns the valid jitter value and metadata


def local_shimmer_measure(y, sr, f0):                                                       # defines a simple cycle-amplitude variability estimate with reliability metadata
    method = "cycle-amplitude shimmer proxy"                                                # names the shimmer method
    if len(y) == 0 or f0 <= 0:                                                              # checks whether the waveform or pitch estimate is unusable
        return None, "not_scored", method, "Shimmer could not be estimated because pitch or waveform data was unavailable."  # returns unavailable metadata
    env = np.abs(y)                                                                         # converts the waveform into a simple absolute-amplitude envelope
    peaks = np.where((env[1:-1] > env[:-2]) & (env[1:-1] >= env[2:]))[0] + 1                # finds local amplitude peaks
    if len(peaks) < 3:                                                                      # checks whether enough peaks exist for multiple cycles
        return None, "not_scored", method, "Too few amplitude peaks were detected for shimmer estimation."  # returns unavailable metadata
    amplitudes = env[peaks]                                                                 # extracts the envelope amplitudes at the detected peaks
    if len(amplitudes) < 2 or np.mean(amplitudes) == 0:                                     # checks whether the amplitude series is usable
        return None, "not_scored", method, "Amplitude peaks were not usable for shimmer estimation."  # returns unavailable metadata
    shimmer = float(np.mean(np.abs(np.diff(amplitudes))) / np.mean(amplitudes))             # returns average cycle-amplitude change normalized by mean amplitude
    return reliability_metadata(shimmer, method=method)                                     # returns shimmer with reliability metadata


def envelope_and_tremor(y, sr):                                                             # defines a helper for amplitude-envelope variability and low-frequency modulation power
    if len(y) == 0:                                                                         # checks whether the waveform is empty
        return 0.0, 0.0, np.array([])                                                       # returns zero summaries and an empty envelope for no signal
    envelope = np.abs(hilbert(y))                                                           # extracts the instantaneous amplitude envelope from the analytic signal
    env_var = float(np.var(envelope))                                                       # measures overall envelope variability
    centered = envelope - np.mean(envelope)                                                 # removes the average envelope level before modulation analysis
    env_spec = np.abs(np.fft.rfft(centered * np.hanning(len(centered)))) ** 2               # computes the modulation-power spectrum
    env_freqs = np.fft.rfftfreq(len(centered), d=1 / sr)                                    # creates modulation-frequency bins for the envelope spectrum
    tremor_mask = (env_freqs >= 3) & (env_freqs <= 12)                                      # keeps only modulation frequencies between 3 and 12 Hz
    tremor_power = float(np.sum(env_spec[tremor_mask])) if np.any(tremor_mask) else 0.0     # sums low-frequency modulation power inside the selected range
    return env_var, tremor_power, envelope                                                  # returns envelope variance, modulation power, and the envelope itself


def approximate_entropy(x, m=2, r_ratio=0.2):                                               # defines an approximate-entropy estimate for signal irregularity
    x = np.asarray(x, dtype=np.float64)                                                     # converts the signal to float64 for stable calculations
    n = len(x)                                                                              # stores the number of samples in the signal
    if n <= m + 1:                                                                          # checks whether enough samples exist for the embedding lengths
        return 0.0                                                                          # returns zero when entropy cannot be estimated
    r = r_ratio * np.std(x)                                                                 # sets the similarity tolerance as a fraction of signal spread
    if r == 0:                                                                              # checks whether the signal has no variation
        return 0.0                                                                          # returns zero when irregularity cannot be measured

    def phi(mm):                                                                            # defines the inner similarity summary for one embedding length
        patterns = np.array([x[i:i + mm] for i in range(n - mm + 1)])                       # builds all overlapping patterns of length mm
        counts = []                                                                         # starts the list of similarity fractions for each pattern
        for pattern in patterns:                                                            # loops through each embedded pattern
            dist = np.max(np.abs(patterns - pattern), axis=1)                               # computes Chebyshev distance from this pattern to all others
            counts.append(np.mean(dist <= r))                                               # stores the fraction of patterns within tolerance
        counts = np.clip(np.array(counts), 1e-12, None)                                     # prevents log-of-zero problems
        return np.mean(np.log(counts))                                                      # returns the average log similarity for this embedding length

    return float(phi(m) - phi(m + 1))                                                       # returns approximate entropy as the change in similarity from m to m+1


def sample_entropy(x, m=2, r_ratio=0.2):                                                    # defines a sample-entropy estimate for signal irregularity
    x = np.asarray(x, dtype=np.float64)                                                     # converts the signal to float64 for stable calculations
    n = len(x)                                                                              # stores the number of samples in the signal
    if n <= m + 1:                                                                          # checks whether enough samples exist for the embedding lengths
        return 0.0                                                                          # returns zero when entropy cannot be estimated
    r = r_ratio * np.std(x)                                                                 # sets the similarity tolerance as a fraction of signal spread
    if r == 0:                                                                              # checks whether the signal has no variation
        return 0.0                                                                          # returns zero when irregularity cannot be measured

    def count(mm):                                                                          # defines the inner pattern-match counter for one embedding length
        patterns = np.array([x[i:i + mm] for i in range(n - mm + 1)])                       # builds all overlapping patterns of length mm
        matches = 0                                                                         # starts the count of matching pattern pairs
        for i in range(len(patterns)):                                                      # loops through each pattern by index
            if i + 1 >= len(patterns):                                                      # checks whether any later patterns remain for comparison
                continue                                                                    # skips the final pattern because it has no later partner
            dist = np.max(np.abs(patterns[i + 1:] - patterns[i]), axis=1)                   # computes distances to later patterns only
            matches += np.sum(dist <= r)                                                    # adds the number of later patterns within tolerance
        return matches                                                                      # returns the total number of matching pairs

    a = count(m + 1)                                                                        # counts matches for the longer pattern length
    b = count(m)                                                                            # counts matches for the shorter pattern length
    return float(-np.log(a / b)) if a > 0 and b > 0 else 0.0                                # returns sample entropy when both match counts are usable


def katz_fd(x):                                                                             # defines Katz fractal dimension as a compact complexity estimate
    x = np.asarray(x, dtype=np.float64)                                                     # converts the signal to float64 for stable calculations
    if len(x) < 2:                                                                          # checks whether at least two samples exist
        return 0.0                                                                          # returns zero when geometric complexity cannot be estimated
    diffs = np.diff(x)                                                                      # calculates point-to-point amplitude changes
    length = np.sum(np.sqrt(1 + diffs ** 2))                                                # estimates total curve length in the time-amplitude plane
    diameter = np.max(np.abs(x - x[0]))                                                     # estimates the farthest amplitude distance from the first point
    if length == 0 or diameter == 0:                                                        # checks whether the signal is perfectly flat
        return 0.0                                                                          # returns zero when the fractal estimate is undefined
    return float(np.log10(len(x)) / (np.log10(len(x)) + np.log10(diameter / length)))       # returns the Katz fractal-dimension estimate


def short_lyapunov_proxy(x):                                                                # defines a short-window divergence proxy
    x = np.asarray(x, dtype=np.float64)                                                     # converts the signal to float64 for stable calculations
    if len(x) < 3:                                                                          # checks whether enough samples exist for successive differences
        return 0.0                                                                          # returns zero when divergence cannot be estimated
    d1 = np.abs(np.diff(x))                                                                 # measures absolute point-to-point divergence
    d2 = d1[1:] / np.clip(d1[:-1], 1e-12, None)                                             # measures how divergence changes from one step to the next
    return float(np.mean(np.log(np.clip(d2, 1e-12, None))))                                 # returns average log divergence growth


def respiratory_metrics(envelope, sr):                                                      # defines simple breath-to-voice coordination proxies from the amplitude envelope
    method = "audio-envelope breath-voice timing proxy"                                     # names the approximate audio-only method used for respiratory timing
    if len(envelope) == 0:                                                                  # checks whether the envelope is empty
        return None, False, method, "No amplitude envelope was available.", 0.0, 0.0        # returns an unavailable timing result with safe companion values
    speech_env = moving_average(envelope, int(0.05 * sr))                                   # smooths the envelope at a speech-scale window
    resp_env = moving_average(envelope, int(0.40 * sr))                                     # smooths the envelope at a slower respiration-scale window
    speech_env = speech_env - np.mean(speech_env)                                           # removes the average speech-envelope level
    resp_env = resp_env - np.mean(resp_env)                                                 # removes the average respiration-envelope level
    if np.std(speech_env) <= 1e-10 or np.std(resp_env) <= 1e-10:                            # checks whether either envelope is too flat to support timing estimation
        resp_amp_var = float(np.var(resp_env))                                              # still records respiratory-scale amplitude variance when possible
        phonation_stability = float(np.std(speech_env)) if len(speech_env) else 0.0         # still records a simple phonation spread estimate
        return None, False, method, "Envelope variation was too low to estimate lag reliably.", resp_amp_var, phonation_stability  # marks lag as unavailable
    corr = np.correlate(speech_env, resp_env, mode="full")                                  # computes cross-correlation across possible timing offsets
    lags = np.arange(-len(speech_env) + 1, len(speech_env))                                 # builds the lag values matching the correlation output
    best_lag_samples = lags[np.argmax(corr)]                                                # finds the lag with strongest envelope alignment
    sync_lag_sec = float(best_lag_samples / sr)                                             # converts the best lag from samples into seconds
    resp_amp_var = float(np.var(resp_env))                                                  # measures variability in the slower respiratory-scale envelope
    exhale_mask = resp_env > 0                                                              # marks positive respiration-envelope regions as an exhalation proxy
    phonation_stability = float(np.std(speech_env[exhale_mask])) if np.any(exhale_mask) else 0.0  # measures speech-envelope spread during exhale-proxy regions
    if best_lag_samples == 0:                                                               # checks whether the strongest match is exactly zero lag
        return None, False, method, "No measurable lag detected by the current audio-only proxy.", resp_amp_var, phonation_stability  # prevents a method-limited zero from being scored as a true zero
    return sync_lag_sec, True, method, "Measurable audio-envelope lag detected.", resp_amp_var, phonation_stability  # returns timing plus reliability metadata


def prepare_feature_signal(y, sr, max_seconds=3.0):                                         # defines the shorter waveform slice used for main feature extraction
    return y[: int(sr * max_seconds)] if len(y) else y                                      # keeps the first few seconds when signal exists, otherwise returns the original empty waveform


def prepare_nonlinear_signal(y, sr, max_seconds=1.0, target_sr=500):                        # defines the reduced waveform used for slower nonlinear calculations
    if len(y) == 0:                                                                         # checks whether the waveform is empty
        return np.array([], dtype=np.float32)                                               # returns an empty float array for no signal
    y_small = y[: int(sr * max_seconds)]                                                    # keeps only the early segment needed for nonlinear summaries
    step = max(1, int(sr / target_sr))                                                      # calculates the downsampling step needed to approach the target sample rate
    return y_small[::step].astype(np.float32)                                               # downsamples and returns a float32 waveform


# ==========================================================
# STAGES 1-3: COMPARISON SESSION FEATURE EXTRACTION
# ==========================================================


def convert_to_wav(input_file, output_file):                                                # defines a helper that converts one raw recording into a consistent WAV file
    subprocess.run(                                                                         # runs ffmpeg from Python so each source file is standardized before analysis
        ["ffmpeg", "-y", "-i", str(input_file), "-ac", "1", "-ar", "44100", "-sample_fmt", "s16", str(output_file)],  # converts to mono, 44.1 kHz, 16-bit PCM WAV
        check=True,                                                                         # raises an error if ffmpeg fails so bad files do not silently continue
        stdout=subprocess.DEVNULL,                                                          # hides routine ffmpeg output so the terminal remains readable
        stderr=subprocess.DEVNULL,                                                          # hides routine ffmpeg progress text so the terminal remains readable
    )                                                                                       # closes the ffmpeg command


def run_stage1_capture_and_qc(session_folder, comparison_run_timestamp, session_processed_timestamp):  # defines Stage 1 for the comparison session
    session_id = session_folder.name                                                        # uses the folder name as the stable session identifier
    session_wav_dir = wav_dir / session_id                                                  # creates the WAV destination folder for this session
    session_wav_dir.mkdir(exist_ok=True)                                                    # creates the session WAV folder if it does not already exist
    files = discover_session_files(session_folder)                                          # finds the six required audio recordings inside the session folder
    qc_rows = []                                                                            # starts an empty list that will store one quality-review row per recording
    raw_signals = {}                                                                        # starts an empty dictionary that will hold loaded raw audio for Stage 2
    for task_key, input_file in files.items():                                              # loops through the six required recordings in the session
        wav_file = session_wav_dir / f"{task_key}.wav"                                      # builds a clean standardized WAV filename for this task
        convert_to_wav(input_file, wav_file)                                                # converts the source recording into the standardized WAV file
        y, sr = sf.read(wav_file)                                                           # loads the waveform samples and sample rate from the WAV file
        if y.ndim > 1:                                                                      # checks whether the file has more than one audio channel
            y = np.mean(y, axis=1)                                                          # averages multiple channels into one mono waveform
        y = y.astype(np.float32)                                                            # converts samples to float32 so later signal math is consistent
        duration = len(y) / sr                                                              # calculates recording duration from sample count divided by sample rate
        rms = rms_track(y)                                                                  # computes frame-by-frame RMS energy for simple quality review
        noise_floor = float(np.percentile(rms, 10)) if len(rms) else 0.0                    # estimates low-end background energy using the 10th percentile RMS
        peak = float(np.max(np.abs(y))) if len(y) else 0.0                                  # finds the largest absolute sample amplitude in the recording
        qc_rows.append(                                                                     # adds one quality-review summary row for this recording
            {                                                                               # opens the quality-review row dictionary
                "session_id": session_id,                                                   # stores the current session folder name
                "comparison_run_timestamp": comparison_run_timestamp,                       # stores when the overall comparison run started
                "session_processed_timestamp": session_processed_timestamp,                  # stores when this specific session began processing
                "recording_key": task_key,                                                  # stores the internal recording task key
                "sample_rate_hz": sr,                                                       # stores the sample rate in Hertz
                "duration_sec": round(duration, 2),                                         # stores duration rounded for readability
                "noise_floor": round(noise_floor, 8),                                       # stores the estimated background-energy floor
                "ambient_noise_acceptable": noise_floor < 0.01,                             # marks whether the noise floor meets the quiet-capture rule
                "peak_amplitude": round(peak, 8),                                           # stores the highest observed waveform amplitude
                "clipping_detected": peak >= 0.95,                                          # flags possible clipping near the digital amplitude ceiling
            }                                                                               # closes the quality-review row dictionary
        )                                                                                   # closes the qc_rows append call
        raw_signals[task_key] = {                                                           # stores raw waveform data and timestamps for Stage 2
            "y": y,                                                                         # stores the raw waveform samples
            "sr": sr,                                                                       # stores the sample rate
            "duration": duration,                                                           # stores the recording duration
            "comparison_run_timestamp": comparison_run_timestamp,                           # carries forward when the overall comparison run started
            "session_processed_timestamp": session_processed_timestamp,                      # carries forward when this specific session began processing
        }                                                                                   # closes the raw-signal payload
    return qc_rows, raw_signals                                                             # returns Stage 1 quality rows and raw waveforms


def run_stage2_conditioning(raw_signals):                                                   # defines Stage 2 for one session's raw signals
    conditioned_signals = {}                                                                # starts an empty dictionary that will hold cleaned waveforms
    for task_key, payload in raw_signals.items():                                           # loops through each raw waveform from Stage 1
        centered = remove_dc_offset(payload["y"])                                           # removes DC offset by centering the waveform around zero
        filtered = butter_bandpass_filter(centered, payload["sr"])                          # removes very low and very high frequency content outside the working voice band
        normalized = normalize_signal(filtered)                                             # scales the filtered waveform to a consistent peak level
        conditioned_signals[task_key] = {                                                   # stores the Stage 2 output for this task
            "y": normalized,                                                                # stores the conditioned waveform samples
            "sr": payload["sr"],                                                            # carries forward the sample rate
            "duration": payload["duration"],                                                # carries forward the original recording duration
            "comparison_run_timestamp": payload["comparison_run_timestamp"],                # carries forward when the overall comparison run started
            "session_processed_timestamp": payload["session_processed_timestamp"],           # carries forward when this specific session began processing
        }                                                                                   # closes the conditioned-signal dictionary for this task
    return conditioned_signals                                                              # returns the fully conditioned session signals


def run_stage3_feature_extraction(session_id, conditioned_signals):                         # defines Stage 3 for one session's cleaned waveforms
    feature_rows = []                                                                       # starts an empty list that will store one feature row per recording
    for task_key, payload in conditioned_signals.items():                                   # loops through each conditioned recording
        y = payload["y"]                                                                    # pulls out the conditioned waveform samples
        sr = payload["sr"]                                                                  # pulls out the sample rate
        y_feat = prepare_feature_signal(y, sr)                                              # shortens the signal used for the main feature calculations
        centroid, bandwidth, freqs, spectrum = spectral_features(y_feat, sr)                # calculates spectral center, spread, and spectrum arrays
        f0 = estimate_f0_autocorr(y_feat, sr)                                               # estimates average fundamental frequency from autocorrelation
        hnr = estimate_hnr(y_feat, sr, f0)                                                  # estimates harmonic-to-noise ratio from waveform periodicity
        f1, f2, f3 = estimate_formants_from_spectrum(freqs, spectrum)                       # estimates the first three broad formant-related spectral peaks
        env_var, tremor_power, envelope = envelope_and_tremor(y_feat, sr)                   # measures envelope variance and tremor-band modulation power
        y_nl = prepare_nonlinear_signal(y, sr)                                              # downsamples a shorter copy for nonlinear feature calculations
        resp_sync_lag, resp_sync_valid, resp_sync_method, resp_sync_note, resp_amp_var, phonation_stability = respiratory_metrics(envelope, sr)  # estimates breath-to-voice timing with reliability metadata
        jitter_value, jitter_valid, jitter_method, jitter_note = local_jitter_measure(y_feat, sr)  # calculates Praat-based local jitter with reliability metadata
        f0_value, f0_status, f0_method, f0_note = reliability_metadata(f0, 75.0, 500.0, "autocorrelation pitch estimate")  # assigns pitch reliability metadata
        hnr_status = "not_scored" if f0_status == "not_scored" or not np.isfinite(float(hnr)) else "valid"  # marks HNR unavailable when pitch support is unavailable
        hnr_value = None if hnr_status == "not_scored" else float(hnr)                      # stores HNR only when its periodicity support is usable
        hnr_note = "HNR requires usable pitch/periodicity support." if hnr_status == "not_scored" else "Feature calculated normally."  # stores HNR reliability note
        shimmer_value, shimmer_status, shimmer_method, shimmer_note = local_shimmer_measure(y_feat, sr, f0_value if f0_value is not None else 0.0)  # calculates shimmer with reliability metadata
        phonation_value, phonation_status, phonation_method, phonation_note = reliability_metadata(phonation_stability, method="speech-envelope exhalation stability proxy")  # assigns phonation-stability reliability metadata
        feature_rows.append(                                                                # adds one feature dictionary for this recording
            {                                                                               # opens the feature-row dictionary
                "session_id": session_id,                                                   # stores the current session ID
                "comparison_run_timestamp": payload["comparison_run_timestamp"],            # stores when the overall comparison run started
                "session_processed_timestamp": payload["session_processed_timestamp"],       # stores when this specific session began processing
                "recording_key": task_key,                                                  # stores the internal recording-task key
                "duration_sec": round(payload["duration"], 2),                              # stores recording duration rounded for readability
                "f0_mean_hz": round(float(f0_value), 6) if f0_value is not None else None,  # stores estimated average fundamental frequency when usable
                "f0_mean_hz_scoring_status": f0_status,                                    # stores pitch reliability status
                "f0_mean_hz_method": f0_method,                                             # stores pitch estimation method
                "f0_mean_hz_note": f0_note,                                                 # stores pitch reliability note
                "hnr_db": round(float(hnr_value), 6) if hnr_value is not None else None,    # stores harmonic-to-noise ratio when usable
                "hnr_db_scoring_status": hnr_status,                                       # stores HNR reliability status
                "hnr_db_method": "periodicity-based HNR proxy",                            # stores HNR method
                "hnr_db_note": hnr_note,                                                    # stores HNR reliability note
                "spectral_centroid_hz": round(float(centroid), 6),                          # stores weighted-average spectral center in Hertz
                "spectral_bandwidth_hz": round(float(bandwidth), 6),                        # stores spectral spread around the centroid in Hertz
                "formant_f1_hz": round(float(f1), 6),                                       # stores estimated first formant-related spectral peak
                "formant_f2_hz": round(float(f2), 6),                                       # stores estimated second formant-related spectral peak
                "formant_f3_hz": round(float(f3), 6),                                       # stores estimated third formant-related spectral peak
                "jitter_local": round(float(jitter_value), 6) if jitter_valid else None,    # stores Praat local jitter only when reliably estimated
                "jitter_local_valid": jitter_valid,                                         # stores whether local jitter was reliably estimated
                "jitter_local_method": jitter_method,                                       # stores the jitter calculation method
                "jitter_local_note": jitter_note,                                           # stores jitter reliability notes
                "shimmer_local": round(float(shimmer_value), 6) if shimmer_value is not None else None,  # stores shimmer only when cycle amplitude can be estimated
                "shimmer_local_scoring_status": shimmer_status,                             # stores shimmer reliability status
                "shimmer_local_method": shimmer_method,                                     # stores shimmer method
                "shimmer_local_note": shimmer_note,                                         # stores shimmer reliability note
                "zero_crossing_rate": round(float(zero_crossing_rate(y_feat)), 6),          # stores how often the waveform crosses zero
                "envelope_variance": round(float(env_var), 6),                              # stores amplitude-envelope variability
                "tremor_band_power_3_12hz": round(float(tremor_power), 6),                  # stores low-frequency modulation power in the 3-12 Hz range
                "approximate_entropy": round(float(approximate_entropy(y_nl)), 6),          # stores one signal-irregularity estimate
                "sample_entropy": round(float(sample_entropy(y_nl)), 6),                    # stores a second signal-irregularity estimate
                "fractal_dimension": round(float(katz_fd(y_nl)), 6),                        # stores geometric-complexity estimate
                "lyapunov_proxy": round(float(short_lyapunov_proxy(y_nl)), 6),              # stores short-window divergence proxy
                "resp_sync_lag_sec": round(float(resp_sync_lag), 6) if resp_sync_valid else None,  # stores breath-to-voice timing only when reliably differentiated
                "resp_sync_lag_valid": resp_sync_valid,                                     # stores whether the respiratory timing proxy was usable
                "resp_sync_lag_method": resp_sync_method,                                   # stores the respiratory timing method
                "resp_sync_lag_note": resp_sync_note,                                       # stores respiratory timing reliability notes
                "resp_amp_variance": round(float(resp_amp_var), 6),                         # stores respiratory-scale amplitude variance
                "phonation_stability_exhalation": round(float(phonation_value), 6) if phonation_value is not None else None,  # stores phonation stability when usable
                "phonation_stability_exhalation_scoring_status": phonation_status,          # stores phonation-stability reliability status
                "phonation_stability_exhalation_method": phonation_method,                  # stores phonation-stability method
                "phonation_stability_exhalation_note": phonation_note,                      # stores phonation-stability reliability note
            }                                                                               # closes the feature-row dictionary
        )                                                                                   # closes the feature_rows append call
    return feature_rows                                                                     # returns all Stage 3 feature rows for this session


# ==========================================================
# STAGE 4: BASELINE-RELATIVE PATTERN COMPARISON
# ==========================================================


def load_baseline_reference(path):                                                          # defines a helper that loads the saved personal baseline JSON
    if not path.exists():                                                                   # checks whether the baseline file exists
        raise FileNotFoundError(f"Missing baseline reference file: {path}")                  # stops if no saved baseline is available
    return json.loads(path.read_text(encoding="utf-8"))                                     # returns the parsed baseline JSON object


def summarize_current_features(feature_rows):                                               # summarizes the current comparison session across voiced tasks
    summary = {}                                                                            # starts the current-session feature summary
    for feature_name in baseline_feature_names:                                             # loops through every feature used in baseline comparison
        selected_task_source = task_source_for_feature(feature_name)                         # gets the task source used for this feature's current-session summary
        values = [row[feature_name] for row in feature_rows if row["recording_key"] in selected_task_source and feature_value_is_valid(row, feature_name)]  # keeps only valid values from the feature's task source
        summary[feature_name] = float(np.mean(values)) if values else None                  # stores the current-session mean, or None when the feature is unavailable
    return summary                                                                          # returns the current-session feature summary


def compute_feature_deviations(current_summary, baseline_reference):                        # computes feature-level shifts from the personal baseline
    baseline_features = baseline_reference["baseline_acoustic_reference"]                   # pulls the saved baseline acoustic summary block
    rows = []                                                                               # starts the feature-deviation row list
    for feature_name in baseline_feature_names:                                             # loops through every feature used in comparison
        selected_task_source = task_source_for_feature(feature_name)                         # gets the task source used for this feature's comparison calculation
        baseline_stats = baseline_features.get(feature_name, {})                            # gets the saved baseline statistics for this feature
        baseline_mean_raw = baseline_stats.get("mean")                                      # reads the raw baseline mean
        baseline_std_raw = baseline_stats.get("std")                                        # reads the raw baseline standard deviation
        baseline_min_raw = baseline_stats.get("min")                                        # reads the raw baseline minimum
        baseline_max_raw = baseline_stats.get("max")                                        # reads the raw baseline maximum
        current_value_raw = current_summary.get(feature_name)                               # reads the raw current-session feature value
        feature_valid_for_scoring = all(value is not None for value in [baseline_mean_raw, baseline_std_raw, baseline_min_raw, baseline_max_raw, current_value_raw])  # checks whether baseline and current values are usable
        if not feature_valid_for_scoring:                                                    # handles features that could not be measured reliably
            rows.append(                                                                    # adds an invalid/unavailable feature row
                {                                                                           # opens the invalid feature row
                    "feature_name": feature_name,                                           # stores the feature name
                    "task_source": sorted(selected_task_source),                             # stores which recording tasks were attempted
                    "threshold_method": "not_scored_unreliable_or_unavailable",             # states why the feature is not scored
                    "baseline_min": baseline_min_raw,                                       # stores baseline minimum when available
                    "baseline_mean": baseline_mean_raw,                                     # stores baseline mean when available
                    "baseline_max": baseline_max_raw,                                       # stores baseline maximum when available
                    "baseline_range": None,                                                 # stores no baseline range for unavailable scoring
                    "baseline_std": baseline_std_raw,                                       # stores baseline standard deviation when available
                    "current_value": current_value_raw,                                     # stores current value when available
                    "raw_delta": None,                                                      # stores no delta for unavailable scoring
                    "normalized_delta": None,                                               # stores no normalized delta for unavailable scoring
                    "z_score": None,                                                        # stores no Z-score for unavailable scoring
                    "abs_z_score": None,                                                    # stores no absolute Z-score for unavailable scoring
                    "sd_deviation_score_0_100": None,                                       # stores no SD score for unavailable scoring
                    "sd_severity_band": "Not Reliably Estimated",                           # stores reliability label
                    "sd_color": "gray",                                                     # stores neutral unavailable color
                    "distance_from_baseline_mean": None,                                    # stores no distance for unavailable scoring
                    "range_excess": None,                                                   # stores no range excess for unavailable scoring
                    "effective_reference_width": None,                                      # stores no reference width for unavailable scoring
                    "threshold_status": "not_scored_unreliable_or_unavailable",             # stores unavailable threshold status
                    "reference_position": "not_scored",                                    # stores unavailable position
                    "range_deviation_score_0_100": None,                                    # stores no range score for unavailable scoring
                    "range_band": "Not Reliably Estimated",                                 # stores unavailable range band
                    "range_color": "gray",                                                  # stores unavailable range color
                    "final_severity_source": "not_scored",                                  # stores unavailable severity source
                    "deviation_score_0_100": None,                                          # stores no final score for unavailable scoring
                    "band": "Not Reliably Estimated",                                       # stores final unavailable band
                    "color": "gray",                                                        # stores final unavailable color
                }                                                                           # closes the invalid feature row
            )                                                                               # closes invalid feature row append
            continue                                                                        # skips numeric scoring for this feature
        baseline_mean = float(baseline_mean_raw)                                            # converts the baseline mean to float
        baseline_std = float(baseline_std_raw)                                              # converts the baseline standard deviation to float
        baseline_min = float(baseline_min_raw)                                              # converts the baseline minimum to float
        baseline_max = float(baseline_max_raw)                                              # converts the baseline maximum to float
        current_value = float(current_value_raw)                                            # converts the current-session value to float
        raw_delta = current_value - baseline_mean                                           # calculates signed change from baseline
        normalized_delta = raw_delta / baseline_std if baseline_std > 0 else 0.0            # calculates the signed Z-score when personal baseline standard deviation is available
        abs_z_score = abs(normalized_delta)                                                 # calculates the absolute personal standard-deviation distance from baseline mean
        sd_score = sd_shift_score(abs_z_score)                                              # converts the absolute Z-score into a backend 0-100 shift score
        sd_band = sd_severity_band(abs_z_score)                                             # assigns the SD-based severity label
        sd_color = sd_severity_color(abs_z_score)                                           # assigns the SD-based severity color
        range_shift = feature_range_shift_score(current_value, baseline_min, baseline_mean, baseline_max, baseline_std)  # compares the current value to this feature's own min/mean/max thresholds
        range_score = range_shift["score"]                                                  # stores the min/max range-excess shift score
        range_band = customer_shift_band(range_score)                                       # assigns the min/max range-based severity label
        range_color = customer_shift_color(range_score)                                     # assigns the min/max range-based severity color
        deviation_score = max(sd_score, range_score)                                        # uses the higher severity from SD distance or min/max range behavior
        final_band, final_color, final_severity_source = final_feature_severity(sd_band, sd_color, sd_score, range_band, range_color, range_score)  # chooses the worse severity label/color/source
        rows.append(                                                                        # adds one feature-deviation row
            {                                                                               # opens the feature-deviation dictionary
                "feature_name": feature_name,                                               # stores the feature name
                "task_source": sorted(selected_task_source),                                 # stores which recording tasks contributed to this feature's comparison
                "threshold_method": "personal_z_score_primary_with_min_max_modifier",       # states that SD severity drives interpretation while min/max range modifies context
                "baseline_min": round(baseline_min, 6),                                     # stores the lowest baseline observation for this feature
                "baseline_mean": round(baseline_mean, 6),                                   # stores the baseline mean
                "baseline_max": round(baseline_max, 6),                                     # stores the highest baseline observation for this feature
                "baseline_range": round(float(range_shift["baseline_range"]), 6),           # stores the observed feature-specific baseline range
                "baseline_std": round(baseline_std, 6),                                     # stores the baseline standard deviation
                "current_value": round(current_value, 6),                                   # stores the current comparison value
                "raw_delta": round(raw_delta, 6),                                           # stores the signed raw change
                "normalized_delta": round(float(normalized_delta), 6),                      # stores the standardized signed change
                "z_score": round(float(normalized_delta), 6),                               # stores the signed personal standard-deviation distance
                "abs_z_score": round(float(abs_z_score), 6),                                # stores the absolute personal standard-deviation distance
                "sd_deviation_score_0_100": round(float(sd_score), 6),                      # stores the SD-based backend shift score
                "sd_severity_band": sd_band,                                                # stores the detailed SD severity band
                "sd_color": sd_color,                                                       # stores the SD-based visual severity color
                "distance_from_baseline_mean": round(float(range_shift["distance_from_mean"]), 6),  # stores absolute distance from the feature's baseline average
                "range_excess": round(float(range_shift["range_excess"]), 6),               # stores how far the value moved outside the baseline range
                "effective_reference_width": round(float(range_shift["effective_reference_width"]), 6),  # stores the feature-specific width used for scaling
                "threshold_status": range_shift["threshold_status"],                        # stores whether the value stayed inside or moved outside the personal feature range
                "reference_position": range_shift["reference_position"],                    # stores the current value's position relative to min, mean, and max
                "range_deviation_score_0_100": round(float(range_score), 6),                # stores the min/max range-based shift score
                "range_band": range_band,                                                   # stores the min/max range-based display band
                "range_color": range_color,                                                 # stores the min/max range-based display color
                "final_severity_source": final_severity_source,                             # stores whether SD or range behavior determined final severity
                "deviation_score_0_100": round(deviation_score, 6),                         # stores the absolute baseline-relative shift score
                "band": final_band,                                                         # stores the final severity label from the worse SD/range source
                "color": final_color,                                                       # stores the final severity color from the worse SD/range source
            }                                                                               # closes the feature-deviation dictionary
        )                                                                                   # closes the row append call
    return rows                                                                             # returns all feature-level baseline comparisons


def deviation_lookup(feature_rows):                                                         # converts feature-deviation rows into a feature-to-score lookup
    return {row["feature_name"]: row["deviation_score_0_100"] for row in feature_rows}      # returns the deviation-score lookup dictionary


def compute_stage4_metrics(feature_deviation_rows):                                         # computes Stage 4 neutral stability and coordination pattern metrics
    d = deviation_lookup(feature_deviation_rows)                                            # creates a compact lookup of feature deviation scores
    variability_index = weighted_mean(                                                      # computes the variability-pattern shift score
        [d["jitter_local"], d["shimmer_local"], d["envelope_variance"], d["approximate_entropy"], d["sample_entropy"]],  # groups variability features
        [0.20, 0.20, 0.20, 0.20, 0.20],                                                     # gives each variability feature equal influence
    )                                                                                       # closes the variability-pattern calculation
    harmonic_coherence_shift = weighted_mean(                                               # computes the harmonic/spectral structure shift score
        [d["hnr_db"], d["spectral_centroid_hz"], d["spectral_bandwidth_hz"], d["f0_mean_hz"]],  # groups harmonic and spectral features
        [0.35, 0.20, 0.20, 0.25],                                                           # emphasizes harmonic-to-noise and pitch structure
    )                                                                                       # closes the harmonic-structure calculation
    transition_continuity_shift = weighted_mean(                                            # computes the transition-continuity shift score
        [d["resp_sync_lag_sec"], d["resp_amp_variance"], d["phonation_stability_exhalation"]],  # groups breath-to-voice coordination features
        [0.40, 0.25, 0.35],                                                                 # emphasizes timing and phonation continuity
    )                                                                                       # closes the transition-continuity calculation
    micro_modulation_shift = weighted_mean(                                                 # computes fine-grain modulation shift
        [d["tremor_band_power_3_12hz"], d["lyapunov_proxy"]],                               # groups tremor-band and short-divergence features
        [0.65, 0.35],                                                                       # emphasizes the direct tremor-band measure
    )                                                                                       # closes the micro-modulation calculation
    signal_clarity_shift = weighted_mean(                                                   # computes signal-clarity shift
        [d["hnr_db"], d["zero_crossing_rate"], d["spectral_bandwidth_hz"]],                 # groups clarity-related acoustic features
        [0.50, 0.25, 0.25],                                                                 # emphasizes harmonic-to-noise ratio
    )                                                                                       # closes the signal-clarity calculation
    distribution_balance_shift = weighted_mean(                                             # computes spectral/formant distribution shift
        [d["spectral_centroid_hz"], d["spectral_bandwidth_hz"], d["f0_mean_hz"]],           # groups distribution-balance features available in the validated baseline
        [0.35, 0.35, 0.30],                                                                 # balances spectral center, spread, and pitch location
    )                                                                                       # closes the distribution-balance calculation
    sustained_phonation_shift = d["phonation_stability_exhalation"]                         # uses exhalation-related phonation stability as the sustained-voice shift proxy
    rdi_score = weighted_mean(                                                              # computes the Regulation Deviation Index as a neutral baseline-relative composite
        [                                                                                   # opens the ordered Stage 4 metric list for the RDI composite
            variability_index,                                                              # includes variability-pattern shift
            harmonic_coherence_shift,                                                       # includes harmonic-structure shift
            transition_continuity_shift,                                                     # includes transition-continuity shift
            micro_modulation_shift,                                                         # includes micro-modulation shift
            signal_clarity_shift,                                                           # includes signal-clarity shift
            distribution_balance_shift,                                                     # includes distribution-balance shift
            sustained_phonation_shift,                                                      # includes sustained-phonation shift
        ],                                                                                  # closes the ordered Stage 4 metric list
        [                                                                                   # opens the matching BSI-framework weight list
            bsi_framework_weights["variability_consistency"],                               # applies the variability-consistency weight
            bsi_framework_weights["harmonic_structure_consistency"],                        # applies the harmonic-structure consistency weight
            bsi_framework_weights["transition_continuity"],                                 # applies the transition-continuity weight
            bsi_framework_weights["micro_modulation_consistency"],                          # applies the micro-modulation consistency weight
            bsi_framework_weights["signal_clarity"],                                        # applies the signal-clarity weight
            bsi_framework_weights["distribution_balance"],                                  # applies the distribution-balance weight
            bsi_framework_weights["sustained_phonation_consistency"],                       # applies the sustained-phonation consistency weight
        ],                                                                                  # closes the matching BSI-framework weight list
    )                                                                                       # closes the RDI calculation
    return {                                                                                # returns the Stage 4 metrics
        "variability_pattern_shift": round(variability_index, 6),                           # stores variability-pattern shift
        "harmonic_structure_shift": round(harmonic_coherence_shift, 6),                     # stores harmonic-structure shift
        "transition_continuity_shift": round(transition_continuity_shift, 6),               # stores transition-continuity shift
        "micro_modulation_shift": round(micro_modulation_shift, 6),                         # stores micro-modulation shift
        "signal_clarity_shift": round(signal_clarity_shift, 6),                             # stores signal-clarity shift
        "distribution_balance_shift": round(distribution_balance_shift, 6),                 # stores distribution-balance shift
        "sustained_phonation_shift": round_optional(sustained_phonation_shift),             # stores sustained-phonation shift when available
        "rdi_score_0_100": round(rdi_score, 6),                                             # stores Regulation Deviation Index on a 0-100 scale
        "rdi_band": customer_shift_band(rdi_score),                                         # stores the tighter neutral RDI band label
        "rdi_color": customer_shift_color(rdi_score),                                       # stores the tighter RDI display color
        "score_direction": "higher = greater baseline-relative pattern shift",              # stores the score direction
    }                                                                                       # closes the Stage 4 metric dictionary


# ==========================================================
# STAGE 5: BSI-FRAMEWORK VOICE PATTERN SCORES
# ==========================================================


def compute_stage5_scores(stage4_metrics, feature_deviation_rows):                          # computes neutral BSI-framework composite voice-pattern scores
    d = deviation_lookup(feature_deviation_rows)                                            # creates a feature-deviation lookup
    activation_pattern_variability = weighted_mean(                                         # computes an activation-pattern variability proxy without internal-state labeling
        [stage4_metrics["variability_pattern_shift"], stage4_metrics["micro_modulation_shift"], stage4_metrics["signal_clarity_shift"]],  # groups dynamic shift metrics
        [0.40, 0.35, 0.25],                                                                 # emphasizes variability and micro-modulation
    )                                                                                       # closes activation-pattern variability calculation
    regulation_consistency_shift = weighted_mean(                                           # computes a regulation-consistency shift score
        [stage4_metrics["harmonic_structure_shift"], stage4_metrics["transition_continuity_shift"], stage4_metrics["sustained_phonation_shift"]],  # groups consistency metrics
        [0.35, 0.35, 0.30],                                                                 # balances harmonic, transition, and sustained-voice consistency
    )                                                                                       # closes regulation-consistency calculation
    flexibility_range_shift = weighted_mean(                                                # computes a flexibility-range shift score
        [d["approximate_entropy"], d["sample_entropy"], d["fractal_dimension"], d["lyapunov_proxy"]],  # groups complexity and irregularity features
        [0.25, 0.25, 0.25, 0.25],                                                           # weights each flexibility feature equally
    )                                                                                       # closes flexibility-range calculation
    return_to_baseline_window_shift = weighted_mean(                                        # computes return-to-baseline window shift
        [stage4_metrics["rdi_score_0_100"], stage4_metrics["transition_continuity_shift"], stage4_metrics["sustained_phonation_shift"]],  # groups overall and continuity measures
        [0.40, 0.30, 0.30],                                                                 # emphasizes overall baseline-relative deviation
    )                                                                                       # closes return-window calculation
    adaptive_transition_pattern_shift = weighted_mean(                                      # computes adaptive transition pattern shift
        [stage4_metrics["transition_continuity_shift"], d["resp_sync_lag_sec"], d["resp_amp_variance"], d["phonation_stability_exhalation"]],  # groups transition features
        [0.35, 0.25, 0.20, 0.20],                                                           # emphasizes transition continuity and breath-to-voice timing
    )                                                                                       # closes adaptive-transition calculation
    overall_voice_pattern_shift = weighted_mean(                                            # computes the overall BSI-framework voice-pattern shift score
        [                                                                                   # opens the ordered Stage 5 composite list
            activation_pattern_variability,                                                  # includes activation-pattern variability
            regulation_consistency_shift,                                                    # includes regulation-consistency shift
            flexibility_range_shift,                                                        # includes flexibility-range shift
            return_to_baseline_window_shift,                                                # includes return-to-baseline window shift
            adaptive_transition_pattern_shift,                                              # includes adaptive-transition pattern shift
        ],                                                                                  # closes the ordered Stage 5 composite list
        [0.20, 0.25, 0.15, 0.20, 0.20],                                                     # balances the validated Stage 5 composite areas
    )                                                                                       # closes overall voice-pattern calculation
    return {                                                                                # returns Stage 5 scores
        "activation_pattern_variability": round(activation_pattern_variability, 6),          # stores activation-pattern variability
        "regulation_consistency_shift": round(regulation_consistency_shift, 6),              # stores regulation-consistency shift
        "flexibility_range_shift": round(flexibility_range_shift, 6),                        # stores flexibility-range shift
        "return_to_baseline_window_shift": round(return_to_baseline_window_shift, 6),        # stores return-to-baseline window shift
        "adaptive_transition_pattern_shift": round(adaptive_transition_pattern_shift, 6),    # stores adaptive-transition pattern shift
        "overall_voice_pattern_shift": round(overall_voice_pattern_shift, 6),                # stores overall voice-pattern shift
        "overall_voice_pattern_band": customer_shift_band(overall_voice_pattern_shift),      # stores tighter neutral overall band
        "overall_voice_pattern_color": customer_shift_color(overall_voice_pattern_shift),    # stores tighter overall display color
        "score_direction": "higher = greater baseline-relative pattern shift",              # stores score direction
    }                                                                                       # closes Stage 5 score dictionary


# ==========================================================
# STAGE 6: LONGITUDINAL PATTERN TRACKING
# ==========================================================


def compute_stage6_trends(session_id, stage4_metrics, stage5_scores):                       # computes longitudinal trend values from comparison history
    history_path = json_dir / "comparison_longitudinal_history.json"                        # defines where comparison history is stored
    existing_history = json.loads(history_path.read_text(encoding="utf-8")) if history_path.exists() else []  # loads existing history when available
    existing_history = [row for row in existing_history if row.get("comparison_model_version") == comparison_model_version]  # keeps only rows created with the current comparison logic
    current_row = {                                                                         # creates the current history row
        "session_id": session_id,                                                           # stores the comparison session ID
        "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),                          # stores when this history row was built
        "comparison_model_version": comparison_model_version,                                # stores the active comparison logic version for history filtering
        "rdi_score_0_100": stage4_metrics["rdi_score_0_100"],                               # stores the RDI score
        "overall_voice_pattern_shift": stage5_scores["overall_voice_pattern_shift"],        # stores the overall pattern-shift score
        "regulation_consistency_shift": stage5_scores["regulation_consistency_shift"],      # stores regulation-consistency shift
        "return_to_baseline_window_shift": stage5_scores["return_to_baseline_window_shift"],  # stores return-window shift
        "adaptive_transition_pattern_shift": stage5_scores["adaptive_transition_pattern_shift"],  # stores adaptive-transition shift
    }                                                                                       # closes the history row
    updated_history = upsert_history(existing_history, current_row)                         # inserts the current session without duplicating reruns
    rdi_values = [row["rdi_score_0_100"] for row in updated_history]                        # collects RDI history
    overall_values = [row["overall_voice_pattern_shift"] for row in updated_history]        # collects overall pattern-shift history
    consistency_values = [row["regulation_consistency_shift"] for row in updated_history]   # collects consistency-shift history
    return_values = [row["return_to_baseline_window_shift"] for row in updated_history]     # collects return-window history
    transition_values = [row["adaptive_transition_pattern_shift"] for row in updated_history]  # collects adaptive-transition history
    trend_summary = {                                                                       # creates the Stage 6 trend summary
        "comparison_session_count": len(updated_history),                                   # stores the number of comparison sessions in history
        "comparison_model_version": comparison_model_version,                                # stores the active comparison logic version
        "rdi_trend_slope": round(trend_slope(rdi_values), 6),                               # stores RDI trend slope
        "overall_pattern_shift_slope": round(trend_slope(overall_values), 6),               # stores overall shift trend slope
        "regulation_consistency_shift_slope": round(trend_slope(consistency_values), 6),    # stores consistency trend slope
        "return_to_baseline_window_shift_slope": round(trend_slope(return_values), 6),      # stores return-window trend slope
        "adaptive_transition_pattern_shift_slope": round(trend_slope(transition_values), 6),  # stores transition trend slope
        "stability_window_status": "provisional" if len(updated_history) < 3 else "trend tracking active",  # labels trend maturity
        "variability_cluster": "insufficient_comparison_history" if len(updated_history) < 3 else pattern_band(float(np.mean(overall_values[-3:]))),  # summarizes recent pattern grouping
        "trend_note": "Trend values become more meaningful after several comparison sessions.",  # stores a neutral trend note
    }                                                                                       # closes the trend summary
    history_path.write_text(json.dumps(updated_history, indent=2), encoding="utf-8")        # saves the updated comparison history
    return trend_summary, updated_history                                                   # returns the current trend summary and history


def compute_customer_display_scores(stage4_metrics, stage5_scores, stage6_trends):          # converts internal research scores into customer-facing BioVoiceprint display values
    regulation_stability_shift = weighted_mean(                                             # computes the customer-facing closeness-to-baseline shift estimate
        [stage4_metrics["rdi_score_0_100"], stage5_scores["regulation_consistency_shift"], stage4_metrics["signal_clarity_shift"]],  # combines overall deviation, consistency, and clarity shifts
        [0.50, 0.30, 0.20],                                                                 # emphasizes the internal RDI while still reflecting consistency and clarity
    )                                                                                       # closes regulation-stability shift calculation
    adaptive_capacity_shift = weighted_mean(                                                # computes how much adaptive voice-pattern behavior shifted from baseline
        [stage4_metrics["variability_pattern_shift"], stage5_scores["flexibility_range_shift"], stage5_scores["adaptive_transition_pattern_shift"]],  # combines variability, flexibility, and transition shifts
        [0.30, 0.35, 0.35],                                                                 # balances dynamic variability with flexibility and transition behavior
    )                                                                                       # closes adaptive-capacity shift calculation
    recovery_consistency_shift = weighted_mean(                                             # computes how much return-to-baseline voice behavior shifted from baseline
        [stage5_scores["return_to_baseline_window_shift"], stage4_metrics["transition_continuity_shift"], stage4_metrics["sustained_phonation_shift"]],  # combines return-window, continuity, and sustained-voice shifts
        [0.45, 0.30, 0.25],                                                                 # emphasizes the return-to-baseline window while preserving traceable voice features
    )                                                                                       # closes recovery-consistency shift calculation
    voice_pattern_shift = stage5_scores["overall_voice_pattern_shift"]                      # uses the Stage 5 composite as the simple customer voice-pattern shift value
    regulation_stability_score = clip_0_100(100.0 - regulation_stability_shift)             # converts shift into a customer strength score where higher means closer to baseline
    adaptive_capacity_score = clip_0_100(100.0 - adaptive_capacity_shift)                   # converts adaptive shift into a strength score where higher means more baseline-consistent adaptation
    recovery_consistency_score = clip_0_100(100.0 - recovery_consistency_shift)             # converts recovery-pattern shift into a strength score where higher means more baseline-consistent return behavior
    voice_pattern_stability_score = clip_0_100(100.0 - voice_pattern_shift)                 # converts overall voice-pattern shift into a customer score where higher means closer to baseline
    baseline_alignment_score = clip_0_100(100.0 - stage4_metrics["rdi_score_0_100"])        # converts RDI into a customer score where higher means closer to baseline
    baseline_alignment = customer_shift_band(stage4_metrics["rdi_score_0_100"])            # creates a customer label from the tighter RDI-based alignment bands
    interpretation = "Your recorded voice patterns stayed close to your personal baseline. Small variations may be present; this result is for awareness and longitudinal comparison only."  # stores neutral display interpretation
    return {                                                                                # returns the customer-facing display dictionary
        "regulation_stability": {                                                           # opens Regulation Stability display values
            "score": round(regulation_stability_score, 6),                                  # stores the customer strength score
            "label": customer_strength_label(regulation_stability_score),                   # stores a simple strength label
            "source_shift_score": round(regulation_stability_shift, 6),                     # stores the traceable shift value behind the strength score
            "direction": "higher = closer to personal baseline",                           # stores customer score direction
        },                                                                                  # closes Regulation Stability display values
        "adaptive_capacity": {                                                              # opens Adaptive Capacity display values
            "score": round(adaptive_capacity_score, 6),                                     # stores the customer strength score
            "label": customer_strength_label(adaptive_capacity_score),                      # stores a simple strength label
            "source_shift_score": round(adaptive_capacity_shift, 6),                        # stores the traceable shift value behind the strength score
            "direction": "higher = more baseline-consistent adaptive voice-pattern behavior",  # stores customer score direction
        },                                                                                  # closes Adaptive Capacity display values
        "recovery_consistency": {                                                           # opens Recovery Consistency display values
            "score": round(recovery_consistency_score, 6),                                  # stores the customer strength score
            "label": customer_strength_label(recovery_consistency_score),                   # stores a simple strength label
            "source_shift_score": round(recovery_consistency_shift, 6),                     # stores the traceable shift value behind the strength score
            "direction": "higher = more baseline-consistent return-to-baseline voice behavior",  # stores customer score direction
        },                                                                                  # closes Recovery Consistency display values
        "voice_pattern_stability": {                                                        # opens Voice Pattern Stability display values
            "score": round(voice_pattern_stability_score, 6),                               # stores the customer score where higher means closer to baseline
            "label": customer_strength_label(voice_pattern_stability_score),                # stores a simple strength label
            "source_shift_score": round(voice_pattern_shift, 6),                            # stores the traceable overall voice-pattern shift behind the stability score
            "source_shift_band": customer_shift_band(voice_pattern_shift),                  # stores the raw shift severity band for technical traceability
            "source_shift_color": customer_shift_color(voice_pattern_shift),                # stores the raw shift color for technical traceability
            "direction": "higher = closer to personal baseline",                           # stores customer score direction
        },                                                                                  # closes Voice Pattern Stability display values
        "baseline_alignment": {                                                             # opens Baseline Alignment display values
            "score": round(baseline_alignment_score, 6),                                    # stores the customer score where higher means closer to baseline
            "label": customer_strength_label(baseline_alignment_score),                     # stores a simple strength label
            "source_shift_score": round(stage4_metrics["rdi_score_0_100"], 6),              # stores the traceable RDI shift behind the alignment score
            "source_shift_band": baseline_alignment,                                        # stores the raw RDI severity band for technical traceability
            "source_shift_color": customer_shift_color(stage4_metrics["rdi_score_0_100"]),  # stores the raw RDI color for technical traceability
            "direction": "higher = closer to personal baseline",                           # stores customer score direction
        },                                                                                  # closes Baseline Alignment display values
        "trend_direction": trend_direction_label(stage6_trends),                            # stores the trend direction label when enough sessions exist
        "plain_language_interpretation": interpretation,                                    # stores the neutral customer interpretation
    }                                                                                       # closes the customer-facing display dictionary


def round_1(value):                                                                         # rounds report values to one decimal place
    return round(float(value or 0.0), 1)                                                     # returns a safe one-decimal float


def round_2(value):                                                                         # rounds report values to two decimal places
    return round(float(value or 0.0), 2)                                                     # returns a safe two-decimal float


def group_features_by_band(feature_rows):                                                   # groups feature rows by their final six-level severity label
    return {                                                                                # returns grouped feature lists for report generation
        "baseline_consistent": [row for row in feature_rows if row.get("band") == "Baseline Consistent"],  # stores baseline-consistent markers
        "minimal": [row for row in feature_rows if row.get("band") == "Minimal Shift"],     # stores minimal-shift markers
        "mild": [row for row in feature_rows if row.get("band") == "Mild Pattern Shift"],   # stores mild-shift markers
        "moderate": [row for row in feature_rows if row.get("band") == "Moderate Pattern Shift"],  # stores moderate-shift markers
        "significant": [row for row in feature_rows if row.get("band") == "Significant Pattern Shift"],  # stores significant-shift markers
        "extreme": [row for row in feature_rows if row.get("band") == "Extreme Pattern Shift"],  # stores extreme-shift markers
        "outside_range": [row for row in feature_rows if row.get("threshold_status") == "outside_personal_baseline_range"],  # stores markers outside observed baseline range
    }                                                                                       # closes the grouped feature dictionary


def top_shifted_features(feature_rows, count=5):                                            # selects the highest-shift feature rows for client/provider summaries
    usable_rows = [row for row in feature_rows if isinstance(row.get("deviation_score_0_100"), (int, float))]  # keeps rows with numeric final scores
    return sorted(usable_rows, key=lambda row: row["deviation_score_0_100"], reverse=True)[:count]  # returns the largest shifted markers


def top_stage4_domain(stage4_metrics):                                                      # identifies the highest Stage 4 domain score
    domains = {                                                                             # maps Stage 4 domain names to their scores
        "variability_pattern_shift": stage4_metrics.get("variability_pattern_shift"),       # includes variability-pattern shift
        "harmonic_structure_shift": stage4_metrics.get("harmonic_structure_shift"),         # includes harmonic-structure shift
        "transition_continuity_shift": stage4_metrics.get("transition_continuity_shift"),   # includes transition-continuity shift
        "micro_modulation_shift": stage4_metrics.get("micro_modulation_shift"),             # includes micro-modulation shift
        "signal_clarity_shift": stage4_metrics.get("signal_clarity_shift"),                 # includes signal-clarity shift
        "distribution_balance_shift": stage4_metrics.get("distribution_balance_shift"),     # includes distribution-balance shift
        "sustained_phonation_shift": stage4_metrics.get("sustained_phonation_shift"),       # includes sustained-phonation shift
    }                                                                                       # closes the domain dictionary
    numeric_domains = [(name, value) for name, value in domains.items() if isinstance(value, (int, float))]  # keeps domains with numeric scores
    return sorted(numeric_domains, key=lambda item: item[1], reverse=True)[0] if numeric_domains else None  # returns the highest domain or None


def humanize_feature(feature_name):                                                         # converts engineering feature names into client-friendly labels
    labels = {                                                                              # opens the feature-label dictionary
        "f0_mean_hz": "vocal pitch center",                                                 # labels pitch center
        "hnr_db": "voice clarity",                                                         # labels harmonic-to-noise ratio
        "spectral_centroid_hz": "frequency balance",                                       # labels spectral centroid
        "spectral_bandwidth_hz": "frequency spread",                                       # labels spectral bandwidth
        "jitter_local": "tiny pitch variation",                                            # labels jitter
        "shimmer_local": "tiny volume variation",                                          # labels shimmer
        "zero_crossing_rate": "signal transition activity",                                # labels zero-crossing rate
        "envelope_variance": "vocal energy variation",                                     # labels envelope variance
        "tremor_band_power_3_12hz": "micro-modulation activity",                           # labels tremor-band power
        "approximate_entropy": "voice pattern complexity",                                 # labels approximate entropy
        "sample_entropy": "adaptive voice variability",                                    # labels sample entropy
        "fractal_dimension": "pattern organization",                                       # labels fractal dimension
        "lyapunov_proxy": "dynamic voice stability",                                       # labels Lyapunov proxy
        "resp_sync_lag_sec": "breath-voice timing",                                        # labels respiratory synchronization lag
        "resp_amp_variance": "breathing-related voice variation",                          # labels respiratory amplitude variance
        "phonation_stability_exhalation": "voice steadiness during exhalation",             # labels phonation stability
    }                                                                                       # closes the feature-label dictionary
    return labels.get(feature_name, feature_name.replace("_", " "))                         # returns the friendly label or a readable fallback


def feature_direction(z_score):                                                             # turns signed Z-score direction into plain language
    if z_score > 0.10:                                                                      # checks whether the feature moved meaningfully upward
        return "higher than baseline"                                                       # returns upward direction
    if z_score < -0.10:                                                                     # checks whether the feature moved meaningfully downward
        return "lower than baseline"                                                        # returns downward direction
    return "very close to baseline"                                                         # returns near-baseline direction


def explain_feature_in_common_language(feature_row):                                        # explains each feature in gentle non-diagnostic language
    explanations = {                                                                        # opens the common-language explanation dictionary
        "f0_mean_hz": "This reflects the natural pitch center of the voice. A shift may show that the speaker used a slightly different vocal setting than usual.",  # explains pitch center
        "hnr_db": "This reflects how clear and tone-rich the voice signal appears. A shift may show a change in vocal clarity or steadiness.",  # explains voice clarity
        "spectral_centroid_hz": "This reflects the brightness or balance of the voice frequencies. A shift may show that the voice sounded slightly brighter, heavier, tighter, or softer than usual.",  # explains frequency balance
        "spectral_bandwidth_hz": "This reflects how widely voice energy spreads across frequencies. A shift may show a different vocal strategy or a temporary change in vocal demand.",  # explains frequency spread
        "jitter_local": "This reflects tiny pitch variations during sustained voice. A shift may suggest the voice could benefit from hydration, warm-up, or easier pacing.",  # explains jitter safely
        "shimmer_local": "This reflects tiny volume variations during sustained voice. A shift may suggest the voice used slightly more effort to stay smooth.",  # explains shimmer safely
        "zero_crossing_rate": "This reflects how actively the signal changes. A shift may show changes in sharpness, pacing, or transition activity.",  # explains zero crossing
        "envelope_variance": "This reflects variation in vocal energy. A shift may show changes in emphasis, breath support, or vocal effort.",  # explains energy variation
        "tremor_band_power_3_12hz": "This reflects subtle micro-modulation in the voice. A shift may be useful to watch alongside fatigue, hydration, workload, and vocal-use context.",  # explains tremor safely
        "approximate_entropy": "This reflects how predictable or irregular the voice pattern appears. A shift may show a change in pattern complexity.",  # explains approximate entropy
        "sample_entropy": "This reflects adaptive variability in the voice signal. A shift may show that the voice pattern used a different variability profile than baseline.",  # explains sample entropy
        "fractal_dimension": "This reflects the organization of the voice pattern. A shift may show a change in how structured the signal appears.",  # explains fractal dimension
        "lyapunov_proxy": "This reflects dynamic voice stability. A shift may show a change in how steadily the voice pattern is maintained over short windows.",  # explains Lyapunov proxy
        "resp_sync_lag_sec": "This reflects estimated breath-voice timing from the audio. A shift may suggest the timing pattern differed from baseline.",  # explains respiratory lag
        "resp_amp_variance": "This reflects breathing-related variation in the voice. A shift may show a change in breath-supported voice energy.",  # explains respiratory amplitude variance
        "phonation_stability_exhalation": "This reflects how steadily the voice is maintained during exhalation. A shift may suggest breath pacing or gentle warm-up could be helpful.",  # explains phonation stability
    }                                                                                       # closes the explanation dictionary
    return explanations.get(feature_row.get("feature_name"), "This marker reflects a baseline-relative voice-pattern change.")  # returns the explanation


def explain_top_domain(domain_tuple):                                                       # explains the highest Stage 4 domain in plain language
    if not domain_tuple:                                                                    # checks whether a domain was available
        return "No dominant pattern area was detected."                                     # returns a neutral fallback
    domain, score = domain_tuple                                                            # unpacks the domain name and score
    domain_map = {                                                                          # opens the domain explanation dictionary
        "variability_pattern_shift": "The strongest pattern area was variability, which reflects how much the voice pattern changed compared with usual rhythm and stability.",  # explains variability
        "harmonic_structure_shift": "The strongest pattern area was harmonic structure, which reflects clarity, organization, and steadiness of the voice signal.",  # explains harmonic structure
        "transition_continuity_shift": "The strongest pattern area was transition continuity, which reflects how smoothly the voice moves from one sound pattern to another.",  # explains transition continuity
        "micro_modulation_shift": "The strongest pattern area was micro-modulation, which reflects tiny voice fluctuations worth monitoring with fatigue, hydration, workload, and vocal-use context.",  # explains micro-modulation
        "signal_clarity_shift": "The strongest pattern area was signal clarity, which reflects how clean and stable the voice tone appears.",  # explains signal clarity
        "distribution_balance_shift": "The strongest pattern area was distribution balance, which reflects how voice energy is spread across the acoustic pattern.",  # explains distribution balance
        "sustained_phonation_shift": "The strongest pattern area was sustained phonation, which reflects how steadily the voice is maintained during exhalation.",  # explains sustained phonation
    }                                                                                       # closes the domain explanation dictionary
    return f"{domain_map.get(domain, 'The strongest area reflects a baseline-relative voice-pattern shift')} Score: {round_1(score)}/100."  # returns the domain explanation


def vocal_readiness_interpretation(rdi, groups):                                            # creates gentle readiness wording from the RDI and feature groups
    shifted_count = len(groups["minimal"]) + len(groups["mild"]) + len(groups["moderate"]) + len(groups["significant"]) + len(groups["extreme"])  # counts shifted markers
    if rdi <= 12.5 and shifted_count == 0:                                                   # checks for strong baseline consistency
        return {                                                                            # returns the strongest readiness wording
            "short_status": "strong baseline consistency",                                  # stores short status
            "overall_summary": "The overall voice pattern is very close to the personal baseline.",  # stores overall summary
            "performance_summary": "The voice pattern appears well prepared for clear communication and sustained speaking.",  # stores performance summary
            "shift_explanation": "Only expected day-to-day variation is visible in this recording.",  # stores shift explanation
            "final_summary": "The voice appears ready for speaking demands, with no major preparation concerns detected.",  # stores final summary
        }                                                                                   # closes the strongest readiness wording
    if rdi <= 25.0:                                                                         # checks for baseline/minimal overall shift
        return {                                                                            # returns stable-with-localized-shifts wording
            "short_status": "stable overall pattern with small localized shifts",            # stores short status
            "overall_summary": "The overall voice pattern remains close to baseline, though a few markers may be moving more than usual.",  # stores overall summary
            "performance_summary": "The voice appears generally ready, with possible benefit from a brief warm-up and steady pacing.",  # stores performance summary
            "shift_explanation": "Localized shifts may reflect normal variation, vocal use, hydration, workload, fatigue, or recording context.",  # stores shift explanation
            "final_summary": "The voice is ready overall; a few minutes of preparation may support ease, steadiness, and projection.",  # stores final summary
        }                                                                                   # closes stable-with-localized-shifts wording
    if rdi <= 37.5:                                                                         # checks for mild overall shift
        return {                                                                            # returns mild-shift wording
            "short_status": "mild movement from baseline",                                  # stores short status
            "overall_summary": "The voice pattern shows a mild baseline-relative shift.",    # stores overall summary
            "performance_summary": "The voice can likely perform effectively, but preparation becomes more useful.",  # stores performance summary
            "shift_explanation": "The speaker may benefit from hydration, slower opening pace, breath support, and gentle vocal warm-up.",  # stores shift explanation
            "final_summary": "The voice can still perform well; preparation should be prioritized before speaking or presenting.",  # stores final summary
        }                                                                                   # closes mild-shift wording
    if rdi <= 50.0:                                                                         # checks for moderate overall shift
        return {                                                                            # returns moderate-shift wording
            "short_status": "moderate baseline-relative shift",                             # stores short status
            "overall_summary": "The voice pattern shows a more noticeable shift from baseline.",  # stores overall summary
            "performance_summary": "The voice may benefit from intentional preparation to support clarity, endurance, and projection.",  # stores performance summary
            "shift_explanation": "It may help to slow the opening, reduce throat effort, and use breath-supported projection.",  # stores shift explanation
            "final_summary": "The voice may perform effectively, but preparation should be treated as important rather than optional.",  # stores final summary
        }                                                                                   # closes moderate-shift wording
    return {                                                                                # returns significant/extreme wording
        "short_status": "larger baseline-relative shift",                                   # stores short status
        "overall_summary": "The voice pattern is meaningfully different from the personal baseline.",  # stores overall summary
        "performance_summary": "The voice may need additional support before important speaking demands.",  # stores performance summary
        "shift_explanation": "A repeat recording under consistent conditions can help confirm whether the pattern is temporary or persistent.",  # stores shift explanation
        "final_summary": "Consider additional warm-up, pacing, hydration, and recovery context before high-demand speaking.",  # stores final summary
    }                                                                                       # closes significant/extreme wording


def performance_preparation_guidance(rdi, top_features):                                    # creates safe speaking-preparation recommendations
    recommendations = [                                                                     # starts the core recommendation list
        {"title": "Hydrate before speaking", "explanation": "Sip water consistently before speaking so hydration is already in place before the presentation begins."},  # adds hydration guidance
        {"title": "Use slow breath pacing", "explanation": "Take several slow breaths before beginning, letting the shoulders and jaw soften while the breath expands through the ribs."},  # adds breathing guidance
        {"title": "Warm up gently", "explanation": "Use gentle humming, lip trills, or comfortable vowel sounds for a few minutes, focusing on ease rather than volume."},  # adds warm-up guidance
        {"title": "Start slightly slower", "explanation": "Starting a little slower can help breath, voice, and pacing settle before speaking demand increases."},  # adds pacing guidance
    ]                                                                                       # closes the core recommendation list
    feature_names = [row.get("feature_name") for row in top_features]                       # collects the top shifted feature names
    if "jitter_local" in feature_names or "shimmer_local" in feature_names:                 # checks for sustained-voice micro-instability markers
        recommendations.append({"title": "Reduce vocal effort", "explanation": "Because small pitch or volume variations shifted, avoid forcing volume and use breath-supported projection."})  # adds vocal-effort guidance
    if any(name in feature_names for name in ["resp_sync_lag_sec", "resp_amp_variance", "phonation_stability_exhalation"]):  # checks for breath-related markers
        recommendations.append({"title": "Anchor breath before speaking", "explanation": "Because breath-related voice markers shifted, spend extra time on slow exhalation and breath pacing."})  # adds breath-anchor guidance
    if rdi > 37.5:                                                                          # checks for moderate or higher overall shift
        recommendations.append({"title": "Repeat if possible", "explanation": "If the result is moderately or more shifted, a second recording under consistent conditions can help confirm the pattern."})  # adds repeat-recording guidance
    return recommendations                                                                  # returns the recommendation list


def preventive_wellness_interpretation(rdi, groups):                                       # creates non-diagnostic wellness-context interpretation
    shifted_count = len(groups["minimal"]) + len(groups["mild"]) + len(groups["moderate"]) + len(groups["significant"]) + len(groups["extreme"])  # counts shifted markers
    if rdi <= 12.5 and shifted_count <= 2:                                                   # checks for highly consistent overall pattern
        return {"summary": "This is a reassuring baseline-relative pattern.", "context": "Most voice markers stayed close to baseline, which can be used as a stable reference point for future comparison."}  # returns stable wellness wording
    if rdi <= 25.0:                                                                         # checks for minimal overall shift
        return {"summary": "This result suggests stable overall voice-pattern regulation with a few early shift signals.", "context": "These shifts may be useful to review alongside sleep, hydration, workload, vocal use, and recovery context."}  # returns minimal wording
    if rdi <= 37.5:                                                                         # checks for mild overall shift
        return {"summary": "This result shows a mild baseline-relative voice-pattern shift.", "context": "This may be a useful moment for early lifestyle support and monitoring over the next few recordings."}  # returns mild wording
    if rdi <= 50.0:                                                                         # checks for moderate overall shift
        return {"summary": "This result shows a moderate baseline-relative voice-pattern shift.", "context": "Follow-up recordings can help determine whether the pattern returns toward baseline or continues shifting."}  # returns moderate wording
    return {"summary": "This result shows a larger baseline-relative voice-pattern shift.", "context": "This does not diagnose a condition; it should be interpreted with repeat recordings and other wellness inputs."}  # returns significant/extreme wording


def trend_guidance(rdi, stage6_trends):                                                     # creates plain-language trend guidance
    if stage6_trends.get("comparison_session_count", 0) < 3:                                # checks whether comparison history is still early
        return "This recording is a point-in-time snapshot. Trend interpretation becomes more useful after several comparison sessions."  # returns early-trend guidance
    if rdi <= 25.0:                                                                         # checks for baseline/minimal shift
        return "If future recordings remain in this range, the voice pattern is staying close to baseline. Watch whether any localized shifted markers repeat."  # returns low-shift trend guidance
    if rdi <= 50.0:                                                                         # checks for mild/moderate shift
        return "Watch whether the score moves back toward baseline, stays similar, or increases across the next few recordings."  # returns mid-shift trend guidance
    return "A repeat recording under similar conditions is useful before drawing conclusions from one larger shift."  # returns high-shift trend guidance


def generate_plain_language_report(session_id, stage4_metrics, stage5_scores, stage6_trends, feature_rows, customer_scores, subjective_objective_alignment):  # builds a user-facing interpretation report from existing scores
    rdi = stage4_metrics.get("rdi_score_0_100", 0.0)                                       # reads the RDI score
    groups = group_features_by_band(feature_rows)                                          # groups feature rows by final severity label
    top_features = top_shifted_features(feature_rows, 5)                                   # selects the strongest shifted markers
    top_domain = top_stage4_domain(stage4_metrics)                                         # selects the strongest Stage 4 domain
    readiness = vocal_readiness_interpretation(rdi, groups)                                # creates readiness interpretation
    wellness = preventive_wellness_interpretation(rdi, groups)                             # creates wellness-context interpretation
    return {                                                                                # returns the plain-language report object
        "report_title": "BioVoicePrint Performance and Wellness Insights",                  # stores the report title
        "participant_label": participant_id,                                                # stores the participant label
        "comparison_session_id": session_id,                                                # stores the comparison session ID
        "overall_result": {                                                                 # opens the overall result block
            "rdi_score_0_100": round_1(rdi),                                                # stores rounded RDI
            "rdi_band": stage4_metrics.get("rdi_band", customer_shift_band(rdi)),           # stores RDI band
            "rdi_color": stage4_metrics.get("rdi_color", customer_shift_color(rdi)),        # stores RDI color
            "score_direction": "Higher score = greater shift from personal baseline",       # stores score direction
            "plain_language_summary": f"{participant_id}'s BioVoicePrint shows {readiness['short_status']}. The current RDI is {round_1(rdi)}/100, which falls in the {stage4_metrics.get('rdi_band', customer_shift_band(rdi))} range.",  # stores short summary
        },                                                                                  # closes the overall result block
        "overall_biovoiceprint_summary": readiness["overall_summary"],                      # stores overall summary
        "vocal_readiness_and_performance": f"{readiness['performance_summary']} {readiness['shift_explanation']}",  # stores performance wording
        "what_your_voice_is_telling_you": explain_feature_in_common_language(top_features[0]) if top_features else "No dominant voice-pattern shift was detected in this recording.",  # stores top feature explanation
        "key_findings": {                                                                   # opens key findings
            "baseline_consistent_markers": len(groups["baseline_consistent"]),              # stores baseline-consistent count
            "minimal_shift_markers": len(groups["minimal"]),                                # stores minimal-shift count
            "mild_shift_markers": len(groups["mild"]),                                      # stores mild-shift count
            "moderate_shift_markers": len(groups["moderate"]),                              # stores moderate-shift count
            "significant_shift_markers": len(groups["significant"]),                        # stores significant-shift count
            "extreme_shift_markers": len(groups["extreme"]),                                # stores extreme-shift count
            "outside_personal_range_markers": len(groups["outside_range"]),                 # stores outside-range count
            "strongest_pattern_area": explain_top_domain(top_domain),                       # stores top domain explanation
            "most_shifted_markers": [                                                       # opens top shifted marker summaries
                {                                                                           # opens one marker summary
                    "feature_name": row.get("feature_name"),                                # stores technical feature name
                    "display_name": humanize_feature(row.get("feature_name")),              # stores friendly feature label
                    "score_0_100": round_1(row.get("deviation_score_0_100")),               # stores rounded final feature score
                    "z_score": round_2(row.get("z_score")),                                 # stores rounded Z-score
                    "band": row.get("band"),                                                # stores final feature band
                    "color": row.get("color"),                                              # stores final feature color
                    "direction": feature_direction(row.get("z_score", 0.0)),                # stores feature direction
                    "common_language_meaning": explain_feature_in_common_language(row),     # stores common-language feature explanation
                }                                                                           # closes one marker summary
                for row in top_features                                                     # loops through top shifted markers
            ],                                                                              # closes top shifted marker summaries
        },                                                                                  # closes key findings
        "before_you_speak_or_present": performance_preparation_guidance(rdi, top_features), # stores preparation guidance
        "performance_readiness_summary": readiness["final_summary"],                        # stores readiness summary
        "preventive_wellness_insight": wellness,                                            # stores preventive wellness context
        "subjective_objective_alignment": subjective_objective_alignment,                    # stores the new pre-recording self-report versus BioVoicePrint alignment layer
        "what_to_watch_over_time": {                                                        # opens trend guidance block
            "summary": trend_guidance(rdi, stage6_trends),                                  # stores trend summary
            "tracking_recommendations": [                                                   # opens tracking recommendations
                "Repeat recordings under similar conditions.",                              # stores repeat recording recommendation
                "Compare changes with sleep quality, hydration, workload, vocal use, fatigue, and recovery context.",  # stores context recommendation
                "Look for repeated patterns rather than reacting to one isolated reading.",  # stores trend recommendation
                "Watch whether shifted markers return to baseline, remain similar, or continue increasing.",  # stores marker trend recommendation
            ],                                                                              # closes tracking recommendations
        },                                                                                  # closes trend guidance block
        "customer_facing_scores": customer_scores,                                          # stores the customer display score layer
        "safe_disclaimer": "BioVoicePrint is a non-diagnostic wellness and performance signal. It does not diagnose disease, emotional states, anxiety, depression, PTSD, or any medical or mental health condition. It reflects voice-derived patterns compared with the individual's own baseline and should be interpreted alongside other wellness inputs.",  # stores safety disclaimer
    }                                                                                       # closes the plain-language report object


# ==========================================================
# STAGE 7: BACKEND / DASHBOARD PACKAGE
# ==========================================================


def build_stage7_payload(session_id, assessment_row, stage4_metrics, stage5_scores, stage6_trends, feature_rows, customer_scores, subjective_objective_alignment):  # packages comparison results for future app use
    return {                                                                                # opens the backend payload
        "api_request": {                                                                    # opens API-style request metadata
            "endpoint": "POST /biovoice-bsi-comparison",                                   # stores the future endpoint name
            "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),                      # stores payload creation time
            "local_simulation_only": True,                                                  # marks this as a local development payload
        },                                                                                  # closes API request metadata
        "voice_engine": {                                                                   # opens voice-engine metadata
            "tokenized_user_id": tokenized_id,                                              # stores the de-identified user token
            "participant_label": participant_id,                                            # stores the local participant label for project organization
            "device_type": device_type,                                                     # stores device type
            "comparison_session_id": session_id,                                            # stores comparison session ID
            "recording_count": len(feature_rows),                                          # stores number of feature rows
            "feature_vector_source": "comparison_session_stage3_feature_vectors",           # stores the feature-vector source label
        },                                                                                  # closes voice-engine metadata
        "pre_recording_context": assessment_row,                                            # stores the five-question context responses
        "subjective_objective_alignment": subjective_objective_alignment,                    # stores the wellness-anchor comparison with current BioVoicePrint alignment
        "baseline_relative_pattern_comparison": {                                           # opens baseline-relative comparison output
            "rdi_score": display_score(stage4_metrics["rdi_score_0_100"]),                 # stores display-ready RDI
            "stage4_metrics": stage4_metrics,                                               # stores Stage 4 metrics
            "stage5_bsi_framework_scores": {                                                # opens Stage 5 display scores
                key: display_score(value)                                                   # creates display object for each numeric Stage 5 value
                for key, value in stage5_scores.items()                                     # loops through Stage 5 values
                if isinstance(value, (int, float))                                          # keeps only numeric scores
            },                                                                              # closes Stage 5 display scores
            "score_direction": "higher = greater baseline-relative pattern shift",          # stores score direction
        },                                                                                  # closes baseline-relative comparison output
        "longitudinal_pattern_tracking": stage6_trends,                                     # stores Stage 6 trends
        "customer_facing_biovoiceprint": customer_scores,                                   # stores simplified customer-facing BioVoiceprint display values
        "dashboard_push": {                                                                 # opens dashboard-friendly display data
            "member_app": {                                                                 # opens member app display block
                "display_score": customer_scores["regulation_stability"]["score"],          # stores the primary customer score where higher means closer to baseline
                "display_label": customer_scores["baseline_alignment"]["label"],            # stores the primary customer baseline-alignment label
                "display_color": customer_scores["baseline_alignment"]["source_shift_color"],  # stores the primary customer display color from RDI severity
                "display_language": "BioVoiceprint comparison to your personal baseline.",  # stores neutral app-facing language
                "regulation_stability": customer_scores["regulation_stability"],            # stores Regulation Stability for the member app
                "adaptive_capacity": customer_scores["adaptive_capacity"],                  # stores Adaptive Capacity for the member app
                "recovery_consistency": customer_scores["recovery_consistency"],            # stores Recovery Consistency for the member app
                "voice_pattern_stability": customer_scores["voice_pattern_stability"],      # stores Voice Pattern Stability for the member app
                "plain_language_interpretation": customer_scores["plain_language_interpretation"],  # stores the customer interpretation
                "subjective_objective_alignment": subjective_objective_alignment,            # stores app-ready self-report versus voice-baseline alignment
            },                                                                              # closes member app display block
            "provider_dashboard": {                                                         # opens provider dashboard block
                "rdi_score_0_100": stage4_metrics["rdi_score_0_100"],                       # stores RDI score
                "overall_voice_pattern_shift": stage5_scores["overall_voice_pattern_shift"],  # stores overall pattern shift
                "comparison_session_count": stage6_trends["comparison_session_count"],      # stores comparison history count
                "trend_status": stage6_trends["stability_window_status"],                   # stores longitudinal trend status
                "subjective_objective_alignment_type": subjective_objective_alignment["alignment_type"]["code"],  # stores the alignment classification code
                "subjective_objective_alignment_label": subjective_objective_alignment["alignment_type"]["label"],  # stores the alignment classification label
                "subjective_objective_gap": subjective_objective_alignment["comparison_metrics"]["descriptive_index_gap"],  # stores the descriptive index gap
            },                                                                              # closes provider dashboard block
        },                                                                                  # closes dashboard push data
        "language_guardrails": {                                                            # opens non-diagnostic guardrails
            "foundational_disclaimer": "Voice regulation analysis evaluates acoustic and regulatory patterns within recorded speech and does not diagnose medical, psychological, or neurological conditions.",  # stores core disclaimer
            "interpretation_boundary": "Scores describe baseline-relative voice-pattern shifts only; they do not directly determine emotional, digestive, inflammatory, metabolic, or recovery states.",  # stores interpretation boundary
            "use_for": "Awareness, pattern recognition, and longitudinal comparison only.",  # stores intended use
        },                                                                                  # closes non-diagnostic guardrails
    }                                                                                       # closes backend payload


# ------------------------------------------------------------------
# MAIN RUN
# ------------------------------------------------------------------

if not comparison_session_folder.exists():                                                  # checks whether a future comparison session folder exists
    raise FileNotFoundError(                                                                # stops with a clear setup message when no comparison audio has been uploaded yet
        f"Missing comparison session folder: {comparison_session_folder}. Create this folder after you record a non-baseline comparison session."  # explains the missing-folder fix
    )                                                                                       # closes the missing-folder error

baseline_reference = load_baseline_reference(baseline_reference_path)                       # loads the saved personal baseline
comparison_run_timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")                     # stores when this comparison run started
session_processed_timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")                  # stores when this comparison session began processing
session_id = comparison_session_folder.name                                                 # uses the folder name as the comparison session ID

print("\n------------------------------------------------------------")                      # prints a visual divider before processing
print(f"Processing comparison session: {session_id}")                                      # prints the comparison session ID
print("Stages 1-3: extract comparable voice-pattern features.")                             # explains why Stages 1-3 still run
print("Stage 4: compare current features to the saved personal baseline.")                  # explains Stage 4
print("Stage 5: calculate neutral BSI-framework voice-pattern composites.")                 # explains Stage 5
print("Stage 6: update longitudinal pattern tracking.")                                     # explains Stage 6
print("Stage 7: package dashboard/API output.")                                             # explains Stage 7
print("------------------------------------------------------------")                       # prints a closing visual divider

assessment_row = get_or_collect_comparison_assessment(session_id, session_processed_timestamp)  # reuses or collects the five context responses for the comparison session
qc_rows, raw_signals = run_stage1_capture_and_qc(comparison_session_folder, comparison_run_timestamp, session_processed_timestamp)  # runs Stage 1
conditioned_signals = run_stage2_conditioning(raw_signals)                                  # runs Stage 2
feature_rows = run_stage3_feature_extraction(session_id, conditioned_signals)               # runs Stage 3
current_feature_summary = summarize_current_features(feature_rows)                          # summarizes the current session features
feature_deviation_rows = compute_feature_deviations(current_feature_summary, baseline_reference)  # runs feature-level Stage 4 comparison
stage4_metrics = compute_stage4_metrics(feature_deviation_rows)                             # computes Stage 4 composite metrics
stage5_scores = compute_stage5_scores(stage4_metrics, feature_deviation_rows)               # computes Stage 5 neutral BSI-framework scores
stage6_trends, longitudinal_history = compute_stage6_trends(session_id, stage4_metrics, stage5_scores)  # computes Stage 6 trends
customer_scores = compute_customer_display_scores(stage4_metrics, stage5_scores, stage6_trends)  # creates the customer-facing BioVoiceprint score layer
subjective_objective_alignment = build_subjective_objective_alignment(assessment_row, baseline_reference, stage4_metrics, customer_scores, longitudinal_history)  # builds the pre-recording self-report versus BioVoicePrint alignment layer
stage7_payload = build_stage7_payload(session_id, assessment_row, stage4_metrics, stage5_scores, stage6_trends, feature_rows, customer_scores, subjective_objective_alignment)  # builds Stage 7 payload
plain_language_report = generate_plain_language_report(session_id, stage4_metrics, stage5_scores, stage6_trends, feature_deviation_rows, customer_scores, subjective_objective_alignment)  # builds the Stage 7 plain-language report

with open(csv_dir / "stage1_comparison_qc_summary.csv", "w", newline="", encoding="utf-8") as file:  # opens the Stage 1 comparison QC CSV
    writer = csv.DictWriter(file, fieldnames=qc_rows[0].keys())                              # creates a CSV writer using QC fields
    writer.writeheader()                                                                    # writes the QC CSV header
    writer.writerows(qc_rows)                                                               # writes all QC rows

with open(csv_dir / "stage3_comparison_feature_vectors.csv", "w", newline="", encoding="utf-8") as file:  # opens the Stage 3 feature CSV
    writer = csv.DictWriter(file, fieldnames=feature_rows[0].keys())                        # creates a CSV writer using feature fields
    writer.writeheader()                                                                    # writes the feature CSV header
    writer.writerows(feature_rows)                                                          # writes all feature rows

with open(csv_dir / "stage4_baseline_pattern_comparison.csv", "w", newline="", encoding="utf-8") as file:  # opens the Stage 4 feature-deviation CSV
    writer = csv.DictWriter(file, fieldnames=feature_deviation_rows[0].keys())              # creates a CSV writer using feature-deviation fields
    writer.writeheader()                                                                    # writes the feature-deviation CSV header
    writer.writerows(feature_deviation_rows)                                                # writes all feature-deviation rows

with open(csv_dir / "stage5_bsi_framework_pattern_scores.csv", "w", newline="", encoding="utf-8") as file:  # opens the Stage 5 scores CSV
    writer = csv.DictWriter(file, fieldnames=stage5_scores.keys())                          # creates a CSV writer using Stage 5 score keys
    writer.writeheader()                                                                    # writes the Stage 5 CSV header
    writer.writerow(stage5_scores)                                                          # writes the Stage 5 score row

with open(csv_dir / "stage6_longitudinal_pattern_tracking.csv", "w", newline="", encoding="utf-8") as file:  # opens the Stage 6 trend CSV
    writer = csv.DictWriter(file, fieldnames=stage6_trends.keys())                          # creates a CSV writer using Stage 6 trend keys
    writer.writeheader()                                                                    # writes the Stage 6 CSV header
    writer.writerow(stage6_trends)                                                          # writes the Stage 6 trend row

with open(json_dir / "stage4_baseline_pattern_comparison.json", "w", encoding="utf-8") as file:  # opens the Stage 4 JSON output
    json.dump({"feature_deviations": feature_deviation_rows, "stage4_metrics": stage4_metrics}, file, indent=2)  # writes Stage 4 JSON

with open(json_dir / "stage5_bsi_framework_pattern_scores.json", "w", encoding="utf-8") as file:  # opens the Stage 5 JSON output
    json.dump(stage5_scores, file, indent=2)                                                # writes Stage 5 JSON

with open(json_dir / "stage6_longitudinal_pattern_tracking.json", "w", encoding="utf-8") as file:  # opens the Stage 6 JSON output
    json.dump({"trend_summary": stage6_trends, "history": longitudinal_history}, file, indent=2)  # writes Stage 6 JSON

with open(json_dir / "stage7_bsi_pattern_payload.json", "w", encoding="utf-8") as file:     # opens the Stage 7 payload JSON output
    json.dump(stage7_payload, file, indent=2)                                               # writes Stage 7 payload JSON

with open(json_dir / "stage7_plain_language_report.json", "w", encoding="utf-8") as file:  # opens the Stage 7 plain-language report JSON output
    json.dump(plain_language_report, file, indent=2)                                        # writes Stage 7 plain-language report JSON

with open(csv_dir / "stage7_bsi_pattern_payload.csv", "w", newline="", encoding="utf-8") as file:  # opens a compact Stage 7 payload CSV
    row = {                                                                                 # creates a compact dashboard row
        "session_id": session_id,                                                           # stores the session ID
        "rdi_score_0_100": stage4_metrics["rdi_score_0_100"],                               # stores RDI score
        "overall_voice_pattern_shift": stage5_scores["overall_voice_pattern_shift"],        # stores overall score
        "regulation_stability_score": customer_scores["regulation_stability"]["score"],     # stores the customer score where higher means closer to baseline
        "adaptive_capacity_score": customer_scores["adaptive_capacity"]["score"],            # stores the customer adaptive-capacity score
        "recovery_consistency_score": customer_scores["recovery_consistency"]["score"],      # stores the customer recovery-consistency score
        "voice_pattern_stability_score": customer_scores["voice_pattern_stability"]["score"],  # stores the customer voice-pattern stability score
        "voice_pattern_stability_label": customer_scores["voice_pattern_stability"]["label"],  # stores the customer voice-pattern stability label
        "voice_pattern_source_shift_score": customer_scores["voice_pattern_stability"]["source_shift_score"],  # stores the underlying overall voice-pattern shift
        "baseline_alignment_label": customer_scores["baseline_alignment"]["label"],          # stores the tighter RDI-based baseline-alignment label
        "display_label": customer_scores["baseline_alignment"]["label"],                    # stores the primary display label
        "display_color": customer_scores["baseline_alignment"]["source_shift_color"],       # stores the primary display color from RDI severity
        "comparison_session_count": stage6_trends["comparison_session_count"],              # stores comparison-session count
        "wellness_anchor_score_0_100": subjective_objective_alignment["current_session_wellness_anchor"]["score_0_100"],  # stores current pre-recording self-report score
        "baseline_wellness_anchor_score_0_100": subjective_objective_alignment["baseline_wellness_anchor"].get("score_0_100"),  # stores baseline self-report average
        "subjective_change_from_baseline": subjective_objective_alignment["comparison_metrics"]["subjective_change_from_baseline"],  # stores current self-report minus baseline self-report
        "objective_rdi_change_from_baseline": subjective_objective_alignment["comparison_metrics"]["objective_rdi_change_from_baseline"],  # stores current RDI minus baseline RDI
        "descriptive_index_gap": subjective_objective_alignment["comparison_metrics"]["descriptive_index_gap"],  # stores descriptive raw display gap only
        "wellness_congruence_code": subjective_objective_alignment["alignment_type"]["code"],  # stores the alignment classification code
        "wellness_congruence_label": subjective_objective_alignment["alignment_type"]["label"],  # stores the alignment classification label
        "comparison_strength": subjective_objective_alignment["comparison_strength"]["level"],  # stores comparison maturity without numeric confidence
        "score_direction": "customer strength scores: higher = closer to baseline; shift scores: lower = closer to baseline",  # stores score direction
    }                                                                                       # closes compact dashboard row
    writer = csv.DictWriter(file, fieldnames=row.keys())                                    # creates the Stage 7 compact CSV writer
    writer.writeheader()                                                                    # writes the Stage 7 compact CSV header
    writer.writerow(row)                                                                    # writes the Stage 7 compact CSV row

print("\n------------------------------------------------------------")                      # prints a visual divider before the final message
print("BioVoice BSI-pattern comparison complete.")                                          # confirms that the comparison run finished
print("Results describe baseline-relative voice-pattern shifts only.")                      # prints the neutral interpretation boundary
print(f"Stage 4 comparison saved to: {json_dir / 'stage4_baseline_pattern_comparison.json'}")  # prints the Stage 4 JSON path
print(f"Stage 5 scores saved to: {json_dir / 'stage5_bsi_framework_pattern_scores.json'}")  # prints the Stage 5 JSON path
print(f"Stage 7 payload saved to: {json_dir / 'stage7_bsi_pattern_payload.json'}")          # prints the Stage 7 JSON path
print(f"Plain-language report saved to: {json_dir / 'stage7_plain_language_report.json'}")  # prints the Stage 7 report JSON path
