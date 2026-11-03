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

	});
})();