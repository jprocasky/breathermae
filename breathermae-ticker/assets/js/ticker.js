/**
 * BreatherMae Ticker - Character-based smooth streaming (2-copy seamless jump)
 */
(function() {
    'use strict';

    function initTicker(ticker) {
        const track = ticker.querySelector('.bm-ticker__track');
        if (!track) return;

        const items = track.querySelectorAll('.bm-ticker__item');
        if (items.length < 3) return; // Need 3 copies for smooth seamless looping

        const lettersPerSecond = parseFloat(ticker.dataset.lettersPerSecond) || 12;

        const firstItem = items[0];
        const oneCopyWidth = firstItem.offsetWidth;

        if (oneCopyWidth <= 0) return;

        // Estimate pixels per second from characters
        const totalChars = firstItem.textContent.length || 50;
        const avgCharWidth = oneCopyWidth / totalChars;
        const pixelsPerSecond = avgCharWidth * lettersPerSecond;

        let position = 0;
        let lastTime = performance.now();
        let isPaused = false;

        function animate(currentTime) {
            if (isPaused) {
                lastTime = currentTime;
                requestAnimationFrame(animate);
                return;
            }

            const delta = currentTime - lastTime;
            lastTime = currentTime;

            position -= (pixelsPerSecond * delta) / 1000;

            // Seamless jump
            if (position <= -oneCopyWidth) {
                position += oneCopyWidth;
            }

            track.style.transform = `translateX(${position}px)`;

            requestAnimationFrame(animate);
        }

        // Pause on hover
        if (ticker.dataset.pauseOnHover === 'true') {
            ticker.addEventListener('mouseenter', () => { isPaused = true; });
            ticker.addEventListener('mouseleave', () => {
                isPaused = false;
                lastTime = performance.now();
            });
        }

        requestAnimationFrame(animate);
    }

    function initAll() {
        document.querySelectorAll('.bm-ticker').forEach(initTicker);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
