document.addEventListener('DOMContentLoaded', function () {

    const details = document.getElementById('eform-details');
    let activeFilters = new Map();
    let currentFormName = '';
    let currentSubmissionId = 0;

    /* =========================
       Helpers
    ========================= */

    function isLikelyLink(value) {
        if (!value) return false;
        if (value.startsWith('http://') || value.startsWith('https://')) return true;
        if (value.startsWith('/wp-content/')) return true;
        return false;
    }

    function isEmail(value) {
        if (!value || typeof value !== 'string') return false;
        // Simple but practical email check
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* =========================
       Row Click Handler
    ========================= */

    function attachRowClickHandlers() {
        document.querySelectorAll('.eform-row').forEach(row => {
            row.addEventListener('click', function () {
                document.querySelectorAll('.eform-row').forEach(r => r.classList.remove('active'));
                this.classList.add('active');

                if (!details) return;

                let id = this.dataset.id;
                details.innerHTML = '<p>Loading...</p>';

                fetch(eform_ajax.ajax_url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:
                        'action=get_submission_details' +
                        '&submission_id=' + encodeURIComponent(id) +
                        '&nonce=' + encodeURIComponent(eform_ajax.nonce)
                })
                .then(res => res.json())
                .then(res => {
                    if (!res.success) {
                        details.innerHTML = '<p>Error loading details.</p>';
                        return;
                    }

                    // New response shape: { fields, form_name, submission_id }
                    const payload = res.data;
                    const fields = payload.fields || payload; // backward compatible
                    currentFormName = payload.form_name || '';
                    currentSubmissionId = payload.submission_id || id;

                    let html = '<table class="eform-detail-table">';
                    html += '<tr><th>Field</th><th>Value</th></tr>';

                    fields.forEach(item => {
                        let value = item.value;
                        let displayValue = escapeHtml(value);

                        if (isLikelyLink(value)) {
                            let url = value.startsWith('http')
                                ? value
                                : window.location.origin + value;
                            displayValue = `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(url)}</a>`;
                        }

                        // Email detection + Reply button
                        if (isEmail(value) && eform_ajax.can_send) {
                            displayValue = `
                                <span class="eform-email-value">${escapeHtml(value)}</span>
                                <button type="button"
                                        class="eform-reply-btn"
                                        data-email="${escapeHtml(value)}"
                                        title="Send email reply">
                                    ✉ Reply
                                </button>`;
                        }

                        let label = item.label || item.key;

                        html += `
                        <tr>
                            <td class="eform-filterable"
                                data-key="${escapeHtml(item.key)}"
                                title="Click to filter by ${escapeHtml(label)}">
                                ${escapeHtml(label)}
                            </td>
                            <td>${displayValue}</td>
                        </tr>`;
                    });

                    html += '</table>';
                    details.innerHTML = html;
                })
                .catch(() => {
                    details.innerHTML = '<p>Request failed.</p>';
                });
            });
        });
    }

    /* =========================
       FILTER: Get Values
    ========================= */

    function fetchFilterValues(key, wrapper) {
        fetch(eform_ajax.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:
                'action=eform_get_values' +
                '&key=' + encodeURIComponent(key) +
                '&nonce=' + encodeURIComponent(eform_ajax.nonce)
        })
        .then(res => res.json())
        .then(res => {
            if (!res.success) return;
            renderFilterPopup(key, res.data, wrapper);
        });
    }

    /* =========================
       FILTER: Popup
    ========================= */

    function renderFilterPopup(key, values, wrapper) {
        document.querySelectorAll('.eform-popup').forEach(p => p.remove());

        let html = `<div class="eform-popup">
            <h4>Filter by ${escapeHtml(key)}</h4>
            <select id="eform-value-select">`;

        values.forEach(val => {
            html += `<option value="${escapeHtml(val)}">${escapeHtml(val)}</option>`;
        });

        html += `</select>
            <button id="apply-filter">Apply</button>
        </div>`;

        let container = document.createElement('div');
        container.innerHTML = html;

        let target = document.getElementById('eform-filter-panel');
        if (target) {
            target.innerHTML = '';
            target.appendChild(container);
        } else {
            document.body.appendChild(container);
        }

        document.getElementById('apply-filter').addEventListener('click', function () {
            let value = document.getElementById('eform-value-select').value;
            applyFilter(key, value, wrapper);
            container.remove();
        });
    }

    /* =========================
       FILTER: Apply + Fetch
    ========================= */
    function applyFilter(key, value, wrapper) {
        if (!activeFilters.has(wrapper)) {
            activeFilters.set(wrapper, {});
        }
        let filters = activeFilters.get(wrapper);
        filters[key] = value;
        wrapper.dataset.page = 1;
        fetchFilteredResults(wrapper);
    }

    function fetchFilteredResults(wrapper) {
        let formName = wrapper.dataset.form;
        let filters = activeFilters.get(wrapper) || {};
        let page = wrapper.dataset.page || 1;
        let rows = wrapper.dataset.rows || 10;

        fetch(eform_ajax.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:
                'action=eform_filter_submissions' +
                '&form_name=' + encodeURIComponent(formName) +
                '&filters=' + encodeURIComponent(JSON.stringify(filters)) +
                '&page=' + encodeURIComponent(page) +
                '&rows=' + encodeURIComponent(rows) +
                '&nonce=' + encodeURIComponent(eform_ajax.nonce)
        })
        .then(res => res.json())
        .then(res => {
            if (!res.success) return;
            updateResultsTable(res.data, wrapper);
        });
    }

    /* =========================
       TABLE UPDATE
    ========================= */

    function updateResultsTable(data, wrapper) {
        const tableBody = wrapper.querySelector('.eform-table tbody');
        if (!tableBody) return;

        if (!data || Object.keys(data).length === 0) {
            tableBody.innerHTML = '<tr><td colspan="10">No results</td></tr>';
            return;
        }

        let html = '';
        data.forEach(row => {
            let id = row.id;
            html += `<tr class="eform-row" data-id="${id}">`;
            wrapper.querySelectorAll('.eform-table thead th').forEach(th => {
                let key = th.dataset.key;
                html += `<td>${escapeHtml(row[key] || '')}</td>`;
            });
            html += `</tr>`;
        });

        tableBody.innerHTML = html;
        let pageDisplay = wrapper.querySelector('.eform-page');
        if (pageDisplay) {
            pageDisplay.innerText = wrapper.dataset.page || 1;
        }
        attachRowClickHandlers();
    }

    /* =========================
       Sync Button
    ========================= */

    document.addEventListener('click', function (e) {
        if (!e.target.classList.contains('eform-sync-btn')) return;

        const button = e.target;
        const formName = button.dataset.form;
        const status = button.parentElement.querySelector('.eform-sync-status');

        button.disabled = true;
        status.innerHTML = 'Syncing...';

        fetch(eform_ajax.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:
                'action=eform_run_sync' +
                '&form_name=' + encodeURIComponent(formName) +
                '&nonce=' + encodeURIComponent(eform_ajax.nonce)
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                status.innerHTML = `<span style="color:green;">${res.data}</span>`;
            } else {
                status.innerHTML = `<span style="color:red;">Error: ${res.data}</span>`;
            }
            button.disabled = false;
        })
        .catch(() => {
            status.innerHTML = '<span style="color:red;">Request failed.</span>';
            button.disabled = false;
        });
    });

    /* =========================
       Pagination
    ========================= */

    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('eform-next')) {
            e.preventDefault();
            e.stopPropagation();
            let wrapper = e.target.closest('.eform-wrapper');
            if (!wrapper) return;
            let currentPage = parseInt(wrapper.dataset.page || 1);
            wrapper.dataset.page = currentPage + 1;
            fetchFilteredResults(wrapper);
        }

        if (e.target.classList.contains('eform-prev')) {
            e.preventDefault();
            e.stopPropagation();
            let wrapper = e.target.closest('.eform-wrapper');
            if (!wrapper) return;
            let currentPage = parseInt(wrapper.dataset.page || 1);
            wrapper.dataset.page = Math.max(1, currentPage - 1);
            fetchFilteredResults(wrapper);
        }
    });

    /* =========================
       Prevent link / button from triggering row click
    ========================= */

    document.addEventListener('click', function (e) {
        if (e.target.tagName === 'A' || e.target.closest('.eform-reply-btn')) {
            e.stopPropagation();
        }
    });

    /* =========================
       Click field -> filter
    ========================= */

    document.addEventListener('click', function (e) {
        let el = e.target.closest('.eform-filterable');
        if (!el) return;

        let key = el.dataset.key;
        let wrapper = document.querySelector('.eform-wrapper .eform-row.active')?.closest('.eform-wrapper');

        if (!wrapper) {
            console.warn('No active wrapper found');
            return;
        }
        fetchFilterValues(key, wrapper);
    });

    /* =========================
       REPLY EMAIL MODAL
    ========================= */

    const modal = document.getElementById('eform-reply-modal');
    const recipientEl = document.getElementById('eform-reply-recipient');
    const subjectEl = document.getElementById('eform-reply-subject');
    const bodyEl = document.getElementById('eform-reply-body');
    const quickStartEl = document.getElementById('eform-reply-quickstart');
    const includeCtaEl = document.getElementById('eform-include-cta');
    const ctaFields = document.getElementById('eform-cta-fields');
    const ctaTextEl = document.getElementById('eform-cta-text');
    const ctaUrlEl = document.getElementById('eform-cta-url');
    const statusEl = document.getElementById('eform-reply-status');
    const sendBtn = document.getElementById('eform-send-reply-btn');

    function populateQuickStarts() {
        if (!quickStartEl || !eform_ajax.quick_starts) return;
        quickStartEl.innerHTML = '';
        eform_ajax.quick_starts.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.body;
            opt.textContent = item.label;
            quickStartEl.appendChild(opt);
        });
    }

    function openReplyModal(email) {
        if (!modal || !eform_ajax.can_send) return;

        recipientEl.textContent = email;
        document.getElementById('eform-reply-submission-id').value = currentSubmissionId;
        document.getElementById('eform-reply-form-name').value = currentFormName;

        // Sensible default subject based on form name
        let defaultSubject = 'Re: Your message';
        const fn = (currentFormName || '').toLowerCase();
        if (fn.includes('career') || fn.includes('job') || fn.includes('apply') || fn.includes('application')) {
            defaultSubject = 'Update regarding your application – Breathermae';
        } else if (fn.includes('comment') || fn.includes('question') || fn.includes('feedback') || fn.includes('help')) {
            defaultSubject = 'Re: Your question / comment';
        }
        subjectEl.value = defaultSubject;

        bodyEl.value = '';
        includeCtaEl.checked = false;
        ctaFields.style.display = 'none';
        ctaTextEl.value = '';
        ctaUrlEl.value = '';
        statusEl.innerHTML = '';
        statusEl.className = 'eform-reply-status';

        populateQuickStarts();
        quickStartEl.value = '';

        modal.style.display = 'block';
        modal.setAttribute('aria-hidden', 'false');
        bodyEl.focus();
    }

    function closeReplyModal() {
        if (!modal) return;
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }

    // Open modal when Reply button clicked
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.eform-reply-btn');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        openReplyModal(btn.dataset.email);
    });

    // Close handlers
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('eform-modal-close') ||
            e.target.classList.contains('eform-modal-cancel') ||
            e.target.classList.contains('eform-modal-overlay')) {
            closeReplyModal();
        }
    });

    // Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && modal.style.display === 'block') {
            closeReplyModal();
        }
    });

    // CTA toggle
    if (includeCtaEl) {
        includeCtaEl.addEventListener('change', function () {
            ctaFields.style.display = this.checked ? 'block' : 'none';
        });
    }

    // Quick start loader
    if (quickStartEl) {
        quickStartEl.addEventListener('change', function () {
            if (this.value) {
                bodyEl.value = this.value;
            }
        });
    }

    // Send button
    if (sendBtn) {
        sendBtn.addEventListener('click', function () {
            const recipient = recipientEl.textContent.trim();
            const subject = subjectEl.value.trim();
            const body = bodyEl.value.trim();
            const includeCta = includeCtaEl.checked;
            const ctaText = ctaTextEl.value.trim();
            const ctaUrl = ctaUrlEl.value.trim();
            const submissionId = document.getElementById('eform-reply-submission-id').value;
            const formName = document.getElementById('eform-reply-form-name').value;

            statusEl.className = 'eform-reply-status';
            statusEl.innerHTML = '';

            if (!recipient) {
                statusEl.innerHTML = '<span class="error">No recipient.</span>';
                return;
            }
            if (!subject) {
                statusEl.innerHTML = '<span class="error">Subject is required.</span>';
                subjectEl.focus();
                return;
            }
            if (!body) {
                statusEl.innerHTML = '<span class="error">Message body is required.</span>';
                bodyEl.focus();
                return;
            }
            if (includeCta && (!ctaText || !ctaUrl)) {
                statusEl.innerHTML = '<span class="error">CTA requires both button text and URL.</span>';
                return;
            }

            sendBtn.disabled = true;
            sendBtn.textContent = 'Sending...';
            statusEl.innerHTML = '<span class="sending">Sending email...</span>';

            const params = new URLSearchParams();
            params.append('action', 'eform_send_reply');
            params.append('nonce', eform_ajax.nonce);
            params.append('submission_id', submissionId);
            params.append('form_name', formName);
            params.append('recipient', recipient);
            params.append('subject', subject);
            params.append('body', body);
            params.append('include_cta', includeCta ? '1' : '');
            params.append('cta_text', ctaText);
            params.append('cta_url', ctaUrl);

            fetch(eform_ajax.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            })
            .then(res => res.json())
            .then(res => {
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send Email';

                if (res.success) {
                    statusEl.innerHTML = '<span class="success">' + (res.data.message || 'Email sent!') + '</span>';
                    // Auto-close after a short delay
                    setTimeout(closeReplyModal, 1600);
                } else {
                    statusEl.innerHTML = '<span class="error">' + (res.data || 'Send failed.') + '</span>';
                }
            })
            .catch(() => {
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send Email';
                statusEl.innerHTML = '<span class="error">Request failed. Please try again.</span>';
            });
        });
    }

    /* =========================
       INIT
    ========================= */

    document.querySelectorAll('.eform-wrapper').forEach(wrapper => {
        wrapper.dataset.page = 1;
    });

    attachRowClickHandlers();
    populateQuickStarts();
});
