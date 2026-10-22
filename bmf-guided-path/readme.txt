=== BMF Guided Path ===
Contributors: breathermae
Tags: workflow, guidance, wp-fusion, member
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0-poc
License: GPLv2 or later

Data-driven next-step guidance for logged-in members. Surfaces the matching workflow step on the member-home dashboard.

== Description ==

Evaluates ordered workflow steps against the current user's WP Fusion tags (all_present / any_present / none_present) and returns the first matching step. Designed to drive the center section of the member-home dashboard.

Public API:
- `bmf_guided_path_get_current_step( $user_id = 0 )` returns array with id, title, description, url (or null).

Tables:
- wp_bm_workflow_steps
- wp_bm_workflow_history
- wp_bm_workflow_user_state

Initial steps are seeded on activation.

== Changelog ==

= 0.1.0-poc =
* Initial foundation: tables, seed steps, evaluator, history, public API.
