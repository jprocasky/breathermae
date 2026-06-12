<?php
if (!defined('ABSPATH')) exit;

class BMF_Interpreter {

    public static function interpret($response_id) {


        // ✅ Detect if this is a pillars-style form
        $form_type = self::detect_form_type($response_id);

        if ($form_type === 'pillars') {
            return self::interpret_pillars($response_id);
        }    
        // --------------------------------------------------
        // 1. Extract signals
        // --------------------------------------------------
        $signals = self::extract_signals($response_id);

        if (empty($signals)) {
            return [];
        }

        // --------------------------------------------------
        // 2. Aggregate signals
        // --------------------------------------------------
        $aggregated = self::aggregate_signals($signals);

        // --------------------------------------------------
        // 3. Rank signals (highest first)
        // --------------------------------------------------
        uasort($aggregated, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // ✅ Reindex to ensure consistent array structure
        $aggregated = array_values($aggregated);

        // ✅ Take top 3 signals
        $top_signals = array_slice($aggregated, 0, 3);

        // --------------------------------------------------
        // 4. Determine path (reuse resolver)
        // --------------------------------------------------
        $result = $GLOBALS['bmf_last_path_result']
            ?? BMF_Path_Resolver::determine_path($response_id);

        $path    = $result['path'] ?? null;
        $weights = $result['weights'] ?? [];

        // --------------------------------------------------
        // 5. Determine tier
        // --------------------------------------------------
        $tier = self::calculate_tier($weights);

        // --------------------------------------------------
        // 6. Build human-readable summary
        // --------------------------------------------------
        $summary = self::build_summary($top_signals);

        // --------------------------------------------------
        // 7. Build human-readable actions
        // --------------------------------------------------
        $actions = self::collect_actions($top_signals);

        // --------------------------------------------------
        // 8. Return final interpretation
        // --------------------------------------------------
        return [
            'path'         => $path,
            'tier'         => $tier,
            'top_signals'  => $top_signals,
            'summary'      => $summary,
            'actions'      => $actions
        ];
    }

    // --------------------------------------------------
    // SIGNAL EXTRACTION
    // --------------------------------------------------
    private static function extract_signals($response_id) {

        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT choice_value 
             FROM {$wpdb->prefix}bm_response_items 
             WHERE response_id = %d",
            $response_id
        ));

        if (!$rows) return [];

        $signals = [];

        foreach ($rows as $row) {

            $raw = trim($row->choice_value);

            if (strpos($raw, '|') === false) continue;

            list($value, $json) = explode('|', $raw, 2);

            $meta = json_decode($json, true);
            if (!$meta) continue;

            $signals[] = [
                'tag'     => $meta['tags'][0] ?? $value,
                'weights' => $meta['weights'] ?? [],
                'actions' => $meta['actions'] ?? []
            ];
        }

