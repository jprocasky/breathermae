=== BMF Wellbeing File ===
Contributors: breathermae
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.1.8-poc
License: GPLv2 or later

Canonical wellbeing brief assembled from entry-level self-assessments. WP Fusion gates the page.

== Description ==

Reads existing results tables. Does not capture new assessments.

Enabled:
* RSI (pulse, weekly recommended) — `wp_bm_rsi_results`
* 8-Pillars (cycle, 90 days) — `wp_bm_pillars_results`
* Key Essentials (state, 14 days) — `uls_key_essentials`
* BSI (cycle, 90 days) — `wp_bm_bsi_results` F1–F9 + Drivers/Mediators/Outcomes
* BioVoicePrint (state) — `wp_bm_biovoice_results` stage7 RDI + marker highlights
* Fitbit (state, nightly) — `uls_bm_fitbit_sleep_summary` (wp_user_id, date_of_sleep, minutes_asleep, efficiency)
* Profile (context) — `uls_ULS_CF_BIO` age/sex/height/weight/diet/employment/dependents (no race/income on card)

Reserved adapters (schema only): Whoop, Oura, Apple Health.

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

= 0.1.8-poc =
* Highlighted items sit under Themes, before detailed score blocks

= 0.1.7-poc =
* Profile adapter from uls_ULS_CF_BIO: context strip + cross-source themes
* Race / income / education / marital stay off the card

= 0.1.6-poc =
* Pin Fitbit reader to uls_bm_fitbit_sleep_summary

= 0.1.5-poc =
* Fitbit adapter: last night / 7-night hours / efficiency, short-night theme, sleep history
* Nights via filter or discovered %fitbit% table

= 0.1.4-poc =
* BioVoicePrint adapter: latest stage7 RDI, summary, top markers, group counts, RDI history
* Theme when RDI ≥25 or device_mismatch

= 0.1.3-poc =
* BSI adapter: Drivers/Mediators/Outcomes + F1–F9, 90-day freshness, D/M/O history chart
* Theme titles end with an em dash so they do not run into body copy

= 0.1.2-poc =
* Compact History charts on the brief (RSI Core/Performance, Pillars master, Keys overall)
* Chart.js 4 from CDN; hidden until a source has 2+ dated points

= 0.1.1-poc =
* Force light text on Themes / Highlights (overrides Elementor list color)
* 8 Pillars scores sit on one row on desktop

= 0.1.0-poc =
* Map v1 + RSI / Pillars / Keys adapters
* Assembler brief (scores, freshness, capped extremes, deterministic patterns)
* Status + brief shortcodes, fixture mode, provider member select
