=== BMF Member Home ===
Contributors: breathermae
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.1.36-poc
License: GPLv2 or later

Logged-in Today dashboard. Dark/light tokens shared with the wellbeing file. Other site pages stay on the current theme until each is redesigned.

== Description ==

Replaces the portal-tile dashboard with a Today view. WP Fusion still gates the page. This plugin does not check roles.

* Site rail. Full-results links and group dividers show only on [bmf_member_nav context="results"].
* Under 900px the rail is a closed drawer opened by Menu. Desktop stays a left column.
* Assessment cards with Available / Current / Not started. Available and the primary Take button also require the WP Fusion tags configured on the card.
* Provider appointments via [bmf_my_appointments].
* Internal reminders via [uls_member_appointments future="1"].
* Notes via [uls_member_notes max="2" format="html" show_dates="0"].
* Info row: Wellness Index or spot media on the left, guided-path step or membership notice on the right.
* Theme uses the same viewer usermeta as the wellbeing file: bmf_ui_theme.

Every link is a page ID plus an optional anchor, set under Settings → Member Home. Nothing is hardcoded.

== Shortcodes ==

* [bmf_member_home] — rail plus Today column. Use this on the dashboard page.
* [bmf_member_nav] — rail only. context="results" adds the full-results links.
* [bmf_member_today] — main column only.
* [bmf_assessment_timeline] — standalone Assessment Timeline panel.

== Installation ==

1. Upload `bmf-member-home` to `/wp-content/plugins/` and activate.
2. Settings → Member Home. Set the rail pages, point each assessment card at the take page and the results page, add an anchor if that page has one.
3. On the logged-in dashboard, replace the old widgets with [bmf_member_home] in a full-width Elementor container.
4. On a results page, use [bmf_member_nav context="results"] in the left column.
5. Leave RSI, Pillars, BSI, and the other detail pages alone until each is redesigned.

== Changelog ==

= 0.1.36-poc =
* Assessment cards and Wellness Timeline convert UTC datetimes to the browser’s local timezone for displayed dates, ages, marker placement, and the Today line. Date-only values are not shifted.

= 0.1.35-poc =
* Assessment cards and Wellness Timeline use updated_at (fallback results_date) for RSI, BSI, and Pillars so a multi-day completion shows the finish date.

= 0.1.34-poc =
* Help Tour includes the Wellness Timeline panel (step after the next-step panel). Copy is editable under Settings → Member Home.

= 0.1.33-poc =
* Wellness Timeline date header is a two-row Gantt axis: full month name on top, day numbers in boxes below. Subtle dotted vertical lines mark each Sunday.

= 0.1.32-poc =
* Wellness Timeline: group labels sit on the first lane of each membership instead of a full-width header row (removes the blank row under the last assessment in a group). Sticky labels stay above markers while scrolling.

= 0.1.31-poc =
* Wellness Timeline: original baseline removed. Actuals are bright green. Next available marker is solid accent; later adjusted markers stay outlined. Single row per assessment (blank sub-rows removed). Scrollbar styled for dark mode.

= 0.1.30-poc =
* Wellness Timeline lanes use cumulative membership groups (Complementary < Art of Wellness < 360 Health Intelligence) instead of each card’s Take tags. S360 members see all assessment lanes.

= 0.1.29-poc =
* Wellness Timeline: title changed. Lanes now appear for every entitled assessment (RSI, Rapid, Keys, Complete Pillars, BSI) even before the first result. Each lane has two sub-rows (Actual on top, Baseline/Adjusted on bottom). Date axis added. Pin justified right. Today line positioned ~25% into the viewport on open.

= 0.1.28-poc =
* Assessment Timeline panel (Gantt-style) under the info panel. Lanes per entitled assessment that has at least one result. Origin is the first result date. Filled diamonds = actual completions; outlined diamonds = baseline cadence from first result; slightly stronger outlined diamonds = adjusted cadence from latest result. Default 6-month view with 1/3/6/9/12 month selector, horizontal scroll, bright today line positioned near 25% on open. Same membership tag gates and result tables as the assessment cards. Collapsible with pin, matching the Assessment Status panel.

= 0.1.27-poc =
* Info Panel graphic (spot_image) also renders under the nav rail when a path is set. Width limited to the rail; sits in the blank space below the links.

= 0.1.26-poc =
* Guided Path description: wpautop on output and white-space:pre-line so carriage returns and <br> render.

= 0.1.25-poc =
* Guided Path description in the info panel now renders safe HTML (links, bold, colors, font size, lists).
* Styles for the description block and its links.

= 0.1.24-poc =
* Assessment cards grouped with small uppercase headers and vertical dividers (same layout as wellbeing Systems snapshot).
* Info panel left, Wellness Index right on the top row.
* [bmf_assessment_status bare="1"] returns just the grouped cards for embedding.

= 0.1.21-poc =
* Swap Wellness Index / Info panel above Assessments.
* Assessments panel is now a collapsible accordion (Assessment Status) with pin-to-keep-open. Default closed.
* Group assessment cards by Complementary / Art of Wellness / 360 Health Intelligence (same as wellbeing file).
* Status note: "Statuses may take up to 1 hour for processing your results."


= 0.1.20-poc =
* Assessment cards: four optional WP Fusion tag fields (Take required/missing, Results required/missing). Comma-separated, any-logic.
* Available badge and primary Take button appear only when the date cadence is met and the Take tags are satisfied. Buttons themselves always show when the corresponding page is set. Empty tag fields impose no extra constraint.

= 0.1.19-poc =
* Info row prefers a guided-path step when the bmf-guided-path plugin is active.
* BioVoicePrint card date comes from the latest session group.
* Spot accepts a media image, an optional link, or a shortcode such as [bmf_wellness_index].
* Info panel default is the first matching Settings row. usermeta bmf_home_notice replaces it for that member. There is no dismiss.

= 0.1.14-poc =
* Assessments panel has a muted line: status and dates update after overnight scoring, usually within 24 hours.

= 0.1.13-poc =
* Help tour adds Your level, Light or dark, and Wellness Index. Those steps are on the home shortcode only.
* Restart with [bmf_help_tour_restart]. Copy stays in Settings → Member Home.

= 0.1.12-poc =
* Standalone [bmf_member_nav] Menu button is full width under 900px. Dashboard Menu stays compact.

= 0.1.11-poc =
* Under 900px the rail is a closed drawer. Menu opens it. Closes on a link, Close, the backdrop, or Escape.
* Same markup for [bmf_member_home] and [bmf_member_nav]. Desktop stays a left column.

= 0.1.10-poc =
* The rail shortcode loads the wellbeing stylesheet, so panel, inset, heading, and text classes work on pages that only have the nav.
* Saved theme applies to those panels on load. The sun/moon control is still a separate shortcode.

= 0.1.9-poc =
* [bmf_member_nav context="results"] shows full-results links and group dividers. The home shortcode rail stays site links only.
* Rail adds Educational Tools, Electronic Records, and Wearable Devices.

= 0.1.8-poc =
* Shared rail shortcode for other pages. Results links stay off the dashboard.

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
