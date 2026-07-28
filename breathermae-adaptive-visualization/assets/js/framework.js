(() => {
    "use strict";

    class BreathermaeEightPillarsDashboard {
        constructor(config) {
            this.config = config;
            this.root = document.getElementById(config.instanceId);
            this.data = null;
        }

        async initialize() {
            if (!this.root) return;

            try {
                const response = await fetch(this.config.restUrl, {
                    method: "GET",
                    credentials: "same-origin",
                    headers: {
                        "Accept": "application/json",
                        "X-WP-Nonce": this.config.nonce
                    }
                });

                if (!response.ok) {
                    throw new Error(`Dashboard request failed with status ${response.status}`);
                }

                this.data = await response.json();

                if (this.data.status === "validation_error") {
                    this.renderValidationError();
                    return;
                }

                this.render();
            } catch (error) {
                console.error("[Breathermae AVF]", error);
                this.renderError();
            }
        }

        render() {
            this.root.innerHTML = "";
            this.root.append(
                this.renderHeader(),
                this.renderSummary(),
                this.renderHistoryChart(),
                this.renderPillarGrid(),
                this.renderDataNotice()
            );
        }

        renderHeader() {
            const header = this.el("header", "bmae-avf-header");
            const heading = this.el("div", "");
            heading.append(
                this.el("span", "bmae-avf-eyebrow", "Adaptive Health Intelligence"),
                this.el("h2", "", this.config.title),
                this.el(
                    "p",
                    "",
                    "Quarterly, longitudinal visibility across all eight dimensions of wellness."
                )
            );

            const current = this.data.current;
            const score = current?.overall_score ?? "—";
            const band = current?.overall_band?.label ?? "No data";
            const scoreBlock = this.el("div", "bmae-avf-overall-score");
            scoreBlock.append(
                this.el("span", "", "Current Wellness"),
                this.el("strong", "", score),
                this.el("small", `bmae-avf-band bmae-avf-band--${(band || "").toLowerCase()}`, band)
            );

            header.append(heading, scoreBlock);
            return header;
        }

        renderSummary() {
            const summary = this.data.summary || {};
            const section = this.el("section", "bmae-avf-summary-grid");
            const change = summary.total_change;
            const formattedChange = change === null || change === undefined
                ? "—"
                : `${change > 0 ? "+" : ""}${change}`;

            section.append(
                this.metric("Baseline", summary.baseline_score ?? "—", "First assessment"),
                this.metric("Current", summary.current_score ?? "—", "Most recent assessment"),
                this.metric("Total Change", formattedChange, this.humanDirection(summary.direction)),
                this.metric(
                    "Most Improved",
                    summary.most_improved_pillar?.label ?? "—",
                    summary.most_improved_pillar
                        ? `${this.signed(summary.most_improved_pillar.change)} points`
                        : "Insufficient history"
                ),
                this.metric(
                    "Priority Area",
                    summary.priority_pillar?.label ?? "—",
                    summary.priority_pillar
                        ? `${summary.priority_pillar.score} current score`
                        : "No data"
                )
            );

            return section;
        }

        metric(label, value, detail) {
            const card = this.el("article", "bmae-avf-metric");
            card.append(
                this.el("span", "", label),
                this.el("strong", "", value),
                this.el("small", "", detail)
            );
            return card;
        }

        renderHistoryChart() {
            const section = this.el("section", "bmae-avf-panel");
            const titleRow = this.el("div", "bmae-avf-panel__title");
            titleRow.append(
                this.el("div", "", this.el("h3", "", "Overall Wellness Evolution"), this.el("p", "", "Historical assessment progression")),
                this.el("span", "bmae-avf-cadence", "Quarterly")
            );

            const history = this.data.history || [];
            const values = history.map(item => Number(item.overall_score || 0));
            const labels = history.map(item => item.label);
            const chart = this.lineChart(values, labels);

            section.append(titleRow, chart);
            return section;
        }

        lineChart(values, labels) {
            const width = 900;
            const height = 250;
            const pad = { left: 48, right: 24, top: 20, bottom: 46 };
            const plotWidth = width - pad.left - pad.right;
            const plotHeight = height - pad.top - pad.bottom;

            const svg = this.svg("svg", {
                viewBox: `0 0 ${width} ${height}`,
                role: "img",
                "aria-label": "Overall wellness historical trend"
            });
            svg.classList.add("bmae-avf-chart");

            [0, 20, 40, 60, 80, 100].forEach(score => {
                const y = pad.top + plotHeight - (score / 100) * plotHeight;
                svg.append(
                    this.svg("line", { x1: pad.left, y1: y, x2: width - pad.right, y2: y, class: "grid-line" }),
                    this.svgText(8, y + 4, String(score), "axis-label")
                );
            });

            if (values.length === 0) return svg;

            const x = index => values.length === 1
                ? pad.left + plotWidth / 2
                : pad.left + (index / (values.length - 1)) * plotWidth;
            const y = value => pad.top + plotHeight - (value / 100) * plotHeight;
            const points = values.map((value, index) => `${x(index)},${y(value)}`).join(" ");

            svg.append(this.svg("polyline", {
                points,
                class: "trend-line",
                fill: "none"
            }));

            values.forEach((value, index) => {
                svg.append(
                    this.svg("circle", { cx: x(index), cy: y(value), r: 5, class: "trend-point" }),
                    this.svgText(x(index), y(value) - 12, String(value), "point-label", "middle"),
                    this.svgText(x(index), height - 15, labels[index], "axis-label", "middle")
                );
            });

            return svg;
        }

        renderPillarGrid() {
            const section = this.el("section", "bmae-avf-pillars-section");
            section.append(
                this.el("div", "bmae-avf-section-heading",
                    this.el("div", "", this.el("h3", "", "Eight Pillars of Wellness"), this.el("p", "", "Select a pillar to reveal its complete subcategory profile."))
                )
            );

            const grid = this.el("div", "bmae-avf-pillar-grid");
            const currentPillars = this.data.current?.pillars || {};
            const history = this.data.history || [];

            (this.data.registry || []).forEach(definition => {
                const current = currentPillars[definition.id];
                grid.append(this.pillarCard(definition, current, history));
            });

            section.append(grid);
            return section;
        }

        pillarCard(definition, current, history) {
            const article = this.el("article", "bmae-avf-pillar-card");
            article.style.setProperty("--pillar-accent", definition.accent || "#52dfd2");

            const header = this.el("button", "bmae-avf-pillar-card__header");
            header.type = "button";
            header.setAttribute("aria-expanded", "false");

            const name = this.el("div", "");
            name.append(
                this.el("span", "bmae-avf-pillar-index", definition.short_label),
                this.el("h4", "", definition.label)
            );

            const scoreBox = this.el("div", "bmae-avf-pillar-score");
            const score = current?.score ?? "—";
            const change = current?.change;
            scoreBox.append(
                this.el("strong", "", score),
                this.el("small", change === null || change === undefined ? "Baseline" : `${this.signed(change)} pts`)
            );

            header.append(name, scoreBox);

            const sparkValues = history
                .map(item => item.pillars?.[definition.id]?.score)
                .filter(value => value !== null && value !== undefined)
                .map(Number);

            const body = this.el("div", "bmae-avf-pillar-card__body");
            body.hidden = true;
            body.append(this.sparkline(sparkValues));

            const list = this.el("div", "bmae-avf-subcategory-list");
            (current?.subcategories || []).forEach(subcategory => {
                const row = this.el("div", "bmae-avf-subcategory");
                row.append(
                    this.el("span", "", subcategory.label),
                    this.el("strong", "", subcategory.score ?? "—"),
                    this.el(
                        "small",
                        `bmae-avf-band bmae-avf-band--${(subcategory.band?.id || "none")}`,
                        subcategory.band?.label ?? "No data"
                    )
                );
                list.append(row);
            });

            body.append(list);
            header.addEventListener("click", () => {
                const expanded = header.getAttribute("aria-expanded") === "true";
                header.setAttribute("aria-expanded", String(!expanded));
                body.hidden = expanded;
                article.classList.toggle("is-expanded", !expanded);
            });

            article.append(header, body);
            return article;
        }

        sparkline(values) {
            const width = 320;
            const height = 70;
            const pad = 7;
            const svg = this.svg("svg", {
                viewBox: `0 0 ${width} ${height}`,
                "aria-hidden": "true"
            });
            svg.classList.add("bmae-avf-sparkline");

            if (values.length === 0) return svg;

            const x = index => values.length === 1
                ? width / 2
                : pad + (index / (values.length - 1)) * (width - pad * 2);
            const y = value => pad + (1 - value / 100) * (height - pad * 2);
            const points = values.map((value, index) => `${x(index)},${y(value)}`).join(" ");

            svg.append(this.svg("polyline", { points, fill: "none" }));
            return svg;
        }

        renderDataNotice() {
            const notice = this.el("div", "bmae-avf-data-notice");
            const source = this.data.source === "demo" ? "Demonstration data" : "Breathermae platform data";
            notice.append(
                this.el("strong", "", source),
                this.el(
                    "span",
                    "",
                    this.data.source === "demo"
                        ? " is currently displayed so the dashboard integration can be tested before the production data adapter is connected."
                        : " is supplying this dashboard through the bmae_avf_eight_pillars_history filter."
                )
            );

            if (this.data.validation?.warnings?.length) {
                notice.append(this.el("small", "", `${this.data.validation.warnings.length} data warning(s) were normalized.`));
            }

            return notice;
        }

        renderValidationError() {
            this.root.innerHTML = "";
            const box = this.el("div", "bmae-avf-error");
            box.append(
                this.el("strong", "", "Eight Pillars data validation failed"),
                this.el("p", "", "Correct the following data-contract issues before rendering the dashboard.")
            );

            const list = this.el("ul", "");
            (this.data.validation?.errors || []).forEach(error => list.append(this.el("li", "", error)));
            box.append(list);
            this.root.append(box);
        }

        renderError() {
            this.root.innerHTML = "";
            const error = this.el(
                "div",
                "bmae-avf-error",
                this.el("strong", "", "Dashboard initialization failed"),
                this.el("p", "", "Confirm that the user is signed in and that the WordPress REST API is available.")
            );
            this.root.append(error);
        }

        humanDirection(direction) {
            const labels = {
                improving: "Improving",
                declining: "Declining",
                stable: "Stable",
                insufficient_data: "Insufficient history"
            };
            return labels[direction] || "Unknown";
        }

        signed(value) {
            const number = Number(value);
            return `${number > 0 ? "+" : ""}${number}`;
        }

        el(tag, className = "", ...children) {
            const node = document.createElement(tag);
            if (className) node.className = className;
            children.flat().forEach(child => {
                if (child === null || child === undefined || child === false) return;
                node.append(child instanceof Node ? child : document.createTextNode(String(child)));
            });
            return node;
        }

        svg(tag, attributes = {}) {
            const node = document.createElementNS("http://www.w3.org/2000/svg", tag);
            Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value));
            return node;
        }

        svgText(x, y, text, className, anchor = "start") {
            const node = this.svg("text", { x, y, class: className, "text-anchor": anchor });
            node.textContent = text;
            return node;
        }
    }

    const boot = () => {
        const queue = window.BMAE_AVF_QUEUE || [];
        queue.forEach(config => new BreathermaeEightPillarsDashboard(config).initialize());
        window.BMAE_AVF_QUEUE = [];
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }
})();
