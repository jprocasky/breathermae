Wiring notes (temporary)

Panels need:
1. Button: <button type="button" class="bmf-qa-export bmf-qa-export-pdf" disabled>Export PDF</button>
2. On data load: window.bmfQaSetPdfPayload(root, {title, member, metaLines, headers, rows, filename})
3. On clear: window.bmfQaSetPdfPayload(root, null)
4. Echo bmf_qa_pdf_script() once in shortcode output
