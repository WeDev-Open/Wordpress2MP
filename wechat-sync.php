<?php
/*
Plugin Name: WordPress To MP
Description: Sync WordPress posts to WeChat Official Accounts.
Version: 1.0.10
Author: clrs
Text Domain: wordpress-to-mp
*/

if (!defined('ABSPATH')) { exit; }

define('WECHAT_SYNC_VERSION', '1.0.10');    
define('WECHAT_SYNC_PATH', plugin_dir_path(__FILE__));
define('WECHAT_SYNC_URL', plugin_dir_url(__FILE__));
define('WECHAT_SYNC_TEXTDOMAIN', 'wordpress-to-mp');

require_once WECHAT_SYNC_PATH . 'includes/class-admin-settings.php';
require_once WECHAT_SYNC_PATH . 'includes/class-token-service.php';
require_once WECHAT_SYNC_PATH . 'includes/class-wechat-provider.php';
require_once WECHAT_SYNC_PATH . 'includes/class-sync-queue.php';
require_once WECHAT_SYNC_PATH . 'includes/class-admin-list-table.php';
require_once WECHAT_SYNC_PATH . 'includes/class-logger.php';
require_once WECHAT_SYNC_PATH . 'includes/class-admin-error-list-table.php';

class Wechat_Sync_Plugin {
    public static function init() {
        add_action('plugins_loaded', [__CLASS__, 'load_textdomain']);
        add_action('admin_menu', [__CLASS__, 'register_admin_pages']);
        add_action('admin_init', ['Wechat_Sync_Admin_Settings', 'register']);
        add_action('transition_post_status', [__CLASS__, 'on_transition_post_status'], 10, 3);
        add_filter('manage_post_posts_columns', [__CLASS__, 'add_posts_columns']);
        add_action('manage_post_posts_custom_column', [__CLASS__, 'render_posts_column'], 10, 2);
        add_action('admin_post_wechat_sync_manual', [__CLASS__, 'handle_manual_sync']);
        add_action('admin_post_wechat_sync_retry', [__CLASS__, 'handle_retry_sync']);
        add_action('admin_post_wechat_sync_delete_log', [__CLASS__, 'handle_delete_log']);
        add_action('admin_post_wechat_sync_clear_logs', [__CLASS__, 'handle_clear_logs']);
        add_action('admin_post_wechat_sync_export_logs', [__CLASS__, 'handle_export_logs']);
        add_action('admin_notices', [__CLASS__, 'admin_notices']);
        add_action('admin_post_nopriv_wechat_sync_cron', [__CLASS__, 'handle_system_cron']);
        add_action('admin_post_wechat_sync_cron', [__CLASS__, 'handle_system_cron']);
        add_action('admin_post_nopriv_wechat_sync_worker_process', [__CLASS__, 'handle_worker_process']);
        add_action('admin_post_wechat_sync_worker_process', [__CLASS__, 'handle_worker_process']);
        add_action('admin_post_nopriv_wechat_sync_worker_poll', [__CLASS__, 'handle_worker_poll']);
        add_action('admin_post_wechat_sync_worker_poll', [__CLASS__, 'handle_worker_poll']);
    }

