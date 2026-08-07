import csv                                                                                  # lets Python save table-style outputs such as CSV summaries
import hashlib                                                                              # lets Python create a de-identified participant token
import json                                                                                 # lets Python save structured baseline outputs in JSON format
import subprocess                                                                           # lets Python call ffmpeg for audio conversion
from datetime import datetime                                                               # lets Python timestamp each baseline build
from pathlib import Path                                                                    # makes folder and file paths safer to work with

import numpy as np                                                                          # provides array math, averages, standard deviations, and signal summaries
import parselmouth                                                                          # provides Praat-based voice measures such as local jitter
import soundfile as sf                                                                      # reads standardized WAV files into Python
from scipy.signal import butter, filtfilt, hilbert                                          # provides filtering and analytic-signal tools


print("Processing BioVoice baseline build...")                                              # prints a progress message when the baseline script begins

# ------------------------------------------------------------------
# USER CONFIGURATION
# ------------------------------------------------------------------

participant_id = "Frank"                                                                       # sets the participant folder name used for this baseline run
device_type = "MacBook Microphone"                                                          # records the microphone/device used for the baseline recordings

# These are the neutral baseline recording folders used only to build
# the first personal voice reference. Add or replace folders here as needed.
baseline_session_folders = [                                                                # lists the neutral recording folders used to build the early personal baseline
    Path("data/raw/Frank Session 1"),                                                          # points to the first neutral baseline session folder
    Path("data/raw/Frank Session 2"),                                                          # points to the second neutral baseline session folder
    Path("data/raw/Frank Session 3"),                                                          # points to the third neutral baseline session folder
    Path("data/raw/Frank Session 4"),                                                       # leaves space for an optional fourth baseline session folder
    Path("data/raw/Frank Session 5"),                                                       # leaves space for an optional fifth baseline session folder
    Path("data/raw/Frank Session 6"),                                                       # leaves space for an optional sixth baseline session folder
    Path("data/raw/Frank Session 7"),                                                       # leaves space for an optional seventh baseline session folder
    Path("data/raw/Frank Session 8"),                                                       # leaves space for an optional eighth baseline session folder
]                                                                                           # closes the baseline-session folder list

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

# Same domain-weight structure currently used by the BSI/RSI comparison layer.
# The baseline builder stores these for future compatibility but does not use
# them to score or compare the baseline sessions against themselves.
future_comparison_domain_weights = {                                                        # stores future comparison weights without applying them during baseline creation
    "variability_consistency": 0.20,                                                        # reserves the future weight for variability consistency
    "harmonic_structure_consistency": 0.15,                                                 # reserves the future weight for harmonic-structure consistency
    "transition_continuity": 0.15,                                                          # reserves the future weight for continuity across vocal transitions
    "micro_modulation_consistency": 0.15,                                                   # reserves the future weight for fine-grain modulation consistency
    "signal_clarity": 0.10,                                                                 # reserves the future weight for signal clarity
    "distribution_balance": 0.10,                                                           # reserves the future weight for balanced feature distribution
    "sustained_phonation_consistency": 0.15,                                                # reserves the future weight for sustained-phonation consistency
}                                                                                           # closes the future comparison-weight dictionary

results_dir = Path("results") / participant_id / "BioVoice baseline"                        # defines the participant-specific baseline results folder
csv_dir = results_dir / "CSV files"                                                         # defines the subfolder for flat CSV outputs
json_dir = results_dir / "JSON files"                                                       # defines the subfolder for structured JSON outputs
wav_dir = results_dir / "WAV files"                                                         # defines the subfolder for converted WAV recordings
for folder in (csv_dir, json_dir, wav_dir):                                                 # loops through every required output folder
    folder.mkdir(parents=True, exist_ok=True)                                               # creates the folder and any missing parent folders

tokenized_id = hashlib.sha256(participant_id.encode()).hexdigest()[:12]                     # creates a short de-identified participant token


# ------------------------------------------------------------------
# SAVED BASELINE HISTORY HELPERS
# ------------------------------------------------------------------

def load_existing_csv_rows(csv_path):                                                       # defines a helper that loads previously saved CSV rows when they exist
    if not csv_path.exists():                                                               # checks whether the requested CSV has been created yet
        return []                                                                           # returns an empty list when no saved rows exist yet
    with open(csv_path, "r", newline="", encoding="utf-8") as file:                         # opens the existing CSV file for reading
        return list(csv.DictReader(file))                                                   # returns every saved CSV row as a dictionary


