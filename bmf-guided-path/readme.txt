=== BMF Guided Path ===
Contributors: breathermae
Tags: workflow, guidance, wp-fusion, member
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.8-poc
License: GPLv2 or later

Data-driven next-step guidance for logged-in members. Surfaces the matching workflow step on the member-home dashboard.

== Description ==

Evaluates ordered workflow steps and returns the first match. Designed to drive the center section of the member-home dashboard (title, description, and button). No header/footer button and no opt-in.

A step matches when all of its conditions pass:

* **all_present** — every listed WP Fusion tag must exist.
* **any_present** — at least one listed tag must exist (blank = ignore).
* **none_present** — none of the listed tags may exist.
* **days_since_gt / days_since_lt** — optional, based on usermeta `_breathermae_last_active`. Ignored when blank. A missing timestamp fails the check.
* **suppress_event_key + suppress_if_seen_days** — optional. Looks up `uls_user_event_log` for the current user's email. If a matching `event_key` exists and `last_updated` is within the day window, the step is skipped. Days **0** means suppress forever once any row exists (one-time notice). Event key blank = never suppress.

Clicking the member-home button on a step that has an event key writes (or refreshes) that row in `uls_user_event_log`, then cleans the URL. Works for a destination page or a member-home-only notice.

Public API:
* `bmf_guided_path_get_current_step( $user_id = 0 )` returns array with id, title, description, url, destination, anchor, button_label (or null).

Tables:
* `wp_bm_workflow_steps` — definition (sort_order, title, description, conditions JSON, destination, anchor, button_label, enabled, notes).
* `wp_bm_workflow_history` — presented / clicked audit.
* `wp_bm_workflow_user_state` — last evaluated step cache.

Admin (Settings → Guided Path):
* List with up/down reorder (no page reload) and Insert above / Insert below.
* Separate All / Any / None fields that write the conditions JSON. Tag autocomplete from WP Fusion available tags.
* Optional days-since, suppress event key + days, custom button label (blank = Continue), destination slug, and anchor.
* Destination and anchor are stored separately. Leading `/` and `#` are stripped. Resulting URL is `home_url('/slug')` + `#anchor` (no slash before the hash).

== Changelog ==

= 0.1.8-poc =
* Suppress days 0 = forever once any matching `uls_user_event_log` row exists.
* Button URL carries a short-lived `bmf_gp_seen` token. On click the event is recorded and the URL is cleaned.
* Works for a destination page or a pure member-home notice (no separate slug required). An event key is still required so there is something to record and check.
* Admin days input allows 0.

= 0.1.7-poc =
* Optional suppress on event view. Fields: event key (from `uls_user_event_log`) and day window.
* Evaluator skips the step when a matching row exists and `last_updated` is within N days. Either field blank = ignore.
* Enables interleaving “assessment ready” and “review results” steps so a viewed result does not block later steps.

= 0.1.6-poc =
* Fixed reorder and insert. IDs from `wp_list_pluck` are cast to int before `array_search`.
* Up/down arrows move rows in place without a page reload (DOM swap + background save).

= 0.1.5-poc =
* Optional `button_label` column. Blank shows “Continue”. Button sizes to the label text.
* Member Home consumes the label. dbDelta runs when the DB version changes.

= 0.1.4-poc =
* Insert above / Insert below links so new steps can be placed without moving them up from the bottom.
* Optional days-since conditions (`days_since_gt`, `days_since_lt`) using usermeta `_breathermae_last_active`. Ignored when blank; a null timestamp fails the check.

= 0.1.3-poc =
* Up/down arrows reorder and auto-renumber `sort_order`.
* Conditions split into All / Any / None comma fields that write JSON.
* Tag autocomplete from `wpf_get_option( 'available_tags' )`.

= 0.1.2-poc =
* Admin menu “Guided Path” for list / add / edit steps (sort_order, title, description, conditions JSON, destination, anchor, enabled, notes).

= 0.1.1-poc =
* Expanded seed uses live tags: `RSI_COMPLETE` (not `RSI_COMPLETED`), `RAPID8`, `KEY_COMPLETE`, `9_COMPLETE`, `BSI_COMPLETE`, `BVP_READY`, membership `ART` / `S360`.
* Priority order puts results-ready and due assessments ahead of catch-alls.
* `seed_initial_steps( true )` replaces existing rows.

= 0.1.0-poc =
* Initial foundation: tables, seed steps, evaluator (first-match on all / any / none), history, public API.
* Designed for the center panel of member-home. No floating button and no opt-in.