    public static function load_textdomain() {
        load_plugin_textdomain(WECHAT_SYNC_TEXTDOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public static function register_admin_pages() {
        add_menu_page(__('微信同步', WECHAT_SYNC_TEXTDOMAIN), __('微信同步', WECHAT_SYNC_TEXTDOMAIN), 'manage_options', 'wechat-sync', [__CLASS__, 'render_sync_page'], 'dashicons-share', 65);
        add_submenu_page('wechat-sync', __('设置', WECHAT_SYNC_TEXTDOMAIN), __('设置', WECHAT_SYNC_TEXTDOMAIN), 'manage_options', 'wechat-sync-settings', ['Wechat_Sync_Admin_Settings', 'render']);
        add_submenu_page('wechat-sync', __('异常信息', WECHAT_SYNC_TEXTDOMAIN), __('异常信息', WECHAT_SYNC_TEXTDOMAIN), 'manage_options', 'wechat-sync-errors', [__CLASS__, 'render_errors_page']);
        add_submenu_page('wechat-sync', __('计划任务', WECHAT_SYNC_TEXTDOMAIN), __('计划任务', WECHAT_SYNC_TEXTDOMAIN), 'manage_options', 'wechat-sync-cron', [__CLASS__, 'render_cron_page']);
    }

    public static function render_sync_page() {
        if (!current_user_can('manage_options')) return;
        $table = new Wechat_Sync_Admin_List_Table();
        echo '<div class="wrap">';
        echo '<h1>' . esc_html(__('文章同步列表', WECHAT_SYNC_TEXTDOMAIN)) . '</h1>';
        $use_system = get_option('wechat_sync_use_system_cron', '0') === '1' ? __('是', WECHAT_SYNC_TEXTDOMAIN) : __('否', WECHAT_SYNC_TEXTDOMAIN);
        $last = get_transient('wechat_sync_last_cron_run');
        echo '<div class="notice notice-info"><p><strong>' . esc_html(__('系统 Cron', WECHAT_SYNC_TEXTDOMAIN)) . ':</strong> ' . esc_html(__('是否启用', WECHAT_SYNC_TEXTDOMAIN)) . ': ' . esc_html($use_system) . ' | ' . esc_html(__('最近执行时间', WECHAT_SYNC_TEXTDOMAIN)) . ': ' . esc_html($last ? date('Y-m-d H:i:s', $last) : __('未执行', WECHAT_SYNC_TEXTDOMAIN)) . '</p></div>';
        $table->prepare_items();
        echo '<form method="get">';
        foreach (['page', 'post_type'] as $keep) {
            if (isset($_GET[$keep])) echo '<input type="hidden" name="' . esc_attr($keep) . '" value="' . esc_attr(sanitize_text_field($_GET[$keep])) . '" />';
        }
        $table->search_box(__('搜索文章', WECHAT_SYNC_TEXTDOMAIN), 'wechat-sync-search');
        $table->display();
        echo '</form>';
        echo '</div>';
    }

    public static function render_errors_page() {
        if (!current_user_can('manage_options')) return;
        $table = new Wechat_Sync_Error_List_Table();
        echo '<div class="wrap">';
        echo '<h1>' . esc_html(__('异常信息', WECHAT_SYNC_TEXTDOMAIN)) . '</h1>';
        $use_system = get_option('wechat_sync_use_system_cron', '0') === '1' ? __('是', WECHAT_SYNC_TEXTDOMAIN) : __('否', WECHAT_SYNC_TEXTDOMAIN);
        $last = get_transient('wechat_sync_last_cron_run');
        echo '<div class="notice notice-info"><p><strong>' . esc_html(__('系统 Cron', WECHAT_SYNC_TEXTDOMAIN)) . ':</strong> ' . esc_html(__('是否启用', WECHAT_SYNC_TEXTDOMAIN)) . ': ' . esc_html($use_system) . ' | ' . esc_html(__('最近执行时间', WECHAT_SYNC_TEXTDOMAIN)) . ': ' . esc_html($last ? date('Y-m-d H:i:s', $last) : __('未执行', WECHAT_SYNC_TEXTDOMAIN)) . '</p></div>';
        echo '<p>';
        $export = wp_nonce_url(admin_url('admin-post.php?action=wechat_sync_export_logs'), 'wechat_sync_export_logs');
        $clear = wp_nonce_url(admin_url('admin-post.php?action=wechat_sync_clear_logs'), 'wechat_sync_clear_logs');
        echo '<a class="button" href="' . esc_url($export) . '">' . esc_html(__('导出 CSV', WECHAT_SYNC_TEXTDOMAIN)) . '</a> ';
        echo '<a class="button" href="' . esc_url($clear) . '">' . esc_html(__('清空日志', WECHAT_SYNC_TEXTDOMAIN)) . '</a>';
        echo '</p>';
        echo '<form method="get" style="margin-bottom:10px;">';
        echo '<input type="hidden" name="page" value="wechat-sync-errors" />';
        echo '<label>' . esc_html(__('状态', WECHAT_SYNC_TEXTDOMAIN)) . ' ';
        echo '<select name="status"><option value="">' . esc_html(__('全部', WECHAT_SYNC_TEXTDOMAIN)) . '</option><option value="失败">失败</option><option value="重试中">重试中</option><option value="超时">超时</option></select></label> ';
        echo '<label>' . esc_html(__('阶段', WECHAT_SYNC_TEXTDOMAIN)) . ' ';
        echo '<select name="stage"><option value="">' . esc_html(__('全部', WECHAT_SYNC_TEXTDOMAIN)) . '</option><option value="token">token</option><option value="thumb">thumb</option><option value="uploadimg">uploadimg</option><option value="draft">draft</option><option value="publish">publish</option><option value="poll">poll</option><option value="retry">retry</option></select></label> ';
        echo '<button class="button">' . esc_html(__('应用筛选', WECHAT_SYNC_TEXTDOMAIN)) . '</button>';
        echo '</form>';
        $table->prepare_items();
        echo '<form method="get">';
        foreach (['page','status','stage'] as $keep) {
            if (isset($_GET[$keep])) echo '<input type="hidden" name="' . esc_attr($keep) . '" value="' . esc_attr(sanitize_text_field($_GET[$keep])) . '" />';
        }
        $table->search_box(__('搜索异常', WECHAT_SYNC_TEXTDOMAIN), 'wechat-sync-errors');
        $table->display();
        echo '</form>';
        echo '</div>';
    }

    public static function render_cron_page() {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap">';
        echo '<h1>' . esc_html(__('计划任务管理', WECHAT_SYNC_TEXTDOMAIN)) . '</h1>';
        $use_system = get_option('wechat_sync_use_system_cron', '0') === '1' ? __('是', WECHAT_SYNC_TEXTDOMAIN) : __('否', WECHAT_SYNC_TEXTDOMAIN);
        $last = get_transient('wechat_sync_last_cron_run');
        echo '<div class="notice notice-info"><p><strong>' . esc_html(__('系统 Cron', WECHAT_SYNC_TEXTDOMAIN)) . ':</strong> ' . esc_html(__('是否启用', WECHAT_SYNC_TEXTDOMAIN)) . ': ' . esc_html($use_system) . ' | ' . esc_html(__('最近执行时间', WECHAT_SYNC_TEXTDOMAIN)) . ': ' . esc_html($last ? date('Y-m-d H:i:s', $last) : __('未执行', WECHAT_SYNC_TEXTDOMAIN)) . '</p></div>';

        $secret = get_option('wechat_sync_cron_secret', '');
        if (!$secret) { $secret = wp_generate_password(32, false); update_option('wechat_sync_cron_secret', $secret, false); }
        $cron_url = admin_url('admin-post.php?action=wechat_sync_cron&key=' . rawurlencode($secret));
        $crontab_http = '*/1 * * * * curl -sS -m 60 \'' . esc_url($cron_url) . '\' > /dev/null 2>&1';
        echo '<h2>' . esc_html(__('推荐系统 Cron 命令', WECHAT_SYNC_TEXTDOMAIN)) . '</h2>';
        echo '<p><code>' . esc_html($crontab_http) . '</code></p>';
        

        echo '<h2>' . esc_html(__('队列与轮询', WECHAT_SYNC_TEXTDOMAIN)) . '</h2>';
        echo '<table class="widefat"><thead><tr><th>' . esc_html(__('文章ID', WECHAT_SYNC_TEXTDOMAIN)) . '</th><th>' . esc_html(__('标题', WECHAT_SYNC_TEXTDOMAIN)) . '</th><th>' . esc_html(__('状态', WECHAT_SYNC_TEXTDOMAIN)) . '</th><th>' . esc_html(__('下一次时间', WECHAT_SYNC_TEXTDOMAIN)) . '</th></tr></thead><tbody>';
        $lists = [];
        $q1 = new WP_Query(['post_type'=>'post','post_status'=>'any','posts_per_page'=>20,'meta_query'=>[['key'=>'wechat_sync_status','value'=>'排队','compare'=>'=']], 'orderby'=>'date','order'=>'ASC']);
        foreach($q1->posts as $p){ $t = get_post_meta($p->ID,'wechat_sync_next_attempt',true); $lists[] = ['id'=>$p->ID,'title'=>get_the_title($p->ID),'status'=>'排队','time'=>$t ? intval($t) : 0]; }
        $q2 = new WP_Query(['post_type'=>'post','post_status'=>'any','posts_per_page'=>20,'meta_query'=>[['key'=>'wechat_sync_status','value'=>'重试中','compare'=>'=']], 'orderby'=>'date','order'=>'ASC']);
        foreach($q2->posts as $p){ $t = get_post_meta($p->ID,'wechat_sync_next_attempt',true); $lists[] = ['id'=>$p->ID,'title'=>get_the_title($p->ID),'status'=>'重试中','time'=>$t ? intval($t) : 0]; }
        $q3 = new WP_Query(['post_type'=>'post','post_status'=>'any','posts_per_page'=>20,'meta_query'=>[['key'=>'wechat_sync_status','value'=>'发布中','compare'=>'=']], 'orderby'=>'date','order'=>'ASC']);
        foreach($q3->posts as $p){ $t = get_post_meta($p->ID,'wechat_sync_next_poll',true); $lists[] = ['id'=>$p->ID,'title'=>get_the_title($p->ID),'status'=>'发布中','time'=>$t ? intval($t) : 0]; }
        usort($lists, function($a,$b){ return ($a['time'] <=> $b['time']); });
        foreach($lists as $e){ echo '<tr><td>' . esc_html($e['id']) . '</td><td>' . esc_html($e['title']) . '</td><td>' . esc_html($e['status']) . '</td><td>' . esc_html($e['time'] ? date('Y-m-d H:i:s', $e['time']) : '-') . '</td></tr>'; }
        echo '</tbody></table>';
        echo '</div>';
        return;
        $use_system = get_option('wechat_sync_use_system_cron', '0') === '1' ? __('是', WECHAT_SYNC_TEXTDOMAIN) : __('否', WECHAT_SYNC_TEXTDOMAIN);
        $last = get_transient('wechat_sync_last_cron_run');
        echo '<div class="notice notice-info"><p><strong>' . esc_html(__('系统 Cron', WECHAT_SYNC_TEXTDOMAIN)) . ':</strong> ' . esc_html(__('是否启用', WECHAT_SYNC_TEXTDOMAIN)) . ': ' . esc_html($use_system) . ' | ' . esc_html(__('最近执行时间', WECHAT_SYNC_TEXTDOMAIN)) . ': ' . esc_html($last ? date('Y-m-d H:i:s', $last) : __('未执行', WECHAT_SYNC_TEXTDOMAIN)) . '</p></div>';

        $crontab_http = '*/1 * * * * curl -sS -m 60 \'' . esc_url(home_url('/wp-cron.php?doing_wp_cron=1')) . '\' > /dev/null 2>&1';
        echo '<h2>' . esc_html(__('推荐系统 Cron 命令', WECHAT_SYNC_TEXTDOMAIN)) . '</h2>';
        echo '<p><code>' . esc_html($crontab_http) . '</code></p>';
        echo '<p>' . esc_html(__('建议在服务器上启用 DISABLE_WP_CRON 后使用系统 Cron 调用以上命令。', WECHAT_SYNC_TEXTDOMAIN)) . '</p>';

        $cron = get_option('cron');
        echo '<h2>' . esc_html(__('已计划事件', WECHAT_SYNC_TEXTDOMAIN)) . '</h2>';
        echo '<table class="widefat"><thead><tr><th>' . esc_html(__('钩子', WECHAT_SYNC_TEXTDOMAIN)) . '</th><th>' . esc_html(__('文章ID', WECHAT_SYNC_TEXTDOMAIN)) . '</th><th>' . esc_html(__('尝试/计数', WECHAT_SYNC_TEXTDOMAIN)) . '</th><th>' . esc_html(__('时间', WECHAT_SYNC_TEXTDOMAIN)) . '</th></tr></thead><tbody>';
        if (is_array($cron)) {
            foreach ($cron as $ts => $hooks) {
                if (!is_array($hooks)) continue;
                foreach ($hooks as $hook => $instances) {
                    if ($hook !== 'wechat_sync_process' && $hook !== 'wechat_sync_poll') continue;
                    if (!is_array($instances)) continue;
                    foreach ($instances as $key => $entry) {
                        $args = isset($entry['args']) ? (array)$entry['args'] : [];
                        $pid = isset($args[0]) ? intval($args[0]) : 0;
                        $num = isset($args[1]) ? intval($args[1]) : 0;
                        echo '<tr><td>' . esc_html($hook) . '</td><td>' . esc_html($pid) . '</td><td>' . esc_html($num) . '</td><td>' . esc_html(date('Y-m-d H:i:s', intval($ts))) . '</td></tr>';
                    }
                }
            }
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    public static function add_posts_columns($columns) {
        $columns['wechat_sync'] = __('微信同步', WECHAT_SYNC_TEXTDOMAIN);
        return $columns;
    }

    public static function render_posts_column($column, $post_id) {
        if ($column !== 'wechat_sync') return;
        $status = get_post_meta($post_id, 'wechat_sync_status', true);
        $platform = get_post_meta($post_id, 'wechat_sync_platform', true);
        $url = get_post_meta($post_id, 'wechat_sync_url', true);
        $text = $status ? $status : __('未同步', WECHAT_SYNC_TEXTDOMAIN);
        $plat = $platform ? $platform : 'WeChatOA';
        echo esc_html($text . ' / ' . $plat);
        if ($url) {
            echo '<br/><a href="' . esc_url($url) . '" target="_blank">' . esc_html(__('查看', WECHAT_SYNC_TEXTDOMAIN)) . '</a>';
        }
        $sync_url = wp_nonce_url(admin_url('admin-post.php?action=wechat_sync_manual&post_id=' . $post_id), 'wechat_sync_manual_' . $post_id);
        echo '<br/><a href="' . esc_url($sync_url) . '" class="button">' . esc_html(__('同步', WECHAT_SYNC_TEXTDOMAIN)) . '</a>';
    }

    public static function on_transition_post_status($new, $old, $post) {
        if ($new === 'publish' && $post->post_type === 'post') {
            $auto = get_option('wechat_sync_auto', '0');
            if ($auto === '1') {
                update_post_meta($post->ID, 'wechat_sync_status', '排队');
                update_post_meta($post->ID, 'wechat_sync_platform', 'WeChatOA');
                Wechat_Sync_Queue::enqueue($post->ID, 0);
            }
        }
    }

    public static function handle_manual_sync() {
        if (!current_user_can('manage_options')) wp_die('');
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
        if (!$post_id) wp_redirect(admin_url('edit.php'));
        check_admin_referer('wechat_sync_manual_' . $post_id);
        update_post_meta($post_id, 'wechat_sync_status', '排队');
        update_post_meta($post_id, 'wechat_sync_platform', 'WeChatOA');
        Wechat_Sync_Queue::enqueue($post_id, 0);
        wp_redirect(wp_get_referer() ? wp_get_referer() : admin_url('edit.php'));
        exit;
    }

    public static function handle_retry_sync() {
        if (!current_user_can('manage_options')) wp_die('');
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
        if (!$post_id) wp_redirect(admin_url('edit.php'));
        check_admin_referer('wechat_sync_retry_' . $post_id);
        delete_post_meta($post_id, 'wechat_sync_error');
        update_post_meta($post_id, 'wechat_sync_status', '排队');
        Wechat_Sync_Queue::enqueue($post_id, 0);
        wp_redirect(wp_get_referer() ? wp_get_referer() : admin_url('edit.php'));
        exit;
    }

    public static function admin_notices() {
        if (!current_user_can('manage_options')) return;
        $msg = get_transient('wechat_sync_last_error');
        if ($msg) {
            $link = admin_url('admin.php?page=wechat-sync-errors');
            echo '<div class="notice notice-error"><p>' . esc_html($msg) . ' <a href="' . esc_url($link) . '">' . esc_html(__('查看异常列表', WECHAT_SYNC_TEXTDOMAIN)) . '</a></p></div>';
            delete_transient('wechat_sync_last_error');
        }
    }

    public static function handle_delete_log() {
        if (!current_user_can('manage_options')) wp_die('');
        $id = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : '';
        check_admin_referer('wechat_sync_delete_log_' . $id);
        if ($id) Wechat_Sync_Logger::delete_log($id);
        wp_redirect(admin_url('admin.php?page=wechat-sync-errors'));
        exit;
    }

    public static function handle_clear_logs() {
        if (!current_user_can('manage_options')) wp_die('');
        check_admin_referer('wechat_sync_clear_logs');
        Wechat_Sync_Logger::clear_logs();
        wp_redirect(admin_url('admin.php?page=wechat-sync-errors'));
        exit;
    }

    public static function handle_export_logs() {
        if (!current_user_can('manage_options')) wp_die('');
        check_admin_referer('wechat_sync_export_logs');
        $logs = Wechat_Sync_Logger::get_logs();
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=wechat-sync-errors.csv');
        Wechat_Sync_Logger::export_csv($logs);
        exit;
    }

    public static function handle_system_cron() {
        $key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $secret = get_option('wechat_sync_cron_secret', '');
        if (!$secret) { $secret = wp_generate_password(32, false); update_option('wechat_sync_cron_secret', $secret, false); }
        if (!current_user_can('manage_options') && $key !== $secret) { status_header(403); echo 'FORBIDDEN'; exit; }
        if (get_transient('wechat_sync_cron_lock')) { status_header(200); echo 'LOCK'; exit; }
        set_transient('wechat_sync_cron_lock', 1, 60);
        self::run_scheduler();
        set_transient('wechat_sync_last_cron_run', time(), 600);
        delete_transient('wechat_sync_cron_lock');
        status_header(200);
        echo 'OK';
        exit;
    }

    public static function run_scheduler() {
        $now = time();
        $secret = get_option('wechat_sync_cron_secret', '');
        if (!$secret) { $secret = wp_generate_password(32, false); update_option('wechat_sync_cron_secret', $secret, false); }
        $pb = max(1, min(50, intval(get_option('wechat_sync_process_batch', '10'))));
        $lb = max(1, min(50, intval(get_option('wechat_sync_poll_batch', '10'))));
        $q1 = new WP_Query([
            'post_type'=>'post','post_status'=>'any','posts_per_page'=>$pb,
            'meta_query'=>[
                ['key'=>'wechat_sync_status','value'=>'排队','compare'=>'='],
                ['key'=>'wechat_sync_next_attempt','value'=>$now,'compare'=>'<=','type'=>'NUMERIC']
            ],
            'orderby'=>'date','order'=>'ASC'
        ]);
        foreach($q1->posts as $p){
            $u = admin_url('admin-post.php?action=wechat_sync_worker_process&post_id=' . intval($p->ID) . '&key=' . rawurlencode($secret));
            wp_remote_post($u, ['timeout' => 0.01, 'blocking' => false]);
        }
        $q2 = new WP_Query([
            'post_type'=>'post','post_status'=>'any','posts_per_page'=>$pb,
            'meta_query'=>[
                ['key'=>'wechat_sync_status','value'=>'重试中','compare'=>'='],
                ['key'=>'wechat_sync_next_attempt','value'=>$now,'compare'=>'<=','type'=>'NUMERIC']
            ],
            'orderby'=>'date','order'=>'ASC'
        ]);
        foreach($q2->posts as $p){
            $u = admin_url('admin-post.php?action=wechat_sync_worker_process&post_id=' . intval($p->ID) . '&key=' . rawurlencode($secret));
            wp_remote_post($u, ['timeout' => 0.01, 'blocking' => false]);
        }
        $q3 = new WP_Query([
            'post_type'=>'post','post_status'=>'any','posts_per_page'=>$lb,
            'meta_query'=>[
                ['key'=>'wechat_sync_status','value'=>'发布中','compare'=>'='],
                ['key'=>'wechat_sync_next_poll','value'=>$now,'compare'=>'<=','type'=>'NUMERIC']
            ],
            'orderby'=>'date','order'=>'ASC'
        ]);
        foreach($q3->posts as $p){
            $u = admin_url('admin-post.php?action=wechat_sync_worker_poll&post_id=' . intval($p->ID) . '&key=' . rawurlencode($secret));
            wp_remote_post($u, ['timeout' => 0.01, 'blocking' => false]);
        }
    }

    public static function handle_worker_process() {
        $key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $secret = get_option('wechat_sync_cron_secret', '');
        if (!$secret) { $secret = wp_generate_password(32, false); update_option('wechat_sync_cron_secret', $secret, false); }
        if (!current_user_can('manage_options') && $key !== $secret) { status_header(403); echo 'FORBIDDEN'; exit; }
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
        if (!$post_id) { status_header(400); echo 'NOP'; exit; }
        $lock_key = 'wechat_sync_post_lock_' . $post_id;
        if (get_transient($lock_key)) { status_header(200); echo 'LOCK'; exit; }
        set_transient($lock_key, 1, 120);
        $attempt = intval(get_post_meta($post_id, 'wechat_sync_attempt', true));
        Wechat_Sync_Queue::process($post_id, $attempt);
        delete_transient($lock_key);
        status_header(200);
        echo 'OK';
        exit;
    }

    public static function handle_worker_poll() {
        $key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $secret = get_option('wechat_sync_cron_secret', '');
        if (!$secret) { $secret = wp_generate_password(32, false); update_option('wechat_sync_cron_secret', $secret, false); }
        if (!current_user_can('manage_options') && $key !== $secret) { status_header(403); echo 'FORBIDDEN'; exit; }
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
        if (!$post_id) { status_header(400); echo 'NOP'; exit; }
        $lock_key = 'wechat_sync_post_lock_poll_' . $post_id;
        if (get_transient($lock_key)) { status_header(200); echo 'LOCK'; exit; }
        set_transient($lock_key, 1, 120);
        $count = intval(get_post_meta($post_id, 'wechat_sync_poll_count', true));
        Wechat_Sync_Queue::poll($post_id, $count);
        delete_transient($lock_key);
        status_header(200);
        echo 'OK';
        exit;
    }
}

Wechat_Sync_Plugin::init();