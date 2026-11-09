(function () {
	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	function apply(theme) {
		document.querySelectorAll('.bmf-home, .bmf-wb-wrap, .bmf-wb-panel').forEach(function (el) {
			el.setAttribute('data-bmf-theme', theme);
		});
		document.querySelectorAll('[data-bmf-theme-set]').forEach(function (btn) {
			btn.setAttribute('aria-pressed', btn.getAttribute('data-bmf-theme-set') === theme ? 'true' : 'false');
		});
	}

	function setMenu(shell, open) {
		if (!shell) return;
		shell.classList.toggle('is-menu-open', open);
		var btn = shell.querySelector('.bmf-home-menu');
		if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
	}

	ready(function () {
		var rail = document.querySelector('.bmf-home');
		if (rail) apply(rail.getAttribute('data-bmf-theme') === 'light' ? 'light' : 'dark');
				document.querySelectorAll('.bmf-home-hello[data-bmf-greet]').forEach(function (el) {
				var hour = new Date().getHours();
				var greet = hour < 12 ? 'Good morning' : (hour < 17 ? 'Good afternoon' : 'Good evening');
				el.innerHTML = greet + ', <span>' + el.getAttribute('data-bmf-greet') + '</span>';
			});
			document.addEventListener('click', function (event) {
			var toggle = event.target.closest('.bmf-home-menu, .bmf-home-menu-close, .bmf-home-backdrop');
			if (toggle) {
				var shell = toggle.closest('.bmf-home');
				setMenu(shell, toggle.classList.contains('bmf-home-menu') && !shell.classList.contains('is-menu-open'));
				return;
			}
			var link = event.target.closest('.bmf-home-rail a');
			if (link) setMenu(link.closest('.bmf-home'), false);
			var dismiss = event.target.closest('[data-bmf-dismiss]');
			if (dismiss && dismiss.closest('.bmf-home') && window.bmfMemberHome) {
				var key = dismiss.getAttribute('data-bmf-dismiss');
				var panel = dismiss.closest('.bmf-home-notice');
				if (panel) panel.remove();
				var body = new URLSearchParams();
				body.set('action', 'bmf_member_home_dismiss');
				body.set('nonce', bmfMemberHome.nonce);
				body.set('key', key);
				body.set('rev', dismiss.getAttribute('data-bmf-rev') || '');
				fetch(bmfMemberHome.ajax, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				});
				return;
			}
			var btn = event.target.closest('[data-bmf-theme-set]');
			if (!btn || !btn.closest('.bmf-home')) return;
			var theme = btn.getAttribute('data-bmf-theme-set') === 'light' ? 'light' : 'dark';
			apply(theme);
			if (!window.bmfMemberHome) return;
			var body = new URLSearchParams();
			body.set('action', 'bmf_member_home_theme');
			body.set('nonce', bmfMemberHome.nonce);
			body.set('theme', theme);
			fetch(bmfMemberHome.ajax, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			});
		});
		document.addEventListener('keydown', function (event) {
			if (event.key !== 'Escape') return;
			document.querySelectorAll('.bmf-home.is-menu-open').forEach(function (shell) {
				setMenu(shell, false);
			});
		});

		// Guided-path "new step" highlight (session-scoped).
		document.querySelectorAll('.bmf-home-guided[data-step-id]').forEach(function (panel) {
			var id = panel.getAttribute('data-step-id') || '';
			if (!id) return;
			var key = 'bmf_guided_last';
			var last = '';
			try { last = sessionStorage.getItem(key) || ''; } catch (e) {}
			if (last !== id) {
				panel.classList.add('bmf-home-guided--new');
				try { sessionStorage.setItem(key, id); } catch (e) {}
			}
		});

		// Assessment Status accordion + pin
		document.querySelectorAll('[data-bmf-assess-panel]').forEach(function (panel) {
			var toggle = panel.querySelector('.bmf-home-assess-toggle');
			var pin = panel.querySelector('.bmf-home-assess-pin');
			var body = panel.querySelector('.bmf-home-due-body');
			if (!toggle || !body) return;
			var key = 'bmf_assess_pinned';
			function setOpen(open) {
				panel.classList.toggle('is-open', open);
				toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
				body.hidden = !open;
			}
			function isPinned() {
				try { return localStorage.getItem(key) === '1'; } catch (e) { return false; }
			}
			function setPinned(v) {
				try { localStorage.setItem(key, v ? '1' : '0'); } catch (e) {}
				if (pin) pin.setAttribute('aria-pressed', v ? 'true' : 'false');
			}
			var pinned = isPinned();
			setPinned(pinned);
			setOpen(pinned);
			toggle.addEventListener('click', function () {
				var open = !panel.classList.contains('is-open');
				setOpen(open);
				if (!open) setPinned(false);
			});
			if (pin) {
				pin.addEventListener('click', function (e) {
					e.stopPropagation();
					var next = !isPinned();
					setPinned(next);
					if (next) setOpen(true);
				});
			}
		});

		// Assessment Timeline accordion + pin + Gantt rendering
		document.querySelectorAll('[data-bmf-timeline]').forEach(function (panel) {
			var toggle = panel.querySelector('.bmf-home-assess-toggle');
			var pin = panel.querySelector('.bmf-home-assess-pin');
			var body = panel.querySelector('.bmf-home-due-body');
			if (!toggle || !body) return;
			var key = 'bmf_timeline_pinned';
			function setOpen(open) {
				panel.classList.toggle('is-open', open);
				toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
				body.hidden = !open;
				if (open) renderTimeline(panel);
			}
			function isPinned() {
				try { return localStorage.getItem(key) === '1'; } catch (e) { return false; }
			}
			function setPinned(v) {
				try { localStorage.setItem(key, v ? '1' : '0'); } catch (e) {}
				if (pin) pin.setAttribute('aria-pressed', v ? 'true' : 'false');
			}
			var pinned = isPinned();
			setPinned(pinned);
			setOpen(pinned);
			toggle.addEventListener('click', function () {
				var open = !panel.classList.contains('is-open');
				setOpen(open);
				if (!open) setPinned(false);
			});
			if (pin) {
				pin.addEventListener('click', function (e) {
					e.stopPropagation();
					var next = !isPinned();
					setPinned(next);
					if (next) setOpen(true);
				});
			}
			var ranges = panel.querySelectorAll('.bmf-tl-ranges button');
			ranges.forEach(function (btn) {
				btn.addEventListener('click', function () {
					ranges.forEach(function (b) { b.classList.remove('is-active'); });
					btn.classList.add('is-active');
					renderTimeline(panel);
				});
			});
		});

		function localParts(value) {
			if (!value) return null;
			if (value.length === 10) {
				var p = value.split('-');
				return { y: +p[0], m: +p[1], d: +p[2] };
			}
			var dt = new Date(value);
			if (isNaN(dt.getTime())) return null;
			return { y: dt.getFullYear(), m: dt.getMonth() + 1, d: dt.getDate() };
		}
		function localYmd(value) {
			var p = localParts(value);
			if (!p) return '';
			var m = p.m < 10 ? '0' + p.m : '' + p.m;
			var d = p.d < 10 ? '0' + p.d : '' + p.d;
			return p.y + '-' + m + '-' + d;
		}
		function localLabel(value) {
			var p = localParts(value);
			if (!p) return '';
			var dt = new Date(p.y, p.m - 1, p.d);
			return dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
		}
		function localAge(value) {
			var ymd = localYmd(value);
			if (!ymd) return null;
			var today = localYmd(new Date().toISOString());
			var a = parseYmd(ymd);
			var b = parseYmd(today);
			if (a === null || b === null) return null;
			return Math.max(0, Math.round((b - a) / 86400000));
		}
		document.querySelectorAll('[data-bmf-local-date]').forEach(function (el) {
			var label = localLabel(el.getAttribute('data-bmf-local-date'));
			if (label) el.textContent = label;
		});
		document.querySelectorAll('[data-bmf-local-age]').forEach(function (el) {
			var age = localAge(el.getAttribute('data-bmf-local-age'));
			if (age !== null) el.textContent = age + 'd';
		});

		function parseYmd(s) {
			var p = (s || '').split('-');
			if (p.length < 3) return null;
			return Date.UTC(+p[0], +p[1] - 1, +p[2]);
		}
		function fmtYmd(ts) {
			var d = new Date(ts);
			var m = d.getUTCMonth() + 1;
			var day = d.getUTCDate();
			return d.getUTCFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
		}
		function addDays(ts, n) {
			return ts + n * 86400000;
		}
		function renderTimeline(panel) {
			var canvas = panel.querySelector('.bmf-tl-canvas');
			var scroll = panel.querySelector('.bmf-tl-scroll');
			if (!canvas || !scroll) return;
			var raw = canvas.getAttribute('data-timeline');
			if (!raw) return;
			var data;
			try { data = JSON.parse(raw); } catch (e) { return; }
			if (!data || !data.groups) return;
			var monthsBtn = panel.querySelector('.bmf-tl-ranges button.is-active');
			var months = monthsBtn ? parseInt(monthsBtn.getAttribute('data-months'), 10) : 6;
			if (!months || months < 1) months = 6;
			var todayTs = parseYmd(localYmd(new Date().toISOString())) || Date.now();
			// Today at ~25% of the visible viewport. Window covers past + future accordingly.
			var totalDays = Math.round(months * 30.44);
			var pastDays = Math.round(totalDays * 0.75);
			var futureDays = totalDays - pastDays;
			var winStart = addDays(todayTs, -pastDays);
			var winEnd = addDays(todayTs, futureDays);
			var span = winEnd - winStart;
			if (span <= 0) span = 1;
			var dayWidth = 22;
			var labelCol = 140;
			var totalDays = Math.round((winEnd - winStart) / 86400000);
			var canvasWidth = labelCol + Math.max(totalDays * dayWidth, 600);
			canvas.style.width = canvasWidth + 'px';
			canvas.style.minWidth = canvasWidth + 'px';
			canvas.innerHTML = '';
			// Two-row Gantt date header
			var axis = document.createElement('div');
			axis.className = 'bmf-tl-axis';
			var axisSpacer = document.createElement('div');
			axisSpacer.className = 'bmf-tl-axis-spacer';
			axis.appendChild(axisSpacer);
			var axisTrack = document.createElement('div');
			axisTrack.className = 'bmf-tl-axis-track';
			axis.appendChild(axisTrack);
			var monthRow = document.createElement('div');
			monthRow.className = 'bmf-tl-month-row';
			var dayRow = document.createElement('div');
			dayRow.className = 'bmf-tl-day-row';
			axisTrack.appendChild(monthRow);
			axisTrack.appendChild(dayRow);
			var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
			var days = [];
			for (var ts = winStart; ts < winEnd; ts = addDays(ts, 1)) days.push(ts);
			var trackPx = totalDays * dayWidth;
			axisTrack.style.width = trackPx + 'px';
			// Month spans
			var i = 0;
			while (i < days.length) {
				var start = days[i];
				var d0 = new Date(start);
				var m = d0.getUTCMonth();
				var y = d0.getUTCFullYear();
				var j = i;
				while (j < days.length) {
					var dj = new Date(days[j]);
					if (dj.getUTCMonth() !== m || dj.getUTCFullYear() !== y) break;
					j++;
				}
				var spanEl = document.createElement('div');
				spanEl.className = 'bmf-tl-month';
				spanEl.style.left = (i * dayWidth) + 'px';
				spanEl.style.width = ((j - i) * dayWidth) + 'px';
				spanEl.textContent = monthNames[m] + ' ' + y;
				monthRow.appendChild(spanEl);
				i = j;
			}
			// Day number boxes
			days.forEach(function (ts, idx) {
				var d = new Date(ts);
				var box = document.createElement('div');
				box.className = 'bmf-tl-day';
				if (d.getUTCDay() === 0) box.classList.add('is-sunday');
				box.style.left = (idx * dayWidth) + 'px';
				box.style.width = dayWidth + 'px';
				box.textContent = d.getUTCDate();
				dayRow.appendChild(box);
			});
			canvas.appendChild(axis);
			// Sunday dotted lines (through the rows, under the header)
			days.forEach(function (ts, idx) {
				var d = new Date(ts);
				if (d.getUTCDay() !== 0) return;
				var line = document.createElement('div');
				line.className = 'bmf-tl-weekline';
				line.style.left = (labelCol + idx * dayWidth) + 'px';
				canvas.appendChild(line);
			});
			// Today line
			var todayIdx = Math.round((todayTs - winStart) / 86400000);
			var todayEl = document.createElement('div');
			todayEl.className = 'bmf-tl-today';
			todayEl.style.left = (labelCol + todayIdx * dayWidth + dayWidth / 2) + 'px';
			canvas.appendChild(todayEl);
			var groups = data.groups;
			Object.keys(groups).forEach(function (gname) {
				var list = groups[gname] || [];
				list.forEach(function (lane, idx) {
					var laneEl = document.createElement('div');
					laneEl.className = 'bmf-tl-lane' + (idx === 0 ? ' is-group-start' : '');
					var label = document.createElement('div');
					label.className = 'bmf-tl-label';
					if (idx === 0) {
						var g = document.createElement('span');
						g.className = 'bmf-tl-group-name';
						g.textContent = gname;
						label.appendChild(g);
					}
					var name = document.createElement('span');
					name.className = 'bmf-tl-lane-name';
					name.textContent = lane.label || lane.key;
					label.appendChild(name);
					laneEl.appendChild(label);
					var track = document.createElement('div');
					track.className = 'bmf-tl-track';
					laneEl.appendChild(track);
					function place(dateStr, cls, title) {
						var ts = parseYmd(dateStr);
						if (ts === null || ts < winStart || ts > winEnd) return;
						var idx = Math.round((ts - winStart) / 86400000);
						var m = document.createElement('div');
						m.className = 'bmf-tl-marker ' + cls;
						m.style.left = (idx * dayWidth + dayWidth / 2) + 'px';
						if (title) m.title = title;
						track.appendChild(m);
					}
					(lane.actuals || []).forEach(function (d) {
						var local = localYmd(d) || d;
						place(local, 'is-actual', (lane.label || '') + ' completed ' + local);
					});
					var lastLocal = localYmd(lane.last) || lane.last;
					var lastTs = parseYmd(lastLocal);
					var cadence = Math.max(1, parseInt(lane.cadence, 10) || 90);
					if (lastTs !== null) {
						var nextMarked = false;
						for (var a = addDays(lastTs, cadence); a <= winEnd; a = addDays(a, cadence)) {
							if (a < winStart) continue;
							var isNext = !nextMarked && a >= todayTs;
							if (isNext) nextMarked = true;
							place(fmtYmd(a), isNext ? 'is-next' : 'is-adj', (isNext ? 'Next available ' : 'Adjusted ') + fmtYmd(a));
						}
					}
					canvas.appendChild(laneEl);
				});
			});
			// Position today at ~25% of the visible viewport after layout
			requestAnimationFrame(function () {
				var todayLeft = labelCol + todayIdx * dayWidth + dayWidth / 2;
				var target = todayLeft - (scroll.clientWidth * 0.25);
				scroll.scrollLeft = Math.max(0, target);
			});
		}

	});
})();