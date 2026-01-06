<?php
/**
 * Plugin Name: Content Trust Gate
 * Description: Mandatory Gate for Content Risk Control. Pre-blocks execution in automation pipelines. System judgment with logs. Essential control layer preventing bypass.
 * Version: 0.8
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
    } elseif ($dup_rate > 0.25) {
        $reason_code = 'DUPLICATION_HIGH';
        $status = 'HOLD';
        $risk_level = 'MEDIUM';
        $potential_outcome = 'Content quality issue';
        $responsibility_shift = 'SYSTEM_HOLD';
    }

    // Score for reference only
    $score = 100;
    if ($abs_count >= 2) $score -= 40;
    if ($num_count == 0) $score -= 10;
    if ($dup_rate > 0.25) $score -= 30;

    return array('status' => $status, 'score' => $score, 'decision' => $status, 'reason_code' => $reason_code, 'risk_level' => $risk_level, 'responsibility_shift' => $responsibility_shift, 'potential_outcome' => $potential_outcome, 'details' => array('abs_count' => $abs_count, 'num_count' => $num_count, 'dup_rate' => $dup_rate));
}

// --- System Judgment Logging (No User Override) -----------------
function ctg_log_result($type, $id, $decision_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'ctg_logs';

    // Normalize decision_id: accept string or array/result
    if (is_array($decision_id)) {
        if (!empty($decision_id['decision_id'])) {
            $decision_id_str = $decision_id['decision_id'];
        } else {
            // Fallback to JSON encode of result array
            $decision_id_str = wp_json_encode($decision_id);
        }
    } else {
        $decision_id_str = (string)$decision_id;
    }

    $inserted = $wpdb->insert($table, array(
        'type' => $type,
        'item_id' => $id,
        'decision_id' => $decision_id_str,
        'timestamp' => current_time('mysql')
    ));

    if ($inserted === false) {
        error_log('[ctg] Failed to insert log: ' . $wpdb->last_error);
    }
}

// --- Mandatory Pre-Publish Gate (Execution Blocker) ---------------------------
function ctg_prepare_and_check($post_id, $post) {
    if (wp_is_post_revision($post_id) || $post->post_status === 'auto-draft') return;

    // Judgment moved to wp_insert_post_data for single point
    // Log only if needed, but since blocking prevents save, log in blocker
    // Removed duplicate judgment logic
}
add_action('save_post', 'ctg_prepare_and_check', 10, 2);

function ctg_pre_publish_check($post_id, $data) {
    if ($data['post_status'] === 'publish') {
        $decision_id = ctg_decide('post', $data['post_title'], $data['post_content'], get_permalink($post_id));
        $result = ctg_get_decision($decision_id);
        if ($result['decision'] === 'BLOCK') {
            wp_die(__('Publishing blocked due to HIGH RISK. System intervention recorded. Responsibility shifted from publisher.', 'content-trust-gate'));
        }
    }
}

function ctg_force_pending($data, $postarr) {
    if ($data['post_status'] === 'publish') {
        $res = get_transient('ctg_last_result_' . $postarr['ID']);
        if ($res && ($res['status'] === 'BLOCK' || $res['status'] === 'HOLD')) {
            $data['post_status'] = 'pending';
        }
    }
    return $data;
}

// --- Mandatory Comment Gate ---------------------------
function ctg_check_comment($comment_id, $comment_object) {
    $decision_id = ctg_decide('comment', 'Comment by ' . $comment_object->comment_author, $comment_object->comment_content, get_comment_link($comment_id));
    $result = ctg_get_decision($decision_id);

    if ($result['decision'] === 'BLOCK') {
        wp_set_comment_status($comment_id, 'hold');
        // Removed global 'pre_comment_approved' filter to avoid side-effects
    }

    // Log the decision_id (not the whole array) for consistency
    ctg_log_result('comment', $comment_id, $decision_id);
}
add_action('wp_insert_comment', 'ctg_check_comment', 10, 2);

// --- Mandatory Feed Gate ---------------------------
function ctg_filter_feed_content($content) {
    global $post;
    if (!$post) return $content;

    $decision_id = ctg_decide('feed', $post->post_title, $content, get_permalink($post->ID));
    $result = ctg_get_decision($decision_id);

    if ($result['decision'] === 'BLOCK') {
        return __('Content blocked for distribution.', 'content-trust-gate');
    }

    return $content;
}
add_filter('the_content_feed', 'ctg_filter_feed_content');
add_filter('the_excerpt_rss', 'ctg_filter_feed_content');

// --- Mandatory API Gate ---------------------------
function ctg_filter_rest_content($response, $post, $request) {
    // Do not interfere with read-only GET requests (editor preload, front-end read). Only enforce on modifying requests.
    if ($request && method_exists($request, 'get_method')) {
        $method = strtoupper($request->get_method());
        if ($method === 'GET') {
            return $response;
        }
    }

    if ($post instanceof WP_Post) {
        $decision_id = ctg_decide('rest', $post->post_title, $post->post_content, get_permalink($post->ID));
        $result = ctg_get_decision($decision_id);
        if ($result['decision'] === 'BLOCK') {
            $payload = array('message' => __('Publishing blocked due to HIGH RISK. System intervention recorded. Responsibility shifted from publisher.', 'content-trust-gate'), 'ctg_status' => 'BLOCKED');
            $resp = rest_ensure_response($payload);
            $resp->set_status(403);
            return $resp;
        } elseif ($result['decision'] === 'HOLD') {
            $payload = array('message' => __('Content held for API distribution.', 'content-trust-gate'), 'ctg_status' => 'HOLD');
            $resp = rest_ensure_response($payload);
            $resp->set_status(200);
            return $resp;
        }
    }
    return $response;
}
add_filter('rest_prepare_post', 'ctg_filter_rest_content', 10, 3);

// --- Mandatory Automation Gate ---------------------------
function ctg_check_automation($title, $text, $url) {
    $decision_id = ctg_decide('automation', $title, $text, $url);
    $result = ctg_get_decision($decision_id);
    if ($result['decision'] === 'BLOCK') {
        // Fail automation step, prevent next execution
        ctg_log_result('automation', 0, $decision_id);
        return false;
    }
    // Log automation decision
    ctg_log_result('automation', 0, $decision_id);
    return $result;
}
add_filter('ctg_automation_gate', 'ctg_check_automation', 10, 3);

// --- Mandatory Ad Gate ---------------------------
function ctg_check_ad_content($ad_title, $ad_content, $ad_url) {
    $decision_id = ctg_decide('ad', $ad_title, $ad_content, $ad_url);
    $result = ctg_get_decision($decision_id);
    if ($result['decision'] === 'BLOCK') return false;
    return $decision_id; // Return decision_id for external use
}
add_filter('ctg_ad_gate', 'ctg_check_ad_content', 10, 3);

// --- Single Judgment Engine (6-stage) -----------------
function ctg_decide($type, $title, $content, $url) {
    $content_hash = wp_hash($title . $content . $url);
    $policy_version = '2026.01';
    global $wpdb;
    $table = $wpdb->prefix . 'ctg_decisions';

    // Check for existing decision
    $existing = $wpdb->get_row($wpdb->prepare("SELECT decision_id FROM $table WHERE content_hash = %s AND policy_version = %s", $content_hash, $policy_version));
    if ($existing) {
        return $existing->decision_id;
    }

    // New judgment
    $result = ctg_builtin_check($title, $content, $url);
    $decision_id = hash('sha256', $content_hash . '|' . $policy_version);

    $inserted = $wpdb->insert($table, array(
        'decision_id' => $decision_id,
        'type' => $type,
        'content_hash' => $content_hash,
        'policy_version' => $policy_version,
        'status' => $result['status'],
        'score' => $result['score'],
        'reason_code' => $result['reason_code'],
        'risk_level' => $result['risk_level'],
        'responsibility_shift' => $result['responsibility_shift'],
        'potential_outcome' => $result['potential_outcome'],
        'issued_at' => current_time('mysql'),
        'immutable' => 1
    ));

    if ($inserted === false) {
        // If insert failed, log and return temporary decision id to avoid blocking flows unexpectedly
        error_log('[ctg] Failed to insert decision: ' . $wpdb->last_error);
    }

    return $decision_id;
}

function ctg_get_decision($decision_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'ctg_decisions';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE decision_id = %s", $decision_id));
    if (!$row) return false;
    return array(
        'decision' => $row->status,
        'reason_code' => $row->reason_code,
        'risk_level' => $row->risk_level,
        'responsibility_shift' => $row->responsibility_shift,
        'potential_outcome' => $row->potential_outcome,
        'policy_version' => $row->policy_version,
        'system' => 'Content Trust Gate',
        'immutable' => (bool)$row->immutable
    );
}

// --- Activation Hook: Create Log Table ---------------------------
function ctg_activate() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // Ensure dbDelta is available
    if (!function_exists('dbDelta')) {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    }

    // Logs table
    $table_logs = $wpdb->prefix . 'ctg_logs';
    $sql_logs = "CREATE TABLE $table_logs (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        type varchar(20) NOT NULL,
        item_id bigint(20) NOT NULL,
        decision_id varchar(255) NOT NULL,
        timestamp datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";
    dbDelta($sql_logs);

    // Decisions table
    $table_dec = $wpdb->prefix . 'ctg_decisions';
    $sql_dec = "CREATE TABLE $table_dec (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        decision_id varchar(64) NOT NULL,
        type varchar(20) NOT NULL,
        content_hash varchar(255) NOT NULL,
        policy_version varchar(32) NOT NULL,
        status varchar(20) NOT NULL,
        score int NOT NULL,
        reason_code varchar(64) DEFAULT '',
        risk_level varchar(32) DEFAULT '',
        responsibility_shift varchar(64) DEFAULT '',
        potential_outcome text,
        issued_at datetime DEFAULT CURRENT_TIMESTAMP,
        immutable tinyint(1) DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY decision_id (decision_id),
        KEY content_hash (content_hash(191))
    ) $charset;";
    dbDelta($sql_dec);
}

// Register activation hook so tables are created on plugin activation
if (defined('WP_PLUGIN_DIR')) {
    register_activation_hook(__FILE__, 'ctg_activate');
}

// --- Mandatory Pre-Publish Gate (Execution Blocker) ---------------------------
function tc_block_low_trust($data, $postarr) {
    // Skip if not publishing
    if ($data['post_status'] !== 'publish') return $data;

    $decision_id = ctg_decide('post', $data['post_title'], $data['post_content'], get_permalink($postarr['ID'] ?? 0));
    $result = ctg_get_decision($decision_id);

    // Good content passes silently
    if ($result && $result['decision'] === 'PASS') {
        return $data;
    }

    // Block or hold low trust content: set to pending so user can review
    if ($result && ($result['decision'] === 'BLOCK' || $result['decision'] === 'HOLD')) {
        // store both decision references for subsequent hooks
        if (!empty($postarr['ID'])) {
            set_transient('ctg_decision_' . $postarr['ID'], $decision_id, 3600);
            set_transient('ctg_last_result_' . $postarr['ID'], $result, 3600);
        }
        ctg_log_result('post_block', $postarr['ID'] ?? 0, $decision_id);
        // Set to pending to avoid unexpected WP_Error return types from filter
        $data['post_status'] = 'pending';
        return $data;
    }

    return $data;
}
add_filter('wp_insert_post_data', 'tc_block_low_trust', 10, 2);