def to_float(value, default=0.0):                                                           # defines a helper that safely converts saved CSV text back into numbers
    try:                                                                                    # starts a protected conversion block
        return float(value)                                                                 # returns the numeric value when conversion succeeds
    except (TypeError, ValueError):                                                         # catches blank or invalid values
        return float(default)                                                               # returns the default numeric value when conversion fails


def to_int(value, default=0):                                                               # defines a helper that safely converts saved CSV text back into integers
    try:                                                                                    # starts a protected conversion block
        return int(float(value))                                                            # returns the integer value even when the CSV stored it as text
    except (TypeError, ValueError):                                                         # catches blank or invalid values
        return int(default)                                                                 # returns the default integer value when conversion fails


def write_csv_rows(csv_path, rows):                                                        # defines a helper that writes a CSV only when rows are available
    if not rows:                                                                            # checks whether there is anything to write
        return                                                                              # skips writing when the row list is empty
    fieldnames = sorted({key for row in rows for key in row.keys()})                        # builds a complete column list even when older rows have fewer fields
    with open(csv_path, "w", newline="", encoding="utf-8") as file:                         # opens the CSV path for overwrite output
        writer = csv.DictWriter(file, fieldnames=fieldnames)                                # creates a CSV writer using all observed columns
        writer.writeheader()                                                                # writes the header row
        writer.writerows(rows)                                                              # writes every saved and newly processed row


# ------------------------------------------------------------------
# PRE-RECORDING CONTEXT ASSESSMENT
# ------------------------------------------------------------------


def ask_integer_response(question, scale_text):                                             # defines a helper that safely collects one 1-5 response
    while True:                                                                             # keeps asking until the user enters a valid response
        raw_value = input(f"{question}\nScale: {scale_text}\nEnter 1-5: ").strip()          # prints the question and scale anchors, then reads the answer
        if raw_value in {"1", "2", "3", "4", "5"}:                                          # checks whether the answer is one of the allowed values
            return int(raw_value)                                                           # returns the valid answer as an integer
        print("Please enter a whole number from 1 to 5.")                                   # tells the user how to correct an invalid answer


def collect_pre_recording_assessment(session_id, session_processed_timestamp):              # defines a helper that collects all five context responses for one session
    print("\n------------------------------------------------------------")                 # prints a visual divider before the questionnaire
    print(f"Pre-recording context questions for {session_id}")                              # prints which session the answers belong to
    print("These answers provide context only and do not score the audio.")                 # explains that the answers are contextual, not scoring inputs
    print("------------------------------------------------------------")                   # prints a closing visual divider for the heading
    responses = {                                                                           # starts the response record with session tracking metadata
        "session_id": session_id,                                                           # stores the matching session folder name
        "session_processed_timestamp": session_processed_timestamp,                         # stores when this session began processing
    }                                                                                       # closes the response-record metadata dictionary
    for field_name, prompt in assessment_questions.items():                                 # loops through the five questionnaire items
        responses[field_name] = ask_integer_response(prompt["question"], prompt["scale"])   # stores the validated 1-5 answer for the current question
        print()                                                                             # adds a blank line so the terminal questionnaire is easier to read
    return add_wellness_anchor_scores(responses)                                            # returns the completed response record with its wellness-anchor score


def add_wellness_anchor_scores(response_row):                                               # adds total and 0-100 wellness-anchor scores to one questionnaire row
    total_score = sum(to_int(response_row.get(field), 0) for field in assessment_questions) # totals the five 1-to-5 answers for the session
    max_score = len(assessment_questions) * 5                                               # calculates the maximum possible score across the five questions
    response_row["wellness_anchor_total_score"] = total_score                               # stores the raw total out of 25
    response_row["wellness_anchor_score_0_100"] = round((total_score / max_score) * 100.0, 6) if max_score else 0.0  # stores the normalized score
    response_row["wellness_anchor_band"] = wellness_anchor_band(response_row["wellness_anchor_score_0_100"])  # stores a simple subjective wellness band
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


def print_session_stage_summary(session_number):                                            # defines the clean terminal stage summary shown for each session
    print(f"Processing Session {session_number}...")                                       # announces which baseline session is beginning
    print("Stage 1: Controlled Voice Capture and Quality Review")                           # names the first processing stage
    print("         Converts recordings and checks basic capture quality.")                  # explains what Stage 1 does in plain language
    print("Stage 2: Signal Conditioning and Normalization")                                 # names the second processing stage
    print("         Prepares each signal consistently before feature extraction.")           # explains what Stage 2 does in plain language
    print("Stage 3: Acoustic Feature Extraction")                                           # names the third processing stage
    print("         Measures observable voice-signal features for the baseline reference.")  # explains what Stage 3 does in plain language


