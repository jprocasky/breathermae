/**
 * BMF Calendar – appointments list + create/edit (Phase A)
 */
(function ($) {
	'use strict';

	var cfg = window.BMF_CALENDAR || {};
	var i18n = cfg.i18n || {};

	function ajax(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = cfg.nonce;
		return $.post(cfg.ajaxUrl, data);
	}

	function statusLabel(status) {
		var map = {
			requested: 'Requested',
			confirmed: 'Confirmed',
			completed: 'Completed',
			cancelled: 'Cancelled',
			no_show: 'No-show'
		};
		return map[status] || status;
	}

	function statusClass(status) {
		return 'bmf-cal-status bmf-cal-status--' + (status || 'requested');
	}

	function toDatetimeLocalValue(ts) {
		if (!ts) return '';
		var d = new Date(ts * 1000);
		var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
			'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
	}

	function localToUtcIso(localValue) {
		if (!localValue) return '';
		var d = new Date(localValue);
		if (isNaN(d.getTime())) return '';
		return d.toISOString();
	}

	function detectViewerTz() {
		try {
			var params = new URLSearchParams(window.location.search);
			var forced = params.get('bmf_tz') || params.get('tz');
			if (forced) return forced;
		} catch (e) { /* ignore */ }
		try {
			return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		} catch (e2) {
			return '';
		}
	}

	var displayTz = detectViewerTz();
	var siteTz = cfg.siteTz || '';

	function zonesDiffer() {
		if (!displayTz || !siteTz) {
			var siteOff = typeof cfg.siteUtcOffset === 'number' ? cfg.siteUtcOffset : null;
			if (siteOff === null) return false;
			return siteOff !== (-new Date().getTimezoneOffset() * 60);
		}
		return displayTz !== siteTz;
	}

	function tzOpts(extra) {
		var o = extra || {};
		if (displayTz) o.timeZone = displayTz;
		return o;
	}

	function stripGmt(str) {
		return String(str || '')
			.replace(/\s*GMT[+-]\d{2}:?\d{2}\b/gi, '')
			.replace(/\s*UTC[+-]\d{2}:?\d{2}\b/gi, '')
			.replace(/\s*\((Coordinated Universal Time|UTC|GMT)\)/gi, '')
			.replace(/\s{2,}/g, ' ')
			.trim();
	}

	function formatLocalTime(ts) {
		if (!ts) return '';
		var raw;
		try {
			raw = new Date(ts * 1000).toLocaleTimeString(undefined, tzOpts({
				hour: 'numeric',
				minute: '2-digit',
				hour12: true
			}));
		} catch (e) {
			raw = new Date(ts * 1000).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', hour12: true });
		}
		return stripGmt(raw);
	}

	function formatLocalDate(ts) {
		if (!ts) return '';
		try {
			return new Date(ts * 1000).toLocaleDateString(undefined, tzOpts({
				weekday: 'short',
				month: 'short',
				day: 'numeric'
			}));
		} catch (e) {
			return new Date(ts * 1000).toLocaleDateString(undefined, {
				weekday: 'short',
				month: 'short',
				day: 'numeric'
			});
		}
	}

	function applyTzNotes() {
		var label = displayTz
			? ('Showing times in ' + displayTz + (zonesDiffer() && siteTz ? ' (site: ' + siteTz + ')' : ''))
			: '';
		if (!label) return;
		$('.bmf-cal-panel').each(function () {
			var $p = $(this);
			if ($p.find('.bmf-cal-tz-live').length) {
				$p.find('.bmf-cal-tz-live').text(label);
				return;
			}
			var $note = $p.find('.bmf-cal-tz-note').first();
			if ($note.length) {
				$note.append($('<span class="bmf-cal-tz-live"></span>').text(' ' + label));
			} else {
				$p.prepend($('<p class="bmf-cal-tz-note bmf-cal-tz-live"></p>').text(label));
			}
		});
	}

	function formatLocalDateTime(ts) {
		if (!ts) return '';
		return formatLocalDate(ts) + ' ' + formatLocalTime(ts);
	}

	function formatRangeLocal(startTs, endTs) {
		if (!startTs) return '';
		var out = formatLocalDateTime(startTs);
		if (endTs) out += ' – ' + formatLocalTime(endTs);
		return out;
	}

	function whenHtml(startTs, endTs, fallback) {
		return formatRangeLocal(startTs, endTs) || stripGmt(fallback) || '';
	}

	function slotLabel(slot) {
		return formatLocalTime(slot.start_ts) || stripGmt(slot.start_fmt) || '';
	}

	function dateHeading(slot) {
		return formatLocalDate(slot.start_ts) || slot.date_fmt || slot.date || '';
	}

	/** Read member context from the panel (always from attributes, not jQuery data cache). */
	function getMemberContext($panel) {
		return {
			memberId: parseInt($panel.attr('data-member-id') || '0', 10) || 0,
			email: ($panel.attr('data-email') || '').trim()
		};
	}

	function setMemberContext($panel, memberId, email) {
		memberId = memberId || 0;
		email = email || '';
		$panel.attr('data-member-id', memberId);
		$panel.attr('data-email', email);
		// Keep jQuery data in sync too (avoids stale cache if anything else reads .data())
		$panel.data('member-id', memberId);
		$panel.data('email', email);
		$panel.find('[name=member_id]').val(memberId || '');
		$panel.find('[name=email]').val(email || '');
	}

	function renderList($panel, appointments) {
		var $list = $panel.find('.bmf-cal-list');
		var emptyMsg = $list.attr('data-empty') || $list.data('empty') || 'No appointments found.';
		var canEdit = String($panel.attr('data-can-edit')) === '1';

		$list.empty();

		if (!appointments || !appointments.length) {
			$list.append($('<p class="bmf-cal-placeholder"></p>').text(emptyMsg));
			return;
		}

		var $ul = $('<ul class="bmf-cal-items"></ul>');
		appointments.forEach(function (a) {
			var $li = $('<li class="bmf-cal-item"></li>').attr('data-id', a.id);
			var $main = $('<div class="bmf-cal-item-main"></div>');

			$main.append($('<div class="bmf-cal-item-subject"></div>').text(a.subject || '(No subject)'));
			$main.append(
				$('<div class="bmf-cal-item-meta"></div>').html(
					'<span class="bmf-cal-item-when">' + whenHtml(a.start_ts, a.end_ts, a.start_fmt) + '</span>' +
					' <span class="' + statusClass(a.status) + '">' + statusLabel(a.status) + '</span>'
				)
			);
			if (a.location) {
				$main.append($('<div class="bmf-cal-item-loc"></div>').text(a.location));
			}

			$li.append($main);

			if (canEdit) {
				var $actions = $('<div class="bmf-cal-item-actions"></div>');
				$actions.append(
					$('<button type="button" class="bmf-cal-btn bmf-cal-btn-sm bmf-cal-edit"></button>')
						.text('Edit').data('appt', a)
				);
				$actions.append(
					$('<button type="button" class="bmf-cal-btn bmf-cal-btn-sm bmf-cal-btn-danger bmf-cal-delete"></button>')
						.text('Delete').data('id', a.id)
				);
				$li.append($actions);
			}

			$ul.append($li);
		});
		$list.append($ul);
	}

	function loadAppointments($panel) {
		var mode = $panel.attr('data-mode') || 'member';
		var ctx = getMemberContext($panel);
		var payload = {
			mode: mode,
			future: 1
		};

		if (mode === 'member') {
			payload.member_id = parseInt($panel.attr('data-user-id') || '0', 10) || 0;
			payload.email = ($panel.attr('data-email') || '').trim();
		} else {
			payload.member_id = ctx.memberId;
			payload.email = ctx.email;
		}

		var $list = $panel.find('.bmf-cal-list');
		$list.html('<p class="bmf-cal-placeholder bmf-cal-loading">' + (i18n.loading || 'Loading…') + '</p>');

		ajax('bmf_cal_get_appointments', payload)
			.done(function (res) {
				if (res && res.success) {
					renderList($panel, res.data.appointments || []);
				} else {
					$list.html('<p class="bmf-cal-msg">' + (res && res.data && res.data.message ? res.data.message : i18n.error) + '</p>');
				}
			})
			.fail(function () {
				$list.html('<p class="bmf-cal-msg">' + (i18n.error || 'Something went wrong.') + '</p>');
			});
	}

	function showForm($panel, appt) {
		var $wrap = $panel.find('.bmf-cal-form-wrap');
		var $form = $wrap.find('.bmf-cal-form');
		$form[0].reset();

		var ctx = getMemberContext($panel);

		$form.find('[name=id]').val(appt && appt.id ? appt.id : '');
		$form.find('[name=subject]').val(appt && appt.subject ? appt.subject : '');
		$form.find('[name=location]').val(appt && appt.location ? appt.location : '');
		$form.find('[name=description]').val(appt && appt.description ? appt.description : '');
		$form.find('[name=status]').val(appt && appt.status ? appt.status : 'confirmed');

		if (appt && appt.start_ts) {
			$form.find('[name=start]').val(toDatetimeLocalValue(appt.start_ts));
		}
		if (appt && appt.end_ts) {
			$form.find('[name=end]').val(toDatetimeLocalValue(appt.end_ts));
		}

		// Member context: prefer existing appointment, else panel context
		if (appt && (appt.member_id || appt.member_email)) {
			$form.find('[name=member_id]').val(appt.member_id || '');
			$form.find('[name=email]').val(appt.member_email || '');
		} else {
			$form.find('[name=member_id]').val(ctx.memberId || '');
			$form.find('[name=email]').val(ctx.email || '');
		}

		$wrap.prop('hidden', false);
		$panel.find('.bmf-cal-toolbar, .bmf-cal-list').prop('hidden', true);
		$form.find('[name=subject]').focus();
	}

	function hideForm($panel) {
		$panel.find('.bmf-cal-form-wrap').prop('hidden', true);
		$panel.find('.bmf-cal-toolbar, .bmf-cal-list').prop('hidden', false);
	}

	function saveAppointment($panel, $form) {
		var startLocal = $form.find('[name=start]').val();
		var endLocal = $form.find('[name=end]').val();
		var ctx = getMemberContext($panel);

		var data = {
			id: $form.find('[name=id]').val() || 0,
			member_id: $form.find('[name=member_id]').val() || ctx.memberId || 0,
			email: $form.find('[name=email]').val() || ctx.email || '',
			provider_id: parseInt($panel.attr('data-provider-id') || '0', 10) || 0,
			subject: $form.find('[name=subject]').val(),
			location: $form.find('[name=location]').val(),
			description: $form.find('[name=description]').val(),
			status: $form.find('[name=status]').val(),
			start: startLocal,
			end: endLocal,
			start_utc: localToUtcIso(startLocal),
			end_utc: localToUtcIso(endLocal)
		};

		var $btn = $form.find('[type=submit]').prop('disabled', true);

		ajax('bmf_cal_save_appointment', data)
			.done(function (res) {
				if (res && res.success) {
					hideForm($panel);
					loadAppointments($panel);
				} else {
					alert((res && res.data && res.data.message) || i18n.error || 'Save failed');
				}
			})
			.fail(function () {
				alert(i18n.error || 'Save failed');
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	}

	function deleteAppointment($panel, id) {
		if (!id) return;
		if (!window.confirm('Delete this appointment?')) return;

		ajax('bmf_cal_delete_appointment', { id: id })
			.done(function (res) {
				if (res && res.success) {
					loadAppointments($panel);
				} else {
					alert((res && res.data && res.data.message) || 'Delete failed');
				}
			})
			.fail(function () {
				alert('Delete failed');
			});
	}

	function initListPanel($panel) {
		var mode = $panel.attr('data-mode');
		if (mode !== 'member' && mode !== 'provider') return;

		loadAppointments($panel);

		$panel.on('click', '.bmf-cal-add', function () {
			if (mode === 'provider') {
				var ctx = getMemberContext($panel);
				if (!ctx.memberId && !ctx.email) {
					alert('Select a member first (or pass member_id / email to the shortcode).');
					return;
				}
			}
			showForm($panel, null);
		});

		$panel.on('click', '.bmf-cal-edit', function () {
			showForm($panel, $(this).data('appt'));
		});

		$panel.on('click', '.bmf-cal-delete', function () {
			deleteAppointment($panel, $(this).data('id'));
		});

		$panel.on('click', '.bmf-cal-form-cancel', function () {
			hideForm($panel);
		});

		$panel.on('submit', '.bmf-cal-form', function (e) {
			e.preventDefault();
			saveAppointment($panel, $(this));
		});
	}

	var DAY_NAMES = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday' };

	function timeShort(t) {
		if (!t) return '';
		return String(t).slice(0, 5);
	}



	function shortName(name) {
		name = String(name || '').trim();
		if (!name) return 'Member';
		var parts = name.split(/\s+/);
		return parts[0];
	}

	function initBooked($panel) {
		var $box = $panel.find('.bmf-cal-coverage-board');
		var days = parseInt($panel.attr('data-days') || '30', 10) || 30;
		ajax('bmf_cal_get_appointments', {
			mode: 'booked',
			future: 1,
			exclude: $panel.attr('data-exclude') || ''
		}).done(function (res) {
			var rows = (res && res.success) ? (res.data.appointments || []) : [];
			renderBookedGrid($box, rows, days);
		}).fail(function () {
			$box.html('<p class="bmf-cal-msg">' + (i18n.error || 'Something went wrong.') + '</p>');
		});
	}

	function renderBookedGrid($box, rows, dayCount) {
		$box.empty();
		var colorBy = {};
		var legend = [];
		rows.forEach(function (a) {
			var id = String(a.provider_id || '0');
			if (!colorBy[id]) {
				var idx = legend.length;
				colorBy[id] = COVERAGE_COLORS[idx % COVERAGE_COLORS.length];
				legend.push({ id: id, name: a.provider_name || ('Provider ' + id), color: colorBy[id] });
			}
		});

		if (legend.length) {
			var $legend = $('<div class="bmf-cal-coverage-legend"></div>');
			legend.forEach(function (p) {
				var $item = $('<span class="bmf-cal-coverage-legend-item"></span>');
				$item.append($('<span class="bmf-cal-coverage-swatch"></span>').css({ background: p.color.bg }));
				$item.append($('<span class="bmf-cal-coverage-legend-name"></span>').text(p.name));
				$legend.append($item);
			});
			$box.append($legend);
		}

		var byDate = {};
		rows.forEach(function (a) {
			var key = a.start_ts ? ymdLocal(new Date(a.start_ts * 1000)) : (a.start_at || '').slice(0, 10);
			if (!byDate[key]) byDate[key] = [];
			byDate[key].push(a);
		});

		var start = new Date();
		start.setHours(0, 0, 0, 0);
		var weekday = (start.getDay() + 6) % 7;
		var $grid = $('<div class="bmf-cal-coverage-grid"></div>');
		['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].forEach(function (label) {
			$grid.append($('<div class="bmf-cal-coverage-dow"></div>').text(label));
		});
		for (var p = 0; p < weekday; p++) {
			$grid.append($('<div class="bmf-cal-coverage-cell is-pad"></div>'));
		}
		for (var i = 0; i < dayCount; i++) {
			var day = new Date(start.getTime());
			day.setDate(start.getDate() + i);
			var key = ymdLocal(day);
			var $cell = $('<div class="bmf-cal-coverage-cell"></div>');
			$cell.append($('<div class="bmf-cal-coverage-date"></div>').text(day.getDate()));
			(byDate[key] || []).forEach(function (a) {
				var c = colorBy[String(a.provider_id || '0')] || COVERAGE_COLORS[0];
				var when = formatLocalTime(a.start_ts);
				if (a.end_ts) when += '–' + formatLocalTime(a.end_ts);
				var member = shortName(a.member_name || a.member_email);
				var tip = [
					a.provider_name || 'Provider',
					when,
					a.member_name || a.member_email || 'Member',
					a.subject || '',
					a.status === 'requested' ? 'Requested' : 'Confirmed'
				].filter(Boolean).join(' · ');
				var $chip = $('<span class="bmf-cal-coverage-chip"></span>')
					.css({ background: c.bg, color: c.fg })
					.text(when + ' ' + member)
					.attr('title', tip);
				if (a.status === 'requested') $chip.addClass('is-requested');
				$cell.append($chip);
			});
			$grid.append($cell);
		}
		$box.append($grid);
	}

	var COVERAGE_COLORS = [
		{ bg: '#2b6cb0', fg: '#fff' },
		{ bg: '#2f855a', fg: '#fff' },
		{ bg: '#c05621', fg: '#fff' },
		{ bg: '#6b46c1', fg: '#fff' },
		{ bg: '#c53030', fg: '#fff' },
		{ bg: '#2c7a7b', fg: '#fff' },
		{ bg: '#b7791f', fg: '#fff' },
		{ bg: '#2a4365', fg: '#fff' }
	];

	function ymdLocal(d) {
		var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	}

	function initCoverage($panel) {
		var $box = $panel.find('.bmf-cal-coverage-board');
		ajax('bmf_cal_get_coverage', {
			days: parseInt($panel.attr('data-days') || '30', 10) || 30,
			exclude: $panel.attr('data-exclude') || ''
		}).done(function (res) {
			renderCoverage($box, (res && res.success) ? res.data : { providers: [] }, parseInt($panel.attr('data-days') || '30', 10) || 30);
		}).fail(function () {
			$box.html('<p class="bmf-cal-msg">' + (i18n.error || 'Something went wrong.') + '</p>');
		});
	}

	function renderCoverage($box, data, dayCount) {
		$box.empty();
		var providers = (data && data.providers) || [];
		if (!providers.length) {
			$box.append($('<p class="bmf-cal-placeholder"></p>').text('No providers found.'));
			return;
		}

		var $legend = $('<div class="bmf-cal-coverage-legend"></div>');
		providers.forEach(function (p, i) {
			var c = COVERAGE_COLORS[i % COVERAGE_COLORS.length];
			p._color = c;
			var $item = $('<span class="bmf-cal-coverage-legend-item"></span>');
			$item.append($('<span class="bmf-cal-coverage-swatch"></span>').css({ background: c.bg }));
			$item.append($('<span class="bmf-cal-coverage-legend-name"></span>').text(p.name || ('Provider ' + p.id)));
			$legend.append($item);
		});
		$box.append($legend);

		var byProvDate = {};
		providers.forEach(function (p) {
			byProvDate[p.id] = {};
			(p.windows || []).forEach(function (w) {
				if (!byProvDate[p.id][w.date]) byProvDate[p.id][w.date] = [];
				byProvDate[p.id][w.date].push(w);
			});
		});

		var start = new Date();
		start.setHours(0, 0, 0, 0);
		var weekday = (start.getDay() + 6) % 7; // 0 = Monday
		var $grid = $('<div class="bmf-cal-coverage-grid"></div>');
		['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].forEach(function (label) {
			$grid.append($('<div class="bmf-cal-coverage-dow"></div>').text(label));
		});
		for (var p = 0; p < weekday; p++) {
			$grid.append($('<div class="bmf-cal-coverage-cell is-pad"></div>'));
		}
		for (var i = 0; i < dayCount; i++) {
			var day = new Date(start.getTime());
			day.setDate(start.getDate() + i);
			var key = ymdLocal(day);
			var $cell = $('<div class="bmf-cal-coverage-cell"></div>');
			$cell.append($('<div class="bmf-cal-coverage-date"></div>').text(day.getDate()));
			providers.forEach(function (prov) {
				var wins = (byProvDate[prov.id] && byProvDate[prov.id][key]) || [];
				wins.forEach(function (w) {
					$cell.append(
						$('<span class="bmf-cal-coverage-chip"></span>')
							.css({ background: prov._color.bg, color: prov._color.fg })
							.text(formatLocalTime(w.start_ts) + '–' + formatLocalTime(w.end_ts))
							.attr('title', (prov.name || '') + ' ' + formatLocalTime(w.start_ts) + '–' + formatLocalTime(w.end_ts))
					);
				});
			});
			$grid.append($cell);
		}
		$box.append($grid);
	}

	function initAgenda($panel) {
		var $box = $panel.find('.bmf-cal-coverage-board');
		var days = parseInt($panel.attr('data-days') || '30', 10) || 30;
		ajax('bmf_cal_get_appointments', { mode: 'agenda', future: 1 })
			.done(function (res) {
				var rows = (res && res.success) ? (res.data.appointments || []) : [];
				renderBookedGrid($box, rows, days);
			})
			.fail(function () {
				$box.html('<p class="bmf-cal-msg">' + (i18n.error || 'Something went wrong.') + '</p>');
			});
	}

	function renderAgenda($box, rows) {
		$box.empty();
		if (!rows.length) {
			$box.append($('<p class="bmf-cal-placeholder"></p>').text('No upcoming confirmed appointments.'));
			return;
		}
		var byDay = {};
		rows.forEach(function (a) {
			var key = formatLocalDate(a.start_ts) || (a.start_at || '').slice(0, 10);
			if (!byDay[key]) byDay[key] = [];
			byDay[key].push(a);
		});
		Object.keys(byDay).forEach(function (day) {
			var $g = $('<div class="bmf-cal-agenda-day"></div>');
			$g.append($('<div class="bmf-cal-slot-day-label"></div>').text(day));
			var $ul = $('<ul class="bmf-cal-items"></ul>');
			byDay[day].forEach(function (a) {
				var $li = $('<li class="bmf-cal-item"></li>');
				var who = a.member_name || a.member_email || 'Member';
				var when = formatLocalTime(a.start_ts);
				if (a.end_ts) when += ' – ' + formatLocalTime(a.end_ts);
				$li.append($('<div class="bmf-cal-item-subject"></div>').text(when + ' · ' + who));
				$li.append($('<div class="bmf-cal-item-meta"></div>').text(a.subject || 'Appointment'));
				$ul.append($li);
			});
			$g.append($ul);
			$box.append($g);
		});
	}

	function initOutlookBar($panel) {
		var $bar = $panel.find('.bmf-cal-outlook-bar');
		if (!$bar.length) return;
		var $state = $bar.find('.bmf-cal-outlook-state');
		var $connect = $bar.find('.bmf-cal-outlook-connect');
		var $disc = $bar.find('.bmf-cal-outlook-disconnect');

		function render(data) {
			data = data || {};
			if (!data.configured) {
				$state.text('Outlook is not configured in Settings.');
				$connect.prop('hidden', true);
				$disc.prop('hidden', true);
				return;
			}
			if (data.needs_reconnect) {
				$state.text('Outlook sign-in expired. Reconnect so confirmations can be written to your calendar.');
				$connect.text('Reconnect Outlook').prop('hidden', false);
				$disc.prop('hidden', false);
			} else if (data.connected) {
				var who = data.email || data.display_name || 'connected';
				var msg = 'Connected as ' + who + '. Confirmed appointments go on this calendar.';
				if (data.last_error) msg += ' Last Outlook error: ' + data.last_error;
				$state.text(msg);
				$connect.prop('hidden', true);
				$disc.prop('hidden', false);
			} else {
				$state.text('Not connected. Confirmations will not appear in Outlook.');
				$connect.prop('hidden', false);
				$disc.prop('hidden', true);
			}
		}

		ajax('bmf_cal_outlook_status', {}).done(function (res) {
			if (res && res.success) render(res.data);
			else $state.text('Could not load Outlook status.');
		}).fail(function () {
			$state.text('Could not load Outlook status.');
		});

		$disc.on('click', function () {
			if (!window.confirm('Disconnect Outlook? Future confirmations will not be written to that calendar.')) return;
			ajax('bmf_cal_outlook_disconnect', {}).done(function (res) {
				if (res && res.success) render({ configured: true, connected: false });
			});
		});

		try {
			var flag = new URLSearchParams(window.location.search).get('bmf_ol');
			if (flag === 'connected') $state.text('Outlook connected.');
			if (flag === 'denied') $state.text('Outlook connect was cancelled.');
			if (flag === 'error' || flag === 'token_error') $state.text('Outlook connect failed. Check Azure credentials and redirect URI.');
		} catch (e) { /* ignore */ }
	}

	function initAvailability($panel) {
		initOutlookBar($panel);

		var providerId = parseInt($panel.attr('data-provider-id') || '0', 10) || 0;

		function loadRules() {
			ajax('bmf_cal_get_availability', { provider_id: providerId, display_tz: displayTz || '' })
				.done(function (res) {
					if (res && res.success) {
						renderRules(res.data.rules || []);
					}
				});
			ajax('bmf_cal_get_slots', { provider_id: providerId, days: 14 })
				.done(function (res) {
					var all = (res && res.success) ? (res.data.slots || []) : [];
					renderSlotPreview(all.filter(function (s) { return !s.state || s.state === 'open'; }));
				});
		}

		function renderRules(rules) {
			var $rec = $panel.find('.bmf-cal-avail-recurring').empty();
			var $exc = $panel.find('.bmf-cal-avail-exceptions').empty();
			var rec = 0, exc = 0;
			(rules || []).forEach(function (r) {
				var $li = $('<li class="bmf-cal-avail-item"></li>');
				var label;
				if (r.type === 'exception') {
					label = r.date_specific + (r.is_available == 1 ? ' (extra hours)' : ' (blocked)');
					exc++;
					$exc.append($li);
				} else {
					var d = r.display_day_of_week || r.day_of_week;
					var st = r.display_start_time || r.start_time;
					var en = r.display_end_time || r.end_time;
					label = (DAY_NAMES[d] || ('Day ' + d)) + ' ' + timeShort(st) + '–' + timeShort(en);
					rec++;
					$rec.append($li);
				}
				$li.append($('<span></span>').text(label));
				$li.append(
					$('<button type="button" class="bmf-cal-btn bmf-cal-btn-sm bmf-cal-btn-danger"></button>')
						.text('Remove')
						.on('click', function () {
							if (!window.confirm('Remove this availability?')) return;
							ajax('bmf_cal_delete_availability', { id: r.id, provider_id: providerId })
								.done(function () { loadRules(); });
						})
				);
			});
			if (!rec) $rec.append($('<li class="bmf-cal-placeholder"></li>').text('No weekly hours yet.'));
			if (!exc) $exc.append($('<li class="bmf-cal-placeholder"></li>').text('No blocked dates.'));
		}

		function renderSlotPreview(slots) {
			var $box = $panel.find('.bmf-cal-slot-preview').empty();
			if (!slots.length) {
				$box.append($('<p class="bmf-cal-placeholder"></p>').text('No upcoming open slots.'));
				return;
			}
			var byDate = {};
			slots.forEach(function (s) {
				var key = dateHeading(s) || s.date;
				if (!byDate[key]) byDate[key] = { label: key, items: [] };
				byDate[key].items.push(s);
			});
			Object.keys(byDate).slice(0, 7).forEach(function (d) {
				var group = byDate[d];
				var $g = $('<div class="bmf-cal-slot-day"></div>');
				$g.append($('<div class="bmf-cal-slot-day-label"></div>').text(group.label));
				var $row = $('<div class="bmf-cal-slot-row"></div>');
				group.items.forEach(function (s) {
					$row.append($('<span class="bmf-cal-slot-chip"></span>').text(slotLabel(s)));
				});
				$g.append($row);
				$box.append($g);
			});
		}

		$panel.find('.bmf-cal-avail-form-recurring').on('submit', function (e) {
			e.preventDefault();
			var $f = $(this);
			ajax('bmf_cal_save_availability', {
				provider_id: providerId,
				type: 'recurring',
				day_of_week: $f.find('[name=day_of_week]').val(),
				start_time: $f.find('[name=start_time]').val(),
				end_time: $f.find('[name=end_time]').val(),
				is_available: 1,
				display_tz: displayTz || ''
			}).done(function (res) {
				if (res && res.success) loadRules();
				else alert((res && res.data && res.data.message) || 'Could not save');
			});
		});

		$panel.find('.bmf-cal-avail-form-exception').on('submit', function (e) {
			e.preventDefault();
			var $f = $(this);
			ajax('bmf_cal_save_availability', {
				provider_id: providerId,
				type: 'exception',
				date_specific: $f.find('[name=date_specific]').val(),
				is_available: 0
			}).done(function (res) {
				if (res && res.success) {
					$f[0].reset();
					loadRules();
				} else {
					alert((res && res.data && res.data.message) || 'Could not save');
				}
			});
		});

		loadRules();
	}

	function initRequest($panel) {
		var lockedProvider = parseInt($panel.attr('data-provider-id') || '0', 10) || 0;
		var selectedSlot = null;
		var $select = $panel.find('.bmf-cal-provider-select');
		var $slots = $panel.find('.bmf-cal-slots');
		var $hint = $panel.find('.bmf-cal-slots-hint');
		var $general = $panel.find('.bmf-cal-general-time');
		var $submit = $panel.find('.bmf-cal-request-submit');
		var $status = $panel.find('.bmf-cal-request-status');

		function currentProvider() {
			return parseInt($select.val() || '0', 10) || 0;
		}

		function updateSubmitState() {
			var pid = currentProvider();
			if (pid) {
				$submit.prop('disabled', !selectedSlot);
			} else {
				$submit.prop('disabled', !$panel.find('.bmf-cal-request-start').val());
			}
		}

		function loadProviders() {
			ajax('bmf_cal_list_providers', { exclude: $panel.attr('data-exclude') || '' })
				.done(function (res) {
					var list = (res && res.success && res.data.providers) ? res.data.providers : [];
					list.forEach(function (p) {
						if (!$select.find('option[value="' + p.id + '"]').length) {
							$select.append($('<option></option>').val(p.id).text(p.name));
						}
					});
					if (lockedProvider) {
						$select.val(String(lockedProvider)).prop('disabled', true);
					}
					onProviderChange();
				});
		}

		function onProviderChange() {
			selectedSlot = null;
			$slots.empty();
			var pid = currentProvider();
			if (!pid) {
				$hint.text('No specific provider — choose a preferred date and time.');
				$general.prop('hidden', false);
				updateSubmitState();
				return;
			}
			$general.prop('hidden', true);
			$hint.text('Loading available times…');
			ajax('bmf_cal_get_slots', { provider_id: pid, days: 14 })
				.done(function (res) {
					var slots = (res && res.success) ? (res.data.slots || []) : [];
					renderRequestSlots(slots);
					updateSubmitState();
				})
				.fail(function () {
					$hint.text('Could not load slots.');
				});
		}

		function renderRequestSlots(slots) {
			$slots.empty();
			var openCount = 0;
			var mineCount = 0;
			var byDate = {};
			(slots || []).forEach(function (s) {
				var key = dateHeading(s) || s.date;
				if (!byDate[key]) byDate[key] = { label: key, items: [] };
				byDate[key].items.push(s);
				if (s.mine && s.state && s.state !== 'open') mineCount++;
				else if (!s.state || s.state === 'open') openCount++;
			});
			if (!openCount && !mineCount) {
				$hint.text('No open slots for this provider in the next two weeks.');
				return;
			}
			$hint.text(mineCount ? 'Pick a time. Your pending request is highlighted.' : 'Pick a time:');
			Object.keys(byDate).forEach(function (d) {
				var group = byDate[d];
				var $g = $('<div class="bmf-cal-slot-day"></div>');
				$g.append($('<div class="bmf-cal-slot-day-label"></div>').text(group.label));
				var $row = $('<div class="bmf-cal-slot-row"></div>');
				group.items.forEach(function (s) {
					var isMine = !!(s.mine && s.state && s.state !== 'open');
					var holdLabel = (s.state === 'confirmed') ? 'Confirmed' : (s.state === 'completed') ? 'Completed' : 'Requested';
					var $btn = $('<button type="button" class="bmf-cal-slot-chip"></button>')
						.text(isMine ? (slotLabel(s) + ' · ' + holdLabel) : slotLabel(s));
					if (isMine) {
						$btn.addClass('is-mine is-' + s.state).prop('disabled', true);
					} else {
						$btn.addClass('bmf-cal-slot-pick').data('slot', s);
					}
					$row.append($btn);
				});
				$g.append($row);
				$slots.append($g);
			});
		}

		$panel.on('click', '.bmf-cal-slot-pick', function () {
			$panel.find('.bmf-cal-slot-pick').removeClass('is-selected');
			$(this).addClass('is-selected');
			selectedSlot = $(this).data('slot');
			updateSubmitState();
		});

		$select.on('change', onProviderChange);
		$panel.find('.bmf-cal-request-start').on('change', updateSubmitState);

		$submit.on('click', function () {
			var pid = currentProvider();
			var payload = {
				provider_id: pid,
				subject: $panel.find('.bmf-cal-request-subject').val() || 'Appointment request',
				description: $panel.find('.bmf-cal-request-notes').val() || ''
			};
			if (pid && selectedSlot) {
				payload.start = selectedSlot.start;
				payload.end = selectedSlot.end;
				payload.start_utc = new Date(selectedSlot.start_ts * 1000).toISOString();
				payload.end_utc = new Date(selectedSlot.end_ts * 1000).toISOString();
			} else {
				var local = $panel.find('.bmf-cal-request-start').val();
				if (!local) return;
				payload.start = local;
				payload.start_utc = localToUtcIso(local);
			}

			$submit.prop('disabled', true);
			ajax('bmf_cal_request_appointment', payload)
				.done(function (res) {
					if (res && res.success) {
						$status.prop('hidden', false).text('Request sent. Your provider will confirm the time.');
						selectedSlot = null;
						$panel.find('.bmf-cal-slot-pick').removeClass('is-selected');
						$panel.find('.bmf-cal-request-notes').val('');
						if (pid) onProviderChange();
					} else {
						alert((res && res.data && res.data.message) || 'Could not send request');
						$submit.prop('disabled', false);
					}
				})
				.fail(function () {
					alert(i18n.error || 'Could not send request');
					$submit.prop('disabled', false);
				});
		});

		loadProviders();
	}

	function initOpenRequests($panel) {
		var $list = $panel.find('.bmf-cal-list');

		function loadInbox() {
			$list.html('<p class="bmf-cal-placeholder bmf-cal-loading">' + (i18n.loading || 'Loading…') + '</p>');
			ajax('bmf_cal_get_open_requests', {})
				.done(function (res) {
					var rows = (res && res.success) ? (res.data.appointments || []) : [];
					renderInbox(rows);
				})
				.fail(function () {
					$list.html('<p class="bmf-cal-msg">' + (i18n.error || 'Something went wrong.') + '</p>');
				});
		}

		function renderInbox(rows) {
			$list.empty();
			if (!rows.length) {
				$list.append($('<p class="bmf-cal-placeholder"></p>').text('No open requests.'));
				return;
			}
			var $ul = $('<ul class="bmf-cal-items"></ul>');
			rows.forEach(function (a) {
				var $li = $('<li class="bmf-cal-item"></li>');
				var $main = $('<div class="bmf-cal-item-main"></div>');
				var who = a.member_name || a.member_email || 'Member';
				$main.append($('<div class="bmf-cal-item-subject"></div>').text(who + ' — ' + (a.subject || 'Appointment request')));
				var meta = whenHtml(a.start_ts, a.end_ts, a.start_fmt) + (a.unassigned ? ' · Unassigned' : '');
				$main.append($('<div class="bmf-cal-item-meta"></div>').html(
					'<span class="bmf-cal-item-when">' + meta + '</span> ' +
					'<span class="bmf-cal-status bmf-cal-status--requested">Requested</span>'
				));
				$li.append($main);
				var $actions = $('<div class="bmf-cal-item-actions"></div>');
				$actions.append(
					$('<button type="button" class="bmf-cal-btn bmf-cal-btn-sm bmf-cal-btn-primary"></button>')
						.text('Confirm')
						.on('click', function () { setStatus(a.id, 'confirmed'); })
				);
				$actions.append(
					$('<button type="button" class="bmf-cal-btn bmf-cal-btn-sm bmf-cal-btn-danger"></button>')
						.text('Decline')
						.on('click', function () { setStatus(a.id, 'cancelled'); })
				);
				$li.append($actions);
				$ul.append($li);
			});
			$list.append($ul);
		}

		function setStatus(id, status) {
			ajax('bmf_cal_set_status', { id: id, status: status })
				.done(function (res) {
					if (res && res.success) loadInbox();
					else alert((res && res.data && res.data.message) || 'Update failed');
				})
				.fail(function () { alert('Update failed'); });
		}

		loadInbox();
	}

	$(function () {
		applyTzNotes();

		$('.bmf-cal-list-panel').each(function () {
			initListPanel($(this));
		});

		$('.bmf-cal-provider-calendar').each(function () {
			initAvailability($(this));
		});

		$('.bmf-cal-request').each(function () {
			initRequest($(this));
		});

		$('.bmf-cal-open-requests').each(function () {
			initOpenRequests($(this));
		});

		$('.bmf-cal-agenda').each(function () {
			initAgenda($(this));
		});

		$('.bmf-cal-booked').each(function () {
			initBooked($(this));
		});
		$('.bmf-cal-coverage').each(function () {
			initCoverage($(this));
		});

		// ULS fires a native CustomEvent: detail = { email, user_id }
		// Match the pattern used by BioVoice and other BMF panels.
		document.addEventListener('uls:selected-member', function (ev) {
			var detail = (ev && ev.detail) || {};
			var userId = parseInt(detail.user_id || 0, 10) || 0;
			var email = (detail.email || '').trim();

			var panels = document.querySelectorAll('.bmf-cal-list-panel[data-mode="provider"]');
			if (!panels.length) return;

			panels.forEach(function (el) {
				var $p = $(el);
				setMemberContext($p, userId, email);
				loadAppointments($p);
			});
		});
	});
})(jQuery);
