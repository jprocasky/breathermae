/**
 * BreatherMae Ticker - Minimal reliable JS
 */
(function() {
    function init() {
        const tickers = document.querySelectorAll('.bm-ticker');
        tickers.forEach(function(ticker) {
            const track = ticker.querySelector('.bm-ticker__track');
            if (!track) return;

            const raw = ticker.dataset.duration || ticker.dataset.speed || '5';
            let secs = parseFloat(raw) || 5;
            if (secs < 2) secs = 2;

            track.style.setProperty('animation-duration', secs + 's', 'important');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
