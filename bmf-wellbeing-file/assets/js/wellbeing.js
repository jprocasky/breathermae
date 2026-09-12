(function () {
	function onReady(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
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
				}
			})
			.catch(function () { /* keep existing markup */ })
			.finally(function () { wrap.classList.remove('is-loading'); });
	}

	onReady(function () {
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
