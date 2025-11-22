<?php
if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

class Wechat_Sync_Admin_List_Table extends WP_List_Table {
    public function get_columns() {
        return [
            'title' => __('标题', WECHAT_SYNC_TEXTDOMAIN),
            'status' => __('同步状态', WECHAT_SYNC_TEXTDOMAIN),
            'platform' => __('平台', WECHAT_SYNC_TEXTDOMAIN),
            'url' => __('发布链接', WECHAT_SYNC_TEXTDOMAIN),
            'error' => __('错误信息', WECHAT_SYNC_TEXTDOMAIN),
            'actions' => __('操作', WECHAT_SYNC_TEXTDOMAIN),
        ];
    }

    public function get_sortable_columns() {
        return [];
    }

    public function no_items() {
        echo esc_html(__('暂无数据', WECHAT_SYNC_TEXTDOMAIN));
    }

    public function prepare_items() {
        $per_page = 20;
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $args = [
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            's' => $search,
        ];
        $q = new WP_Query($args);
        $this->items = $q->posts;
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];
        $this->set_pagination_args(['total_items' => $q->found_posts, 'per_page' => $per_page]);
    }

    public function column_default($item, $column_name) {
        $post_id = $item->ID;
        if ($column_name === 'title') return '<a href="' . esc_url(get_edit_post_link($post_id)) . '">' . esc_html(get_the_title($post_id)) . '</a>';
        if ($column_name === 'status') return esc_html(get_post_meta($post_id, 'wechat_sync_status', true) ?: __('未同步', WECHAT_SYNC_TEXTDOMAIN));
        if ($column_name === 'platform') return esc_html(get_post_meta($post_id, 'wechat_sync_platform', true) ?: 'WeChatOA');
        if ($column_name === 'url') {
            $url = get_post_meta($post_id, 'wechat_sync_url', true);
            return $url ? '<a href="' . esc_url($url) . '" target="_blank">' . esc_html(__('打开', WECHAT_SYNC_TEXTDOMAIN)) . '</a>' : '';
        }
        if ($column_name === 'error') {
            $err = get_post_meta($post_id, 'wechat_sync_error', true);
            return $err ? esc_html($err) : '';
        }
        if ($column_name === 'actions') {
            $sync = wp_nonce_url(admin_url('admin-post.php?action=wechat_sync_manual&post_id=' . $post_id), 'wechat_sync_manual_' . $post_id);
            $retry = wp_nonce_url(admin_url('admin-post.php?action=wechat_sync_retry&post_id=' . $post_id), 'wechat_sync_retry_' . $post_id);
            return '<a class="button" href="' . esc_url($sync) . '">' . esc_html(__('同步', WECHAT_SYNC_TEXTDOMAIN)) . '</a> ' . '<a class="button" href="' . esc_url($retry) . '">' . esc_html(__('重试', WECHAT_SYNC_TEXTDOMAIN)) . '</a>';
        }
        return '';
    }
}