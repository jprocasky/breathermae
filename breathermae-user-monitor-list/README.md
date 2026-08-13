# BreatherMae User Monitor List

Internal user dashboard plugin for BreatherMae. Provides a flexible shortcode-powered table view of registered users with last activity from your existing `live-user-monitor` plugin and dynamic status indicators from WP Fusion tags.

Perfect for internal/administrative pages protected by WP Fusion tag-based access control. Works seamlessly with Elementor Pro.

## Features

- **Core columns**: Username, First Name, Last Name, Last Visit Date, Last Page Visited
- **Dynamic status columns** via shortcode parameter (green check / empty box using Dashicons)
- **Switchable fields**: IP Address and Geo Location from usermeta written by live-user-monitor
- **AJAX searchable + paginated** table (Prev/Next + page numbers, no full page reload)
- **Correct totals** even when using the `exclude` tag filter
- **One-click CSV Export** — ideal for feeding into your Excel VBA backend
- **Default sort**: Last Visit Date (newest first)
- Clean, professional styling that fits Elementor sections
- No external dependencies beyond WP Fusion and your existing live-user-monitor plugin

## Version 1.4.0 changes

- Fixed pagination (previously total was calculated only on the current limited page → UI never appeared)
- AJAX search & pagination (only the results area refreshes)
- Prev / Next buttons + smart page number window + “Showing X–Y of Z”
- Loading spinner while fetching
- Separated JS into its own file
- JS disabled in Elementor editor / preview (no accidental refreshes while editing)

## Installation

1. Copy the `breathermae-user-monitor-list` folder into `wp-content/plugins/`
2. Activate the plugin via **Plugins > Installed Plugins**

## Shortcode Usage

### Basic Example

```
[user_monitor_list]
```

### Full Example with Status Tags + IP/Geo

```
[user_monitor_list 
  status_tags="RSI|RSI_COMPLETE, BSI|BSI_COMPLETE, 8Pillars|8_PILLARS_COMPLETE" 
  exclude="TEST,STAFF"
  show_ip="1" 
  show_geo="1" 
  per_page="50"
]
```

### Parameter Reference

| Parameter     | Example Value                                      | Description |
|---------------|----------------------------------------------------|-----------|
| `status_tags` | `RSI\|RSI_COMPLETE, BSI\|BSI_COMPLETE`            | Comma-separated list of `Label\|WP_Fusion_Tag_Slug`. Creates one checkbox-style column per pair. |
| `exclude`     | `TEST,STAFF`                                      | Comma-separated WP Fusion tags. Users who have *any* of these tags are hidden. |
| `show_ip`     | `1` or `0`                                         | Show/hide IP Address column |
| `show_geo`    | `1` or `0`                                         | Show/hide Geo Location column |
| `per_page`    | `50` (default), `25`, `100`, up to `200`           | Rows per page |
| `search`      | (optional)                                         | Pre-fill the search box |

**Note on `status_tags`**: The pipe `|` separates display label from the actual WP Fusion tag slug. Use exact tag slugs as they appear in WP Fusion.

## How Status Columns Work

- Uses `wp_fusion()->user->has_tag()` when available
- Falls back through `zoho_tags`, `multi-tags` / `mulit-tags`, and `wpf_tags` usermeta
- Checked = green dashicons-yes
- Unchecked = gray dashicons-minus

## Data Source

All activity data comes from persistent usermeta written by the `live-user-monitor` plugin:

- `_breathermae_last_active`
- `_breathermae_last_page_url`
- `_breathermae_last_ip`
- `_breathermae_last_geo`

No direct dependency on the live sessions table.

## Recommended Page Setup

1. Create a new Page called **“Internal User Monitor”** (or similar)
2. Protect it with a WP Fusion tag (e.g. `staff_internal`, `admin_access`)
3. (Optional) Build the page layout in **Elementor Pro**
4. Add a **Shortcode** widget and paste the `[user_monitor_list ...]` code
5. Publish

The shortcode is self-contained and mobile-responsive. Search and pagination use AJAX so the rest of the page (and any Elementor chrome) stays put. Interactive JS is automatically disabled inside the Elementor editor/preview.

## CSV Export

The **Export CSV** button generates a clean CSV file named with today’s date. Status columns export as `✓` or `☐` so they import nicely into Excel for your VBA processing.

## Notes on performance

Because the `exclude` filter must check WP Fusion tags (which can live in several meta keys), the plugin loads the full search-matching set, filters in PHP, then slices for the current page. This is intentional and correct for an internal staff list. If you ever have tens of thousands of users and heavy exclude usage, we can add a different strategy later.

## Support

Maintained as part of the BreatherMae plugin ecosystem.  
Repo: https://github.com/jprocasky/breathermae

---

**Created for Jeff Procasky / BreatherMae** — July 2026 · AJAX paging Aug 2026