        return $signals;
    }

    // --------------------------------------------------
    // AGGREGATION
    // --------------------------------------------------
    private static function aggregate_signals($signals) {

        $agg = [];

        foreach ($signals as $s) {

            $tag = $s['tag'];

            $weight_total = array_sum($s['weights']);

            if (!isset($agg[$tag])) {
                $agg[$tag] = [
                    'tag'     => $tag,
                    'score'   => 0,
                    'actions' => []
                ];
            }

            $agg[$tag]['score'] += $weight_total;

            if (!empty($s['actions'])) {
                $agg[$tag]['actions'] = array_merge(
                    $agg[$tag]['actions'],
                    $s['actions']
                );
            }
        }

        return $agg;
    }

    // --------------------------------------------------
    // TIER LOGIC (dynamic)
    // --------------------------------------------------
    private static function calculate_tier($weights) {

        if (empty($weights)) return 'low';

        $max = max($weights);
        $sum = array_sum($weights);

        if ($sum == 0) return 'low';

        $intensity = $max / $sum;

        if ($intensity > 0.7) return 'high';
        if ($intensity > 0.4) return 'medium';

        return 'low';
    }

    // --------------------------------------------------
    // SUMMARY BUILDER
    // --------------------------------------------------
    private static function build_summary($signals) {

        $labels = self::bmf_signal_labels();

        $phrases = [];

        foreach ($signals as $s) {
            $tag = $s['tag'];
            $phrases[] = $labels[$tag] ?? $tag;
        }

        if (count($phrases) === 1) {
            return "Your responses suggest that {$phrases[0]} is your primary area of focus right now.";
        }

        if (count($phrases) === 2) {
            return "Your responses indicate a focus on {$phrases[0]} and {$phrases[1]}.";
        }

        $last = array_pop($phrases);

        return "Your responses show a mix of " . implode(', ', $phrases) . ", and {$last}.";
    }

    // --------------------------------------------------
    // ACTION COLLECTION
    // --------------------------------------------------
    private static function collect_actions($signals) {

        $action_map = self::bmf_action_labels();

        $actions = [];

        foreach ($signals as $s) {

            $tag = $s['tag'];

            if (isset($action_map[$tag])) {
                $actions[] = $action_map[$tag];
            } elseif (!empty($s['actions'])) {
                // fallback to CSV action if no mapping exists
                $actions = array_merge($actions, $s['actions']);
            }
        }

        return array_values(array_unique($actions));
    }


    private static function bmf_signal_labels() {
        return [
            'mid'     => 'daily consistency',
            'health'  => 'overall health balance',
            'stress'  => 'stress management',
            'clarity' => 'clarity and direction',
            'energy'  => 'energy levels',
            'focus'   => 'mental focus',
        ];
    }    

    private static function bmf_action_labels() {
        return [
            'mid'     => 'Build more consistency in your daily habits and routines.',
            'health'  => 'Focus on strengthening your overall health through balanced lifestyle choices.',
            'stress'  => 'Introduce strategies to better manage and reduce stress levels.',
            'clarity' => 'Work toward gaining clearer direction and focus in your priorities.',
            'energy'  => 'Find ways to improve and sustain your energy throughout the day.',
        ];
    }    


    private static function interpret_pillars($response_id) {

        global $wpdb;
        $p = $wpdb->prefix;

        $t_sections = $p . 'bm_form_sections';
        $t_scores   = $p . 'bm_section_scores';
        $t_items    = $p . 'bm_response_items';
        $t_qs       = $p . 'bm_questions';

        // --------------------------------------------------
        // 1. Load section metadata (dynamic from CSV)
        // --------------------------------------------------
        $sections = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, order_index 
            FROM {$t_sections}
            WHERE form_id = (
                SELECT form_id FROM {$p}bm_responses WHERE id = %d LIMIT 1
            )
            ORDER BY order_index ASC",
            $response_id
        ), ARRAY_A);

        if (!$sections) return [];

        // Map: section_id → title
        $section_map = [];
        foreach ($sections as $s) {
            $section_map[$s['id']] = [
                'title' => $s['title'],
                'order' => (int)$s['order_index']
            ];
        }

        // --------------------------------------------------
        // 2. Get section scores
        // --------------------------------------------------
        $scores = $wpdb->get_results($wpdb->prepare(
            "SELECT section_id, score
            FROM {$t_scores}
            WHERE response_id = %d",
            $response_id
        ), ARRAY_A);

        if (!$scores) return [];

        // Build structured score list
        $pillar_data = [];

        foreach ($scores as $row) {

            $sid = (int)$row['section_id'];

            // Skip section 1 (ranking) if desired
            if (!isset($section_map[$sid])) continue;

            $label = $section_map[$sid]['title'];
            if (strtolower($label) === 'wellness priorities') continue;

            $score = (float)$row['score']; // 0–1

            $percent = round($score * 100, 2);

            // Awareness classification
            if ($percent < 40) {
                $level = 'low';
            } elseif ($percent < 70) {
                $level = 'moderate';
            } else {
                $level = 'high';
            }

            $pillar_data[] = [
                'section_id' => $sid,
                'label'      => $section_map[$sid]['title'],
                'score'      => $score,
                'percent'    => $percent,
                'level'      => $level
            ];
        }

        // --------------------------------------------------
        // 3. Build ACTUAL ranking (by score)
        // --------------------------------------------------
        usort($pillar_data, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $actual_ranking = array_column($pillar_data, 'label');

        // --------------------------------------------------
        // 4. Get PERCEIVED ranking (Section 1)
        // --------------------------------------------------
        $ranking_raw = $wpdb->get_var($wpdb->prepare(
            "SELECT ri.choice_value
            FROM {$t_items} ri
            JOIN {$t_qs} q ON q.id = ri.question_id
            WHERE ri.response_id = %d
            AND q.type = 'rank'
            LIMIT 1",
            $response_id
        ));

        $perceived_ranking = [];

        if ($ranking_raw) {
            $decoded = urldecode($ranking_raw);
            $perceived_ranking = array_map('trim', explode(',', $decoded));
        }

        // --------------------------------------------------
        // 5. Compare rankings (alignment engine)
        // --------------------------------------------------

        $within_range_count = 0;
        $far_shift_count    = 0;
        $comparison         = [];

        // Normalize perceived labels to match actual titles
        $perceived = array_map(function($v) {
            return ucfirst(trim($v));
        }, $perceived_ranking);

        // Actual labels (already sorted by score)
        $actual = array_column($pillar_data, 'label');

        $total = min(count($perceived), count($actual));

        for ($i = 0; $i < $total; $i++) {

            $label = $perceived[$i];

            // Find position of this label in actual ranking
            $actual_index = array_search($label, $actual);

            if ($actual_index === false) {
                continue;
            }

            $diff = $actual_index - $i;
            $abs  = abs($diff);

            // ✅ Count alignment proximity
            if ($abs <= 1) {
                $within_range_count++;
            }

            if ($abs >= 2) {
                $far_shift_count++;
            }

            $comparison[] = [
                'label'      => $label,
                'perceived'  => $i + 1,
                'actual'     => $actual_index + 1,
                'difference' => $diff
            ];
        }

        // --------------------------------------------------
        // 6. Generate pillar-specific insights
        // --------------------------------------------------

        $insights = [];

        foreach ($comparison as $c) {

            $label = $c['label'];
            $diff  = $c['difference'];

            if ($diff <= -2) {
                $insights[] = "Your responses suggest that {$label} may be playing a more influential role in your overall well-being than initially perceived.";
            } elseif ($diff >= 2) {
                $insights[] = "{$label} may currently be receiving less attention or awareness than originally expected, which could be influencing your overall balance in subtle ways.";
            }
        }

        // ✅ limit AFTER building list
        $insights = array_slice($insights, 0, 3);



        // --------------------------------------------------
        // 6. Determine alignment scenario
        // --------------------------------------------------
        $total = count($comparison);

        if ($within_range_count >= ($total - 2)) {
            $alignment = 'strong';
        } elseif ($within_range_count >= 3) {
            $alignment = 'moderate';
        } else {
            $alignment = 'low';
        }

        // --------------------------------------------------
        // 7. Narrative templates (minimal hardcoding)
        // --------------------------------------------------
        $templates = [

            'strong' =>
            "Your assessment responses appear to closely align with the order in which you initially prioritized these areas of wellness. This may suggest a strong level of awareness regarding where your energy and attention are currently focused.  At the same time, alignment can sometimes make it easier to overlook how less-prioritized areas may still influence your overall balance over time. Wellness is dynamic, and every area contributes in interconnected ways.",

            'moderate' =>
            "Your assessment responses revealed both alignment and subtle shifts between the areas you initially prioritized and those reflected in your responses.  This may indicate that some areas are more integrated into your day-to-day awareness than expected, while others may benefit from additional reflection or support. Small shifts like these often highlight meaningful patterns in balance, consistency, or overall well-being.",

            'low' =>
            "Your assessment responses revealed noticeable differences between the areas you initially prioritized and the patterns reflected in your responses.  This does not mean your initial perceptions were incorrect. Rather, it may suggest that certain aspects of your experience are influencing your daily life in ways that may not always be immediately recognized. Awareness often develops in layers over time."
        ];

        $summary = $templates[$alignment] ?? '';

        // --------------------------------------------------
        // 8. Awareness distribution (optional but powerful)
        // --------------------------------------------------
        $levels = array_column($pillar_data, 'level');

        $low_count  = count(array_filter($levels, fn($l) => $l === 'low'));
        $high_count = count(array_filter($levels, fn($l) => $l === 'high'));

        if ($high_count >= 5) {
            $distribution = 'Predominantly High Awareness';
        } elseif ($low_count >= 5) {
            $distribution = 'Emerging Awareness Profile';
        } else {
            $distribution = 'Balanced or Mixed Awareness';
        }

        // --------------------------------------------------
        // 9. RETURN STRUCTURE
        // --------------------------------------------------
        return [
            'type' => 'pillars',

            'summary'        => $summary,
            'alignment'      => $alignment,
            'within_range'   => $within_range_count,
            'far_shifts'     => $far_shift_count,

            'distribution'   => $distribution,

            'perceived_ranking' => $perceived_ranking,
            'actual_ranking'    => $actual_ranking,

            'comparison' => $comparison,

            'pillars'   => $pillar_data,

            'insights'  => $insights
        ];

    }

    public static function detect_form_type($response_id) {

        global $wpdb;
        $p = $wpdb->prefix;

        // Get form_id for this response
        $form_id = $wpdb->get_var($wpdb->prepare(
            "SELECT form_id FROM {$p}bm_responses WHERE id = %d LIMIT 1",
            $response_id
        ));

        if (!$form_id) return 'unknown';

        // Look for ranking question (your defining signal)
        $has_rank = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
            FROM {$p}bm_questions q
            JOIN {$p}bm_form_sections s ON s.id = q.section_id
            WHERE s.form_id = %d
            AND q.type = 'rank'",
            $form_id
        ));

        if ($has_rank) {
            return 'pillars';
        }

        return 'signals'; // default (your current system)
    }
    

}