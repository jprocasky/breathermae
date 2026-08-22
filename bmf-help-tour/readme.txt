=== BMF Help Tour ===
Contributors: breathermae
Tags: onboarding, tour, guided help, elementor
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.0-poc
License: GPLv2 or later

Attribute-driven guided help tours for Elementor pages. Mobile-first. Native Container controls.

== Description ==

BMF Help Tour lets you add step-by-step guided help to any Elementor page.

**Two ways to define steps**

1. **Elementor Container controls (recommended)**  
   Edit a Container → Advanced → **Help Tour**  
   Enable “Use as tour step”, set step number, title, body, icon, importance.

2. **Manual custom attributes** (still supported)  
   Advanced → Custom Attributes, one per line:
   bmf-help-step|1
   bmf-help-title|Start Here
   bmf-help-text|This is where you begin…

**Behavior**

* Tour auto-starts once per page per logged-in user.
* Completion stored in user meta (bmf_completed_help_tours), keyed by page ID.
* Hidden elements (Elementor responsive, WP Fusion, display:none) are skipped.
* Restart via shortcode or CSS class (see below).

**Restarting a tour**

* Shortcode: [bmf_help_tour_restart] or [bmf_help_tour_restart text="Show me again"]
* Or add class bmf-help-tour-restart to any icon / button / link.

**Developer notes**

* Engine: Driver.js
* Console: window.bmfHelpTourStart() / window.bmfHelpTourRestart()
* Only runs for logged-in users

== Changelog ==

= 0.2.0-poc =
* Elementor Container + Section “Help Tour” controls (Advanced tab)
* Writes same bmf-help-* attributes used by the JS engine

= 0.1.4-poc =
* Stable tour ID by page only (completion survives mobile/desktop and title edits)

= 0.1.3-poc =
* Skip hidden / responsive / zero-size steps

= 0.1.2-poc =
* Mobile scroll/popover positioning fix (refresh after highlight)

= 0.1.1-poc =
* Restart shortcode + class trigger

= 0.1.0-poc =
* Initial POC
