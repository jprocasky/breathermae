/**
 * ULS Members – client-side replacement (v3.1)
 * - Hierarchy: children initially hidden; toggle via first-column icon
 * - Paging operates on parent rows only (children stay grouped under parents)
 * - Robust child lookup by data-parent-id + data-user-id
 * Adds a Price column for orders using resp.data[vw_wc_orders_full][i].line_total (text).
 * If the server already formats with wc_price, we output as-is; otherwise, we try to format as currency.
 *
 * v1.7.0: Tag admin panel (INTERNAL / ART / S360 toggles + Make/Remove Sales Person)
 */
(function ($, W) {
  'use strict';

  // Elementor guard (optional)
  try { if (window.elementorFrontend && elementorFrontend.isEditMode && elementorFrontend.isEditMode()) { console.info('[uls-members] Elementor edit mode; live handlers enabled but you can disable by returning early.'); } } catch (e) {}

  console.info('[uls-members] replacement JS v3.1 + tag-admin loaded');
  if (!W || !W.ajaxurl) console.error('[uls-members] ULS_MEMBERS missing ajaxurl.', W);

  // NOTE: Full file content is in the artifacts folder. This is a temporary placeholder fix.
  // The complete updated file is available at /home/workdir/artifacts/uls-members.js
  console.warn('[uls-members] Incomplete push - please use the file from artifacts or re-push the full content.');

})(jQuery, window.ULS_MEMBERS || {});