# ------------------------------------------------------------------
# FILE DISCOVERY
# ------------------------------------------------------------------

task_keywords = {                                                                           # defines the filename clues used to find the six required recordings in each session folder
    "step1_silence_pre": ["step 1", "silent capture"],                                      # identifies the pre-recording silence file
    "step2_sustained_phonation": ["step 2", "sustained phonation"],                         # identifies the sustained-vowel file
    "step3a_counting_natural": ["step 3a", "rhythmic counting"],                            # identifies the natural counting file
    "step3b_counting_slow": ["step 3b", "slower controlled"],                               # identifies the slower controlled counting file
    "step4_reading": ["step 4", "standardized reading"],                                    # identifies the standardized reading file
    "step5_silence_post": ["step 5", "post"],                                               # identifies the post-recording silence file
}                                                                                           # closes the filename-keyword dictionary


def discover_session_files(session_folder):                                                 # defines a helper that locates the six required recordings in one session folder
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
    y = y - np.mean(y)                                                                      # removes the DC offset so periodic structure is easier to measure
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
    bandwidth = (                                                                           # begins the spectral-bandwidth calculation
        float(np.sqrt(np.sum(((freqs - centroid) ** 2) * power) / power_sum))               # calculates power-weighted spread around the centroid
        if power_sum > 0                                                                    # uses the formula only when power exists
        else 0.0                                                                            # returns zero when the spectrum contains no power
    )                                                                                       # closes the spectral-bandwidth calculation
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


# ==========================================================  # visual divider for the first baseline-processing stage
# STAGE 1: CONTROLLED VOICE CAPTURE AND QUALITY REVIEW       # Stage 1 converts recordings and checks basic capture quality
# ==========================================================  # closes the Stage 1 visual header


def convert_to_wav(input_file, output_file):                                                # defines a helper that converts one raw recording into a consistent WAV file
    subprocess.run(                                                                         # runs ffmpeg from Python so each source file is standardized before analysis
        ["ffmpeg", "-y", "-i", str(input_file), "-ac", "1", "-ar", "44100", "-sample_fmt", "s16", str(output_file)],  # converts to mono, 44.1 kHz, 16-bit PCM WAV
        check=True,                                                                         # raises an error if ffmpeg fails so bad files do not silently continue
        stdout=subprocess.DEVNULL,                                                          # hides routine ffmpeg output so the terminal remains readable
        stderr=subprocess.DEVNULL,                                                          # hides routine ffmpeg progress text so the terminal remains readable
    )                                                                                       # closes the ffmpeg command


def run_stage1_capture_and_qc(session_folder, baseline_run_timestamp, session_processed_timestamp):  # defines Stage 1 for one baseline session folder
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
                "baseline_run_timestamp": baseline_run_timestamp,                           # stores when the overall baseline run started
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
            "baseline_run_timestamp": baseline_run_timestamp,                               # carries forward when the overall baseline run started
            "session_processed_timestamp": session_processed_timestamp,                      # carries forward when this specific session began processing
        }                                                                                   # closes the raw-signal payload
    return qc_rows, raw_signals                                                             # returns Stage 1 quality rows and raw waveforms


# ==========================================================  # visual divider for the second baseline-processing stage
# STAGE 2: SIGNAL CONDITIONING AND NORMALIZATION             # Stage 2 prepares signals consistently before feature extraction
# ==========================================================  # closes the Stage 2 visual header


def run_stage2_conditioning(session_id, raw_signals):                                       # defines Stage 2 for one session's raw signals
    conditioned_signals = {}                                                                # starts an empty dictionary that will hold cleaned waveforms
    for task_key, payload in raw_signals.items():                                           # loops through each raw waveform from Stage 1
        centered = remove_dc_offset(payload["y"])                                           # removes DC offset by centering the waveform around zero
        filtered = butter_bandpass_filter(centered, payload["sr"])                          # removes very low and very high frequency content outside the working voice band
        normalized = normalize_signal(filtered)                                             # scales the filtered waveform to a consistent peak level
        conditioned_signals[task_key] = {                                                   # stores the Stage 2 output for this task
            "y": normalized,                                                                # stores the conditioned waveform samples
            "sr": payload["sr"],                                                            # carries forward the sample rate
            "duration": payload["duration"],                                                # carries forward the original recording duration
            "baseline_run_timestamp": payload["baseline_run_timestamp"],                    # carries forward when the overall baseline run started
            "session_processed_timestamp": payload["session_processed_timestamp"],           # carries forward when this specific session began processing
        }                                                                                   # closes the conditioned-signal dictionary for this task
    return conditioned_signals                                                              # returns the fully conditioned session signals


