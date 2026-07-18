document.addEventListener('DOMContentLoaded', function () {

    const details = document.getElementById('eform-details');
    let activeFilters = new Map();


    /* =========================
       Helpers
    ========================= */

    function isLikelyLink(value) {
        if (!value) return false;

        if (value.startsWith('http://') || value.startsWith('https://')) return true;
        if (value.startsWith('/wp-content/')) return true;

        return false;
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

                    let html = '<table class="eform-detail-table">';
                    html += '<tr><th>Field</th><th>Value</th></tr>';

                    res.data.forEach(item => {

                        let value = item.value;

                        if (isLikelyLink(value)) {
                            let url = value.startsWith('http')
                                ? value
                                : window.location.origin + value;

                            value = `<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`;
                        }

                        let label = item.label || item.key;

                        html += `
                        <tr>
                            <td class="eform-filterable"
                                data-key="${item.key}"
                                title="Click to filter by ${label}">
                                ${label}
                            </td>
                            <td>${value}</td>
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

        // remove existing popup
        document.querySelectorAll('.eform-popup').forEach(p => p.remove());

        let html = `<div class="eform-popup">
            <h4>Filter by ${key}</h4>
            <select id="eform-value-select">`;

        values.forEach(val => {
            html += `<option value="${val}">${val}</option>`;
        });

        html += `</select>
            <button id="apply-filter">Apply</button>
        </div>`;

        let container = document.createElement('div');
        container.innerHTML = html;

        let target = document.getElementById('eform-filter-panel');

        if (target) {
            target.innerHTML = ''; // clear previous
            target.appendChild(container);
        } else {
            // fallback if container not present
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

        // ✅ Reset to page 1 when filters change
        wrapper.dataset.page = 1;

        fetchFilteredResults(wrapper);
    }

    function fetchFilteredResults(wrapper) {

        let formName = wrapper.dataset.form;
        let filters = activeFilters.get(wrapper) || {};
        let page = wrapper.dataset.page || 1;
        let rows = wrapper.dataset.rows || 10;
console.log('ROWS SENT:', rows);
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
                html += `<td>${row[key] || ''}</td>`;
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


    function getCurrentPage(wrapper) {
        return parseInt(wrapper.dataset.page || 1);
    }

    function setCurrentPage(wrapper, page) {
        wrapper.dataset.page = page;
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

    document.addEventListener('click', function(e) {

        // NEXT
        if (e.target.classList.contains('eform-next')) {

            e.preventDefault();
            e.stopPropagation();

            let wrapper = e.target.closest('.eform-wrapper');
            if (!wrapper) return;

            let currentPage = parseInt(wrapper.dataset.page || 1);
            let nextPage = currentPage + 1;

            wrapper.dataset.page = nextPage;

            fetchFilteredResults(wrapper);
        }

        // PREV
        if (e.target.classList.contains('eform-prev')) {

            e.preventDefault();
            e.stopPropagation();

            let wrapper = e.target.closest('.eform-wrapper');
            if (!wrapper) return;

            let currentPage = parseInt(wrapper.dataset.page || 1);
            let prevPage = Math.max(1, currentPage - 1);

            wrapper.dataset.page = prevPage;

            fetchFilteredResults(wrapper);
        }

    });

    /* =========================
       Prevent link triggering row click
    ========================= */

    document.addEventListener('click', function (e) {
        if (e.target.tagName === 'A') {
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

        // ✅ find which table this came from
        let wrapper = document.querySelector('.eform-wrapper .eform-row.active')?.closest('.eform-wrapper');

        if (!wrapper) {
            console.warn('No active wrapper found');
            return;
        }

        fetchFilterValues(key, wrapper);
    });

    /* =========================
       INIT
    ========================= */

    // Initialize page = 1 for each table
    document.querySelectorAll('.eform-wrapper').forEach(wrapper => {
        wrapper.dataset.page = 1;
    });    

    attachRowClickHandlers();

});