# Breathermae Internal Files Plugin

Internal file and document library management plugin for Breathermae.

## Features
- **Two shortcodes** for Elementor:
  - `[breathermae_internal_files context="SALES" admin_tag="your_admin_tag"]` — Renders the file list table with major/minor headers (General / Internal / Sharable), clickable descriptions opening a centered modal with long description, icon links for files (type-aware: Word/Excel/PDF/etc.) and videos.
  - `[breathermae_internal_file_form context="SALES" admin_tag="your_admin_tag"]` — Upload/Edit form (Short + Long desc, Graphic upload, Internal File+Video, Sharable File+Video). Shown only to users with the matching WP Fusion tag (or any logged-in if admin_tag omitted).
- **WP Fusion tag integration**: Uses `wpf_get_tags()` / `wp_fusion()->user->get_tags()` + fallback to `zoho_tags` / `wpf_tags` usermeta for multi-tag storage. Matches patterns from uls-custom and breathermae-user-monitor-list style.
- **Context-based organization**: Files grouped and filtered by `context` (SALES, CORPORATE, etc.). Shortcode param driven.
- **Admin-only edit/delete**: Edit button in list (for admins) populates the form on the same page for easy updates. Delete with confirmation + attachment cleanup.
- **Media Library storage**: Recommended approach — all uploaded files (graphic + docs) go to WP Media Library with proper metadata. Videos stored as YouTube unlisted URLs.
- **File size limits & validation**: Enforced server-side (filters: `bmif_max_graphic_bytes`, `bmif_max_file_bytes`, `bmif_allowed_exts`). Notes shown in form.
- **Clean, modular, shortcode-driven**: Follows the architecture and coding style of `uls-files.php` (class singleton, AJAX CRUD, nonce, can_* helpers) and clean shortcode containers from live-user-monitor / user-monitor-list patterns.
- **Controlled access**: List requires logged-in (page should be further gated via Elementor + WP Fusion conditions on context tag). Downloads can be switched to AJAX-controlled if stricter per-file rules needed later.
- **Modal for long descriptions**: Click short description → centered modal.
- **Icons**: Emoji-based file-type icons (easy to extend to SVGs).

## Installation
1. Copy the `breathermae-internal-files/` folder to `wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. (Optional but recommended) Add to your main plugin loader or mu-plugin if using multiple uls-/breathermae- modules.
4. Ensure WP Fusion is active for tag checks (graceful fallback exists).

## Usage in Elementor
- Create a page/section visible to users with e.g. "sales_team" WP Fusion tag (use Elementor conditions or WP Fusion content restrictions).
- Add the **List** shortcode in a section for all authorized users.
- Add the **Form** shortcode in a separate section/column, and apply WP Fusion tag condition so only admins (e.g. tag "sales_admin" or "internal_files_admin") see the upload/edit form.
- Both shortcodes on same page works perfectly — edit from list populates form.

Example:
```
[breathermae_internal_file_form context="SALES" admin_tag="sales_admin"]
[breathermae_internal_files context="SALES" admin_tag="sales_admin"]
```

## Database
- Custom table: `wp_breathermae_internal_files`
- Auto-created/upgraded on activation/init.
- Stores context, short/long desc, attachment IDs for graphic/internal/sharable files, video URLs, audit fields.

## Extending / Filters
- `bmif_max_graphic_bytes` (default 2MB)
- `bmif_max_file_bytes` (default 25MB)
- `bmif_allowed_exts`
- `bmif_allowed_graphic`
- `bmif_can_view` filter for custom view logic

## File Type Icons (current)
Graphic: image thumbnail  
Internal/Sharable Files: 📕 PDF, 📘 Word, 📗 Excel, 📙 PowerPoint, 📄 generic  
Videos: 🎥 

Easily customized in `internal-files.js` (getFileIconHTML) and CSS.

## Security Notes
- Nonce protected AJAX.
- Admin actions require matching WP Fusion tag.
- Attachments cleaned up on delete/replace.
- For even stricter file access (beyond page gating), uncomment the AJAX download handler in JS and extend `can_view` / add per-context tag checks.

## Future Enhancements (as needed)
- Per-file additional WP Fusion tag requirements
- Bulk import / CSV
- Version history for files
- Search / filter in list table
- AI summary integration (like uls-files + ai_document_types)

## Compatibility
- Elementor Pro + WP Fusion
- Follows Breathermae plugin patterns (breathermae-forms, uls-*, live-user-monitor)
- PHP 7.4+, WP 6.0+

## Author
Jeff Procasky — Breathermae (github.com/jprocasky/breathermae)

Report issues or request changes via the repo.
