/**
 * BioVoicePrint – Scores dashboard (Chart.js radar).
 * Expects bmfBioVoiceScores.charts = { canvasId: { labels, values, colors } }
 */
(function () {
  'use strict';

  function bandColor(token) {
    var t = String(token || '').toLowerCase();
    if (t === 'green' || t === 'light_green') return 'rgba(52, 211, 153, 0.85)';
    if (t === 'yellow') return 'rgba(251, 191, 36, 0.85)';
    if (t === 'orange') return 'rgba(251, 146, 60, 0.85)';
    if (t === 'red' || t === 'light_red') return 'rgba(248, 113, 113, 0.85)';
    return 'rgba(56, 189, 248, 0.85)';
  }

  function boot() {
    if (typeof Chart === 'undefined' || !window.bmfBioVoiceScores || !bmfBioVoiceScores.charts) {
      return;
    }

    Object.keys(bmfBioVoiceScores.charts).forEach(function (id) {
      var cfg = bmfBioVoiceScores.charts[id];
      var el = document.getElementById(id);
      if (!el || !cfg || !cfg.labels || !cfg.labels.length) {
        return;
      }
      if (el._bmfChart) {
        return;
      }

      var values = (cfg.values || []).map(function (v) {
        return typeof v === 'number' ? v : parseFloat(v) || 0;
      });

      el._bmfChart = new Chart(el, {
        type: 'radar',
        data: {
          labels: cfg.labels,
          datasets: [
            {
              label: 'Pattern shift',
              data: values,
              borderColor: 'rgba(56, 189, 248, 0.95)',
              backgroundColor: 'rgba(56, 189, 248, 0.18)',
              borderWidth: 2,
              pointBackgroundColor: (cfg.colors || []).map(bandColor),
              pointBorderColor: '#0f172a',
              pointBorderWidth: 1,
              pointRadius: 4,
              pointHoverRadius: 5,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          scales: {
            r: {
              min: 0,
              max: 100,
              beginAtZero: true,
              ticks: {
                stepSize: 25,
                color: '#94a3b8',
                backdropColor: 'transparent',
                font: { size: 10 },
              },
              grid: { color: 'rgba(51, 65, 85, 0.9)' },
              angleLines: { color: 'rgba(51, 65, 85, 0.9)' },
              pointLabels: {
                color: '#e2e8f0',
                font: { size: 11, weight: '500' },
              },
            },
          },
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function (ctx) {
                  var v = ctx.parsed && typeof ctx.parsed.r === 'number' ? ctx.parsed.r : ctx.raw;
                  return 'Shift: ' + (typeof v === 'number' ? v.toFixed(1) : v) + ' / 100';
                },
              },
            },
          },
        },
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
