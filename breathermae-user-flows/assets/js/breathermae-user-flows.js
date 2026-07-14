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

    // Initial load
    loadFlowList();

    // Filter + Search
    $('#flow-filter-type').on('change', loadFlowList);
    let searchTimeout;
    $('#flow-search').on('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(loadFlowList, 350);
    });

    // Wire "View Flow" buttons
    $(document).on('click', '.view-flow-btn', function() {
        const sessionId = $(this).data('session');

        // Hide list, show viz area
        $('#breathermae-flow-table-container').hide();
        $('#flow-viz-area').show();

        // Load the graph
        if (typeof window.BreatherMaeFlowViz !== 'undefined' && window.BreatherMaeFlowViz.loadSessionFlow) {
            window.BreatherMaeFlowViz.loadSessionFlow(sessionId);
        } else {
            console.error('BreatherMaeFlowViz not loaded');
        }
    });

    // Back to list button
    $(document).on('click', '#back-to-list', function() {
        $('#flow-viz-area').hide();
        $('#breathermae-flow-table-container').show();
    });

    // Make sure the viz assets are available (they should already be enqueued when needed)
});