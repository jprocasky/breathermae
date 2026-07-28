# Breathermae Adaptive Visualization Framework
## Module 2 — Eight Pillars Data Foundation and Working Dashboard

This package contains the complete Module 1 foundation plus Module 2. Install this ZIP as one WordPress plugin.

Module 2 is intentionally limited to the Breathermae **Eight Pillars of Wellness Dashboard** so the integration can be verified before expanding the framework to other Breathermae systems.

## Included capabilities

- Native WordPress plugin
- Elementor Pro Shortcode widget compatibility
- Authenticated WordPress REST API
- Canonical registry for all eight pillars
- Registry for the 38 current subcategories
- Quarterly historical data contract
- Input validation and normalization
- Weighted pillar score calculation
- Weighted overall wellness score calculation
- Baseline-to-current change
- Most-improved pillar
- Current priority pillar
- Historical overall line chart
- Eight expandable pillar cards
- Pillar sparklines
- Complete subcategory drill-down
- Demonstration data for immediate integration testing
- Production data-provider hook

No React, TypeScript, Node, npm, or external chart library is required.

---

## Important upgrade instruction

If Module 1 is already installed:

1. Deactivate Module 1.
2. Delete the old plugin from WordPress. This does not delete member wellness data because Module 1 created no custom data tables.
3. Upload the Module 2 ZIP.
4. Activate it.
5. Keep the existing Elementor shortcode in place.

Do not activate Module 1 and Module 2 simultaneously because they use the same plugin bootstrap and shortcode.

---

## Installation

1. Open **WordPress Admin → Plugins → Add New → Upload Plugin**.
2. Upload:
   `breathermae-adaptive-visualization-framework-module-2.zip`
3. Activate the plugin.
4. Open the target page in Elementor Pro.
5. Add an Elementor **Shortcode** widget.
6. Enter:

```text
[breathermae_eight_pillars]
```

7. Update and view the page while signed in.

The dashboard will initially display deterministic demonstration history so the WordPress, Elementor, REST, JavaScript, CSS, and visualization integration can be tested immediately.

---

## Shortcode examples

Default current member:

```text
[breathermae_eight_pillars]
```

Custom title:

```text
[breathermae_eight_pillars title="My 8-Pillars Wellness Evolution"]
```

Administrator viewing an authorized member:

```text
[breathermae_eight_pillars user_id="123"]
```

Optional CSS class:

```text
[breathermae_eight_pillars class="breathermae-member-dashboard"]
```

---

## Production data integration

The plugin reads production history through:

```php
add_filter(
    'bmae_avf_eight_pillars_history',
    function (array $history, int $user_id): array {
        // Query the existing Breathermae assessment records here.
        return $history;
    },
    10,
    2
);
```

Your adapter must return an array of assessments:

```php
[
    [
        'assessment_id' => 'assessment-1001',
        'assessment_date' => '2026-01-15',
        'label' => 'Q1 2026',
        'pillars' => [
            'physical' => [
                'subcategories' => [
                    'overall_physical_health' => 63,
                    'physical_activity_daily_movement' => 71,
                    'daily_habits_health_behaviors' => 68,
                    'health_maintenance_medical_beliefs' => 59,
                ],
            ],
            // Continue with the remaining seven pillars.
        ],
    ],
]
```

The full schema is included in:

```text
schema/eight-pillars-history-contract.json
```

When production history is returned, the dashboard automatically stops using demonstration data.

---

## Score bands

The current Eight Pillars result bands are preserved:

- High: 80–100
- Moderate: 40–79.99
- Low: 0–39.99

Scores are normalized to a 0–100 scale.

---

## Current score calculation

Each pillar score is the weighted average of its available subcategory scores.

The overall wellness score is the weighted average of the eight available pillar scores.

All current weights are `1.0`, which produces an equal-weight average. The canonical registry allows weights to be changed later without rewriting the display layer.

---

## Module 2 file structure

```text
breathermae-adaptive-visualization-framework-module-2/
├── breathermae-adaptive-visualization-framework.php
├── README.md
├── CHANGELOG.md
├── uninstall.php
├── includes/
│   ├── class-bmae-avf-activator.php
│   ├── class-bmae-avf-config.php
│   ├── class-bmae-avf-data-validator.php
│   ├── class-bmae-avf-eight-pillars-provider.php
│   ├── class-bmae-avf-eight-pillars-registry.php
│   ├── class-bmae-avf-plugin.php
│   ├── class-bmae-avf-rest-controller.php
│   ├── class-bmae-avf-security.php
│   └── class-bmae-avf-shortcodes.php
├── templates/
│   └── dashboard-shell.php
├── assets/
│   ├── css/
│   │   └── framework.css
│   └── js/
│       └── framework.js
└── schema/
    ├── eight-pillars-history-contract.json
    └── module-2-contract.json
```

---

## What to verify

After activation, verify:

1. The shortcode loads inside Elementor.
2. The dashboard appears only for signed-in users.
3. The summary cards display.
4. The historical chart displays six quarters.
5. All eight pillar cards display.
6. Each pillar expands when selected.
7. All subcategories and scores display.
8. The layout responds on desktop, tablet, and mobile.
9. Browser console shows no JavaScript error.
10. `/wp-json/breathermae/v1/framework/status` returns a ready response for an authenticated user.

---

## Scope deliberately deferred

Module 2 does not yet include:

- forecasts
- velocity or acceleration
- plateau detection
- correlations
- AI-generated interpretation
- recommendations
- production database queries
- WordPress administration settings
- other Breathermae dashboards

Those should only be added after this Eight Pillars integration is verified.
