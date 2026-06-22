/**
 * BreatherMae Ticker - Frontend JavaScript
 * Handles dynamic animation duration and pause-on-hover behavior
 */

document.addEventListener('DOMContentLoaded', function () {
    const tickers = document.querySelectorAll('.bm-ticker');

    tickers.forEach(function (ticker) {
        const track = ticker.querySelector('.bm-ticker__track');
        if (!track) return;

        // Read settings from data attributes (set by Elementor widget)
        const speedSeconds = parseInt(ticker.dataset.speed) || 25;
        const pauseOnHover = ticker.dataset.pauseOnHover === 'true';

        // Set animation duration dynamically
        track.style.animationDuration = speedSeconds + 's';

        // Optional: Add a class for styling when paused
        if (pauseOnHover) {
            ticker.addEventListener('mouseenter', function () {
                track.style.animationPlayState = 'paused';
            });

            ticker.addEventListener('mouseleave', function () {
                track.style.animationPlayState = 'running';
            });
        }

        // Accessibility: respect reduced motion preference
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            track.style.animation = 'none';
        }
    });
});
