<?php
/**
 * Shared PDF export helper for Q&A / Extremes panels.
 * Opens a print-ready document (logo + table) so the browser can Save as PDF.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'bmf_qa_pdf_logo_url' ) ) {
	function bmf_qa_pdf_logo_url(): string {
		$url = wp_get_attachment_url( 6618 );
		if ( ! $url ) {
			$url = 'https://breathermae.com/wp-content/uploads/2025/11/Article-1-Image-1-e1764384968248.jpeg';
		}
		return (string) apply_filters( 'bmf_qa_pdf_logo_url', $url );
	}
}

if ( ! function_exists( 'bmf_qa_pdf_script' ) ) {
	/**
	 * Inline once-per-page PDF helper. Safe to call from multiple shortcodes.
	 */
	function bmf_qa_pdf_script(): string {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;

		$logo = bmf_qa_pdf_logo_url();

		obj_start();
		?>
<script>
(function(){
	if (window.bmfQaExportPdf) return;
	var LOGO = <?php echo wp_json_encode( $logo ); ?>;

	function esc(s){
		var d=document.createElement('div');
		d.textContent = s==null ? '' : String(s);
		return d.innerHTML;
	}

	/**
	 * opts: {
	 *   title, member, metaLines: string[],
	 *   headers: string[],
	 *   rows: array of string[] (same length as headers),
	 *   sectionRows: optional [{label, colSpan}] markers interleaved — use null cells
	 *   note: optional footer note,
	 *   filename: suggested name (shown in print dialog title)
	 * }
	 */
	window.bmfQaExportPdf = function(opts){
		opts = opts || {};
		var title = opts.title || 'BreatherMae Report';
		var member = opts.member || '';
		var meta = opts.metaLines || [];
		var headers = opts.headers || [];
		var rows = opts.rows || [];
		var note = opts.note || '';
		var filename = opts.filename || 'breathermae-report';

		if (!rows.length) {
			alert('Nothing to export yet.');
			return;
		}

		var html = '<!DOCTYPE html><html><head><meta charset="utf-8">';
		html += '<title>' + esc(filename) + '</title>';
		html += '<style>';
		html += 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1e293b;margin:24px;font-size:11px;}';
		html += '.brand{display:flex;align-items:center;gap:14px;margin-bottom:16px;border-bottom:2px solid #001d50;padding-bottom:12px;}';
		html += '.brand img{max-height:48px;max-width:160px;object-fit:contain;}';
		html += '.brand h1{margin:0;font-size:16px;color:#001d50;}';
		html += '.brand .sub{margin:2px 0 0;color:#64748b;font-size:11px;}';
		html += '.meta{margin:0 0 14px;color:#475569;}';
		html += '.meta strong{color:#001d50;}';
		html += 'table{width:100%;border-collapse:collapse;margin-top:6px;}';
		html += 'th,td{border:1px solid #cbd5e1;padding:5px 7px;text-align:left;vertical-align:top;}';
		html += 'th{background:#6ec1e4;color:#001d50;font-weight:600;}';
		html += 'tr.section td{background:#f1f5f9;font-weight:600;color:#001d50;}';
		html += 'tr.extreme td{background:#fef2f2;}';
		html += 'tr.extreme td.answer{color:#991b1b;font-weight:600;}';
		html += '.note{margin-top:14px;font-size:10px;color:#64748b;}';
		html += '@media print{body{margin:12px;} .no-print{display:none;}}';
		html += '</style></head><body>';

		html += '<div class="brand">';
		if (LOGO) html += '<img src="' + esc(LOGO) + '" alt="BreatherMae">';
		html += '<div><h1>' + esc(title) + '</h1>';
		if (member) html += '<div class="sub">Member: ' + esc(member) + '</div>';
		html += '</div></div>';

		if (meta.length) {
			html += '<div class="meta">';
			meta.forEach(function(line){ html += '<div>' + esc(line) + '</div>'; });
			html += '</div>';
		}

		html += '<table><thead><tr>';
		headers.forEach(function(h){ html += '<th>' + esc(h) + '</th>'; });
		html += '</tr></thead><tbody>';

		rows.forEach(function(r){
			if (r && r._section) {
				html += '<tr class="section"><td colspan="' + headers.length + '">' + esc(r.label || '') + '</td></tr>';
				return;
			}
			var extreme = !!(r && r._extreme);
			var cells = (r && r.cells) ? r.cells : (Array.isArray(r) ? r : []);
			html += '<tr' + (extreme ? ' class="extreme"' : '') + '>';
			cells.forEach(function(c, i){
				var cls = (extreme && headers[i] && /answer/i.test(headers[i])) ? ' class="answer"' : '';
				html += '<td' + cls + '>' + esc(c == null ? '' : c) + '</td>';
			});
			html += '</tr>';
		});

		html += '</tbody></table>';
		if (note) html += '<p class="note">' + esc(note) + '</p>';
		html += '<p class="note no-print">Use your browser’s print dialog → Save as PDF.</p>';
		html += '</body></html>';

		var w = window.open('', '_blank');
		if (!w) {
			alert('Pop-up blocked. Allow pop-ups for this site to export PDF.');
			return;
		}
		w.document.open();
		w.document.write(html);
		w.document.close();
		// Wait for logo image, then print
		setTimeout(function(){
			try { w.focus(); w.print(); } catch(e) {}
		}, 400);
	};
})();
</script>
			<?php
			return ob_get_clean();
	}
}
