jQuery(document).ready(function($) {
    function loadFlowList() {
        const filterType = $('#flow-filter-type').val();
        const searchTerm = $('#flow-search').val();

        $('#breathermae-flow-table-container').html('<p class="loading">Loading...</p>');

        $.post(breathermaeFlows.ajaxurl, {
            action: 'breathermae_get_flow_list',
            filter_type: filterType,
            search: searchTerm
        }, function(response) {
            if (response.success) {
                $('#breathermae-flow-table-container').html(response.data.html);
            } else {
                $('#breathermae-flow-table-container').html('<p>Error loading data.</p>');
            }
        });
    }

    loadFlowList();

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