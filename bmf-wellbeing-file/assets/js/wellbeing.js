(function () {
	function onReady(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	function uniqueDates(series) {
		var seen = {};
		var out = [];
		series.forEach(function (s) {
			(s.points || []).forEach(function (p) {
				if (p && p.x && !seen[p.x]) {
					seen[p.x] = true;
					out.push(p.x);
				}
			});
		});
		out.sort();
		return out;
	}

	function mapY(points, labels) {
		var by = {};
		(points || []).forEach(function (p) {
			if (p && p.x != null) by[p.x] = p.y;
		});
		return labels.map(function (d) {
			return Object.prototype.hasOwnProperty.call(by, d) ? by[d] : null;
		});
	}

	function shortDate(iso) {
		if (!iso || iso.length < 10) return iso || '';
		var m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		var mo = parseInt(iso.slice(5, 7), 10);
		return m[mo - 1] + ' ' + parseInt(iso.slice(8, 10), 10);
	}

	function renderCharts(root) {
		if (!window.Chart || !root) return;
		root.querySelectorAll('canvas.bmf-wb-canvas').forEach(function (cv) {
			if (cv._wbChart) {
				try { cv._wbChart.destroy(); } catch (e) {}
				cv._wbChart = null;
			}
			var series;
			try {
				series = JSON.parse(cv.getAttribute('data-wb-chart') || '[]');
			} catch (e) {
				return;
			}
			if (!series.length) return;
			var labels = uniqueDates(series);
			if (labels.length < 2) return;
			var datasets = series.map(function (s) {
				return {
					label: s.label,
					data: mapY(s.points, labels),
					borderColor: s.color || '#6ec1e4',
					backgroundColor: 'transparent',
					borderWidth: 2,
					pointRadius: labels.length > 10 ? 2 : 3,
					pointHoverRadius: 4,
					tension: 0.25,
					spanGaps: true,
				};
			});
			cv._wbChart = new window.Chart(cv.getContext('2d'), {
				type: 'line',
				data: { labels: labels, datasets: datasets },
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: {
							display: datasets.length > 1,
							labels: { color: '#cfe6ff', boxWidth: 10, font: { size: 10 } },
						},
						tooltip: {
							callbacks: {
								title: function (items) {
									return items.length ? items[0].label : '';
								},
							},
						},
					},
					scales: {
						x: {
							ticks: {
								color: '#9db0d0',
								maxRotation: 0,
								autoSkip: true,
								maxTicksLimit: 6,
								font: { size: 10 },
								callback: function (val) {
									var lab = this.getLabelForValue(val);
									return shortDate(lab);
								},
							},
							grid: { color: 'rgba(35,59,109,.45)' },
						},
						y: {
							min: 0,
							max: 100,
							ticks: { color: '#9db0d0', font: { size: 10 }, stepSize: 25 },
							grid: { color: 'rgba(35,59,109,.45)' },
						},
					},
				},
			});
		});
	}

	function loadFor(wrap, userId, email) {
		var cfg = window.bmfWellbeingCfg || {};
		if (!cfg.ajax || !cfg.nonce) return;
		var body = new FormData();
		body.append('action', 'bmf_wellbeing_brief');
		body.append('nonce', cfg.nonce);
		body.append('voice', wrap.getAttribute('data-voice') || cfg.voice || 'member');
		body.append('mode', wrap.getAttribute('data-mode') || cfg.mode || 'status');
		if (userId) body.append('user_id', String(userId));
		if (email) body.append('email', email);
		wrap.classList.add('is-loading');
		fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (json && json.success && json.data && json.data.html) {
					wrap.innerHTML = json.data.html;
					renderCharts(wrap);
				}
			})
			.catch(function () { /* keep existing markup */ })
			.finally(function () { wrap.classList.remove('is-loading'); });
	}

	onReady(function () {
		document.querySelectorAll('.bmf-wb-wrap').forEach(function (wrap) {
			renderCharts(wrap);
		});
		document.addEventListener('uls:selected-member', function (ev) {
			var d = (ev && ev.detail) || {};
			var userId = d.user_id || d.id || 0;
			var email = d.email || '';
			document.querySelectorAll('.bmf-wb-wrap[data-admin="1"]').forEach(function (wrap) {
				loadFor(wrap, userId, email);
			});
		});
	});
})();
