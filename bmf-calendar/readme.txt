=== BMF Calendar ===
Contributors: breathermae
Tags: appointments, calendar, scheduling, outlook, provider
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.1-poc
License: GPLv2 or later

Provider–Member appointment scheduling with availability, Calendly-style requests, and Outlook integration. WP Fusion optional.

== Description ==

BMF Calendar is a focused scheduling plugin for healthcare / wellness platforms.

* Provider availability (weekly + exceptions)
* Member request flow (specific Provider or general)
* Outlook event write-back (Phase 1)
* WP Fusion tags optional – works with native capability / manual list
* Shortcodes for member view, provider calendar, and request UI
* Mobile-capable

Documentation will be expanded once core functionality is locked.

== Shortcodes ==

* [bmf_my_appointments]
* [bmf_member_appointments]
* [bmf_provider_calendar]
* [bmf_request_appointment]

== Changelog ==

= 0.2.0-poc =
* Provider weekly availability + blocked dates.
* Slot calculator (30-minute slots, skips booked times).
* Member request flow: pick provider or general, pick a slot, status=requested.

= 0.1.1-poc =
* Appointments list + create/edit/delete (soft).
* Status lifecycle: requested, confirmed, completed, cancelled, no_show.
* Provider view with ULS selected-member support.
* Member self-view (read-only for now).

= 0.1.0-poc =
* Initial scaffold: tables, settings, provider designation, shortcodes, Outlook placeholders.
