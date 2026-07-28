# Changelog

## 1.2.0 — Subcategory enrichment from forms

- Added `BMAE_AVF_Section_Map` mapping live `bm_form_sections` IDs (forms 18–25) to registry subcategory keys.
- Registry updated to match live form section titles; added forms-only sections (`mind_body_connection`, `environmental_reflection`).
- Adapter now joins `bm_responses` + `bm_section_scores` to attach subcategory scores to each `bm_pillars_results` assessment.
- Matching uses a ±21 day window around `results_date`, preferring the closest submitted response per pillar form.
- Section scores normalized from 0–1 to 0–100 when needed.

## 1.1.1 — Pillar-level production history

- Added `BMAE_AVF_Pillars_Results_Adapter` to read finalized rows from `{prefix}bm_pillars_results`.
- Provider now prefers live `bm_pillars_results` data over demo data when available.
- Provider and validator accept **direct pillar scores** (no subcategory required).
- Overall score prefers stored `master_score` when present.
- JavaScript shows a clear message when subcategory detail is not available.
- Source label distinguishes `bm_pillars_results`, filter-supplied platform data, and demo data.

## 1.1.0 — Module 2

- Preserved the original Module 1 WordPress, Elementor, security, REST, and asset foundation.
- Added the canonical Eight Pillars registry.
- Added all 38 current wellness subcategories.
- Added versioned quarterly historical-data contracts.
- Added data validation, range enforcement, missing-value handling, duplicate detection, and date normalization.
- Added demonstration history for immediate integration testing.
- Added the `bmae_avf_eight_pillars_history` production-data filter.
- Added weighted pillar aggregation.
- Added weighted overall wellness aggregation.
- Added baseline, current, total-change, most-improved, and priority summaries.
- Added a working historical trend chart.
- Added eight expandable pillar cards.
- Added pillar sparklines.
- Added complete subcategory drill-down.
- Preserved authenticated user-level access rules.

## 1.0.0 — Module 1

- Added native WordPress plugin bootstrap.
- Added PHP 8.0 and WordPress 6.4 activation validation.
- Added central framework configuration.
- Added dashboard registry.
- Added authenticated REST API framework.
- Added current-user and administrator authorization.
- Added Elementor-compatible Eight Pillars shortcode.
- Added responsive dashboard shell.
- Added JavaScript initialization framework.
- Added CSS foundation.
