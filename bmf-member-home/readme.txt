=== BMF Member Home ===
Contributors: breathermae
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.1.7-poc
License: GPLv2 or later

Logged-in Today dashboard. First iteration of the home redesign. Other site pages stay on the current theme.

== Description ==

Replaces the portal-tile dashboard with a Today view. WP Fusion still gates the page. This plugin does not check roles.

* Site rail, site links only. Full-results links stay off this page.
* Assessment cards with a due badge from the existing result tables.
* Provider appointments via [bmf_my_appointments].
* Internal reminders via [uls_member_appointments future="1"].
* Notes via [uls_member_notes max="2" format="html" show_dates="0"].
* Theme uses the same viewer usermeta as the wellbeing file: bmf_ui_theme.

Every link is a page ID plus an optional anchor, set under Settings → Member Home. Nothing is hardcoded.

== Shortcodes ==

* [bmf_member_home] — rail plus Today column. Use this on the dashboard page.
* [bmf_member_nav] — rail only, for a later shared layout.
* [bmf_member_today] — main column only.

== Installation ==

1. Upload `bmf-member-home` to `/wp-content/plugins/` and activate.
2. Settings → Member Home. Set the rail pages, point each assessment card at the Assessments page, add an anchor if that page has one.
3. On the logged-in dashboard, replace the old widgets with [bmf_member_home] in a full-width Elementor container.
4. Leave RSI, Pillars, BSI, and the other detail pages alone.

== Changelog ==

= 0.1.5-poc =
* Assessment badges say Available or Current. Not started stays.
* Add to Calendar keeps white text. Reminder cards follow the theme.
* Help tour steps are bmf-help-* attributes on the rail, assessments, agenda, and reminders. Copy is in Settings. Existing tour script picks them up.

= 0.1.4-poc =
* Open results stays readable. Only the Take button uses the filled style.
* Info panel button label is editable. Bump Rev to show a notice again after a copy change.
* Greeting uses the browser clock. Notices live in the bmf_member_home_settings option.

= 0.1.3-poc =
* Assessment cards have a Take page (surveys plus anchor) and a Results page.
* Info panel above Next 30 days. First matching Settings row: tag, max days from registration. Dismiss stored on the user.
* Add to Calendar and other embedded buttons keep dark text on the accent fill.

= 0.1.2-poc =
* Rail and assessment icons. Sun/moon theme control.
* Membership chip from WP Fusion tags: S360, else ART, else Complementary.
* Assessment cards tightened. Embedded reminders, notes, and appointments forced onto the theme background.

= 0.1.1-poc =
* Settings save no longer wipes page IDs. WordPress sanitizes the option twice; the second pass now finds rows by key.
* Show checkboxes post an explicit 0 so an unchecked box is not confused with a missing row.
* Empty reminder, notes, and appointment shortcodes show a line instead of a blank card.
* Shell paints its own background so Elementor does not leave light text on the page background.

= 0.1.0-poc =
* Today shell, configurable rail and assessment destinations, cadence badges, embedded reminders / notes / provider appointments.