# ==========================================================  # visual divider for the third baseline-processing stage
# STAGE 3: ACOUSTIC FEATURE EXTRACTION                       # Stage 3 measures observable voice-signal features
# ==========================================================  # closes the Stage 3 visual header


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
                "baseline_run_timestamp": payload["baseline_run_timestamp"],                # stores when the overall baseline run started
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


all_qc_rows = load_existing_csv_rows(csv_dir / "baseline_qc_summary.csv")                   # loads any previously saved Stage 1 quality rows so baseline collection can continue over time
all_feature_rows = load_existing_csv_rows(csv_dir / "baseline_feature_vectors.csv")          # loads any previously saved Stage 3 feature rows so new sessions can be added
assessment_rows = load_existing_csv_rows(csv_dir / "pre_recording_assessment.csv")           # loads any previously saved questionnaire rows so completed sessions are not re-asked
processed_session_ids = {row["session_id"] for row in assessment_rows if row.get("session_id")}  # identifies sessions that have already been processed and stored
baseline_run_timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")                       # stores when this full baseline-processing run started
new_sessions_processed = 0                                                                  # counts how many new baseline sessions are processed during this run

for session_number, session_folder in enumerate(baseline_session_folders, start=1):         # loops through each neutral session folder and gives it a readable session number
    if not session_folder.exists():                                                         # checks whether the expected session folder is present
        print(f"Skipping missing future baseline folder: {session_folder}")                 # skips future sessions that have not been uploaded yet
        continue                                                                            # moves to the next configured baseline folder
    session_id = session_folder.name                                                        # stores the current session folder name
    if session_id in processed_session_ids:                                                  # checks whether this session was already saved in a previous run
        print(f"Skipping already processed baseline session: {session_id}")                 # confirms that saved data is being reused instead of duplicated
        continue                                                                            # moves to the next configured baseline folder without re-asking questions
    session_processed_timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")              # stores when this specific session began processing
    print("\n------------------------------------------------------------")                  # prints a visual divider before the next session begins
    print_session_stage_summary(session_number)                                             # prints the session number plus the definitions of Stages 1-3
    print(f"Session processed timestamp: {session_processed_timestamp}")                     # prints the processing timestamp for this specific session
    assessment_rows.append(collect_pre_recording_assessment(session_id, session_processed_timestamp))  # collects the five pre-recording context answers before processing audio
    qc_rows, raw_signals = run_stage1_capture_and_qc(session_folder, baseline_run_timestamp, session_processed_timestamp)  # runs Stage 1 and receives quality rows plus raw waveforms
    conditioned_signals = run_stage2_conditioning(session_id, raw_signals)                  # runs Stage 2 and receives cleaned waveforms
    feature_rows = run_stage3_feature_extraction(session_id, conditioned_signals)           # runs Stage 3 and receives acoustic feature rows
    all_qc_rows.extend(qc_rows)                                                             # adds this session's Stage 1 quality rows to the total list
    all_feature_rows.extend(feature_rows)                                                   # adds this session's Stage 3 feature rows to the total list
    processed_session_ids.add(session_id)                                                    # marks this session as processed for the current run
    new_sessions_processed += 1                                                              # increases the count of newly processed sessions
    print(f"Session {session_number} complete.")                                           # confirms that all three stages finished for this session

if not all_feature_rows or not assessment_rows:                                             # checks whether any baseline session data is available at all
    raise FileNotFoundError("No baseline sessions are available yet. Add at least one session folder under data/raw and rerun this script.")  # stops cleanly when nothing can be stored

assessment_rows = [add_wellness_anchor_scores(row) for row in assessment_rows]              # backfills wellness-anchor scores for older saved questionnaire rows


# ==========================================================                                  # visual divider for the personal-baseline build section
# BASELINE REFERENCE BUILD                                                                  # names the non-comparison baseline-reference section
# ==========================================================                                  # closes the baseline-build visual header
# This section is intentionally not called Stage 4.                                          # clarifies that Stage 4 is reserved for future comparison work
# Stage 4 should begin only when a new recording is compared against this saved baseline.    # explains where later comparison logic belongs


voice_task_keys = {                                                                         # defines the voiced recordings used to build the acoustic baseline reference
    "step2_sustained_phonation",                                                            # includes the sustained-vowel recording
    "step3a_counting_natural",                                                              # includes the natural counting recording
    "step3b_counting_slow",                                                                 # includes the slower controlled counting recording
    "step4_reading",                                                                        # includes the standardized reading recording
}                                                                                           # closes the set of voiced baseline tasks

