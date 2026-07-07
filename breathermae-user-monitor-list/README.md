# BreatherMae User Monitor List

Internal user dashboard plugin for BreatherMae. Provides a flexible shortcode-powered table view of registered users with last activity from your existing `live-user-monitor` plugin and dynamic status indicators from WP Fusion tags.

Perfect for internal/administrative pages protected by WP Fusion tag-based access control. Works seamlessly with Elementor Pro.

## Features

- **Core columns**: Username, First Name, Last Name, Last Visit Date, Last Page Visited (automatically excludes `session-expired` entries)
- **Dynamic status columns** via shortcode parameter (green check / empty box using Dashicons)
- **Switchable fields**: IP Address and Geo Location pulled from your `live-user-monitor` table
- **Searchable** and **paginated** table (server-side)
- **One-click CSV Export** — ideal for feeding into your Excel VBA backend
- **Default sort**: Last Visit Date (newest first)
- Clean, professional styling that fits Elementor sections
- No external dependencies beyond WP Fusion and your existing live-user-monitor plugin

## Installation

1. Copy the `breathermae-user-monitor-list` folder into `wp-content/plugins/`
2. Activate the plugin via **Plugins > Installed Plugins**
3. (Optional but recommended) Add the folder to your GitHub repo under `https://github.com/jprocasky/breathermae`

## Configuration (Important First Step)

Open `breathermae-user-monitor-list.php` and locate this line near the top of the shortcode function:

```php
$live_table = $wpdb->prefix . 'live_user_monitor';
```

**Update `$live_table`** to exactly match the custom table created by your `live-user-monitor-fixed.php` plugin.

Common alternatives you may need:
- `wp_lum_activity`
- `wp_user_activity_log`
- `wp_live_sessions`

After changing, test the shortcode. The plugin will show a clear error if the table name is wrong.

You can also adjust column names (`last_page`, `timestamp`, `ip_address`, `geo_location`) in the SQL queries if your schema differs slightly.

## Shortcode Usage

### Basic Example

```php
[user_monitor_list]
```

### Full Example with Status Tags + IP/Geo

```php
[user_monitor_list 
  status_tags="RSI|RSI_COMPLETE, BSI|BSI_COMPLETE, 8Pillars|8_PILLARS_COMPLETE" 
  show_ip="1" 
  show_geo="1" 
  per_page="75"
]
```

### Parameter Reference

| Parameter       | Example Value                                      | Description |
|-----------------|----------------------------------------------------|-----------|
| `status_tags`   | `RSI\|RSI_COMPLETE, BSI\|BSI_COMPLETE`            | Comma-separated list of `Label\|WP_Fusion_Tag_Slug`. Creates one checkbox-style column per pair. |
| `show_ip`       | `1` or `0`                                         | Show/hide IP Address column from live monitor table |
| `show_geo`      | `1` or `0`                                         | Show/hide Geo Location column |
| `per_page`      | `50` (default), `25`, `100`, up to `200`           | Rows per page |
| `search`        | (optional)                                         | Pre-fill the search box |

**Note on `status_tags`**: The pipe `|` separates display label from the actual WP Fusion tag slug. Use exact tag slugs as they appear in WP Fusion.

## How Status Columns Work

- Uses `wp_fusion()->user->has_tag( $user_id, 'TAG_SLUG' )` when available (recommended)
- Falls back to checking the `wpf_tags` usermeta array (common WP Fusion storage)
- Checked = green <span class="dashicons dashicons-yes"></span> checkmark
- Unchecked = gray <span class="dashicons dashicons-minus"></span> minus icon

## Regarding the `fld_[slug]` usermeta View

You mentioned an existing view/structure where columns are named `fld_[page-slug]` and values are stored as `visit_count|last_timestamp` in usermeta.

**This plugin does not currently use it** because:
- The `live-user-monitor` table already provides a reliable "Last Page Visited" + timestamp
- The `fld_*` structure appears to be a more granular per-page visit counter (great for detailed analytics)

**Future enhancement possible**:
We can easily add another parameter e.g. `fld_columns="About Us|fld_about-us,Home|fld_home"` that would pull and parse those usermeta values into extra columns (showing visit count + optional last visit time).

Let me know if you want that added — it would be a small extension.

## Recommended Page Setup

1. Create a new Page called **"Internal User Monitor"** (or similar)
2. Protect it with a WP Fusion tag (e.g. `staff_internal`, `admin_access`)
3. (Optional) Build the page layout in **Elementor Pro**
4. Add a **Shortcode** widget and paste the `[user_monitor_list ...]` code
5. Publish

The shortcode is self-contained and mobile-responsive.

## CSV Export

The **Export CSV** button generates a clean CSV file named with today's date. Status columns export as `✓` or `☐` so they import nicely into Excel for your VBA processing.

## Extending / Customizing

- Want clickable last page links that go to the actual frontend URL?
- Want to filter by specific WP Fusion tags (only show users who have RSI_COMPLETE)?
- Want to pull additional fields from `uls-members` plugin?
- Add more columns from usermeta?

Just let me know — this plugin is designed to be extended.

## Support

Maintained as part of the BreatherMae plugin ecosystem.  
Repo: https://github.com/jprocasky/breathermae

---

**Created for Jeff Procasky / BreatherMae** — July 2026
