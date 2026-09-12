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
			'bsi'      => self::disabled_source( 'bsi' ),
			'biovoice' => self::disabled_source( 'biovoice' ),
			'fitbit'   => self::disabled_source( 'fitbit' ),
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
		];
	}

	private static function collect_highlights( int $user_id, array $sources ): array {
		if ( ! class_exists( 'BMF_QA_Extremes_Shortcodes' ) || $user_id <= 0 ) {
			return [];
		}
		$out = [];
		foreach ( [ 'rsi', 'pillars', 'keys' ] as $key ) {
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
					'provider'=> 'RSI R11 (Core) ≥50. Entry proxy for F1/F6/F9 neighborhood until BSI is in the file.',
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

		return $patterns;
	}
}
