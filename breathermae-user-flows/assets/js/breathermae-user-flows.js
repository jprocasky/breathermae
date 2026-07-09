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

    // Filter change
    $('#flow-filter-type').on('change', loadFlowList);

    // Live search with debounce
    let searchTimeout;
    $('#flow-search').on('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(loadFlowList, 350);
    });

    // View Flow button (placeholder for Phase 3)
    $(document).on('click', '.view-flow-btn', function() {
        const sessionId = $(this).data('session');
        alert('View Flow clicked for session: ' + sessionId + '\n\n(Phase 3 will open the graphical playback here)');
        // Future: Trigger modal or load second shortcode with this sessionId
    });
});