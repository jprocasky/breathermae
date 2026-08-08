<?php
/**
 * BioVoicePrint – Protocol definitions & seed data.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Protocol_Service {

	const SEED_VERSION = '1.0';

	/**
	 * Ensure schema is current and v1 protocol is seeded.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'bmf_biovoice_db_version', '' );
		if ( $installed !== BMF_BioVoice_Repository::DB_VERSION ) {
			BMF_BioVoice_Repository::install_tables();
		}
		self::seed_v1_if_needed();
		self::seed_scripts_if_needed();
		// One-time: existing users who already recorded get the legacy script locked.
		if ( ! get_option( 'bmf_biovoice_legacy_script_migrated' ) ) {
			BMF_BioVoice_Repository::migrate_existing_users_to_legacy_script();
			update_option( 'bmf_biovoice_legacy_script_migrated', 1, false );
		}
	}

	/**
	 * Active protocol + ordered steps for the client wizard.
	 */
	public static function get_active_payload( string $purpose = 'baseline' ): array {
		$protocol = BMF_BioVoice_Repository::get_active_protocol( $purpose );
		if ( ! $protocol ) {
			$protocol = BMF_BioVoice_Repository::get_active_protocol( '' );
		}
		if ( ! $protocol ) {
			return [
				'protocol' => null,
				'steps'    => [],
			];
		}
		$steps = BMF_BioVoice_Repository::get_steps_for_protocol( (int) $protocol['id'] );
		return [
			'protocol' => [
				'id'      => (int) $protocol['id'],
				'version' => $protocol['version'],
				'name'    => $protocol['name'],
				'purpose' => $protocol['purpose'],
			],
			'steps'    => array_map( [ __CLASS__, 'format_step' ], $steps ),
		];
	}

	public static function format_step( array $row ): array {
		return [
			'id'             => (int) $row['id'],
			'step_number'    => (int) $row['step_number'],
			'task_code'      => $row['task_code'],
			'title'          => $row['title'],
			'directions'     => $row['directions'],
			'prompt_text'    => $row['prompt_text'],
			'min_seconds'    => (float) $row['min_seconds'],
			'max_seconds'    => $row['max_seconds'] !== null ? (float) $row['max_seconds'] : null,
			'requires_audio' => (bool) $row['requires_audio'],
			'is_silence'     => (bool) $row['is_silence'],
			'is_speech'      => (bool) $row['is_speech'],
			'allow_retake'   => (bool) $row['allow_retake'],
		];
	}

	/**
	 * Seed baseline protocol v1 from current SOP assumptions.
	 */
	public static function seed_v1_if_needed() {
		$existing = BMF_BioVoice_Repository::get_protocol_by_version( self::SEED_VERSION );
		if ( $existing ) {
			return (int) $existing['id'];
		}

		$protocol_id = BMF_BioVoice_Repository::insert_protocol( [
			'version'      => self::SEED_VERSION,
			'name'         => 'BioVoicePrint Baseline v1',
			'purpose'      => 'baseline',
			'is_active'    => 1,
			'notes'        => 'Seeded from SOP: mic check + silence + 2 phonation + counting + reading + post silence. Baseline = 3 complete groups.',
			'published_at' => current_time( 'mysql', true ),
		] );

		if ( ! $protocol_id ) {
			return false;
		}

		$reading = 'I am reading this passage in a calm, natural, and steady voice. I will speak at a comfortable pace and breathe normally throughout the recording. I will not try to change my tone, emotion, or rhythm. I will allow each sentence to flow naturally and pause only where punctuation occurs. My goal is to remain clear, relaxed, and consistent from beginning to end. This recording is capturing how my voice functions in this moment through breath, timing, steadiness, and control.';

		$steps = [
			[
				'step_number'    => 0,
				'task_code'      => 'mic_check',
				'title'          => 'Microphone check',
				'directions'     => 'Hold the phone or microphone 6–8 inches from your mouth. Speak naturally for a couple of seconds so we can confirm audio is coming through.',
				'prompt_text'    => 'Testing one two three.',
				'min_seconds'    => 2,
				'max_seconds'    => 5,
				'requires_audio' => 1,
				'is_silence'     => 0,
				'is_speech'      => 1,
				'allow_retake'   => 1,
				'sort_order'     => 0,
			],
			[
				'step_number'    => 1,
				'task_code'      => 'silence_pre',
				'title'          => 'Pre-capture silence',
				'directions'     => 'Remain still and quiet. Do not speak. This captures the noise floor.',
				'prompt_text'    => null,
				'min_seconds'    => 10,
				'max_seconds'    => 12,
				'requires_audio' => 1,
				'is_silence'     => 1,
				'is_speech'      => 0,
				'allow_retake'   => 1,
				'sort_order'     => 10,
			],
			[
				'step_number'    => 2,
				'task_code'      => 'phonation_1',
				'title'          => 'Sustained phonation (trial 1)',
				'directions'     => 'Take a comfortable breath and hold the sound “ahhh” as steadily as you can.',
				'prompt_text'    => 'ahhh',
				'min_seconds'    => 4,
				'max_seconds'    => 30,
				'requires_audio' => 1,
				'is_silence'     => 0,
				'is_speech'      => 1,
				'allow_retake'   => 1,
				'sort_order'     => 20,
			],
			[
				'step_number'    => 3,
				'task_code'      => 'phonation_2',
				'title'          => 'Sustained phonation (trial 2)',
				'directions'     => 'Rest briefly, then again hold “ahhh” as steadily as you can.',
				'prompt_text'    => 'ahhh',
				'min_seconds'    => 4,
				'max_seconds'    => 30,
				'requires_audio' => 1,
				'is_silence'     => 0,
				'is_speech'      => 1,
				'allow_retake'   => 1,
				'sort_order'     => 30,
			],
			[
				'step_number'    => 4,
				'task_code'      => 'count_natural',
				'title'          => 'Rhythmic counting (natural)',
				'directions'     => 'Count from one to ten in your normal speaking voice.',
				'prompt_text'    => '1 2 3 4 5 6 7 8 9 10',
				'min_seconds'    => 5,
				'max_seconds'    => 20,
				'requires_audio' => 1,
				'is_silence'     => 0,
				'is_speech'      => 1,
				'allow_retake'   => 1,
				'sort_order'     => 40,
			],
			[
				'step_number'    => 5,
				'task_code'      => 'count_slow',
				'title'          => 'Rhythmic counting (slower)',
				'directions'     => 'Count from one to ten again, slightly slower, while staying natural and relaxed.',
				'prompt_text'    => '1 2 3 4 5 6 7 8 9 10',
				'min_seconds'    => 8,
				'max_seconds'    => 30,
				'requires_audio' => 1,
				'is_silence'     => 0,
				'is_speech'      => 1,
				'allow_retake'   => 1,
				'sort_order'     => 50,
			],
			[
				'step_number'    => 6,
				'task_code'      => 'reading',
				'title'          => 'Standardized reading passage',
				'directions'     => 'Read the passage below in a calm, natural, steady voice at a comfortable pace.',
				'prompt_text'    => $reading,
				'min_seconds'    => 20,
				'max_seconds'    => 45,
				'requires_audio' => 1,
				'is_silence'     => 0,
				'is_speech'      => 1,
				'allow_retake'   => 1,
				'sort_order'     => 60,
			],
			[
				'step_number'    => 7,
				'task_code'      => 'silence_post',
				'title'          => 'Post-capture silence',
				'directions'     => 'Remain still and quiet again for the trailing noise floor.',
				'prompt_text'    => null,
				'min_seconds'    => 10,
				'max_seconds'    => 12,
				'requires_audio' => 1,
				'is_silence'     => 1,
				'is_speech'      => 0,
				'allow_retake'   => 1,
				'sort_order'     => 70,
			],
		];

		foreach ( $steps as $step ) {
			$step['protocol_id'] = $protocol_id;
			BMF_BioVoice_Repository::insert_protocol_step( $step );
		}

		return $protocol_id;
	}

	/**
	 * Seed reading scripts (legacy + category variants). Idempotent.
	 */
	public static function seed_scripts_if_needed() {
		if ( BMF_BioVoice_Repository::get_script_by_code( 'legacy_en_v1' ) ) {
			return;
		}

		$legacy_body = 'I am reading this passage in a calm, natural, and steady voice. I will speak at a comfortable pace and breathe normally throughout the recording. I will not try to change my tone, emotion, or rhythm. I will allow each sentence to flow naturally and pause only where punctuation occurs. My goal is to remain clear, relaxed, and consistent from beginning to end. This recording is capturing how my voice functions in this moment through breath, timing, steadiness, and control.';

		$scripts = [
			[
				'script_code'       => 'legacy_en_v1',
				'category'          => 'general',
				'language'          => 'en',
				'title'             => 'Original / General',
				'description'       => 'The original BioVoicePrint passage. Clear, neutral, and consistent — a solid default for most users.',
				'body_text'         => $legacy_body,
				'estimated_seconds' => 35,
				'version'           => '1.0',
				'is_active'         => 1,
				'sort_order'        => 0,
			],
			[
				'script_code'       => 'tech_en_v1',
				'category'          => 'technical',
				'language'          => 'en',
				'title'             => 'Technical explanation',
				'description'       => 'Structured, precise language useful for engineers, analysts, and instructors who explain systems or processes.',
				'body_text'         => 'When we design a reliable system, we begin with clear requirements and measurable outcomes. Each component must perform its role without unnecessary complexity. I describe the flow from input to output in a calm and steady voice, pausing only where the logic requires a breath. Precision matters more than speed. Consistency of timing and articulation lets us observe how breath and control support technical communication.',
				'estimated_seconds' => 40,
				'version'           => '1.0',
				'is_active'         => 1,
				'sort_order'        => 10,
			],
			[
				'script_code'       => 'motiv_en_v1',
				'category'          => 'motivational',
				'language'          => 'en',
				'title'             => 'Coaching / motivational',
				'description'       => 'Supportive, forward-looking language suited to coaches, trainers, and leaders who encourage others.',
				'body_text'         => 'Progress is built one deliberate step at a time. I speak with steady energy and a calm center, reminding myself that recovery and focus are part of performance. Each breath supports the next phrase. I do not rush. I allow the message to land clearly so that encouragement feels grounded and real. This is how I communicate when I want others to feel capable and ready.',
				'estimated_seconds' => 35,
				'version'           => '1.0',
				'is_active'         => 1,
				'sort_order'        => 20,
			],
			[
				'script_code'       => 'public_en_v1',
				'category'          => 'public_speaking',
				'language'          => 'en',
				'title'             => 'Public speaking',
				'description'       => 'Projected yet natural phrasing for presentations, talks, and any setting where presence and clarity matter.',
				'body_text'         => 'Good morning. Today I want to share a simple idea with clarity and presence. I speak at a measured pace so every listener can follow. My posture is open, my breath is steady, and my voice carries without strain. I pause between thoughts so the message has room to land. Consistency of tone and timing is what makes a presentation feel confident and trustworthy from the first sentence to the last.',
				'estimated_seconds' => 40,
				'version'           => '1.0',
				'is_active'         => 1,
				'sort_order'        => 30,
			],
			[
				'script_code'       => 'legacy_es_v1',
				'category'          => 'general',
				'language'          => 'es',
				'title'             => 'Original / General (Español)',
				'description'       => 'La pasaje original de BioVoicePrint en español. Clara, neutra y consistente.',
				'body_text'         => 'Estoy leyendo este pasaje con una voz calmada, natural y constante. Hablaré a un ritmo cómodo y respiraré con normalidad durante la grabación. No intentaré cambiar mi tono, emoción o ritmo. Permitiré que cada oración fluya de forma natural y solo haré pausas donde lo indique la puntuación. Mi objetivo es mantenerme claro, relajado y consistente de principio a fin. Esta grabación captura cómo funciona mi voz en este momento a través de la respiración, el tiempo, la estabilidad y el control.',
				'estimated_seconds' => 40,
				'version'           => '1.0',
				'is_active'         => 1,
				'sort_order'        => 0,
			],
		];

		foreach ( $scripts as $row ) {
			BMF_BioVoice_Repository::insert_script( $row );
		}
	}
}
