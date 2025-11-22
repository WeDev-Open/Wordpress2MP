<?php
if (!defined('ABSPATH')) { exit; }

class Wechat_Sync_Logger {
    private static function option_key() { return 'wechat_sync_error_logs'; }
    private static function max_logs() { return max(50, intval(get_option('wechat_sync_log_limit', 500))); }
    private static function make_id() {
        if (function_exists('wp_generate_uuid4')) return wp_generate_uuid4();
        if (function_exists('random_bytes')) { $b = random_bytes(16); $h = bin2hex($b); return substr($h,0,8) . '-' . substr($h,8,4) . '-' . substr($h,12,4) . '-' . substr($h,16,4) . '-' . substr($h,20,12); }
        $r = function($n){ $s=''; for($i=0;$i<$n;$i++){ $s .= dechex(mt_rand(0,15)); } return $s; };
        return $r(8) . '-' . $r(4) . '-' . $r(4) . '-' . $r(4) . '-' . $r(12);
    }

    public static function log_error($post_id, $message, $stage, $attempt, $status) {
        $logs = get_option(self::option_key(), []);
        if (!is_array($logs)) $logs = [];
        $entry = [
            'id' => self::make_id(),
            'post_id' => intval($post_id),
            'title' => get_the_title($post_id),
            'status' => (string)$status,
            'error' => (string)$message,
            'stage' => (string)$stage,
            'attempt' => intval($attempt),
            'created_at' => time(),
        ];
        array_unshift($logs, $entry);
        $limit = self::max_logs();
        if (count($logs) > $limit) $logs = array_slice($logs, 0, $limit);
        update_option(self::option_key(), $logs, false);
    }

    public static function log_info($post_id, $message, $stage) {
        self::log_error($post_id, $message, $stage, 0, '信息');
    }

    public static function get_logs($args = []) {
        $logs = get_option(self::option_key(), []);
        if (!is_array($logs)) $logs = [];
        $search = isset($args['search']) ? sanitize_text_field($args['search']) : '';
        $status = isset($args['status']) ? sanitize_text_field($args['status']) : '';
        $stage = isset($args['stage']) ? sanitize_text_field($args['stage']) : '';
        $filtered = array_filter($logs, function($e) use ($search, $status, $stage) {
            if ($status && (!isset($e['status']) || $e['status'] !== $status)) return false;
            if ($stage && (!isset($e['stage']) || $e['stage'] !== $stage)) return false;
            if ($search) {
                $hay = ($e['title'] ?? '') . ' ' . ($e['error'] ?? '');
                if (stripos($hay, $search) === false) return false;
            }
            return true;
        });
        return array_values($filtered);
    }

    public static function delete_log($id) {
        $logs = get_option(self::option_key(), []);
        if (!is_array($logs)) return;
        $logs = array_values(array_filter($logs, function($e) use ($id) { return ($e['id'] ?? '') !== $id; }));
        update_option(self::option_key(), $logs, false);
    }

    public static function clear_logs() {
        delete_option(self::option_key());
    }

    public static function export_csv($logs) {
        $out = fopen('php://output', 'w');
        fputcsv($out, ['时间', '文章ID', '标题', '状态', '阶段', '错误信息', '重试次数']);
        foreach ($logs as $e) {
            fputcsv($out, [date('Y-m-d H:i:s', $e['created_at']), $e['post_id'], $e['title'], $e['status'], $e['stage'], $e['error'], $e['attempt']]);
        }
        fclose($out);
    }
}