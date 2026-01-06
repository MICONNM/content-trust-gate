<?php
/**
 * Plugin Name: Content Trust Gate
 * Description: Mandatory Gate for Content Risk Control. Pre-blocks execution in automation pipelines. System judgment with logs. Essential control layer preventing bypass.
 * Version: 1.0
 * Author: Content Trust
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

// --- Mandatory Builtin Checks (Pre-Block Execution) -----------------
function ctg_builtin_check($title, $text, $source_url) {
    $lower = mb_strtolower($title . ' ' . $text);

    // Absolute words
    $absolute = array('무조건','절대','100%','반드시');
    $abs_count = 0;
    foreach ($absolute as $w) { $abs_count += mb_substr_count($lower, $w); }

    // Numeric presence
    preg_match_all('/\d+/', $text, $nums);
    $num_count = count($nums[0]);

    // Duplication heuristic
    $sents = preg_split('/(?<=[\.\?\!])\s+/', wp_strip_all_tags($text));
    $sents = array_filter(array_map('trim', $sents));
    $uniq = array_unique($sents);
    $dup_rate = count($sents) > 0 ? (count($sents) - count($uniq)) / count($sents) : 0;

    // Reason code based judgment (5-stage)
    $reason_code = 'PASS';
    $status = 'PASS';
    $risk_level = 'LOW';
    $potential_outcome = '';
    $responsibility_shift = 'NONE';

    if ($abs_count >= 2) {
        $reason_code = 'ABSOLUTE_OVERUSE';
        $status = 'BLOCK';
        $risk_level = 'HIGH';
        $potential_outcome = 'Misinformation liability';
        $responsibility_shift = 'SYSTEM_BLOCKED';
    } elseif ($num_count == 0) {
        $reason_code = 'EVIDENCE_MISSING';
        $status = 'BLOCK';
        $risk_level = 'HIGH';
        $potential_outcome = 'Misinformation liability';
        $responsibility_shift = 'SYSTEM_BLOCKED';
