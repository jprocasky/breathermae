/**
 * BreatherMae Ticker - Frontend JavaScript
 * Consistent pixel-based speed + smart duplication for all screen sizes
 */

document.addEventListener('DOMContentLoaded', function () {
    const tickers = document.querySelectorAll('.bm-ticker');

    tickers.forEach(function (ticker) {
        const track = ticker.querySelector('.bm-ticker__track');
        if (!track) return;

        const originalItems = track.querySelectorAll('.bm-ticker__item');
        if (originalItems.length === 0) return;

        // Read settings from data attributes set by the widget
        // data-duration = seconds for one full scroll loop (lower = faster)
        let durationSeconds = parseFloat(ticker.dataset.duration) || 12;

        // Safety net
        if (durationSeconds < 3 || durationSeconds > 120) {
            durationSeconds = 12;
        }

        const pauseOnHover = ticker.dataset.pauseOnHover === 'true';

        // Store reference to the original first item for cloning
        const firstItem = originalItems[0];

        /**
         * Duplicate items until the track is wide enough for seamless looping
         */
        function ensureEnoughWidth() {
            const containerWidth = ticker.offsetWidth || 300;
            let currentWidth = track.scrollWidth;

            const targetWidth = Math.max(containerWidth * 2.2, currentWidth * 2.2);

            while (currentWidth < targetWidth) {
                const clone = firstItem.cloneNode(true);
                track.appendChild(clone);
                currentWidth = track.scrollWidth;
            }
        }

        // Initial duplication
        ensureEnoughWidth();

        // Apply duration directly (simple and reliable)
        track.style.animationDuration = durationSeconds + 's';

        // Re-calculate on resize (debounced)
        let resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                // Clean up extra clones
                const allCurrentItems = track.querySelectorAll('.bm-ticker__item');
                while (allCurrentItems.length > originalItems.length) {
                    allCurrentItems[allCurrentItems.length - 1].remove();
                }
                ensureEnoughWidth();
                // Re-apply duration after resize
                track.style.animationDuration = durationSeconds + 's';
            }, 180);
        });

        // Pause on hover
        if (pauseOnHover) {
            ticker.addEventListener('mouseenter', function () {
                track.style.animationPlayState = 'paused';
            });
            ticker.addEventListener('mouseleave', function () {
                track.style.animationPlayState = 'running';
            });
        }

        // Respect reduced motion preference
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            track.style.animation = 'none';
        }
    });
});
