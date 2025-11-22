<?php
if (!defined('ABSPATH')) { exit; }
if (!class_exists('WP_List_Table')) require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

class Wechat_Sync_Error_List_Table extends WP_List_Table {
    public function get_columns() {
        return [
            'created_at' => __('时间', WECHAT_SYNC_TEXTDOMAIN),
            'title' => __('标题', WECHAT_SYNC_TEXTDOMAIN),
            'status' => __('状态', WECHAT_SYNC_TEXTDOMAIN),
            'stage' => __('阶段', WECHAT_SYNC_TEXTDOMAIN),
            'error' => __('错误信息', WECHAT_SYNC_TEXTDOMAIN),
            'attempt' => __('重试次数', WECHAT_SYNC_TEXTDOMAIN),
            'actions' => __('操作', WECHAT_SYNC_TEXTDOMAIN),
        ];
    }

    public function get_sortable_columns() {
        return [ 'created_at' => ['created_at', true], 'attempt' => ['attempt', false] ];
    }

    public function no_items() {
        echo esc_html(__('暂无异常日志', WECHAT_SYNC_TEXTDOMAIN));
    }

    public function prepare_items() {
        $per_page = 20;
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $stage = isset($_GET['stage']) ? sanitize_text_field($_GET['stage']) : '';
        $logs = Wechat_Sync_Logger::get_logs(['search' => $search, 'status' => $status, 'stage' => $stage]);
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'created_at';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'desc';
        usort($logs, function($a, $b) use ($orderby, $order) {
            $va = $a[$orderby] ?? '';
            $vb = $b[$orderby] ?? '';
            if ($va == $vb) return 0;
            $cmp = ($va < $vb) ? -1 : 1;
            return $order === 'asc' ? $cmp : -$cmp;
        });
        $total = count($logs);
        $offset = ($paged - 1) * $per_page;
        $this->items = array_slice($logs, $offset, $per_page);
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];
        $this->set_pagination_args(['total_items' => $total, 'per_page' => $per_page]);
    }

    public function column_default($item, $column_name) {
        if ($column_name === 'created_at') return esc_html(date('Y-m-d H:i:s', $item['created_at']));
        if ($column_name === 'title') return '<a href="' . esc_url(get_edit_post_link($item['post_id'])) . '">' . esc_html($item['title']) . '</a>';
        if ($column_name === 'status') return esc_html($item['status']);
        if ($column_name === 'stage') return esc_html($item['stage']);
        if ($column_name === 'error') return esc_html($item['error']);
        if ($column_name === 'attempt') return esc_html(intval($item['attempt']));
        if ($column_name === 'actions') {
            $retry = wp_nonce_url(admin_url('admin-post.php?action=wechat_sync_retry&post_id=' . intval($item['post_id'])), 'wechat_sync_retry_' . intval($item['post_id']));
            $del = wp_nonce_url(admin_url('admin-post.php?action=wechat_sync_delete_log&id=' . urlencode($item['id'])), 'wechat_sync_delete_log_' . $item['id']);
            return '<a class="button" href="' . esc_url($retry) . '">' . esc_html(__('重试', WECHAT_SYNC_TEXTDOMAIN)) . '</a> ' . '<a class="button" href="' . esc_url($del) . '">' . esc_html(__('删除', WECHAT_SYNC_TEXTDOMAIN)) . '</a>';
        }
        return '';
    }
}