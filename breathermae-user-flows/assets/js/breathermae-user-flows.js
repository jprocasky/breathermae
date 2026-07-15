jQuery(document).ready(function($) {
    
    let currentPage = 1;
    let perPage = 25;

    function updatePagination(data) {
        const pagination = $('#breathermae-flow-pagination');
        if (data.total_pages <= 1) {
            pagination.hide();
            return;
        }
        pagination.show();
        $('#current-page-info').text(`Page ${data.current_page} of ${data.total_pages} (${data.total} total)`);
        
        $('#prev-page').prop('disabled', data.current_page <= 1);
        $('#next-page').prop('disabled', data.current_page >= data.total_pages);
    }    

    function loadFlowList() {
        const filterType = $('#flow-filter-type').val();
        const searchTerm = $('#flow-search').val();

        $('#breathermae-flow-table-container').html('<p class="loading">Loading...</p>');

        $.post(breathermaeFlows.ajaxurl, {
            action: 'breathermae_get_flow_list',
            filter_type: filterType,
            search: searchTerm,
            page: currentPage,
            per_page: perPage
        }, function(response) {
            if (response.success) {
                $('#breathermae-flow-table-container').html(response.data.html);
                currentPage = response.data.current_page;
                updatePagination(response.data);
            } else {
                $('#breathermae-flow-table-container').html('<p>Error loading data.</p>');
            }
        });
    }

    loadFlowList();

    $('#prev-page').on('click', function() {
        if (currentPage > 1) {
            currentPage--;
            loadFlowList();
        }
    });

    $('#next-page').on('click', function() {
        currentPage++;
        loadFlowList();
    });

    $('#per-page').on('change', function() {
        perPage = parseInt($(this).val());
        currentPage = 1;
        loadFlowList();
    });

    $('#flow-filter-type').on('change', loadFlowList);
    let searchTimeout;
    $('#flow-search').on('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(loadFlowList, 350);
    });

    // === View Flow wiring ===
    $(document).on('click', '.view-flow-btn', function() {
        const sessionId = $(this).data('session');
        if (!sessionId) {
            console.error('No session_id on button');
            return;
        }

        $('#breathermae-flow-table-container').hide();
        $('#flow-viz-area').show();

        // Set the data attribute on the container
        const vizContainer = document.getElementById('viz-flow-container');
        if (vizContainer) {
            vizContainer.setAttribute('data-session-id', sessionId);
        }

        // Give a tiny moment for the script to be fully ready
        setTimeout(() => {
            if (typeof window.BreatherMaeFlowViz !== 'undefined' && typeof window.BreatherMaeFlowViz.loadSessionFlow === 'function') {
                console.log('Calling loadSessionFlow with:', sessionId);
                window.BreatherMaeFlowViz.loadSessionFlow(sessionId);
            } else {
                console.error('BreatherMaeFlowViz still not ready after delay');
            }
        }, 150);
    });

    $(document).on('click', '#back-to-list', function() {
        $('#flow-viz-area').hide();
        $('#breathermae-flow-table-container').show();
        // Optional: clear the viz container for next time
        $('#viz-flow-container').html('<p class="loading">Ready for next session...</p>');
    });
});