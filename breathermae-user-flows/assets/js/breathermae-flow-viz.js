console.log('=== BreatherMaeFlowViz JS LOADED (Full Graph) ===');

document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('viz-flow-container');
    if (!container) {
        console.error('viz-flow-container not found');
        return;
    }

    const urlParams = new URLSearchParams(window.location.search);
    const sessionId = urlParams.get('session_id') || container.dataset.sessionId || '';

    console.log('Final sessionId resolved:', sessionId);

    let currentRows = [];
    let nodes = new Map();
    let edges = [];
    let orderedVisits = [];
    let isPlaying = false;
    let playTimeout = null;
    let speedMultiplier = 1;
    let currentStep = 0;

    function getColorForDwell(seconds) {
        if (seconds < 10) return '#40c6ff';
        if (seconds < 45) return '#67c9e0';
        if (seconds < 180) return '#FD5A38';
        return '#c73d1f';
    }

    function prettifySlug(slug) {
        if (!slug || slug === 'home') return 'Home';
        return slug.replace(/-/g, ' ').replace(/\//g, ' → ').replace(/\b\w/g, l => l.toUpperCase());
    }

    function processData(rows) {
        nodes.clear();
        edges = [];
        orderedVisits = rows.map(r => r.page_url);

        const transitionMap = new Map();

        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            const page = row.page_url;

            if (!nodes.has(page)) {
                nodes.set(page, {
                    page_url: page,
                    totalDwell: 0,
                    visitCount: 0,
                    firstSeen: row.viewed_at,
                    lastSeen: row.viewed_at
                });
            }

            const node = nodes.get(page);
            node.visitCount++;

            let dwell = 8;
            if (i < rows.length - 1) {
                const t1 = new Date(row.viewed_at);
                const t2 = new Date(rows[i + 1].viewed_at);
                dwell = Math.max(1, (t2 - t1) / 1000);
            }
            node.totalDwell += dwell;

            if (new Date(row.viewed_at) < new Date(node.firstSeen)) node.firstSeen = row.viewed_at;
            if (new Date(row.viewed_at) > new Date(node.lastSeen)) node.lastSeen = row.viewed_at;

            if (i > 0) {
                const prev = rows[i - 1].page_url;
                const key = `${prev}|||${page}`;
                transitionMap.set(key, (transitionMap.get(key) || 0) + 1);
            }
        }

        transitionMap.forEach((count, key) => {
            const [from, to] = key.split('|||');
            edges.push({ from, to, count });
        });

        return { nodes, edges, orderedVisits };
    }

    function calculateColumns(count) {
        if (count <= 4) return count;
        if (count <= 9) return 3;
        if (count <= 16) return 4;
        return Math.ceil(Math.sqrt(count));
    }

    function renderNodes(nodeArray) {
        container.innerHTML = '';
        container.style.position = 'relative';
        container.style.display = 'grid';
        container.style.gap = '48px';
        container.style.padding = '30px 20px';

        const cols = calculateColumns(nodeArray.length);
        container.style.gridTemplateColumns = `repeat(${cols}, minmax(160px, 1fr))`;

        nodeArray.forEach(node => {
            const el = document.createElement('div');
            el.className = 'page-block graph-node';
            el.dataset.page = node.page_url;
            el.style.background = getColorForDwell(node.totalDwell);
            el.style.color = node.totalDwell > 120 ? 'white' : '#222';

            el.innerHTML = `
                <div class="page-slug">${prettifySlug(node.page_url)}</div>
                <div class="dwell-badge">${node.totalDwell.toFixed(0)}s total • ${node.visitCount} visits</div>
            `;

            el.addEventListener('click', () => showNodeDetails(node));
            container.appendChild(el);
        });
    }

    function renderConnectors(nodeArray, edgeList) {
        let svg = container.querySelector('svg.viz-connectors');
        if (!svg) {
            svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.classList.add('viz-connectors');
            svg.style.position = 'absolute';
            svg.style.top = '0';
            svg.style.left = '0';
            svg.style.width = '100%';
            svg.style.height = '100%';
            svg.style.pointerEvents = 'none';
            svg.style.zIndex = '1';
            container.appendChild(svg);
        }
        svg.innerHTML = '';

        const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
        defs.innerHTML = `
            <marker id="arrowhead" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">
                <polygon points="0 0, 10 3.5, 0 7" fill="#555" />
            </marker>
        `;
        svg.appendChild(defs);

        edgeList.forEach(edge => {
            const fromEl = container.querySelector(`[data-page="${edge.from}"]`);
            const toEl = container.querySelector(`[data-page="${edge.to}"]`);
            if (!fromEl || !toEl) return;

            const fromRect = fromEl.getBoundingClientRect();
            const toRect = toEl.getBoundingClientRect();
            const containerRect = container.getBoundingClientRect();

            const fromCenterX = fromRect.left + fromRect.width / 2 - containerRect.left;
            const fromCenterY = fromRect.top + fromRect.height / 2 - containerRect.top;
            const toCenterX = toRect.left + toRect.width / 2 - containerRect.left;
            const toCenterY = toRect.top + toRect.height / 2 - containerRect.top;

            const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', fromCenterX);
            line.setAttribute('y1', fromCenterY);
            line.setAttribute('x2', toCenterX);
            line.setAttribute('y2', toCenterY);
            line.setAttribute('stroke', '#555');
            line.setAttribute('stroke-width', '2.2');
            line.setAttribute('marker-end', 'url(#arrowhead)');
            line.dataset.from = edge.from;
            line.dataset.to = edge.to;

            svg.appendChild(line);
        });
    }

    function showNodeDetails(node) {
        const info = document.getElementById('viz-info');
        info.innerHTML = `
            <strong>${prettifySlug(node.page_url)}</strong><br>
            <small>Total time: ${node.totalDwell.toFixed(1)}s &nbsp;|&nbsp; Visits: ${node.visitCount}</small>
        `;
    }

    function playNext() {
        if (!isPlaying || currentStep >= orderedVisits.length) {
            isPlaying = false;
            return;
        }

        const currentPage = orderedVisits[currentStep];
        document.querySelectorAll('.page-block').forEach(el => el.classList.remove('active'));
        const nodeEl = container.querySelector(`[data-page="${currentPage}"]`);
        if (nodeEl) nodeEl.classList.add('active');

        const svg = container.querySelector('svg.viz-connectors');
        if (svg && currentStep > 0) {
            const prevPage = orderedVisits[currentStep - 1];
            svg.querySelectorAll('line').forEach(line => {
                line.classList.remove('active-edge');
                line.setAttribute('stroke', '#555');
                line.setAttribute('stroke-width', '2.2');
                if (line.dataset.from === prevPage && line.dataset.to === currentPage) {
                    line.classList.add('active-edge');
                    line.setAttribute('stroke', '#40c6ff');
                    line.setAttribute('stroke-width', '3.5');
                }
            });
        }

        const dwell = nodes.get(currentPage)?.totalDwell || 3;
        const delay = Math.max(650, (dwell * 850) / speedMultiplier);

        playTimeout = setTimeout(() => {
            currentStep++;
            playNext();
        }, delay);
    }

    function renderGraph(rows) {
        if (!rows || rows.length === 0) {
            container.innerHTML = '<p>No journey data found.</p>';
            return;
        }

        currentRows = rows;
        const processed = processData(rows);
        const nodeArray = Array.from(processed.nodes.values());
        nodeArray.sort((a, b) => new Date(a.firstSeen) - new Date(b.firstSeen));

        renderNodes(nodeArray);

        requestAnimationFrame(() => {
            renderConnectors(nodeArray, processed.edges);
        });

        orderedVisits = processed.orderedVisits;
        currentStep = 0;
        // Attach control listeners after rendering (dynamic content)
        const playBtn = document.getElementById('viz-play');
        const pauseBtn = document.getElementById('viz-pause');
        const speedSlider = document.getElementById('viz-speed');
        const speedVal = document.getElementById('speed-val');
        const resetBtn = document.getElementById('viz-reset');
        const stepBtn = document.getElementById('viz-step');

        if (playBtn) playBtn.addEventListener('click', () => {
            if (!orderedVisits.length) return;
            isPlaying = true;
            if (currentStep >= orderedVisits.length) currentStep = 0;
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
            currentStep = 0;
            document.querySelectorAll('.page-block').forEach(el => el.classList.remove('active'));
            const svg = container.querySelector('svg.viz-connectors');
            if (svg) svg.querySelectorAll('line').forEach(l => {
                l.classList.remove('active-edge');
                l.setAttribute('stroke', '#555');
                l.setAttribute('stroke-width', '1');
            });
        });

        if (stepBtn) stepBtn.addEventListener('click', () => {
            isPlaying = false;
            clearTimeout(playTimeout);
            currentStep = (currentStep + 1) % orderedVisits.length;
            
            // Highlight current node
            document.querySelectorAll('.page-block').forEach(el => el.classList.remove('active'));
            const nodeEl = container.querySelector(`[data-page="${orderedVisits[currentStep]}"]`);
            if (nodeEl) nodeEl.classList.add('active');
            
            // Highlight the connecting edge
            const svg = container.querySelector('svg.viz-connectors');
            if (svg && currentStep > 0) {
                const prevPage = orderedVisits[currentStep - 1];
                svg.querySelectorAll('line').forEach(line => {
                    line.classList.remove('active-edge');
                    line.setAttribute('stroke', '#555');
                    line.setAttribute('stroke-width', '1');
                    if (line.dataset.from === prevPage && line.dataset.to === orderedVisits[currentStep]) {
                        line.classList.add('active-edge');
                        line.setAttribute('stroke', '#40c6ff');
                        line.setAttribute('stroke-width', '4');
                    }
                });
            }
            
            // Optional: show info
            const currentNode = Array.from(nodes.values()).find(n => n.page_url === orderedVisits[currentStep]);
            if (currentNode) showNodeDetails(currentNode);
        });

    }

    // Expose loader
    window.BreatherMaeFlowViz = {
        loadSessionFlow: function(sid) {
            console.log('loadSessionFlow called with:', sid);
            if (!sid) {
                container.innerHTML = '<p style="color:red">No session_id provided.</p>';
                return;
            }
            container.innerHTML = '<p class="loading">Building journey graph...</p>';

            fetch(breathermaeFlowViz.ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'breathermae_get_session_flow',
                    session_id: sid
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.data.rows && data.data.rows.length) {
                    renderGraph(data.data.rows);
                } else {
                    container.innerHTML = '<p>No journey data found for this session.</p>';
                }
            })
            .catch(err => {
                console.error('Fetch error:', err);
                container.innerHTML = '<p>Error loading journey data.</p>';
            });
        }
    };

    console.log('BreatherMaeFlowViz global exposed successfully');

    if (sessionId) {
        window.BreatherMaeFlowViz.loadSessionFlow(sessionId);
    }


    // Playback controls
    if (playBtn) playBtn.addEventListener('click', () => {
        if (!orderedVisits.length) return;
        isPlaying = true;
        if (currentStep >= orderedVisits.length) currentStep = 0;
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
        currentStep = 0;
        document.querySelectorAll('.page-block').forEach(el => el.classList.remove('active'));
        const svg = container.querySelector('svg.viz-connectors');
        if (svg) svg.querySelectorAll('line').forEach(l => {
            l.classList.remove('active-edge');
            l.setAttribute('stroke', '#555');
            l.setAttribute('stroke-width', '2.2');
        });
    });

    if (stepBtn) stepBtn.addEventListener('click', () => {
        isPlaying = false;
        clearTimeout(playTimeout);
        currentStep = (currentStep + 1) % orderedVisits.length;
    });

});