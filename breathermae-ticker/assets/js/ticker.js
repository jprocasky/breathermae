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

        // Read duration (lower number = faster scrolling)
        let durationSeconds = parseFloat(ticker.dataset.duration) || 6;

        if (durationSeconds < 2) durationSeconds = 6;

        const pauseOnHover = ticker.dataset.pauseOnHover === 'true';

        // Set duration
        track.style.animationDuration = durationSeconds + 's';

        // Pause on hover (simple)
        if (pauseOnHover) {
            ticker.addEventListener('mouseenter', function() {
                track.style.animationPlayState = 'paused';
            });
            ticker.addEventListener('mouseleave', function() {
                track.style.animationPlayState = 'running';
            });
        }
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
