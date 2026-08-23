=== BMF SQL Edit ===
Contributors: blackmountainfactory
Tags: sql, database, developer, tools, admin
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Developer SQL console for WordPress. Lite (free) is read-only; Pro unlocks write queries and inline cell editing.

== Description ==

BMF SQL Edit gives developers and site admins a convenient SQL console inside the WordPress admin (Tools menu).

**Lite (free)**
* SELECT, DESCRIBE, SHOW, EXPLAIN
* Result grid with horizontal scroll sync
* CSV export
* Query history
* Recent tables list with Clear (x) control
* All WP tables browser with live filter

**Pro**
* Write queries (INSERT / UPDATE / DELETE / DDL) when explicitly allowed
* Inline cell editing (double-click) with automatic primary-key detection
* Auto-run updates and Auto-add PK helpers
* License-controlled access with daily status checks

License keys are managed under Tools - BMF SQL Edit.

== Installation ==

1. Upload the `bmf-sql-edit` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins screen
3. Go to Tools - BMF SQL Edit
4. (Optional) Enter a Pro license key under Tools - BMF SQL Edit (settings)

For local emergency override you may define `BMSE_FORCE_PRO` as true in `wp-config.php`.

== Frequently Asked Questions ==

= Why are write controls disabled? =
You are running the free Lite edition. Activate a valid Pro license to unlock write queries and edit mode.

= Does the plugin drop data on uninstall? =
No. The query-history table is retained by default. You can enable the DROP statement in `uninstall.php` if you prefer a clean removal.

= Which license server is used? =
The plugin talks to the Software License for WooCommerce (NSP-CODE SRL) API hosted on blackmountainfactory.com.

== Changelog ==

= 1.2.0 =
* Renamed from Breathermae SQL Edit to BMF SQL Edit (Black Mountain Factory)
* Added Lite / Pro gating with Software License for WooCommerce integration
* Daily license status polling (active / suspended / expired / etc.)
* Enhanced Clear Recents control (x icon + label) on the recent-tables pane
* Proper readme, GPL headers, and code documentation
* Settings page for license key + toolbar defaults

= 1.1.7-beta =
* Previous Breathermae release (history, edit mode, CSV export, etc.)

== Upgrade Notice ==

= 1.2.0 =
Branding and folder name changed to bmf-sql-edit. Re-activate the new plugin after upload; previous history table is compatible.
