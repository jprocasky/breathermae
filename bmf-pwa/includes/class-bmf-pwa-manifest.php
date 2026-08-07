<?php
/**
 * Serves the web app manifest and service worker.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_PWA_Manifest {

	/** @var BMF_PWA_Manifest */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'serve_endpoints' ) );
	}

	public static function add_rewrite_rules() {
		add_rewrite_rule( '^bmf-pwa-manifest\.webmanifest$', 'index.php?bmf_pwa_manifest=1', 'top' );
		add_rewrite_rule( '^bmf-pwa-sw\.js$', 'index.php?bmf_pwa_sw=1', 'top' );
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'bmf_pwa_manifest';
		$vars[] = 'bmf_pwa_sw';
		return $vars;
	}

	public function serve_endpoints() {
		if ( get_query_var( 'bmf_pwa_manifest' ) ) {
			$this->serve_manifest();
			exit;
		}

		if ( get_query_var( 'bmf_pwa_sw' ) ) {
			$this->serve_service_worker();
			exit;
		}
	}

	private function serve_manifest() {
		$settings = BMF_PWA_Settings::get_settings();

		$icons = array();
		if ( ! empty( $settings['icon_192'] ) ) {
			$icons[] = array(
				'src'   => $settings['icon_192'],
				'sizes' => '192x192',
				'type'  => 'image/png',
				'purpose' => 'any',
			);
			$icons[] = array(
				'src'   => $settings['icon_192'],
				'sizes' => '192x192',
				'type'  => 'image/png',
				'purpose' => 'maskable',
			);
		}
		if ( ! empty( $settings['icon_512'] ) ) {
			$icons[] = array(
				'src'   => $settings['icon_512'],
				'sizes' => '512x512',
				'type'  => 'image/png',
				'purpose' => 'any',
			);
			$icons[] = array(
				'src'   => $settings['icon_512'],
				'sizes' => '512x512',
				'type'  => 'image/png',
				'purpose' => 'maskable',
			);
		}

		$manifest = array(
			'name'             => $settings['name'],
			'short_name'       => $settings['short_name'],
			'description'      => $settings['description'],
			'start_url'        => $settings['start_url'],
			'scope'            => '/',
			'display'          => $settings['display'],
			'background_color' => $settings['background_color'],
			'theme_color'      => $settings['theme_color'],
			'orientation'      => 'any',
			'icons'            => $icons,
		);

		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	private function serve_service_worker() {
		// Minimal service worker that satisfies Chromium's installability criteria.
		// It caches the start page and a few static assets so the SW has a fetch handler.
		$sw = <<<'JS'
const CACHE_NAME = 'bmf-pwa-v1';
const PRECACHE = ['/'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  // Network-first for HTML, cache-first for others – keep it simple.
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request).catch(() => caches.match('/'))
    );
    return;
  }
  event.respondWith(
    caches.match(event.request).then((cached) => cached || fetch(event.request))
  );
});
JS;

		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' ); // Allow controlling the whole origin
		header( 'Cache-Control: no-cache' );
		echo $sw;
	}
}
