<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wellbeing_map_v1 — versioned crosswalk.
 *
 * Adapters declared here even when disabled so the brief schema stays stable.
 */
class BMF_Wellbeing_Map {

	public const VERSION = 'v1';

	public static function definition(): array {
		$map = [
			'version' => self::VERSION,
			'domains' => [
				'core_regulation'      => [ 'label' => 'Core regulation', 'bsi_slot' => 'F1' ],
				'performance_load'     => [ 'label' => 'Performance load', 'bsi_slot' => null ],
				'sleep_recovery'       => [ 'label' => 'Sleep & recovery', 'bsi_slot' => 'F6' ],
				'fuel'                 => [ 'label' => 'Fuel (fluid & food)', 'bsi_slot' => 'F2' ],
				'movement'             => [ 'label' => 'Movement', 'bsi_slot' => 'F6' ],
				'mind_emotion'         => [ 'label' => 'Mind & emotion', 'bsi_slot' => 'F5' ],
				'environment_breath'   => [ 'label' => 'Environment & breath', 'bsi_slot' => 'F7' ],
				'connection_meaning'   => [ 'label' => 'Connection & meaning', 'bsi_slot' => 'F8' ],
				'occupational_demand'  => [ 'label' => 'Occupational demand', 'bsi_slot' => 'F8' ],
				'resources'            => [ 'label' => 'Resources (financial / social)', 'bsi_slot' => null ],
				'digestive'            => [ 'label' => 'Digestive', 'bsi_slot' => 'F4' ],
				'inflammatory'         => [ 'label' => 'Inflammatory', 'bsi_slot' => 'F3' ],
				'adaptive_capacity'    => [ 'label' => 'Adaptive capacity', 'bsi_slot' => 'F9' ],
			],
			'sources' => [
				'rsi' => [
					'enabled'           => true,
					'label'             => 'RSI',
					'clock'             => 'pulse',
					'recommended_days'  => 7,
					'aging_days'        => 14,
					'stale_days'        => 21,
					'direction'         => 'low_better',
					'scale'             => 100,
					'assessment_key'    => 'rsi',
				],
				'pillars' => [
					'enabled'           => true,
					'label'             => '8 Pillars',
					'clock'             => 'cycle',
					'recommended_days'  => 90,
					'aging_days'        => 100,
					'stale_days'        => 120,
					'direction'         => 'high_better',
					'scale'             => 100,
					'assessment_key'    => 'pillars',
				],
				'keys' => [
					'enabled'           => true,
					'label'             => 'Key Essentials',
					'clock'             => 'state',
					'recommended_days'  => 14,
					'aging_days'        => 21,
					'stale_days'        => 28,
					'direction'         => 'high_better',
					'scale'             => 5,
					'assessment_key'    => 'keys',
				],
				'bsi'      => [ 'enabled' => false, 'label' => 'BSI', 'clock' => 'cycle', 'recommended_days' => 90, 'aging_days' => 100, 'stale_days' => 120, 'direction' => 'low_better', 'scale' => 100, 'assessment_key' => 'bsi' ],
				'biovoice' => [ 'enabled' => false, 'label' => 'BioVoicePrint', 'clock' => 'state', 'recommended_days' => 30, 'aging_days' => 45, 'stale_days' => 60, 'direction' => 'low_better', 'scale' => 100 ],
				'fitbit'   => [ 'enabled' => false, 'label' => 'Fitbit', 'clock' => 'state', 'recommended_days' => 1, 'aging_days' => 3, 'stale_days' => 7, 'direction' => 'context' ],
				'whoop'    => [ 'enabled' => false, 'label' => 'Whoop', 'clock' => 'state', 'reserved' => true ],
				'oura'     => [ 'enabled' => false, 'label' => 'Oura', 'clock' => 'state', 'reserved' => true ],
				'apple'    => [ 'enabled' => false, 'label' => 'Apple Health', 'clock' => 'state', 'reserved' => true ],
			],
			'metrics' => [
				[ 'source' => 'rsi', 'code' => 'R11', 'label' => 'Core', 'domains' => [ 'core_regulation' ], 'role' => 'output' ],
				[ 'source' => 'rsi', 'code' => 'R12', 'label' => 'Performance', 'domains' => [ 'performance_load' ], 'role' => 'output' ],
				[ 'source' => 'pillars', 'code' => 'physical', 'label' => 'Physical', 'domains' => [ 'sleep_recovery', 'movement' ], 'role' => 'context' ],
				[ 'source' => 'pillars', 'code' => 'mental', 'label' => 'Mental', 'domains' => [ 'mind_emotion' ], 'role' => 'context' ],
				[ 'source' => 'pillars', 'code' => 'emotional', 'label' => 'Emotional', 'domains' => [ 'mind_emotion' ], 'role' => 'context' ],
				[ 'source' => 'pillars', 'code' => 'financial', 'label' => 'Financial', 'domains' => [ 'resources' ], 'role' => 'context' ],
				[ 'source' => 'pillars', 'code' => 'occupational', 'label' => 'Occupational', 'domains' => [ 'occupational_demand' ], 'role' => 'context' ],
				[ 'source' => 'pillars', 'code' => 'environmental', 'label' => 'Environmental', 'domains' => [ 'environment_breath' ], 'role' => 'context' ],
				[ 'source' => 'pillars', 'code' => 'spiritual', 'label' => 'Spiritual', 'domains' => [ 'connection_meaning' ], 'role' => 'context' ],
				[ 'source' => 'pillars', 'code' => 'social', 'label' => 'Social', 'domains' => [ 'resources', 'connection_meaning' ], 'role' => 'context' ],
				[ 'source' => 'keys', 'code' => 'key_fluid_form', 'label' => 'Fluid & Hydration', 'domains' => [ 'fuel' ], 'role' => 'input' ],
				[ 'source' => 'keys', 'code' => 'key_food_form', 'label' => 'Food & Nutrition', 'domains' => [ 'fuel', 'digestive' ], 'role' => 'input' ],
				[ 'source' => 'keys', 'code' => 'key_breath_form', 'label' => 'Breath & Environment', 'domains' => [ 'environment_breath' ], 'role' => 'input' ],
				[ 'source' => 'keys', 'code' => 'key_movement_form', 'label' => 'Movement', 'domains' => [ 'movement' ], 'role' => 'input' ],
				[ 'source' => 'keys', 'code' => 'key_mind_form', 'label' => 'Mind Balance', 'domains' => [ 'mind_emotion' ], 'role' => 'input' ],
				[ 'source' => 'keys', 'code' => 'key_sleep_form', 'label' => 'Sleep & Recovery', 'domains' => [ 'sleep_recovery' ], 'role' => 'input' ],
				[ 'source' => 'keys', 'code' => 'key_nature_form', 'label' => 'Nature & Connection', 'domains' => [ 'connection_meaning', 'environment_breath' ], 'role' => 'input' ],
			],
			'bsi_forms' => [
				'F1' => [ 'label' => 'Biological', 'title' => 'Biological Strain', 'composite' => 'outcomes' ],
				'F2' => [ 'label' => 'Metabolic', 'title' => 'Metabolic Flexibility', 'composite' => 'mediators' ],
				'F3' => [ 'label' => 'Inflammatory', 'title' => 'Inflammatory Load', 'composite' => 'mediators' ],
				'F4' => [ 'label' => 'Digestive', 'title' => 'Digestion & Assimilation Efficiency', 'composite' => 'drivers' ],
				'F5' => [ 'label' => 'Neuro-emotional', 'title' => 'Neural-Emotional Load', 'composite' => 'mediators' ],
				'F6' => [ 'label' => 'Recovery', 'title' => 'Recovery & Resilience Capacity', 'composite' => 'outcomes' ],
				'F7' => [ 'label' => 'Environmental', 'title' => 'Environmental Load', 'composite' => 'drivers' ],
				'F8' => [ 'label' => 'Lifecycle', 'title' => 'Lifestyle Alignment', 'alias' => 'Lifestyle Alignment', 'composite' => 'drivers' ],
				'F9' => [ 'label' => 'Adaptive Capacity', 'title' => 'Adaptive Capacity', 'composite' => 'outcomes' ],
			],
		];
		return apply_filters( 'bmf_wellbeing_map', $map );
	}

	public static function source( string $key ): array {
		$def = self::definition();
		return $def['sources'][ $key ] ?? [];
	}

	public static function metrics_for( string $source ): array {
		$out = [];
		foreach ( self::definition()['metrics'] as $m ) {
			if ( ( $m['source'] ?? '' ) === $source ) {
				$out[] = $m;
			}
		}
		return $out;
	}
}
