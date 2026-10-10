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

	function render(root) {
		if (!window.Chart || !root) return;
		var wrap = root.closest('.bmf-wb-wrap') || root;
		var pal = colors(wrap);
		root.querySelectorAll('canvas.bm-oura-canvas').forEach(function (cv) {
			if (cv._ouraChart) {
				try { cv._ouraChart.destroy(); } catch (e) {}
				cv._ouraChart = null;
			}
			var points;
			try { points = JSON.parse(cv.getAttribute('data-oura-chart') || '[]'); } catch (e) { return; }
			if (!points.length) return;
			var labels = points.map(function (p) { return p.x; });
			var values = points.map(function (p) { return p.y; });
			var scale = cv.getAttribute('data-oura-scale') || 'score';
			var y = scale === 'hours'
				? { min: 0, suggestedMax: 9, ticks: { color: pal.tick, font: { size: 10 } }, grid: { color: pal.grid } }
				: { min: 0, max: 100, ticks: { color: pal.tick, font: { size: 10 }, stepSize: 25 }, grid: { color: pal.grid } };
			cv._ouraChart = new window.Chart(cv.getContext('2d'), {
				type: 'line',
				data: {
					labels: labels,
					datasets: [{
						label: cv.getAttribute('data-oura-label') || 'Oura',
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
								label: function (item) {
									if (scale === 'hours') return item.parsed.y + ' h';
									return item.parsed.y;
								}
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
						y: y
					}
				}
			});
		});
	}

	function boot() {
		document.querySelectorAll('.bm-oura-panel').forEach(render);
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-bmf-theme-set]');
		if (!btn) return;
		var wrap = btn.closest('.bmf-wb-wrap');
		if (!wrap || !wrap.querySelector('.bm-oura-panel')) return;
		var theme = btn.getAttribute('data-bmf-theme-set') === 'light' ? 'light' : 'dark';
		wrap.setAttribute('data-bmf-theme', theme);
		wrap.querySelectorAll('[data-bmf-theme-set]').forEach(function (b) {
			b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
		});
		document.querySelectorAll('.bmf-wb-wrap').forEach(function (other) {
			other.setAttribute('data-bmf-theme', theme);
		});
		boot();
		if (!window.bmOura || !bmOura.ajax) return;
		var body = new URLSearchParams();
		body.set('action', 'bm_oura_ui_theme');
		body.set('_ajax_nonce', bmOura.nonce);
		body.set('theme', theme);
		fetch(bmOura.ajax, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body });
	});

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();
