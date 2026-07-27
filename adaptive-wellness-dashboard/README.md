# Adaptive Wellness Visualization Engine

A configuration-driven, longitudinally aware dashboard architecture for the Eight Pillars of Wellness.

## What this project implements

- Every pillar and subcategory is represented as an independent computational variable.
- Pillar scores can be supplied directly or calculated dynamically from weighted subcategories.
- Overall wellness is dynamically calculated from weighted pillar values.
- Historical direction, velocity, acceleration, volatility, plateau length, inflection points, consistency, and confidence are computed automatically.
- Forecasts use linear regression and confidence intervals.
- Visual state, color band, emphasis, arrow state, and status label are derived from rules.
- Correlation discovery scans cross-metric relationships and supports lagged relationships.
- Adaptive Intelligence Insights are generated from computed evidence, not hard-coded user text.
- The React dashboard is parameterized from the generated `DashboardModel`.

## Run

```bash
npm install
npm run dev
```

## Production build

```bash
npm run build
```

## Primary architecture

1. `src/domain/types.ts` — canonical data contracts.
2. `src/config/wellnessConfig.ts` — pillar definitions, subcategories, weights, bands, and engine thresholds.
3. `src/engine/trendEngine.ts` — trend, plateau, inflection, confidence, consistency, and forecast logic.
4. `src/engine/modelBuilder.ts` — builds the personalized dashboard model.
5. `src/engine/relationshipEngine.ts` — cross-variable relationship discovery.
6. `src/engine/insightEngine.ts` — rule-driven individualized narrative generation.
7. `src/engine/visualizationEngine.ts` — converts computational state into visual state.
8. `src/ui` — rendering only. It does not contain business rules.

## Replace the demo data

Replace `src/data/sampleUser.ts` with API data matching `UserWellnessHistory`.

The engine expects a list of `MetricSeries`, one for each subcategory. Pillar and overall series are optional because they can be calculated dynamically.

## Recommended next production steps

- Add authenticated API endpoints and persistent storage.
- Add validated assessment-version metadata to prevent incomparable instruments from being mixed.
- Add clinical and product governance rules around narrative wording.
- Add missing-data imputation policy and data-quality flags.
- Add rule versioning so every insight can be reproduced later.
- Add explainability payloads that expose the exact evidence and rule IDs behind every generated insight.
- Add a formal causal-inference layer before presenting relationships as causal.
