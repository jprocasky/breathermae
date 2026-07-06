
jQuery(document).ready(function($){
  console.log('Live User Monitor JS loaded');

  // Detect which widgets are present on the page
  var hasTable = document.getElementById('live-user-table') !== null;
  var hasGauge = document.getElementById('active-sessions-gauge') !== null;

  let sessionIntervalId = null;
  let tableIntervalId = null;
  let gaugeIntervalId = null;

  function updateSession(){
    $.post(lum_ajax.ajax_url, { action: 'update_session', page_url: window.location.href })
      .fail(function(xhr){ console.error('Update session AJAX error:', xhr && xhr.responseText); });
  }

  function fetchSessions(){
    if (!hasTable) return; // hard guard
    $.post(lum_ajax.ajax_url, { action: 'get_sessions' }, function(response){
      if(response && response.success){
        var rows = Array.isArray(response.data) ? response.data : [];
        var html = '\n<table>\n<tr>\n<th>User</th>\n<th>Email</th>\n<th>Page</th>\n<th>IP</th>\n<th>Device</th>\n<th>Location</th>\n<th>Last Seen</th>\n</tr>\n';
        $.each(rows, function(i, session){
          html += '<tr>'+
                  '<td>'+ (session.username || '') +'</td>'+
                  '<td>'+ (session.email || '') +'</td>'+
                  '<td>'+ (session.page_url || '') +'</td>'+
                  '<td>'+ (session.ip_address || '') +'</td>'+
                  '<td>'+ (session.device_info || '') +'</td>'+
                  '<td>'+ (session.geo_location || '') +'</td>'+
                  '<td>'+ (session.last_seen || '') +'</td>'+
                  '</tr>';
        });
        html += '</table>';
        $('#live-user-table').html(html);
      } else {
        $('#live-user-table').html('<div>Error loading active users.</div>');
      }
    }).fail(function(xhr){
      if (hasTable) $('#live-user-table').html('<div>Error loading active users.</div>');
    });
  }

  function updateGauge(){
    if (!hasGauge) return; // hard guard

    // Only try Plotly if it exists and the target node is present
    var gaugeEl = document.getElementById('active-sessions-gauge');
    if (!gaugeEl) return;

    $.post(lum_ajax.ajax_url, { action: 'get_active_count' }, function(response){
      if(response && response.success){
        var count = Number(response.data && response.data.count) || 0;
        var maxRange = Math.max(Math.ceil(count * 1.5), 10);
        var data = [{
          type: 'indicator',
          mode: 'gauge+number',
          value: count,
          title: { text: 'Active Sessions' },
          gauge: {
            axis: { range: [0, maxRange] },
            bar: { color: '#1f77b4' },
            steps: [
              { range: [0, maxRange * 0.25], color: '#d4e6f1' },
              { range: [maxRange * 0.25, maxRange * 0.5], color: '#a9cce3' },
              { range: [maxRange * 0.5, maxRange * 0.75], color: '#5dade2' },
              { range: [maxRange * 0.75, maxRange], color: '#2e86c1' }
            ]
          }
        }];

        if (window.Plotly && typeof window.Plotly.newPlot === 'function') {
          window.Plotly.newPlot(gaugeEl, data, {margin:{t:30,b:10,l:20,r:20}}, {displayModeBar: false});
        } else {
          // If Plotly isn't available, silently skip to avoid console noise
          // Optional: show a minimal fallback message once
          if (!gaugeEl.getAttribute('data-fallback-shown')){
            gaugeEl.setAttribute('data-fallback-shown','1');
            gaugeEl.innerHTML = '<div style="font:14px/1.4 system-ui, sans-serif; color:#666;">Gauge unavailable.</div>';
          }
        }
      } else {
        if (hasGauge) $('#active-sessions-gauge').html('<div>Error loading gauge.</div>');
      }
    }).fail(function(){
      if (hasGauge) $('#active-sessions-gauge').html('<div>Error loading gauge.</div>');
    });
  }

  // Always keep the session alive (site-wide)
  updateSession();
  sessionIntervalId = setInterval(updateSession, 10000);

  // Only poll/paint the components that actually exist on the current page
  if (hasTable){
    fetchSessions();
    tableIntervalId = setInterval(fetchSessions, 5000);
  }
  if (hasGauge){
    updateGauge();
    gaugeIntervalId = setInterval(updateGauge, 5000);
  }
});
