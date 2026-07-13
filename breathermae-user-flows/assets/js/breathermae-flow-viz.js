document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('viz-flow-container');
    if (!container) return;

    // Try to get session_id from URL param or data attribute
    const urlParams = new URLSearchParams(window.location.search);
    let sessionId = urlParams.get('session_id') || container.dataset.sessionId || '';

    const controls = document.querySelector('.viz-controls');
    if (!controls) return;

    let currentRows = [];
    let dwells = [];
    let currentIndex = 0;
    let isPlaying = false;
    let playTimeout = null;
    let speedMultiplier = 1;

    const playBtn = document.getElementById('viz-play');
    const pauseBtn = document.getElementById('viz-pause');
    const speedSlider = document.getElementById('viz-speed');
    const speedVal = document.getElementById('speed-val');
    const resetBtn = document.getElementById('viz-reset');
    const stepBtn = document.getElementById('viz-step');

    function getColorForDwell(seconds) {
        if (seconds < 5) return '#40c6ff';
        if (seconds < 30) return '#67c9e0';
        if (seconds < 120) return '#FD5A38';
        return '#c73d1f';
    }

    function prettifySlug(slug) {
        if (!slug) return 'Home';
        return slug.replace(/-/g, ' ').replace(/\//g, ' → ').replace(/\b\w/g, l => l.toUpperCase());
    }

    function renderBlocks(rows) {
        container.innerHTML = '';
        currentRows = rows;
        dwells = [];

        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            let dwell = 0;
            if (i < rows.length - 1) {
                const t1 = new Date(rows[i].viewed_at);
                const t2 = new Date(rows[i + 1].viewed_at);
                dwell = Math.max(0, (t2 - t1) / 1000);
            } else {
                dwell = 8; // default for last block
            }
            dwells.push(dwell);

            const block = document.createElement('div');
            block.className = 'page-block';
            block.dataset.index = i;
            block.innerHTML = `
                <div class="step-number">${i + 1}</div>
                <div class="page-slug">${prettifySlug(row.page_url)}</div>
                <div class="dwell-badge">${dwell.toFixed(0)}s</div>
            `;
            block.style.background = getColorForDwell(dwell);
            block.style.color = dwell > 60 ? 'white' : '#222';

            block.addEventListener('click', () => {
                highlightBlock(i);
                showBlockInfo(i);
            });

            container.appendChild(block);
        }

        // Add legend
        const legend = document.createElement('div');
        legend.className = 'color-legend';
        legend.innerHTML = `
            <span><span class="color-swatch" style="background:#40c6ff"></span> &lt; 5s</span>
            <span><span class="color-swatch" style="background:#67c9e0"></span> 5–30s</span>
            <span><span class="color-swatch" style="background:#FD5A38"></span> 30s–2m</span>
            <span><span class="color-swatch" style="background:#c73d1f"></span> &gt; 2m</span>
        `;
        container.parentNode.appendChild(legend);
    }

    function highlightBlock(index) {
        document.querySelectorAll('.page-block').forEach((b, i) => {
            b.classList.toggle('active', i === index);
        });
        currentIndex = index;

        // Auto scroll into view
        const block = document.querySelector(`.page-block[data-index="${index}"]`);
        if (block) block.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
    }

    function showBlockInfo(index) {
        const row = currentRows[index];
        const dwell = dwells[index] || 0;
        const info = document.getElementById('viz-info') || document.createElement('div');
        info.id = 'viz-info';
        info.innerHTML = `
            <strong>Step ${index + 1}</strong> — ${prettifySlug(row.page_url)}<br>
            <small>Time spent: ${dwell.toFixed(1)}s &nbsp;|&nbsp; 
            ${new Date(row.viewed_at).toLocaleTimeString()}</small>
        `;
        if (!info.parentNode) container.parentNode.appendChild(info);
    }

    function playNext() {
        if (!isPlaying || currentIndex >= currentRows.length) {
            isPlaying = false;
            return;
        }

        highlightBlock(currentIndex);
        showBlockInfo(currentIndex);

        const dwell = dwells[currentIndex] || 3;
        const delay = Math.max(800, dwell * 1000 / speedMultiplier); // minimum 800ms per step

        playTimeout = setTimeout(() => {
            currentIndex++;
            if (currentIndex < currentRows.length) {
                playNext();
            } else {
                isPlaying = false;
            }
        }, delay);
    }

    // Playback controls
    if (playBtn) playBtn.addEventListener('click', () => {
        if (!currentRows.length) return;
        isPlaying = true;
        if (currentIndex >= currentRows.length - 1) currentIndex = 0;
        playNext();
    });

    if (pauseBtn) pauseBtn.addEventListener('click', () => {
        isPlaying = false;
        clearTimeout(playTimeout);
    });

    if (speedSlider) speedSlider.addEventListener('input', () => {
        speedMultiplier = parseFloat(speedSlider.value);
        if (speedVal) speedVal.textContent = speedMultiplier.toFixed(2) + 'x';
    });

    if (resetBtn) resetBtn.addEventListener('click', () => {
        isPlaying = false;
        clearTimeout(playTimeout);
        currentIndex = 0;
        highlightBlock(0);
        showBlockInfo(0);
    });

    if (stepBtn) stepBtn.addEventListener('click', () => {
        isPlaying = false;
        clearTimeout(playTimeout);
        currentIndex = (currentIndex + 1) % currentRows.length;
        highlightBlock(currentIndex);
        showBlockInfo(currentIndex);
    });

    // Main load function
    function loadSessionFlow(sessionIdToLoad) {
        if (!sessionIdToLoad) {
            container.innerHTML = '<p style="padding:20px">No session_id provided. Add <code>?session_id=xxxx</code> to the URL or pass it via shortcode attribute.</p>';
            return;
        }

        container.innerHTML = '<p class="loading">Loading user journey...</p>';

        fetch(breathermaeFlowViz.ajaxurl || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'breathermae_get_session_flow',
                session_id: sessionIdToLoad
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data.rows && data.data.rows.length > 0) {
                renderBlocks(data.data.rows);
                highlightBlock(0);
                showBlockInfo(0);
            } else {
                container.innerHTML = '<p>No journey data found for this session.</p>';
            }
        })
        .catch(err => {
            console.error(err);
            container.innerHTML = '<p>Error loading journey data.</p>';
        });
    }

    // Auto-load if session_id is available
    if (sessionId) {
        loadSessionFlow(sessionId);
    } else {
        container.innerHTML = '<p>Ready. Pass a <code>session_id</code> via URL parameter or shortcode attribute to load a user journey.</p>';
    }

    // Expose for debugging / future wiring
    window.BreatherMaeFlowViz = { loadSessionFlow };
});