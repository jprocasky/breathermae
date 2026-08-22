/**
 * BMF Help Tour – client engine
 * Discovers steps via bmf-help-* attributes and runs Driver.js.
 */
(function () {
	'use strict';

	if (typeof window.bmfHelpTour === 'undefined') {
		return;
	}

	const cfg = window.bmfHelpTour;
	const log = cfg.debug ? console.log.bind(console, '[BMF Help Tour]') : function () {};

	/** Cached after first discovery so restart works even after auto-skip. */
	let cachedSteps = null;
	let cachedTourId = null;

	/**
	 * Is this element actually visible in the *current* viewport?
	 *
	 * Elementor responsive classes (elementor-hidden-mobile, etc.) stay on the
	 * element at every breakpoint; CSS media queries apply display:none only
	 * when the viewport matches. So we must NOT key off class names — only
	 * computed style + size. WP Fusion elements that never rendered are already
	 * absent from the DOM.
	 */
	function isElementVisible(el) {
		if (!el || !el.isConnected) {
			return false;
		}

		const style = window.getComputedStyle(el);
		if (
			style.display === 'none' ||
			style.visibility === 'hidden' ||
			parseFloat(style.opacity) === 0
		) {
			return false;
		}

		// Ancestor with display:none (e.g. parent container hidden on this breakpoint)
		let parent = el.parentElement;
		while (parent && parent !== document.body) {
			const ps = window.getComputedStyle(parent);
			if (ps.display === 'none') {
				return false;
			}
			parent = parent.parentElement;
		}

		// Zero-size (collapsed or not laid out)
		const rect = el.getBoundingClientRect();
		if (rect.width < 1 && rect.height < 1) {
			return false;
		}

		return true;
	}

	/**
	 * If an attribute value is an exact doubled string ("Foo Foo"),
	 * return a single copy. Handles controls + leftover Custom Attributes merge.
	 */
	function dedupeAttr(raw) {
		const val = (raw || '').trim();
		if (!val) {
			return '';
		}
		// Exact half+half with a single space in the middle is the common failure mode.
		const mid = Math.floor(val.length / 2);
		// "ABC ABC" → length 7, mid 3 → left "ABC", right "ABC"
		if (val.length >= 3 && val.charAt(mid) === ' ') {
			const left = val.substring(0, mid);
			const right = val.substring(mid + 1);
			if (left === right) {
				return left;
			}
		}
		// Also handle no-space concatenation "ABCABC" when even length.
		if (val.length >= 2 && val.length % 2 === 0) {
			const half = val.length / 2;
			const left = val.substring(0, half);
			const right = val.substring(half);
			if (left === right) {
				return left;
			}
		}
		return val;
	}

	/**
	 * Collect all elements that have a bmf-help-step attribute.
	 * Skips targets that are hidden (responsive / CSS / ancestor).
	 * Returns sorted array of step objects.
	 */
	function discoverSteps() {
		const nodes = document.querySelectorAll('[bmf-help-step]');
		const steps = [];

		nodes.forEach((el) => {
			const stepNum = parseInt(el.getAttribute('bmf-help-step'), 10);
			if (isNaN(stepNum) || stepNum < 1) {
				return;
			}

			// Collapse accidental duplicates from attribute merge
			// (e.g. "Title Title" when controls + Custom Attributes both set the same value).
			const title = dedupeAttr(el.getAttribute('bmf-help-title'));
			const text  = dedupeAttr(el.getAttribute('bmf-help-text'));

			if (!title && !text) {
				return; // nothing useful to show
			}

			if (!isElementVisible(el)) {
				log('Skipping hidden step', stepNum, title || text.substring(0, 40));
				return;
			}

			steps.push({
				element: el,
				step: stepNum,
				title: title,
				text: text,
				icon: (el.getAttribute('bmf-help-icon') || '').trim(),
				importance: (el.getAttribute('bmf-help-importance') || 'info').trim().toLowerCase(),
			});
		});

		// Sort by step number ascending
		steps.sort((a, b) => a.step - b.step);

		return steps;
	}

	/**
	 * Stable tour ID for this page.
	 * Keyed only by post/page ID so completion survives:
	 * - title/text edits
	 * - mobile vs desktop (different visible steps)
	 * - WP Fusion show/hide of individual steps
	 * One auto-start per page per user; restart button clears it.
	 */
	function buildTourId(steps) {
		const postId = cfg.postId || 0;
		return 'p' + postId;
	}

	/**
	 * Map icon key → prefix shown in the popover title.
	 */
	const ICON_PREFIX = {
		arrow: '→ ',
		info: 'ℹ ',
		warning: '⚠ ',
		question: '? ',
	};

	/**
	 * Convert our step objects into Driver.js step config.
	 */
	function toDriverSteps(steps) {
		return steps.map((s, index) => {
			const importance = s.importance || 'info';
			const iconKey = (s.icon || '').toLowerCase();
			const iconPrefix = ICON_PREFIX[iconKey] || '';
			const title = (s.title || '').trim();

			const popover = {
				title: iconPrefix + title,
				description: s.text || '',
				side: 'bottom',          // mobile-friendly default
				align: 'start',
				// Per-step class so importance styles apply (Driver supports this).
				popoverClass: 'bmf-help-popover bmf-help-imp-' + importance,
				showButtons: ['next', 'previous', 'close'],
				nextBtnText: cfg.strings.next,
				prevBtnText: cfg.strings.prev,
				doneBtnText: cfg.strings.done,
			};

			// Last step uses "Done"
			if (index === steps.length - 1) {
				popover.showButtons = ['previous', 'close'];
			}

			return {
				element: s.element,
				popover: popover,
			};
		});
	}

	/**
	 * Persist completion via AJAX.
	 */
	function markComplete(tourId) {
		const body = new FormData();
		body.append('action', 'bmf_help_tour_complete');
		body.append('nonce', cfg.nonce);
		body.append('tour_id', tourId);

		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		})
			.then((r) => r.json())
			.then((data) => {
				log('Tour marked complete', data);
				return data;
			})
			.catch((err) => {
				console.warn('[BMF Help Tour] Failed to save completion', err);
			});
	}

	/**
	 * Remove tour from completed list so it can run again.
	 */
	function resetTour(tourId) {
		const body = new FormData();
		body.append('action', 'bmf_help_tour_reset');
		body.append('nonce', cfg.nonce);
		body.append('tour_id', tourId);

		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		})
			.then((r) => r.json())
			.then((data) => {
				log('Tour reset', data);
				// Keep local completed list in sync
				if (data && data.success && Array.isArray(cfg.completed)) {
					cfg.completed = cfg.completed.filter((id) => id !== tourId);
				}
				return data;
			})
			.catch((err) => {
				console.warn('[BMF Help Tour] Failed to reset tour', err);
			});
	}

	/**
	 * Main entry.
	 */
	function init() {
		const steps = discoverSteps();
		if (steps.length === 0) {
			log('No help steps found on this page');
			return;
		}

		const tourId = buildTourId(steps);
		cachedSteps = steps;
		cachedTourId = tourId;

		log('Tour ID:', tourId, 'Steps:', steps.length);

		// Always expose restart helpers
		window.bmfHelpTourStart = function () {
			runTour(cachedSteps, cachedTourId);
		};

		window.bmfHelpTourRestart = function () {
			if (!cachedTourId) {
				return;
			}
			resetTour(cachedTourId).then(function () {
				runTour(cachedSteps, cachedTourId);
			});
		};

		// Already completed?
		if (Array.isArray(cfg.completed) && cfg.completed.indexOf(tourId) !== -1) {
			log('Tour already completed – skipping auto-start');
			bindRestartClicks();
			return;
		}

		// Small delay so Elementor / other scripts finish layout
		setTimeout(function () {
			runTour(steps, tourId);
			bindRestartClicks();
		}, 600);
	}

	function runTour(steps, tourId) {
		if (typeof window.driver === 'undefined' || !window.driver.js) {
			console.error('[BMF Help Tour] Driver.js not loaded');
			return;
		}

		const driverFactory = window.driver.js.driver;
		let hasMarked = false;

		const driver = driverFactory({
			showProgress: true,
			animate: true,
			// Instant scroll is more predictable for popover placement on mobile.
			// Smooth scroll often finishes *after* Driver positions the popover.
			smoothScroll: false,
			allowClose: true,
			overlayOpacity: 0.55,
			stagePadding: 8,
			stageRadius: 8,
			popoverOffset: 12,
			popoverClass: 'bmf-help-popover',
			progressText: '{{current}} of {{total}}',
			nextBtnText: cfg.strings.next,
			prevBtnText: cfg.strings.prev,
			doneBtnText: cfg.strings.done,
			// After highlight + any scroll settles, re-measure so the popover
			// doesn't sit on top of the target (common on mobile).
			onHighlighted: function () {
				window.setTimeout(function () {
					try {
						driver.refresh();
					} catch (e) {
						/* ignore if already destroyed */
					}
				}, 180);
			},
			// Mark complete whether they finish or skip/close.
			// v1 treats "seen once" as success.
			onDestroyStarted: function () {
				if (!hasMarked) {
					hasMarked = true;
					markComplete(tourId);
				}
				driver.destroy();
			},
			steps: toDriverSteps(steps),
		});

		driver.drive();

		// Re-bind so a mid-tour restart still works
		window.bmfHelpTourStart = function () {
			hasMarked = false;
			driver.drive();
		};
	}

	/**
	 * Click handler for any .bmf-help-tour-restart element
	 * (shortcode output or class added to an Elementor icon/link).
	 */
	function bindRestartClicks() {
		document.addEventListener('click', function (e) {
			const trigger = e.target.closest('.bmf-help-tour-restart');
			if (!trigger) {
				return;
			}
			e.preventDefault();
			if (typeof window.bmfHelpTourRestart === 'function') {
				window.bmfHelpTourRestart();
			}
		});
	}

	// Boot
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
