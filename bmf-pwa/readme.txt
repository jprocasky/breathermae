=== BMF PWA ===
Contributors: jprocasky
Tags: pwa, progressive web app, install app, desktop app
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Turns Breathermae into an installable Progressive Web App and provides a convenient Install button shortcode.

== Description ==

* Generates a proper Web App Manifest
* Registers a service worker (required by Chrome/Edge for installability)
* Adds all necessary meta tags (theme-color, apple-mobile-web-app-*)
* Provides the shortcode `[bmf_pwa_install_button]`
* Admin settings page under Settings → BMF PWA

== Installation ==

1. Upload the `bmf-pwa` folder to `/wp-content/plugins/`
2. Activate the plugin
3. Go to Settings → BMF PWA and fill in the icons (192×192 and 512×512 PNG) + colors
4. Place the shortcode `[bmf_pwa_install_button]` anywhere you want the button to appear

== Usage ==

Shortcode:
[bmf_pwa_install_button]

Optional attributes:
[bmf_pwa_install_button text="Install Breathermae App" class="my-elementor-class"]

The button is automatically hidden when:
- The site is already installed as a PWA
- The browser does not support the install prompt (e.g. older browsers or iOS – users use Share → Add to Home Screen)

== Changelog ==

= 1.0.0 =
* Initial release
