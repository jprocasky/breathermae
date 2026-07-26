=== Breathermae BioVoicePrint (POC Skeleton) ===
Contributors: breathermae
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.1.0-poc

Skeleton plugin for BioVoicePrint voice capture, private local storage, and session tracking.
No scoring or results UI yet — framework only.

== Description ==

This is a proof-of-concept / foundation plugin. It provides:

* Browser-based audio recording (MediaRecorder)
* Private local file storage (not directly web-accessible)
* Custom database table for sessions
* REST API for upload, list, play, delete
* Shortcodes for recording UI and session list

Scoring, baseline establishment rules, Wellness Anchor, and results display will be added later once the Recording & Baseline Collection Protocol is finalized.

== Installation ==

1. Copy the `bmf-biovoiceprint` folder into `wp-content/plugins/`
2. Activate the plugin in wp-admin
3. Place shortcodes on any page (user must be logged in)

== Shortcodes ==

[bmf_biovoice_record]
  - Shows the recorder UI for the current logged-in user
  - Optional attribute: session_type="baseline" or "comparison" (default)

[bmf_biovoice_sessions]
  - Lists the current user's past recordings with playback
  - Optional attribute: limit="20"

== REST API ==

Base: /wp-json/bmf-biovoice/v1/

POST   /sessions              Upload audio (multipart field "audio")
GET    /sessions              List current user's sessions
GET    /sessions/{id}/play    Stream audio (owner or admin only)
DELETE /sessions/{id}         Delete session + file

All routes require a logged-in user (cookie + X-WP-Nonce).

== Storage ==

Files are stored under:
  wp-content/uploads/bmf-biovoice-private/{user_id}/{Y}/{m}/...

Protected by .htaccess Deny from all. Playback is forced through the REST play endpoint.

A thin storage interface is used so Azure Blob / S3 can be swapped in later without changing callers.

== Database ==

Table: {prefix}bm_biovoice_sessions

Columns include placeholders for wellness_anchor_json, context_flags_json, quality_json so the protocol fields can be filled later without a schema migration scramble.

== Future expansion points ==

* Baseline establishment rules + bm_biovoice_baselines table
* Quality gates and reliability flags
* Background job that calls the Python scoring engine
* Results shortcodes + Wellness Congruence UI
* Storage backend switch (local → Azure)
* WP Fusion / capability gates for membership tiers
