<?php
if (!defined('ABSPATH')) exit;

class BMF_Path_Resolver {

    public static function determine_path($response_id) {
        global $wpdb;

        $response_id = (int)$response_id;
        if ($response_id <= 0) return null;

        $form_type = BMF_Interpreter::detect_form_type($response_id);

        if ($form_type === 'pillars') {

            bm_log(__METHOD__ . ' SKIP | pillars form detected');

            // ✅ DO NOT return — just safely exit logic
            return [
                'path' => 'pillars',
                'weights' => []
            ];
        }

        bm_log(__METHOD__ . " ENTER | response_id={$response_id}");

        $t_items = $wpdb->prefix . 'bm_response_items';
        $t_qs    = $wpdb->prefix . 'bm_questions';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ri.choice_value, q.options_string, q.choices_json
             FROM {$t_items} ri
             JOIN {$t_qs} q ON q.id = ri.question_id
             WHERE ri.response_id = %d",
            $response_id
        ), ARRAY_A);

        if (!$rows) {
            bm_log(__METHOD__ . " NO ANSWERS FOUND");
            return null;
        }

        $weights = [
            'rsi'     => 0,
            'pillars' => 0
        ];

        foreach ($rows as $r) {

            $raw_value    = (string)$r['choice_value'];
            $choice_value = trim(stripslashes($raw_value)); // ✅ FIX

            bm_log(__METHOD__ . " PROCESS ANSWER | raw={$raw_value} | cleaned={$choice_value}");

            // =====================================================
            // ✅ CASE 1: value|JSON format
            // Example: performance|{"weights":{"rsi":2}}
            // =====================================================
            if (strpos($choice_value, '|') !== false) {

                $parts = explode('|', $choice_value, 2);

                $value_part = trim($parts[0]);
                $json_part  = trim($parts[1]);

                // If second part is JSON
                if (strpos($json_part, '{') === 0) {

                    $meta = json_decode($json_part, true);

                    if (!empty($meta['weights'])) {

                        bm_log(__METHOD__ . " MATCHED VALUE+JSON | value={$value_part}");

                        foreach ($meta['weights'] as $key => $w) {

                            $w = (float)$w;

                            if (!isset($weights[$key])) {
                                $weights[$key] = 0;
                            }

                            $weights[$key] += $w;

                            bm_log(__METHOD__ . " APPLY SPLIT WEIGHT | {$key} += {$w}");
                        }
                    }

                    continue;
                }
            }

            // =====================================================
            // ✅ CASE 2: options_string parsing (future-safe)
            // =====================================================
            $option_string = trim((string)$r['options_string']);

            if (!empty($option_string)) {

                $options = explode(',', $option_string);

                foreach ($options as $opt) {

                    $opt = trim($opt);
                    if ($opt === '') continue;

                    $parts = explode(',', $opt, 2);
                    if (count($parts) < 2) continue;

                    $value_meta = trim($parts[1]);

                    $vm_parts = explode('|', $value_meta, 2);

                    $value = trim($vm_parts[0]);

                    if ($value !== $choice_value) continue;

                    if (empty($vm_parts[1])) continue;

                    $meta = json_decode($vm_parts[1], true);

                    if (empty($meta['weights'])) continue;

                    bm_log(__METHOD__ . " MATCHED OPTION VALUE | {$value}");

                    foreach ($meta['weights'] as $key => $w) {

                        $w = (float)$w;

                        if (!isset($weights[$key])) {
                            $weights[$key] = 0;
                        }

                        $weights[$key] += $w;

                        bm_log(__METHOD__ . " APPLY OPTION WEIGHT | {$key} += {$w}");
                    }
                }
            }

            // =====================================================
            // ✅ CASE 3: choices_json fallback
            // =====================================================
            else {

                $choices_json = $r['choices_json'];

                if (empty($choices_json)) continue;

                $choices = json_decode($choices_json, true);
                if (!is_array($choices)) continue;

                foreach ($choices as $choice) {

                    if (!isset($choice['value'])) continue;
                    if ((string)$choice['value'] !== $choice_value) continue;
                    if (empty($choice['weights'])) continue;

                    bm_log(__METHOD__ . " MATCHED JSON VALUE | {$choice_value}");

                    foreach ($choice['weights'] as $key => $w) {

                        $w = (float)$w;

                        if (!isset($weights[$key])) {
                            $weights[$key] = 0;
                        }

                        $weights[$key] += $w;

                        bm_log(__METHOD__ . " APPLY JSON WEIGHT | {$key} += {$w}");
                    }
                }
            }
        }

        bm_log(__METHOD__ . ' WEIGHTS = ' . json_encode($weights));

        $path = ($weights['rsi'] >= $weights['pillars']) ? 'rsi' : 'pillars';

        bm_log(__METHOD__ . " RESULT | path={$path}");

        return [
            'path'    => $path,
            'weights' => [
                'rsi' => 0,
                'pillars' => 0
            ]
        ];
    }
}