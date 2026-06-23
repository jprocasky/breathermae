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
        // Support both new (data-duration) and legacy (data-speed)
        let durationSeconds = parseFloat(ticker.dataset.duration || ticker.dataset.speed) || 10;

        // Safety net - allow fast scrolling
        if (durationSeconds < 2 || durationSeconds > 120) {
            durationSeconds = 10;
        }

        const pauseOnHover = ticker.dataset.pauseOnHover === 'true';

        // Apply duration directly (simple and reliable)
        track.style.animationDuration = durationSeconds + 's';

        // Re-apply duration on resize (debounced)
        let resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                track.style.animationDuration = durationSeconds + 's';
            }, 150);
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
