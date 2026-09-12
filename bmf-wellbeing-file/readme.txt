=== BMF Wellbeing File ===
Contributors: breathermae
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.1.0-poc
License: GPLv2 or later

Canonical wellbeing brief assembled from entry-level self-assessments. WP Fusion gates the page.

== Description ==

Reads existing results tables. Does not capture new assessments.

Enabled in v0.1:
* RSI (pulse, weekly recommended) — `wp_bm_rsi_results`
* 8-Pillars (cycle, 90 days) — `wp_bm_pillars_results`
* Key Essentials (state, 14 days) — `uls_key_essentials`

Reserved adapters (schema only): BSI, BioVoicePrint, Fitbit, Whoop, Oura, Apple Health.

Shortcodes:

[bmf_wellbeing_status]
[bmf_wellbeing_brief]
[bmf_wellbeing_brief fixture="1"]
[bmf_wellbeing_brief voice="provider"]
[bmf_wellbeing_status admin="1"]

admin="1" listens for uls:selected-member (same as Q&A / BioVoice admin panels).
fixture="1" renders fixtures/sample_brief.json for Elementor layout work.

Map version: wellbeing_map_v1 in includes/class-map.php

== Installation ==

1. Upload `bmf-wellbeing-file` to `/wp-content/plugins/`
2. Activate
3. Add shortcodes on a WP Fusion-gated page

== Changelog ==

= 0.1.1-poc =
* Force light text on Themes / Highlights (overrides Elementor list color)
* 8 Pillars scores sit on one row on desktop

= 0.1.0-poc =
* Map v1 + RSI / Pillars / Keys adapters
* Assembler brief (scores, freshness, capped extremes, deterministic patterns)
* Status + brief shortcodes, fixture mode, provider member select
