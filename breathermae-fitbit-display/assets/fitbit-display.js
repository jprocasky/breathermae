(function () {
	function colors(wrap) {
		var light = wrap && wrap.getAttribute('data-bmf-theme') === 'light';
		return light
			? { line: '#0284c7', tick: '#5b6b82', grid: 'rgba(213,222,235,.95)', point: '#0284c7' }
			: { line: '#6ec1e4', tick: '#9db0d0', grid: 'rgba(35,59,109,.45)', point: '#6ec1e4' };
	}

	function shortDate(iso) {
		if (!iso || iso.length < 10) return iso || '';
		var m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		return m[parseInt(iso.slice(5, 7), 10) - 1] + ' ' + parseInt(iso.slice(8, 10), 10);
	}

	function yScale(scale, values, pal) {
		var base = { ticks: { color: pal.tick, font: { size: 10 } }, grid: { color: pal.grid } };
		if (scale === 'score') {
			base.min = 0;
			base.max = 100;
			base.ticks.stepSize = 25;
			return base;
		}
		if (scale === 'hours') {
			base.min = 0;
			base.suggestedMax = 9;
			return base;
		}
		base.beginAtZero = true;
		return base;
	}

	function unit(scale, y) {
		if (scale === 'hours') return y + ' h';
		if (scale === 'bpm') return y + ' bpm';
		if (scale === 'ms') return y + ' ms';
		if (scale === 'steps') return y.toLocaleString() + ' steps';
		return y;
	}

	function render(root) {
		if (!window.Chart || !root) return;
		var wrap = root.closest('.bmf-wb-wrap') || root;
		var pal = colors(wrap);
		root.querySelectorAll('canvas.bm-fitbit-canvas').forEach(function (cv) {
			if (cv._fitbitChart) {
				try { cv._fitbitChart.destroy(); } catch (e) {}
				cv._fitbitChart = null;
			}
			var points;
			try { points = JSON.parse(cv.getAttribute('data-fitbit-chart') || '[]'); } catch (e) { return; }
			if (!points.length) return;
			var labels = points.map(function (p) { return p.x; });
			var values = points.map(function (p) { return p.y; });
			var scale = cv.getAttribute('data-fitbit-scale') || 'score';
			cv._fitbitChart = new window.Chart(cv.getContext('2d'), {
				type: 'line',
				data: {
					labels: labels,
					datasets: [{
						label: cv.getAttribute('data-fitbit-label') || 'Fitbit',
						data: values,
						borderColor: pal.line,
						backgroundColor: 'transparent',
						pointBackgroundColor: pal.point,
						borderWidth: 2,
						pointRadius: labels.length > 14 ? 2 : 3,
						pointHoverRadius: 4,
						tension: 0.25,
						spanGaps: true
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: { display: false },
						tooltip: {
							callbacks: {
								title: function (items) { return items.length ? items[0].label : ''; },
								label: function (item) { return unit(scale, item.parsed.y); }
							}
						}
					},
					scales: {
						x: {
							ticks: {
								color: pal.tick,
								maxRotation: 0,
								autoSkip: true,
								maxTicksLimit: 6,
								font: { size: 10 },
								callback: function (val) { return shortDate(this.getLabelForValue(val)); }
							},
							grid: { color: pal.grid }
						},
						y: yScale(scale, values, pal)
					}
				}
			});
		});
	}

	function boot() {
		document.querySelectorAll('.bm-fitbit-panel').forEach(render);
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-bmf-theme-set]');
		if (!btn) return;
		var wrap = btn.closest('.bmf-wb-wrap');
		if (!wrap || !wrap.querySelector('.bm-fitbit-panel')) return;
		var theme = btn.getAttribute('data-bmf-theme-set') === 'light' ? 'light' : 'dark';
		document.querySelectorAll('.bmf-wb-wrap').forEach(function (other) {
			other.setAttribute('data-bmf-theme', theme);
		});
		wrap.querySelectorAll('[data-bmf-theme-set]').forEach(function (b) {
			b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
		});
		boot();
		if (!window.bmFitbit || !bmFitbit.ajax) return;
		var body = new URLSearchParams();
		body.set('action', 'bm_fitbit_ui_theme');
		body.set('_ajax_nonce', bmFitbit.nonce);
		body.set('theme', theme);
		fetch(bmFitbit.ajax, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body });
	});

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();