baseline_feature_names = [                                                                  # lists the acoustic features summarized into the baseline reference
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

feature_specific_task_sources = {                                                          # defines feature-specific task sources so each feature uses the recording where it is most valid
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


def feature_value_is_valid(row, feature_name):                                               # checks whether a feature row should contribute to a baseline summary
    if feature_name == "jitter_local":                                                       # applies jitter-specific reliability filtering
        return str(row.get("jitter_local_valid", "")).lower() == "true"                     # keeps only Praat jitter values marked valid
    if feature_name == "resp_sync_lag_sec":                                                  # applies respiratory timing reliability filtering
        return str(row.get("resp_sync_lag_valid", "")).lower() == "true"                    # keeps only measurable audio-envelope lag values
    if feature_name in {"f0_mean_hz", "hnr_db", "shimmer_local", "phonation_stability_exhalation"}:  # applies reliability filtering for zero-prone acoustic features
        return str(row.get(f"{feature_name}_scoring_status", "")).lower() in {"valid", "low_confidence"}  # keeps usable values and traceable low-confidence values
    return row.get(feature_name) not in ("", None)                                           # keeps non-empty values for all other features


def round_optional(value, digits=6):                                                        # rounds numeric values while preserving unavailable values
    return round(float(value), digits) if value not in (None, "") and np.isfinite(float(value)) else None


def clip_0_100(value):                                                                      # keeps shift scores inside the display range
    return float(np.clip(value, 0.0, 100.0))


def weighted_mean(values, weights):                                                         # calculates a weighted mean after removing unavailable values
    filtered_pairs = [
        (float(value), float(weight))
        for value, weight in zip(values, weights)
        if value not in (None, "") and np.isfinite(float(value)) and float(weight) > 0
    ]
    if not filtered_pairs:
        return 0.0
    values_array = np.asarray([pair[0] for pair in filtered_pairs], dtype=np.float64)
    weights_array = np.asarray([pair[1] for pair in filtered_pairs], dtype=np.float64)
    return float(np.sum(values_array * weights_array) / np.sum(weights_array)) if np.sum(weights_array) else 0.0


def sd_shift_score(abs_z_score):                                                            # converts absolute baseline-relative distance to a 0-100 shift score
    return clip_0_100(abs_z_score * 25.0)


def summarize_session_features(session_id, feature_rows):                                   # summarizes one baseline session across the same task sources used in comparison
    summary = {}
    session_rows = [row for row in feature_rows if row.get("session_id") == session_id]
    for feature_name in baseline_feature_names:
        selected_task_source = task_source_for_feature(feature_name)
        values = [
            to_float(row.get(feature_name))
            for row in session_rows
            if row.get("recording_key") in selected_task_source and feature_value_is_valid(row, feature_name)
        ]
        summary[feature_name] = float(np.mean(values)) if values else None
    return summary


def baseline_range_shift_score(current_value, baseline_min, baseline_mean, baseline_max, baseline_std):  # estimates movement outside the observed personal feature range
    baseline_range = max(float(baseline_max) - float(baseline_min), 0.0)
    fallback_width = max(abs(float(baseline_mean)) * 0.05, float(baseline_std), 1e-6)
    effective_width = baseline_range if baseline_range > 0 else fallback_width
    if float(baseline_min) <= float(current_value) <= float(baseline_max):
        return 0.0
    range_excess = min(abs(float(current_value) - float(baseline_min)), abs(float(current_value) - float(baseline_max)))
    return clip_0_100((range_excess / effective_width) * 25.0)


def compute_baseline_session_deviation_scores(session_summary, baseline_reference):          # computes baseline-session voice shifts using the final personal reference
    scores = {}
    baseline_features = baseline_reference
    for feature_name in baseline_feature_names:
        baseline_stats = baseline_features.get(feature_name, {})
        baseline_mean = baseline_stats.get("mean")
        baseline_std = baseline_stats.get("std")
        baseline_min = baseline_stats.get("min")
        baseline_max = baseline_stats.get("max")
        current_value = session_summary.get(feature_name)
        if None in (baseline_mean, baseline_std, baseline_min, baseline_max, current_value):
            scores[feature_name] = None
            continue
        z_score = (float(current_value) - float(baseline_mean)) / float(baseline_std) if float(baseline_std) > 0 else 0.0
        sd_score = sd_shift_score(abs(z_score))
        range_score = baseline_range_shift_score(current_value, baseline_min, baseline_mean, baseline_max, baseline_std)
        scores[feature_name] = max(sd_score, range_score)
    return scores


def compute_rdi_from_deviation_scores(deviation_scores):                                    # mirrors the comparison RDI composite for baseline distribution storage
    variability_index = weighted_mean(
        [deviation_scores["jitter_local"], deviation_scores["shimmer_local"], deviation_scores["envelope_variance"], deviation_scores["approximate_entropy"], deviation_scores["sample_entropy"]],
        [0.20, 0.20, 0.20, 0.20, 0.20],
    )
    harmonic_structure_shift = weighted_mean(
        [deviation_scores["hnr_db"], deviation_scores["spectral_centroid_hz"], deviation_scores["spectral_bandwidth_hz"], deviation_scores["f0_mean_hz"]],
        [0.35, 0.20, 0.20, 0.25],
    )
    transition_continuity_shift = weighted_mean(
        [deviation_scores["resp_sync_lag_sec"], deviation_scores["resp_amp_variance"], deviation_scores["phonation_stability_exhalation"]],
        [0.40, 0.25, 0.35],
    )
    micro_modulation_shift = weighted_mean(
        [deviation_scores["tremor_band_power_3_12hz"], deviation_scores["lyapunov_proxy"]],
        [0.65, 0.35],
    )
    signal_clarity_shift = weighted_mean(
        [deviation_scores["hnr_db"], deviation_scores["zero_crossing_rate"], deviation_scores["spectral_bandwidth_hz"]],
        [0.50, 0.25, 0.25],
    )
    distribution_balance_shift = weighted_mean(
        [deviation_scores["spectral_centroid_hz"], deviation_scores["spectral_bandwidth_hz"], deviation_scores["f0_mean_hz"]],
        [0.35, 0.35, 0.30],
    )
    return weighted_mean(
        [variability_index, harmonic_structure_shift, transition_continuity_shift, micro_modulation_shift, signal_clarity_shift, distribution_balance_shift, deviation_scores["phonation_stability_exhalation"]],
        [
            future_comparison_domain_weights["variability_consistency"],
            future_comparison_domain_weights["harmonic_structure_consistency"],
            future_comparison_domain_weights["transition_continuity"],
            future_comparison_domain_weights["micro_modulation_consistency"],
            future_comparison_domain_weights["signal_clarity"],
            future_comparison_domain_weights["distribution_balance"],
            future_comparison_domain_weights["sustained_phonation_consistency"],
        ],
    )


def build_baseline_biovoiceprint_reference(session_ids, feature_rows, baseline_reference):   # stores the person's baseline RDI distribution for future congruence comparisons
    session_rdi_rows = []
    for session_id in session_ids:
        session_summary = summarize_session_features(session_id, feature_rows)
        deviation_scores = compute_baseline_session_deviation_scores(session_summary, baseline_reference)
        rdi_score = compute_rdi_from_deviation_scores(deviation_scores)
        session_rdi_rows.append({"session_id": session_id, "rdi_score_0_100": round(rdi_score, 6)})
    rdi_scores = [row["rdi_score_0_100"] for row in session_rdi_rows]
    return {
        "mean_rdi_score_0_100": round(float(np.mean(rdi_scores)), 6) if rdi_scores else None,
        "rdi_standard_deviation": round(float(np.std(rdi_scores)), 6) if rdi_scores else None,
        "rdi_score_range": [round(float(np.min(rdi_scores)), 6), round(float(np.max(rdi_scores)), 6)] if rdi_scores else [],
        "n_sessions": len(rdi_scores),
        "session_rdi_scores": session_rdi_rows,
        "score_direction": "higher = greater baseline-relative voice-pattern shift",
        "interpretation_note": "This summarizes how much neutral baseline sessions vary around the personal BioVoicePrint reference. It supports future baseline-relative congruence comparison.",
    }


baseline_reference = {}                                                                     # starts the dictionary that will store acoustic baseline summaries
for feature_name in baseline_feature_names:                                                 # loops through every acoustic feature selected for baseline storage
    selected_task_source = task_source_for_feature(feature_name)                             # gets the task source used for this feature's baseline reference
    values = [                                                                              # starts the list of observed values for this feature
        to_float(row[feature_name])                                                         # takes the current feature value from one feature row and converts saved CSV text to a number
        for row in all_feature_rows                                                         # checks every extracted feature row across all baseline sessions
        if row["recording_key"] in selected_task_source                                     # keeps only the valid task source for this feature
        and feature_value_is_valid(row, feature_name)                                       # excludes invalid feature estimates such as failed jitter calculations
    ]                                                                                       # closes the collected feature-value list
    baseline_reference[feature_name] = {                                                    # stores the summary statistics for the current feature
        "task_source": sorted(selected_task_source),                                         # stores which recording tasks contributed to this feature's baseline
        "mean": round(float(np.mean(values)), 6) if values else None,                       # stores the average observed value or null when unavailable
        "std": round(float(np.std(values)), 6) if values else None,                         # stores the spread across baseline observations or null when unavailable
        "min": round(float(np.min(values)), 6) if values else None,                         # stores the lowest observed value or null when unavailable
        "max": round(float(np.max(values)), 6) if values else None,                         # stores the highest observed value or null when unavailable
        "n_observations": len(values),                                                      # stores how many voiced-task observations contributed
        "valid_for_scoring": len(values) > 0,                                                # records whether this feature can be used in future comparison scoring
    }                                                                                       # closes the current feature-summary dictionary

assessment_reference = {}                                                                   # starts the dictionary that will store questionnaire reference summaries
for field in assessment_questions:                                                          # loops through the five questionnaire fields
    values = [to_int(row[field]) for row in assessment_rows]                                # collects this question's numeric responses across baseline sessions
    assessment_reference[field] = {                                                         # stores summary information for the current questionnaire field
        "question": assessment_questions[field]["question"],                                # stores the exact client-facing question text
        "scale": assessment_questions[field]["scale"],                                      # stores the 1-to-5 anchor labels
        "mean_response": round(float(np.mean(values)), 6),                                  # stores the average response across sessions
        "mean_normalized_score_0_100": round(float(np.mean(values)) / 5.0 * 100.0, 6),      # stores the item mean on the same 0-100 descriptive scale as the total anchor
        "standard_deviation": round(float(np.std(values)), 6),                              # stores item-level response spread across baseline sessions
        "response_range": [int(np.min(values)), int(np.max(values))],                       # stores the lowest and highest observed response
        "normalized_score_range_0_100": [round(float(np.min(values)) / 5.0 * 100.0, 6), round(float(np.max(values)) / 5.0 * 100.0, 6)],  # stores item range on a 0-100 descriptive scale
        "n_sessions": len(values),                                                          # stores how many sessions contributed responses
    }                                                                                       # closes the current questionnaire-summary dictionary

wellness_anchor_scores = [to_float(row.get("wellness_anchor_score_0_100")) for row in assessment_rows]  # collects the total wellness-anchor score for each baseline session
wellness_anchor_reference = {                                                               # summarizes the person's baseline self-reported pre-recording state
    "baseline_wellness_anchor_score_0_100": round(float(np.mean(wellness_anchor_scores)), 6),  # stores the average baseline wellness anchor
    "baseline_wellness_anchor_band": wellness_anchor_band(float(np.mean(wellness_anchor_scores))),  # stores the average baseline wellness band
    "standard_deviation": round(float(np.std(wellness_anchor_scores)), 6),                  # stores baseline spread for future within-person standardized comparison
    "score_range": [round(float(np.min(wellness_anchor_scores)), 6), round(float(np.max(wellness_anchor_scores)), 6)],  # stores baseline self-report range
    "n_sessions": len(wellness_anchor_scores),                                              # stores how many baseline check-ins contributed
    "score_direction": "higher = stronger self-reported pre-recording wellness",            # stores the score direction
    "interpretation_note": "This is the person's self-reported baseline wellness anchor. It does not score or change the BioVoicePrint acoustic baseline.",  # explains the separation from voice scoring
}                                                                                           # closes the wellness-anchor summary

baseline_biovoiceprint_reference = build_baseline_biovoiceprint_reference(processed_session_ids, all_feature_rows, baseline_reference)  # summarizes baseline RDI variation for future comparison

run_timestamp = baseline_run_timestamp                                                      # reuses the timestamp captured when this full baseline-processing run began
available_baseline_session_count = len(processed_session_ids)                               # counts how many unique baseline sessions are currently stored
baseline_ready = available_baseline_session_count >= 3                                      # checks whether the minimum three sessions are available
baseline_status = "baseline collection in progress" if not baseline_ready else "early personal baseline reference" if available_baseline_session_count < 8 else "expanded personal baseline reference"  # labels the baseline maturity level
client_message = "Baseline collection is in progress. Three sessions are needed before this becomes an early personal baseline." if not baseline_ready else "Your early personal baseline has been created. Accuracy and personalization improve as more sessions are added."  # chooses the correct client-facing message

baseline_json_output = {                                                                    # opens the final structured JSON output object
    "baseline_summary": {                                                                   # opens the top-level summary section
        "timestamp": run_timestamp,                                                         # stores when this baseline build was created
        "baseline_run_timestamp": baseline_run_timestamp,                                   # stores when this full baseline-processing run began
        "session_processed_timestamps": [row["session_processed_timestamp"] for row in assessment_rows],  # stores when each session began processing
        "participant_id_tokenized": tokenized_id,                                           # stores the de-identified participant token
        "device_type": device_type,                                                         # stores the recording device label
        "baseline_session_count": available_baseline_session_count,                         # stores how many unique sessions are currently saved
        "minimum_sessions_needed": 3,                                                       # stores the minimum number of sessions required for an early baseline
        "baseline_ready_for_comparison": baseline_ready,                                    # states whether the saved reference is ready for the future comparison script
        "new_sessions_processed_this_run": new_sessions_processed,                          # stores how many new sessions were added during this script run
        "baseline_status": baseline_status,                                                 # stores the current baseline-maturity label
        "client_message": client_message,                                                   # stores the client-facing baseline message
        "baseline_growth_note": "Three sessions create an early working baseline; every additional uploaded session is included and recalculates the personal reference range.",  # explains why more sessions improve the reference
        "purpose": "Creates a personal acoustic reference from neutral baseline recordings only.",  # explains what this code is intended to do
        "interpretation_note": "No comparison, scoring, or internal-state inference is performed in this baseline builder.",  # clarifies what this code intentionally does not do
        "future_comparison_note": "Stored domain weights are reserved for a later comparison script and are not applied here.",  # explains why weights are stored but unused
        "pipeline_position": "Stages 1-3 plus baseline reference build. Stage 4 begins only in the future comparison script.",  # documents where this code stops in the larger pipeline
    },                                                                                      # closes the baseline-summary section
    "pre_recording_assessment": {                                                           # opens the questionnaire-output section
        "scale": "1 to 5",                                                                  # records the shared response scale
        "question_scale_note": "Each question includes its own 1-to-5 anchor labels so lower and higher responses are clear.",  # explains the question-specific anchors
        "responses_by_session": assessment_rows,                                            # stores every raw questionnaire response by session
        "reference_summary": assessment_reference,                                          # stores the questionnaire averages and ranges
        "wellness_anchor_reference": wellness_anchor_reference,                              # stores the averaged 0-100 baseline self-report anchor
    },                                                                                      # closes the questionnaire-output section
    "baseline_acoustic_reference": baseline_reference,                                      # stores the acoustic feature summaries that form the personal reference
    "baseline_biovoiceprint": baseline_biovoiceprint_reference,                             # stores baseline RDI distribution for future Wellness Congruence comparisons
    "future_comparison_domain_weights": future_comparison_domain_weights,                   # stores future-compatible weights without applying them here
    "language_guardrails": {                                                                # opens the non-diagnostic language section
        "foundational_disclaimer": "Voice regulation analysis evaluates acoustic and regulatory patterns within recorded speech and does not diagnose medical, psychological, or neurological conditions.",  # stores the core disclaimer
        "use_for": "Awareness, pattern recognition, and longitudinal comparison only.",     # states the intended use of the baseline output
    },                                                                                      # closes the language-guardrails section
}                                                                                           # closes the final JSON output object

write_csv_rows(csv_dir / "baseline_qc_summary.csv", all_qc_rows)                            # writes every saved and newly added Stage 1 quality-review row
write_csv_rows(csv_dir / "baseline_feature_vectors.csv", all_feature_rows)                  # writes every saved and newly added Stage 3 acoustic-feature row
write_csv_rows(csv_dir / "pre_recording_assessment.csv", assessment_rows)                   # writes every saved and newly added questionnaire-response row

with open(json_dir / "baseline_reference.json", "w", encoding="utf-8") as file:             # opens the final structured baseline JSON file for writing
    json.dump(baseline_json_output, file, indent=2)                                         # writes the baseline JSON with indentation for readability

print("\n------------------------------------------------------------")                      # prints a visual divider before the final completion message
print(f"New sessions processed during this run: {new_sessions_processed}")                  # prints how many new sessions were added during this run
print(f"Total stored baseline sessions: {available_baseline_session_count}")                # prints how many sessions are now saved for this participant
print("BioVoice baseline build complete.")                                                  # confirms that the baseline build finished
print(client_message)                                                                       # prints the correct client-facing baseline message for the current session count
print(f"Baseline reference saved to: {json_dir / 'baseline_reference.json'}")               # prints where the structured baseline JSON was saved
print(f"Baseline feature vectors saved to: {csv_dir / 'baseline_feature_vectors.csv'}")     # prints where the acoustic-feature CSV was saved
print(f"Pre-recording assessment saved to: {csv_dir / 'pre_recording_assessment.csv'}")     # prints where the questionnaire CSV was saved
