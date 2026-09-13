<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Wellbeing_Assembler {

	public static function fixture_path(): string {
		return BMF_WELLBEING_PATH . 'fixtures/sample_brief.json';
	}

	public static function load_fixture(): ?array {
		$path = self::fixture_path();
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$json = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $json ) ? $json : null;
	}

	public static function assemble( int $user_id, string $email, string $label, string $voice = 'member' ): array {
		$voice = in_array( $voice, [ 'member', 'provider' ], true ) ? $voice : 'member';
		$map   = BMF_Wellbeing_Map::definition();

		$sources = [
			'rsi'      => BMF_Wellbeing_Adapter_RSI::load( $user_id, $email ),
			'pillars'  => BMF_Wellbeing_Adapter_Pillars::load( $user_id, $email ),
			'keys'     => BMF_Wellbeing_Adapter_Keys::load( $user_id, $email ),
			'bsi'      => BMF_Wellbeing_Adapter_BSI::load( $user_id, $email ),
			'biovoice' => BMF_Wellbeing_Adapter_BioVoice::load( $user_id, $email ),
			'fitbit'   => BMF_Wellbeing_Adapter_Fitbit::load( $user_id, $email ),
			'profile'  => BMF_Wellbeing_Adapter_Profile::load( $user_id, $email ),
		];

		$highlights = self::collect_highlights( $user_id, $sources );
		$patterns   = self::detect_patterns( $sources );

		return [
			'map_version'  => $map['version'],
			'plugin'       => BMF_WELLBEING_VERSION,
			'generated_at' => gmdate( 'c' ),
			'is_fixture'   => false,
			'voice'        => $voice,
			'user'         => [
				'id'    => $user_id,
				'email' => $email,
				'label' => $label,
			],
			'sources'      => $sources,
			'highlights'   => $highlights,
			'patterns'     => $patterns,
			'disclaimer'   => 'Self-assessment snapshot only. Not a diagnosis or treatment plan.',
		];
	}

	private static function disabled_source( string $key ): array {
		$cfg = BMF_Wellbeing_Map::source( $key );
		return [
			'key'      => $key,
			'label'    => $cfg['label'] ?? $key,
			'enabled'  => false,
			'clock'    => $cfg['clock'] ?? '',
			'present'  => false,
			'status'   => 'reserved',
			'date'     => '',
			'age_days' => null,
			'scores'   => [],
			'history'  => [],
		];
	}

	private static function collect_highlights( int $user_id, array $sources ): array {
		$out = [];
		if ( class_exists( 'BMF_QA_Extremes_Shortcodes' ) && $user_id > 0 ) {
		foreach ( [ 'rsi', 'pillars', 'keys', 'bsi' ] as $key ) {
			$src = $sources[ $key ] ?? [];
			if ( empty( $src['present'] ) || empty( $src['date'] ) ) {
				continue;
			}
			$cfg = BMF_Wellbeing_Map::source( $key );
			$dir = $cfg['direction'] ?? 'low_better';
			try {
				$data = BMF_QA_Extremes_Shortcodes::build_extremes( $user_id, $key, $src['date'], $dir, 0.75 );
			} catch ( Throwable $e ) {
				continue;
			}
			$rows = $data['rows'] ?? [];
			$cap  = 8;
			$n    = 0;
			foreach ( $rows as $row ) {
				if ( $n >= $cap ) {
					break;
				}
				$out[] = [
					'source'       => $key,
					'form'         => $row['form'] ?? '',
					'section'      => $row['section'] ?? '',
					'prompt'       => $row['prompt'] ?? '',
					'answer_label' => $row['answer_label'] ?? '',
					'score'        => $row['score'] ?? null,
					'scale_max'    => $row['scale_max'] ?? null,
					'date'         => $src['date'],
				];
				$n++;
			}
		}
		}

		foreach ( $sources['biovoice']['markers'] ?? [] as $m ) {
			if ( empty( $m['label'] ) ) {
				continue;
			}
			$out[] = [
				'source'       => 'biovoice',
				'form'         => 'BioVoicePrint',
				'section'      => $m['band'] ?? '',
				'prompt'       => $m['label'],
				'answer_label' => $m['note'] ?? '',
				'score'        => $m['score'] ?? null,
				'scale_max'    => 100,
				'date'         => $sources['biovoice']['date'] ?? '',
			];
		}
		return $out;
	}

	private static function detect_patterns( array $sources ): array {
		$patterns = [];

		$rsi = $sources['rsi'] ?? [];
		foreach ( $rsi['scores'] ?? [] as $s ) {
			if ( ( $s['code'] ?? '' ) === 'R12' && isset( $s['value'] ) && (float) $s['value'] >= 50 ) {
				$patterns[] = [
					'code'    => 'rsi_performance_elevated',
					'severity'=> (float) $s['value'] >= 75 ? 'high' : 'watch',
					'title'   => 'Performance load is elevated',
					'member'  => 'Your latest RSI Performance score is in an elevated range. That is a pulse signal, not a diagnosis — worth pairing with sleep and work demand.',
					'provider'=> 'RSI R12 (Performance) ≥50. Pulse clock. Confirm against Keys movement/sleep and, when present, BSI F5/F3.',
					'evidence'=> [ 'rsi.R12' => $s['value'] ],
				];
			}
			if ( ( $s['code'] ?? '' ) === 'R11' && isset( $s['value'] ) && (float) $s['value'] >= 50 ) {
				$patterns[] = [
					'code'    => 'rsi_core_elevated',
					'severity'=> (float) $s['value'] >= 75 ? 'high' : 'watch',
					'title'   => 'Core regulation is elevated',
					'member'  => 'RSI Core is showing more strain than the lower bands. Recovery choices over the next two weeks matter more than adding load.',
					'provider'=> 'RSI R11 (Core) ≥50. Pulse proxy for F1/F6/F9. Prefer BSI Outcomes when a final BSI row exists.',
					'evidence'=> [ 'rsi.R11' => $s['value'] ],
				];
			}
		}

		$keys_by = [];
		foreach ( $sources['keys']['scores'] ?? [] as $s ) {
			$keys_by[ $s['code'] ] = $s;
		}
		if ( ! empty( $keys_by['key_sleep_form']['value'] ) && (float) $keys_by['key_sleep_form']['value'] <= 2.5 ) {
			$patterns[] = [
				'code'    => 'keys_sleep_low',
				'severity'=> 'watch',
				'title'   => 'Sleep & recovery choices are off',
				'member'  => 'Sleep & Recovery is one of the weaker Key Essentials right now. That is a daily-choice signal on a 14-day clock.',
				'provider'=> 'Keys sleep average ≤2.5/5. State clock. Ask schedule vs environment vs mind load before attributing to physiology.',
				'evidence'=> [ 'keys.key_sleep_form' => $keys_by['key_sleep_form']['value'] ],
			];
		}

		$pillars_by = [];
		foreach ( $sources['pillars']['scores'] ?? [] as $s ) {
			$pillars_by[ $s['code'] ] = $s;
		}
		$cmp = $sources['pillars']['comparison']['items'] ?? [];
		$mismatch = 0;
		foreach ( $cmp as $item ) {
			if ( isset( $item['diff'] ) && abs( (int) $item['diff'] ) >= 2 ) {
				$mismatch++;
			}
		}
		if ( $mismatch >= 2 ) {
			$patterns[] = [
				'code'    => 'pillars_awareness_gap',
				'severity'=> 'info',
				'title'   => 'Perceived vs scored pillar rank differs',
				'member'  => 'How you ranked your pillars and how they scored are not the same. That gap is useful — it often points at what you notice versus what is carrying load.',
				'provider'=> 'Two or more pillars differ by ≥2 rank positions (perceived vs scored). Treat as awareness gap, not inconsistency.',
				'evidence'=> [ 'mismatch_count' => $mismatch ],
			];
		}

		$move = $keys_by['key_movement_form']['value'] ?? null;
		$phys = $pillars_by['physical']['value'] ?? null;
		if ( $move !== null && $phys !== null && (float) $move <= 2.5 && (float) $phys >= 60 ) {
			$patterns[] = [
				'code'    => 'choice_vs_capacity_movement',
				'severity'=> 'info',
				'title'   => 'Movement looks like choice, not capacity',
				'member'  => 'Your physical pillar is holding up better than your recent movement choices. This is usually a schedule or habit gap, not a capacity gap.',
				'provider'=> 'Keys movement ≤2.5/5 while Pillars physical ≥60. Choice vs capacity pattern.',
				'evidence'=> [ 'keys.movement' => $move, 'pillars.physical' => $phys ],
			];
		}

		$bsi_by = [];
		foreach ( $sources['bsi']['scores'] ?? [] as $s ) {
			$bsi_by[ $s['code'] ] = $s;
		}
		foreach ( [ 'drivers' => 'Drivers', 'mediators' => 'Mediators', 'outcomes' => 'Outcomes' ] as $code => $label ) {
			$val = $bsi_by[ $code ]['value'] ?? null;
			if ( $val === null || (float) $val < 50 ) {
				continue;
			}
			$patterns[] = [
				'code'     => 'bsi_' . $code . '_elevated',
				'severity' => (float) $val >= 75 ? 'high' : 'watch',
				'title'    => 'BSI ' . $label . ' are elevated',
				'member'   => 'Your latest BSI ' . $label . ' composite is in an elevated range. That is a 90-day physiological cycle signal — pair it with sleep, mind, and daily choices rather than treating it as a diagnosis.',
				'provider' => 'BSI ' . $label . ' ≥50 on the current final cycle. Cross-check RSI pulse and Keys state before attributing cause.',
				'evidence' => [ 'bsi.' . $code => $val ],
			];
		}

		$bv   = $sources['biovoice'] ?? [];
		$rdi  = null;
		foreach ( $bv['scores'] ?? [] as $s ) {
			if ( ( $s['code'] ?? '' ) === 'rdi' && isset( $s['value'] ) ) {
				$rdi = (float) $s['value'];
			}
		}
		if ( $rdi !== null && $rdi >= 25 ) {
			$patterns[] = [
				'code'     => 'biovoice_shift',
				'severity' => $rdi >= 50 ? 'high' : 'watch',
				'title'    => 'Voice pattern is shifting from baseline',
				'member'   => 'BioVoicePrint RDI is ' . $rdi . '/100 versus your own baseline. That is a personal-shift signal — useful next to sleep, load, and recording context, not a diagnosis.',
				'provider' => 'BioVoice RDI ≥25 (stage7). State clock. Confirm device_mismatch and last take quality before pairing with BSI F5/F6.',
				'evidence' => [ 'biovoice.rdi' => $rdi ],
			];
		}
		if ( ! empty( $bv['progress']['device_mismatch'] ) ) {
			$patterns[] = [
				'code'     => 'biovoice_device_mismatch',
				'severity' => 'info',
				'title'    => 'BioVoice device or mic differs from baseline',
				'member'   => 'A later take used a different device or microphone than your baseline. Treat voice shifts with extra caution until you record on a similar setup.',
				'provider' => 'session_groups.device_mismatch is set. Do not over-read RDI until a matched-device take exists.',
				'evidence' => [ 'device_mismatch' => true ],
			];
		}

		$fb = $sources['fitbit'] ?? [];
		if ( ! empty( $fb['present'] ) && (int) ( $fb['short_nights'] ?? 0 ) >= 4 ) {
			$patterns[] = [
				'code'     => 'fitbit_short_sleep',
				'severity' => 'watch',
				'title'    => 'Recent nights are running short',
				'member'   => 'Fitbit shows several nights under 6 hours in the last week. That is a nightly state signal — pair it with Key Essentials sleep, not a quarterly type.',
				'provider' => (int) $fb['short_nights'] . ' of last 7 Fitbit nights < 6 hours. State clock. Do not let this veto a fresh BSI cycle; use it to confirm Keys sleep.',
				'evidence' => [ 'short_nights' => (int) $fb['short_nights'], 'nights_7d' => (int) ( $fb['nights_7d'] ?? 0 ) ],
			];
		}

		$pf    = $sources['profile']['facts'] ?? [];
		$care  = ! empty( $pf['care_load'] );
		$short = (int) ( $sources['fitbit']['short_nights'] ?? 0 );
		if ( $care && $short >= 3 ) {
			$patterns[] = [
				'code'     => 'profile_care_sleep',
				'severity' => 'info',
				'title'    => 'Care load sits next to short nights',
				'member'   => 'Your profile lists care responsibilities and recent nights have been shorter. That is often schedule, not a quarterly physiology signal.',
				'provider' => 'Profile care_load + Fitbit short nights ≥3. Ask schedule before attributing to BSI F6 or Keys sleep alone.',
				'evidence' => [ 'care_load' => true, 'short_nights' => $short ],
			];
		}

		$job = trim( (string) ( $pf['employment'] ?? '' ) );
		$r12 = null;
		foreach ( $sources['rsi']['scores'] ?? [] as $s ) {
			if ( ( $s['code'] ?? '' ) === 'R12' ) {
				$r12 = $s['value'] ?? null;
			}
		}
		if ( $job !== '' && $r12 !== null && (float) $r12 >= 50 ) {
			$patterns[] = [
				'code'     => 'profile_work_performance',
				'severity' => 'info',
				'title'    => 'Work demand is on file next to elevated Performance',
				'member'   => 'Your profile includes work context and RSI Performance is elevated. Demand may be doing as much as capacity here.',
				'provider' => 'Profile employment is set and RSI R12 ≥50. Occupational pillar / schedule before adding load.',
				'evidence' => [ 'employment' => $job, 'rsi.R12' => $r12 ],
			];
		}

		$food_low = false;
		foreach ( $sources['keys']['scores'] ?? [] as $s ) {
			if ( ( $s['code'] ?? '' ) === 'key_food_form' && isset( $s['value'] ) && (float) $s['value'] <= 2.5 ) {
				$food_low = true;
			}
		}
		if ( ! empty( $pf['food_sensitivity'] ) && $food_low ) {
			$patterns[] = [
				'code'     => 'profile_food_sensitivity',
				'severity' => 'info',
				'title'    => 'Food sensitivity is on file with a weak food score',
				'member'   => 'Your profile notes a food sensitivity and Key Essentials food is on the low side. That is a choice-and-constraint signal, not a lab result.',
				'provider' => 'uls_ULS_CF_BIO food sensitivity + Keys food ≤2.5/5. Read BSI F4 against that constraint.',
				'evidence' => [ 'food_sensitivity' => true ],
			];
		}

		$age = $pf['age'] ?? null;
		$out = null;
		foreach ( $sources['bsi']['scores'] ?? [] as $s ) {
			if ( ( $s['code'] ?? '' ) === 'outcomes' ) {
				$out = $s['value'] ?? null;
			}
		}
		if ( $age !== null && (int) $age >= 50 && $out !== null && (float) $out >= 50 ) {
			$patterns[] = [
				'code'     => 'profile_age_outcomes',
				'severity' => 'info',
				'title'    => 'Age band is part of the Outcomes reading',
				'member'   => 'BSI Outcomes are elevated and your age band is 50+. Recovery load is expected to show here — pair with sleep and daily choices rather than treating it as a single defect.',
				'provider' => 'Age ≥50 + BSI Outcomes ≥50. Use F8 / F6 / F9 as lifecycle context, not a standalone alarm.',
				'evidence' => [ 'age' => $age, 'bsi.outcomes' => $out ],
			];
		}

		return $patterns;
	}
}
