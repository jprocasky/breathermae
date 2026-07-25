<?php
/**
 * Keep Q&A / Extremes date and response selects visible when they have options.
 * Counteracts setEmpty() hiding the dropdown after a no-rows result.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) {
		return;
	}
	$plugin_file = dirname( __DIR__ ) . '/breathermae-forms.php';
	$js_path     = dirname( __DIR__ ) . '/assets/js/bmf-qa-select-fix.js';
	if ( ! file_exists( $js_path ) ) {
		return;
	}
	$ver = (string) filemtime( $js_path );
	$src = plugins_url( 'assets/js/bmf-qa-select-fix.js', $plugin_file );
	wp_register_script( 'bmf-qa-select-fix', $src, [], $ver, true );
}, 20 );

add_filter( 'do_shortcode_tag', function ( $output, $tag ) {
	if ( ! in_array( $tag, [ 'bmf_qa', 'bmf_qa_extremes' ], true ) ) {
		return $output;
	}
	if ( wp_script_is( 'bmf-qa-select-fix', 'registered' ) ) {
		wp_enqueue_script( 'bmf-qa-select-fix' );
	}
	return $output;
}, 20, 2 );
