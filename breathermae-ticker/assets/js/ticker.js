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
        // scrollSpeed = pixels per second (higher = faster)
        let pixelsPerSecond = parseFloat(ticker.dataset.speed) || 45;

        // Safety net: if the value is very small (< 10), it's likely from an old widget
        // saved with the previous "seconds" control. Use a sensible default instead.
        if (pixelsPerSecond < 10) {
            pixelsPerSecond = 45;
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

            // We want the track to be at least ~2.5x container width for smooth looping
            const targetWidth = containerWidth * 2.5;

            while (currentWidth < targetWidth) {
                const clone = firstItem.cloneNode(true);
                track.appendChild(clone);
                currentWidth = track.scrollWidth;
            }
        }

        // Initial duplication
        ensureEnoughWidth();

        /**
         * Calculate and apply animation duration based on actual width
         * This keeps visual speed consistent across screen sizes
         */
        function setAnimationDuration() {
            // Measure the width of one full "set" (half the track after duplication)
            const totalWidth = track.scrollWidth;
            const oneSetWidth = totalWidth / 2; // because we duplicate enough to make two sets

            if (oneSetWidth <= 0) return;

            // duration (seconds) = distance / speed
            const durationSeconds = oneSetWidth / pixelsPerSecond;

            track.style.animationDuration = durationSeconds + 's';
        }

        // Set initial duration after duplication
        setAnimationDuration();

        // Re-calculate on resize (debounced)
        let resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                // Clean up extra clones, keep originals + re-duplicate
                const allCurrentItems = track.querySelectorAll('.bm-ticker__item');
                while (allCurrentItems.length > originalItems.length) {
                    allCurrentItems[allCurrentItems.length - 1].remove();
                }
                ensureEnoughWidth();
                setAnimationDuration();
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
